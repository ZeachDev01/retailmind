<?php
// Prepares pre-restore recovery state for tests (#311).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';

$database = $argv[1] ?? '';
$password = $argv[2] ?? 'ThemeTest@2026';

if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Invalid disposable database name.');
}

$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$superRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='super_admin'")->fetchColumn();
$adminRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='admin'")->fetchColumn();
$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo->prepare("INSERT INTO users (user_id, username, full_name, email, password_hash, role_id, status, is_recovery_account) VALUES (8, 'other_admin', 'Other Admin', 'other@example.com', ?, ?, 'active', 0)")->execute([$hash, $adminRoleId]);
$pdo->prepare("INSERT INTO users (user_id, username, full_name, email, password_hash, role_id, status, is_recovery_account) VALUES (9, 'recovery_primary', 'Recovery Account', NULL, ?, ?, 'disabled', 1)")->execute([$hash, $superRoleId]);
$pdo->prepare("INSERT INTO recovery_accounts (account_key, user_id, activation_secret_hash, sealed_at) VALUES ('primary', 9, ?, CURRENT_TIMESTAMP)")->execute([password_hash('Secret1234', PASSWORD_DEFAULT)]);

$opId = bin2hex(random_bytes(16));
$safetyOpId = bin2hex(random_bytes(16));
$approval = [
    'package_id' => 'pkg-test-12345',
    'approval_hash' => hash('sha256', 'test_approval'),
    'destination_watermark' => hash('sha256', 'test_watermark'),
    'decisions' => ['user_link' => 1],
];
$safetyMeta = [
    'operation_id' => $safetyOpId,
    'stage' => 'safety_verified',
    'requested_by' => 1,
    'requested_by_role' => 'admin',
    'package_id' => 'pkg-safety-999',
    'origin_id' => 'origin-001',
    'schema_hash' => 'schema-hash-abc',
    'sha256' => 'sha256-mock',
    'sql_sha256' => 'sql-sha256-mock',
    'file_sha256' => [],
    'size' => 2048,
    'filename' => 'retailmind-safety-' . $safetyOpId . '.rmpkg',
];

App\Backup\RecoveryAuthService::preparePreRestoreState($pdo, $opId, 1, 'admin', $password, $approval, $safetyMeta);
App\Backup\RecoveryStore::transitionStage(App\Backup\RecoveryStore::STAGE_DESTRUCTIVE_START);
App\Backup\RecoveryStore::transitionStage(App\Backup\RecoveryStore::STAGE_DATABASE_REPLACEMENT);

// Simulate table drop / catastrophic missing tables during replacement
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('DROP TABLE users');
$pdo->exec('DROP TABLE roles');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

echo json_encode(['operation_id' => $opId, 'safety_op_id' => $safetyOpId]);
