<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Creation boundary: rejected requests must never reach storage.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE categories (category_id INTEGER PRIMARY KEY); INSERT INTO categories VALUES (1)');
$service = new ProductService($pdo);
$valid = ['product_name' => 'Sample', 'category_id' => 1, 'base_unit' => 'piece', 'cost_price' => '0', 'selling_price' => '5'];
foreach ([
    ['product_name', '', 'Product name'], ['category_id', '', 'Category'],
    ['category_id', 99, 'Category'], ['base_unit', '', 'Base unit'],
    ['cost_price', '', 'Cost price'], ['cost_price', null, 'Cost price'],
    ['selling_price', '', 'Selling price'], ['selling_price', '0', 'Selling price'],
    ['units_per_package', '0', 'Items per Receiving Package'],
    ['units_per_package', '1.5', 'Items per Receiving Package'],
    ['selling_price', 'NaN', 'Selling price'], ['selling_price', '-1', 'Selling price'],
] as [$field, $value, $message]) {
    try { $service->createProduct(array_replace($valid, [$field => $value]), 1); throw new LogicException("Accepted invalid $field"); }
    catch (RuntimeException|InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), $message)) throw $e;
    }
}
foreach (['initial_stock_quantity', 'starting_packs', 'starting_loose'] as $field) {
    try { $service->createProduct($valid + ['creation_origin' => 'receiving', $field => '1'], 1); throw new LogicException("Accepted receiving $field"); }
    catch (RuntimeException $e) { if (!str_contains($e->getMessage(), 'Receiving')) throw $e; }
}
echo "Product creation boundary: passed\n";
