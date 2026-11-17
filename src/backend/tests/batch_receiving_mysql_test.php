<?php
declare(strict_types=1);

// Exercise the real SQL/locking dialect; never connect to the configured Store database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_DB_TESTS') !== '1') { echo "Batch receiving MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/SupplierPulloutService.php';
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, (string)$exception . "\n");
    exit(1);
});

$database = 'retailmind_theme_test_' . bin2hex(random_bytes(6));
$fixture = static function (string $action) use ($database): void {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/theme_fixture.php')
        . ' ' . escapeshellarg($action) . ' ' . escapeshellarg($database);
    exec($command, $output, $code);
    if ($code !== 0) throw new RuntimeException("Disposable fixture {$action} failed: " . implode("\n", $output));
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
    $pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status, created_by) VALUES ('Inventory retry test', '2026-01-01', '2099-12-31', 'open', 1)");
    $products = new ProductService($pdo);
    $receiving = new ReceivingService($pdo);
    $multi = $products->createProduct(['category_id' => 1, 'base_unit' => 'bottle', 'cost_price' => '5', 'selling_price' => 20, 'product_name' => 'Independent bottles'], 3);
    $delivery = [['product_id' => $multi, 'current_product_cost' => '7.00', 'allocations' => [
        ['received_qty' => 12, 'batch_number' => 'MAY', 'expiration_date' => '2027-05-31', 'cost_price' => '6'],
        ['received_qty' => 12, 'batch_number' => 'AUG', 'expiration_date' => '2027-08-31', 'cost_price' => '7'],
    ]]];
    $multiResults = $receiving->receiveShoppingTrip($delivery, [], 3);
    $multiHistory = $receiving->getGroupedReceivingHistory(3)[0];
    $assert(count($multiResults) === 2 && $multiHistory['total_accepted_qty'] === 24, 'Two allocations must total 24 exactly once');
    $assert(array_column($multiHistory['lines'], 'batch_number') === ['MAY', 'AUG']
        && array_column($multiHistory['lines'], 'expiration_date') === ['2027-05-31', '2027-08-31'], 'Allocations retain independent provenance and order');
    $savedProduct = $products->findProductBySearchTerm('Independent bottles');
    $assert((float)$savedProduct['cost_price'] === 7.0 && (float)$savedProduct['unit_price'] === 20.0, 'Explicit current cost applies without changing selling price');
    $assert(array_column($multiHistory['lines'], 'current_product_cost') === [7.0, 7.0]
        && array_column($multiHistory['lines'], 'cost_source') === ['explicit', 'explicit'], 'History preserves the chosen cost and allocation cost provenance');
    $receiptCount = $receiving->countGroupedReceivingHistory(3);
    foreach ([
        array_replace($delivery[0], ['current_product_cost' => '']),
        array_replace($delivery[0], ['current_product_cost' => '8']),
        array_replace($delivery[0], ['allocations' => [$delivery[0]['allocations'][0], []]]),
        array_replace($delivery[0], ['allocations' => []]),
        array_replace($delivery[0], ['allocations' => [['quantity_mode' => 'packs', 'received_qty' => 1]]]),
        array_replace($delivery[0], ['allocations' => [['received_qty' => 2147483647], ['received_qty' => 1]]]),
    ] as $invalidGroup) {
        $rejected = false;
        try { $receiving->receiveShoppingTrip([$invalidGroup], [], 3); }
        catch (RuntimeException) { $rejected = true; }
        $assert($rejected && $receiving->countGroupedReceivingHistory(3) === $receiptCount, 'Missing/removed cost choices, empty batches and cumulative overflow must save nothing');
    }
    $assert((float)$products->findProductBySearchTerm('Independent bottles')['cost_price'] === 7.0, 'Rejected cost choices preserve the current product cost');
    $pulloutProduct = $products->createProduct(['category_id' => 1, 'base_unit' => 'piece', 'cost_price' => '0', 'selling_price' => 20,
        'product_name' => 'Split replacement', 'initial_stock_quantity' => 10, 'supplier_pullout_eligible' => 1], 3);
    $pullouts = new SupplierPulloutService($pdo);
    $eligibleBatch = $pullouts->getEligibleBatches($pulloutProduct)[0]['batch_id'];
    $pullout = $pullouts->recordPullout(['product_id' => $pulloutProduct, 'batch_id' => $eligibleBatch, 'quantity' => 4, 'reason' => 'Supplier replacement'], 3);
    $receiving->receiveShoppingTrip([
        ['product_id' => $pulloutProduct, 'pullout_id' => $pullout['pullout_id'], 'allocations' => [['received_qty' => 2], ['received_qty' => 2]]],
        ['product_id' => $multi, 'allocations' => [['received_qty' => 1]]],
    ], [], 3);
    $replacement = $pullouts->getPullout($pullout['pullout_id']);
    $assert((int)$replacement['total_replaced_qty'] === 4 && (int)$replacement['replacement_count'] === 1, 'Two linked batches count as one replacement receipt');
    $assert(array_column($receiving->getGroupedReceivingHistory(3)[0]['lines'], 'pullout_id') === [$pullout['pullout_id'], $pullout['pullout_id'], null], 'Only the matching product allocations inherit the replacement link');
    $pdo->exec('UPDATE stock_receiving SET group_id=NULL WHERE pullout_id=' . (int)$pullout['pullout_id']);
    $legacyReplacement = $pullouts->getPullout($pullout['pullout_id']);
    $assert((int)$legacyReplacement['replacement_count'] === 2, 'Historical ungrouped replacement receipts keep their individual identities');
    $id = $products->createProduct(['category_id' => 1, 'base_unit' => 'piece', 'cost_price' => 0, 'selling_price' => 20, 'product_name' => 'Isolated batch loaf', 'initial_stock_quantity' => 20, 'supplier_pullout_eligible' => 1,
        'expiration_date' => '2099-10-25'], 3);
    $receiving->receiveStock(['product_id' => $id, 'received_qty' => 30, 'expiration_date' => '2099-10-27'], 3);
    $products->purchaseStock(['search_term' => 'Isolated batch loaf', 'purchase_quantity' => 4,
        'expiration_date' => '2099-10-27'], 3);
    $assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$id}")->fetchColumn() === 54, 'On Hand must be 54');
    $assert((int)$pdo->query("SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = {$id}")->fetchColumn() === 54, 'Batches must total 54');
    $assert($pdo->query("SELECT expiration_date FROM product_batches WHERE product_id = {$id} ORDER BY batch_id")->fetchAll(PDO::FETCH_COLUMN)
        === ['2099-10-25', '2099-10-27', '2099-10-27'], 'Opening and separate equal-date receipts must retain dates');
    $assert($pdo->query("SELECT expiration_date FROM products WHERE product_id = {$id}")->fetchColumn() === '2099-10-25', 'Legacy Product date must remain unchanged');

    $retryEntry = ['supplier' => 'Lost response test', 'operation_token' => str_repeat('a', 48)];
    $retryLines = [['product_id' => $id, 'received_qty' => 2]];
    $first = $receiving->receiveShoppingTrip($retryLines, $retryEntry, 3);
    $replayed = $receiving->receiveShoppingTrip($retryLines, $retryEntry, 3);
    $assert(json_encode($first) === json_encode($replayed), 'Lost response retry must return the same saved receipt');
    $assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$id}")->fetchColumn() === 56, 'Retry must add stock only once');
    $changedRejected = false;
    try { $receiving->receiveShoppingTrip([['product_id' => $id, 'received_qty' => 3]], $retryEntry, 3); }
    catch (RuntimeException) { $changedRejected = true; }
    $assert($changedRejected, 'A committed token must not accept edited quantities');

    // Real DATE columns reject empty-string comparisons that SQLite TEXT fixtures accept.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $receiving->receiveStock(['product_id' => $id, 'received_qty' => 2], 3);
    $inventory = new InventoryService($pdo);
    $summaries = array_column($inventory->getProductsWithBatchSummaries('2099-10-20'), null, 'product_id');
    $assert($summaries[$id]['quantity_on_hand'] === 58, 'Inventory landing page must load batch summaries on MySQL');
    $assert($summaries[$id]['undated_quantity'] === 4 && $summaries[$id]['next_expiration'] === '2099-10-25',
        'MySQL summaries must distinguish dated and undated stock');
    $batches = $inventory->getProductBatches($id, '2099-10-20');
    $assert(count($batches) === 5 && array_column($batches, 'expiration_date') === ['2099-10-25', '2099-10-27', '2099-10-27', null, null],
        'View Batches must load on MySQL with dated stock before undated stock');
    $eligible = (new SupplierPulloutService($pdo))->getEligibleBatches($id);
    $assert(count($eligible) === 5 && array_column($eligible, 'expiration_date') === array_column($batches, 'expiration_date'),
        'Supplier pullout batch picker must load on MySQL with dated stock before undated stock');

    $before = [];
    foreach (['products', 'inventory', 'product_batches', 'stock_receiving_groups', 'stock_receiving', 'stock_movements', 'purchase_history'] as $table) {
        $before[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
    }
    $pdo->exec("CREATE TRIGGER fail_batch_receiving BEFORE INSERT ON stock_receiving FOR EACH ROW
        BEGIN IF NEW.batch_number = 'FAIL' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected second allocation failure'; END IF; END");
    $atomicLines = [['product_id' => $id, 'allocations' => [
        ['received_qty' => 2, 'cost_price' => '9', 'batch_number' => 'FIRST', 'expiration_date' => '2099-11-01'],
        ['received_qty' => 3, 'cost_price' => '9.00', 'batch_number' => 'FAIL'],
    ]]];
    $atomicEntry = ['operation_token' => str_repeat('b', 48)];
    $failed = false;
    try {
        $receiving->receiveShoppingTrip($atomicLines, $atomicEntry, 3);
    } catch (RuntimeException $e) {
        $failed = true;
    } finally {
        $pdo->exec('DROP TRIGGER fail_batch_receiving');
    }
    $assert($failed && !$pdo->inTransaction(), 'Injected write failure must roll back');
    foreach ($before as $table => $rows) {
        $assert($pdo->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC) === $rows, "Failed receipt changed {$table}");
    }
    $saved = $receiving->receiveShoppingTrip($atomicLines, $atomicEntry, 3);
    $assert(json_encode($receiving->receiveShoppingTrip($atomicLines, $atomicEntry, 3)) === json_encode($saved), 'Rolled-back multi-batch submissions can retry with the original token');
    $assert($receiving->getGroupedReceivingHistory(3)[0]['total_accepted_qty'] === 5, 'Successful retry commits every allocation exactly once');
    echo "Batch receiving MySQL: passed (opening, receiving, legacy purchase, preserved dates, batch readers, transaction rollback)\n";
} finally {
    $pdo = null;
    $fixture('cleanup');
}
