<?php
// A stranded backup row must not pause a host that cannot run backups.
putenv('BACKUP_STORAGE_PATH=');
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Backup\RecoveryStore;
use App\Store\StoreWriteGate;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'retailmind');
$pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
$pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT)');
$pdo->exec("INSERT INTO information_schema.tables VALUES ('retailmind', 'backup_operations')");
$pdo->exec('CREATE TABLE backup_operations (operation_key TEXT, active_slot INTEGER, state TEXT, requested_by INTEGER, started_at TEXT)');
$pdo->exec("INSERT INTO backup_operations VALUES ('stranded', 1, 'capturing', 1, '2026-09-01 00:00:00')");

$activeStatus = StoreWriteGate::status($pdo);
if (($activeStatus['paused'] ?? null) !== true) {
    fwrite(STDERR, "Backup unavailable status: an available host did not report an active backup.\n");
    exit(1);
}

ini_set('open_basedir', dirname(__DIR__, 3));
if (RecoveryStore::isAvailable()) {
    fwrite(STDERR, "Backup unavailable status: test host still permits recovery storage.\n");
    exit(1);
}

$status = StoreWriteGate::status($pdo);
if (($status['paused'] ?? null) !== false || ($status['state'] ?? null) !== 'idle') {
    fwrite(STDERR, "Backup unavailable status: stranded row still pauses an unsupported host.\n");
    exit(1);
}

echo "Backup unavailable status: passed\n";
