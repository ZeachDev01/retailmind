<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_DB_TESTS') !== '1') { echo "Known receiving costs: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e . "\n"); exit(1); });

$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$fixture = static function (string $action) use ($database): void {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/theme_fixture.php')
        . ' ' . escapeshellarg($action) . ' ' . escapeshellarg($database), $output, $code);
    if ($code !== 0) throw new RuntimeException(implode("\n", $output));
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
try {
    $fixture('create');
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $_SESSION = ['user_id' => 3, 'role' => 'inventory_manager'];
    $fixture('open_fiscal_period');
    $products = new ProductService($pdo);
    $receiving = new ReceivingService($pdo);
    $id = $products->createProduct(['category_id' => 1, 'base_unit' => 'piece', 'selling_price' => 20, 'product_name' => 'Free sample', 'cost_price' => '0', 'initial_stock_quantity' => 1], 3);
    $receiving->receiveStock(['product_id' => $id, 'received_qty' => 2], 3);
    $history = $receiving->getGroupedReceivingHistory(3);
    $assert($history[0]['lines'][0]['cost_price'] === 0.0, 'Blank receipt must inherit explicitly recorded product zero');
    $missing = $products->createProduct(['category_id' => 1, 'base_unit' => 'piece', 'cost_price' => 0, 'selling_price' => 20, 'product_name' => 'Missing sample', 'initial_stock_quantity' => 1], 3);
    $legacy = $products->createProduct(['category_id' => 1, 'base_unit' => 'piece', 'cost_price' => 0, 'selling_price' => 20, 'product_name' => 'Legacy sample'], 3);
    $positive = $products->createProduct(['category_id' => 1, 'base_unit' => 'piece', 'selling_price' => 20, 'product_name' => 'Paid sample', 'cost_price' => '5'], 3);
    $pdo->exec("UPDATE products SET cost_known=0 WHERE product_id IN ($missing, $legacy)");
    $pdo->exec("UPDATE product_batches SET cost_price=NULL WHERE product_id=$missing");
    $receiving->receiveShoppingTrip([
        ['product_id' => $missing, 'received_qty' => 2],
        ['product_id' => $legacy, 'received_qty' => 2],
        ['product_id' => $positive, 'received_qty' => 3],
        ['product_id' => $id, 'received_qty' => 1],
    ], [], 3);
    $lines = $receiving->getGroupedReceivingHistory(3)[0]['lines'];
    $assert(array_column($lines, 'cost_price') === [null, null, 5.0, 0.0], 'Missing, legacy, positive and known zero must stay distinct');
    $assert(array_column($lines, 'total_cost') === [null, null, 15.0, 0.0], 'Partial totals must preserve missing lines alongside known cost');
    $products->updateProductCatalogDetails($legacy, ['product_name' => 'Legacy sample', 'cost_price' => ''], 3);
    $assert(!\App\Inventory\ProductCost::known($products->findProductBySearchTerm('Legacy sample')), 'Blank edit must preserve unknown provenance');
    $products->updateProductCatalogDetails($legacy, ['product_name' => 'Legacy sample', 'cost_price' => '0'], 3);
    $assert(\App\Inventory\ProductCost::known($products->findProductBySearchTerm('Legacy sample')), 'Explicit zero edit must be known');
    $plan = (new \App\Services\BatchAllocationEngine($pdo))->planCombinedDemand($missing, 'Missing sample', 0, 3, null, 20, false, 3);
    $assert(array_unique(array_column($plan['piece_batch_plan'], 'cost_status')) === ['missing'], 'Missing receipt and opening batches must not acquire a later product fallback: ' . json_encode($plan['piece_batch_plan']));
    $receiving->receiveStock(['product_id' => $missing, 'received_qty' => 1, 'cost_price' => '0'], 3);
    $assert(\App\Inventory\ProductCost::known($products->findProductBySearchTerm('Missing sample')), 'Explicit receiving zero becomes a known fallback');
    $before = $receiving->countGroupedReceivingHistory(3);
    try {
        $receiving->receiveStock(['product_id' => $positive, 'received_qty' => 1, 'cost_fallback' => '0.00'], 3);
        throw new LogicException('Stale Review must reject changed fallback costs');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'Saved product cost changed'), 'Stale Review must explain how to recover');
    }
    $assert($receiving->countGroupedReceivingHistory(3) === $before, 'Stale Review must save nothing');
    $pdo->exec("UPDATE products SET units_per_package=24 WHERE product_id=$positive");
    try {
        $receiving->receiveStock(['product_id' => $positive, 'quantity_mode' => 'packages', 'received_packages' => 2, 'review_conversion' => '12'], 3);
        throw new LogicException('Stale package conversion must reject saving');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'conversion changed'), 'Stale conversion must request another Review');
    }
    $assert($receiving->countGroupedReceivingHistory(3) === $before, 'Changed conversion must save nothing');
    $zeroPlan = (new \App\Services\BatchAllocationEngine($pdo))->planCombinedDemand($id, 'Free sample', 0, 1, null, 10, false, 3);
    $assert($zeroPlan['piece_batch_plan'][0]['unit_cost'] === 0.0 && $zeroPlan['piece_batch_plan'][0]['cost_status'] === 'recorded', 'Free goods retain recorded zero in allocations');
    $products->updateProductCatalogDetails($missing, ['product_name' => 'Missing sample', 'cost_price' => '20'], 3);
    $source = file_get_contents(__DIR__ . '/../../frontend/components/report/report_generation.php');
    $start = strpos($source, 'function normalize_date(');
    eval(substr($source, $start, strpos($source, '$selectedReport =', $start) - $start));
    $report = run_report($pdo, 'inventory_valuation', ['product_id' => $missing, 'category_id' => 0, 'from' => '2026-01-01', 'to' => '2026-12-31']);
    $row = $report['rows'][0];
    $assert($row['Cost Missing Qty'] === 3 && $row['Estimated Value'] === '0.00' && $row['Recorded Value'] === '0.00', 'Valuation keeps unknown quantities distinct from recorded free goods after a later catalog edit: ' . json_encode($row));
    $unknown = $products->createProduct(['category_id' => 1, 'base_unit' => 'piece', 'selling_price' => 20, 'product_name' => 'Unknown allocations', 'cost_price' => '0'], 3);
    $pdo->exec("UPDATE products SET cost_known=0 WHERE product_id=$unknown");
    $receiving->receiveShoppingTrip([['product_id' => $unknown, 'allocations' => [['received_qty' => 2], ['received_qty' => 3]]]], [], 3);
    $unknownHistory = $receiving->getGroupedReceivingHistory(3)[0]['lines'];
    $assert(array_column($unknownHistory, 'cost_price') === [null, null]
        && array_column($unknownHistory, 'cost_source') === ['unknown', 'unknown'], 'All-unknown allocations stay unknown in history');
    $assert(!\App\Inventory\ProductCost::known($products->findProductBySearchTerm('Unknown allocations')), 'All-unknown delivery cannot turn missing cost into free goods');
    $receiving->receiveShoppingTrip([['product_id' => $unknown, 'allocations' => [
        ['received_qty' => 1, 'cost_price' => '0'], ['received_qty' => 2], ['received_qty' => 1, 'cost_price' => '0.00'],
    ]]], [], 3);
    $mixed = $receiving->getGroupedReceivingHistory(3)[0]['lines'];
    $assert(array_column($mixed, 'cost_price') === [0.0, null, 0.0]
        && array_column($mixed, 'current_product_cost') === [0.0, 0.0, 0.0], 'Deduplicated known zero applies automatically while a sibling remains unknown');
    $receiving->receiveShoppingTrip([['product_id' => $positive, 'current_product_cost' => '6', 'allocations' => [
        ['received_qty' => 1, 'cost_price' => '6'], ['received_qty' => 1],
    ]]], [], 3);
    $assert(array_column($receiving->getGroupedReceivingHistory(3)[0]['lines'], 'cost_price') === [6.0, 5.0], 'Blank sibling inherits the product cost before the delivery, not the previous allocation');
    echo "Known receiving costs: passed\n";
} finally {
    $pdo = null;
    $fixture('cleanup');
}
