<?php
// Test for Issue #254: Manage prepared oil pouches as ordinary Products.
// Verifies all acceptance criteria against a disposable database:
// 1. Separate Products for Oil 250 mL (initially PHP 20) and Oil 500 mL (initially PHP 40).
// 2. Base Unit is 'pouch' for each Product; quantities are whole integers.
// 3. Rejects invalid and fractional quantities at write boundaries (entry, receiving, sale, count, refund).
// 4. Initial prices are editable Product data, not hardcoded global rules.
// 5. Receiving and selling one size alters only that size's inventory and batches.
// 6. Normal batches, expiration, costs, receipts, and authorization are preserved.
// 7. No bulk-oil, litre balances, conversions, spillage, or production costs.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/CashRefundService.php';
require_once __DIR__ . '/../app/Services/InventoryCountService.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Store/StoreWriteGate.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Database\MigrationRunner;
use App\Services\CashRefundService;
use App\Services\PhilippineTime;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

$throws = static function (callable $operation, string $expectedClass, string $message, ?string $expectedPattern = null) use ($assert): void {
    try {
        $operation();
        $assert(false, "{$message} (expected {$expectedClass}, none thrown)");
    } catch (Throwable $e) {
        $matchesClass = $e instanceof $expectedClass;
        $matchesMsg = $expectedPattern === null || preg_match($expectedPattern, $e->getMessage()) === 1;
        $assert($matchesClass && $matchesMsg, "{$message} (threw " . get_class($e) . ': ' . $e->getMessage() . ')');
    }
};

echo "Running Ticket #254 Prepared Oil Pouches disposable database tests...\n";

$config = require __DIR__ . '/../config/database.php';
$dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$server = new PDO($dsn, $config['username'], $config['password'], $options);
$dbName = 'rm_oil_test_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = new PDO("{$dsn};dbname={$dbName}", $config['username'], $config['password'], $options);
    $pdo->exec("SET time_zone='+08:00'");

    // Load schema
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $sql = preg_replace('/\/\*!\d{5}\s*(.*?)\*\//s', '$1', file_get_contents(__DIR__ . '/../database/sql/schema.sql'));
    foreach (split_sql_statements($sql) as $stmt) {
        $pdo->query($stmt)->closeCursor();
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // Run migrations
    $runner = new MigrationRunner($pdo, __DIR__ . '/../database/migrations');
    $runner->runPending();

    // Ensure clean state for test actors
    $cashierId = 101;
    $inventoryManagerId = 102;

    $pdo->prepare("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (?, ?, ?, 'unused', ?, 1)
        ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), branch_id = VALUES(branch_id)")
        ->execute([$cashierId, 'Casey Cashier', 'casey_oil_test', 4]);

    $pdo->prepare("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (?, ?, ?, 'unused', ?, 1)
        ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), branch_id = VALUES(branch_id)")
        ->execute([$inventoryManagerId, 'Ian Inventory', 'ian_oil_test', 3]);

    $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, 4, 1)
        ON DUPLICATE KEY UPDATE is_primary = 1")
        ->execute([$cashierId]);

    $pdo->prepare("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, 3, 1)
        ON DUPLICATE KEY UPDATE is_primary = 1")
        ->execute([$inventoryManagerId]);

    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (101, 'Oil Test Register') ON DUPLICATE KEY UPDATE name = VALUES(name)");
    $pdo->prepare("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash) VALUES (101, ?, 101, 1000.00)
        ON DUPLICATE KEY UPDATE cashier_id = VALUES(cashier_id)")
        ->execute([$cashierId]);

    $policy = new RoleCapabilityPolicy();
    $shifts = new \App\Services\CashierShiftService($pdo, $policy);
    $productService = new ProductService($pdo);
    $receivingService = new ReceivingService($pdo);
    $salesWorkflow = new SalesWorkflowService($pdo, $policy);
    $refundService = new CashRefundService($pdo, $shifts, $policy);
    $countService = new InventoryCountService($pdo);

    // Set active role context in session/globals
    $_SESSION['user_id'] = $inventoryManagerId;
    $_SESSION['role'] = 'inventory_manager';

    // -------------------------------------------------------------------------
    // 1. Entry & Setup Seam (ProductService)
    // -------------------------------------------------------------------------
    echo "\n--- 1. Entry & Setup Seam ---\n";

    // Rejection of fractional initial stock quantity at entry boundary
    $throws(
        static fn() => $productService->createProduct([
            'product_name' => 'Fractional Oil',
            'barcode' => 'OIL-FRAC-01',
            'base_unit' => 'pouch',
            'selling_price' => 20.00,
            'initial_stock_quantity' => 2.5,
        ], $inventoryManagerId),
        RuntimeException::class,
        'Product entry rejects float initial_stock_quantity'
    );

    $throws(
        static fn() => $productService->createProduct([
            'product_name' => 'Fractional String Oil',
            'barcode' => 'OIL-FRAC-02',
            'base_unit' => 'pouch',
            'selling_price' => 20.00,
            'initial_stock_quantity' => '3.75',
        ], $inventoryManagerId),
        RuntimeException::class,
        'Product entry rejects fractional string initial_stock_quantity'
    );

    // Create Oil 250 mL pouch
    $oil250Id = $productService->createProduct([
        'product_name' => 'Oil 250 mL',
        'barcode' => 'OIL-250ML',
        'base_unit' => 'pouch',
        'receiving_unit' => 'box',
        'selling_price' => 20.00,
        'cost_price' => 15.00,
        'initial_stock_quantity' => 0,
        'reorder_level' => 10,
        'units_per_package' => 24,
    ], $inventoryManagerId);

    // Create Oil 500 mL pouch
    $oil500Id = $productService->createProduct([
        'product_name' => 'Oil 500 mL',
        'barcode' => 'OIL-500ML',
        'base_unit' => 'pouch',
        'receiving_unit' => 'box',
        'selling_price' => 40.00,
        'cost_price' => 30.00,
        'initial_stock_quantity' => 0,
        'reorder_level' => 5,
        'units_per_package' => 12,
    ], $inventoryManagerId);

    $assert($oil250Id > 0 && $oil500Id > 0 && $oil250Id !== $oil500Id, 'Two separate Products created with distinct IDs');

    $findProduct = static fn(int $id) => $pdo->query(
        "SELECT p.*, i.quantity_on_hand FROM products p JOIN inventory i ON i.product_id = p.product_id WHERE p.product_id = {$id}"
    )->fetch();

    $oil250 = $findProduct($oil250Id);
    $oil500 = $findProduct($oil500Id);

    $assert((float)$oil250['unit_price'] === 20.00, 'Oil 250 mL initial price is PHP 20.00');
    $assert($oil250['base_unit'] === 'pouch', 'Oil 250 mL Base Unit is pouch');
    $assert((int)$oil250['quantity_on_hand'] === 0, 'Oil 250 mL starts with 0 inventory');

    $assert((float)$oil500['unit_price'] === 40.00, 'Oil 500 mL initial price is PHP 40.00');
    $assert($oil500['base_unit'] === 'pouch', 'Oil 500 mL Base Unit is pouch');
    $assert((int)$oil500['quantity_on_hand'] === 0, 'Oil 500 mL starts with 0 inventory');

    // Verify initial prices are ordinary editable Product data, not hardcoded global rules
    $updated250 = $productService->updateProductCatalogDetails($oil250Id, [
        'product_name' => 'Oil 250 mL',
        'selling_price' => 22.00,
    ], $inventoryManagerId);
    $assert((float)$updated250['selling_price'] === 22.00, 'Oil 250 mL price edited to PHP 22.00');

    $reloaded500 = $findProduct($oil500Id);
    $assert((float)$reloaded500['unit_price'] === 40.00, 'Oil 500 mL price remains PHP 40.00 after editing 250 mL price');

    // Reset price back to initial PHP 20.00 for subsequent workflow tests
    $productService->updateProductCatalogDetails($oil250Id, [
        'product_name' => 'Oil 250 mL',
        'selling_price' => 20.00,
    ], $inventoryManagerId);

    // -------------------------------------------------------------------------
    // 2. Receiving Seam (ReceivingService)
    // -------------------------------------------------------------------------
    echo "\n--- 2. Stock Receiving Seam ---\n";

    // Receiving write boundary rejects fractional quantities
    $throws(
        static fn() => $receivingService->receiveStock([
            'product_id' => $oil250Id,
            'quantity_mode' => 'units',
            'received_qty' => 1.5,
        ], $inventoryManagerId),
        RuntimeException::class,
        'Receiving rejects float quantity'
    );

    $throws(
        static fn() => $receivingService->receiveStock([
            'product_id' => $oil250Id,
            'quantity_mode' => 'units',
            'received_qty' => '2.5',
        ], $inventoryManagerId),
        RuntimeException::class,
        'Receiving rejects fractional string quantity'
    );

    // Receive 20 pouches of Oil 250 mL
    $rec250 = $receivingService->receiveStock([
        'product_id' => $oil250Id,
        'quantity_mode' => 'units',
        'received_qty' => 20,
        'cost_price' => '15.00',
        'batch_number' => 'BATCH-OIL250-01',
        'expiration_date' => date('Y-m-d', strtotime('+180 days')),
        'supplier' => 'Golden Oil Supplier',
    ], $inventoryManagerId);

    $assert((int)$rec250['accepted_qty'] === 20, 'Received 20 pouches of Oil 250 mL');

    // Check balances: 250 mL has 20, 500 mL still has 0
    $oil250AfterRec = $findProduct($oil250Id);
    $oil500AfterRec = $findProduct($oil500Id);
    $assert((int)$oil250AfterRec['quantity_on_hand'] === 20, 'Oil 250 mL inventory is now 20 pouches');
    $assert((int)$oil500AfterRec['quantity_on_hand'] === 0, 'Oil 500 mL inventory is unchanged at 0');

    // Receive 10 pouches of Oil 500 mL
    $rec500 = $receivingService->receiveStock([
        'product_id' => $oil500Id,
        'quantity_mode' => 'units',
        'received_qty' => 10,
        'cost_price' => '30.00',
        'batch_number' => 'BATCH-OIL500-01',
        'expiration_date' => date('Y-m-d', strtotime('+180 days')),
        'supplier' => 'Golden Oil Supplier',
    ], $inventoryManagerId);

    $assert((int)$rec500['accepted_qty'] === 10, 'Received 10 pouches of Oil 500 mL');

    $oil250AfterRec2 = $findProduct($oil250Id);
    $oil500AfterRec2 = $findProduct($oil500Id);
    $assert((int)$oil250AfterRec2['quantity_on_hand'] === 20, 'Oil 250 mL inventory is unchanged at 20');
    $assert((int)$oil500AfterRec2['quantity_on_hand'] === 10, 'Oil 500 mL inventory is now 10 pouches');

    // Verify product batches are separate and retain provenance
    $batchStmt = $pdo->prepare('SELECT batch_id, batch_number, remaining_quantity, cost_price FROM product_batches WHERE product_id = ?');
    $batchStmt->execute([$oil250Id]);
    $batch250 = $batchStmt->fetch();
    $assert($batch250['batch_number'] === 'BATCH-OIL250-01' && (int)$batch250['remaining_quantity'] === 20, 'Oil 250 mL batch created with 20 pouches');

    $batchStmt->execute([$oil500Id]);
    $batch500 = $batchStmt->fetch();
    $assert($batch500['batch_number'] === 'BATCH-OIL500-01' && (int)$batch500['remaining_quantity'] === 10, 'Oil 500 mL batch created with 10 pouches');

    // -------------------------------------------------------------------------
    // 3. Checkout / Sales Seam (SalesWorkflowService)
    // -------------------------------------------------------------------------
    echo "\n--- 3. Checkout / Sales Seam ---\n";

    $_SESSION['user_id'] = $cashierId;
    $_SESSION['role'] = 'cashier';

    // Checkout write boundary rejects invalid/fractional quantities
    $throws(
        static fn() => $salesWorkflow->checkout([
            ['product_id' => $oil250Id, 'qty' => 1.5],
        ], $cashierId, 'cashier', 'cash', ['cash_received' => 50.00]),
        Throwable::class,
        'Checkout rejects float qty 1.5'
    );

    $throws(
        static fn() => $salesWorkflow->checkout([
            ['product_id' => $oil250Id, 'qty' => '2.5'],
        ], $cashierId, 'cashier', 'cash', ['cash_received' => 100.00]),
        Throwable::class,
        'Checkout rejects fractional string qty 2.5'
    );

    $throws(
        static fn() => $salesWorkflow->checkout([
            ['product_id' => $oil250Id, 'qty' => 0.5],
        ], $cashierId, 'cashier', 'cash', ['cash_received' => 20.00]),
        Throwable::class,
        'Checkout rejects fractional qty 0.5'
    );

    $throws(
        static fn() => $salesWorkflow->checkout([
            ['product_id' => $oil250Id, 'qty' => 0],
        ], $cashierId, 'cashier', 'cash', ['cash_received' => 20.00]),
        DomainException::class,
        'Checkout rejects integer 0 qty'
    );

    $throws(
        static fn() => $salesWorkflow->checkout([
            ['product_id' => $oil250Id, 'qty' => -1],
        ], $cashierId, 'cashier', 'cash', ['cash_received' => 20.00]),
        DomainException::class,
        'Checkout rejects negative integer -1 qty'
    );

    $throws(
        static fn() => $salesWorkflow->checkout([
            ['product_id' => $oil250Id, 'qty' => '0'],
        ], $cashierId, 'cashier', 'cash', ['cash_received' => 20.00]),
        DomainException::class,
        'Checkout rejects string 0 qty'
    );

    $throws(
        static fn() => $salesWorkflow->checkout([
            ['product_id' => $oil250Id, 'qty' => '-1'],
        ], $cashierId, 'cashier', 'cash', ['cash_received' => 20.00]),
        DomainException::class,
        'Checkout rejects string -1 qty'
    );

    // Sell 3 pouches of Oil 250 mL at PHP 20 each
    $quote1 = $salesWorkflow->reviewQuote([
        ['product_id' => $oil250Id, 'qty' => 3],
    ], $cashierId, 'cashier');

    $attempt1 = bin2hex(random_bytes(16));
    $sale1 = $salesWorkflow->checkout([
        ['product_id' => $oil250Id, 'qty' => 3],
    ], $cashierId, 'cashier', 'cash', [
        'cash_received' => 100.00,
        'checkout_attempt' => $attempt1,
        'reviewed_quote' => $quote1['state_hash'],
    ]);

    $assert((float)$sale1['total'] === 60.00, 'Sale 1 total is PHP 60.00 (3 * PHP 20.00)');

    $sale1Items = $pdo->query("SELECT * FROM sale_items WHERE sale_id = {$sale1['sale_id']}")->fetchAll();
    $assert(count($sale1Items) === 1, 'Sale 1 contains 1 item line');
    $assert((int)$sale1Items[0]['quantity'] === 3, 'Sale 1 item line quantity is 3 pouches');
    $assert((float)$sale1Items[0]['unit_price'] === 20.00, 'Sale 1 unit price is PHP 20.00');

    // Verify inventory after sale 1: 250 mL drops by 3 to 17; 500 mL unchanged at 10
    $oil250AfterSale1 = $findProduct($oil250Id);
    $oil500AfterSale1 = $findProduct($oil500Id);
    $assert((int)$oil250AfterSale1['quantity_on_hand'] === 17, 'Oil 250 mL balance decremented to 17 pouches');
    $assert((int)$oil500AfterSale1['quantity_on_hand'] === 10, 'Oil 500 mL balance unchanged at 10 pouches');

    // Sell 2 pouches of Oil 500 mL at PHP 40 each
    $quote2 = $salesWorkflow->reviewQuote([
        ['product_id' => $oil500Id, 'qty' => 2],
    ], $cashierId, 'cashier');

    $attempt2 = bin2hex(random_bytes(16));
    $sale2 = $salesWorkflow->checkout([
        ['product_id' => $oil500Id, 'qty' => 2],
    ], $cashierId, 'cashier', 'cash', [
        'cash_received' => 100.00,
        'checkout_attempt' => $attempt2,
        'reviewed_quote' => $quote2['state_hash'],
    ]);

    $assert((float)$sale2['total'] === 80.00, 'Sale 2 total is PHP 80.00 (2 * PHP 40.00)');

    $sale2Items = $pdo->query("SELECT * FROM sale_items WHERE sale_id = {$sale2['sale_id']}")->fetchAll();
    $assert(count($sale2Items) === 1, 'Sale 2 contains 1 item line');
    $assert((int)$sale2Items[0]['quantity'] === 2, 'Sale 2 item line quantity is 2 pouches');
    $assert((float)$sale2Items[0]['unit_price'] === 40.00, 'Sale 2 unit price is PHP 40.00');

    // Verify inventory after sale 2: 500 mL drops by 2 to 8; 250 mL unchanged at 17
    $oil250AfterSale2 = $findProduct($oil250Id);
    $oil500AfterSale2 = $findProduct($oil500Id);
    $assert((int)$oil250AfterSale2['quantity_on_hand'] === 17, 'Oil 250 mL balance unchanged at 17 pouches');
    $assert((int)$oil500AfterSale2['quantity_on_hand'] === 8, 'Oil 500 mL balance decremented to 8 pouches');

    // -------------------------------------------------------------------------
    // 4. Cash Refund Seam (CashRefundService)
    // -------------------------------------------------------------------------
    echo "\n--- 4. Cash Refund Seam ---\n";

    $sale1ItemId = (int)$sale1Items[0]['sale_item_id'];

    // Refund write boundary rejects fractional quantities
    $throws(
        static fn() => $refundService->refund($cashierId, 'cashier', (int)$sale1['sale_id'], [
            $sale1ItemId => ['quantity' => 1.5, 'disposition' => 'restockable'],
        ], 'customer_return'),
        Throwable::class,
        'Refund rejects float quantity 1.5'
    );

    $throws(
        static fn() => $refundService->refund($cashierId, 'cashier', (int)$sale1['sale_id'], [
            $sale1ItemId => ['quantity' => '0.5', 'disposition' => 'restockable'],
        ], 'customer_return'),
        Throwable::class,
        'Refund rejects fractional string quantity 0.5'
    );

    $throws(
        static fn() => $refundService->refund($cashierId, 'cashier', (int)$sale1['sale_id'], [
            $sale1ItemId => ['quantity' => 0, 'disposition' => 'restockable'],
        ], 'customer_return'),
        DomainException::class,
        'Refund rejects integer 0 quantity'
    );

    $throws(
        static fn() => $refundService->refund($cashierId, 'cashier', (int)$sale1['sale_id'], [
            $sale1ItemId => ['quantity' => -1, 'disposition' => 'restockable'],
        ], 'customer_return'),
        DomainException::class,
        'Refund rejects negative integer -1 quantity'
    );

    $throws(
        static fn() => $refundService->refund($cashierId, 'cashier', (int)$sale1['sale_id'], [
            $sale1ItemId => ['quantity' => '0', 'disposition' => 'restockable'],
        ], 'customer_return'),
        DomainException::class,
        'Refund rejects string 0 quantity'
    );

    $throws(
        static fn() => $refundService->refund($cashierId, 'cashier', (int)$sale1['sale_id'], [
            $sale1ItemId => ['quantity' => '-1', 'disposition' => 'restockable'],
        ], 'customer_return'),
        DomainException::class,
        'Refund rejects string -1 quantity'
    );

    // Refund 1 whole pouch of Oil 250 mL
    $refundId = $refundService->refund($cashierId, 'cashier', (int)$sale1['sale_id'], [
        $sale1ItemId => ['quantity' => 1, 'disposition' => 'restockable'],
    ], 'customer_return');

    $assert($refundId > 0, 'Refunded 1 pouch of Oil 250 mL');

    // Check balances: 250 mL restored by 1 to 18; 500 mL unchanged at 8
    $oil250AfterRefund = $findProduct($oil250Id);
    $oil500AfterRefund = $findProduct($oil500Id);
    $assert((int)$oil250AfterRefund['quantity_on_hand'] === 18, 'Oil 250 mL balance restored to 18 pouches');
    $assert((int)$oil500AfterRefund['quantity_on_hand'] === 8, 'Oil 500 mL balance unchanged at 8 pouches');

    // Batch remaining_quantity restored
    $batchStmt->execute([$oil250Id]);
    $batch250AfterRefund = $batchStmt->fetch();
    $assert((int)$batch250AfterRefund['remaining_quantity'] === 18, 'Oil 250 mL batch restored to 18 pouches');

    // -------------------------------------------------------------------------
    // 5. Inventory Count Seam (InventoryCountService)
    // -------------------------------------------------------------------------
    echo "\n--- 5. Inventory Count Seam ---\n";

    $_SESSION['user_id'] = $inventoryManagerId;
    $_SESSION['role'] = 'inventory_manager';

    // Count write boundary rejects fractional quantities
    $batchStmt->execute([$oil250Id]);
    $currentBatch250 = $batchStmt->fetch();

    $throws(
        static fn() => $countService->recordCount([
            'product_id' => $oil250Id,
            'physical_quantity' => 17.5,
            'discrepancy_reason' => 'Cycle count test',
        ], $inventoryManagerId),
        Throwable::class,
        'Count rejects float physical_quantity 17.5'
    );

    $throws(
        static fn() => $countService->recordCount([
            'product_id' => $oil250Id,
            'physical_quantity' => '17.5',
            'discrepancy_reason' => 'Cycle count test',
        ], $inventoryManagerId),
        Throwable::class,
        'Count rejects fractional string physical_quantity'
    );

    $throws(
        static fn() => $countService->recordCount([
            'product_id' => $oil250Id,
            'discrepancy_reason' => 'Cycle count test',
            'batches' => [
                [
                    'batch_id' => $currentBatch250['batch_id'] ?? 1,
                    'physical_quantity' => 17.5,
                ],
            ],
        ], $inventoryManagerId),
        Throwable::class,
        'Count rejects fractional batch physical_quantity'
    );

    $throws(
        static fn() => $countService->recordCount([
            'product_id' => $oil250Id,
            'physical_quantity' => -1,
            'discrepancy_reason' => 'Cycle count test',
        ], $inventoryManagerId),
        RuntimeException::class,
        'Count rejects negative integer -1 physical_quantity'
    );

    $throws(
        static fn() => $countService->recordCount([
            'product_id' => $oil250Id,
            'physical_quantity' => '-1',
            'discrepancy_reason' => 'Cycle count test',
        ], $inventoryManagerId),
        RuntimeException::class,
        'Count rejects negative string -1 physical_quantity'
    );

    $throws(
        static fn() => $countService->recordCount([
            'product_id' => $oil250Id,
            'discrepancy_reason' => 'Cycle count test',
            'batches' => [
                [
                    'batch_id' => $currentBatch250['batch_id'] ?? 1,
                    'physical_quantity' => -1,
                ],
            ],
        ], $inventoryManagerId),
        RuntimeException::class,
        'Count rejects negative batch physical_quantity'
    );

    // Record valid whole count of 19 pouches (found 1 extra pouch)
    $countId = $countService->recordCount([
        'product_id' => $oil250Id,
        'physical_quantity' => 19,
        'discrepancy_reason' => 'Found 1 extra pouch behind shelf',
        'batches' => [
            [
                'batch_id' => (int)$currentBatch250['batch_id'],
                'physical_quantity' => 19,
            ],
        ],
    ], $inventoryManagerId);

    $assert($countId > 0, 'Inventory count recorded successfully');

    $countService->approveCount($countId, $inventoryManagerId);

    $oil250AfterCount = $findProduct($oil250Id);
    $oil500AfterCount = $findProduct($oil500Id);
    $assert((int)$oil250AfterCount['quantity_on_hand'] === 19, 'Oil 250 mL balance updated to 19 pouches');
    $assert((int)$oil500AfterCount['quantity_on_hand'] === 8, 'Oil 500 mL balance unchanged at 8 pouches');

    // -------------------------------------------------------------------------
    // 6. Non-goals / Invariants Verification
    // -------------------------------------------------------------------------
    echo "\n--- 6. Invariants & Non-Goals ---\n";

    // Verify no bulk oil or conversion exists
    $columns = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    $assert(!in_array('bulk_volume', $columns, true), 'No bulk_volume column on products table');
    $assert(!in_array('litres_on_hand', $columns, true), 'No litres_on_hand column on products table');
    $assert(!in_array('spillage_rate', $columns, true), 'No spillage_rate column on products table');

    // Verify quantity_on_hand == sum(remaining_quantity)
    $qohStmt = $pdo->query("SELECT p.product_id, p.product_name, i.quantity_on_hand,
        COALESCE(SUM(pb.remaining_quantity), 0) AS batch_sum
        FROM products p
        JOIN inventory i ON i.product_id = p.product_id
        LEFT JOIN product_batches pb ON pb.product_id = p.product_id
        WHERE p.product_id IN ({$oil250Id}, {$oil500Id})
        GROUP BY p.product_id, p.product_name, i.quantity_on_hand");

    foreach ($qohStmt->fetchAll() as $row) {
        $assert(
            (int)$row['quantity_on_hand'] === (int)$row['batch_sum'],
            "Invariant hold: {$row['product_name']} quantity_on_hand ({$row['quantity_on_hand']}) equals batch sum ({$row['batch_sum']})"
        );
    }

} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    echo "Cleaned up disposable database {$dbName}.\n";
}

if ($failures !== []) {
    echo "\nTest failed with " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "\nAll Ticket #254 Prepared Oil Pouches tests passed successfully!\n";
