<?php
// Uses connection-local copies of the installed schema; no real accounts are changed.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "User password reset integration: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Core\Database;
use App\Services\UserLifecycleService;
use App\Store\StoreScope;

try {
    $pdo = Database::connection();
    $storeId = (new StoreScope($pdo))->id();
    foreach (['users', 'user_roles', 'activity_log'] as $table) {
        $pdo->exec("CREATE TEMPORARY TABLE reset_fixture LIKE {$table}");
        $ddl = $pdo->query('SHOW CREATE TABLE reset_fixture')->fetch(PDO::FETCH_NUM)[1];
        preg_match_all('/CONSTRAINT `([^`]+)` CHECK/', $ddl, $checks);
        foreach ($checks[1] as $check) $pdo->exec('ALTER TABLE reset_fixture DROP CHECK `' . str_replace('`', '``', $check) . '`');
        $pdo->exec("ALTER TABLE reset_fixture RENAME TO {$table}");
    }
    $insert = $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role_id, branch_id, must_change_password) VALUES (?, ?, ?, ?, ?, 0)');
    $oldPassword = bin2hex(random_bytes(16));
    $ids = [];
    foreach (['super_admin', 'admin', 'cashier', 'inventory_manager'] as $role) {
        $roleQuery = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
        $roleQuery->execute([$role]);
        $roleId = (int)$roleQuery->fetchColumn();
        $insert->execute(['Reset Fixture', 'reset_fixture_' . $role, password_hash($oldPassword, PASSWORD_DEFAULT), $roleId, $storeId]);
        $ids[$role] = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 1)')->execute([$ids[$role], $roleId]);
    }
    $service = new UserLifecycleService($pdo, new RoleCapabilityPolicy(), new StoreScope($pdo));
    foreach (['super_admin', 'admin'] as $actorRole) {
        foreach (['cashier', 'inventory_manager'] as $targetRole) {
            foreach ([['admin'], [$targetRole, 'admin']] as $roles) {
                $targetId = $ids[$targetRole];
                $service->update($ids[$actorRole], $actorRole, $targetId,
                    array_replace($service->get($targetId), ['roles' => $roles]));
                foreach (['active', 'disabled'] as $status) {
                    $service->setStatus($ids[$actorRole], $actorRole, $targetId, $status);
                    $target = $service->get($targetId);
                    $newPassword = bin2hex(random_bytes(16));
                    $service->resetPassword($ids[$actorRole], $actorRole, $targetId, password_hash($newPassword, PASSWORD_DEFAULT));
                    $after = $service->get($targetId);
                    $audit = $pdo->query("SELECT category, new_value FROM activity_log WHERE action = 'Password reset' ORDER BY log_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if (password_verify($newPassword, $target['password_hash'])
                        || !password_verify($newPassword, $after['password_hash'])
                        || password_verify($oldPassword, $after['password_hash'])
                        || (int)$after['must_change_password'] !== 1
                        || (int)$after['session_version'] !== (int)$target['session_version'] + 1
                        || $after['status'] !== $status || $audit['category'] !== 'security'
                        || !in_array('admin', json_decode($audit['new_value'], true)['roles'], true)) {
                        throw new RuntimeException('Administrator password replacement, forced change, session revocation, status, or audit assertion failed.');
                    }
                }
            }
        }
    }
    $forbiddenCases = [
        ['admin', $ids['super_admin']], ['cashier', $ids['admin']], ['inventory_manager', $ids['admin']],
    ];
    foreach (['additional_super', 'recovery'] as $identity) {
        $insert->execute(['Protected Fixture', 'reset_fixture_' . $identity,
            password_hash($oldPassword, PASSWORD_DEFAULT), $service->get($ids['cashier'])['role_id'], $storeId]);
        $protectedId = (int)$pdo->lastInsertId();
        if ($identity === 'additional_super') {
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 0)')
                ->execute([$protectedId, $service->get($ids['super_admin'])['role_id']]);
        } else {
            $pdo->prepare('UPDATE users SET is_recovery_account = 1 WHERE user_id = ?')->execute([$protectedId]);
        }
        foreach (['admin', 'super_admin'] as $actorRole) $forbiddenCases[] = [$actorRole, $protectedId];
    }
    foreach ($forbiddenCases as [$actorRole, $targetId]) {
        $before = $service->get($targetId);
        $auditCount = (int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
        try {
            $service->resetPassword($ids[$actorRole], $actorRole, $targetId, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));
            throw new RuntimeException('Forbidden password reset succeeded.');
        } catch (DomainException) {
            if ($service->get($targetId) !== $before
                || (int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() !== $auditCount) {
                throw new RuntimeException('Forbidden reset changed account, sessions, or audit.');
            }
        }
    }
    echo "User password reset integration: passed\n";
} catch (Throwable $exception) {
    // Fixture contains generated credentials only; never print payloads or hashes.
    fwrite(STDERR, 'User password reset integration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
