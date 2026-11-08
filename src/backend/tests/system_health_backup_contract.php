<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/SystemHealthService.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$recoveryPath = sys_get_temp_dir() . '/retailmind-health-' . bin2hex(random_bytes(6));
mkdir($recoveryPath, 0700, true);
putenv('BACKUP_STORAGE_PATH=' . $recoveryPath);
file_put_contents($recoveryPath . '/backup-encryption.key', str_repeat('a', 64) . "\n");
$pdo->exec('CREATE TABLE backup_history (
    filename TEXT,
    backup_type TEXT,
    status TEXT,
    envelope_version TEXT,
    created_at TEXT
)');
$pdo->exec("INSERT INTO backup_history
    (filename, backup_type, status, envelope_version, created_at)
    VALUES ('database_20261109.rmpkg', 'manual', 'completed', 'RMSTORE2', datetime('now'))");

$checks = (new SystemHealthService($pdo, dirname(__DIR__)))->checks();
$backup = array_values(array_filter(
    $checks,
    static fn(array $check): bool => $check['name'] === 'Database backup'
))[0] ?? null;

$assert(is_array($backup), 'System Health must expose a Database backup check');
$assert($backup['status'] === 'healthy', 'A recent completed RMSTORE2 backup must be healthy');
$assert(str_contains($backup['detail'], 'Encrypted database backup verified at creation'), 'Health detail must describe encrypted verification evidence');
$assert(!str_contains($backup['detail'], 'SQL backup'), 'Health detail must not describe the encrypted package as a plain SQL backup');

unlink($recoveryPath . '/backup-encryption.key');
$checks = (new SystemHealthService($pdo, dirname(__DIR__)))->checks();
$backup = array_values(array_filter(
    $checks,
    static fn(array $check): bool => $check['name'] === 'Database backup'
))[0] ?? null;
$assert($backup['status'] === 'warning', 'Missing recovery key must require attention');
$assert($backup['detail'] === 'Recovery key unavailable; import the key before relying on this backup.', 'Missing recovery key guidance must be explicit');

echo "System Health backup contract: passed\n";
