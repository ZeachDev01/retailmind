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
    $products = new ProductService($pdo);
    $receiving = new ReceivingService($pdo);
    $id = $products->createProduct(['product_name' => 'Isolated batch loaf', 'initial_stock_quantity' => 20,
        'expiration_date' => '2099-10-25'], 3);
    $receiving->receiveStock(['product_id' => $id, 'received_qty' => 30, 'expiration_date' => '2099-10-27'], 3);
    $products->purchaseStock(['search_term' => 'Isolated batch loaf', 'purchase_quantity' => 4,
        'expiration_date' => '2099-10-27'], 3);
    $assert((int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$id}")->fetchColumn() === 54, 'On Hand must be 54');
    $assert((int)$pdo->query("SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = {$id}")->fetchColumn() === 54, 'Batches must total 54');
    $assert($pdo->query("SELECT expiration_date FROM product_batches WHERE product_id = {$id} ORDER BY batch_id")->fetchAll(PDO::FETCH_COLUMN)
        === ['2099-10-25', '2099-10-27', '2099-10-27'], 'Opening and separate equal-date receipts must retain dates');
    $assert($pdo->query("SELECT expiration_date FROM products WHERE product_id = {$id}")->fetchColumn() === '2099-10-25', 'Legacy Product date must remain unchanged');

    // Real DATE columns reject empty-string comparisons that SQLite TEXT fixtures accept.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $receiving->receiveStock(['product_id' => $id, 'received_qty' => 2], 3);
    $inventory = new InventoryService($pdo);
    $summaries = array_column($inventory->getProductsWithBatchSummaries('2099-10-20'), null, 'product_id');
    $assert($summaries[$id]['quantity_on_hand'] === 56, 'Inventory landing page must load batch summaries on MySQL');
    $assert($summaries[$id]['undated_quantity'] === 2 && $summaries[$id]['next_expiration'] === '2099-10-25',
        'MySQL summaries must distinguish dated and undated stock');
    $batches = $inventory->getProductBatches($id, '2099-10-20');
    $assert(count($batches) === 4 && array_column($batches, 'expiration_date') === ['2099-10-25', '2099-10-27', '2099-10-27', null],
        'View Batches must load on MySQL with dated stock before undated stock');
    $eligible = (new SupplierPulloutService($pdo))->getEligibleBatches($id);
    $assert(count($eligible) === 4 && array_column($eligible, 'expiration_date') === array_column($batches, 'expiration_date'),
        'Supplier pullout batch picker must load on MySQL with dated stock before undated stock');

    $before = [];
    foreach (['products', 'inventory', 'product_batches', 'stock_receiving_groups', 'stock_receiving', 'stock_movements', 'purchase_history'] as $table) {
        $before[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
    }
    $pdo->exec("CREATE TRIGGER fail_batch_receiving BEFORE INSERT ON stock_movements FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected receiving movement failure'");
    $failed = false;
    try {
        $receiving->receiveStock(['product_id' => $id, 'received_qty' => 3, 'expiration_date' => '2099-11-01'], 3);
    } catch (RuntimeException $e) {
        $failed = true;
    } finally {
        $pdo->exec('DROP TRIGGER fail_batch_receiving');
    }
    $assert($failed && !$pdo->inTransaction(), 'Injected write failure must roll back');
    foreach ($before as $table => $rows) {
        $assert($pdo->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC) === $rows, "Failed receipt changed {$table}");
    }
    echo "Batch receiving MySQL: passed (opening, receiving, legacy purchase, preserved dates, batch readers, transaction rollback)\n";
} finally {
    $pdo = null;
    $fixture('cleanup');
}
