<?php
// Recovery Account lifecycle contract through the offline service seam.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Audit\ProtectedAuditRecordService;
use App\Services\RecoveryAccountService;
use App\Services\UserLifecycleService;
use App\Authorization\RoleCapabilityPolicy;
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
        // Expected protected-account rejection.
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
        failed_login_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until TEXT NULL,
        last_login_at TEXT NULL,
        session_version INTEGER NOT NULL DEFAULT 1,
        password_changed_at TEXT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 1,
        branch_id INTEGER NULL,
        is_recovery_account INTEGER NOT NULL DEFAULT 0,
        disabled_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE recovery_accounts (
        account_key TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL UNIQUE,
        activation_secret_hash TEXT NOT NULL,
        activated_at TEXT NULL,
        sealed_at TEXT NULL,
        credentials_rotated_at TEXT NULL,
        last_used_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE password_reset_tokens (
        reset_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used_at TEXT NULL
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
        metadata TEXT NULL,
        ip_address TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('super_admin'), ('admin'), ('inventory_manager'), ('cashier')");
    (new StoreScope($pdo))->migrate();

    $service = new RecoveryAccountService($pdo);
    $assert(
        $service->publicStatus() === ['status' => 'not_configured', 'last_used_at' => null],
        'Unprovisioned status should expose only non-secret state and last use'
    );

    $userId = $service->provision(
        'sealed_recovery',
        password_hash('OfflineLoginPassword123', PASSWORD_DEFAULT),
        'offline-activation-secret-0001'
    );
    $account = $pdo->query("SELECT * FROM users WHERE is_recovery_account = 1")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$account['user_id'] === $userId, 'Recovery Account should be explicitly identifiable');
    $assert($account['status'] === 'disabled', 'Provisioned Recovery Account should begin sealed');
    $assert($account['email'] === null, 'Recovery Account should have no email recovery path');
    $assert((int)$account['must_change_password'] === 0, 'Recovery Account should not enter normal password-change workflow');
    $expectDenied(
        static fn() => $service->provision('second_recovery', password_hash('SecondPassword123', PASSWORD_DEFAULT), 'second-activation-secret-0002'),
        'Only one Recovery Account may be provisioned'
    );
    $assert(array_keys($service->publicStatus()) === ['status', 'last_used_at'], 'Application status must not expose credentials or identity');
    $routineLogin = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND status = 'active'");
    $routineLogin->execute(['sealed_recovery']);
    $assert($routineLogin->fetchColumn() === false, 'Routine login must not activate or authenticate a sealed Recovery Account');
    $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, 'forced-token', '2999-01-01 00:00:00')")
        ->execute([$userId]);
    $routineReset = $pdo->query("SELECT pr.reset_id FROM password_reset_tokens pr JOIN users u ON u.user_id = pr.user_id WHERE pr.token_hash = 'forced-token' AND u.is_recovery_account = 0");
    $assert($routineReset->fetchColumn() === false, 'Normal password-reset tokens must be rejected for the Recovery Account');

    $expectDenied(
        static fn() => $service->activate('wrong-activation-secret'),
        'Activation should require the separately stored activation credential'
    );
    $service->activate('offline-activation-secret-0001');
    $assert($service->publicStatus()['status'] === 'active', 'Offline activation should enable the Recovery Account');

    $lifecycle = new UserLifecycleService($pdo, new RoleCapabilityPolicy(), new StoreScope($pdo));
    foreach ([
        static fn() => $lifecycle->update($userId, 'super_admin', $userId, ['full_name' => 'Changed', 'username' => 'changed', 'role' => 'super_admin']),
        static fn() => $lifecycle->setStatus($userId, 'super_admin', $userId, 'disabled'),
        static fn() => $lifecycle->resetPassword($userId, 'super_admin', $userId, password_hash('NormalReset123', PASSWORD_DEFAULT)),
        static fn() => $lifecycle->revokeSessions($userId, 'super_admin', $userId),
    ] as $mutation) {
        $expectDenied($mutation, 'Routine user-management mutations must reject the Recovery Account');
    }

    $service->recordUse($userId);
    $assert($service->publicStatus()['last_used_at'] !== null, 'Successful Recovery Account use should update non-secret last-use information');

    $oldPasswordHash = (string)$account['password_hash'];
    $service->rotateCredentials(
        'offline-activation-secret-0001',
        password_hash('RotatedOfflinePassword456', PASSWORD_DEFAULT),
        'offline-activation-secret-0002'
    );
    $rotated = $pdo->query("SELECT password_hash, session_version FROM users WHERE user_id = {$userId}")->fetch(PDO::FETCH_ASSOC);
    $assert($rotated['password_hash'] !== $oldPasswordHash, 'Offline rotation should replace the login credential');
    $assert((int)$rotated['session_version'] === 2, 'Credential rotation should revoke existing sessions');
    $expectDenied(
        static fn() => $service->reseal('offline-activation-secret-0001'),
        'Credential rotation should invalidate the old activation credential'
    );

    $service->reseal('offline-activation-secret-0002');
    $sealed = $pdo->query("SELECT status, session_version FROM users WHERE user_id = {$userId}")->fetch(PDO::FETCH_ASSOC);
    $assert($sealed['status'] === 'disabled', 'Resealing should disable routine login');
    $assert((int)$sealed['session_version'] === 3, 'Resealing should revoke the recovery session');
    $assert($service->publicStatus()['status'] === 'sealed', 'Resealing should restore sealed status');
    $routineLogin->execute(['sealed_recovery']);
    $assert($routineLogin->fetchColumn() === false, 'Resealing should remove the Recovery Account from routine login');

    $actions = $pdo->query("SELECT action, category FROM activity_log WHERE module = 'Recovery Account' ORDER BY log_id")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach (['Recovery Account activated', 'Recovery Account used', 'Recovery Account credentials rotated', 'Recovery Account resealed'] as $expectedAction) {
        $matches = array_filter($actions, static fn(array $row): bool => $row['action'] === $expectedAction && $row['category'] === 'recovery_account');
        $assert(count($matches) === 1, "Protected Audit Records should include {$expectedAction}");
    }

    $auditView = new ProtectedAuditRecordService($pdo, new RoleCapabilityPolicy());
    $visibleRecoveryRecords = $auditView->records('super_admin', ['category' => 'recovery_account']);
    $assert(
        $visibleRecoveryRecords !== []
        && $visibleRecoveryRecords[0]['full_name'] === 'Recovery Account'
        && $visibleRecoveryRecords[0]['username'] === null,
        'Application audit views must mask Recovery Account login identity'
    );
    $assert(
        $auditView->records('super_admin', ['search' => 'sealed_recovery']) === [],
        'Application audit search must not reveal the Recovery Account username'
    );
} catch (Throwable $exception) {
    $failures[] = 'Recovery Account lifecycle contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Recovery Account lifecycle test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Recovery Account lifecycle test: passed\n";
