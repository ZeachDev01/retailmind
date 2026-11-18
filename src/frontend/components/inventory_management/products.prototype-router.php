<?php
// Fixture-only router. The production Product page and its handlers never run here.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$files = [
    '/' => ['products.prototype.html', 'text/html; charset=utf-8'],
    '/components/inventory_management/products.php' => ['products.prototype.html', 'text/html; charset=utf-8'],
    '/products.prototype.css' => ['products.prototype.css', 'text/css; charset=utf-8'],
    '/products.prototype.js' => ['products.prototype.js', 'text/javascript; charset=utf-8'],
    '/products.prototype-tuna.jpg' => ['products.prototype-tuna.jpg', 'image/jpeg'],
];
if (str_starts_with($path, '/assets/vendor/bootstrap-icons/')
    && in_array(basename($path), ['bootstrap-icons.min.css', 'bootstrap-icons.woff2', 'bootstrap-icons.woff'], true)) {
    return false;
}
if (!isset($files[$path])) {
    http_response_code(404);
    return true;
}
[$filename, $type] = $files[$path];
header('Content-Type: ' . $type);
header('Cache-Control: no-store');
readfile(__DIR__ . '/' . $filename);
return true;
