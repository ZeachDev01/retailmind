<?php

header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie');
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/product_images.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit;
}
if (!in_array(current_role(), ['cashier', 'inventory_manager', 'admin', 'super_admin'], true)
    || (bool)($_SESSION['is_recovery_account'] ?? false)) {
    http_response_code(403);
    exit;
}
$filename = $_GET['file'] ?? '';
$reference = is_string($filename) ? 'storage/images/' . $filename : '';
$image = product_image_file($reference);
if ($image === null) {
    http_response_code(404);
    exit;
}
[$scope, $params] = store_product_scope('p');
$statement = $pdo->prepare("SELECT p.product_id FROM products p WHERE p.product_image = ?{$scope} LIMIT 1");
$statement->execute(array_merge([$reference], $params));
if (!$statement->fetchColumn()) {
    http_response_code(404);
    exit;
}
App\Core\Session::closeWrite();
header('Content-Type: ' . $image['mime']);
header('Content-Length: ' . (string)filesize($image['path']));
header('Content-Disposition: inline; filename="product-image"');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
readfile($image['path']);
