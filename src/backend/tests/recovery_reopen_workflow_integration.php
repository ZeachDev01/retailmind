<?php
// Disposable complete-schema MySQL/filesystem recovery verification and reopening workflow (#313).
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Recovery reopen workflow: skipped (set RUN_DB_TESTS=1)\n";
    exit;
}

$private = sys_get_temp_dir() . '/rm-reopen-test-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
$_ENV['BACKUP_STORAGE_PATH'] = $private;
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Backup\{DatabaseBackupService, DatabaseRestoreService, IdentityReconciliationService, RecoveryAuthService, RecoveryStore, SafetyCopyService, StorePackageFormat};
use App\Store\{StoreWriteGate, StoreWritePausedException};

$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$reject = static function(callable $work, string $message) use ($assert): void {
    $failed = false;
    try {
        $work();
    } catch (Throwable $e) {
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

    // Prepare upload directory fixture
    $uploadDir = $private . '/storage';
    if (!is_dir($uploadDir . '/images')) mkdir($uploadDir . '/images', 0700, true);
    if (!is_dir($uploadDir . '/profile-images')) mkdir($uploadDir . '/profile-images', 0700, true);

    $testPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    file_put_contents($uploadDir . '/images/product-test.png', $testPng);
    file_put_contents($uploadDir . '/profile-images/user-test.png', $testPng);

    $pdo->prepare("UPDATE products SET product_image = 'storage/images/product-test.png' WHERE product_id = 100")->execute();
    $pdo->prepare("UPDATE users SET profile_image = 'user-test.png' WHERE user_id = 1")->execute();
    $pdo->exec("DELETE FROM user_roles WHERE user_id = 2 AND role_id != 2");

    $policy = new RoleCapabilityPolicy();
    $backupService = new DatabaseBackupService($pdo, $policy, null, null, $uploadDir);
    $restoreService = new DatabaseRestoreService($pdo, $policy, $backupService, $uploadDir);
    $safetyService = new SafetyCopyService($pdo, $policy, null, $uploadDir);
    $reconciliationService = new IdentityReconciliationService($pdo, $policy);

    $password = 'ThemeTest@2026';
    $safetyPassphrase = 'DestinationSafetyPassphrase@2026';
    $incomingPassphrase = 'IncomingStorePassphrase@2026';

    // 1. Capture safety copy
    $safetyOpId = bin2hex(random_bytes(16));
    $safetyMeta = $safetyService->create($safetyOpId, 1, 'admin', $password, $safetyPassphrase);
    $safetyArtifactHash = hash_file('sha256', $private . '/safety-' . $safetyOpId . '/safety.rmpkg');

    // 2. Build incoming package fixture
    $schema = \App\Backup\SqlBackupFormat::schema($pdo);
    $incSqlPath = $private . '/incoming.sql';
    (new \App\Backup\DatabaseSnapshotWriter($pdo))->write($incSqlPath, StorePackageFormat::MAX_CONTENT_BYTES, ['backup_operations', 'store_write_gate']);
    $incSql = file_get_contents($incSqlPath);
    $incFiles = ['images/product-test.png' => $testPng, 'profile-images/user-test.png' => $testPng];
    $srcOrigin = bin2hex(random_bytes(16));
    $incomingRaw = StorePackageFormat::encode($incSql, $incFiles, $schema, $incomingPassphrase, $srcOrigin);
    $incomingPackagePath = $private . '/incoming.rmpkg';
    file_put_contents($incomingPackagePath, $incomingRaw);
    $incomingDecoded = StorePackageFormat::decode($incomingRaw, $incomingPassphrase, $schema);

    // 3. Approve reconciliation
    $decisions = [
        1 => ['action' => 'link', 'destination_id' => 1],
        2 => ['action' => 'historical'],
        3 => ['action' => 'distinct', 'username' => 'src_mgr_distinct', 'email' => 'src_mgr@example.com', 'administrator' => false],
        4 => ['action' => 'distinct', 'username' => 'src_cashier_distinct', 'email' => 'src_cashier@example.com', 'administrator' => false],
    ];
    $approvalResult = $reconciliationService->approve(2, 'super_admin', $password, $incomingDecoded, $decisions);
    $approval = $approvalResult['approval'];

    // -------------------------------------------------------------
    // Scenario A: Safety Recovery path -> Operator Review -> Reopening
    // -------------------------------------------------------------
    $opId = bin2hex(random_bytes(16));
    $state = RecoveryAuthService::preparePreRestoreState($pdo, $opId, 1, 'admin', $password, $approval, $safetyMeta);
    $initialEpoch = RecoveryStore::epoch();
    $assert(RecoveryStore::isPaused(), 'Recovery is paused');

    // Execute complete safety recovery
    $safetyOutcome = $restoreService->recoverSafety(1, 'admin', $safetyOpId, $safetyPassphrase);
    $assert($safetyOutcome['success'] === true, 'Safety recovery succeeded');
    $assert(RecoveryStore::currentStage() === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING, 'Stage is operator_review_pending');

    // AC 1: Review pending is distinct from completion. Ordinary writes and requests remain blocked.
    $assert(RecoveryStore::isPaused(), 'Store remains paused while review pending');
    $reject(fn() => StoreWriteGate::begin($pdo), 'Store writes remain blocked by write gate during operator review pending');

    // Controlled read-only verification checks work
    $verification = $restoreService->runVerificationChecks(['images/product-test.png' => $testPng, 'profile-images/user-test.png' => $testPng]);
    $assert($verification['all_passed'] === true, 'All automated verification checks passed');
    $assert(isset($verification['checks']['stock'], $verification['checks']['receipt'], $verification['checks']['upload'], $verification['checks']['attribution']), 'All 4 required checks present');
    $assert(isset($verification['checks']['signin_preview'], $verification['checks']['report']), 'Previews present');

    // AC 2: Missing or stale checks prevent reopening with actionable guidance.
    // Try reopening before recording automated checks or human confirmations
    $readinessBefore = $restoreService->validateReopeningReadiness();
    $assert($readinessBefore['ready'] === false, 'Readiness fails when checks missing');
    $assert(count($readinessBefore['missing']) > 0, 'Missing items identified');

    $reject(function() use ($restoreService, $opId, $password) {
        $restoreService->reopenStore(1, 'admin', $password, $opId);
    }, 'Reopening rejected when review checks are incomplete');

    // Record automated checks into durable state
    $restoreService->recordReviewChecks($verification['checks']);

    // Still missing human confirmations
    $readinessMid = $restoreService->validateReopeningReadiness();
    $assert($readinessMid['ready'] === false, 'Readiness fails when human confirmations missing');
    $reject(function() use ($restoreService, $opId, $password) {
        $restoreService->reopenStore(1, 'admin', $password, $opId);
    }, 'Reopening rejected when human checklist unconfirmed');

    // Confirm checklist items
    $restoreService->confirmChecklistItem(1, 'admin', 'stock', true);
    $restoreService->confirmChecklistItem(1, 'admin', 'receipt', true);
    $restoreService->confirmChecklistItem(1, 'admin', 'upload', true);
    $restoreService->confirmChecklistItem(1, 'admin', 'attribution', true);

    $readinessAfter = $restoreService->validateReopeningReadiness();
    $assert($readinessAfter['ready'] === true, 'Readiness succeeds after confirmations');

    // AC 3 & AC 4: Test security restrictions during reopening
    // Reject wrong operator password (fresh authentication required)
    $reject(function() use ($restoreService, $opId) {
        $restoreService->reopenStore(1, 'admin', 'WrongOperatorPassword@999', $opId);
    }, 'Reopening rejects wrong password');

    // Reject operation ID mismatch
    $reject(function() use ($restoreService, $password) {
        $restoreService->reopenStore(1, 'admin', $password, 'wrong-op-id-12345');
    }, 'Reopening rejects operation id mismatch');

    // Reject unauthorized actor (e.g. user_id 3 cashier or imported user)
    $reject(function() use ($restoreService, $opId, $password) {
        $restoreService->reopenStore(3, 'cashier', $password, $opId);
    }, 'Reopening rejects unauthorized operator');

    // AC 3, AC 4, AC 5, AC 6: Initiating Administrator explicitly reopens Store
    $reopenOutcome = $restoreService->reopenStore(1, 'admin', $password, $opId);
    $assert($reopenOutcome['success'] === true, 'Reopen succeeded');

    // Verify AC 5: Persistence
    $assert(!RecoveryStore::isPaused(), 'RecoveryStore is no longer paused');
    $reopenedPath = $private . '/recovery-reopened.json';
    $assert(is_file($reopenedPath), 'recovery-reopened.json was written');
    $reopenedData = json_decode((string)file_get_contents($reopenedPath), true);
    $assert($reopenedData['reopened_by']['user_id'] === 1, 'Persisted who reopened');
    $assert($reopenedData['path_completed'] === 'safety_recovery', 'Persisted path completed');
    $assert(!empty($reopenedData['verified_checks']['automated']), 'Persisted automated checks');
    $assert(!empty($reopenedData['verified_checks']['human_confirmations']), 'Persisted human confirmations');
    $assert(hash_file('sha256', $private . '/safety-' . $safetyOpId . '/safety.rmpkg') === $safetyArtifactHash, 'Safety copy intact on disk');

    // Verify AC 6: Reopening effect
    // 1. Ordinary Store writes unpaused
    StoreWriteGate::begin($pdo);
    $pdo->commit();

    // 2. Session epoch was bumped, invalidating existing sessions
    $newEpoch = RecoveryStore::epoch();
    $assert($newEpoch !== $initialEpoch, 'Session epoch was bumped');

    // -------------------------------------------------------------
    // Scenario B: Restart Incoming path -> Operator Review -> Reopen by Destination Super Admin
    // -------------------------------------------------------------
    $opId2 = bin2hex(random_bytes(16));
    RecoveryAuthService::preparePreRestoreState($pdo, $opId2, 1, 'admin', $password, $approval, $safetyMeta);
    $epoch2 = RecoveryStore::epoch();
    $assert(RecoveryStore::isPaused(), 'Store paused for incoming restart');

    $restartOutcome = $restoreService->restartIncoming(1, 'admin', $incomingPackagePath, $incomingPassphrase, $safetyPassphrase);
    $assert($restartOutcome['success'] === true, 'Incoming restart succeeded');
    $assert(RecoveryStore::currentStage() === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING, 'Stage is operator_review_pending');

    // Controlled verification checks
    $verification2 = $restoreService->runVerificationChecks(['images/product-test.png' => $testPng, 'profile-images/user-test.png' => $testPng]);
    $assert($verification2['all_passed'] === true, 'Verification passed for restart');
    $restoreService->recordReviewChecks($verification2['checks']);

    // Confirm checklist
    $restoreService->confirmChecklistItem(2, 'super_admin', 'stock', true);
    $restoreService->confirmChecklistItem(2, 'super_admin', 'receipt', true);
    $restoreService->confirmChecklistItem(2, 'super_admin', 'upload', true);
    $restoreService->confirmChecklistItem(2, 'super_admin', 'attribution', true);

    // Reopen as Destination Super Administrator (user_id 2)
    $reopenOutcome2 = $restoreService->reopenStore(2, 'super_admin', $password, $opId2);
    $assert($reopenOutcome2['success'] === true, 'Super Admin reopened successfully');
    $assert(!RecoveryStore::isPaused(), 'RecoveryStore is unpaused');

    $reopenedData2 = json_decode((string)file_get_contents($reopenedPath), true);
    $assert($reopenedData2['reopened_by']['user_id'] === 2, 'Persisted Super Admin who reopened');
    $assert($reopenedData2['path_completed'] === 'restart_incoming', 'Persisted restart_incoming path');

    echo "Recovery reopen workflow: passed (review pending distinct, controlled checks, checklist validation, authorization, fresh auth, session invalidation, persistence)\n";

} catch (Throwable $e) {
    fwrite(STDERR, "TEST FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    throw $e;
} finally {
    if (RecoveryStore::isPaused()) {
        try { RecoveryStore::resume(); } catch (Throwable) {}
    }
    $fixture('cleanup');
    if (is_dir($private)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($private, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($private);
    }
}
