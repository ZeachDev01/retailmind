<?php
// Real MySQL boundary tests. Only uniquely named disposable databases are changed.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database Backup workflow integration: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}
$private = sys_get_temp_dir() . '/rm-sql-test-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
require_once __DIR__ . '/../bootstrap/app.php';
$_ENV['BACKUP_STORAGE_PATH'] = $private;
require_once __DIR__ . '/../includes/backup.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\RecoveryStore;
use App\Backup\SqlBackupFormat;
use App\Backup\StorePackageFormat;
use App\Backup\DatabaseBackupService;
use App\Backup\DatabaseRestoreService;
use App\Store\StoreWriteGate;

class InterruptedRestorePdo extends PDO
{
    public bool $interrupt = false;
    public function exec(string $statement): int|false
    {
        if ($this->interrupt && str_starts_with($statement, 'CREATE TABLE `users`')) {
            $this->interrupt = false;
            throw new RuntimeException('Injected failure after dropping users');
        }
        return parent::exec($statement);
    }
}
final class BackupIntegrationClock implements \App\Attention\Clock
{
    public function __construct(public DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$reject = static function (callable $work, string $message) use ($assert): void {
    $rejected = false;
    try { $work(); } catch (Throwable) { $rejected = true; }
    $assert($rejected, $message);
};
$config = require __DIR__ . '/../config/database.php';
$dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$server = null;
$names = ['rm_backup_src_' . bin2hex(random_bytes(6)), 'rm_backup_dst_' . bin2hex(random_bytes(6)), 'rm_backup_full_' . bin2hex(random_bytes(6))];
$exit = 0;
try {
    $server = new PDO($dsn, $config['username'], $config['password'], $options);
    foreach ($names as $name) { $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
    $source = new PDO($dsn . ';dbname=' . $names[0], $config['username'], $config['password'], $options);
    $target = new InterruptedRestorePdo($dsn . ';dbname=' . $names[1], $config['username'], $config['password'], $options);
    foreach ([$source, $target] as $db) {
        $db->exec('CREATE TABLE roles (role_id INT PRIMARY KEY, role_name VARCHAR(30)) ENGINE=InnoDB');
        $db->exec("INSERT INTO roles VALUES (1, 'super_admin'), (2, 'admin'), (3, 'cashier')");
        $db->exec("CREATE TABLE users (user_id INT PRIMARY KEY, full_name VARCHAR(100), role_id INT, password_hash VARCHAR(255), status VARCHAR(20), session_version INT, display_name VARCHAR(100) NULL) ENGINE=InnoDB");
        $insert = $db->prepare("INSERT INTO users VALUES (?, ?, ?, ?, 'active', 1, ?)");
        foreach ([1 => 'Super', 2 => 'Owner', 3 => 'Cashier'] as $id => $name) {
            $insert->execute([$id, $name, $id, password_hash($db === $source ? 'Snapshot-password' : 'Current-password', PASSWORD_DEFAULT), $name]);
        }
        $db->exec('CREATE TABLE activity_log (log_id INT AUTO_INCREMENT PRIMARY KEY, note TEXT) ENGINE=InnoDB');
        $db->exec('CREATE TABLE products (product_id INT PRIMARY KEY, product_name TEXT, optional_value TEXT NULL, binary_value BLOB, stock INT) ENGINE=InnoDB');
        $migration = require __DIR__ . '/../database/migrations/202609260001_shared_database_backups.php';
        $migration['up']($db);
        // The current backup history columns and status enum come from the
        // foundation migration (#460); apply it to this disposable fixture too.
        $history = require __DIR__ . '/../database/migrations/202611100001_backup_keyring_and_history.php';
        $history['up']($db);
        (require __DIR__ . '/../database/migrations/202609240002_dormancy_policy_settings.php')['up']($db);
        (require __DIR__ . '/../database/migrations/202611100002_scheduled_backup_settings.php')['up']($db);
    }
    $text = "O'Brien; DROP DATABASE not_a_command;\nüñicode \\ test";
    $source->prepare('INSERT INTO products VALUES (1, ?, NULL, ?, 42)')->execute([$text, "\x00\xff\x01"]);
    $target->exec("INSERT INTO products VALUES (9, 'Old data', NULL, NULL, 3)");
    $target->exec("INSERT INTO activity_log (note) VALUES ('Newer audit evidence')");
    foreach ([$source, $target] as $db) {
        $db->exec('ALTER TABLE products ADD COLUMN stock_doubled INT AS (stock * 2) STORED, ADD COLUMN stock_plus_one INT AS (stock + 1) VIRTUAL');
    }
    $uploadRoot = $private . '/uploads';
    mkdir($uploadRoot . '/images', 0700, true);
    mkdir($uploadRoot . '/profile-images', 0700, true);
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
    foreach ([$source, $target] as $db) {
        $db->exec('ALTER TABLE products ADD COLUMN product_image VARCHAR(255) NULL');
        $db->exec('ALTER TABLE users ADD COLUMN profile_image VARCHAR(80) NULL, ADD COLUMN username VARCHAR(80) COLLATE utf8mb4_unicode_ci NULL UNIQUE, ADD COLUMN email VARCHAR(120) COLLATE utf8mb4_unicode_ci NULL UNIQUE');
        $db->exec("UPDATE users SET username=CONCAT('person-',user_id), email=CONCAT('person-',user_id,'@example.test')");
    }
    $source->exec("UPDATE products SET product_image='storage/images/test.png' WHERE product_id=1");
    $source->exec("UPDATE users SET profile_image='test.png' WHERE user_id=2");
    file_put_contents($uploadRoot . '/images/test.png', $image);
    file_put_contents($uploadRoot . '/profile-images/test.png', $image);
    $policy = new RoleCapabilityPolicy();
    $backups = new DatabaseBackupService($source, $policy, $private . '/downloads', $source, $uploadRoot);
    $targetBackups = new DatabaseBackupService($target, $policy, $private . '/target-downloads', $target, $uploadRoot);
    $restorer = new DatabaseRestoreService($target, $policy, $targetBackups);

    foreach (['cashier', 'inventory_manager'] as $role) {
        $reject(fn() => $backups->create(3, $role), 'Operational staff cannot create backups');
    }
    foreach (['admin', 'cashier', 'inventory_manager'] as $role) {
        $reject(fn() => $restorer->restore(2, $role, '', 'backup.sql', 'Current-password'), 'Only Super Administrator can restore');
    }
    // Snapshot coordination still refuses concurrent writes and backup captures.
    $coordinator = $backups->coordinator();
    $claim = $coordinator->claim(1, 'super_admin');
    $assert($coordinator->claim(2, 'admin') === null, 'Only one capture may claim the slot');
    $assert($backups->create(2, 'admin')['status'] === DatabaseBackupService::OUTCOME_IN_PROGRESS, 'Busy backup does not start another export');
    $coordinator->beginCapture(1);
    $writer = new PDO($dsn . ';dbname=' . $names[0], $config['username'], $config['password'], $options);
    $reject(fn() => StoreWriteGate::begin($writer), 'Store writes must pause during a capture');
    $coordinator->abortCapture();
    $coordinator->fail($claim, 'fixture');
    StoreWriteGate::begin($writer);
    $writer->rollBack();

    $backup = $backups->create(2, 'admin');
    $assert($backup['status'] === DatabaseBackupService::OUTCOME_READY, 'Administrator can capture an encrypted Store package');
    $encrypted = (string)file_get_contents($backup['path']);
    $decoded = StorePackageFormat::decode($encrypted, SqlBackupFormat::schema($source));
    $sql = $decoded['sql'];
    $assert($decoded['files']['images/test.png'] === $image && $decoded['files']['profile-images/test.png'] === $image, 'Actual Product and profile image bytes round trip');
    $assert(!str_contains($encrypted, 'INSERT INTO') && !str_contains($encrypted, \App\Backup\BackupKeyStore::current()), 'Package conceals captured SQL and key');
    $assert(str_ends_with($backup['filename'], '.rmpkg'), 'Downloads use encrypted package extension');
    $assert($backups->history('admin')[0]['format'] === 'Encrypted Store package', 'History reports SQL format');
    $reject(fn() => $backups->resolveDownload($backup['token'], 1, 'super_admin'), 'Downloads are requester-bound');
    $assert($backups->resolveDownload($backup['token'], 2, 'admin')['path'] === $backup['path'], 'Owner resolves own download');
    $superBackup = $backups->create(1, 'super_admin');
    $assert($superBackup['status'] === DatabaseBackupService::OUTCOME_READY, 'Super Administrator can create backups too');
    $assert($decoded['rows']['backup_operations'] === [] && $decoded['rows']['store_write_gate'] === [], 'Packages exclude source tokens, private artifact paths and transient pause state');
    $systemBackup = $backups->createForSystem();
    $assert(str_starts_with((string)file_get_contents($systemBackup['path']), "RMSTORE2\n"), 'System export also requires encrypted package format');
    $backups->discardAfterDelivery($systemBackup['path']);

    // The real scheduled-run seam: due, verified package, retention, manual
    // evidence preservation, and a busy-slot skip against MySQL.
    $scheduledClock = new BackupIntegrationClock(new DateTimeImmutable('2026-11-08 23:05:00', new DateTimeZone('Asia/Manila')));
    $scheduledSettings = new \App\Backup\ScheduledBackupSettings($source, $scheduledClock);
    $scheduledDestination = $private . '/scheduled-destination';
    $scheduler = new \App\Backup\ScheduledBackupService($source, $scheduledClock, $scheduledSettings, $backups, $scheduledDestination);
    $scheduledFiles = static fn(): array => array_values(glob($scheduledDestination . '/*.rmpkg') ?: []);
    $firstScheduled = $scheduler->runDue();
    $assert(($firstScheduled['outcome'] ?? '') === \App\Backup\ScheduledBackupService::OUTCOME_COMPLETED, 'The real scheduled seam completes a verified package');
    $assert(count($scheduledFiles()) === 1, 'The scheduled destination holds exactly one package');
    $scheduledRow = $source->query("SELECT * FROM backup_history WHERE backup_type='scheduled' AND status='completed' AND artifact_path IS NOT NULL ORDER BY backup_id DESC LIMIT 1")->fetch();
    $assert(is_array($scheduledRow) && is_file((string)$scheduledRow['artifact_path']), 'The scheduled history row binds the retained package');
    $assert(str_starts_with(str_replace('\\', '/', (string)$scheduledRow['artifact_path']), str_replace('\\', '/', (string)realpath($scheduledDestination))), 'The scheduled package lives in the configured destination');
    $assert((string)$scheduledRow['key_fingerprint'] === \App\Backup\BackupKeyStore::currentFingerprint(), 'The scheduled row records the actual package key fingerprint');
    $decodedScheduled = StorePackageFormat::decode((string)file_get_contents((string)$scheduledRow['artifact_path']), SqlBackupFormat::schema($source));
    $assert(isset($decodedScheduled['rows']['products']), 'The retained scheduled package decodes against the source schema');
    $firstScheduledPath = (string)$scheduledRow['artifact_path'];
    $scheduledClock->now = $scheduledClock->now->modify('+8 days');
    $secondScheduled = $scheduler->runDue();
    $assert(($secondScheduled['outcome'] ?? '') === \App\Backup\ScheduledBackupService::OUTCOME_COMPLETED, 'The next due scheduled run completes');
    $assert(!is_file($firstScheduledPath), 'Retention removes the replaced scheduled package');
    $assert(count($scheduledFiles()) === 1, 'Retention leaves one scheduled package');
    $firstRemovedAt = $source->query('SELECT removed_at FROM backup_history WHERE backup_id=' . (int)$scheduledRow['backup_id'])->fetchColumn();
    $assert($firstRemovedAt !== null && (string)$firstRemovedAt !== '', 'Retention marks the replaced history row as removed');
    $manualEvidence = $private . '/manual-evidence.rmpkg';
    file_put_contents($manualEvidence, 'manual');
    $source->prepare("INSERT INTO backup_history (filename,backup_type,file_size,status,artifact_path) VALUES ('manual-evidence.rmpkg','manual',6,'completed',?)")->execute([$manualEvidence]);
    $scheduledClock->now = $scheduledClock->now->modify('+8 days');
    $scheduler->runDue();
    $assert(is_file($manualEvidence), 'Retention never removes manual evidence');
    $manualRemovedAt = $source->query("SELECT removed_at FROM backup_history WHERE filename='manual-evidence.rmpkg'")->fetchColumn();
    $assert($manualRemovedAt === null, 'Retention never marks manual evidence removed');
    $busyClaim = $backups->coordinator()->claim(1, 'super_admin');
    $scheduledClock->now = $scheduledClock->now->modify('+8 days');
    $busyScheduled = $scheduler->runDue();
    $assert(($busyScheduled['outcome'] ?? '') === \App\Backup\ScheduledBackupService::OUTCOME_SKIPPED, 'A busy capture slot skips the scheduled run');
    $assert(($busyScheduled['reason'] ?? '') === 'backup_in_progress', 'A busy capture slot explains backup_in_progress');
    $backups->coordinator()->fail((string)$busyClaim, 'fixture');

    $before = (string)$target->query('SELECT password_hash FROM users WHERE user_id=1')->fetchColumn();
    $preflight = $restorer->preflight(1, 'super_admin', $backup['path'], $backup['filename'], 'Current-password');
    $assert(!$preflight['valid'] && count($preflight['conflicts']) >= 3 && !$preflight['replacement_authorized'], 'Preflight reports colliding actor IDs and never permits replacement');
    $assert($target->query('SELECT password_hash FROM users WHERE user_id=1')->fetchColumn() === $before, 'Preflight never changes credentials');
    $reject(fn() => $restorer->preflight(1, 'super_admin', $backup['path'], $backup['filename'], 'Snapshot-password'), 'Obsolete source password cannot authorize destination preflight');
    $assert(!$restorer->preflight(2, 'admin', $backup['path'], $backup['filename'], 'Current-password')['replacement_authorized'], 'Administrator can validate a backup without replacing data');
    $bodyWithDuplicate = preg_replace('/\n-- SHA256: [a-f0-9]{64}\n$/', '', $sql);
    $bodyWithDuplicate = str_replace("X'" . bin2hex('person-3') . "'", "X'" . bin2hex('PERSON-2') . "'", $bodyWithDuplicate);
    $duplicateSql = $bodyWithDuplicate . "\n-- SHA256: " . hash('sha256', $bodyWithDuplicate) . "\n";
    $duplicatePath = $private . '/duplicate.rmpkg';
    file_put_contents($duplicatePath, StorePackageFormat::encode($duplicateSql, $decoded['files'], SqlBackupFormat::schema($source)));
    $reject(fn() => $restorer->preflight(1, 'super_admin', $duplicatePath, 'duplicate.rmpkg', 'Current-password'), 'MySQL case-insensitive unique semantics reject hostile duplicate usernames');
    $assert((int)$target->query('SELECT stock FROM products WHERE product_id=9')->fetchColumn() === 3, 'Hostile preflight leaves permanent operational data unchanged');
    // Existing CLI-only legacy recovery remains private until encrypted replacement lands.
    $legacySql = $private . '/legacy-private.sql';
    file_put_contents($legacySql, $sql);
    $restore = fn(string $path, string $name = 'backup.sql', string $password = 'Current-password') => $restorer->restore(1, 'super_admin', $path, $name, $password);
    $reject(fn() => $restore($legacySql, 'backup.sql', 'wrong'), 'Wrong password must be rejected');
    $reject(fn() => $restore($legacySql, 'backup.rmbak'), 'Old encrypted extension is rejected');
    file_put_contents($private . '/broken.sql', substr($sql, 0, -20));
    $reject(fn() => $restore($private . '/broken.sql'), 'Truncated SQL must be rejected');
    $assert((int)$target->query('SELECT stock FROM products WHERE product_id=9')->fetchColumn() === 3, 'Rejected files/passwords leave target untouched');
    $assert(!RecoveryStore::isPaused(), 'Rejected input does not leave maintenance active');

    // An admitted request prevents destructive restore, even across DDL commits.
    $lease = fopen(RecoveryStore::path('requests.lock'), 'c+b');
    flock($lease, LOCK_SH);
    $reject(fn() => $restore($legacySql), 'Existing requests must finish before restore');
    fclose($lease);

    // A safety persistence failure must happen before replacement.
    mkdir(RecoveryStore::path('latest-safety.sql'));
    $reject(fn() => $restore($legacySql), 'Safety backup failure aborts replacement');
    rmdir(RecoveryStore::path('latest-safety.sql'));
    $assert((int)$target->query('SELECT stock FROM products WHERE product_id=9')->fetchColumn() === 3, 'Safety failure leaves current records intact');
    $assert(!RecoveryStore::isPaused(), 'Non-destructive safety failure reopens the Store');

    $epochBefore = RecoveryStore::epoch();
    $result = $restore($legacySql);
    $assert($result['statements'] > 0, 'Restore executes a real full replacement');
    $recovered = $target->query('SELECT * FROM products WHERE product_id=1')->fetch();
    $assert($recovered['product_name'] === $text && $recovered['optional_value'] === null && $recovered['binary_value'] === "\x00\xff\x01" && (int)$recovered['stock'] === 42, 'Text, binary, null, and stock values round-trip');
    $assert((int)$recovered['stock_doubled'] === 84 && (int)$recovered['stock_plus_one'] === 43, 'Stored and virtual generated columns must be recalculated after restore');
    $assert((int)$target->query('SELECT COUNT(*) FROM products WHERE product_id=9')->fetchColumn() === 0, 'Old records are replaced, not merged');
    $assert((int)$target->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === 0, 'Newer audit records are not reconciled');
    $assert(RecoveryStore::epoch() !== $epochBefore, 'Restore invalidates sessions outside the database');
    $assert(!RecoveryStore::isPaused(), 'Successful restore reopens Store access');
    $assert(is_file(RecoveryStore::path('latest-safety.sql')), 'Latest safety copy is retained');
    $assert(str_contains((string)file_get_contents(RecoveryStore::path('restore-log.jsonl')), 'completed'), 'Completion is logged outside database');
    $assert(password_verify('Snapshot-password', (string)$target->query('SELECT password_hash FROM users WHERE user_id=1')->fetchColumn()), 'Restored accounts use snapshot credentials');

    // Force a partial DDL failure leaving no users table. Recovery must still work.
    $target->exec("UPDATE products SET stock=99 WHERE product_id=1");
    $target->interrupt = true;
    $reject(fn() => $restore($legacySql, 'backup.sql', 'Snapshot-password'), 'Injected mid-import failure is reported');
    $assert(RecoveryStore::isPaused(), 'Partial import must leave Store access blocked');
    $assert(!RecoveryStore::admitRequest(), 'Maintenance blocks fresh requests before authentication/database access');
    $reject(fn() => StoreWriteGate::begin($target), 'Store writes stay blocked after failure');
    $safetyHash = hash_file('sha256', RecoveryStore::path('latest-safety.sql'));
    $reject(fn() => $restorer->recover(RecoveryStore::path('latest-safety.sql'), 'wrong'), 'Offline recovery verifies pre-restore password');
    $restorer->recover(RecoveryStore::path('latest-safety.sql'), 'Snapshot-password');
    $assert((int)$target->query('SELECT stock FROM products WHERE product_id=1')->fetchColumn() === 99, 'Offline safety recovery restores the pre-attempt state even without users table');
    $assert(hash_file('sha256', RecoveryStore::path('latest-safety.sql')) === $safetyHash, 'Recovery does not replace the good safety copy with partial data');
    $assert(!RecoveryStore::isPaused(), 'Offline recovery reopens access');

    $legacy = $private . '/downloads/legacy.rmbak';
    file_put_contents($legacy, 'old encrypted artifact');
    touch($legacy, time() - 7200);
    touch($backup['path'], time() - 7200);
    $backups->cleanupStrandedArtifacts();
    $assert(!is_file($backup['path']) && is_file($legacy) && is_file(RecoveryStore::path('latest-safety.sql')), 'Cleanup removes expired encrypted packages only, not safety or legacy files');
    $backups->discardAfterDelivery($superBackup['path']);
    $assert(!is_file($superBackup['path']), 'Delivered artifacts are removed');
    // Round-trip the repository's complete shipped schema plus all migrations,
    // not just the small failure-injection fixture above.
    $full = new PDO($dsn . ';dbname=' . $names[2], $config['username'], $config['password'], $options);
    $full->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (split_sql_statements((string)file_get_contents(__DIR__ . '/../database/sql/schema.sql')) as $statement) {
        $full->exec($statement);
    }
    $full->exec('SET FOREIGN_KEY_CHECKS=1');
    require_once __DIR__ . '/../includes/functions.php';
    (new \App\Database\MigrationRunner($full, __DIR__ . '/../database/migrations'))->runPending();
    $superRole = (int)$full->query("SELECT role_id FROM roles WHERE role_name='super_admin'")->fetchColumn();
    $full->prepare('INSERT INTO users (first_name, display_name, username, password_hash, role_id, must_change_password) VALUES (?, ?, ?, ?, ?, 0)')
        ->execute(['Full', 'Full schema fixture', 'sql_roundtrip_fixture', password_hash('Full-schema-password', PASSWORD_DEFAULT), $superRole]);
    $fullActor = (int)$full->lastInsertId();
    $receiptSentinel = '{"reference_number":"00000000000000000000000000123456","fee_amount":"123.45","store":{"name":"Saved Store","footer":"Saved footer"}}';
    $full->prepare("INSERT INTO gcash_transactions (transaction_type,reference_number,amount,cashier_id,verification_status,receipt_details_json) VALUES ('Cash-In','00000000000000000000000000123456',12345,?,'Completed',?)")->execute([$fullActor, $receiptSentinel]);
    foreach (StorePackageFormat::references(['users' => $full->query('SELECT profile_image FROM users')->fetchAll(), 'products' => $full->query('SELECT product_image FROM products')->fetchAll()]) as $reference) {
        file_put_contents($uploadRoot . '/' . $reference, $image);
    }
    $fullBackups = new DatabaseBackupService($full, $policy, $private . '/full-downloads', $full, $uploadRoot);
    $fullBackup = $fullBackups->create($fullActor, 'super_admin');
    $full->prepare('UPDATE users SET display_name=? WHERE user_id=?')->execute(['Changed since snapshot', $fullActor]);
    $fullRestorer = new DatabaseRestoreService($full, $policy, $fullBackups);
    $fullSchema = SqlBackupFormat::schema($full);
    foreach (['privileges', 'role_privileges', 'user_privileges', 'restore_preserved_activity', 'emergency_access_sessions'] as $retiredTable) {
        $assert(!isset($fullSchema[$retiredTable]), 'Encrypted baseline must not recreate retired storage: ' . $retiredTable);
    }
    $fullPreflight = $fullRestorer->preflight($fullActor, 'super_admin', $fullBackup['path'], $fullBackup['filename'], 'Full-schema-password');
    $assert($fullPreflight['replacement_authorized'] === false, 'Complete migrated schema preflight never authorizes replacement');
    $assert($full->query('SELECT display_name FROM users WHERE user_id=' . $fullActor)->fetchColumn() === 'Changed since snapshot', 'Complete migrated schema preflight leaves destination unchanged');
    $fullDecoded = StorePackageFormat::decode((string)file_get_contents($fullBackup['path']), SqlBackupFormat::schema($full));
    $assert($fullDecoded['rows']['schema_migrations'] === [], 'Captured packages exclude the source migration ledger rows');
    $assert($fullDecoded['compatibility']['status'] === 'current', 'A current package classifies as current');
    // A backup older than this installation (newer destination columns only)
    // must stay restorable instead of demanding a migration that already ran.
    $full->exec('ALTER TABLE store_calendar ADD COLUMN operator_note TEXT NULL');
    $additivePreflight = $fullRestorer->preflight($fullActor, 'super_admin', $fullBackup['path'], $fullBackup['filename'], 'Full-schema-password');
    $full->exec('ALTER TABLE store_calendar DROP COLUMN operator_note');
    $assert(($additivePreflight['compatibility']['status'] ?? '') === 'upgradable'
        && array_key_exists('adapter', $additivePreflight['compatibility'] ?? [])
        && $additivePreflight['compatibility']['adapter'] === null
        && in_array('store_calendar.operator_note', $additivePreflight['compatibility']['changes']['columns_added'] ?? [], true),
        'A backup older than this installation is upgradable without an adapter: ' . ($additivePreflight['compatibility']['message'] ?? 'missing report'));
    $assert($full->query('SELECT display_name FROM users WHERE user_id=' . $fullActor)->fetchColumn() === 'Changed since snapshot',
        'An older backup preflight leaves destination data unchanged');
    $ledgerPath = __DIR__ . '/../database/migrations';
    $ledgerBefore = (new \App\Database\MigrationLedger($full, $ledgerPath))->snapshot();
    $assert($ledgerBefore !== [], 'The destination ledger is populated before replacement');
    file_put_contents($legacySql, $fullDecoded['sql']);
    $fullRestorer->restore($fullActor, 'super_admin', $legacySql, 'legacy-private.sql', 'Full-schema-password');
    $assert((new \App\Database\MigrationLedger($full, $ledgerPath))->snapshot() === $ledgerBefore, 'The destination migration ledger survives replacement');
    $assert($full->query('SELECT display_name FROM users WHERE user_id=' . $fullActor)->fetchColumn() === 'Full schema fixture', 'Complete migrated application schema must round-trip');
    $assert($full->query("SELECT receipt_details_json FROM gcash_transactions WHERE reference_number='00000000000000000000000000123456'")->fetchColumn() === $receiptSentinel, 'GCash receipt snapshot survives full backup and restore byte for byte');

    // Synthesize a pre-branch-retirement package from the same destination structure.
    $compactDdl = static fn(string $table, array $columns): string => "CREATE TABLE `{$table}` ("
        . implode(",\n  ", array_map(static fn(string $column): string => "`{$column}` text DEFAULT NULL", $columns))
        . "\n) ENGINE=InnoDB";
    $olderSchema = $fullSchema;
    unset($olderSchema['held_sale_items'], $olderSchema['cashier_shift_payment_totals']);
    $olderSchema['branches'] = ['ddl' => $compactDdl('branches', ['branch_id', 'branch_name']), 'columns' => ['branch_id', 'branch_name']];
    $olderSchema['users'] = ['ddl' => $compactDdl('users', ['user_id', 'full_name', 'branch_id']), 'columns' => ['user_id', 'full_name', 'branch_id']];
    $olderSchema['products'] = ['ddl' => $compactDdl('products', ['product_id', 'branch_id', 'product_name']), 'columns' => ['product_id', 'branch_id', 'product_name']];
    $olderSchema['held_sales'] = ['ddl' => $compactDdl('held_sales', ['held_sale_id', 'cart_json']), 'columns' => ['held_sale_id', 'cart_json']];
    $olderSchema['cashier_shifts'] = ['ddl' => $compactDdl('cashier_shifts', ['shift_id', 'payment_totals']), 'columns' => ['shift_id', 'payment_totals']];
    ksort($olderSchema, SORT_STRING);
    $schemaOnlySql = static function (array $schema): string {
        $body = '';
        foreach ($schema as $table => $definition) {
            $quoted = '`' . $table . '`';
            $body .= 'DROP TABLE IF EXISTS ' . $quoted . ";\n" . $definition['ddl'] . ";\n";
        }
        $body = SqlBackupFormat::HEADER . "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" . $body . "\nSET FOREIGN_KEY_CHECKS=1;";
        return $body . "\n-- SHA256: " . hash('sha256', $body) . "\n";
    };
    $olderMigrations = array_values(array_filter($fullDecoded['migrations'],
        static fn(string $key): bool => strcmp($key, '202611010001_retire_branches') < 0));
    $olderPackagePath = $private . '/older.rmpkg';
    file_put_contents($olderPackagePath, StorePackageFormat::encode($schemaOnlySql($olderSchema), [], $olderSchema,
        str_repeat('a', 32), 'mariadb', ['migrations' => $olderMigrations]));
    $olderPreflight = $fullRestorer->preflight($fullActor, 'super_admin', $olderPackagePath, 'older.rmpkg', 'Full-schema-password');
    $assert(($olderPreflight['compatibility']['status'] ?? '') === 'upgradable',
        'An older package is classified as upgradable: ' . ($olderPreflight['compatibility']['message'] ?? 'missing report'));
    $assert(($olderPreflight['compatibility']['adapter'] ?? '') === 'pre-202611010001', 'The registered adapter handles an older package');
    $assert($full->query('SELECT display_name FROM users WHERE user_id=' . $fullActor)->fetchColumn() === 'Full schema fixture',
        'Older package preflight leaves destination data unchanged');
    $newerPackagePath = $private . '/newer.rmpkg';
    file_put_contents($newerPackagePath, StorePackageFormat::encode($fullDecoded['sql'], $fullDecoded['files'], $fullSchema, '', 'mariadb',
        ['migrations' => array_merge($fullDecoded['migrations'], ['209901010001_future_format'])]));
    $newerPreflight = $fullRestorer->preflight($fullActor, 'super_admin', $newerPackagePath, 'newer.rmpkg', 'Full-schema-password');
    $assert($newerPreflight['valid'] === false
        && ($newerPreflight['compatibility']['status'] ?? '') === 'unsupported'
        && in_array('209901010001_future_format', $newerPreflight['compatibility']['missing_migrations'] ?? [], true),
        'A newer package is reviewed as unsupported before any destructive work');
    $assert($full->query('SELECT display_name FROM users WHERE user_id=' . $fullActor)->fetchColumn() === 'Full schema fixture',
        'Unsupported preflight leaves destination data unchanged');

    // Story 40: a partially upgraded installation repairs through the migration runner.
    $full->exec("DELETE FROM schema_migrations WHERE migration_key='202611100001_backup_keyring_and_history'");
    $full->exec('ALTER TABLE backup_history DROP COLUMN snapshot_at');
    ensure_backup_storage($full);
    $assert(App\Database\Schema::columnExists($full, 'backup_history', 'snapshot_at'), 'Backup history repairs through the normal migration path');
    $assert(is_array($fullBackups->history('super_admin')), 'Repaired backup history is readable again');

    $full->exec('CREATE VIEW unsupported_fixture_view AS SELECT user_id FROM users');
    $reject(fn() => $fullBackups->create($fullActor, 'super_admin'), 'Unsupported custom objects must fail rather than produce an incomplete backup');
    $assert(!$fullBackups->status()['paused'], 'Failed unsupported capture releases the write gate');
    echo 'Integrated schema: ' . count($fullSchema) . ' tables, SHA-256 ' . hash('sha256', json_encode($fullSchema, JSON_THROW_ON_ERROR)) . "\n";
    echo "Database Backup workflow integration: passed (including full migrated schema)\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    $exit = 1;
} finally {
    if ($server instanceof PDO) {
        foreach ($names as $name) { $server->exec("DROP DATABASE IF EXISTS `{$name}`"); }
    }
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (glob($path . '/*') ?: [] as $child) { $remove($child); }
            @rmdir($path);
        } else { @unlink($path); }
    };
    $remove($private);
}
exit($exit);
