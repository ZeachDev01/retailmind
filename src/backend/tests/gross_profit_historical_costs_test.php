<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

set_exception_handler(static function (Throwable $e): void {
    $class = get_class($e);
    fwrite(STDERR, "Uncaught exception {$class}: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}\n");
    exit(1);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        fwrite(STDERR, "FATAL ERROR: {$err['message']} in {$err['file']}:{$err['line']}\n");
    }
});

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/GrossProfitService.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';

use App\Services\GrossProfitService;
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

$throws = static function (callable $operation, string $expectedClass, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message . ' (expected ' . $expectedClass . ', none thrown)');
    } catch (Throwable $e) {
        $assert(
            $e instanceof $expectedClass,
            $message . ' (threw ' . $e::class . ': ' . $e->getMessage() . ')'
        );
    }
};

echo "Starting Ticket #230 Gross Profit Historical Costs Tests...\n";

// --- Database Setup (SQLite Memory) ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('LEAST', static fn(...$values) => min($values));
$pdo->sqliteCreateFunction('GREATEST', static fn(...$values) => max($values));
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$GLOBALS['pdo'] = $pdo;

$pdo->exec("CREATE TABLE branches (
    branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name TEXT NOT NULL,
    branch_code TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Store', 'MAIN', 'active')");

$pdo->exec("CREATE TABLE categories (
    category_id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_name TEXT NOT NULL
)");
$pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'General'), (2, 'Groceries')");

$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)");
$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin')");

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE,
    role_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO users (user_id, full_name, username, role_id, branch_id, status) VALUES (1, 'Admin Alice', 'admin1', 3, 1, 'active')");
$pdo->exec("INSERT INTO users (user_id, full_name, username, role_id, branch_id, status) VALUES (2, 'Cashier Bob', 'cashier1', 1, 1, 'active')");

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    sku TEXT NOT NULL,
    barcode TEXT NOT NULL DEFAULT '',
    product_name TEXT NOT NULL,
    category_id INTEGER NOT NULL DEFAULT 1,
    units_per_package INTEGER NOT NULL DEFAULT 1,
    base_unit TEXT NOT NULL DEFAULT 'piece',
    receiving_unit TEXT NOT NULL DEFAULT 'case',
    unit_price REAL NOT NULL DEFAULT 10.0,
    cost_price REAL NOT NULL DEFAULT 0.0,
    status TEXT NOT NULL DEFAULT 'active'
)");

$pdo->exec("CREATE TABLE stock_receiving (
    receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    received_qty INTEGER NOT NULL,
    received_packages INTEGER NOT NULL DEFAULT 0,
    units_per_package_used INTEGER NOT NULL DEFAULT 1,
    accepted_qty INTEGER NOT NULL DEFAULT 0,
    damaged_qty INTEGER NOT NULL DEFAULT 0,
    cost_price REAL NULL,
    total_cost REAL NOT NULL DEFAULT 0.00,
    received_by INTEGER NOT NULL DEFAULT 1,
    received_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE product_batches (
    batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    receiving_id INTEGER NULL,
    batch_number TEXT NULL,
    quantity INTEGER NOT NULL,
    remaining_quantity INTEGER NOT NULL,
    cost_price REAL NULL,
    expiration_date TEXT NULL,
    date_received TEXT NOT NULL
)");

$pdo->exec("CREATE TABLE sales (
    sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
    shift_id INTEGER NOT NULL DEFAULT 1,
    cashier_id INTEGER NOT NULL DEFAULT 2,
    gross_amount REAL NULL,
    total_amount REAL NOT NULL DEFAULT 0,
    discount_amount REAL NOT NULL DEFAULT 0,
    discount_type TEXT NOT NULL DEFAULT 'none',
    discount_value REAL NOT NULL DEFAULT 0,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    sale_date TEXT DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE sale_items (
    sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price REAL NOT NULL,
    subtotal REAL NOT NULL
)");

$pdo->exec("CREATE TABLE sale_item_batches (
    sale_item_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_item_id INTEGER NOT NULL,
    batch_id INTEGER NULL,
    quantity INTEGER NOT NULL,
    unit_cost REAL NULL,
    cost_price REAL NULL,
    cost_status TEXT NOT NULL DEFAULT 'missing',
    cost_source TEXT NULL,
    cost_provenance TEXT NULL
)");

$pdo->exec("CREATE TABLE cash_refunds (
    refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    shift_id INTEGER NOT NULL DEFAULT 1,
    cashier_id INTEGER NOT NULL DEFAULT 2,
    refund_amount REAL NOT NULL,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    reason TEXT NOT NULL DEFAULT 'other',
    note TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE cash_refund_items (
    refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
    refund_id INTEGER NOT NULL,
    sale_item_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price REAL NOT NULL,
    subtotal REAL NOT NULL DEFAULT 0.00,
    disposition TEXT NOT NULL DEFAULT 'restockable'
)");

$pdo->exec("CREATE TABLE inventory (
    product_id INTEGER PRIMARY KEY,
    quantity_on_hand INTEGER NOT NULL DEFAULT 0
)");

$pdo->exec("CREATE TABLE sale_receipt_details (
    receipt_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    details_json TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$service = new GrossProfitService($pdo);
$_SESSION['role'] = 'admin';

// =========================================================================
// Test 1: Traceable Legacy Batch & Receiving Costs with Base Unit Conversion
// =========================================================================
echo "\n--- Test 1: Traceable Legacy Costs with Base Unit Normalization ---\n";

// Product 1: Canned Tuna (24 cans/case). Base unit: can.
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, units_per_package, base_unit, receiving_unit, unit_price, cost_price)
            VALUES (1, 1, 'SKU-TUNA', 'BC-TUNA', 'Canned Tuna 155g', 2, 24, 'can', 'case', 25.00, 15.00)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 48)");

// Received 2 cases (48 cans). total_cost = 600.00 -> 12.50 per can (base unit)
$pdo->exec("INSERT INTO stock_receiving (receiving_id, product_id, received_qty, received_packages, units_per_package_used, accepted_qty, cost_price, total_cost)
            VALUES (101, 1, 48, 2, 24, 48, 12.50, 600.00)");

// Batch 1001 created from receiving 101, but batch cost_price was NULL (legacy)
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity, cost_price, date_received)
            VALUES (1001, 1, 101, 'BATCH-TUNA-01', 48, 28, NULL, '2026-10-01')");

// Older Sale 1 on 2026-10-05: 20 cans sold @ 25.00 = 500.00.
// Pre-existing allocation in sale_item_batches with unpopulated unit_cost (null) and cost_status = 'missing'
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, gross_amount, total_amount, sale_date)
            VALUES (1, 2, 500.00, 500.00, '2026-10-05 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (1, 1, 1, 20, 25.00, 500.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status, cost_source)
            VALUES (1, 1001, 20, NULL, NULL, 'missing', NULL)");

// Establish historical costs for Sale 1
$recoveryResult = $service->establishHistoricalCosts([1]);
$assert($recoveryResult['processed_sales'] === 1, 'Processed 1 legacy sale');
$assert($recoveryResult['established_lines'] === 1, 'Established 1 line');
$assert($recoveryResult['recovered_allocations'] === 1, 'Recovered 1 allocation');

// Check that snapshot was saved to sale_item_batches
$alloc1 = $pdo->query("SELECT * FROM sale_item_batches WHERE sale_item_id = 1")->fetch();
$assert((float)$alloc1['unit_cost'] === 12.50, 'Recovered unit_cost normalized to base unit (12.50/can)');
$assert($alloc1['cost_status'] === 'recorded', 'Cost status is recorded');
$assert($alloc1['cost_source'] === 'receiving', 'Cost source is receiving');

// Calculate gross profit for Oct 5
$calc1 = $service->calculate(['from' => '2026-10-05', 'to' => '2026-10-05', 'store_id' => 1]);
$assert($calc1['summary']['total_net_sales'] === 500.00, 'Net Sales is 500.00');
$assert($calc1['summary']['total_net_cost'] === 250.00, 'Net Cost is 250.00 (20 * 12.50)');
$assert($calc1['summary']['total_gross_profit'] === 250.00, 'Gross profit is 250.00');
$assert($calc1['summary']['total_margin'] === 50.00, 'Margin is 50%');
$assert($calc1['summary']['status'] === 'recorded', 'Summary status is recorded');

// =========================================================================
// Test 2: Mixed-Cost Allocations Across Multiple Batches
// =========================================================================
echo "\n--- Test 2: Mixed-Cost Allocations Across Multiple Batches ---\n";

// Product 2: Instant Noodles
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (2, 1, 'SKU-NOODLE', 'BC-NOODLE', 'Instant Noodles', 2, 20.00, 10.00)");

// Two batches with different costs: Batch 1002A @ 10.00, Batch 1002B @ 15.00
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, date_received)
            VALUES (10021, 2, 10, 7, 10.00, '2026-10-01')");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, date_received)
            VALUES (10022, 2, 10, 8, 15.00, '2026-10-02')");

// Older Sale 2: 5 units sold (3 from 10021, 2 from 10022), but unpopulated unit_cost
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, gross_amount, total_amount, sale_date)
            VALUES (2, 2, 100.00, 100.00, '2026-10-06 11:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (2, 2, 2, 5, 20.00, 100.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_id, batch_id, quantity, unit_cost, cost_status)
            VALUES (2, 10021, 3, NULL, 'missing')");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_id, batch_id, quantity, unit_cost, cost_status)
            VALUES (2, 10022, 2, NULL, 'missing')");

// Establish historical costs
$service->establishHistoricalCosts([2]);

$allocs2 = $pdo->query("SELECT * FROM sale_item_batches WHERE sale_item_id = 2 ORDER BY sale_item_batch_id ASC")->fetchAll();
$alloc2A = $allocs2[0];
$alloc2B = $allocs2[1];
$assert((float)$alloc2A['unit_cost'] === 10.00, 'Allocation 2A preserved individual purchase cost 10.00');
$assert($alloc2A['cost_status'] === 'recorded', 'Allocation 2A cost status is recorded');
$assert((float)$alloc2B['unit_cost'] === 15.00, 'Allocation 2B preserved individual purchase cost 15.00');
$assert($alloc2B['cost_status'] === 'recorded', 'Allocation 2B cost status is recorded');

// Calculate gross profit for Oct 6
$calc2 = $service->calculate(['from' => '2026-10-06', 'to' => '2026-10-06', 'store_id' => 1]);
$assert($calc2['summary']['total_net_sales'] === 100.00, 'Sale 2 Net Sales is 100.00');
$assert($calc2['summary']['total_net_cost'] === 60.00, 'Sale 2 Net Cost is 60.00 (3*10 + 2*15)');
$assert($calc2['summary']['total_gross_profit'] === 40.00, 'Sale 2 Gross Profit is 40.00');
$assert($calc2['summary']['total_margin'] === 40.00, 'Sale 2 Margin is 40%');
$assert($calc2['summary']['status'] === 'recorded', 'Sale 2 status is recorded');

// =========================================================================
// Test 3: Partially Covered Quantities & Opening Stock (Estimated Fallback)
// =========================================================================
echo "\n--- Test 3: Partially Covered Quantities & Opening Stock ---\n";

// Product 3: Premium Juice. Catalog cost_price = 14.00
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (3, 1, 'SKU-JUICE', 'BC-JUICE', 'Premium Juice', 1, 20.00, 14.00)");

// Batch 1003 has 6 units @ 10.00
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, date_received)
            VALUES (1003, 3, 6, 0, 10.00, '2026-10-01')");

// Older Sale 3 on 2026-10-07: 10 units sold.
// Only 6 units were covered in sale_item_batches (batch 1003 @ 10.00). 4 units were UNALLOCATED opening stock!
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, gross_amount, total_amount, sale_date)
            VALUES (3, 2, 200.00, 200.00, '2026-10-07 12:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (3, 3, 3, 10, 20.00, 200.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_id, batch_id, quantity, unit_cost, cost_status, cost_source)
            VALUES (3, 1003, 6, 10.00, 'recorded', 'batch')");

// Establish historical costs: should create snapshot for uncovered 4 units using catalog cost_price 14.00 as estimate
$service->establishHistoricalCosts([3]);

$allocs3 = $pdo->query("SELECT * FROM sale_item_batches WHERE sale_item_id = 3 ORDER BY sale_item_batch_id ASC")->fetchAll();
$assert(count($allocs3) === 2, 'Sale item 3 now has exactly 2 allocation rows covering all 10 units');
$assert((int)$allocs3[0]['quantity'] === 6 && (float)$allocs3[0]['unit_cost'] === 10.00 && $allocs3[0]['cost_status'] === 'recorded', 'Covered 6 units recorded at 10.00');
$assert((int)$allocs3[1]['quantity'] === 4 && (float)$allocs3[1]['unit_cost'] === 14.00 && $allocs3[1]['cost_status'] === 'estimated', 'Unallocated 4 units estimated at catalog cost 14.00');
$assert($allocs3[1]['cost_source'] === 'product', 'Unallocated cost source is product');

// Calculate gross profit for Oct 7
$calc3 = $service->calculate(['from' => '2026-10-07', 'to' => '2026-10-07', 'store_id' => 1]);
$row3 = $calc3['rows'][0];
$assert($row3['net_sales'] === 200.00, 'Product 3 Net Sales is 200.00');
$assert($row3['net_cost'] === 116.00, 'Product 3 Net Cost is 116.00 (6*10 + 4*14)');
$assert($row3['gross_profit'] === 84.00, 'Product 3 Gross Profit is 84.00 (200 - 116)');
$assert($row3['status'] === 'estimated', 'Product 3 row status is estimated');
$assert($row3['margin'] === null, 'Margin is withheld for estimated costs');
$assert($calc3['summary']['status'] === 'estimated', 'Summary status is estimated');
$assert($calc3['summary']['total_margin'] === null, 'Summary margin is withheld');

// =========================================================================
// Test 4: Confirmed Zero vs Ambiguous Database Default Zero
// =========================================================================
echo "\n--- Test 4: Confirmed Zero vs Ambiguous Default Zero ---\n";

// Scenario 4A: Confirmed Zero Purchase Cost (Promo / Free stock recorded as 0.00 in batch)
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (4, 1, 'SKU-FREE', 'BC-FREE', 'Free Promo Water', 1, 5.00, 0.00)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, date_received)
            VALUES (1004, 4, 10, 8, 0.00, '2026-10-01')");

$pdo->exec("INSERT INTO sales (sale_id, cashier_id, gross_amount, total_amount, sale_date)
            VALUES (4, 2, 10.00, 10.00, '2026-10-08 09:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (4, 4, 4, 2, 5.00, 10.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_id, batch_id, quantity, unit_cost, cost_status)
            VALUES (4, 1004, 2, NULL, 'missing')");

$service->establishHistoricalCosts([4]);

$alloc4A = $pdo->query("SELECT * FROM sale_item_batches WHERE sale_item_id = 4")->fetch();
$assert($alloc4A['unit_cost'] !== null && (float)$alloc4A['unit_cost'] === 0.00, 'Confirmed zero cost preserved as 0.00');
$assert($alloc4A['cost_status'] === 'recorded', 'Confirmed zero cost status is recorded');

$calc4A = $service->calculate(['from' => '2026-10-08', 'to' => '2026-10-08', 'product_id' => 4, 'store_id' => 1]);
$assert($calc4A['summary']['total_net_cost'] === 0.00, 'Confirmed zero net cost is 0.00');
$assert($calc4A['summary']['total_gross_profit'] === 10.00, 'Confirmed zero gross profit is 10.00');
$assert($calc4A['summary']['total_margin'] === 100.00, 'Confirmed zero margin is 100%');
$assert($calc4A['summary']['status'] === 'recorded', 'Confirmed zero summary status is recorded');

// Scenario 4B: Ambiguous Database Default Zero (product cost_price is 0.00, completely unallocated older sale)
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (5, 1, 'SKU-UNKNOWN', 'BC-UNKNOWN', 'Unknown Cost Item', 1, 50.00, 0.00)");

// Sale 5: completely unallocated (0 rows in sale_item_batches)
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, gross_amount, total_amount, sale_date)
            VALUES (5, 2, 100.00, 100.00, '2026-10-08 14:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (5, 5, 5, 2, 50.00, 100.00)");

$service->establishHistoricalCosts([5]);

$alloc4B = $pdo->query("SELECT * FROM sale_item_batches WHERE sale_item_id = 5")->fetch();
$assert($alloc4B['unit_cost'] === null, 'Ambiguous zero default cost is stored as NULL');
$assert($alloc4B['cost_status'] === 'missing', 'Ambiguous zero cost status is missing');
$assert($alloc4A['cost_status'] !== $alloc4B['cost_status'], 'Confirmed zero is strictly distinguished from unknown default zero');

$calc4B = $service->calculate(['from' => '2026-10-08', 'to' => '2026-10-08', 'product_id' => 5, 'store_id' => 1]);
$assert($calc4B['summary']['status'] === 'incomplete', 'Ambiguous zero results in incomplete status (never assumed zero)');
$assert($calc4B['summary']['total_net_cost'] === null, 'Cost is withheld as null');
$assert($calc4B['summary']['total_gross_profit'] === null, 'Profit is withheld as null');
$assert($calc4B['summary']['total_margin'] === null, 'Margin is withheld as null');
$assert($calc4B['summary']['affected_lines_count'] === 1, 'Affected lines count is exactly 1');

// =========================================================================
// Test 5: Uncertainty Precedence & Isolated Known-Cost Subtotals
// =========================================================================
echo "\n--- Test 5: Uncertainty Precedence & Known-Cost Subtotal ---\n";

// Combined report for 2026-10-08 includes:
// Product 4: Recorded (Sales 10, Cost 0, Profit 10)
// Product 5: Missing / Incomplete (Sales 100, Cost NULL)
$calc5 = $service->calculate(['from' => '2026-10-08', 'to' => '2026-10-08', 'store_id' => 1]);

$assert($calc5['summary']['status'] === 'incomplete', 'Incomplete status takes precedence over recorded');
$assert($calc5['summary']['total_net_sales'] === 110.00, 'Total net sales is preserved at 110.00');
$assert($calc5['summary']['total_net_cost'] === null, 'Total net cost is unavailable (null)');
$assert($calc5['summary']['total_gross_profit'] === null, 'Total gross profit is unavailable (null)');
$assert($calc5['summary']['total_margin'] === null, 'Total margin is unavailable (null)');
$assert($calc5['summary']['affected_lines_count'] === 1, 'Distinct affected sold lines count is 1');

// Known-cost subtotal isolates only the known-cost lines (Product 4)
$assert($calc5['summary']['known_net_sales'] === 10.00, 'Known-cost Net Sales is exactly 10.00 (excludes unknown line)');
$assert($calc5['summary']['known_net_cost'] === 0.00, 'Known-cost Net Cost is exactly 0.00');
$assert($calc5['summary']['known_gross_profit'] === 10.00, 'Known-cost profit is exactly 10.00');
$assert($calc5['summary']['known_margin'] === 100.00, 'Known-cost margin is 100%');

// =========================================================================
// Test 6: Historical Refunds Use Established Original Allocation Costs
// =========================================================================
echo "\n--- Test 6: Historical Refunds Use Established Allocation Costs ---\n";

// Sale 6 on 2026-10-09: 5 units of Product 2 (Noodles)
// Batch allocations: 3 units @ 10.00, 2 units @ 15.00
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, gross_amount, total_amount, sale_date)
            VALUES (6, 2, 100.00, 100.00, '2026-10-09 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (6, 6, 2, 5, 20.00, 100.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_id, batch_id, quantity, unit_cost, cost_status, cost_source)
            VALUES (6, 10021, 3, 10.00, 'recorded', 'batch')");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_id, batch_id, quantity, unit_cost, cost_status, cost_source)
            VALUES (6, 10022, 2, 15.00, 'recorded', 'batch')");

// Refund 1 on 2026-10-12: 2 units restockable returned (from the 3 @ 10.00 slice)
$pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, cashier_id, refund_amount, payment_method, reason, created_at)
            VALUES (1, 6, 2, 40.00, 'cash', 'overcharge', '2026-10-12 10:00:00')");
$pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
            VALUES (1, 1, 6, 2, 2, 20.00, 40.00, 'restockable')");

// Calculate gross profit for refund-only period (2026-10-12)
$refundCalc = $service->calculate(['from' => '2026-10-12', 'to' => '2026-10-12', 'store_id' => 1]);
$assert($refundCalc['summary']['total_net_sales'] === -40.00, 'Refund period Net Sales is -40.00');
$assert($refundCalc['summary']['total_net_cost'] === -20.00, 'Refund period reversed cost is -20.00 (2 * 10.00)');
$assert($refundCalc['summary']['total_gross_profit'] === -20.00, 'Refund period net profit adjustment is -20.00 (-40 - (-20))');
$assert($refundCalc['summary']['status'] === 'recorded', 'Refund cost status is recorded from original allocation');

// Calculate gross profit for full lifecycle (Oct 9 to Oct 12)
$fullCalc = $service->calculate(['from' => '2026-10-09', 'to' => '2026-10-12', 'product_id' => 2, 'store_id' => 1]);
$assert($fullCalc['summary']['total_net_sales'] === 60.00, 'Full period Net Sales is 60.00 (100 - 40)');
$assert($fullCalc['summary']['total_net_cost'] === 40.00, 'Full period Net Cost is 40.00 (60 - 20)');
$assert($fullCalc['summary']['total_gross_profit'] === 20.00, 'Full period Gross Profit is 20.00 (60 - 40)');
$assert($fullCalc['summary']['total_margin'] === 33.33, 'Full period margin is 33.33%');

// =========================================================================
// Test 7: Stability After Subsequent Catalog Price & Batch Changes
// =========================================================================
echo "\n--- Test 7: Stability After Subsequent Price & Cost Changes ---\n";

// Drastically change product catalog price, cost_price, and batch cost
$pdo->exec("UPDATE products SET cost_price = 999.00, unit_price = 1500.00 WHERE product_id = 1");
$pdo->exec("UPDATE product_batches SET cost_price = 888.00 WHERE batch_id = 1001");
$pdo->exec("UPDATE stock_receiving SET total_cost = 99999.00, cost_price = 888.00 WHERE receiving_id = 101");

// Re-run establishHistoricalCosts - must be idempotent and NOT recalculate or overwrite established snapshot
$reRun = $service->establishHistoricalCosts([1]);
$alloc1After = $pdo->query("SELECT * FROM sale_item_batches WHERE sale_item_batch_id = 1")->fetch();

$assert((float)$alloc1After['unit_cost'] === 12.50, 'Historical unit_cost strictly stable at 12.50 after catalog & receiving changes');
$assert($alloc1After['cost_status'] === 'recorded', 'Cost status remains recorded');

// Re-calculate gross profit for Sale 1 period
$calc1After = $service->calculate(['from' => '2026-10-05', 'to' => '2026-10-05', 'store_id' => 1]);
$assert($calc1After['summary']['total_net_cost'] === 250.00, 'Historical Net Cost remains stable at 250.00');
$assert($calc1After['summary']['total_gross_profit'] === 250.00, 'Historical Gross Profit remains stable at 250.00');
$assert($calc1After['summary']['total_margin'] === 50.00, 'Historical Margin remains stable at 50%');

// Also test estimated line stability (Product 3)
$pdo->exec("UPDATE products SET cost_price = 777.00 WHERE product_id = 3");
$service->establishHistoricalCosts([3]);
$alloc3After = $pdo->query("SELECT * FROM sale_item_batches WHERE sale_item_id = 3 ORDER BY sale_item_batch_id ASC")->fetchAll();
$assert((float)$alloc3After[1]['unit_cost'] === 14.00, 'Historical estimated unit_cost strictly stable at 14.00 after product cost change to 777.00');

// =========================================================================
// Test 8: Operational & Audit Record Non-Rewriting Invariance
// =========================================================================
echo "\n--- Test 8: Operational & Audit Record Non-Rewriting Invariance ---\n";

// Capture operational snapshots before establishment
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, gross_amount, total_amount, discount_amount, payment_method, sale_date)
            VALUES (8, 2, 300.00, 270.00, 30.00, 'cash', '2026-10-13 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (8, 8, 2, 15, 20.00, 300.00)");
$pdo->exec("INSERT INTO sale_receipt_details (sale_id, details_json)
            VALUES (8, '{\"receipt_no\": \"REC-008\", \"total\": 270.00}')");

$saleBefore = $pdo->query("SELECT * FROM sales WHERE sale_id = 8")->fetch();
$itemBefore = $pdo->query("SELECT * FROM sale_items WHERE sale_item_id = 8")->fetch();
$receiptBefore = $pdo->query("SELECT * FROM sale_receipt_details WHERE sale_id = 8")->fetch();
$refundsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM cash_refunds")->fetchColumn();
$refundItemsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM cash_refund_items")->fetchColumn();

// Establish historical costs on Sale 8
$service->establishHistoricalCosts([8]);

$saleAfter = $pdo->query("SELECT * FROM sales WHERE sale_id = 8")->fetch();
$itemAfter = $pdo->query("SELECT * FROM sale_items WHERE sale_item_id = 8")->fetch();
$receiptAfter = $pdo->query("SELECT * FROM sale_receipt_details WHERE sale_id = 8")->fetch();
$refundsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM cash_refunds")->fetchColumn();
$refundItemsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM cash_refund_items")->fetchColumn();

$assert($saleAfter['total_amount'] === $saleBefore['total_amount'], 'Operational sale total_amount untouched');
$assert($saleAfter['gross_amount'] === $saleBefore['gross_amount'], 'Operational sale gross_amount untouched');
$assert($saleAfter['discount_amount'] === $saleBefore['discount_amount'], 'Operational sale discount_amount untouched');
$assert($itemAfter['subtotal'] === $itemBefore['subtotal'], 'Sale item subtotal untouched');
$assert($itemAfter['unit_price'] === $itemBefore['unit_price'], 'Sale item unit_price untouched');
$assert($receiptAfter['details_json'] === $receiptBefore['details_json'], 'Immutable Sale Receipts untouched');
$assert($refundsCountAfter === $refundsCountBefore, 'Append-only Cash Refund rows untouched');
$assert($refundItemsCountAfter === $refundItemsCountBefore, 'Cash Refund item rows untouched');

// =========================================================================
// Test 9: Report Query Recovery Trigger & Export Formatting
// =========================================================================
echo "\n--- Test 9: Report Query Recovery & Export Formatting ---\n";

// Add unestablished legacy sale 9 on 2026-10-14
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, gross_amount, total_amount, sale_date)
            VALUES (9, 2, 80.00, 80.00, '2026-10-14 15:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (9, 9, 2, 4, 20.00, 80.00)");
// Unallocated (0 rows in sale_item_batches)

// Run report with recover_historical = true
$reportResult = $service->runReport([
    'from' => '2026-10-14',
    'to' => '2026-10-14',
    'store_id' => 1,
    'recover_historical' => true,
]);

$assert($reportResult['metrics']['Status'] === 'Estimated', 'Report recovered unallocated line as Estimated');
$assert($reportResult['metrics']['Net Sales'] === '80.00', 'Report Net Sales metric is 80.00');
$assert($reportResult['metrics']['Net Cost'] === '40.00', 'Report Net Cost metric is 40.00 (4 * 10.00)');
$assert($reportResult['metrics']['Gross Profit'] === '40.00', 'Report Gross Profit metric is 40.00');
$assert($reportResult['metrics']['Margin'] === '-', 'Report Margin is - (withheld for estimated)');

$row9 = $reportResult['rows'][0];
$assert($row9['Units Sold'] === 4, 'Semantic alias Units Sold is 4');
$assert($row9['Revenue'] === '80.00', 'Semantic alias Revenue is 80.00');
$assert($row9['Cost of Sales'] === '40.00', 'Semantic alias Cost of Sales is 40.00');
$assert($row9['Status'] === 'Estimated', 'Formatted Status is Estimated');

// RecoverHistoricalCosts alias check
$aliasResult = $service->recoverHistoricalCosts([9]);
$assert(is_array($aliasResult), 'recoverHistoricalCosts alias returns array result');

echo "\n============================================================\n";
if (!empty($failures)) {
    echo "FAILED: " . count($failures) . " assertions failed.\n";
    exit(1);
}
echo "All Ticket #230 gross profit historical cost tests passed cleanly!\n";
