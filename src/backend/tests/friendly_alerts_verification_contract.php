<?php
// Friendly alerts 06 (ticket #58): log and audit preservation, decision
// note, and verification matrix — the capstone for parent ticket #52.
//
// Proves the whole chain at the plain-PHP seams: AC1 user-facing text and
// logged text stay separate (shop alerts show easy words while the app log
// and Protected Audit Records keep the full exception); AC2 the four agreed
// seams are covered (domain service boundary message builder, shared wrapper
// safety net, global fallback gated by the debug flag, and the database
// connection failure path); AC3 the decision note records why Operator Alerts
// hide tech detail (fast dev view versus calm shop view); AC4 the manual
// matrix records save, camera, and checkout failures with debug off showing
// easy only and debug on showing easy plus tech line, with the out-of-scope
// list respected.

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Support\ErrorFallback;
use App\Support\OperatorAlert;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$contains = static function (string $haystack, string $needle, string $failure) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $failure . ' (missing: ' . $needle . ')';
    }
};

$setDebug = static function (bool $debug): void {
    if (!isset($GLOBALS['app']) || !is_array($GLOBALS['app'])) {
        $GLOBALS['app'] = [];
    }
    $GLOBALS['app']['debug'] = $debug;
};

$leakNeedles = ['SQLSTATE', 'Stack trace', 'RuntimeException', 'PDOException', '.php:', '/var/www', 'Object of class'];

$saveLine = 'The stock report could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.';
$checkoutLine = 'The sale could not finish. Please try again. Tell your Administrator if this keeps happening.';
$cameraLine = 'The camera could not start. Check your camera permission, or enter the code manually to continue. Tell your Administrator if this keeps happening.';
$backupLine = 'The backup could not be created. Check free space and your connection, then try again. Tell your Super Administrator if this keeps happening.';

$logPath = tempnam(sys_get_temp_dir(), 'retailmind-friendly-alerts-verify-');
$previousLog = ini_get('error_log');
ini_set('error_log', $logPath);

try {
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

    $ui = $read('src/frontend/assets/js/ui.js');
    $runAll = $read('src/backend/tests/run_all.sh');
    $bootstrap = $read('src/backend/bootstrap/app.php');
    $dbConfig = $read('src/backend/config/db.php');
    $modalsCss = $read('src/frontend/assets/css/modals.css');
    $pos = $read('src/frontend/components/cashier/pos.php');
    $stock = $read('src/frontend/components/cashier/stock_issues.php');
    $backupPage = $read('src/frontend/components/system_administrator/backup_restore.php');
    $backupRoute = $read('src/backend/legacy/routes/admin/backup_restore.php');
    $assert(str_contains($backupRoute, 'frontend/components/system_administrator/backup_restore.php'), 'Legacy restore route must delegate to the guarded page');
    // Assertions below follow the actual included error boundary.
    $backupRoute .= $backupPage;
    $auth = $read('src/backend/includes/auth.php');
    $alertSource = $read('src/backend/app/Support/OperatorAlert.php');
    $fallbackSource = $read('src/backend/app/Support/ErrorFallback.php');
    $decisionNote = $read('docs/adr/0002-operator-alerts-hide-tech-detail.md');
    $matrix = $read('docs/verification/friendly-alerts-matrix.md');

    // --- AC1: shop alerts show easy words while the log keeps the full exception ---
    $setDebug(false);
    $nasty = new RuntimeException(
        'SQLSTATE[HY000] [2002] Connection refused in /var/www/retailmind/src/backend/app/Core/Database.php:44'
    );
    $shown = OperatorAlert::message($nasty, $checkoutLine);
    $assert($shown === $checkoutLine, 'Shop mode must show only the easy line for a technical failure, got: ' . $shown);
    foreach ($leakNeedles as $needle) {
        $assert(!str_contains($shown, $needle), 'Shop mode must not leak ' . $needle . ' into the operator alert');
    }
    $logged = (string)file_get_contents($logPath);
    $contains($logged, '[operator-alert]', 'The boundary must write an operator-alert log entry');
    $contains($logged, 'SQLSTATE', 'The app log must keep the full technical exception text');
    $contains($logged, 'RuntimeException', 'The log entry must record the exception class');
    $contains($logged, 'Database.php', 'The log entry must record the failing file');

    // Protected Audit Records keep the full exception text at the same
    // boundary where the operator only ever sees the easy line.
    foreach (['backup page' => $backupPage, 'backup route' => $backupRoute] as $name => $source) {
        $assert(
            (bool)preg_match('/record_backup_history\([^;]*\$e->getMessage\(\)/', $source),
            ucfirst($name) . ' must store the full exception text in its Protected Audit Record trail'
        );
        $contains($source, 'OperatorAlert::message', ucfirst($name) . ' must build the easy line at the boundary');
        $contains($source, $backupLine, ucfirst($name) . ' must show the aligned easy line to the operator');
        $assert(
            !OperatorAlert::isTechnicalMessage($backupLine),
            ucfirst($name) . ' easy line must stay free of technical words, got: ' . $backupLine
        );
    }
    $contains($auth, 'log_activity', 'Login failures must keep their Protected Audit Record trail');
    $contains($auth, 'Invalid username or password.', 'Login failure wording must stay non-enumerating');

    // --- AC2a: domain service boundary message builder ---
    $domain = new RuntimeException('Not enough stock for product #5.');
    $assert(
        OperatorAlert::message($domain, $saveLine) === 'Not enough stock for product #5.',
        'Plain domain messages must pass through to the operator unchanged'
    );
    $setDebug(true);
    $debugShown = OperatorAlert::message($nasty, $saveLine);
    $debugLines = explode("\n", $debugShown);
    $assert(count($debugLines) >= 2, 'Debug mode must separate the easy line from the tech line');
    $assert($debugLines[0] === $saveLine, 'Debug mode must still lead with the easy line');
    $contains($debugShown, 'SQLSTATE', 'Debug mode must surface the real tech line for developers');
    $contains($debugShown, 'RuntimeException', 'Debug tech line must name the exception class');
    $setDebug(false);

    // --- AC2b: shared wrapper safety net (plain-PHP source seam) ---
    $contains($ui, 'sanitizeAlert', 'ui.js must expose the shared wrapper safety net');
    $contains($ui, 'SQLSTATE', 'Safety net must recognise SQLSTATE leaks');
    $contains($ui, 'Stack trace', 'Safety net must recognise stack traces');
    $contains($ui, 'RM_DEBUG', 'Safety net must gate the tech line on the debug flag');
    $contains($ui, 'rm-toast-tech', 'Debug tech line must render in toasts');
    $contains($ui, 'rm-swal-tech', 'Debug tech line must render in modal alerts');
    $contains($ui, 'console.error', 'Debug mode must emit technical detail to the developer console');
    $contains($ui, 'Tell your Administrator if this keeps happening', 'Generic easy line must name who to tell');
    $assert((bool)preg_match('/error:\s*"Unable to continue"/', $ui), 'Blocked work must keep the red title');
    $assert((bool)preg_match('/warning:\s*"Attention"/', $ui), 'Retryable work must keep the yellow title');
    $assert(substr_count($ui, 'sanitizeAlert') >= 4, 'Toast, alert, and confirm must all apply the safety net');

    // The full behavioral wrapper check stays registered in the suite.
    $wrapperTest = 'src/backend/tests/operator_alert_wrapper_behavior_test.js';
    $assert(is_file($root . '/' . $wrapperTest), 'Wrapper behavior test must exist');
    $contains($runAll, $wrapperTest, 'run_all.sh must register the wrapper behavior test');
    $contains($runAll, 'src/backend/tests/operator_alert_test.php', 'run_all.sh must register the message builder test');
    $contains($runAll, 'src/backend/tests/operator_alert_ui_contract.php', 'run_all.sh must register the wrapper source contract');
    $contains($runAll, 'src/backend/tests/operator_alert_fallback_contract.php', 'run_all.sh must register the fallback and database contract');
    $contains($runAll, 'src/backend/tests/friendly_alerts_verification_contract.php', 'run_all.sh must register this capstone contract');

    // --- AC2c: global fallback gated by the debug flag ---
    $htmlOff = ErrorFallback::page($nasty, false);
    $contains($htmlOff, OperatorAlert::GENERIC_FALLBACK, 'Shop-mode page fallback must show the aligned easy line');
    $contains($htmlOff, 'data-kind="error"', 'Page fallback must declare the red error kind');
    foreach ($leakNeedles as $needle) {
        $assert(!str_contains($htmlOff, $needle), 'Shop-mode page fallback must not leak ' . $needle);
    }
    $assert(!str_contains($htmlOff, '#9ca3af'), 'Debug-off page fallback must not render the grey tech line');
    $htmlOn = ErrorFallback::page($nasty, true);
    $contains($htmlOn, 'RuntimeException: SQLSTATE', 'Debug page fallback must show the tech line');
    $contains($htmlOn, '#9ca3af', 'Debug page fallback must render the tech line in grey');
    $payloadOff = ErrorFallback::json($nasty, false);
    $assert(($payloadOff['success'] ?? true) === false, 'JSON fallback must report success false');
    $assert(
        (string)($payloadOff['message'] ?? '') === ErrorFallback::JSON_MESSAGE,
        'Shop-mode JSON fallback must use the aligned easy message'
    );
    $assert(!array_key_exists('tech', $payloadOff), 'Shop-mode JSON fallback must not carry a tech line');
    $payloadOn = ErrorFallback::json($nasty, true);
    $contains((string)($payloadOn['tech'] ?? ''), 'SQLSTATE', 'Debug JSON fallback must surface the tech line');
    $contains($bootstrap, 'ErrorFallback::page', 'Global handler must render through the shared page fallback');
    $contains($bootstrap, 'ErrorFallback::json', 'Global handler must answer scanner routes through the JSON fallback');
    $contains($bootstrap, 'error_log', 'Global handler must keep full detail in server logs');

    // --- AC2d: database connection failure path ---
    $badConfig = [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '1',
        'database' => 'retailmind_missing',
        'username' => 'retailmind_missing',
        'password' => '',
        'charset' => 'utf8mb4',
    ];
    $caught = null;
    try {
        Database::connection($badConfig);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }
    $assert($caught !== null, 'A refused connection must surface as a RuntimeException');
    if ($caught !== null) {
        $assert(
            $caught->getMessage() === 'Unable to connect to the database.',
            'The thrown message must stay the easy line, got: ' . $caught->getMessage()
        );
        $assert(
            $caught->getPrevious() instanceof PDOException,
            'The original PDOException must stay chained for full detail'
        );
        $contains(
            ErrorFallback::techDetail($caught),
            'PDOException',
            'Debug tech detail must reach the root PDOException'
        );
    }
    $contains($dbConfig, 'ErrorFallback::page', 'DB failure must render through the shared page fallback');
    $contains($dbConfig, 'ErrorFallback::json', 'DB failure must answer scanner routes through the JSON fallback');
    $contains($dbConfig, 'error_log', 'DB failure must keep logging full detail server-side');
    $assert(
        !str_contains($dbConfig, "die('Database connection failed."),
        'DB failure must not die() with the old hard-coded line'
    );
    $logged = (string)file_get_contents($logPath);
    $contains($logged, 'Database connection failed', 'The log entry must mark the connection failure');
    $contains($logged, 'PDOException', 'The log entry must record the original exception class');
    $contains($logged, 'SQLSTATE', 'The full PDO detail must be written to the server log');

    // --- AC3: decision note records the trade-off ---
    $assert($decisionNote !== '', 'Decision note must exist at docs/adr/0002-operator-alerts-hide-tech-detail.md');
    foreach (['Operator Alert', 'fast dev view', 'calm shop view', 'APP_DEBUG', 'Protected Audit Record', 'full exception', 'live Store'] as $needle) {
        $contains($decisionNote, $needle, 'Decision note must cover the recorded trade-off');
    }

    // --- AC4: manual matrix recorded ---
    $assert($matrix !== '', 'Manual matrix must exist at docs/verification/friendly-alerts-matrix.md');
    foreach ([
        'Save failure',
        'Camera failure',
        'Checkout failure',
        'Debug off',
        'Debug on',
        'easy only',
        'easy plus tech line',
    ] as $needle) {
        $contains($matrix, $needle, 'Manual matrix must record the scenario row');
    }
    $assert(substr_count($matrix, 'Log check') >= 3, 'Every matrix row must record its log check');
    foreach ([
        'Demand Forecast',
        'Fiscal Period',
        'Emergency Access',
        'Recovery Account',
        'translation',
        'toast redesign',
        'retry',
    ] as $needle) {
        $contains($matrix, $needle, 'Manual matrix must record the out-of-scope item');
    }

    // Save failure row enforced at its seam: easy only off, easy plus tech on.
    $contains($stock, 'OperatorAlert::message', 'Save failure must build the easy line at the boundary');
    $contains($stock, $saveLine, 'Save failure must show its aligned easy line');
    $contains($stock, 'catch (Throwable', 'Save failure must catch every failure at the boundary');
    $setDebug(false);
    $assert(
        OperatorAlert::message($nasty, $saveLine) === $saveLine,
        'Save failure with debug off must show easy only'
    );
    $setDebug(true);
    $saveDebug = OperatorAlert::message($nasty, $saveLine);
    $assert(str_starts_with($saveDebug, $saveLine), 'Save failure with debug on must lead with the easy line');
    $contains($saveDebug, 'SQLSTATE', 'Save failure with debug on must show easy plus tech line');
    $setDebug(false);

    // Camera failure row: easy line always, tech line gated on the debug flag,
    // full detail kept on the developer console.
    $contains($pos, $cameraLine, 'Camera failure must show its aligned easy line');
    $assert(
        !OperatorAlert::isTechnicalMessage($cameraLine),
        'Camera easy line must stay free of technical words, got: ' . $cameraLine
    );
    $assert(
        (bool)preg_match('/isDebug\(\)[^;]{0,160}String\(detail\)/', $pos),
        'Camera tech line must be gated behind the debug flag'
    );
    $contains($pos, "console.error('Camera start failed:'", 'Camera failure must keep full detail on the developer console');

    // Checkout failure row enforced at its seam: easy only off, easy plus tech on.
    $contains($pos, 'OperatorAlert::message($e, \'The sale could not finish.', 'Checkout must build the easy line at the boundary');
    $assert(
        (bool)preg_match('/<div class="pos-alert error".*\$checkout_error/', $pos),
        'Checkout failure must render in the red Unable to continue container'
    );

    // --- AC4 out-of-scope respected: no Operator Alert wiring on those surfaces ---
    $outOfScope = [
        'src/frontend/components/report/predictions.php',
        'src/frontend/components/system_administrator/ml_settings.php',
        'src/backend/legacy/routes/admin/ml_settings.php',
        'src/frontend/components/system_administrator/fiscal_periods.php',
        'src/frontend/components/modals/fiscal_periods.php',
        'src/backend/legacy/routes/admin/fiscal_periods.php',
        'src/frontend/components/system_administrator/emergency_access.php',
        'src/frontend/components/system_administrator/recovery_account.php',
        'src/backend/scripts/recovery_account.php',
    ];
    foreach ($outOfScope as $relative) {
        $source = $read($relative);
        $assert($source !== '', ucfirst($relative) . ' must be readable');
        foreach (['OperatorAlert', 'RM_DEBUG', 'sanitizeAlert'] as $needle) {
            $assert(
                !str_contains($source, $needle),
                ucfirst($relative) . ' is out of scope and must not gain ' . $needle
            );
        }
    }

    // No translation work was added.
    foreach (['src/frontend/lang', 'src/frontend/locales', 'src/frontend/translations', 'src/frontend/i18n', 'src/backend/lang', 'src/backend/locales', 'src/backend/translations', 'src/backend/i18n'] as $relative) {
        $assert(!is_dir($root . '/' . $relative), 'Out of scope: no translation directory may be added (' . $relative . ')');
    }

    // Toast look, position, and timing stay; only the grey tech line was added.
    $contains($modalsCss, '.rm-toast {', 'The base toast styling must stay in place');
    $contains($modalsCss, 'rm-toast-tech', 'The debug tech line style must exist');
    $contains($ui, '#rm-toast-stack', 'The toast stack position must stay in place');
    $contains($ui, '4800', 'The default toast duration must stay in place');

    // No automatic retry logic was introduced in the alert core.
    foreach (['ui.js' => $ui, 'OperatorAlert.php' => $alertSource, 'ErrorFallback.php' => $fallbackSource] as $name => $source) {
        $assert(
            !preg_match('/retry\s*\(|retryCount|withRetry|maxRetries|backoff/i', $source),
            'Out of scope: ' . $name . ' must not introduce automatic retry logic'
        );
    }
} catch (Throwable $exception) {
    $failures[] = 'Friendly alerts verification contract threw: ' . $exception->getMessage();
} finally {
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    if (is_string($logPath) && is_file($logPath)) {
        @unlink($logPath);
    }
    $setDebug(false);
}

if ($failures) {
    fwrite(STDERR, "Friendly alerts verification contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Friendly alerts verification contract: passed\n";
