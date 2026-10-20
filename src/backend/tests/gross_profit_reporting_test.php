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
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/CashRefundService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

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

echo "Starting Ticket #229 Gross Profit Reconciled Reporting Tests...\n";

// Setup SQLite Memory Database
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
$pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'Snacks'), (2, 'Beverages'), (3, 'Canned Goods')");

$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)");
$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin'), (4, 'super_admin')");

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE,
    role_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active'
)");

$pdo->exec("CREATE TABLE user_roles (
    user_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    is_primary INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, role_id)
)");

$insertUser = static function (string $name, string $username, string $role) use ($pdo): int {
    $roleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = '{$role}'")->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO users (full_name, username, role_id, branch_id, status) VALUES (?, ?, ?, 1, ?)');
    $stmt->execute([$name, $username, $roleId, 'active']);
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, 1)')->execute([$userId, $roleId]);
    return $userId;
};

$cashierId = $insertUser('Carl Cashier', 'cashier1', 'cashier');
$managerId = $insertUser('Mina Manager', 'manager1', 'inventory_manager');
$adminId = $insertUser('Alice Admin', 'admin1', 'admin');
$superId = $insertUser('Sam Super', 'super1', 'super_admin');

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    sku TEXT NOT NULL,
    barcode TEXT NOT NULL DEFAULT '',
    product_name TEXT NOT NULL,
    category_id INTEGER NOT NULL DEFAULT 1,
    unit_price REAL NOT NULL DEFAULT 10.0,
    cost_price REAL NOT NULL DEFAULT 0.0,
    status TEXT NOT NULL DEFAULT 'active'
)");

$pdo->exec("CREATE TABLE product_batches (
    batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
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
    cashier_id INTEGER NOT NULL,
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
    batch_id INTEGER NOT NULL,
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
    cashier_id INTEGER NOT NULL,
    refund_amount REAL NOT NULL DEFAULT 0.00,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    reason TEXT NOT NULL,
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

// =========================================================================
// Test 1: Reconciled Gross Profit & Margin with Discounts and Refunds
// =========================================================================
echo "\n--- Test 1: Reconciled Profit, Discounts, Refunds, and 30% Margin ---\n";
// Demonstrate PHP 10,400 sales less PHP 200 discounts and PHP 200 refunds gives PHP 10,000 Net Sales;
// PHP 7,000 net cost gives PHP 3,000 profit and 30% margin. Rows and exports reconcile.

$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (101, 1, 'SKU-RECON', 'BC-RECON', 'Reconciled Bulk Snack', 1, 104.00, 70.00)");

$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, cost_price, date_received)
            VALUES (1001, 101, 'BATCH-1001', 100, 0, 70.00, '2026-10-01')");

// Sale of 100 units: Gross 10,400.00, Discount 200.00, Paid 10,200.00
$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, discount_amount, discount_type, discount_value, sale_date)
            VALUES (1, 1, {$cashierId}, 10400.00, 10200.00, 200.00, 'fixed', 200.00, '2026-10-10 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (1, 1, 101, 100, 104.00, 10400.00)");
// Cost allocated: 100 units @ 70.00 = 7,000.00 cost
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status, cost_source)
            VALUES (1, 1, 1001, 100, 70.00, 70.00, 'recorded', 'batch')");

// Refund of 2 units: customer gets 200.00 refund (settled net refund). Damaged disposition so cost is not reversed.
$pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, created_at)
            VALUES (1, 1, 1, {$cashierId}, 200.00, 'cash', 'damaged', '2026-10-10 14:00:00')");
$pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
            VALUES (1, 1, 1, 101, 2, 104.00, 200.00, 'damaged')");

$service = new GrossProfitService($pdo);
$res1 = $service->calculate([
    'from' => '2026-10-10',
    'to' => '2026-10-10',
    'store_id' => 1,
]);

$sum1 = $res1['summary'];
$assert($sum1['total_gross_sales'] === 10400.00, 'Gross sales is 10,400.00');
$assert($sum1['total_discounts'] === 200.00, 'Total discount is 200.00');
$assert($sum1['total_refunds'] === 200.00, 'Total refunds is 200.00');
$assert($sum1['total_net_sales'] === 10000.00, 'Net Sales is exactly 10,000.00');
$assert($sum1['total_net_cost'] === 7000.00, 'Net cost is exactly 7,000.00');
$assert($sum1['total_gross_profit'] === 3000.00, 'Gross profit is exactly 3,000.00');
$assert($sum1['total_margin'] === 30.00, 'Margin is exactly 30%');
$assert($sum1['status'] === 'recorded', 'Status is recorded with complete evidence');

// Verify runReport formatting and reconciliation with rows
$_SESSION['role'] = 'admin';
$reportRes = $service->runReport([
    'from' => '2026-10-10',
    'to' => '2026-10-10',
    'store_id' => 1,
]);
$row1 = $reportRes['rows'][0];
$assert($row1['Net Sales'] === '10,000.00', 'Formatted Net Sales in row is 10,000.00');
$assert($row1['Net Cost'] === '7,000.00', 'Formatted Net Cost in row is 7,000.00');
$assert($row1['Gross Profit'] === '3,000.00', 'Formatted Gross Profit in row is 3,000.00');
$assert($row1['Margin'] === '30.00%', 'Formatted Margin in row is 30.00%');
$assert($row1['Status'] === 'Recorded', 'Row Status is Recorded');

// =========================================================================
// Test 2: Monday-Friday Restockable vs Damaged Lifecycle
// =========================================================================
echo "\n--- Test 2: Monday-Friday Restockable vs Damaged Cases ---\n";
// Monday sale: 1 unit @ 20 sale, 15 cost -> profit PHP 5
// Friday Restockable: adjustment minus PHP 5
// Friday Damaged: adjustment minus PHP 20

// Restockable Product
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (102, 1, 'SKU-RESTOCK', 'BC-RESTOCK', 'Monday Restockable Item', 1, 20.00, 15.00)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, cost_price, date_received)
            VALUES (1002, 102, 'BATCH-1002', 10, 0, 15.00, '2026-10-01')");

// Monday Sale (2026-10-12 is Monday)
$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, discount_amount, sale_date)
            VALUES (2, 1, {$cashierId}, 20.00, 20.00, 0.00, '2026-10-12 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (2, 2, 102, 1, 20.00, 20.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status, cost_source)
            VALUES (2, 2, 1002, 1, 15.00, 15.00, 'recorded', 'batch')");

// Damaged Product
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (103, 1, 'SKU-DAMAGED', 'BC-DAMAGED', 'Monday Damaged Item', 1, 20.00, 15.00)");
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, cost_price, date_received)
            VALUES (1003, 103, 'BATCH-1003', 10, 0, 15.00, '2026-10-01')");

// Monday Sale for Damaged product
$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, discount_amount, sale_date)
            VALUES (3, 1, {$cashierId}, 20.00, 20.00, 0.00, '2026-10-12 11:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (3, 3, 103, 1, 20.00, 20.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status, cost_source)
            VALUES (3, 3, 1003, 1, 15.00, 15.00, 'recorded', 'batch')");

// Check Monday profit for Product 102
$monRes = $service->calculate(['from' => '2026-10-12', 'to' => '2026-10-12', 'product_id' => 102, 'store_id' => 1]);
$assert($monRes['summary']['total_net_sales'] === 20.00, 'Monday Product 102 Net Sales is 20.00');
$assert($monRes['summary']['total_net_cost'] === 15.00, 'Monday Product 102 Cost is 15.00');
$assert($monRes['summary']['total_gross_profit'] === 5.00, 'Monday Product 102 Profit is PHP 5.00');

// Friday Refunds (2026-10-16 is Friday)
// Refund Product 102 as Restockable
$pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, created_at)
            VALUES (2, 2, 1, {$cashierId}, 20.00, 'cash', 'wrong_item', '2026-10-16 14:00:00')");
$pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
            VALUES (2, 2, 2, 102, 1, 20.00, 20.00, 'restockable')");

// Refund Product 103 as Damaged
$pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, created_at)
            VALUES (3, 3, 1, {$cashierId}, 20.00, 'cash', 'damaged', '2026-10-16 15:00:00')");
$pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
            VALUES (3, 3, 3, 103, 1, 20.00, 20.00, 'damaged')");

// Check Friday report for Restockable Product 102
$friRestock = $service->calculate(['from' => '2026-10-16', 'to' => '2026-10-16', 'product_id' => 102, 'store_id' => 1]);
$assert($friRestock['summary']['total_net_sales'] === -20.00, 'Friday Restockable Net Sales is -20.00');
$assert($friRestock['summary']['total_net_cost'] === -15.00, 'Friday Restockable Cost reversed is -15.00');
$assert($friRestock['summary']['total_gross_profit'] === -5.00, 'Friday Restockable adjustment is minus PHP 5 (-5.00)');

// Check Friday report for Damaged Product 103
$friDamaged = $service->calculate(['from' => '2026-10-16', 'to' => '2026-10-16', 'product_id' => 103, 'store_id' => 1]);
$assert($friDamaged['summary']['total_net_sales'] === -20.00, 'Friday Damaged Net Sales is -20.00');
$assert($friDamaged['summary']['total_net_cost'] === 0.00, 'Friday Damaged Cost is 0.00 (not reversed)');
$assert($friDamaged['summary']['total_gross_profit'] === -20.00, 'Friday Damaged adjustment is minus PHP 20 (-20.00)');

// Combined Monday-Friday week for both products
$weekRestock = $service->calculate(['from' => '2026-10-12', 'to' => '2026-10-16', 'product_id' => 102, 'store_id' => 1]);
$assert($weekRestock['summary']['total_gross_profit'] === 0.00, 'Full week Restockable net profit is exactly 0.00');

$weekDamaged = $service->calculate(['from' => '2026-10-12', 'to' => '2026-10-16', 'product_id' => 103, 'store_id' => 1]);
$assert($weekDamaged['summary']['total_gross_profit'] === -15.00, 'Full week Damaged net profit is -15.00 (loss of stock cost)');

// =========================================================================
// Test 3: Partial, Repeated, and Mixed-Cost Returns
// =========================================================================
echo "\n--- Test 3: Partial, Repeated, and Mixed-Cost Returns ---\n";
// Sale of 5 units: 3 from batch @ 10.00, 2 from batch @ 15.00. Total cost = 60.00.
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (104, 1, 'SKU-MIX', 'BC-MIX', 'Mixed Batch Item', 1, 30.00, 10.00)");

$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, discount_amount, sale_date)
            VALUES (4, 1, {$cashierId}, 150.00, 150.00, 0.00, '2026-10-13 09:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (4, 4, 104, 5, 30.00, 150.00)");
// Batch allocations: 3 @ 10.00, 2 @ 15.00
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status)
            VALUES (4, 4, 1004, 3, 10.00, 10.00, 'recorded')");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status)
            VALUES (5, 4, 1005, 2, 15.00, 15.00, 'recorded')");

// Refund 1: 2 units Restockable (consumes first 2 units of batch 1004 @ 10.00 = 20.00 cost reversed)
$pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, created_at)
            VALUES (4, 4, 1, {$cashierId}, 60.00, 'cash', 'customer_return', '2026-10-13 12:00:00')");
$pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
            VALUES (4, 4, 4, 104, 2, 30.00, 60.00, 'restockable')");

// Refund 2: 2 units Restockable (consumes 1 remaining of batch 1004 @ 10.00 + 1 of batch 1005 @ 15.00 = 25.00 cost reversed)
$pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, created_at)
            VALUES (5, 4, 1, {$cashierId}, 60.00, 'cash', 'customer_return', '2026-10-13 14:00:00')");
$pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
            VALUES (5, 5, 4, 104, 2, 30.00, 60.00, 'restockable')");

// Refund 3: 1 unit Damaged (consumes last unit of batch 1005 @ 15.00, but damaged so 0.00 cost reversed)
$pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, created_at)
            VALUES (6, 4, 1, {$cashierId}, 30.00, 'cash', 'damaged', '2026-10-13 16:00:00')");
$pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
            VALUES (6, 6, 4, 104, 1, 30.00, 30.00, 'damaged')");

// Test Refund 2 in isolation
$resRefund2 = $service->calculate([
    'from' => '2026-10-13',
    'to' => '2026-10-13',
    'product_id' => 104,
    'store_id' => 1,
]);
// Total Net Sales: 150 - 60 - 60 - 30 = 0.00
// Total Net Cost: 60 - 20 (rf1) - 25 (rf2) - 0 (rf3) = 15.00
$assert($resRefund2['summary']['total_net_sales'] === 0.00, 'All 5 units refunded gives Net Sales 0.00');
$assert($resRefund2['summary']['total_net_cost'] === 15.00, 'Cumulative cost reversal accurately left 15.00 for the damaged unit');
$assert($resRefund2['summary']['total_gross_profit'] === -15.00, 'Gross profit is -15.00');

// =========================================================================
// Test 4: Refund-Only Periods
// =========================================================================
echo "\n--- Test 4: Refund-Only Period Preservation ---\n";
// Create a separate refund on Saturday for Product 101 (sold on Oct 10)
$pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, created_at)
            VALUES (7, 1, 1, {$cashierId}, 100.00, 'cash', 'customer_return', '2026-10-17 10:00:00')");
$pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
            VALUES (7, 7, 1, 101, 1, 104.00, 100.00, 'restockable')");

$satRes = $service->calculate(['from' => '2026-10-17', 'to' => '2026-10-17', 'product_id' => 101, 'store_id' => 1]);
$assert($satRes['summary']['total_units'] === -1, 'Net units is -1 in refund-only period');
$assert($satRes['summary']['total_net_sales'] === -100.00, 'Net Sales is -100.00');
$assert($satRes['summary']['total_net_cost'] === -70.00, 'Negative cost is preserved (-70.00)');
$assert($satRes['summary']['total_gross_profit'] === -30.00, 'Signed profit is preserved (-30.00)');
$assert($satRes['summary']['total_margin'] === null, 'Margin is withheld / null when net sales <= 0');

// =========================================================================
// Test 5: Uncertainty Precedence, Affected Lines & Unsupported Costs
// =========================================================================
echo "\n--- Test 5: Uncertainty Precedence & Known-Cost Subtotal ---\n";
// Product 105: Recorded cost
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (105, 1, 'SKU-REC', 'BC-REC', 'Recorded Snack', 1, 50.00, 30.00)");
// Product 106: Estimated cost
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (106, 1, 'SKU-EST', 'BC-EST', 'Estimated Snack', 1, 40.00, 20.00)");
// Product 107: Missing cost (unsupported legacy)
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (107, 1, 'SKU-MISS', 'BC-MISS', 'Missing Cost Snack', 1, 60.00, 45.00)");

// Sale on 2026-10-14 containing all 3
$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, discount_amount, sale_date)
            VALUES (5, 1, {$cashierId}, 150.00, 150.00, 0.00, '2026-10-14 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (5, 5, 105, 1, 50.00, 50.00)");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (6, 5, 106, 1, 40.00, 40.00)");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (7, 5, 107, 1, 60.00, 60.00)");

$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status)
            VALUES (6, 5, 1005, 1, 30.00, 30.00, 'recorded')");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status)
            VALUES (7, 6, 1006, 1, 20.00, 20.00, 'estimated')");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status)
            VALUES (8, 7, 1007, 1, NULL, NULL, 'missing')");

// Test Estimated only (product 106)
$estOnly = $service->calculate(['from' => '2026-10-14', 'to' => '2026-10-14', 'product_id' => 106, 'store_id' => 1]);
$assert($estOnly['summary']['status'] === 'estimated', 'Estimated status when required cost is estimated and none missing');
$assert($estOnly['summary']['total_net_cost'] === 20.00, 'Estimated cost is numeric 20.00');
$assert($estOnly['summary']['total_margin'] === null, 'Margin is withheld for estimated costs');

// Test Combined Oct 14 report (has recorded, estimated, and missing)
$mixRes = $service->calculate(['from' => '2026-10-14', 'to' => '2026-10-14', 'store_id' => 1]);
$assert($mixRes['summary']['status'] === 'incomplete', 'Incomplete takes precedence over estimated and recorded');
$assert($mixRes['summary']['affected_lines_count'] === 1, 'Affected lines count is exactly 1');
$assert($mixRes['summary']['total_net_cost'] === null, 'Incomplete totals withhold full numeric cost');
$assert($mixRes['summary']['total_gross_profit'] === null, 'Incomplete totals withhold full numeric profit');
$assert($mixRes['summary']['total_margin'] === null, 'Incomplete totals withhold full margin');
$assert($mixRes['summary']['total_net_sales'] === 150.00, 'Total Net Sales is preserved at 150.00');

// Known-cost subtotal excludes unknown-cost line (Product 107 with 60.00 revenue and unknown cost)
$assert($mixRes['summary']['known_net_sales'] === 90.00, 'Known-cost Net Sales is 90.00 (50 + 40)');
$assert($mixRes['summary']['known_net_cost'] === 50.00, 'Known-cost Net Cost is 50.00 (30 + 20)');
$assert($mixRes['summary']['known_gross_profit'] === 40.00, 'Known-cost profit is 40.00 (90 - 50)');

// Ensure unsupported older costs never substitute mutable catalog price
$missingItemRow = null;
foreach ($mixRes['rows'] as $r) {
    if ($r['product_id'] === 107) {
        $missingItemRow = $r;
        break;
    }
}
$assert($missingItemRow !== null, 'Product 107 is in report rows');
$assert($missingItemRow['net_cost'] === null, 'Unsupported cost remains null (never substituted from catalog cost_price 45.00)');
$assert($missingItemRow['status'] === 'incomplete', 'Product 107 status is incomplete');

// =========================================================================
// Test 6: Empty, Loss, and Nonpositive-Sales States
// =========================================================================
echo "\n--- Test 6: Empty, Loss, and Nonpositive-Sales States ---\n";
// Empty activity
$emptyRes = $service->calculate(['from' => '2026-01-01', 'to' => '2026-01-01', 'store_id' => 1]);
$assert($emptyRes['summary']['total_net_sales'] === 0.00, 'Empty Net Sales is 0.00');
$assert($emptyRes['summary']['total_net_cost'] === 0.00, 'Empty Net Cost is 0.00');
$assert($emptyRes['summary']['total_gross_profit'] === 0.00, 'Empty Gross Profit is 0.00');
$assert($emptyRes['summary']['total_margin'] === null, 'Empty activity has no meaningful margin (null)');
$assert(empty($emptyRes['rows']), 'Empty activity has empty rows');

// Loss state (Net Sales > 0, but cost exceeds sales)
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (108, 1, 'SKU-LOSS', 'BC-LOSS', 'Loss Item', 1, 10.00, 15.00)");
$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, discount_amount, sale_date)
            VALUES (6, 1, {$cashierId}, 10.00, 10.00, 0.00, '2026-10-15 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (8, 6, 108, 1, 10.00, 10.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status)
            VALUES (9, 8, 1008, 1, 15.00, 15.00, 'recorded')");

$lossRes = $service->calculate(['from' => '2026-10-15', 'to' => '2026-10-15', 'product_id' => 108, 'store_id' => 1]);
$assert($lossRes['summary']['total_net_sales'] === 10.00, 'Loss sale Net Sales is 10.00');
$assert($lossRes['summary']['total_net_cost'] === 15.00, 'Loss sale Net Cost is 15.00');
$assert($lossRes['summary']['total_gross_profit'] === -5.00, 'Loss sale Gross Profit is -5.00');
$assert($lossRes['summary']['total_margin'] === -50.00, 'Loss sale Margin is -50.00% (signed percentage preserved)');

// =========================================================================
// Test 7: Manila Date Boundaries and Invalid Ranges
// =========================================================================
echo "\n--- Test 7: Date Boundaries and Invalid Ranges ---\n";
// Midnight boundary test: sale at 23:59:59 included in 2026-10-11, sale at 00:00:00 excluded
$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, discount_amount, sale_date)
            VALUES (7, 1, {$cashierId}, 20.00, 20.00, 0.00, '2026-10-11 23:59:59')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (9, 7, 101, 1, 20.00, 20.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status)
            VALUES (10, 9, 1001, 1, 10.00, 10.00, 'recorded')");

$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, discount_amount, sale_date)
            VALUES (8, 1, {$cashierId}, 20.00, 20.00, 0.00, '2026-10-12 00:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (10, 8, 101, 1, 20.00, 20.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status)
            VALUES (11, 10, 1001, 1, 10.00, 10.00, 'recorded')");

$boundaryRes = $service->calculate(['from' => '2026-10-11', 'to' => '2026-10-11', 'product_id' => 101, 'store_id' => 1]);
$assert($boundaryRes['summary']['total_units'] === 1, 'Sale at 23:59:59 is included in day; sale at 00:00:00 is excluded');

// Invalid date formats
$throws(static fn() => $service->calculate(['from' => '2026-13-45', 'to' => '2026-10-11', 'store_id' => 1]),
    InvalidArgumentException::class, 'Invalid calendar date throws InvalidArgumentException');
$throws(static fn() => $service->calculate(['from' => 'not-a-date', 'to' => '2026-10-11', 'store_id' => 1]),
    InvalidArgumentException::class, 'Malformed date throws InvalidArgumentException');

// Inverted range
$throws(static fn() => $service->calculate(['from' => '2026-10-15', 'to' => '2026-10-10', 'store_id' => 1]),
    InvalidArgumentException::class, 'from > to throws InvalidArgumentException');

// =========================================================================
// Test 8: Workspace Authorization
// =========================================================================
echo "\n--- Test 8: Workspace Authorization ---\n";
// Admin workspace allowed
GrossProfitService::enforceAdministratorWorkspace('admin');
$assert(true, 'Admin workspace allowed');

// Cashier denied
$throws(static fn() => GrossProfitService::enforceAdministratorWorkspace('cashier'),
    DomainException::class, 'Cashier workspace denied');

// Inventory Manager denied
$throws(static fn() => GrossProfitService::enforceAdministratorWorkspace('inventory_manager'),
    DomainException::class, 'Inventory Manager workspace denied');

// Super Admin workspace denied
$throws(static fn() => GrossProfitService::enforceAdministratorWorkspace('super_admin'),
    DomainException::class, 'Super Admin workspace denied');

// Combined privileges check: multi-role user in non-admin workspace is denied
$_SESSION['roles'] = ['admin', 'inventory_manager', 'super_admin'];
$_SESSION['role'] = 'inventory_manager';
$throws(static fn() => GrossProfitService::enforceAdministratorWorkspace($_SESSION['role']),
    DomainException::class, 'Assigned admin role in non-admin active workspace is denied');

// Ordinary reports remain accessible to other roles
require_once __DIR__ . '/../includes/auth.php';
$policy = new App\Authorization\RoleCapabilityPolicy();
$assert($policy->allows('inventory_manager', App\Authorization\RoleCapabilityPolicy::VIEW_STORE_REPORTS),
    'Inventory manager can still view store reports');
$assert($policy->allows('super_admin', App\Authorization\RoleCapabilityPolicy::VIEW_STORE_REPORTS),
    'Super administrator can still view store reports');

// =========================================================================
// Test 9: Partially allocated pack line is flagged as missing cost
// =========================================================================
echo "\n--- Test 9: Partially allocated pack line ---\n";
$pdo->exec('ALTER TABLE sale_items ADD COLUMN base_unit_quantity INTEGER');
$pdo->exec("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, unit_price, cost_price)
            VALUES (108, 1, 'SKU-PACK-CHEAP', 'BC-PACK-CHEAP', 'Partly Allocated Pack', 1, 100.00, 10.00)");
$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, discount_amount, sale_date)
            VALUES (6, 1, {$cashierId}, 100.00, 100.00, 0.00, '2026-10-15 09:00:00')");
// One pack line of 12 Base Units but only 6 Base Units have a batch allocation with cost.
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, base_unit_quantity, unit_price, subtotal)
            VALUES (8, 6, 108, 1, 12, 100.00, 100.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_price, cost_status)
            VALUES (9, 8, 1008, 6, 10.00, 10.00, 'recorded')");
$partialPack = $service->calculate(['from' => '2026-10-15', 'to' => '2026-10-15', 'product_id' => 108, 'store_id' => 1]);
$assert($partialPack['summary']['status'] === 'incomplete', 'A partially allocated pack line is reported incomplete, not recorded');
$assert($partialPack['summary']['affected_lines_count'] === 1, 'The partially allocated pack line is counted as affected');
$assert($partialPack['summary']['total_units'] === 12, 'Pack demand contributes its 12 Base Units once');

echo "\n============================================================\n";
if (empty($failures)) {
    echo "All Ticket #229 gross profit reporting tests passed cleanly!\n";
} else {
    echo "Failures encountered:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}
