<?php
// Recovery-key recommendation: newest completed package fingerprint, per-user
// acknowledgement, and tolerance for a partially upgraded installation.
// Issue #460.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\BackupKeyRecommendation;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$fingerprint = static fn(string $seed): string => hash('sha256', $seed);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'retailmind');
$pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
$pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT)');
$pdo->exec('CREATE TABLE information_schema.columns (table_schema TEXT, table_name TEXT, column_name TEXT)');
$pdo->exec("INSERT INTO information_schema.tables VALUES ('retailmind','backup_history'),('retailmind','user_backup_key_acknowledgements')");
foreach (['backup_id', 'filename', 'backup_type', 'status', 'key_fingerprint', 'snapshot_at', 'created_at'] as $column) {
    $pdo->exec("INSERT INTO information_schema.columns VALUES ('retailmind','backup_history','{$column}')");
}
foreach (['user_id', 'key_fingerprint', 'acknowledged_at'] as $column) {
    $pdo->exec("INSERT INTO information_schema.columns VALUES ('retailmind','user_backup_key_acknowledgements','{$column}')");
}

$pdo->exec('CREATE TABLE backup_history (
    backup_id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    backup_type TEXT NOT NULL DEFAULT "manual",
    status TEXT NOT NULL,
    key_fingerprint TEXT NULL,
    snapshot_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE user_backup_key_acknowledgements (
    user_id INTEGER NOT NULL,
    key_fingerprint TEXT NOT NULL,
    acknowledged_at TEXT NOT NULL,
    PRIMARY KEY (user_id, key_fingerprint)
)');

$insert = $pdo->prepare('INSERT INTO backup_history (filename, backup_type, status, key_fingerprint, snapshot_at, created_at)
    VALUES (?, ?, ?, ?, ?, ?)');
$insert->execute(['null-fingerprint.rmpkg', 'manual', 'completed', null, '2026-11-06 08:00:00', '2026-11-06 08:00:00']);
$insert->execute(['failed.rmpkg', 'scheduled', 'failed', $fingerprint('failed'), '2026-11-07 08:00:00', '2026-11-07 08:00:00']);
$insert->execute(['older.rmpkg', 'manual', 'completed', $fingerprint('older'), '2026-11-01 08:00:00', '2026-11-01 08:00:00']);
$insert->execute(['first-newest.rmpkg', 'scheduled', 'completed', $fingerprint('first-newest'), '2026-11-05 08:00:00', '2026-11-05 08:00:00']);
$insert->execute(['newest.rmpkg', 'scheduled', 'completed', $fingerprint('newest'), '2026-11-05 08:00:00', '2026-11-05 08:00:00']);

$recommendation = new BackupKeyRecommendation($pdo);
$result = $recommendation->recommendation(1, 'admin');
$assert($result !== null, 'A completed backup with a key fingerprint is recommended');
$assert($result['fingerprint'] === $fingerprint('newest'), 'The newest completed backup wins; failed and null fingerprints are ignored');
$assert($result['short'] === substr($fingerprint('newest'), 0, 8), 'The recommendation exposes a short fingerprint');
$assert($result['filename'] === 'newest.rmpkg', 'The recommendation names the package');
$assert($result['snapshot_at'] === '2026-11-05 08:00:00', 'The recommendation reports the snapshot time');
$assert($recommendation->recommendation(1, 'super_admin') !== null, 'The Super Administrator receives the same recommendation');
$assert($recommendation->recommendation(1, 'cashier') === null, 'Roles without backup capability receive no recommendation');

$recommendation->acknowledge(1, $fingerprint('newest'));
$recommendation->acknowledge(1, $fingerprint('newest'));
$assert((int)$pdo->query('SELECT COUNT(*) FROM user_backup_key_acknowledgements')->fetchColumn() === 1,
    'Acknowledging the same key twice stores one record');
$assert($recommendation->recommendation(1, 'admin') === null, 'An acknowledged key is no longer recommended to that account');
$other = $recommendation->recommendation(2, 'admin');
$assert($other !== null && $other['fingerprint'] === $fingerprint('newest'), 'Acknowledgement is personal to each account');

$pdo->exec('DROP TABLE user_backup_key_acknowledgements');
$pdo->exec("DELETE FROM information_schema.tables WHERE table_name='user_backup_key_acknowledgements'");
$assert($recommendation->recommendation(2, 'admin') !== null, 'A missing acknowledgement table does not break the recommendation');
$recommendation->acknowledge(2, $fingerprint('newest'));
$assert(true, 'A missing acknowledgement table swallows the acknowledgement');

$pdo->exec("DELETE FROM information_schema.columns WHERE table_name='backup_history' AND column_name='key_fingerprint'");
$assert($recommendation->recommendation(1, 'admin') === null, 'A missing fingerprint column suppresses the recommendation');

echo "Backup key recommendation: passed\n";
