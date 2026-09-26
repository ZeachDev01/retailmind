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
use App\Services\DatabaseBackupService;
use App\Services\DatabaseRestoreService;
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
    $policy = new RoleCapabilityPolicy();
    $backups = new DatabaseBackupService($source, $policy, $private . '/downloads', $source);
    $targetBackups = new DatabaseBackupService($target, $policy, $private . '/target-downloads', $target);
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
    $assert($backup['status'] === DatabaseBackupService::OUTCOME_READY, 'Administrator can capture SQL without a key');
    $sql = (string)file_get_contents($backup['path']);
    $assert(str_starts_with($sql, SqlBackupFormat::HEADER) && str_contains($sql, 'INSERT INTO'), 'SQL is not encrypted');
    $assert(str_ends_with($backup['filename'], '.sql'), 'Downloads use SQL extension');
    $assert($backups->history('admin')[0]['format'] === 'SQL (unencrypted)', 'History reports SQL format');
    $reject(fn() => $backups->resolveDownload($backup['token'], 1, 'super_admin'), 'Downloads are requester-bound');
    $assert($backups->resolveDownload($backup['token'], 2, 'admin')['path'] === $backup['path'], 'Owner resolves own download');
    $superBackup = $backups->create(1, 'super_admin');
    $assert($superBackup['status'] === DatabaseBackupService::OUTCOME_READY, 'Super Administrator can create backups too');

    $restore = fn(string $path, string $name = 'backup.sql', string $password = 'Current-password') => $restorer->restore(1, 'super_admin', $path, $name, $password);
    $reject(fn() => $restore($backup['path'], 'backup.sql', 'wrong'), 'Wrong password must be rejected');
    $reject(fn() => $restore($backup['path'], 'backup.rmbak'), 'Old encrypted extension is rejected');
    file_put_contents($private . '/broken.sql', substr($sql, 0, -20));
    $reject(fn() => $restore($private . '/broken.sql'), 'Truncated SQL must be rejected');
    $assert((int)$target->query('SELECT stock FROM products WHERE product_id=9')->fetchColumn() === 3, 'Rejected files/passwords leave target untouched');
    $assert(!RecoveryStore::isPaused(), 'Rejected input does not leave maintenance active');

    // An admitted request prevents destructive restore, even across DDL commits.
    $lease = fopen(RecoveryStore::path('requests.lock'), 'c+b');
    flock($lease, LOCK_SH);
    $reject(fn() => $restore($backup['path']), 'Existing requests must finish before restore');
    fclose($lease);

    // A safety persistence failure must happen before replacement.
    mkdir(RecoveryStore::path('latest-safety.sql'));
    $reject(fn() => $restore($backup['path']), 'Safety backup failure aborts replacement');
    rmdir(RecoveryStore::path('latest-safety.sql'));
    $assert((int)$target->query('SELECT stock FROM products WHERE product_id=9')->fetchColumn() === 3, 'Safety failure leaves current records intact');
    $assert(!RecoveryStore::isPaused(), 'Non-destructive safety failure reopens the Store');

    $epochBefore = RecoveryStore::epoch();
    $result = $restore($backup['path']);
    $assert($result['statements'] > 0, 'Restore executes a real full replacement');
    $recovered = $target->query('SELECT * FROM products WHERE product_id=1')->fetch();
    $assert($recovered['product_name'] === $text && $recovered['optional_value'] === null && $recovered['binary_value'] === "\x00\xff\x01" && (int)$recovered['stock'] === 42, 'Text, binary, null, and stock values round-trip');
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
    $reject(fn() => $restore($backup['path'], 'backup.sql', 'Snapshot-password'), 'Injected mid-import failure is reported');
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
    $assert(!is_file($backup['path']) && is_file($legacy) && is_file(RecoveryStore::path('latest-safety.sql')), 'Cleanup removes old temporary SQL only, not safety or legacy files');
    $backups->discardAfterDelivery($superBackup['path']);
    $assert(!is_file($superBackup['path']), 'Delivered artifacts are removed');
    // Round-trip the repository's complete shipped schema plus all migrations,
    // not just the small failure-injection fixture above.
    $full = new PDO($dsn . ';dbname=' . $names[2], $config['username'], $config['password'], $options);
    $full->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (split_sql_statements((string)file_get_contents(__DIR__ . '/../sql/schema.sql')) as $statement) {
        $full->exec($statement);
    }
    $full->exec('SET FOREIGN_KEY_CHECKS=1');
    require_once __DIR__ . '/../includes/functions.php';
    (new \App\Database\MigrationRunner($full, __DIR__ . '/../database/migrations'))->runPending();
    $superRole = (int)$full->query("SELECT role_id FROM roles WHERE role_name='super_admin'")->fetchColumn();
    $full->prepare('INSERT INTO users (full_name, username, password_hash, role_id, must_change_password) VALUES (?, ?, ?, ?, 0)')
        ->execute(['Full schema fixture', 'sql_roundtrip_fixture', password_hash('Full-schema-password', PASSWORD_DEFAULT), $superRole]);
    $fullActor = (int)$full->lastInsertId();
    $fullBackups = new DatabaseBackupService($full, $policy, $private . '/full-downloads', $full);
    $fullBackup = $fullBackups->create($fullActor, 'super_admin');
    $full->prepare('UPDATE users SET full_name=? WHERE user_id=?')->execute(['Changed since snapshot', $fullActor]);
    $fullRestorer = new DatabaseRestoreService($full, $policy, $fullBackups);
    $fullRestorer->restore($fullActor, 'super_admin', $fullBackup['path'], $fullBackup['filename'], 'Full-schema-password');
    $assert($full->query('SELECT full_name FROM users WHERE user_id=' . $fullActor)->fetchColumn() === 'Full schema fixture', 'Complete migrated application schema must round-trip');
    $full->exec('CREATE VIEW unsupported_fixture_view AS SELECT user_id FROM users');
    $reject(fn() => $fullBackups->create($fullActor, 'super_admin'), 'Unsupported custom objects must fail rather than produce an incomplete backup');
    $assert(!$fullBackups->status()['paused'], 'Failed unsupported capture releases the write gate');
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
