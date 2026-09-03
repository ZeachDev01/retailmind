<?php
// config/db.php
// Backward-compatible PDO connection for legacy page scripts.

require_once __DIR__ . '/../bootstrap/app.php';

try {
    $pdo = App\Core\Database::connection();
} catch (RuntimeException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    die('Database connection failed. Please contact the system administrator.');
}
