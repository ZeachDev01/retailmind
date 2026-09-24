<?php
// Friendly alerts 02 (ticket #54): global fallback + database connection failure.
//
// Behavior-level contract for the unhandled-failure presentation: the normal
// page fallback shows the aligned easy line with the red error kind and no
// technical leak in shop mode, the JSON fallback for scanner and API routes
// keeps the aligned easy message, both gate the grey tech line on the debug
// flag, and a real database connection failure logs full detail server-side
// while throwing only the easy line at the operator.

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

$leakNeedles = ['SQLSTATE', 'RuntimeException', 'PDOException', '/var/www', 'Stack trace', 'Object of class', '.php:'];

$logPath = tempnam(sys_get_temp_dir(), 'retailmind-operator-fallback-');
$previousLog = ini_get('error_log');
ini_set('error_log', $logPath);

try {
    $nasty = new RuntimeException(
        'SQLSTATE[HY000] [2002] Connection refused in /var/www/retailmind/src/backend/app/Core/Database.php:44'
    );

    // --- AC1: normal-page fallback, shop mode ---
    $setDebug(false);
    $html = ErrorFallback::page($nasty, false);
    $contains($html, OperatorAlert::GENERIC_FALLBACK, 'Page fallback must show the aligned easy line');
    $contains($html, ErrorFallback::TITLE, 'Page fallback must carry the red error kind title');
    $contains($html, 'data-kind="error"', 'Page fallback must declare the red error kind');
    $contains($html, '#dc2626', 'Page fallback must render the red error accent');
    foreach ($leakNeedles as $needle) {
        $assert(
            !str_contains($html, $needle),
            'Shop-mode page fallback must not leak ' . $needle . ', got: ' . $html
        );
    }

    // --- AC4: page fallback, debug on ---
    $htmlDebug = ErrorFallback::page($nasty, true);
    $contains($htmlDebug, OperatorAlert::GENERIC_FALLBACK, 'Debug page fallback must still lead with the easy line');
    $contains($htmlDebug, 'RuntimeException: SQLSTATE', 'Debug page fallback must show the tech line');
    $contains($htmlDebug, '#9ca3af', 'Debug page fallback must render the tech line in grey');
    $contains($htmlDebug, ErrorFallback::TITLE, 'Debug page fallback must keep the red error kind');

    // --- AC2: JSON scanner and API fallback, shop mode ---
    $payload = ErrorFallback::json($nasty, false);
    $assert(($payload['success'] ?? true) === false, 'JSON fallback must report success false');
    $message = (string)($payload['message'] ?? '');
    $assert($message === ErrorFallback::JSON_MESSAGE, 'JSON fallback must use the aligned easy message, got: ' . $message);
    $contains($message, 'Please try again', 'JSON easy message must tell the operator to retry');
    $contains($message, 'Tell your Administrator', 'JSON easy message must say who to tell when it repeats');
    $assert(!array_key_exists('tech', $payload), 'Shop-mode JSON fallback must not carry a tech line');
    foreach ($leakNeedles as $needle) {
        $assert(
            !str_contains($message, $needle),
            'Shop-mode JSON fallback must not leak ' . $needle . ', got: ' . $message
        );
    }

    // --- AC4: JSON fallback, debug on ---
    $setDebug(true);
    $payloadDebug = ErrorFallback::json($nasty, true);
    $assert(
        (string)($payloadDebug['message'] ?? '') === ErrorFallback::JSON_MESSAGE,
        'Debug JSON fallback must still lead with the easy message'
    );
    $contains((string)($payloadDebug['tech'] ?? ''), 'SQLSTATE', 'Debug JSON fallback must surface the tech line');
    $contains((string)($payloadDebug['tech'] ?? ''), 'RuntimeException', 'Debug JSON tech line must name the exception class');

    // --- Shop mode after debug: tech hidden again ---
    $setDebug(false);
    $assert(
        !array_key_exists('tech', ErrorFallback::json($nasty, false)),
        'Turning debug off must hide the JSON tech line again'
    );
    $assert(
        !str_contains(ErrorFallback::page($nasty, false), '#9ca3af'),
        'Turning debug off must hide the page tech line again'
    );

    // --- AC3: database connection failure wording through the same renderer ---
    $dbShop = ErrorFallback::page($nasty, false, ErrorFallback::DB_EASY_LINE);
    $contains($dbShop, ErrorFallback::DB_EASY_LINE, 'DB shop mode must show only the database easy line');
    foreach ($leakNeedles as $needle) {
        $assert(
            !str_contains($dbShop, $needle),
            'DB shop mode must not leak ' . $needle . ', got: ' . $dbShop
        );
    }
    $dbDebug = ErrorFallback::page($nasty, true, ErrorFallback::DB_EASY_LINE);
    $contains($dbDebug, ErrorFallback::DB_EASY_LINE, 'DB debug mode must still lead with the easy line');
    $contains($dbDebug, '#9ca3af', 'DB debug mode must render the grey tech line');

    // --- JSON detection seam for scanner and API routes ---
    $assert(
        ErrorFallback::expectsJson(['HTTP_ACCEPT' => 'application/json', 'REQUEST_URI' => '/components/cashier/pos.php']),
        'An application/json Accept header must be treated as JSON'
    );
    $assert(
        ErrorFallback::expectsJson(['REQUEST_URI' => '/components/barcodeScanner/apiScanner/barcode.php']),
        'Scanner routes must be treated as JSON'
    );
    $assert(
        ErrorFallback::expectsJson(['REQUEST_URI' => '/api/invoice/receipt.php']),
        'API routes must be treated as JSON'
    );
    $assert(
        !ErrorFallback::expectsJson(['HTTP_ACCEPT' => 'text/html', 'REQUEST_URI' => '/components/cashier/pos.php']),
        'Normal pages must not be treated as JSON'
    );

    // --- Real database connection failure: full log, easy exception ---
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
        $assert(
            ErrorFallback::techDetail($caught) !== '',
            'Debug tech detail must be available for the connection failure'
        );
        $contains(
            ErrorFallback::techDetail($caught),
            'PDOException',
            'Debug tech detail must reach the root PDOException'
        );
    }

    $logged = (string)file_get_contents($logPath);
    $contains($logged, 'SQLSTATE', 'The full PDO detail must be written to the server log');
    $contains($logged, 'PDOException', 'The log entry must record the original exception class');
    $contains($logged, 'Database connection failed', 'The log entry must mark the connection failure');

    // --- Wiring: db.php routes both surfaces through the fallback renderer ---
    $root = dirname(__DIR__, 3);
    $dbSource = (string)file_get_contents($root . '/src/backend/config/db.php');
    $bootstrapSource = (string)file_get_contents($root . '/src/backend/bootstrap/app.php');
    $contains($dbSource, 'ErrorFallback::page', 'DB failure must render through the shared page fallback');
    $contains($dbSource, 'ErrorFallback::json', 'DB failure must answer scanner routes through the JSON fallback');
    $contains($dbSource, 'error_log', 'DB failure must keep logging full detail server-side');
    $assert(
        !str_contains($dbSource, "die('Database connection failed."),
        'DB failure must not die() with the old hard-coded line'
    );
    $contains($bootstrapSource, 'ErrorFallback::page', 'Global handler must render through the shared page fallback');
    $contains($bootstrapSource, 'ErrorFallback::json', 'Global handler must answer scanner routes through the JSON fallback');
    $contains($bootstrapSource, 'error_log', 'Global handler must keep logging full detail server-side');
} catch (Throwable $exception) {
    $failures[] = 'Operator alert fallback contract threw: ' . $exception->getMessage();
} finally {
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    if (is_string($logPath) && is_file($logPath)) {
        @unlink($logPath);
    }
    $setDebug(false);
}

if ($failures) {
    fwrite(STDERR, "Operator alert fallback contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Operator alert fallback contract: passed\n";
