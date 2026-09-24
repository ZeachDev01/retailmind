<?php
// config/db.php
// Backward-compatible PDO connection for legacy page scripts.

require_once __DIR__ . '/../bootstrap/app.php';

try {
    $pdo = App\Core\Database::connection();
} catch (RuntimeException $exception) {
    error_log((string)$exception);
    $debug = App\Support\OperatorAlert::isDebug();
    $easyLine = App\Support\ErrorFallback::DB_EASY_LINE;

    if (PHP_SAPI === 'cli') {
        die($easyLine . ($debug ? "\n" . App\Support\ErrorFallback::techDetail($exception) : ''));
    }

    http_response_code(500);
    if (App\Support\ErrorFallback::expectsJson()) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(App\Support\ErrorFallback::json($exception, $debug, $easyLine));
    } else {
        echo App\Support\ErrorFallback::page($exception, $debug, $easyLine);
    }
    die;
}
