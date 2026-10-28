<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

// Exercise the report's public result without starting its authenticated page.
$source = file_get_contents(__DIR__ . '/../../frontend/components/report/report_generation.php');
$start = strpos($source, 'function normalize_date(');
eval(substr($source, $start, strpos($source, '$selectedReport =', $start) - $start));

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE products (product_id INTEGER, branch_id INTEGER, sku TEXT, barcode TEXT, product_name TEXT, category_id INTEGER, cost_price REAL, unit_price REAL, reorder_level INTEGER)');
$pdo->exec('CREATE TABLE categories (category_id INTEGER, category_name TEXT)');
$pdo->exec('CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER)');
$pdo->exec("INSERT INTO products VALUES (1, 1, 'A', NULL, 'Gain', NULL, 80, 100, 0), (2, 1, 'B', NULL, 'Loss', NULL, 120, 100, 0), (3, 1, 'C', NULL, 'Equal', NULL, 100, 100, 0), (4, 1, 'D', NULL, 'Unknown cost', NULL, NULL, 100, 0)");
$result = run_report($pdo, 'inventory_valuation', [
    'store_id' => 1, 'product_id' => 0, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-21',
]);
if (array_slice($result['headers'], array_search('Cost Price', $result['headers'], true), 3) !== ['Cost Price', 'Retail Price', 'Price minus cost per item']) {
    throw new RuntimeException('Inventory valuation must export cost, sale, then price minus cost per item');
}
$profits = array_column($result['rows'], 'Price minus cost per item', 'Product');
foreach (['Gain' => '20.00', 'Loss' => '-20.00', 'Equal' => '0.00', 'Unknown cost' => '100.00'] as $product => $profit) {
    if (($profits[$product] ?? null) !== $profit) {
        throw new RuntimeException("Incorrect exported price minus cost per item for {$product}");
    }
}
echo "Inventory profit export: passed\n";
