<?php
// Friendly Operator Alerts (ticket #52): service/page-boundary helper.
//
// Shop mode must show only an easy line (domain message when it is already
// plain English, otherwise the per-operation friendly text). Debug mode may
// append a small tech line. Full exception detail always goes to the log.

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

$logPath = tempnam(sys_get_temp_dir(), 'retailmind-operator-alert-');
$previousLog = ini_get('error_log');
ini_set('error_log', $logPath);

try {
    $friendly = 'The stock report could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening.';

    // 1) Shop mode: technical exception text never reaches the operator.
    $setDebug(false);
    $techException = new RuntimeException('SQLSTATE[HY000] [2002] Connection refused in /var/www/retailmind/src/backend/app/Core/Database.php:44');
    $shown = OperatorAlert::message($techException, $friendly);
    $assert($shown === $friendly, 'Shop mode must show the friendly line for a technical failure, got: ' . $shown);
    $assert(!OperatorAlert::isTechnicalMessage($shown) || $shown === $friendly, 'Shop-mode output must not be raw technical text');
    $assert(!str_contains($shown, 'SQLSTATE'), 'Shop mode must not leak SQLSTATE');
    $assert(!str_contains($shown, 'Database.php'), 'Shop mode must not leak file paths');
    $assert(!str_contains($shown, 'RuntimeException'), 'Shop mode must not leak exception class names');

    // 2) Shop mode: plain domain messages stay (already easy words).
    $domainException = new RuntimeException('Not enough stock for product #5.');
    $domainShown = OperatorAlert::message($domainException, $friendly);
    $assert($domainShown === 'Not enough stock for product #5.', 'Plain domain messages must be kept for the operator, got: ' . $domainShown);

    // 3) Full technical detail is always logged separately from the user text.
    $logged = (string)file_get_contents($logPath);
    $assert(str_contains($logged, 'SQLSTATE[HY000]'), 'The full technical exception must be written to the log');
    $assert(str_contains($logged, 'RuntimeException'), 'The log entry must record the exception class');

    // 4) Debug mode: easy line plus tech line.
    $setDebug(true);
    $debugShown = OperatorAlert::message($techException, $friendly);
    $assert(str_starts_with($debugShown, $friendly), 'Debug mode must still lead with the easy line');
    $assert(str_contains($debugShown, 'SQLSTATE'), 'Debug mode must include the real tech line for developers');
    $assert(str_contains($debugShown, 'RuntimeException'), 'Debug tech line must name the exception class');
    $lines = explode("\n", $debugShown);
    $assert(count($lines) >= 2, 'Debug mode must separate the easy line from the tech line');
    $assert($lines[0] === $friendly, 'The first debug line must remain the easy line');

    // 5) Shop mode after debug: tech line hidden again.
    $setDebug(false);
    $assert(OperatorAlert::message($techException, $friendly) === $friendly, 'Turning debug off must hide the tech line again');

    // 6) Empty message falls back to friendly text.
    $assert(
        OperatorAlert::message(new RuntimeException(''), $friendly) === $friendly,
        'An empty exception message must fall back to the friendly line'
    );

    // 7) isTechnicalMessage detects known leak shapes.
    $assert(OperatorAlert::isTechnicalMessage('SQLSTATE[23000]: Integrity constraint violation'), 'SQLSTATE must be technical');
    $assert(OperatorAlert::isTechnicalMessage('PDOException: connection lost'), 'PDOException must be technical');
    $assert(OperatorAlert::isTechnicalMessage('in /xampp/htdocs/retailmind/src/backend/app.php:12'), 'File paths must be technical');
    $assert(OperatorAlert::isTechnicalMessage('Stack trace:\n#0 /var/www/x.php(1)'), 'Stack traces must be technical');
    $assert(OperatorAlert::isTechnicalMessage('Object of class stdClass could not be converted'), 'Object dumps must be technical');
    $assert(!OperatorAlert::isTechnicalMessage('This cashier already has an open shift.'), 'Plain domain text must not be treated as technical');
    $assert(!OperatorAlert::isTechnicalMessage('Image file must not exceed 5MB.'), 'Plain validation text must not be treated as technical');
    $assert(OperatorAlert::isTechnicalMessage(''), 'An empty message must be treated as technical');

    // 8) Generic fallback constant stays in plain Operator Alert language.
    $assert(
        str_contains(OperatorAlert::GENERIC_FALLBACK, 'Try again') || str_contains(OperatorAlert::GENERIC_FALLBACK, 'try again'),
        'Generic fallback must tell the operator to try again'
    );
    $assert(
        str_contains(OperatorAlert::GENERIC_FALLBACK, 'Administrator'),
        'Generic fallback must say who to tell when it repeats'
    );
    $assert(!OperatorAlert::isTechnicalMessage(OperatorAlert::GENERIC_FALLBACK), 'Generic fallback must stay free of technical words');
} catch (Throwable $exception) {
    $failures[] = 'Operator alert helper test threw: ' . $exception->getMessage();
} finally {
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    if (is_string($logPath) && is_file($logPath)) {
        @unlink($logPath);
    }
    $setDebug(false);
}

if ($failures) {
    fwrite(STDERR, "Operator alert helper tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Operator alert helper tests: passed\n";
