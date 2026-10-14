<?php
// Local sample-data preview only. Never routes to application HTTP handlers.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/src/frontend';
if ($path === '/' || $path === '/components/report/stock_receiving.php') {
    $preview = true;
    require $root . '/components/report/stock_receiving.prototype.php';
    return true;
}
$allowed = ['/assets/css/style.css', '/components/report/stock_receiving.prototype.css', '/components/report/stock_receiving.prototype.js'];
if (in_array($path, $allowed, true)) {
    header('Content-Type: ' . (str_ends_with($path, '.js') ? 'text/javascript' : 'text/css'));
    readfile($root . $path);
    return true;
}
http_response_code(404);
