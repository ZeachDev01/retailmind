<?php
// Friendly alerts 05 (ticket #57): Administrator operation alerts.
//
// Contract for the Administrator surfaces in shop mode: staff create,
// update, and password flows, Store Setting save, backup and restore,
// and login failures. Each shows its aligned easy sentence (what happened,
// what to do next, who to tell if it repeats) with the correct red or
// yellow kind; no password, token, raw database text, or getMessage output
// reaches the Administrator; login stays Invalid username or password or
// Disabled Account without a user-enumeration leak; full exception text
// still goes to server logs and Protected Audit Records at the page
// boundary through OperatorAlert; and with debug on the alert leads with
// the easy line plus the grey tech line through the shared wrapper.

require_once __DIR__ . '/../bootstrap/app.php';

use App\Support\OperatorAlert;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$setDebug = static function (bool $debug): void {
    if (!isset($GLOBALS['app']) || !is_array($GLOBALS['app'])) {
        $GLOBALS['app'] = [];
    }
    $GLOBALS['app']['debug'] = $debug;
};

$logPath = tempnam(sys_get_temp_dir(), 'retailmind-admin-alert-');
$previousLog = ini_get('error_log');
ini_set('error_log', $logPath);

try {
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

    $userManager = $read('src/frontend/components/user_manager/user_manager.php');
    $changePassword = $read('src/frontend/components/auth/change_password.php');
    $resetPassword = $read('src/frontend/components/auth/reset_password.php');
    $forgotPassword = $read('src/frontend/components/auth/forgot_password.php');
    $storeSettings = $read('src/frontend/components/administrator/store_settings.php');
    $backupPage = $read('src/frontend/components/system_administrator/backup_restore.php');
    $backupRoute = $read('src/backend/legacy/routes/admin/backup_restore.php');
    $auth = $read('src/backend/includes/auth.php');

    foreach ([
        'user manager' => $userManager,
        'change password' => $changePassword,
        'reset password' => $resetPassword,
        'forgot password' => $forgotPassword,
        'store settings' => $storeSettings,
        'backup page' => $backupPage,
        'backup route' => $backupRoute,
        'auth' => $auth,
    ] as $name => $source) {
        $assert($source !== '', ucfirst($name) . ' must be readable');
    }

    $leakNeedles = ['SQLSTATE', 'Stack trace', 'getMessage', '.php:', 'PDOException', 'Object of class'];
    $assertTechnicalFree = static function (string $name, string $line) use ($assert, $leakNeedles): void {
        $assert(!OperatorAlert::isTechnicalMessage($line), ucfirst($name) . ' easy sentence must stay free of technical words, got: ' . $line);
        foreach ($leakNeedles as $needle) {
            $assert(!str_contains($line, $needle), ucfirst($name) . ' easy sentence must not contain ' . $needle);
        }
    };

    // --- AC1: staff create, update, and password flows show easy sentences ---
    $easyLines = [
        'staff save' => ['The account could not be saved. Check the details and try again. Tell your Administrator if this keeps happening.', $userManager],
        'staff duplicate' => ['Unable to save the account. Username or email may already exist. Tell your Administrator if this keeps happening.', $userManager],
        'password change' => ['Your password could not be changed. Check your connection and try again. Tell your Administrator if this keeps happening.', $changePassword],
        'password reset' => ['Your password could not be reset. Check your connection and try again. Tell your Administrator if this keeps happening.', $resetPassword],
        'reset link send' => ['The reset link could not be sent. Check your connection and try again. Tell your Administrator if this keeps happening.', $forgotPassword],
    ];
    foreach ($easyLines as $name => [$line, $source]) {
        $assert(str_contains($source, $line), ucfirst($name) . ' failure must show its aligned easy sentence');
        $assert(str_contains($line, 'Tell your Administrator'), ucfirst($name) . ' easy sentence must say who to tell if it repeats');
        $assertTechnicalFree($name, $line);
    }

    // Yellow Attention kind for retryable staff and password failures.
    foreach ([
        'user manager' => $userManager,
        'store settings' => $storeSettings,
        'backup page' => $backupPage,
        'backup route' => $backupRoute,
    ] as $name => $source) {
        $assert(
            str_contains($source, 'tag-warning'),
            ucfirst($name) . ' failures must render in the yellow Attention kind'
        );
    }
    $assert(
        str_contains($changePassword, 'tag-warning'),
        'Password change failures must render in the yellow Attention kind'
    );
    $assert(
        str_contains($resetPassword, 'tag-warning'),
        'Password reset failures must render in the yellow Attention kind'
    );

    // No raw getMessage, password value, or reset token reaches the operator.
    foreach ([
        'user manager' => $userManager,
        'change password' => $changePassword,
        'reset password' => $resetPassword,
        'forgot password' => $forgotPassword,
    ] as $name => $source) {
        $assert(
            !preg_match('/\$message\s*=\s*[^;]*getMessage\s*\(/', $source),
            ucfirst($name) . ' must not surface getMessage output to the Administrator'
        );
        $assert(
            str_contains($source, 'OperatorAlert::message'),
            ucfirst($name) . ' must build the friendly message at the boundary so the full exception is logged'
        );
        $assert(
            str_contains($source, 'catch (Throwable'),
            ucfirst($name) . ' must catch every failure at the boundary'
        );
    }
    $assert(
        !str_contains($userManager, 'catch (DomainException | InvalidArgumentException | RuntimeException'),
        'User manager must catch every Throwable, not only the named domain exceptions'
    );
    $assert(
        (bool)preg_match('/catch \(PDOException[^)]*\)[\s\S]{0,400}Username or email may already exist/', $userManager),
        'User manager must keep the reachable PDOException path for the duplicate why-in-plain-words message'
    );
    $assert(
        !str_contains($changePassword, '{$password}') && !str_contains($changePassword, '$currentPassword .'),
        'Password change failures must not echo the submitted password value'
    );
    $assert(
        !preg_match('/\$error\s*=\s*[^;]*\$token/', $resetPassword),
        'Password reset failures must not echo the reset token into the error line'
    );
    $assert(
        !preg_match('/\$message\s*=\s*[^;]*\$token/', $forgotPassword),
        'Forgot password failures must not echo the reset token into the message line'
    );
    $assert(
        str_contains($forgotPassword, "If the account exists and has an email address, a password-reset link has been sent."),
        'Forgot password must keep the non-enumerating success sentence'
    );

    // --- AC2: Store Setting and backup/restore show easy sentences + kind ---
    $storeLine = 'The Store Setting could not be saved. Check the values and try again. Tell your Administrator if this keeps happening.';
    $backupLine = 'The backup could not be created. Check free space and your connection, then try again. Tell your Super Administrator if this keeps happening.';
    $restoreLine = 'The restore could not be completed. Check the backup file and try again. Tell your Super Administrator if this keeps happening.';
    $assert(str_contains($storeSettings, $storeLine), 'Store Setting save failure must show its aligned easy sentence');
    $assertTechnicalFree('store setting', $storeLine);
    $assert(str_contains($storeSettings, 'tag-warning'), 'Store Setting save failure must render in the yellow Attention kind');
    foreach (['backup page' => $backupPage, 'backup route' => $backupRoute] as $name => $source) {
        $assert(str_contains($source, $backupLine), ucfirst($name) . ' backup failure must show its aligned easy sentence');
        $assert(str_contains($source, $restoreLine), ucfirst($name) . ' restore failure must show its aligned easy sentence');
        $assertTechnicalFree($name . ' backup', $backupLine);
        $assertTechnicalFree($name . ' restore', $restoreLine);
        $assert(str_contains($source, 'tag-warning'), ucfirst($name) . ' failures must render in the yellow Attention kind');
        $assert(str_contains($source, 'OperatorAlert::message'), ucfirst($name) . ' must build the friendly message at the boundary');
        $assert(str_contains($source, 'catch (Throwable'), ucfirst($name) . ' must catch every failure at the boundary');
        $assert(str_contains($source, 'record_backup_history'), ucfirst($name) . ' must keep its Protected Audit Record trail with the full exception text');
    }

    // --- AC3: login stays Invalid username or password or Disabled Account ---
    $assert(str_contains($auth, 'Invalid username or password.'), 'Login failure must stay as Invalid username or password');
    $assert(
        str_contains($auth, "'This account has been disabled.'"),
        'Disabled Account login failure must remain a plain disabled message'
    );
    $assert(
        !str_contains($auth, 'user not found') && !str_contains($auth, 'User not found') && !str_contains($auth, 'no such user'),
        'Login must not reveal whether the username exists'
    );
    $assert(
        !str_contains($auth, 'password is incorrect') && !str_contains($auth, 'wrong password'),
        'Login must not reveal whether only the password was wrong'
    );
    // The disabled sentence must be gated behind a successful password check
    // so a wrong password on a disabled account still reads as invalid
    // credentials (no user-enumeration leak).
    $loginBlock = '';
    if (preg_match('/function login_user\(.*?^}/ms', $auth, $matches)) {
        $loginBlock = $matches[0];
    }
    $assert($loginBlock !== '', 'login_user must exist for the enumeration gate check');
    $assert(
        (bool)preg_match('/password_verify[\s\S]{0,400}disabled/i', $loginBlock)
        || (bool)preg_match('/\$passwordMatches[\s\S]{0,200}disabled/i', $loginBlock),
        'The Disabled Account sentence must only appear after password_verify succeeds'
    );
    $assert(
        str_contains($auth, 'log_activity'),
        'Login failures must keep their Protected Audit Record trail'
    );

    // --- AC4: full exception stays in logs and audit; debug adds tech ---
    $setDebug(false);
    $nasty = new RuntimeException('SQLSTATE[HY000] [2002] Connection refused in /var/www/retailmind/src/backend/app/Core/Database.php:44');
    $staffFriendly = $easyLines['staff save'][0];
    $shown = OperatorAlert::message($nasty, $staffFriendly);
    $assert($shown === $staffFriendly, 'Shop mode must show only the easy line for a technical failure, got: ' . $shown);
    foreach ($leakNeedles as $needle) {
        $assert(!str_contains($shown, $needle), 'Shop mode must not leak ' . $needle);
    }
    $logged = (string)file_get_contents($logPath);
    $assert(str_contains($logged, 'SQLSTATE'), 'The full technical exception must be written to the server log');
    $assert(str_contains($logged, 'RuntimeException'), 'The log entry must record the exception class');
    $assert(
        str_contains($userManager, 'OperatorAlert::message') && str_contains($storeSettings, 'log_activity'),
        'Staff and Store Setting failures must keep their Protected Audit Record trails'
    );
    $assert(
        str_contains($changePassword, 'log_activity') && str_contains($resetPassword, 'log_activity'),
        'Password change and reset must keep their Protected Audit Record trails'
    );

    $setDebug(true);
    $debugShown = OperatorAlert::message($nasty, $staffFriendly);
    $assert(str_starts_with($debugShown, $staffFriendly), 'Debug mode must still lead with the easy line');
    $assert(str_contains($debugShown, 'RuntimeException:'), 'Debug mode must include the grey tech line');
    $setDebug(false);
} catch (Throwable $exception) {
    $failures[] = 'Administrator alert contract threw: ' . $exception->getMessage();
} finally {
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    if (is_string($logPath) && is_file($logPath)) {
        @unlink($logPath);
    }
    $setDebug(false);
}

if ($failures) {
    fwrite(STDERR, "Administrator alert contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Administrator alert contract: passed\n";
