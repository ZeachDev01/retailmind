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
        $db->exec("CREATE TABLE users (user_id INT PRIMARY KEY, full_name VARCHAR(100), role_id INT, password_hash VARCHAR(255), status VARCHAR(20), session_version INT) ENGINE=InnoDB");
        $insert = $db->prepare("INSERT INTO users VALUES (?, ?, ?, ?, 'active', 1)");
        foreach ([1 => 'Super', 2 => 'Owner', 3 => 'Cashier'] as $id => $name) {
            $insert->execute([$id, $name, $id, password_hash($db === $source ? 'Snapshot-password' : 'Current-password', PASSWORD_DEFAULT)]);
        }
        $db->exec('CREATE TABLE activity_log (log_id INT AUTO_INCREMENT PRIMARY KEY, note TEXT) ENGINE=InnoDB');
        $db->exec('CREATE TABLE products (product_id INT PRIMARY KEY, product_name TEXT, optional_value TEXT NULL, binary_value BLOB, stock INT) ENGINE=InnoDB');
        $migration = require __DIR__ . '/../database/migrations/202609260001_shared_database_backups.php';
        $migration['up']($db);
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
    $passphrase = 'separate-off-server-passphrase';
    $policy = new RoleCapabilityPolicy();
    $backups = new DatabaseBackupService($source, $policy, $private . '/downloads', $source, $uploadRoot);
    $targetBackups = new DatabaseBackupService($target, $policy, $private . '/target-downloads', $target, $uploadRoot);
    $restorer = new DatabaseRestoreService($target, $policy, $targetBackups);

    foreach (['cashier', 'inventory_manager'] as $role) {
        $reject(fn() => $backups->create(3, $role, $passphrase), 'Operational staff cannot create backups');
    }
    foreach (['admin', 'cashier', 'inventory_manager'] as $role) {
        $reject(fn() => $restorer->restore(2, $role, '', 'backup.sql', 'Current-password'), 'Only Super Administrator can restore');
    }
    // Snapshot coordination still refuses concurrent writes and backup captures.
    $coordinator = $backups->coordinator();
    $claim = $coordinator->claim(1, 'super_admin');
    $assert($coordinator->claim(2, 'admin') === null, 'Only one capture may claim the slot');
    $assert($backups->create(2, 'admin', $passphrase)['status'] === DatabaseBackupService::OUTCOME_IN_PROGRESS, 'Busy backup does not start another export');
    $coordinator->beginCapture(1);
    $writer = new PDO($dsn . ';dbname=' . $names[0], $config['username'], $config['password'], $options);
    $reject(fn() => StoreWriteGate::begin($writer), 'Store writes must pause during a capture');
    $coordinator->abortCapture();
    $coordinator->fail($claim, 'fixture');
    StoreWriteGate::begin($writer);
    $writer->rollBack();

    $backup = $backups->create(2, 'admin', $passphrase);
    $assert($backup['status'] === DatabaseBackupService::OUTCOME_READY, 'Administrator can capture an encrypted Store package');
    $encrypted = (string)file_get_contents($backup['path']);
    $decoded = StorePackageFormat::decode($encrypted, $passphrase, SqlBackupFormat::schema($source));
    $sql = $decoded['sql'];
    $assert($decoded['files']['images/test.png'] === $image && $decoded['files']['profile-images/test.png'] === $image, 'Actual Product and profile image bytes round trip');
    $assert(!str_contains($encrypted, 'INSERT INTO') && !str_contains($encrypted, $passphrase), 'Package conceals captured SQL and passphrase');
    $assert(str_ends_with($backup['filename'], '.rmpkg'), 'Downloads use encrypted package extension');
    $assert($backups->history('admin')[0]['format'] === 'Encrypted Store package', 'History reports SQL format');
    $reject(fn() => $backups->resolveDownload($backup['token'], 1, 'super_admin'), 'Downloads are requester-bound');
    $assert($backups->resolveDownload($backup['token'], 2, 'admin')['path'] === $backup['path'], 'Owner resolves own download');
    $superBackup = $backups->create(1, 'super_admin', $passphrase);
    $assert($superBackup['status'] === DatabaseBackupService::OUTCOME_READY, 'Super Administrator can create backups too');
    $assert($decoded['rows']['backup_operations'] === [] && $decoded['rows']['store_write_gate'] === [], 'Packages exclude source tokens, private artifact paths and transient pause state');
    $systemBackup = $backups->createForSystem($passphrase);
    $assert(str_starts_with((string)file_get_contents($systemBackup['path']), "RMSTORE1\n"), 'System export also requires encrypted package format');
    $backups->discardAfterDelivery($systemBackup['path']);

    $before = (string)$target->query('SELECT password_hash FROM users WHERE user_id=1')->fetchColumn();
    $preflight = $restorer->preflight(1, 'super_admin', $backup['path'], $backup['filename'], 'Current-password', $passphrase);
    $assert(!$preflight['valid'] && count($preflight['conflicts']) >= 3 && !$preflight['replacement_authorized'], 'Preflight reports colliding actor IDs and never permits replacement');
    $assert($target->query('SELECT password_hash FROM users WHERE user_id=1')->fetchColumn() === $before, 'Preflight never changes credentials');
    $reject(fn() => $restorer->preflight(1, 'super_admin', $backup['path'], $backup['filename'], 'Snapshot-password', $passphrase), 'Obsolete source password cannot authorize destination preflight');
    $reject(fn() => $restorer->preflight(1, 'super_admin', $backup['path'], $backup['filename'], 'Current-password', 'wrong-offline-passphrase'), 'Wrong independent passphrase rejected');
    $assert(!$restorer->preflight(2, 'admin', $backup['path'], $backup['filename'], 'Current-password', $passphrase)['replacement_authorized'], 'Administrator can validate a backup without replacing data');
    $bodyWithDuplicate = preg_replace('/\n-- SHA256: [a-f0-9]{64}\n$/', '', $sql);
    $bodyWithDuplicate = str_replace("X'" . bin2hex('person-3') . "'", "X'" . bin2hex('PERSON-2') . "'", $bodyWithDuplicate);
    $duplicateSql = $bodyWithDuplicate . "\n-- SHA256: " . hash('sha256', $bodyWithDuplicate) . "\n";
    $duplicatePath = $private . '/duplicate.rmpkg';
    file_put_contents($duplicatePath, StorePackageFormat::encode($duplicateSql, $decoded['files'], SqlBackupFormat::schema($source), $passphrase));
    $reject(fn() => $restorer->preflight(1, 'super_admin', $duplicatePath, 'duplicate.rmpkg', 'Current-password', $passphrase), 'MySQL case-insensitive unique semantics reject hostile duplicate usernames');
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
    $full->prepare('INSERT INTO users (full_name, username, password_hash, role_id, must_change_password) VALUES (?, ?, ?, ?, 0)')
        ->execute(['Full schema fixture', 'sql_roundtrip_fixture', password_hash('Full-schema-password', PASSWORD_DEFAULT), $superRole]);
    $fullActor = (int)$full->lastInsertId();
    $receiptSentinel = '{"reference_number":"00000000000000000000000000123456","fee_amount":"123.45","store":{"name":"Saved Store","footer":"Saved footer"}}';
    $full->prepare("INSERT INTO gcash_transactions (transaction_type,reference_number,amount,cashier_id,verification_status,receipt_details_json) VALUES ('Cash-In','00000000000000000000000000123456',12345,?,'Completed',?)")->execute([$fullActor, $receiptSentinel]);
    foreach (StorePackageFormat::references(['users' => $full->query('SELECT profile_image FROM users')->fetchAll(), 'products' => $full->query('SELECT product_image FROM products')->fetchAll()]) as $reference) {
        file_put_contents($uploadRoot . '/' . $reference, $image);
    }
    $fullBackups = new DatabaseBackupService($full, $policy, $private . '/full-downloads', $full, $uploadRoot);
    $fullBackup = $fullBackups->create($fullActor, 'super_admin', $passphrase);
    $full->prepare('UPDATE users SET full_name=? WHERE user_id=?')->execute(['Changed since snapshot', $fullActor]);
    $fullRestorer = new DatabaseRestoreService($full, $policy, $fullBackups);
    $fullSchema = SqlBackupFormat::schema($full);
    foreach (['privileges', 'role_privileges', 'user_privileges', 'restore_preserved_activity', 'emergency_access_sessions'] as $retiredTable) {
        $assert(!isset($fullSchema[$retiredTable]), 'Encrypted baseline must not recreate retired storage: ' . $retiredTable);
    }
    $fullPreflight = $fullRestorer->preflight($fullActor, 'super_admin', $fullBackup['path'], $fullBackup['filename'], 'Full-schema-password', $passphrase);
    $assert($fullPreflight['replacement_authorized'] === false, 'Complete migrated schema preflight never authorizes replacement');
    $assert($full->query('SELECT full_name FROM users WHERE user_id=' . $fullActor)->fetchColumn() === 'Changed since snapshot', 'Complete migrated schema preflight leaves destination unchanged');
    $fullDecoded = StorePackageFormat::decode((string)file_get_contents($fullBackup['path']), $passphrase, SqlBackupFormat::schema($full));
    file_put_contents($legacySql, $fullDecoded['sql']);
    $fullRestorer->restore($fullActor, 'super_admin', $legacySql, 'legacy-private.sql', 'Full-schema-password');
    $assert($full->query('SELECT full_name FROM users WHERE user_id=' . $fullActor)->fetchColumn() === 'Full schema fixture', 'Complete migrated application schema must round-trip');
    $assert($full->query("SELECT receipt_details_json FROM gcash_transactions WHERE reference_number='00000000000000000000000000123456'")->fetchColumn() === $receiptSentinel, 'GCash receipt snapshot survives full backup and restore byte for byte');
    $full->exec('CREATE VIEW unsupported_fixture_view AS SELECT user_id FROM users');
    $reject(fn() => $fullBackups->create($fullActor, 'super_admin', $passphrase), 'Unsupported custom objects must fail rather than produce an incomplete backup');
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
