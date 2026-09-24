<?php
// config/db.php
// Backward-compatible PDO connection for legacy page scripts.

require_once __DIR__ . '/../bootstrap/app.php';

try {
    $pdo = App\Core\Database::connection();
} catch (RuntimeException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    $easyLine = 'RetailMind cannot reach its data right now. Please try again shortly. Tell your Super Administrator if this continues.';
    if (!empty($GLOBALS['app']['debug'])) {
        die($easyLine . "\n" . App\Support\OperatorAlert::techDetail($exception));
    }
    die($easyLine);
}
