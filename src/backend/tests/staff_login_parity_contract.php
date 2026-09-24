<?php
// Staff sign-in parity contract (ticket #68).
//
// Disabled/Dormant handling, throttle keying, and audit identifier typing
// behave identically no matter which Login Identifier was typed. Exercises
// the shipped unified login entry point plus the throttle helpers and the
// DormancyRunner on SQLite in-memory. Asserts user-facing sentences,
// session outcomes, shared throttle buckets, dormancy references, and
// Protected Audit Records; never asserts query text. The shipped functions
// are extracted from src/backend/includes/auth.php and executed (auth.php
// itself cannot be required here because it opens the live database
// connection on include).
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Attention\Clock;
use App\Authorization\DormancyRunner;
use App\Services\UserAccountNotifier;
use App\Store\StoreScope;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

final class StaffLoginParityContractClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

try {
    date_default_timezone_set('UTC');
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
    $countCode = $extract('login_attempt_count');
    $recordCode = $extract('record_login_attempt');
    $throttleKeyCode = $extract('login_throttle_identity');
    $assert($resolveCode !== '', 'auth.php must own Login Identifier resolution at the unified entry point');
    $assert($loginCode !== '', 'auth.php must own login_user at the unified entry point');
    $assert($errorCode !== '', 'auth.php must own last_login_error at the unified entry point');
    $assert($countCode !== '', 'auth.php must own the throttle attempt counter');
    $assert($recordCode !== '', 'auth.php must own throttle attempt recording');
    $assert($throttleKeyCode !== '', 'auth.php must own the resolved-identity throttle key helper');
    if ($resolveCode === '' || $loginCode === '' || $errorCode === '' || $countCode === '' || $recordCode === '' || $throttleKeyCode === '') {
        throw new RuntimeException('Shipped login parity entry point could not be loaded.');
    }
    eval($resolveCode);
    eval($loginCode);
    eval($errorCode);
    eval($countCode);
    eval($recordCode);
    eval($throttleKeyCode);

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
        branch_id TEXT NULL,
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
        metadata TEXT NULL,
        ip_address TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE login_attempts (
        attempt_id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        ip_address TEXT NOT NULL,
        was_successful INTEGER NOT NULL DEFAULT 0,
        attempted_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE TABLE platform_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_by INTEGER NULL,
        updated_at TEXT NULL
    )');
    $pdo->exec("INSERT INTO platform_settings (setting_key, setting_value)
                VALUES ('dormancy_disable_days', '40'), ('dormancy_warn_days', '25')");
    $pdo->exec("CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        reference_id INTEGER NULL,
        reference_type TEXT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL
    )");
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('super_admin'), ('admin'), ('inventory_manager'), ('cashier')");
    (new StoreScope($pdo))->migrate();

    $now = new DateTimeImmutable('now');
    $daysAgo = static fn(int $days): string => $now->modify("-{$days} days")->format('Y-m-d H:i:s');

    $roleId = static function (string $role) use ($pdo): int {
        $stmt = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ?');
        $stmt->execute([$role]);
        return (int)$stmt->fetchColumn();
    };
    $cashierRole = $roleId('cashier');
    $insertUser = static function (string $fullName, string $username, ?string $email, string $passwordHash, string $status = 'active', ?string $lastLoginAt = null) use ($pdo, $cashierRole): int {
        $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, password_hash, role_id, status, must_change_password, last_login_at, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
        $stmt->execute([$fullName, $username, $email, $passwordHash, $cashierRole, $status, $lastLoginAt, $lastLoginAt ?? date('Y-m-d H:i:s')]);
        return (int)$pdo->lastInsertId();
    };

    $cashierId = $insertUser('Parity Cashier', 'paritycashier', 'Parity@Store.test', password_hash('ParityPass123', PASSWORD_DEFAULT));
    $disabledId = $insertUser('Parity Disabled', 'paritydisabled', 'ParityDisabled@Store.test', password_hash('DisabledPass123', PASSWORD_DEFAULT), 'disabled');
    $emailMoverId = $insertUser('Email Mover', 'emailmover', 'EmailMover@Store.test', password_hash('MoverPass123', PASSWORD_DEFAULT), 'active', $daysAgo(45));
    $usernameMoverId = $insertUser('Username Mover', 'usernamemover', 'UsernameMover@Store.test', password_hash('MoverPass123', PASSWORD_DEFAULT), 'active', $daysAgo(45));
    $staleId = $insertUser('Stale Cashier', 'stalecashier', 'Stale@Store.test', 'hash-stalecashier', 'active', $daysAgo(45));

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
    $lastLoginAt = static function (int $userId) use ($pdo): ?string {
        $stmt = $pdo->prepare('SELECT last_login_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    };

    // Disabled parity: correct password shows the disabled sentence via
    // either identifier; a wrong password stays generic via either.
    foreach (['paritydisabled', 'ParityDisabled@Store.test', '  PARITYDISABLED@STORE.test '] as $identifier) {
        $resetSession();
        $assert(login_user($pdo, $identifier, 'DisabledPass123') === false, "Disabled account must fail for identifier '{$identifier}'");
        $assert(last_login_error() === 'This account has been disabled.', "Disabled account with correct password must show the disabled sentence for '{$identifier}'");
        $disabledAudit = $lastAudit();
        $assert((int)$disabledAudit['user_id'] === $disabledId, "Disabled sign-in must audit the resolved user for '{$identifier}'");
        $disabledPayload = $auditPayload($disabledAudit);
        $assert(($disabledPayload['reason'] ?? '') === 'inactive_account', "Disabled sign-in audit must record the inactive reason for '{$identifier}'");
        $assert(!str_contains((string)$disabledAudit['new_value'], 'DisabledPass123'), "Disabled audit must not leak credentials for '{$identifier}'");
    }
    $assert($auditPayload($lastAudit())['identifier_type'] === 'email', 'Disabled sign-in via email must record the email identifier type');
    foreach (['paritydisabled', 'ParityDisabled@Store.test'] as $identifier) {
        $resetSession();
        $assert(login_user($pdo, $identifier, 'NotThePassword123') === false, "Disabled account with bad password must fail for '{$identifier}'");
        $assert(last_login_error() === 'Invalid username or password.', "Disabled account with bad password must stay generic for '{$identifier}'");
        $wrongAudit = $lastAudit();
        $assert((int)$wrongAudit['user_id'] === $disabledId, "Disabled bad-password sign-in must still audit the resolved user for '{$identifier}'");
        $assert(($auditPayload($wrongAudit)['reason'] ?? '') === 'invalid_credentials', "Disabled bad-password audit must record invalid credentials for '{$identifier}'");
    }

    // Throttle parity: Username vs Email Address share one bucket keyed on
    // the resolved identity plus network address, never the raw typed string.
    $ip = get_client_ip_address();
    $window = 15;
    $keyViaUsername = login_throttle_identity(resolve_login_user($pdo, 'paritycashier')['user'], 'paritycashier');
    $keyViaEmail = login_throttle_identity(resolve_login_user($pdo, 'Parity@Store.test')['user'], 'Parity@Store.test');
    $assert($keyViaUsername === $keyViaEmail, 'Username and Email Address of one account must share one throttle key');
    $assert(str_starts_with($keyViaUsername, 'user:'), 'Resolved throttle key must name the resolved identity');
    $assert(login_attempt_count($pdo, $keyViaUsername, $ip, $window) === 0, 'Throttle bucket must start empty');
    $resetSession();
    $assert(login_user($pdo, 'paritycashier', 'NotThePassword123') === false, 'Throttle probe via Username must fail');
    $resetSession();
    $assert(login_user($pdo, '  PARITY@store.TEST ', 'NotThePassword123') === false, 'Throttle probe via Email must fail');
    $assert(login_attempt_count($pdo, $keyViaUsername, $ip, $window) === 2, 'Both identifiers must land in the one shared throttle bucket (no double guesses)');
    $assert(login_attempt_count($pdo, 'paritycashier', $ip, $window) === 0, 'Throttle must never key on the raw typed Username');
    $assert(login_attempt_count($pdo, 'Parity@Store.test', $ip, $window) === 0, 'Throttle must never key on the raw typed Email Address');
    $assert(login_attempt_count($pdo, $keyViaUsername, '10.0.0.99', $window) === 0, 'Throttle bucket must include the network address');
    $unknownKey = login_throttle_identity(null, 'ghost@store.test');
    $assert($unknownKey !== $keyViaUsername, 'Unknown identifiers must not share a resolved account bucket');
    $resetSession();
    $assert(login_user($pdo, 'ghost@store.test', 'WhateverPass123') === false, 'Unknown identifier must fail');
    $assert(last_login_error() === 'Invalid username or password.', 'Unknown identifier must show exactly the generic sentence');
    $unknownAudit = $lastAudit();
    $assert($unknownAudit['user_id'] === null, 'Unknown identifier must audit without a resolved user');
    $assert($auditPayload($unknownAudit)['identifier_type'] === 'unknown', 'Unknown identifier audit must record the unknown identifier type');
    $assert(login_attempt_count($pdo, $unknownKey, $ip, $window) === 1, 'Unknown identifier failures must be accounted under the unknown key');
    $assert(login_attempt_count($pdo, $keyViaUsername, $ip, $window) === 2, 'Unknown failures must not pollute the resolved account bucket');
    $resetSession();
    $assert(login_user($pdo, 'parity@store.test', 'ParityPass123') === true, 'Correct sign-in via Email must succeed');
    $assert(($_SESSION['user_id'] ?? null) === $cashierId, 'Email sign-in must establish the cashier session');
    $assert($auditPayload($lastAudit())['identifier_type'] === 'email', 'Email success audit must record the email identifier type');
    $assert(login_attempt_count($pdo, $keyViaUsername, $ip, $window) === 0, 'Successful sign-in must clear the shared throttle bucket');

    // Dormancy parity: a sign-in via either identifier refreshes the
    // dormancy reference equally, so the policy clock treats both alike.
    $assert($lastLoginAt($emailMoverId) !== null && strtotime((string)$lastLoginAt($emailMoverId)) < time() - 40 * 86400, 'Email mover must start dormant');
    $resetSession();
    $assert(login_user($pdo, '  EMAILMOVER@store.TEST ', 'MoverPass123') === true, 'Dormant mover must sign in via Email');
    $assert(($_SESSION['user_id'] ?? null) === $emailMoverId, 'Email mover sign-in must establish its own session');
    $emailStamp = strtotime((string)$lastLoginAt($emailMoverId));
    $assert($emailStamp !== false && abs(time() - $emailStamp) < 120, 'Sign-in via Email must refresh the dormancy reference timestamp');
    $resetSession();
    $assert(login_user($pdo, 'usernamemover', 'MoverPass123') === true, 'Dormant mover must sign in via Username');
    $usernameStamp = strtotime((string)$lastLoginAt($usernameMoverId));
    $assert($usernameStamp !== false && abs(time() - $usernameStamp) < 120, 'Sign-in via Username must refresh the dormancy reference timestamp');

    $notifier = new class extends UserAccountNotifier {
        /** @var array<int, array{email: string, subject: string, message: string}> */
        public array $delivered = [];
        protected function deliver(string $email, string $subject, string $message): bool
        {
            $this->delivered[] = ['email' => $email, 'subject' => $subject, 'message' => $message];
            return true;
        }
    };
    $runner = new DormancyRunner($pdo, new StaffLoginParityContractClock($now), null, $notifier);
    $outcome = $runner->run();
    $assert(in_array($staleId, $outcome['disabled'], true), 'Dormancy must still disable an account with no fresh sign-in');
    $assert(!in_array($emailMoverId, $outcome['disabled'], true), 'Dormancy must spare the account whose clock was reset via Email');
    $assert(!in_array($usernameMoverId, $outcome['disabled'], true), 'Dormancy must spare the account whose clock was reset via Username');
    $statusOf = static function (int $userId) use ($pdo): string {
        $stmt = $pdo->prepare('SELECT status FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (string)$stmt->fetchColumn();
    };
    $assert($statusOf($staleId) === 'disabled', 'Stale account must read disabled after the policy run');
    $assert($statusOf($emailMoverId) === 'active', 'Email-refreshed account must stay active after the policy run');
    $assert($statusOf($usernameMoverId) === 'active', 'Username-refreshed account must stay active after the policy run');

    // Glossary: Login Identifier plus Username (never @), Email Address
    // (alternate, optional, unique), Recovery exclusion restated.
    $context = (string)@file_get_contents($root . '/CONTEXT.md');
    $assert(str_contains($context, 'Login Identifier'), 'Glossary must define the Login Identifier');
    $assert(str_contains($context, '**Username**'), 'Glossary must define the Username');
    $assert(str_contains($context, 'never contains `@`'), 'Glossary must state the Username never contains `@`');
    $assert(str_contains($context, '**Email Address**'), 'Glossary must define the Email Address');
    $assert(str_contains($context, 'optional') && str_contains($context, 'unique'), 'Glossary must state email is optional and unique');
    $assert(str_contains($context, 'alternate'), 'Glossary must state email is an alternate co-option');
    $assert(substr_count($context, 'excluded from email matching') >= 2, 'Glossary must restate the Recovery Account email exclusion');

    // ADR-0002: exact generic sentences, disabled sentence only after a
    // correct password, no technical leak in what staff see.
    foreach (['This account has been disabled.', 'Invalid username or password.'] as $sentence) {
        foreach (['SQLSTATE', 'Exception', 'PDO', 'stack trace', '.php'] as $leak) {
            $assert(!str_contains($sentence, $leak), "Shipped sentence must not leak technical detail ({$leak})");
        }
    }

    // Registration: the suite must run this contract.
    $runAll = (string)@file_get_contents($root . '/src/backend/tests/run_all.sh');
    $assert(str_contains($runAll, 'staff_login_parity_contract.php'), 'run_all.sh must run the login parity contract');
} catch (Throwable $exception) {
    $failures[] = 'Staff login parity contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Staff login parity contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Staff login parity contract: passed\n";
