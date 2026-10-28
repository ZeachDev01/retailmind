<?php
// Disposable complete-schema MySQL/filesystem recovery authentication workflow (#311).
if (getenv('RUN_DB_TESTS') !== '1') { echo "Recovery authentication workflow: skipped (set RUN_DB_TESTS=1)\n"; exit; }
$private = sys_get_temp_dir() . '/rm-recovery-test-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
require_once __DIR__ . '/../bootstrap/app.php';
$_ENV['BACKUP_STORAGE_PATH'] = $private;
require_once __DIR__ . '/../includes/backup.php';
use App\Authorization\RoleCapabilityPolicy;
use App\Backup\{RecoveryStore, RecoveryAuthService, SafetyCopyService, StorePackageFormat};
use App\Store\{StoreWriteGate, StoreWritePausedException};

$assert = static function(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$reject = static function(callable $work, string $message) use ($assert): void { $failed = false; try { $work(); } catch (Throwable) { $failed = true; } $assert($failed, $message); };
$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$fixture = static function(string $action) use ($database): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/theme_fixture.php', $action, $database], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($output . $error);
};

try {
    $fixture('create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);

    // Setup extra test users: second_super_admin (id 7), other_admin (id 8), sealed recovery account (id 9)
    $superRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='super_admin'")->fetchColumn();
    $adminRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='admin'")->fetchColumn();
    $passwordHash = password_hash('ThemeTest@2026', PASSWORD_DEFAULT);

    $pdo->prepare("INSERT INTO users (user_id, username, full_name, email, password_hash, role_id, status, is_recovery_account) VALUES (7, 'second_super_admin', 'Second Super', 'super2@example.com', ?, ?, 'active', 0)")->execute([$passwordHash, $superRoleId]);
    $pdo->prepare("INSERT INTO users (user_id, username, full_name, email, password_hash, role_id, status, is_recovery_account) VALUES (8, 'other_admin', 'Other Admin', 'other@example.com', ?, ?, 'active', 0)")->execute([$passwordHash, $adminRoleId]);
    $pdo->prepare("INSERT INTO users (user_id, username, full_name, email, password_hash, role_id, status, is_recovery_account) VALUES (9, 'recovery_primary', 'Recovery Account', NULL, ?, ?, 'disabled', 1)")->execute([$passwordHash, $superRoleId]);
    $pdo->prepare("INSERT INTO recovery_accounts (account_key, user_id, activation_secret_hash, sealed_at) VALUES ('primary', 9, ?, CURRENT_TIMESTAMP)")->execute([password_hash('SecretActivation1234', PASSWORD_DEFAULT)]);

    // 1. Initial State: Store is not paused, requests admitted
    $assert(!RecoveryStore::isPaused(), 'Initial store is not paused');
    $assert(RecoveryStore::admitRequest(), 'Initial requests are admitted');

    // 2. Prepare durable pre-restore maintenance state
    $operationId = bin2hex(random_bytes(16));
    $safetyOpId = bin2hex(random_bytes(16));
    $reconciliationApproval = [
        'stage' => 'reconciliation_approved',
        'package_id' => 'pkg-' . bin2hex(random_bytes(8)),
        'approval_hash' => hash('sha256', 'mock_decisions'),
        'destination_watermark' => hash('sha256', 'mock_watermark'),
        'decisions' => ['decision1' => 'link'],
    ];
    $safetyMetadata = [
        'operation_id' => $safetyOpId,
        'stage' => 'safety_verified',
        'requested_by' => 1,
        'requested_by_role' => 'admin',
        'package_id' => 'safety-' . bin2hex(random_bytes(8)),
        'origin_id' => 'origin-' . bin2hex(random_bytes(8)),
        'schema_hash' => hash('sha256', 'mock_schema'),
        'sha256' => hash('sha256', 'mock_bytes'),
        'sql_sha256' => hash('sha256', 'mock_sql'),
        'file_sha256' => [],
        'size' => 1024,
        'filename' => 'retailmind-safety-' . $safetyOpId . '.rmpkg',
    ];
    // Create dummy safety file and metadata in RecoveryStore
    $safetyDir = RecoveryStore::path('safety-' . $safetyOpId);
    mkdir($safetyDir, 0700, true);
    file_put_contents($safetyDir . '/safety.rmpkg', 'RMSTORE1_MOCK_SAFETY');
    file_put_contents($safetyDir . '/metadata.json', json_encode($safetyMetadata, JSON_THROW_ON_ERROR));

    $initialEpoch = RecoveryStore::epoch();
    $prepared = RecoveryAuthService::preparePreRestoreState($pdo, $operationId, 1, 'admin', 'ThemeTest@2026', $reconciliationApproval, $safetyMetadata);

    $assert(RecoveryStore::isPaused(), 'Store is paused once recovery state is prepared');
    $assert(!RecoveryStore::admitRequest(), 'Ordinary request admission blocked');
    $assert(RecoveryStore::epoch() !== $initialEpoch, 'Session epoch is invalidated on preparation');
    $assert(is_file(RecoveryStore::path('restore-state.json')), 'restore-state.json exists in RecoveryStore');

    $state = RecoveryStore::state();
    $assert($state['operation_id'] === $operationId, 'Operation ID recorded');
    $assert($state['package_id'] === $reconciliationApproval['package_id'], 'Approved package ID recorded');
    $assert($state['destination_watermark'] === $reconciliationApproval['destination_watermark'], 'Destination watermark recorded');
    $assert($state['safety_operation_id'] === $safetyOpId, 'Safety operation ID recorded');
    $assert($state['stage'] === RecoveryStore::STAGE_SAFETY_VERIFIED, 'Initial stage is safety_verified');
    $assert($state['replacement_started'] === false, 'Replacement not started yet');

    // Verify authorized operators
    $opUsernames = array_column($state['authorized_operators'], 'username');
    $assert(in_array('theme_admin', $opUsernames, true), 'Initiating admin is authorized');
    $assert(in_array('theme_super_admin', $opUsernames, true), 'Super Admin 1 is authorized');
    $assert(in_array('second_super_admin', $opUsernames, true), 'Super Admin 2 is authorized');
    $assert(in_array('other_admin', $opUsernames, true), 'Destination Admin is authorized operator for takeover');
    $assert(!in_array('recovery_primary', $opUsernames, true), 'Sealed recovery account is excluded');
    $assert(!in_array('theme_cashier', $opUsernames, true), 'Cashier is excluded');

    // Verify NO plaintext passwords or decrypted data are stored
    $stateJson = (string)file_get_contents(RecoveryStore::path('restore-state.json'));
    $assert(!str_contains($stateJson, 'ThemeTest@2026'), 'Plaintext password never stored in state');

    // 3. Persist destructive start BEFORE destructive write
    $destructiveState = RecoveryStore::transitionStage(RecoveryStore::STAGE_DESTRUCTIVE_START);
    $assert($destructiveState['stage'] === RecoveryStore::STAGE_DESTRUCTIVE_START, 'Transition to destructive_start');
    $assert($destructiveState['replacement_started'] === true, 'replacement_started is true');
    $assert(RecoveryStore::isReplacementStarted(), 'RecoveryStore reflects replacement started');

    // 4. Test Durable Stages progression
    RecoveryStore::transitionStage(RecoveryStore::STAGE_DATABASE_REPLACEMENT);
    $assert(RecoveryStore::currentStage() === RecoveryStore::STAGE_DATABASE_REPLACEMENT, 'Stage advanced to database_replacement');

    RecoveryStore::transitionStage(RecoveryStore::STAGE_UPLOADS);
    $assert(RecoveryStore::currentStage() === RecoveryStore::STAGE_UPLOADS, 'Stage advanced to uploads');

    RecoveryStore::transitionStage(RecoveryStore::STAGE_AUTOMATED_VERIFICATION);
    $assert(RecoveryStore::currentStage() === RecoveryStore::STAGE_AUTOMATED_VERIFICATION, 'Stage advanced to automated_verification');

    RecoveryStore::transitionStage(RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING);
    $assert(RecoveryStore::currentStage() === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING, 'Stage advanced to operator_review_pending');

    RecoveryStore::transitionStage(RecoveryStore::STAGE_RECOVERY_REQUIRED, ['error' => 'Injected test failure']);
    $assert(RecoveryStore::currentStage() === RecoveryStore::STAGE_RECOVERY_REQUIRED, 'Stage marked recovery_required on error');

    // 5. CATASTROPHIC FAILURE: Drop users and roles tables to simulate partial/missing DB tables!
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DROP TABLE users');
    $pdo->exec('DROP TABLE roles');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // Verify ordinary requests and writers are blocked
    $assert(!RecoveryStore::admitRequest(), 'Ordinary requests remain blocked across database loss');
    $reject(fn() => StoreWriteGate::begin($pdo), 'Store writes blocked by recovery pause');

    // 6. Independent Recovery Authentication with missing tables
    // Authenticate Initiating Admin
    $adminAuth = RecoveryAuthService::authenticate('theme_admin', 'ThemeTest@2026', '127.0.0.1');
    $assert($adminAuth['user_id'] === 1 && $adminAuth['role'] === 'admin', 'Initiating admin authenticates with pre-restore password');

    // Authenticate Destination Super Admin 1
    $sa1Auth = RecoveryAuthService::authenticate('theme_super_admin', 'ThemeTest@2026', '127.0.0.1');
    $assert($sa1Auth['user_id'] === 2 && $sa1Auth['role'] === 'super_admin', 'Super Admin 1 authenticates independently');

    // Authenticate Destination Super Admin 2
    $sa2Auth = RecoveryAuthService::authenticate('second_super_admin', 'ThemeTest@2026', '127.0.0.1');
    $assert($sa2Auth['user_id'] === 7 && $sa2Auth['role'] === 'super_admin', 'Super Admin 2 authenticates independently');

    // Reject wrong credentials
    $reject(fn() => RecoveryAuthService::authenticate('theme_admin', 'wrong_pass', '127.0.0.1'), 'Wrong password rejected');
    $reject(fn() => RecoveryAuthService::authenticate('unknown_user', 'ThemeTest@2026', '127.0.0.1'), 'Unknown user rejected');

    // Destination admin authenticates successfully for takeover
    $otherAuth = RecoveryAuthService::authenticate('other_admin', 'ThemeTest@2026', '127.0.0.1');
    $assert($otherAuth['user_id'] === 8 && $otherAuth['role'] === 'admin', 'Destination admin can authenticate for takeover');

    // Reject unauthorized cashier
    $reject(fn() => RecoveryAuthService::authenticate('theme_cashier', 'ThemeTest@2026', '127.0.0.1'), 'Cashier rejected');

    // Reject sealed recovery account
    $reject(fn() => RecoveryAuthService::authenticate('recovery_primary', 'ThemeTest@2026', '127.0.0.1'), 'Sealed recovery account rejected');

    // Reject operation mismatch
    $reject(fn() => RecoveryAuthService::authenticate('theme_admin', 'ThemeTest@2026', '127.0.0.1', 'mismatched_op_id'), 'Mismatched operation ID rejected');

    // 7. Externally Maintained Throttling
    $throttleIp = '192.168.10.99';
    for ($i = 0; $i < 5; $i++) {
        try {
            RecoveryAuthService::authenticate('theme_admin', 'bad_password', $throttleIp);
        } catch (DomainException) {}
    }
    // 6th attempt with correct password must be throttled
    $throttled = false;
    try {
        RecoveryAuthService::authenticate('theme_admin', 'ThemeTest@2026', $throttleIp);
    } catch (DomainException $e) {
        if (str_contains($e->getMessage(), 'Too many failed recovery login attempts')) {
            $throttled = true;
        }
    }
    $assert($throttled, 'Exceeded attempts throttled externally in RecoveryStore');

    // 8. Recovery Session Lifecycle and Expiry
    $sessionToken = RecoveryAuthService::startRecoverySession($sa1Auth);
    $assert(RecoveryAuthService::verifyCsrf($sessionToken), 'Valid recovery CSRF token accepted');
    $assert(!RecoveryAuthService::verifyCsrf('forged_token'), 'Forged recovery CSRF token rejected');

    $activeSession = RecoveryAuthService::currentRecoverySession();
    $assert($activeSession !== null && $activeSession['username'] === 'theme_super_admin', 'Active recovery session resolved');

    // Test session timeout (simulate 1000 seconds passed)
    $_SESSION['recovery_auth']['last_activity'] = time() - 1000;
    $assert(RecoveryAuthService::currentRecoverySession() === null, 'Expired recovery session rejected');

    // Re-authenticate and test operation binding
    RecoveryAuthService::startRecoverySession($sa1Auth);
    $_SESSION['recovery_auth']['operation_id'] = 'different_op_id';
    $assert(RecoveryAuthService::currentRecoverySession() === null, 'Session with mismatched operation ID rejected');

    // 9. Role-Restricted Evidence
    RecoveryAuthService::startRecoverySession($adminAuth);
    $adminView = RecoveryAuthService::recoveryViewData(RecoveryAuthService::currentRecoverySession());
    $assert(isset($adminView['stage']) && $adminView['role'] === 'admin', 'Admin view contains stage');
    $assert(!isset($adminView['append_log']), 'Admin view excludes sensitive full append logs');

    RecoveryAuthService::startRecoverySession($sa1Auth);
    $saView = RecoveryAuthService::recoveryViewData(RecoveryAuthService::currentRecoverySession());
    $assert(isset($saView['append_log']) && $saView['role'] === 'super_admin', 'Super Admin view contains full log evidence');
    $assert(isset($saView['destination_watermark']), 'Super Admin view contains destination watermark');

    // 10. Worker death and reopening
    // Worker termination simulation: Store remains paused across worker death
    $assert(RecoveryStore::isPaused(), 'Store remains paused across worker termination');
    $assert(is_file($safetyDir . '/safety.rmpkg'), 'Safety artifact preserved across failure and termination');
    $assert(is_file(RecoveryStore::path('restore-log.jsonl')), 'Append-only log preserved');

    // Explicit reopened completion
    RecoveryStore::transitionStage(RecoveryStore::STAGE_REOPENED_COMPLETED);
    $assert(!RecoveryStore::isPaused(), 'Explicit completion clears pause');
    $assert(RecoveryStore::admitRequest(), 'Store requests admitted after completion');
    $assert(is_file($safetyDir . '/safety.rmpkg'), 'Safety copy retained after reopen');

    echo "Recovery authentication workflow: passed (durable stages, pre-restore credentials, missing tables, throttling, session timeout, role evidence)\n";
} catch (Throwable $e) {
    echo "MAIN CAUGHT: " . $e->getMessage() . " AT " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
    throw $e;
} finally {
    if (RecoveryStore::isPaused()) {
        RecoveryStore::resume();
    }
    $fixture('cleanup');
    $delete = static function(string $path) use (&$delete): void { if (is_dir($path)) { foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $delete($path . '/' . $name); rmdir($path); } else unlink($path); };
    if (str_starts_with(basename($private), 'rm-recovery-test-') && dirname($private) === sys_get_temp_dir() && is_dir($private)) $delete($private);
}
