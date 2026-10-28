<?php
// Contract and integration test for Ticket #350: Protected restore flow, durable stages, and authenticated takeover.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database restore takeover contract: skipped (set RUN_DB_TESTS=1)\n";
    exit;
}

$private = sys_get_temp_dir() . '/rm-takeover-test-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
$_ENV['BACKUP_STORAGE_PATH'] = $private;
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\{
    DatabaseBackupService,
    DatabaseRestoreService,
    IdentityReconciliationService,
    RecoveryAuthService,
    RecoveryStore,
    SafetyCopyService,
    StorePackageFormat
};

$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$reject = static function(callable $work, string $message) use ($assert): void {
    $failed = false;
    try {
        $work();
    } catch (Throwable) {
        $failed = true;
    }
    $assert($failed, $message);
};

$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$fixture = static function(string $action) use ($database): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/support/theme_fixture.php', $action, $database], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($output . $error);
};

try {
    $fixture('cleanup');
    $fixture('create');
    $fixture('reports');
    $fixture('receipts');

    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );

    // Setup extra test users:
    // User 1 = initiator Admin (theme_admin)
    // User 2 = destination Super Admin (theme_super_admin)
    // User 8 = second active destination Admin (other_admin)
    // User 4 = cashier (theme_cashier)
    $adminRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='admin'")->fetchColumn();
    $superRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name='super_admin'")->fetchColumn();
    $password = 'ThemeTest@2026';
    $takeoverPassword = 'TakeoverAdmin@2026';
    $hashOtherAdmin = password_hash($takeoverPassword, PASSWORD_DEFAULT);

    $pdo->prepare("INSERT INTO users (user_id, username, full_name, email, password_hash, role_id, status, is_recovery_account) VALUES (8, 'other_admin', 'Other Admin', 'other@example.com', ?, ?, 'active', 0)")->execute([$hashOtherAdmin, $adminRoleId]);

    $pdo->exec("DELETE FROM user_roles WHERE user_id = 2 AND role_id != 2");

    $policy = new RoleCapabilityPolicy();
    $backupService = new DatabaseBackupService($pdo, $policy);
    $restoreService = new DatabaseRestoreService($pdo, $policy, $backupService);
    $safetyService = new SafetyCopyService($pdo, $policy);
    $reconciliationService = new IdentityReconciliationService($pdo, $policy);

    // -------------------------------------------------------------
    // Stage 1: Technical approval waiting
    // -------------------------------------------------------------
    $guidanceBeforeApproval = $restoreService->workflowGuidance();
    $assert($guidanceBeforeApproval['stage'] === 'technical_approval_waiting', 'Stage indicates waiting for technical approval when no approval exists');
    $assert(!empty($guidanceBeforeApproval['next_permitted_action']), 'Provides next permitted action for technical approval waiting');

    // Test rejection of invalid/stale approvals
    $safetyOpId = bin2hex(random_bytes(16));
    $safetyMeta = $safetyService->create($safetyOpId, 1, 'admin', $password, 'SafetyPassphrase@2026');

    $invalidApproval = [
        'stage' => 'draft', // not reconciliation_approved
        'package_id' => 'pkg-invalid',
    ];
    $reject(function() use ($pdo, $invalidApproval, $safetyMeta, $password) {
        RecoveryAuthService::preparePreRestoreState($pdo, bin2hex(random_bytes(16)), 1, 'admin', $password, $invalidApproval, $safetyMeta);
    }, 'Rejects invalid or unapproved reconciliation');

    // Build valid incoming package
    $schema = \App\Backup\SqlBackupFormat::schema($pdo);
    $incSqlPath = $private . '/incoming.sql';
    (new \App\Backup\DatabaseSnapshotWriter($pdo))->write($incSqlPath, StorePackageFormat::MAX_CONTENT_BYTES, ['backup_operations', 'store_write_gate']);
    $incSql = file_get_contents($incSqlPath);
    $incomingPassphrase = 'IncomingPassphrase@2026';
    $incomingRaw = StorePackageFormat::encode($incSql, [], $schema, $incomingPassphrase, bin2hex(random_bytes(16)));
    $incomingDecoded = StorePackageFormat::decode($incomingRaw, $incomingPassphrase, $schema);

    // Super Admin approves reconciliation
    $decisions = [
        1 => ['action' => 'link', 'destination_id' => 1],
        2 => ['action' => 'historical'],
        3 => ['action' => 'distinct', 'username' => 'src_mgr_distinct', 'email' => 'src_mgr@example.com', 'administrator' => false],
        4 => ['action' => 'distinct', 'username' => 'src_cashier_distinct', 'email' => 'src_cashier@example.com', 'administrator' => false],
        8 => ['action' => 'link', 'destination_id' => 8],
    ];
    $approvedResult = $reconciliationService->approve(2, 'super_admin', $password, $incomingDecoded, $decisions);
    $validApproval = $approvedResult['approval'];

    // -------------------------------------------------------------
    // Stage 2: Ready to restore
    // -------------------------------------------------------------
    $opId = bin2hex(random_bytes(16));
    $preparedState = RecoveryAuthService::preparePreRestoreState($pdo, $opId, 1, 'admin', $password, $validApproval, $safetyMeta);
    $assert(RecoveryStore::isPaused(), 'Write pause active upon pre-restore preparation');

    $guidanceReady = $restoreService->workflowGuidance();
    $assert($guidanceReady['stage'] === 'ready_to_restore', 'Stage is ready_to_restore after valid approval and safety copy');
    $assert(!empty($guidanceReady['next_permitted_action']), 'Next action guided for ready to restore');

    // Check authorized operators include destination admin (other_admin) and exclude cashiers
    $operators = $preparedState['authorized_operators'] ?? [];
    $opNames = array_column($operators, 'username');
    $assert(in_array('theme_admin', $opNames, true), 'Initiator is authorized');
    $assert(in_array('other_admin', $opNames, true), 'Other active Administrator is an authorized operator');
    $assert(in_array('theme_super_admin', $opNames, true), 'Destination Super Admin is authorized');
    $assert(!in_array('theme_cashier', $opNames, true), 'Cashier is strictly unauthorized');

    // -------------------------------------------------------------
    // Stage 3 & 4: Actionable failure vs Verification needed
    // -------------------------------------------------------------
    // Transition to review pending
    RecoveryStore::transitionStage(RecoveryStore::STAGE_DESTRUCTIVE_START);
    RecoveryStore::transitionStage(RecoveryStore::STAGE_DATABASE_REPLACEMENT);
    RecoveryStore::transitionStage(RecoveryStore::STAGE_AUTOMATED_VERIFICATION);
    RecoveryStore::transitionStage(RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING);

    // Initial guidance in review pending: verification needed (no checks recorded yet)
    $guidanceVerifNeeded = $restoreService->workflowGuidance();
    $assert($guidanceVerifNeeded['stage'] === 'verification_needed', 'Stage is verification_needed when checks incomplete');
    $assert(!empty($guidanceVerifNeeded['next_permitted_action']), 'Next action guided for verification needed');

    // Reopening is strictly blocked when checks are missing
    $readiness = $restoreService->validateReopeningReadiness();
    $assert($readiness['ready'] === false, 'Reopening blocked when checks missing');
    $assert(count($readiness['missing']) > 0, 'Missing checks listed');

    $reject(function() use ($restoreService, $opId, $password) {
        $restoreService->reopenStore(1, 'admin', $password, $opId);
    }, 'ReopenStore rejects when verification incomplete');

    // Run automated verification checks
    $checksResult = $restoreService->runVerificationChecks();
    $restoreService->recordReviewChecks($checksResult['checks']);

    // Still blocked: human checklist confirmations missing
    $readinessAfterAuto = $restoreService->validateReopeningReadiness();
    $assert($readinessAfterAuto['ready'] === false, 'Reopening still blocked without human confirmations');

    // Test actionable failure stage
    RecoveryStore::transitionStage(RecoveryStore::STAGE_RECOVERY_REQUIRED, ['error' => 'Simulated failure']);
    $guidanceFailure = $restoreService->workflowGuidance();
    $assert($guidanceFailure['stage'] === 'actionable_failure', 'Stage is actionable_failure when recovery_required');
    $assert(!empty($guidanceFailure['next_permitted_action']), 'Next action guided for actionable failure');

    // Return to review pending for takeover test
    RecoveryStore::transitionStage(RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING);
    $restoreService->recordReviewChecks($checksResult['checks']);

    // -------------------------------------------------------------
    // Authorized Takeover: Initiator becomes unavailable
    // -------------------------------------------------------------
    // Authenticate as other_admin (user 8)
    $authTakeover = RecoveryAuthService::authenticate('other_admin', $takeoverPassword, '127.0.0.1');
    $assert($authTakeover['user_id'] === 8, 'other_admin authenticated');
    $assert($authTakeover['is_initiator'] === false, 'other_admin is not initiator');
    $assert($authTakeover['role'] === 'admin', 'other_admin is admin');

    // Wrong password refusal
    $reject(function() use ($restoreService, $opId) {
        $restoreService->reopenStore(8, 'admin', 'WrongPassword123', $opId);
    }, 'Rejects reopening with wrong password for takeover operator');

    // Unauthorized user refusal (user 4 cashier)
    $reject(function() use ($restoreService, $opId) {
        $restoreService->reopenStore(4, 'cashier', 'ThemeTest@2026', $opId);
    }, 'Rejects reopening by unauthorized non-admin operator');

    // Takeover operator finishes human checklist items
    foreach (['stock', 'receipt', 'upload', 'attribution'] as $itemKey) {
        $restoreService->confirmChecklistItem(8, 'admin', $itemKey, true);
    }

    // Now all automated and human checks pass -> ready_to_reopen
    $guidanceReadyToReopen = $restoreService->workflowGuidance();
    $assert($guidanceReadyToReopen['stage'] === 'ready_to_reopen', 'Stage is ready_to_reopen after all checks pass');
    $assert(!empty($guidanceReadyToReopen['next_permitted_action']), 'Next action guided for ready to reopen');

    // Explicit takeover call (optional pre-registration) and durable persistence
    $takeoverOutcome = $restoreService->takeover(8, 'admin', $takeoverPassword);
    $assert($takeoverOutcome['success'] === true, 'Takeover registered successfully');
    $assert($takeoverOutcome['is_takeover'] === true, 'Recognized as takeover');

    // Reopen store as takeover administrator
    $reopenOutcome = $restoreService->reopenStore(8, 'admin', $takeoverPassword, $opId, 'Reopened by authorized administrator takeover');
    $assert($reopenOutcome['success'] === true, 'Store reopened by takeover administrator');

    // Reopening attribution verification
    $reopenedRec = RecoveryStore::reopenedRecord();
    $assert($reopenedRec !== null, 'Reopened record saved in RecoveryStore');
    $assert($reopenedRec['reopened_by']['user_id'] === 8, 'Reopened by user 8 (other_admin)');
    $assert($reopenedRec['reopened_by']['is_initiator'] === false, 'Recorded as non-initiator');
    $assert(!empty($reopenedRec['takeover']['taken_over']), 'Takeover flag is true');
    $assert($reopenedRec['takeover']['taken_over_by'] === 8, 'Takeover operator ID recorded');
    $assert($reopenedRec['takeover']['original_initiator_id'] === 1, 'Original initiator ID recorded');

    // Store is unpaused and requests admitted
    $assert(!RecoveryStore::isPaused(), 'Recovery write pause is released after explicit reopening');
    $assert(RecoveryStore::admitRequest(), 'Ordinary requests admitted');
    $assert($reopenedRec['stage'] === RecoveryStore::STAGE_REOPENED_COMPLETED, 'Durable reopened stage is reopened_completed');

    echo "Database restore takeover contract: passed (durable stages, invalid approval rejection, verification prerequisites, initiator unavailability, authorized takeover, refusal of wrong password/unauthorized, and explicit reopening attribution)\n";
} finally {
    $fixture('cleanup');
    if (is_dir($private)) {
        array_map('unlink', glob($private . '/*/*') ?: []);
        array_map('unlink', glob($private . '/*') ?: []);
        array_map('rmdir', glob($private . '/*') ?: []);
        @rmdir($private);
    }
}
