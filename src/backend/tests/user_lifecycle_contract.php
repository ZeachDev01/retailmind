<?php
// Public integration and authorization contract for single-Store staff lifecycle management.
// Ticket #61: disabled_at stamping/clearing and manual disable/re-enable emails through an injectable notifier.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\UserAccountNotifier;
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
    } catch (DomainException $exception) {
        // Expected authorization or protected-account rejection.
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
    $pdo->exec("CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        category TEXT NOT NULL,
        module TEXT NULL,
        record_id INTEGER NULL,
        previous_value TEXT NULL,
        new_value TEXT NULL,
        ip_address TEXT NULL,
        timestamp TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('super_admin'), ('admin'), ('inventory_manager'), ('cashier')");
    $pdo->exec('CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, is_primary INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (user_id, role_id))');
    (new StoreScope($pdo))->migrate();
    $storeId = (new StoreScope($pdo))->id();

    $roleId = static function (string $role) use ($pdo): int {
        $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
        $stmt->execute([$role]);
        return (int)$stmt->fetchColumn();
    };
    $insertActor = static function (string $name, string $role) use ($pdo, $roleId, $storeId): int {
        $stmt = $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, role_id, must_change_password, branch_id) VALUES (?, ?, ?, ?, ?, 0, ?)');
        $username = strtolower(str_replace(' ', '_', $name));
        $stmt->execute([$name, $username, $username . '@example.test', password_hash('ActorPassword123', PASSWORD_DEFAULT), $roleId($role), $storeId]);
        return (int)$pdo->lastInsertId();
    };

    $superId = $insertActor('Super Owner', 'super_admin');
    $adminId = $insertActor('Store Admin', 'admin');
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 1)')->execute([$superId, $roleId('super_admin')]);
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 1)')->execute([$adminId, $roleId('admin')]);
    $notifier = new class extends UserAccountNotifier {
        /** @var array<int, array{email: string, subject: string, message: string}> */
        public array $delivered = [];
        protected function deliver(string $email, string $subject, string $message): bool
        {
            $this->delivered[] = ['email' => $email, 'subject' => $subject, 'message' => $message];
            return true;
        }
    };
    $service = new UserLifecycleService($pdo, new RoleCapabilityPolicy(), new StoreScope($pdo), $notifier);

    $service->update($superId, 'super_admin', $superId, [
        'full_name' => 'Super Owner',
        'username' => 'super_owner',
        'email' => 'super_owner@example.test',
        'roles' => ['admin', 'inventory_manager', 'cashier'],
    ]);
    $superRoles = $service->get($superId)['roles'];
    $assert($superRoles === ['super_admin', 'admin', 'inventory_manager', 'cashier'], 'Super Administrator retains identity and saves optional workspaces');
    $service->update($superId, 'super_admin', $superId, [
        'full_name' => 'Super Owner',
        'username' => 'super_owner',
        'email' => 'super_owner@example.test',
        'roles' => [],
    ]);
    $assert($service->get($superId)['roles'] === ['super_admin'], 'Super Administrator can remove all optional workspaces');

    $cashierId = $service->create($adminId, 'admin', [
        'full_name' => 'New Cashier',
        'username' => 'new_cashier',
        'email' => 'cashier@example.test',
        'password_hash' => password_hash('CashierPassword123', PASSWORD_DEFAULT),
        'role' => 'cashier',
        'branch_id' => 999999,
    ]);
    $cashier = $service->get($cashierId);
    $assert((int)$cashier['branch_id'] === $storeId, 'new operational users must receive Store scope server-side');
    $assert($cashier['role_name'] === 'cashier', 'Administrator should create a Cashier role template');

    $managerId = $service->create($adminId, 'admin', [
        'full_name' => 'Inventory Manager',
        'username' => 'new_manager',
        'email' => 'manager@example.test',
        'password_hash' => password_hash('ManagerPassword123', PASSWORD_DEFAULT),
        'role' => 'inventory_manager',
    ]);
    $service->update($adminId, 'admin', $managerId, [
        'full_name' => 'Inventory Lead',
        'username' => 'new_manager',
        'email' => 'manager@example.test',
        'role' => 'inventory_manager',
    ]);
    $assert($service->get($managerId)['full_name'] === 'Inventory Lead', 'Administrator should edit delegated Store staff');
    $service->update($adminId, 'admin', $managerId, [
        'full_name' => 'Inventory Lead',
        'username' => 'new_manager',
        'email' => 'manager@example.test',
        'role' => 'cashier',
    ]);
    $assert($service->get($managerId)['role_name'] === 'cashier', 'Administrator should assign delegated fixed role templates');

    $beforeReset = (int)$cashier['session_version'];
    $service->resetPassword($adminId, 'admin', $cashierId, password_hash('ResetPassword123', PASSWORD_DEFAULT));
    $afterReset = $service->get($cashierId);
    $assert((int)$afterReset['session_version'] === $beforeReset + 1 && (int)$afterReset['must_change_password'] === 1, 'password reset must revoke sessions and require a password change');

    $service->revokeSessions($adminId, 'admin', $cashierId);
    $afterRevoke = $service->get($cashierId);
    $assert((int)$afterRevoke['session_version'] === (int)$afterReset['session_version'] + 1, 'explicit session revocation must invalidate active sessions');

    $beforeDisableSessions = (int)$service->get($cashierId)['session_version'];
    $service->setStatus($adminId, 'admin', $cashierId, 'disabled');
    $disabled = $service->get($cashierId);
    $assert($disabled['status'] === 'disabled', 'Administrator should disable delegated Store staff');
    $assert($disabled['disabled_at'] !== null && $disabled['disabled_at'] !== '', 'manual disable must record disabled_at');
    $assert(
        (int)$disabled['session_version'] === $beforeDisableSessions + 1,
        'manual disable must revoke sessions via session_version bump'
    );
    $assert((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username = 'new_cashier'")->fetchColumn() === 1, 'disabling must retain historical user attribution');
    $assert(count($notifier->delivered) === 1, 'manual disable must deliver exactly one holder email through the injected notifier');
    $disabledMail = $notifier->delivered[0];
    $assert($disabledMail['email'] === 'cashier@example.test', 'disabled notice must go to the holder email on file');
    $assert(
        str_contains(strtolower($disabledMail['subject']), 'disabled'),
        'disabled notice subject must plainly say the account was disabled, got: ' . $disabledMail['subject']
    );
    $assert(
        !str_contains(strtolower($disabledMail['subject'] . ' ' . $disabledMail['message']), 'inactive'),
        'disabled notice must use Disabled Account vocabulary, not inactive'
    );
    $assert(
        str_contains(strtolower($disabledMail['message']), 'disabled'),
        'disabled notice body must plainly say the account was disabled'
    );

    $service->setStatus($adminId, 'admin', $cashierId, 'active');
    $reEnabled = $service->get($cashierId);
    $assert($reEnabled['status'] === 'active', 'Administrator should re-enable delegated Store staff');
    $assert(
        $reEnabled['disabled_at'] === null,
        're-enable must clear disabled_at, got: ' . var_export($reEnabled['disabled_at'], true)
    );
    $assert(count($notifier->delivered) === 2, 'manual re-enable must deliver exactly one holder email through the injected notifier');
    $enabledMail = $notifier->delivered[1];
    $assert($enabledMail['email'] === 'cashier@example.test', 'access-active-again notice must go to the holder email on file');
    $assert(
        str_contains(strtolower($enabledMail['subject'] . ' ' . $enabledMail['message']), 'active')
        || str_contains(strtolower($enabledMail['subject'] . ' ' . $enabledMail['message']), 'sign in'),
        'access-active-again notice must plainly say access works again, got: ' . $enabledMail['subject']
    );

    $noEmailId = $service->create($adminId, 'admin', [
        'full_name' => 'No Email Staff',
        'username' => 'no_email_staff',
        'email' => null,
        'password_hash' => password_hash('NoEmailPassword123', PASSWORD_DEFAULT),
        'role' => 'cashier',
    ]);
    $deliveredBeforeSkip = count($notifier->delivered);
    $service->setStatus($adminId, 'admin', $noEmailId, 'disabled');
    $assert(
        $service->get($noEmailId)['status'] === 'disabled',
        'disable must still succeed when the holder has no email on file'
    );
    $assert(
        count($notifier->delivered) === $deliveredBeforeSkip,
        'emails must be skipped quietly when the holder has no email on file'
    );
    $service->setStatus($adminId, 'admin', $noEmailId, 'active');

    $ordinaryAdminId = $service->create($adminId, 'admin', [
        'full_name' => 'Another Admin', 'username' => 'another_admin', 'email' => 'ordinary_admin@example.test',
        'password_hash' => password_hash('AnotherPassword123', PASSWORD_DEFAULT), 'role' => 'admin',
    ]);
    $assert($service->get($ordinaryAdminId)['role_name'] === 'admin', 'Administrator creates ordinary Administrators');
    $beforeAdditional = $service->get($cashierId);
    $promoted = $service->update($adminId, 'admin', $cashierId,
        array_replace($beforeAdditional, ['roles' => ['cashier', 'admin']]));
    $assert($promoted['roles'] === ['cashier', 'admin'], 'Administrator promotes staff through additional access');
    $assert((int)$promoted['session_version'] === (int)$beforeAdditional['session_version'] + 1,
        'Adding Administrator access revokes existing sessions even when the primary role stays Cashier');
    $service->resetPassword($adminId, 'admin', $cashierId, password_hash('AdminResetPassword123', PASSWORD_DEFAULT));
    $resetAudit = $pdo->query("SELECT category FROM activity_log WHERE action = 'Password reset' ORDER BY log_id DESC LIMIT 1")->fetchColumn();
    $assert($resetAudit === 'security', 'Additional Administrator password resets remain Protected Audit Records');
    $adminNoticeBefore = count($notifier->delivered);
    foreach (['active', 'disabled'] as $status) {
        $service->setStatus($adminId, 'admin', $ordinaryAdminId, $status);
        $before = $service->get($ordinaryAdminId);
        $updated = $service->update($adminId, 'admin', $ordinaryAdminId,
            array_replace($before, ['full_name' => 'Updated Administrator', 'roles' => ['admin', 'inventory_manager']]));
        $service->resetPassword($adminId, 'admin', $ordinaryAdminId, password_hash('ReplacementPassword123', PASSWORD_DEFAULT));
        $after = $service->get($ordinaryAdminId);
        $assert($after['status'] === $status && $updated['full_name'] === 'Updated Administrator', 'Active and disabled Administrator profiles remain manageable');
        $assert(password_verify('ReplacementPassword123', $after['password_hash']) && (int)$after['must_change_password'] === 1,
            'Administrator reset replaces password and requires holder change');
    }
    $service->setStatus($adminId, 'admin', $ordinaryAdminId, 'active');
    $assert(count($notifier->delivered) === $adminNoticeBefore + 2, 'Administrator disable and re-enable notify the holder through the injected notifier');
    $beforeRemoval = $service->get($cashierId);
    $demoted = $service->update($adminId, 'admin', $cashierId,
        array_replace($beforeRemoval, ['roles' => ['cashier']]));
    $assert((int)$demoted['session_version'] === (int)$beforeRemoval['session_version'] + 1,
        'Removing additional Administrator access revokes stale sessions');
    foreach (['super_admin', 'platform_governance', 'unknown'] as $role) {
        $expectDenied(fn() => $service->update($adminId, 'admin', $ordinaryAdminId,
            array_replace($service->get($ordinaryAdminId), ['roles' => [$role]])), 'Administrator cannot assign forbidden authority');
    }
    foreach (['cashier', 'inventory_manager'] as $workspace) {
        $expectDenied(fn() => $service->update($adminId, $workspace, $ordinaryAdminId,
            $service->get($ordinaryAdminId)), 'Additional Administrator access does not elevate the active Workspace');
        $expectDenied(fn() => $service->resetPassword($adminId, $workspace, $ordinaryAdminId, 'unused'), 'Unauthorized Workspace cannot reset passwords');
    }
    $expectDenied(fn() => $service->setStatus($adminId, 'admin', $superId, 'disabled'), 'Administrator must not manage the Super Administrator');

    $privilegedId = $service->create($superId, 'super_admin', [
        'full_name' => 'Privileged Admin',
        'username' => 'privileged_admin',
        'email' => 'privileged@example.test',
        'password_hash' => password_hash('PrivilegedPassword123', PASSWORD_DEFAULT),
        'role' => 'admin',
    ]);
    $service->update($superId, 'super_admin', $privilegedId, [
        'full_name' => 'Privileged Administrator',
        'username' => 'privileged_admin',
        'email' => 'privileged@example.test',
        'role' => 'admin',
    ]);
    $assert($service->get($privilegedId)['full_name'] === 'Privileged Administrator', 'Super Administrator should manage privileged identities');
    $service->revokeSessions($superId, 'super_admin', $managerId);

    $auditRows = $pdo->query("SELECT action, category, new_value FROM activity_log WHERE module = 'User Access' ORDER BY log_id")->fetchAll(PDO::FETCH_ASSOC);
    $auditActions = array_column($auditRows, 'action');
    foreach (['User created', 'User updated', 'Password reset', 'Sessions revoked', 'User disabled'] as $expectedAction) {
        $assert(in_array($expectedAction, $auditActions, true), "Protected Audit Records must include {$expectedAction}");
    }
    $assert(
        count(array_filter($auditRows, static fn(array $row): bool => str_contains((string)$row['new_value'], '"actor_role":"admin"'))) > 0
        && count(array_filter($auditRows, static fn(array $row): bool => str_contains((string)$row['new_value'], '"actor_role":"super_admin"'))) > 0,
        'Protected Audit Records must retain the authority context for each actor role'
    );
    $assert(
        count(array_filter($auditRows, static fn(array $row): bool => $row['category'] === 'store_operation')) > 0,
        'delegated Store staff lifecycle records should remain visible to the Administrator'
    );
    $assert(
        count(array_filter($auditRows, static fn(array $row): bool => $row['category'] === 'security')) > 0,
        'privileged-account lifecycle records should be categorized as security'
    );
    // Primary and additional access are the same minimum, independently of Workspace.
    foreach ([['admin'], ['cashier', 'admin']] as $targetRoles) {
        $pdo->beginTransaction();
        $pdo->exec("UPDATE users SET status = 'disabled' WHERE role_id = 2");
        $pdo->prepare("UPDATE users SET status = 'active' WHERE user_id = ?")->execute([$privilegedId]);
        $target = $service->get($privilegedId);
        $target['roles'] = $targetRoles;
        $service->update($superId, 'super_admin', $privilegedId, $target);
        // Neither a Super Administrator with Administrator access nor Recovery counts.
        $pdo->prepare('INSERT OR IGNORE INTO user_roles VALUES (?, 2, 0)')->execute([$superId]);
        $recoveryId = $insertActor('Recovery Minimum', 'cashier');
        $pdo->prepare('UPDATE users SET is_recovery_account = 1 WHERE user_id = ?')->execute([$recoveryId]);
        $pdo->prepare('INSERT INTO user_roles VALUES (?, 2, 0)')->execute([$recoveryId]);
        $additionalSuperId = $insertActor('Additional Super Minimum', 'cashier');
        $pdo->prepare('INSERT INTO user_roles VALUES (?, 1, 0), (?, 2, 0)')->execute([$additionalSuperId, $additionalSuperId]);
        $before = $service->get($privilegedId);
        $auditsBefore = (int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
        $mailBefore = count($notifier->delivered);
        foreach ([
            fn() => $service->setStatus($superId, 'super_admin', $privilegedId, 'disabled'),
            fn() => $service->update($superId, 'super_admin', $privilegedId,
                array_replace($before, ['full_name' => 'Must roll back', 'roles' => ['cashier']])),
        ] as $operation) {
            try {
                $operation();
                $assert(false, 'Last ordinary Administrator removal must be rejected');
            } catch (DomainException $exception) {
                $assert(str_contains($exception->getMessage(), 'at least one active'), 'Minimum rejection explains the restriction');
            }
            $assert($service->get($privilegedId) === $before, 'Denied removal preserves the whole account and roles');
            $assert((int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === $auditsBefore, 'Denied removal adds no audit');
            $assert(count($notifier->delivered) === $mailBefore, 'Denied removal sends no notification');
        }
        $expectDenied(fn() => $service->setStatus($privilegedId, 'admin', $privilegedId, 'disabled'), 'Self-disable stays forbidden');
        $expectDenied(fn() => $service->update($privilegedId, 'admin', $privilegedId,
            array_replace($before, ['roles' => ['cashier']])), 'Self-demotion stays forbidden');
        $expectDenied(fn() => $service->setStatus($adminId, 'admin', $privilegedId, 'disabled'), 'Administrator actors also respect the minimum');
        $service->update($superId, 'super_admin', $privilegedId, array_replace($before, ['roles' => ['cashier', 'admin', 'inventory_manager']]));
        $service->update($superId, 'super_admin', $privilegedId, array_replace($before, ['roles' => ['admin', 'cashier']]));
        $assert(in_array('admin', $service->get($privilegedId)['roles'], true), 'Retained Administrator access permits primary changes and unrelated removal');
        $pdo->prepare("UPDATE users SET status = 'disabled' WHERE user_id = ?")->execute([$privilegedId]);
        $service->update($superId, 'super_admin', $privilegedId, array_replace($before, ['roles' => ['cashier']]));
        $assert($service->get($privilegedId)['roles'] === ['cashier'], 'Disabled targets are outside the minimum');
        $pdo->rollBack();
    }
    $beforeFailure = $service->get($privilegedId);
    $auditCount = (int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
    $pdo->exec("CREATE TRIGGER reject_minimum_role BEFORE INSERT ON user_roles BEGIN SELECT RAISE(ABORT, 'Injected role failure'); END");
    try {
        $service->update($superId, 'super_admin', $privilegedId, array_replace($beforeFailure, ['full_name' => 'Must roll back', 'roles' => ['cashier']]));
        $assert(false, 'Role sync failure must be propagated');
    } catch (PDOException) {
        $assert($service->get($privilegedId) === $beforeFailure, 'Role failure rolls back profile, primary role, mappings and sessions');
        $assert((int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === $auditCount, 'Role failure leaves no audit');
    }
} catch (Throwable $exception) {
    $failures[] = 'User lifecycle contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "User lifecycle contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "User lifecycle contract: passed\n";
