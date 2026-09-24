<?php
// Staff sign-in with Login Identifier contract (ticket #67).
//
// Exercises the shipped unified login entry point — identifier + password
// in, sentence + session + audit out — on SQLite in-memory. Asserts
// user-facing sentences, session outcomes, and Protected Audit Records;
// never asserts query text. The shipped functions are extracted from
// src/backend/includes/auth.php and executed (auth.php itself cannot be
// required here because it opens the live database connection on include).
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Services\RecoveryAccountService;
use App\Store\StoreScope;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $root = dirname(__DIR__, 3);
    $authSource = (string)@file_get_contents($root . '/src/backend/includes/auth.php');
    $extract = static function (string $name) use ($authSource): string {
        if (preg_match('/function ' . $name . '\(.*?^}/ms', $authSource, $matches)) {
            return $matches[0];
        }
        return '';
    };
    $resolveCode = $extract('resolve_login_user');
    $loginCode = $extract('login_user');
    $errorCode = $extract('last_login_error');
    $assert($resolveCode !== '', 'auth.php must own Login Identifier resolution at the unified entry point');
    $assert($loginCode !== '', 'auth.php must own login_user at the unified entry point');
    $assert($errorCode !== '', 'auth.php must own last_login_error at the unified entry point');
    if ($resolveCode === '' || $loginCode === '' || $errorCode === '') {
        throw new RuntimeException('Shipped login entry point could not be loaded.');
    }
    eval($resolveCode);
    eval($loginCode);
    eval($errorCode);

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

    $roleId = static function (string $role) use ($pdo): int {
        $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
        $stmt->execute([$role]);
        return (int)$stmt->fetchColumn();
    };
    $cashierRole = $roleId('cashier');
    $insertUser = static function (string $fullName, string $username, ?string $email, string $password, string $status = 'active') use ($pdo, $cashierRole): int {
        $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, password_hash, role_id, status, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 0)");
        $stmt->execute([$fullName, $username, $email, password_hash($password, PASSWORD_DEFAULT), $cashierRole, $status]);
        return (int)$pdo->lastInsertId();
    };

    $cashierId = $insertUser('Shop Cashier', 'shopcashier', 'Cashier@Store.test', 'CashierPass123');
    $noEmailId = $insertUser('No Email Clerk', 'noemailclerk', null, 'NoEmailPass123');
    $disabledId = $insertUser('Disabled Clerk', 'disabledclerk', 'Disabled@Store.test', 'DisabledPass123', 'disabled');
    $collisionUserId = $insertUser('Collision Owner', 'contact@store.test', null, 'CollisionUserPass123');
    $collisionEmailId = $insertUser('Collision Email Owner', 'contactrow', 'contact@store.test', 'CollisionEmailPass123');
    $legacyId = $insertUser('Legacy Clerk', 'legacyclerk', 'legacycontact', 'LegacyPass123');

    $recovery = new RecoveryAccountService($pdo);
    $recoveryId = $recovery->provision('sealed_recovery', password_hash('OfflineLoginPassword123', PASSWORD_DEFAULT), 'offline-activation-secret-0001');
    $recovery->activate('offline-activation-secret-0001');
    $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?')->execute(['Recovery@Store.test', $recoveryId]);

    $resetSession = static function (): void {
        $_SESSION = [];
    };
    $lastAudit = static function () use ($pdo): array {
        $row = $pdo->query('SELECT user_id, action, category, new_value FROM activity_log ORDER BY log_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    };
    $auditPayload = static function (array $row): array {
        $decoded = json_decode((string)($row['new_value'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    };

    // Username sign-in keeps working exactly as today.
    $resetSession();
    $assert(login_user($pdo, 'shopcashier', 'CashierPass123') === true, 'Username sign-in must succeed');
    $assert(($_SESSION['user_id'] ?? null) === $cashierId, 'Username sign-in must establish the cashier session');
    $assert(($_SESSION['role'] ?? null) === 'cashier', 'Username sign-in must establish the cashier role');
    $assert(!isset($_SESSION['_login_error']), 'Successful sign-in must clear the login error');
    $successAudit = $lastAudit();
    $assert($successAudit['action'] === 'Login success' && (int)$successAudit['user_id'] === $cashierId, 'Username sign-in must write a success audit for the resolved user');
    $assert($auditPayload($successAudit)['identifier_type'] === 'username', 'Username sign-in audit must record the username identifier type');
    $assert($pdo->query("SELECT last_login_at FROM users WHERE user_id = {$cashierId}")->fetchColumn() !== null, 'Successful sign-in must stamp the last login');

    // Email Address works in the same box; mixed case and padded spaces tolerated.
    $resetSession();
    $assert(login_user($pdo, '  CASHIER@store.TEST  ', 'CashierPass123') === true, 'Email sign-in with mixed case and padding must succeed');
    $assert(($_SESSION['user_id'] ?? null) === $cashierId, 'Email sign-in must establish the same cashier session');
    $emailAudit = $lastAudit();
    $assert($emailAudit['action'] === 'Login success' && (int)$emailAudit['user_id'] === $cashierId, 'Email sign-in must write a success audit for the resolved user');
    $assert($auditPayload($emailAudit)['identifier_type'] === 'email', 'Email sign-in audit must record the email identifier type');

    // Bad password shows exactly the generic sentence with no existence leak.
    $resetSession();
    $assert(login_user($pdo, 'shopcashier', 'NotThePassword123') === false, 'Bad password must fail');
    $assert(last_login_error() === 'Invalid username or password.', 'Bad password must show exactly the generic sentence');
    $badPasswordAudit = $lastAudit();
    $assert($badPasswordAudit['action'] === 'Login failure' && (int)$badPasswordAudit['user_id'] === $cashierId, 'Bad password must audit the resolved user');
    $badPasswordPayload = $auditPayload($badPasswordAudit);
    $assert($badPasswordPayload['identifier_type'] === 'username', 'Bad password audit must record the identifier type');
    $assert(!str_contains((string)$badPasswordAudit['new_value'], 'CashierPass123') && !str_contains(json_encode($badPasswordPayload), '$2y$'), 'Failure audit must not leak credentials');

    // Unknown identifier shows exactly the generic sentence; audit keeps no user.
    $resetSession();
    $assert(login_user($pdo, 'nobody@store.test', 'WhateverPass123') === false, 'Unknown identifier must fail');
    $assert(last_login_error() === 'Invalid username or password.', 'Unknown identifier must show exactly the generic sentence');
    $unknownAudit = $lastAudit();
    $assert($unknownAudit['action'] === 'Login failure' && $unknownAudit['user_id'] === null, 'Unknown identifier must audit without a resolved user');
    $assert($auditPayload($unknownAudit)['identifier_type'] === 'unknown', 'Unknown identifier audit must record the unknown identifier type');

    // Account with no email on file works via Username; email attempt fails generic.
    $resetSession();
    $assert(login_user($pdo, 'noemailclerk', 'NoEmailPass123') === true, 'No-email account must sign in by Username');
    $assert(($_SESSION['user_id'] ?? null) === $noEmailId, 'No-email Username sign-in must establish the right session');
    $resetSession();
    $assert(login_user($pdo, 'noemailclerk@store.test', 'NoEmailPass123') === false, 'No-email account must not sign in by email');
    $assert(last_login_error() === 'Invalid username or password.', 'No-email email attempt must show exactly the generic sentence');

    // Recovery Account signs in by Username; email attempt fails generic and records no use.
    $pdo->prepare('UPDATE recovery_accounts SET last_used_at = NULL WHERE user_id = ?')->execute([$recoveryId]);
    $resetSession();
    $assert(login_user($pdo, 'sealed_recovery', 'OfflineLoginPassword123') === true, 'Recovery Account must sign in by Username');
    $assert(($_SESSION['user_id'] ?? null) === $recoveryId && ($_SESSION['is_recovery_account'] ?? false) === true, 'Recovery sign-in must establish the recovery session');
    $assert($recovery->publicStatus()['last_used_at'] !== null, 'Recovery sign-in by Username must record use');
    $pdo->prepare('UPDATE recovery_accounts SET last_used_at = NULL WHERE user_id = ?')->execute([$recoveryId]);
    $resetSession();
    $assert(login_user($pdo, 'Recovery@Store.test', 'OfflineLoginPassword123') === false, 'Recovery Account must not sign in by email');
    $assert(last_login_error() === 'Invalid username or password.', 'Recovery email attempt must show exactly the generic sentence');
    $assert($recovery->publicStatus()['last_used_at'] === null, 'Recovery email attempt must not record use');

    // Disabled Account: correct password shows the disabled sentence via either
    // identifier; a bad password stays generic (no status oracle).
    foreach (['disabledclerk', 'Disabled@Store.test', '  DISABLED@STORE.test '] as $identifier) {
        $resetSession();
        $assert(login_user($pdo, $identifier, 'DisabledPass123') === false, "Disabled account must fail for identifier '{$identifier}'");
        $assert(last_login_error() === 'This account has been disabled.', "Disabled account with correct password must show the disabled sentence for '{$identifier}'");
        $disabledAudit = $lastAudit();
        $assert((int)$disabledAudit['user_id'] === $disabledId, "Disabled sign-in must audit the resolved user for '{$identifier}'");
    }
    $resetSession();
    $assert(login_user($pdo, 'disabledclerk', 'NotThePassword123') === false, 'Disabled account with bad password must fail');
    $assert(last_login_error() === 'Invalid username or password.', 'Disabled account with bad password must stay generic');

    // An email change immediately invalidates the old address.
    $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?')->execute(['CashierNew@Store.test', $cashierId]);
    $resetSession();
    $assert(login_user($pdo, 'Cashier@Store.test', 'CashierPass123') === false, 'Old email after change must fail');
    $assert(last_login_error() === 'Invalid username or password.', 'Old email after change must show exactly the generic sentence');
    $resetSession();
    $assert(login_user($pdo, 'cashiernew@store.test', 'CashierPass123') === true, 'New email after change must succeed');
    $assert(($_SESSION['user_id'] ?? null) === $cashierId, 'New email sign-in must establish the cashier session');

    // A value stored as both a Username row and an Email row resolves to the Username row.
    $resetSession();
    $assert(login_user($pdo, 'contact@store.test', 'CollisionUserPass123') === true, 'Colliding value must sign in as the Username row');
    $assert(($_SESSION['user_id'] ?? null) === $collisionUserId, 'Colliding value must establish the Username-row session');
    $resetSession();
    $assert(login_user($pdo, 'contact@store.test', 'CollisionEmailPass123') === false, 'Colliding value must not authenticate as the Email row');
    $assert(last_login_error() === 'Invalid username or password.', 'Email-row password on a colliding value must show exactly the generic sentence');
    $resetSession();
    $assert(login_user($pdo, 'contactrow', 'CollisionEmailPass123') === true, 'Shadowed Email-row account must stay reachable by its Username');
    $assert(($_SESSION['user_id'] ?? null) === $collisionEmailId, 'Shadowed account Username sign-in must establish its own session');

    // Safety fallback: an @-less value stored as an email address still resolves.
    $resetSession();
    $assert(login_user($pdo, 'legacycontact', 'LegacyPass123') === true, '@-less email fallback must succeed');
    $assert(($_SESSION['user_id'] ?? null) === $legacyId, '@-less email fallback must establish the right session');
    $assert($auditPayload($lastAudit())['identifier_type'] === 'email', '@-less email fallback audit must record the email identifier type');

    // Empty or blank identifiers fail generic and never match an empty stored email.
    $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?')->execute(['', $legacyId]);
    foreach (['', '   '] as $blank) {
        $resetSession();
        $assert(login_user($pdo, $blank, 'LegacyPass123') === false, 'Blank identifier must fail');
        $assert(last_login_error() === 'Invalid username or password.', 'Blank identifier must show exactly the generic sentence');
    }
    $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?')->execute(['legacycontact', $legacyId]);

    // Presentation: single field labelled for both identifiers, same POST key,
    // correct autocomplete hint, typed value kept on failure.
    $landing = (string)@file_get_contents($root . '/src/frontend/index.php');
    $assert(str_contains($landing, '<label for="landing-login-username">Username or email</label>'), 'Login box must be labelled "Username or email"');
    $assert(str_contains($landing, 'placeholder="Enter your username or email"'), 'Login box must hint both identifiers in its placeholder');
    $assert(str_contains($landing, 'name="username"'), 'Login box must keep the same POST key to avoid handler churn');
    $assert(str_contains($landing, 'autocomplete="username"'), 'Login box must keep the username autocomplete hint');
    $assert(str_contains($landing, '$_SESSION[\'_login_username\']'), 'Typed Login Identifier must be stored for failure repopulation');
    $assert(str_contains($landing, '$loginUsername'), 'Login box must repopulate the last typed value on failure');
} catch (Throwable $exception) {
    $failures[] = 'Staff login identifier contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Staff login identifier contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Staff login identifier contract: passed\n";
