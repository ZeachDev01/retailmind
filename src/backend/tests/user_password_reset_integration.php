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
    foreach (['users', 'activity_log'] as $table) {
        $pdo->exec("CREATE TEMPORARY TABLE reset_fixture LIKE {$table}");
        $pdo->exec("ALTER TABLE reset_fixture RENAME TO {$table}");
    }
    $insert = $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role_id, branch_id, must_change_password) VALUES (?, ?, ?, ?, ?, 0)');
    $oldPassword = bin2hex(random_bytes(16));
    $newPassword = bin2hex(random_bytes(16));
    $ids = [];
    foreach (['super_admin', 'cashier'] as $role) {
        $roleQuery = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
        $roleQuery->execute([$role]);
        $insert->execute(['Reset Fixture', 'reset_fixture_' . $role, password_hash($oldPassword, PASSWORD_DEFAULT), $roleQuery->fetchColumn(), $storeId]);
        $ids[$role] = (int)$pdo->lastInsertId();
    }
    $service = new UserLifecycleService($pdo, new RoleCapabilityPolicy(), new StoreScope($pdo));
    $target = $service->get($ids['cashier']);
    $service->update($ids['super_admin'], 'super_admin', $ids['cashier'], [
        'full_name' => $target['full_name'], 'username' => $target['username'],
        'email' => $target['email'], 'role' => 'cashier',
    ]);
    $service->resetPassword($ids['super_admin'], 'super_admin', $ids['cashier'], password_hash($newPassword, PASSWORD_DEFAULT));
    $after = $service->get($ids['cashier']);
    if (!password_verify($newPassword, $after['password_hash'])
        || password_verify($oldPassword, $after['password_hash'])
        || (int)$after['must_change_password'] !== 1
        || (int)$after['session_version'] !== (int)$target['session_version'] + 1
        || (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Password reset'")->fetchColumn() !== 1) {
        throw new RuntimeException('Password replacement, forced change, session revocation, or audit assertion failed.');
    }
    echo "User password reset integration: passed\n";
} catch (Throwable $exception) {
    // Fixture contains generated credentials only; never print payloads or hashes.
    fwrite(STDERR, 'User password reset integration failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
