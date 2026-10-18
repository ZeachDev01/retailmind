<?php
declare(strict_types=1);

// Exercise the real SQL/locking dialect; never connect to the configured Store database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_DB_TESTS') !== '1') { echo "Batch receiving MySQL: skipped (RUN_DB_TESTS=1 required)\n"; exit; }
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';

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
    echo "Batch receiving MySQL: passed (opening, receiving, legacy purchase, preserved dates, transaction rollback)\n";
} finally {
    $pdo = null;
    $fixture('cleanup');
}
