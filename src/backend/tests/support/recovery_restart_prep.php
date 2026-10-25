<?php
// Prepares real safety copy and pre-restore recovery state for restart browser test (#312).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../includes/backup.php';

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

$safetyPassphrase = 'BrowserSafetyPassphrase@2026';
$policy = new App\Authorization\RoleCapabilityPolicy();
$safetyService = new App\Backup\SafetyCopyService($pdo, $policy);

$opId = bin2hex(random_bytes(16));
$safetyOpId = bin2hex(random_bytes(16));

// Capture real verified safety copy
$safetyMeta = $safetyService->create($safetyOpId, 1, 'admin', $password, $safetyPassphrase);

$approval = [
    'stage' => 'reconciliation_approved',
    'package_id' => 'pkg-test-restart-123',
    'approval_hash' => hash('sha256', 'restart_approval'),
    'destination_watermark' => hash('sha256', 'restart_watermark'),
    'decisions' => ['user_link' => 1],
    'identity_map' => [1 => 1],
    'file_map' => [],
    'active_administrators' => 1,
    'resulting_access' => [],
    'preservation_closure' => [],
];

App\Backup\RecoveryAuthService::preparePreRestoreState($pdo, $opId, 1, 'admin', $password, $approval, $safetyMeta);
App\Backup\RecoveryStore::transitionStage(App\Backup\RecoveryStore::STAGE_DESTRUCTIVE_START);
App\Backup\RecoveryStore::transitionStage(App\Backup\RecoveryStore::STAGE_DATABASE_REPLACEMENT);

// Simulate table drop during interrupted replacement
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('DROP TABLE users');
$pdo->exec('DROP TABLE roles');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

echo json_encode([
    'operation_id' => $opId,
    'safety_op_id' => $safetyOpId,
    'safety_passphrase' => $safetyPassphrase,
]);
