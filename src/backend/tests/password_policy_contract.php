<?php
// Unified password policy regression sweep (ticket #36).
// Verifies the 8-character floor holds in any environment, every staff entry
// point shares one rule, and existing hashes stay valid without force-reset.
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

require_once __DIR__ . '/../includes/password_policy.php';

$setConfig = static function (?string $value, ?string $legacyValue = null): void {
    foreach (['PASSWORD_MIN_LENGTH', 'PASSWORD_MIN_length'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
    if ($value !== null) {
        putenv('PASSWORD_MIN_LENGTH=' . $value);
        $_ENV['PASSWORD_MIN_LENGTH'] = $value;
        $_SERVER['PASSWORD_MIN_LENGTH'] = $value;
    }
    if ($legacyValue !== null) {
        putenv('PASSWORD_MIN_length=' . $legacyValue);
        $_ENV['PASSWORD_MIN_length'] = $legacyValue;
        $_SERVER['PASSWORD_MIN_length'] = $legacyValue;
    }
};

try {
    // Default environment resolves to the unified 8-character minimum.
    $setConfig(null);
    $assert(password_minimum_length() === 8, 'Default password minimum should resolve to 8');

    // No environment setting can silently lower the minimum below 8.
    foreach (['7', '6', '2', '0', '-1', '', 'abc'] as $lowValue) {
        $setConfig($lowValue);
        $assert(
            password_minimum_length() === 8,
            "PASSWORD_MIN_LENGTH={$lowValue} must still resolve to a minimum of 8"
        );
        $assert(
            password_policy_error('Ab1def7') !== null,
            "7-character password must be rejected when PASSWORD_MIN_LENGTH={$lowValue}"
        );
    }
    // A misspelled/legacy mixed-case key is ignored, so it cannot lower the floor either.
    $setConfig(null, '6');
    $assert(password_minimum_length() === 8, 'Legacy PASSWORD_MIN_length=6 must still resolve to a minimum of 8');

    // A higher configured minimum is honoured (floor, not a fixed ceiling).
    $setConfig('10');
    $assert(password_minimum_length() === 10, 'PASSWORD_MIN_LENGTH=10 should resolve to 10');
    $assert(password_policy_error('Ab1def78') !== null, '8-character password must be rejected when minimum is raised to 10');
    $setConfig('12');
    $assert(password_minimum_length() === 12, 'PASSWORD_MIN_LENGTH=12 should resolve to 12');

    // Back to the unified default for the remaining boundary checks.
    $setConfig(null);
    $setConfig('8');
    $assert(password_minimum_length() === 8, 'PASSWORD_MIN_LENGTH=8 should resolve to 8');

    // Standard staff boundary: 7 rejected, 8 accepted (with complexity).
    $seven = password_policy_error('Ab1def7');
    $assert($seven !== null && str_contains($seven, '8'), '7-character standard password must be rejected with an 8-character message');
    $assert(password_policy_error('Ab1def78') === null, '8-character compliant standard password must be accepted');

    // Complexity is preserved for standard staff.
    foreach (['abcdefgh', 'ABCDEFGH', '12345678', 'Abcdefgh', 'Ab1'] as $weak) {
        $assert(password_policy_error($weak) !== null, "Standard password '{$weak}' must be rejected (length or complexity)");
    }

    // Recovery Account boundary: same 8-character plus complexity rule.
    $recoverySeven = recovery_password_policy_error('Ab1def7');
    $assert($recoverySeven !== null && str_contains($recoverySeven, '8'), '7-character recovery password must be rejected with an 8-character message');
    $assert(recovery_password_policy_error('Ab1def78') === null, '8-character compliant recovery password must be accepted');
    foreach (['abcdefgh', 'ABCDEFGH', '12345678', 'Abcdefgh'] as $weak) {
        $assert(recovery_password_policy_error($weak) !== null, "Recovery password '{$weak}' must be rejected (length or complexity)");
    }
    // Recovery honours the same floor when the minimum is lowered or raised.
    $setConfig('6');
    $assert(recovery_password_policy_error('Ab1def7') !== null, 'Recovery 7-character password must be rejected when minimum is lowered to 6');
    $setConfig('10');
    $assert(recovery_password_policy_error('Ab1def78') !== null, 'Recovery 8-character password must be rejected when minimum is raised to 10');
    $setConfig('8');

    // Existing stored hashes remain valid; enforcement applies at
    // creation/change/reset events only, with no force-reset.
    foreach (['Ab1def78', 'CashierPassword123', 'OfflineLoginPassword123'] as $existing) {
        $hash = password_hash($existing, PASSWORD_DEFAULT);
        $assert(password_verify($existing, $hash), "Existing stored credential '{$existing}' must still verify");
    }
    $legacyShortHash = password_hash('Ab1def7', PASSWORD_DEFAULT);
    $assert(password_verify('Ab1def7', $legacyShortHash), 'Previously stored hashes must verify even if they predate the unified policy');

    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);
    $authSource = $read('src/backend/includes/auth.php');
    $loginBlock = '';
    if (preg_match('/function login_user\(.*?^}/ms', $authSource, $matches)) {
        $loginBlock = $matches[0];
    }
    $assert($loginBlock !== '' && !str_contains($loginBlock, 'password_policy_error'), 'login_user must verify hashes without re-validating policy (no force-reset)');
    $validateBlock = '';
    if (preg_match('/function validate_current_session\(.*?^}/ms', $authSource, $matches)) {
        $validateBlock = $matches[0];
    }
    $assert($validateBlock !== '' && !str_contains($validateBlock, 'password_policy_error'), 'validate_current_session must not re-validate policy (no force-reset)');

    // Every staff password entry point enforces the shared rule server-side.
    foreach ([
        'src/frontend/components/user_manager/user_manager.php',
        'src/frontend/components/auth/change_password.php',
        'src/frontend/components/auth/reset_password.php',
        'src/backend/legacy/routes/admin/manage_users.php',
    ] as $entryPoint) {
        $assert(str_contains($read($entryPoint), 'password_policy_error'), "{$entryPoint} must enforce password_policy_error server-side");
    }
    $recoveryScript = $read('src/backend/scripts/recovery_account.php');
    $assert(
        str_contains($recoveryScript, 'recovery_password_policy_error') || str_contains($recoveryScript, 'password_minimum_length'),
        'src/backend/scripts/recovery_account.php must enforce the shared recovery policy'
    );

    // Hint text states the same expectation on every staff form.
    $expectedHint = 'Use at least 8 characters with uppercase, lowercase, and a number.';
    foreach ([
        'src/frontend/components/user_manager/modals/add_user_modal.php',
        'src/frontend/components/user_manager/modals/manage_user_modal.php',
        'src/frontend/components/auth/change_password.php',
        'src/frontend/components/auth/reset_password.php',
        'src/backend/legacy/routes/admin/manage_users.php',
    ] as $form) {
        $source = $read($form);
        $assert(str_contains($source, $expectedHint), "{$form} must state the unified 8-character expectation");
        $assert(str_contains($source, 'minlength="8"'), "{$form} must block short passwords client-side with minlength=\"8\"");
    }
    $feedback = $read('src/frontend/assets/js/password-feedback.js');
    $assert(str_contains($feedback, 'MIN_LENGTH = 8') || str_contains($feedback, 'MIN_LENGTH=8'), 'password-feedback.js must use an 8-character minimum');

    // Single-Store administrator boundaries: Recovery Account lifecycle stays
    // under Super Administrator offline control with Protected Audit Records.
    $assert(str_contains($recoveryScript, "PHP_SAPI !== 'cli'"), 'Recovery Account procedure must remain CLI-only');
    foreach ([
        'src/frontend/components/auth/forgot_password.php',
        'src/frontend/components/auth/reset_password.php',
    ] as $resetEntry) {
        $assert(str_contains($read($resetEntry), 'is_recovery_account'), "{$resetEntry} must exclude the Recovery Account from email reset");
    }
    $assert(str_contains($read('src/frontend/components/auth/change_password.php'), 'Recovery Account credentials can be rotated only through the offline recovery procedure.'), 'change_password.php must keep the Recovery Account on the offline procedure');
    $recoveryService = $read('src/backend/app/Services/RecoveryAccountService.php');
    $assert(str_contains($recoveryService, 'recovery_account') || str_contains($recoveryService, 'Recovery Account'), 'RecoveryAccountService must emit Protected Audit Records');
} catch (Throwable $exception) {
    $failures[] = 'Password policy contract threw: ' . $exception->getMessage();
} finally {
    $setConfig(null);
    putenv('PASSWORD_MIN_LENGTH=8');
    $_ENV['PASSWORD_MIN_LENGTH'] = '8';
    $_SERVER['PASSWORD_MIN_LENGTH'] = '8';
}

if ($failures) {
    fwrite(STDERR, "Password policy contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Password policy contract: passed\n";
