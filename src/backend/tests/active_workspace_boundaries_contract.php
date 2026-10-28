<?php
// Contract test for Ticket #328: Enforce active-Workspace operational boundaries.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

use App\Authorization\AuthorizationContext;
use App\Authorization\RoleCapabilityPolicy;
use App\Services\SupervisorApprovalService;
use App\Services\GCashTransactionService;
use App\Services\CashierShiftService;
use App\Services\UserLifecycleService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $policy = new RoleCapabilityPolicy();
    $localContext = AuthorizationContext::standard();
    $remoteContext = AuthorizationContext::remote();

    // 1. RoleCapabilityPolicy: Local evaluation
    $assert($policy->allows('super_admin', RoleCapabilityPolicy::PLATFORM_GOVERNANCE, null, $localContext), 'super_admin has platform governance locally');
    $assert($policy->allows('super_admin', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP, null, $localContext), 'super_admin has backup capability locally');
    $assert(!$policy->allows('super_admin', RoleCapabilityPolicy::STORE_OPERATIONS, null, $localContext), 'super_admin lacks store operations locally');
    $assert(!$policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $localContext), 'super_admin lacks inventory mutation locally');
    $assert(!$policy->allows('super_admin', RoleCapabilityPolicy::OPERATE_POINT_OF_SALE, null, $localContext), 'super_admin lacks POS locally');

    $assert($policy->allows('admin', RoleCapabilityPolicy::STORE_OPERATIONS, null, $localContext), 'admin has store operations locally');
    $assert($policy->allows('admin', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP, null, $localContext), 'admin has shared backup capability locally');
    $assert($policy->allows('admin', RoleCapabilityPolicy::MANAGE_REGISTERS, null, $localContext), 'admin has register management locally');
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::PLATFORM_GOVERNANCE, null, $localContext), 'admin lacks platform governance');
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $localContext), 'admin lacks inventory mutation');
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::OPERATE_POINT_OF_SALE, null, $localContext), 'admin lacks POS');
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::RETRAIN_MODELS, null, $localContext), 'admin lacks model retrain authority');
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::APPROVE_MODELS, null, $localContext), 'admin lacks model approval authority');

    $assert($policy->allows('cashier', RoleCapabilityPolicy::OPERATE_POINT_OF_SALE, null, $localContext), 'cashier has POS locally');
    $assert(!$policy->allows('cashier', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP, null, $localContext), 'cashier lacks backup capability');

    // 2. RoleCapabilityPolicy: Remote evaluation (ADR-0019 read-only monitoring)
    $assert($remoteContext->isRemote() === true, 'remote context identifies as remote');
    $assert($localContext->isRemote() === false, 'standard context identifies as local');

    // Read-only monitoring capabilities are preserved remotely
    $assert($policy->allows('admin', RoleCapabilityPolicy::VIEW_STORE_REPORTS, null, $remoteContext), 'admin can view store reports remotely');
    $assert($policy->allows('admin', RoleCapabilityPolicy::VIEW_INVENTORY, null, $remoteContext), 'admin can view inventory remotely');
    $assert($policy->allows('admin', RoleCapabilityPolicy::VIEW_STORE_AUDIT, null, $remoteContext), 'admin can view store audit remotely');
    $assert($policy->allows('admin', RoleCapabilityPolicy::VIEW_SALES_HISTORY, null, $remoteContext), 'admin can view sales history remotely');
    $assert($policy->allows('super_admin', RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT, null, $remoteContext), 'super_admin can view platform audit remotely');

    // Backups, user management, and mutations are rejected remotely
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP, null, $remoteContext), 'admin backup refused remotely');
    $assert(!$policy->allows('super_admin', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP, null, $remoteContext), 'super_admin backup refused remotely');
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::MANAGE_REGISTERS, null, $remoteContext), 'admin register management refused remotely');
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::MANAGE_USERS, null, $remoteContext), 'admin user management refused remotely');
    $assert(!$policy->allows('super_admin', RoleCapabilityPolicy::RESET_OPERATIONAL_DATA, null, $remoteContext), 'super_admin data reset refused remotely');
    $assert(!$policy->allows('super_admin', RoleCapabilityPolicy::RETRAIN_MODELS, null, $remoteContext), 'super_admin model retraining refused remotely');
    $assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $remoteContext), 'inventory mutation refused remotely');
    $assert(!$policy->allows('cashier', RoleCapabilityPolicy::OPERATE_POINT_OF_SALE, null, $remoteContext), 'POS refused remotely');

    // 3. Database test setup
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE roles (
            role_id INTEGER PRIMARY KEY,
            role_name TEXT UNIQUE NOT NULL
        );
        INSERT INTO roles (role_id, role_name) VALUES
            (1, 'admin'),
            (2, 'super_admin'),
            (3, 'cashier'),
            (4, 'inventory_manager');

        CREATE TABLE users (
            user_id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE,
            password_hash TEXT NOT NULL,
            role_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            must_change_password INTEGER NOT NULL DEFAULT 0,
            is_recovery_account INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT NULL
        );

        CREATE TABLE user_roles (
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            is_primary INTEGER DEFAULT 0,
            PRIMARY KEY (user_id, role_id)
        );

        CREATE TABLE registers (
            register_id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            branch_id INTEGER DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'active'
        );
        INSERT INTO registers (register_id, name, branch_id, status) VALUES (1, 'Register 1', 1, 'active');

        CREATE TABLE cashier_shifts (
            shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER NOT NULL,
            register_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            locked_at TEXT NULL,
            opening_cash REAL NOT NULL DEFAULT 1000.0,
            opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
            closed_at TEXT NULL,
            expected_cash REAL DEFAULT 1000.0,
            actual_cash REAL NULL,
            cash_variance REAL NULL,
            variance_threshold REAL NULL,
            variance_review_required INTEGER DEFAULT 0,
            payment_totals TEXT NULL,
            closing_notes TEXT NULL,
            closed_by INTEGER NULL,
            intervention_reason TEXT NULL,
            reviewed_by INTEGER NULL,
            reviewed_at TEXT NULL
        );

        CREATE TABLE cash_drawer_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER NOT NULL,
            cashier_id INTEGER NOT NULL,
            movement_type TEXT NOT NULL,
            amount REAL NOT NULL,
            reason TEXT NOT NULL,
            note TEXT NULL,
            authorized_by INTEGER NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE gcash_service_sessions (
            session_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER NOT NULL,
            opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
            opening_service_cash REAL NOT NULL,
            opening_wallet_balance REAL NOT NULL,
            closed_at TEXT NULL,
            closing_service_cash REAL NULL,
            closing_wallet_balance REAL NULL,
            expected_service_cash REAL NULL,
            expected_wallet_balance REAL NULL,
            cash_variance REAL NULL,
            wallet_variance REAL NULL,
            status TEXT NOT NULL DEFAULT 'open',
            notes TEXT NULL,
            unresolved_carryover INTEGER NOT NULL DEFAULT 0,
            waived_at TEXT NULL,
            waived_by INTEGER NULL,
            waiver_notes TEXT NULL
        );

        CREATE TABLE gcash_transactions (
            transaction_id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            cashier_id INTEGER NOT NULL,
            transaction_type TEXT NOT NULL,
            amount REAL NOT NULL,
            fee_amount REAL NOT NULL DEFAULT 0,
            fee_method TEXT NOT NULL DEFAULT 'cash',
            gross_fee REAL NOT NULL DEFAULT 0,
            reference_number TEXT NULL,
            verification_status TEXT NOT NULL DEFAULT 'Needs checking',
            confirmed_wallet INTEGER NOT NULL DEFAULT 0,
            confirmed_cash INTEGER NOT NULL DEFAULT 0,
            service_cash_delta REAL NOT NULL DEFAULT 0,
            wallet_delta REAL NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE activity_log (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            category TEXT,
            module TEXT,
            record_id INTEGER,
            previous_value TEXT,
            new_value TEXT,
            details TEXT,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE branches (
            branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_name TEXT NOT NULL,
            is_main INTEGER NOT NULL DEFAULT 1
        );
        INSERT INTO branches (branch_id, branch_name, is_main) VALUES (1, 'Main Branch', 1);
    ");

    $pw = password_hash('AdminPass123!', PASSWORD_BCRYPT);
    // User 1: Primary Super Admin with secondary Admin role
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id) VALUES (1, 'Super Tech', 'supertech', 'tech@example.com', ?, 2)")->execute([$pw]);
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (1, 1)")->execute();

    // User 2: Pure Admin
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id) VALUES (2, 'Store Admin', 'storeadmin', 'admin@example.com', ?, 1)")->execute([$pw]);

    // User 3: Cashier with secondary Admin role
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id) VALUES (3, 'Multi Cashier', 'multicashier', 'cashier@example.com', ?, 3)")->execute([$pw]);
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (3, 1)")->execute();

    // Seed a closed GCash session with discrepancies
    $pdo->exec("INSERT INTO gcash_service_sessions (session_id, cashier_id, status, closed_at, opening_service_cash, opening_wallet_balance, closing_service_cash, closing_wallet_balance, expected_service_cash, expected_wallet_balance, cash_variance, wallet_variance) VALUES (10, 3, 'closed', '2026-10-28 07:00:00', 5000, 10000, 4800, 10000, 5000, 10000, -200, 0)");

    // 4. Test GCash Discrepancy Waiver:
    $gcashService = new GCashTransactionService($pdo, ['enabled' => true]);

    $superAdminWaiveFailed = false;
    try {
        $gcashService->waiveDiscrepancy(1, 'super_admin', 10, 'Super admin waiving discrepancy');
    } catch (\DomainException $e) {
        $superAdminWaiveFailed = true;
    }
    $assert($superAdminWaiveFailed, 'super_admin workspace cannot waive discrepancy');

    // Verify session remained un-waived after refusal
    $checkSession = $pdo->query("SELECT waived_at FROM gcash_service_sessions WHERE session_id = 10")->fetch(PDO::FETCH_ASSOC);
    $assert($checkSession['waived_at'] === null, 'session remains un-waived after super_admin refusal');

    // Cashier active workspace bypass check:
    $cashierWaiveFailed = false;
    try {
        $gcashService->waiveDiscrepancy(3, 'cashier', 10, 'Cashier trying to waive discrepancy');
    } catch (\DomainException $e) {
        $cashierWaiveFailed = true;
    }
    $assert($cashierWaiveFailed, 'cashier workspace cannot waive discrepancy');

    // Remote context refusal check:
    $_SERVER['HTTP_CF_RAY'] = 'test-remote-ray-123';
    $remoteWaiveFailed = false;
    try {
        $gcashService->waiveDiscrepancy(2, 'admin', 10, 'Admin trying to waive remotely');
    } catch (\DomainException $e) {
        $remoteWaiveFailed = true;
    }
    $assert($remoteWaiveFailed, 'admin cannot waive discrepancy during remote monitoring');
    unset($_SERVER['HTTP_CF_RAY']);

    // Admin active workspace locally succeeds:
    $gcashService->waiveDiscrepancy(2, 'admin', 10, 'Valid admin in-store review');
    $checkSession = $pdo->query("SELECT waived_at, waived_by FROM gcash_service_sessions WHERE session_id = 10")->fetch(PDO::FETCH_ASSOC);
    $assert($checkSession['waived_at'] !== null && (int)$checkSession['waived_by'] === 2, 'admin workspace successfully waives discrepancy locally');

    // 5. Test Supervisor Approval: Remote restrictions
    $approverId = SupervisorApprovalService::authorize($pdo, 'storeadmin', 'AdminPass123!');
    $assert($approverId === 2, 'local supervisor approval succeeds');

    $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.195';
    $remoteApprovalFailed = false;
    try {
        SupervisorApprovalService::authorize($pdo, 'storeadmin', 'AdminPass123!');
    } catch (\DomainException $e) {
        $remoteApprovalFailed = true;
    }
    $assert($remoteApprovalFailed, 'supervisor approval refused in remote context');
    unset($_SERVER['HTTP_CF_CONNECTING_IP']);

    // 6. Test Cashier Shift Service: Active workspace boundaries
    $shiftService = new CashierShiftService($pdo);

    $adminOpenFailed = false;
    try {
        $shiftService->openShift(2, 'admin', 1, 1000.0);
    } catch (\DomainException $e) {
        $adminOpenFailed = true;
    }
    $assert($adminOpenFailed, 'admin workspace cannot open cashier shift');

    $superAdminOpenFailed = false;
    try {
        $shiftService->openShift(1, 'super_admin', 1, 1000.0);
    } catch (\DomainException $e) {
        $superAdminOpenFailed = true;
    }
    $assert($superAdminOpenFailed, 'super_admin workspace cannot open cashier shift');

    // Remote cashier shift open is refused:
    $_SESSION['is_remote'] = true;
    $remoteShiftOpenFailed = false;
    try {
        $shiftService->openShift(3, 'cashier', 1, 1000.0);
    } catch (\DomainException $e) {
        $remoteShiftOpenFailed = true;
    }
    $assert($remoteShiftOpenFailed, 'cashier cannot open shift in remote context');
    unset($_SESSION['is_remote']);

    // Verify no shift opened when refused:
    $shiftCount = (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE cashier_id = 3 AND status = 'open'")->fetchColumn();
    $assert($shiftCount === 0, 'no shift opened after refused requests');

    // Local cashier shift open succeeds:
    $shiftId = $shiftService->openShift(3, 'cashier', 1, 1000.0);
    $assert($shiftId > 0, 'cashier opens shift locally in cashier workspace');

    // Drawer movement: admin cannot record drawer movement in cashier shift:
    $adminMovementFailed = false;
    try {
        $shiftService->addDrawerMovement(2, 'admin', 'cash_in', 500.0, 'other', 'Test movement', 'storeadmin', 'AdminPass123!');
    } catch (\DomainException $e) {
        $adminMovementFailed = true;
    }
    $assert($adminMovementFailed, 'admin workspace cannot record drawer movement');

    // Remote drawer movement refused:
    $_SERVER['RETAILMIND_REMOTE'] = '1';
    $remoteMovementFailed = false;
    try {
        $shiftService->addDrawerMovement(3, 'cashier', 'cash_in', 500.0, 'other', 'Remote movement', 'storeadmin', 'AdminPass123!');
    } catch (\DomainException $e) {
        $remoteMovementFailed = true;
    }
    $assert($remoteMovementFailed, 'drawer movement refused in remote context');
    unset($_SERVER['RETAILMIND_REMOTE']);

    // Verify drawer movements unchanged after refusals:
    $movementCount = (int)$pdo->query("SELECT COUNT(*) FROM cash_drawer_movements WHERE shift_id = {$shiftId}")->fetchColumn();
    $assert($movementCount === 0, 'no drawer movements recorded after refusals');

    // 7. Emergency handover, funding movement & linked correction checks:
    $pdo->exec("INSERT INTO gcash_service_sessions (session_id, cashier_id, status, opening_service_cash, opening_wallet_balance) VALUES (20, 3, 'open', 2000, 5000)");

    // Super admin cannot execute emergency handover:
    $superHandoverFailed = false;
    try {
        $gcashService->emergencyHandover(1, 3, 2000.0, 5000.0, 'Super admin trying emergency handover');
    } catch (\DomainException) {
        $superHandoverFailed = true;
    }
    $assert($superHandoverFailed, 'super_admin cannot execute emergency handover');

    // Remote emergency handover refused:
    $_SESSION['is_remote'] = true;
    $remoteHandoverFailed = false;
    try {
        $gcashService->emergencyHandover(2, 3, 2000.0, 5000.0, 'Remote emergency handover');
    } catch (\DomainException) {
        $remoteHandoverFailed = true;
    }
    $assert($remoteHandoverFailed, 'emergency handover refused in remote context');
    unset($_SESSION['is_remote']);

    // Linked correction: super_admin cannot record linked correction:
    $superCorrectionFailed = false;
    try {
        $gcashService->recordLinkedCorrection(1, 'super_admin', 999, 100.0, 0.0, 'Fix error');
    } catch (\DomainException) {
        $superCorrectionFailed = true;
    }
    $assert($superCorrectionFailed, 'super_admin cannot record linked correction');

    // 8. Preserved technical model maintenance boundaries:
    $modelCaps = [
        RoleCapabilityPolicy::RETRAIN_MODELS,
        RoleCapabilityPolicy::CONFIGURE_MODELS,
        RoleCapabilityPolicy::APPROVE_MODELS,
        RoleCapabilityPolicy::REPLACE_MODELS,
        RoleCapabilityPolicy::ROLL_BACK_MODELS,
    ];
    foreach ($modelCaps as $cap) {
        $assert($policy->allows('super_admin', $cap, null, $localContext), "super_admin retains {$cap}");
        $assert(!$policy->allows('admin', $cap, null, $localContext), "admin lacks {$cap}");
        $assert(!$policy->allows('super_admin', $cap, null, $remoteContext), "{$cap} refused remotely");
    }

    // 9. Preserved User Lifecycle & Recovery Account boundaries:
    $userService = new UserLifecycleService($pdo, $policy, new \App\Store\StoreScope($pdo));
    $recoveryUser = [
        'user_id' => 99,
        'username' => 'recovery_admin',
        'role_name' => 'admin',
        'is_recovery_account' => 1,
        'roles' => ['admin']
    ];
    $recoveryBlocked = false;
    try {
        $reflector = new \ReflectionClass($userService);
        $method = $reflector->getMethod('requireManageable');
        $method->setAccessible(true);
        $method->invoke($userService, 2, 'admin', $recoveryUser, false);
    } catch (\DomainException) {
        $recoveryBlocked = true;
    }
    $assert($recoveryBlocked, 'Recovery Account boundary is protected against normal management');

    // 10. Preserved Protected Restore authority:
    $restoreFile = (string)file_get_contents(__DIR__ . '/../app/Backup/DatabaseRestoreService.php');
    $assert(str_contains($restoreFile, 'Operator role is not authorized to reopen the Store.') || str_contains($restoreFile, 'Only the initiating administrator'), 'protected Administrator restore preserved');

    // 11. Remote bulk export and mutation refusal in routes:
    $gcashOverviewApi = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/administrator/gcash_overview_api.php');
    $assert(str_contains($gcashOverviewApi, "current_role() !== 'admin'"), 'gcash_overview_api requires active admin workspace for POST');
    $assert(str_contains($gcashOverviewApi, 'is_remote_request()'), 'gcash_overview_api rejects remote mutations');

    $gcashOverview = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/administrator/gcash_overview.php');
    $assert(str_contains($gcashOverview, 'Bulk exports are not available during remote monitoring'), 'gcash_overview rejects remote csv export');
    $assert(str_contains($gcashOverview, '$canMutate'), 'gcash_overview guards mutation buttons with canMutate');

    // 12. Test auth.php helper functions:
    $authCode = (string)file_get_contents(__DIR__ . '/../includes/auth.php');
    $assert(str_contains($authCode, 'function is_remote_request('), 'auth.php defines is_remote_request');
    $assert(str_contains($authCode, 'function require_local_request('), 'auth.php defines require_local_request');

} catch (\Throwable $e) {
    $failures[] = 'Contract threw exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures) {
    fwrite(STDERR, "Active workspace boundaries contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Active workspace boundaries contract: passed\n";
