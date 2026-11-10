<?php
if (getenv('RUN_DB_TESTS') !== '1') { echo "Identity Reconciliation MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$tmpRecovery = sys_get_temp_dir() . '/rm_rec_test_' . bin2hex(random_bytes(6));
mkdir($tmpRecovery, 0700, true);
putenv('BACKUP_STORAGE_PATH=' . $tmpRecovery);
$_ENV['BACKUP_STORAGE_PATH'] = $tmpRecovery;
$fixture = static function (string $action) use ($database): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/theme_fixture.php', $action, $database], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($errors . $output);
};
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$failure = null;
try {
    $fixture('create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rows = [];
    foreach (App\Backup\SqlBackupFormat::schema($pdo) as $table => $definition) {
        $columns = implode(',', array_map([App\Backup\DatabaseSnapshotWriter::class, 'identifier'], $definition['columns']));
        $rows[$table] = $pdo->query('SELECT ' . $columns . ' FROM ' . App\Backup\DatabaseSnapshotWriter::identifier($table))->fetchAll(PDO::FETCH_ASSOC);
    }
    $package = ['rows' => $rows, 'files' => [], 'manifest' => ['origin_id' => str_repeat('a', 32), 'package_id' => str_repeat('b', 32)]];
    $service = new App\Backup\IdentityReconciliationService($pdo, new App\Authorization\RoleCapabilityPolicy());
    $choices = [];
    foreach ($service->review(2, 'super_admin', $package)['source_users'] as $person) {
        if (!$person['historical']) $choices[$person['user_id']] = ['action' => 'link', 'destination_id' => $person['user_id']];
    }
    $first = $service->review(2, 'super_admin', $package, $choices);
    $assert($first['ready'], 'The initial existing-account match must pass');
    // A previous restore has left the source privileged identity as a Historical Actor.
    foreach ($first['rows']['users'] as $user) {
        if (!str_starts_with($user['username'], 'rm_h_')) continue;
        $columns = implode(',', array_map([App\Backup\DatabaseSnapshotWriter::class, 'identifier'], array_keys($user)));
        $pdo->prepare('INSERT INTO users (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($user), '?')) . ')')->execute(array_values($user));
    }
    $again = $service->review(2, 'super_admin', $package, $choices);
    $assert($again['ready'], 'Matching the same existing accounts must still pass after an earlier restore of this backup');
    echo "Repeated backup identity review: passed\n";
} catch (Throwable $e) { $failure = $e; }
finally {
    $fixture('cleanup');
    if (is_dir($tmpRecovery)) {
        foreach (glob($tmpRecovery . '/*') as $f) @unlink($f);
        @rmdir($tmpRecovery);
    }
}
if ($failure !== null) { fwrite(STDERR, (string)$failure); exit(1); }
