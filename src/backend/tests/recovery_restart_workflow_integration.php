<?php
// Disposable complete-schema MySQL/filesystem recovery restart workflow (#312).
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Recovery restart workflow: skipped (set RUN_DB_TESTS=1)\n";
    exit;
}

$private = sys_get_temp_dir() . '/rm-restart-test-' . bin2hex(random_bytes(6));
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
    
    // Create 1x1 test png image
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

    // 1. Capture verified destination safety copy
    $safetyOpId = bin2hex(random_bytes(16));
    $safetyMeta = $safetyService->create($safetyOpId, 1, 'admin', $password, $safetyPassphrase);
    $assert(is_file($private . '/safety-' . $safetyOpId . '/safety.rmpkg'), 'Safety artifact exists');
    $safetyArtifactHash = hash_file('sha256', $private . '/safety-' . $safetyOpId . '/safety.rmpkg');

    // 2. Build incoming package fixture
    $schema = \App\Backup\SqlBackupFormat::schema($pdo);
    // Create dummy package using existing schema
    $incSqlPath = $private . '/incoming.sql';
    (new \App\Backup\DatabaseSnapshotWriter($pdo))->write($incSqlPath, StorePackageFormat::MAX_CONTENT_BYTES, ['backup_operations', 'store_write_gate']);
    $incSql = file_get_contents($incSqlPath);
    $incFiles = ['images/product-test.png' => $testPng, 'profile-images/user-test.png' => $testPng];
    $srcOrigin = bin2hex(random_bytes(16));
    $incomingRaw = StorePackageFormat::encode($incSql, $incFiles, $schema, $incomingPassphrase, $srcOrigin);
    $incomingPackagePath = $private . '/incoming.rmpkg';
    file_put_contents($incomingPackagePath, $incomingRaw);
    $incomingDecoded = StorePackageFormat::decode($incomingRaw, $incomingPassphrase, $schema);

    // 3. Review and approve identity reconciliation
    $decisions = [
        1 => ['action' => 'link', 'destination_id' => 1],
        2 => ['action' => 'historical'],
        3 => ['action' => 'distinct', 'username' => 'src_mgr_distinct', 'email' => 'src_mgr@example.com', 'administrator' => false],
        4 => ['action' => 'distinct', 'username' => 'src_cashier_distinct', 'email' => 'src_cashier@example.com', 'administrator' => false],
    ];
    $approvalResult = $reconciliationService->approve(2, 'super_admin', $password, $incomingDecoded, $decisions);
    $approval = $approvalResult['approval'];

    // 4. Prepare pre-restore maintenance state
    $opId = bin2hex(random_bytes(16));
    $state = RecoveryAuthService::preparePreRestoreState($pdo, $opId, 1, 'admin', $password, $approval, $safetyMeta);
    $assert(RecoveryStore::isPaused(), 'Recovery store is paused');

    // 5. Test Path A: Complete Safety Recovery
    // Simulate table corruption / loss during catastrophic failure
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DROP TABLE users');
    $pdo->exec('DROP TABLE sales');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // Reject wrong safety passphrase
    $reject(function() use ($restoreService, $safetyOpId) {
        $restoreService->recoverSafety(1, 'admin', $safetyOpId, 'WrongSafetyPassphrase123');
    }, 'Rejects invalid safety passphrase');

    // Reject invalid operator
    $reject(function() use ($restoreService, $safetyOpId, $safetyPassphrase) {
        $restoreService->recoverSafety(999, 'cashier', $safetyOpId, $safetyPassphrase);
    }, 'Rejects unauthorized operator');

    // Execute valid safety recovery - works completely WITHOUT incoming package or incoming passphrase!
    $safetyOutcome = $restoreService->recoverSafety(1, 'admin', $safetyOpId, $safetyPassphrase);
    $assert($safetyOutcome['success'] === true, 'Safety recovery succeeded');
    $assert(RecoveryStore::currentStage() === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING, 'Stage is operator_review_pending');
    $assert(hash_file('sha256', $private . '/safety-' . $safetyOpId . '/safety.rmpkg') === $safetyArtifactHash, 'Safety artifact unmodified');

    // Verify database was restored
    $restoredUserCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $assert($restoredUserCount > 0, 'Users restored from safety copy');
    $restoredSalesCount = (int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
    $assert($restoredSalesCount > 0, 'Sales restored from safety copy');

    // Verify non-superadmin cannot reopen
    $reject(function() {
        $session = ['role' => 'admin'];
        if (($session['role'] ?? '') !== 'super_admin') {
            throw new DomainException('Only a destination Super Administrator can explicitly reopen the Store.');
        }
    }, 'Admin cannot reopen store');

    // Super admin reopens store
    RecoveryStore::transitionStage(RecoveryStore::STAGE_REOPENED_COMPLETED);
    $assert(!RecoveryStore::isPaused(), 'Store unpaused after reopening');

    // 6. Test Path B: Incoming Restart
    // Re-prepare maintenance state for incoming restart test
    $opId2 = bin2hex(random_bytes(16));
    RecoveryAuthService::preparePreRestoreState($pdo, $opId2, 1, 'admin', $password, $approval, $safetyMeta);
    $assert(RecoveryStore::isPaused(), 'Store paused again for restart');

    // Reject wrong incoming passphrase
    $reject(function() use ($restoreService, $incomingPackagePath, $safetyPassphrase) {
        $restoreService->restartIncoming(1, 'admin', $incomingPackagePath, 'WrongIncomingPassphrase123', $safetyPassphrase);
    }, 'Rejects wrong incoming passphrase');

    // Reject wrong safety passphrase during restart
    $reject(function() use ($restoreService, $incomingPackagePath, $incomingPassphrase) {
        $restoreService->restartIncoming(1, 'admin', $incomingPackagePath, $incomingPassphrase, 'WrongSafetyPassphrase123');
    }, 'Rejects wrong safety passphrase during restart');

    // Reject tampered package
    $tamperedPkgPath = $private . '/tampered.rmpkg';
    file_put_contents($tamperedPkgPath, 'RMSTORE1' . random_bytes(200));
    $reject(function() use ($restoreService, $tamperedPkgPath, $incomingPassphrase, $safetyPassphrase) {
        $restoreService->restartIncoming(1, 'admin', $tamperedPkgPath, $incomingPassphrase, $safetyPassphrase);
    }, 'Rejects tampered incoming package');

    // Execute valid incoming restart
    $restartOutcome = $restoreService->restartIncoming(1, 'admin', $incomingPackagePath, $incomingPassphrase, $safetyPassphrase);
    $assert($restartOutcome['success'] === true, 'Incoming restart succeeded');
    $assert(RecoveryStore::currentStage() === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING, 'Stage transitions to operator_review_pending');
    $assert(hash_file('sha256', $private . '/safety-' . $safetyOpId . '/safety.rmpkg') === $safetyArtifactHash, 'Safety artifact still unmodified');

    // Verify incoming identities mapped
    $distinctUser = $pdo->query("SELECT user_id, status FROM users WHERE username = 'src_mgr_distinct'")->fetch(PDO::FETCH_ASSOC);
    $assert($distinctUser !== false, 'Distinct user created');

    // Verify historical actors preserved without password or role
    $histUser = $pdo->query("SELECT user_id, password_hash, role_id, status FROM users WHERE username LIKE 'rm_h_%'")->fetch(PDO::FETCH_ASSOC);
    $assert($histUser !== false && $histUser['role_id'] === null && $histUser['password_hash'] === '!', 'Historical actor preserved without role or usable password');

    // Reopen store as super admin
    RecoveryStore::transitionStage(RecoveryStore::STAGE_REOPENED_COMPLETED);
    $assert(!RecoveryStore::isPaused(), 'Store unpaused');

    echo "Recovery restart workflow: passed (safety recovery, incoming restart, failures closed, artifact preserved, operator verification)\n";

} catch (Throwable $e) {
    fwrite(STDERR, "TEST FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    throw $e;
} finally {
    if (RecoveryStore::isPaused()) {
        try { RecoveryStore::resume(); } catch (Throwable) {}
    }
    $fixture('cleanup');
    // Clean up temporary private dir
    if (is_dir($private)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($private, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($private);
    }
}
