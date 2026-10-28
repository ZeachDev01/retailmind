<?php
// Contract test for Ticket #344: Staff lifecycle authority, revocation, and audit.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Audit\AuditRecordCategory;
use App\Audit\ProtectedAuditRecordService;
use App\Authorization\RoleCapabilityPolicy;
use App\Services\UserLifecycleService;
use App\Store\StoreScope;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expectDenied = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (DomainException $e) {
        // Expected domain exception
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_name TEXT NOT NULL UNIQUE,
        branch_code TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'active'
    )");
    $pdo->exec('CREATE TABLE products (product_id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NULL)');
    $pdo->exec('CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)');
    $pdo->exec("CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        username TEXT NOT NULL UNIQUE,
        email TEXT NULL UNIQUE,
        profile_image TEXT NULL,
        password_hash TEXT NOT NULL,
        role_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        session_version INTEGER NOT NULL DEFAULT 1,
        password_changed_at TEXT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 1,
        branch_id INTEGER NULL,
        is_recovery_account INTEGER NOT NULL DEFAULT 0,
        disabled_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE TABLE user_roles (
        user_id INTEGER NOT NULL,
        role_id INTEGER NOT NULL,
        is_primary INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, role_id)
    )');
    $pdo->exec("CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        category TEXT NOT NULL,
        module TEXT NULL,
        record_id INTEGER NULL,
        previous_value TEXT NULL,
        new_value TEXT NULL,
        metadata TEXT NULL,
        ip_address TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        register_id INTEGER NOT NULL DEFAULT 1,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        opening_cash REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'open',
        closed_at TEXT NULL,
        actual_cash REAL NULL,
        expected_cash REAL NULL,
        cash_variance REAL NULL
    )");
    $pdo->exec("CREATE TABLE gcash_service_sessions (
        session_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        cashier_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'open',
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        opening_service_cash REAL NOT NULL DEFAULT 0,
        opening_wallet_balance REAL NOT NULL DEFAULT 0,
        closed_at TEXT NULL
    )");

    $pdo->exec("INSERT INTO roles (role_name) VALUES ('super_admin'), ('admin'), ('inventory_manager'), ('cashier')");
    (new StoreScope($pdo))->migrate();
    $storeId = (new StoreScope($pdo))->id();

    $roleId = static function (string $role) use ($pdo): int {
        $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
        $stmt->execute([$role]);
        return (int)$stmt->fetchColumn();
    };

    $insertUser = static function (string $name, string $role, array $roles = [], int $isRecovery = 0) use ($pdo, $roleId, $storeId): int {
        $stmt = $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, role_id, must_change_password, branch_id, is_recovery_account) VALUES (?, ?, ?, ?, ?, 0, ?, ?)');
        $username = strtolower(str_replace(' ', '_', $name));
        $stmt->execute([$name, $username, $username . '@example.test', password_hash('Pass123456!', PASSWORD_DEFAULT), $roleId($role), $storeId, $isRecovery]);
        $uid = (int)$pdo->lastInsertId();
        $assignRoles = $roles ?: [$role];
        foreach ($assignRoles as $r) {
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, ?)')
                ->execute([$uid, $roleId($r), $r === $role ? 1 : 0]);
        }
        return $uid;
    };

    $superId = $insertUser('Super Owner', 'super_admin');
    $adminId = $insertUser('Store Admin', 'admin');
    $cashierId = $insertUser('Cashier Dave', 'cashier', ['cashier', 'inventory_manager']);
    $pureCashierId = $insertUser('Pure Cashier', 'cashier');
    $recoveryId = $insertUser('Technical Recovery', 'admin', ['admin'], 1);

    $policy = new RoleCapabilityPolicy();
    $service = new UserLifecycleService($pdo, $policy, new StoreScope($pdo));
    $auditService = new ProtectedAuditRecordService($pdo, $policy);

    // =========================================================================
    // 1. Immediate account disablement with open shifts & GCash custody
    // =========================================================================
    // Open a cashier shift and a GCash session for cashierId
    $pdo->prepare("INSERT INTO cashier_shifts (shift_id, cashier_id, status) VALUES (101, ?, 'open')")->execute([$cashierId]);
    $pdo->prepare("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status) VALUES (201, 101, ?, 'open')")->execute([$cashierId]);

    // Check pending duties helper
    $pending = $service->pendingDuties($cashierId);
    $assert($pending['has_pending_duties'] === true, 'cashierId has pending duties');
    $assert(count($pending['open_shifts']) === 1, 'cashierId has 1 open shift');
    $assert(count($pending['open_gcash_sessions']) === 1, 'cashierId has 1 open GCash session');
    $assert(!empty($pending['handover_links']), 'pending duties provide handover links');

    // Immediate disable must succeed despite unfinished duties
    $sessionVerBefore = (int)$service->get($cashierId)['session_version'];
    $afterDisable = $service->setStatus($adminId, 'admin', $cashierId, 'disabled');
    $assert($afterDisable['status'] === 'disabled', 'Account disabled immediately');
    $assert((int)$afterDisable['session_version'] === $sessionVerBefore + 1, 'Session revoked on disablement');
    $assert(!empty($afterDisable['pending_duties']), 'setStatus returns pending duties information');

    // Verify cashier shift and GCash custody were NEVER auto-closed or reconciled
    $shiftStatus = $pdo->query("SELECT status FROM cashier_shifts WHERE shift_id = 101")->fetchColumn();
    $assert($shiftStatus === 'open', 'Cashier shift remains open after account disablement (never auto-closed)');
    $gcashStatus = $pdo->query("SELECT status FROM gcash_service_sessions WHERE session_id = 201")->fetchColumn();
    $assert($gcashStatus === 'open', 'GCash session remains open after account disablement (never auto-closed)');

    // Re-enable for next tests
    $service->setStatus($adminId, 'admin', $cashierId, 'active');

    // =========================================================================
    // 2. Role removal withdraws workspace & revokes sessions, preserves duties
    // =========================================================================
    $beforeRoleUpdate = $service->get($cashierId);
    $sessionVerBeforeRemoval = (int)$beforeRoleUpdate['session_version'];
    // Remove cashier role, keep only inventory_manager
    $updatedRoles = $service->update($adminId, 'admin', $cashierId, [
        'full_name' => $beforeRoleUpdate['full_name'],
        'username' => $beforeRoleUpdate['username'],
        'roles' => ['inventory_manager'],
    ]);
    $assert($updatedRoles['roles'] === ['inventory_manager'], 'Role cashier was removed');
    $assert((int)$updatedRoles['session_version'] === $sessionVerBeforeRemoval + 1, 'Sessions revoked on role removal');

    // Cashier shift and GCash session must still remain open and preserved
    $shiftStatus2 = $pdo->query("SELECT status FROM cashier_shifts WHERE shift_id = 101")->fetchColumn();
    $assert($shiftStatus2 === 'open', 'Open shift preserved after role removal');
    $gcashStatus2 = $pdo->query("SELECT status FROM gcash_service_sessions WHERE session_id = 201")->fetchColumn();
    $assert($gcashStatus2 === 'open', 'GCash session preserved after role removal');

    // =========================================================================
    // 3. Super Administrator user management restricted boundaries
    // =========================================================================
    // 3a. Super Admin can create ordinary Administrator
    $newAdminId = $service->create($superId, 'super_admin', [
        'full_name' => 'Ordinary Admin Two',
        'username' => 'ordinary_admin_two',
        'email' => 'admin2@example.test',
        'password_hash' => password_hash('Pass123456!', PASSWORD_DEFAULT),
        'roles' => ['admin'],
    ]);
    $assert($newAdminId > 0, 'Super Administrator can create ordinary Administrator');

    // 3b. Super Admin cannot create Cashier or Inventory Manager
    $expectDenied(fn() => $service->create($superId, 'super_admin', [
        'full_name' => 'Cashier by Super',
        'username' => 'cashier_by_super',
        'email' => 'cashiersuper@example.test',
        'password_hash' => password_hash('Pass123456!', PASSWORD_DEFAULT),
        'roles' => ['cashier'],
    ]), 'Super Administrator must not create Cashier accounts');

    $expectDenied(fn() => $service->create($superId, 'super_admin', [
        'full_name' => 'Manager by Super',
        'username' => 'manager_by_super',
        'email' => 'managersuper@example.test',
        'password_hash' => password_hash('Pass123456!', PASSWORD_DEFAULT),
        'roles' => ['inventory_manager'],
    ]), 'Super Administrator must not create Inventory Manager accounts');

    // 3c. Super Admin can disable accounts with Administrator access
    $disabledAdmin = $service->setStatus($superId, 'super_admin', $newAdminId, 'disabled');
    $assert($disabledAdmin['status'] === 'disabled', 'Super Administrator can disable account with Administrator access');

    // 3d. Super Admin CANNOT disable accounts WITHOUT Administrator access (pure cashier)
    $expectDenied(
        fn() => $service->setStatus($superId, 'super_admin', $pureCashierId, 'disabled'),
        'Super Administrator must not disable accounts without Administrator access'
    );

    // 3e. Super Admin CANNOT re-enable staff accounts
    $expectDenied(
        fn() => $service->setStatus($superId, 'super_admin', $newAdminId, 'active'),
        'Super Administrator must not re-enable staff accounts'
    );

    // 3f. Super Admin CANNOT edit other staff profiles/passwords/roles
    $expectDenied(fn() => $service->update($superId, 'super_admin', $newAdminId, [
        'full_name' => 'Tampered Admin',
        'username' => 'ordinary_admin_two',
        'roles' => ['admin'],
    ]), 'Super Administrator must not edit staff profiles');

    $expectDenied(
        fn() => $service->resetPassword($superId, 'super_admin', $newAdminId, password_hash('NewPass123!', PASSWORD_DEFAULT)),
        'Super Administrator must not reset staff passwords'
    );

    $expectDenied(
        fn() => $service->revokeSessions($superId, 'super_admin', $pureCashierId),
        'Super Administrator must not revoke sessions for cashier-only staff'
    );

    // =========================================================================
    // 4. Operational Audit: ordinary staff lifecycle events and password masking
    // =========================================================================
    // 4a. Ordinary Administrator creation must be STORE_OPERATION
    $createAdminAudit = $pdo->query("SELECT category, action FROM activity_log WHERE record_id = {$newAdminId} AND action = 'User created'")->fetch(PDO::FETCH_ASSOC);
    $assert($createAdminAudit['category'] === AuditRecordCategory::STORE_OPERATION, 'Administrator creation is categorized as STORE_OPERATION in audit log');

    // 4b. Ordinary Administrator password reset must be STORE_OPERATION
    $service->resetPassword($adminId, 'admin', $newAdminId, password_hash('AdminPassReset123!', PASSWORD_DEFAULT));
    $resetAdminAudit = $pdo->query("SELECT category, action FROM activity_log WHERE record_id = {$newAdminId} AND action = 'Password reset'")->fetch(PDO::FETCH_ASSOC);
    $assert($resetAdminAudit['category'] === AuditRecordCategory::STORE_OPERATION, 'Administrator password reset is categorized as STORE_OPERATION');

    // 4c. Operational audit records visible to Administrator
    $adminAuditRecords = $auditService->records('admin');
    $adminActions = array_column($adminAuditRecords, 'action');
    $assert(in_array('User created', $adminActions, true), 'Operational audit includes User created');
    $assert(in_array('User disabled', $adminActions, true), 'Operational audit includes User disabled');
    $assert(in_array('Password reset', $adminActions, true), 'Operational audit includes Password reset');

    // 4d. Recovery Account records excluded from Operational Audit for Administrator
    // Insert a dummy recovery account audit log
    $pdo->prepare("INSERT INTO activity_log (user_id, action, category, module, record_id, new_value) VALUES (?, 'Recovery login', 'recovery_account', 'Recovery Account', ?, '{\"note\":\"offline\"}')")
        ->execute([$recoveryId, $recoveryId]);
    $adminAuditAfter = $auditService->records('admin');
    foreach ($adminAuditAfter as $rec) {
        $assert((int)$rec['user_id'] !== $recoveryId, 'Recovery Account user records must be excluded from Operational Audit');
        $assert($rec['module'] !== 'Recovery Account', 'Recovery Account module records must be excluded from Operational Audit');
        $assert($rec['category'] !== AuditRecordCategory::RECOVERY_ACCOUNT, 'recovery_account category must be excluded from Operational Audit');
    }

    // 4e. Password masking in audit records
    // Insert an audit log with sensitive password fields
    $pdo->prepare("INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value) VALUES (?, 'Test update', 'store_operation', 'User Access', 999, '{\"password\":\"secret_old\",\"password_hash\":\"\$2y\$10\$abc\"}', '{\"password\":\"secret_new\",\"new_password\":\"brand_new_pass\"}')")
        ->execute([$adminId]);
    $maskedRecords = $auditService->records('admin');
    $testRecord = null;
    foreach ($maskedRecords as $r) {
        if ($r['action'] === 'Test update') {
            $testRecord = $r;
            break;
        }
    }
    $assert($testRecord !== null, 'Test audit record found');
    $assert(!str_contains($testRecord['previous_value'], 'secret_old'), 'previous_value password masked');
    $assert(!str_contains($testRecord['previous_value'], '$2y$10$abc'), 'previous_value password_hash masked');
    $assert(!str_contains($testRecord['new_value'], 'secret_new'), 'new_value password masked');
    $assert(!str_contains($testRecord['new_value'], 'brand_new_pass'), 'new_value new_password masked');

} catch (Throwable $e) {
    $failures[] = 'Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures) {
    fwrite(STDERR, "Staff lifecycle audit contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Staff lifecycle audit contract: passed\n";
