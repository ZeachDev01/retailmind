<?php
// Contract and integration tests for Ticket #228:
// Gross Profit: preserve sold-stock costs during checkout.
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
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/CashRefundService.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/HeldSaleService.php';
require_once __DIR__ . '/../app/Services/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Store/StoreWriteGate.php';
require_once __DIR__ . '/../app/Services/StockConflictException.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\PhilippineTime;
use App\Services\CashierShiftService;
use App\Services\HeldSaleService;

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

echo "Starting Ticket #228 sold-stock cost preservation tests...\n";

// --- Database Setup ---
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

$pdo->exec("CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)");
$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin')");

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NULL,
    role_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active',
    must_change_password INTEGER NOT NULL DEFAULT 0
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

$pdo->exec("CREATE TABLE registers (
    register_id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active',
    paper_width_mm INTEGER NOT NULL DEFAULT 80
)");
$pdo->exec("INSERT INTO registers (register_id, name, branch_id) VALUES (1, 'Front Register', 1)");

$pdo->exec("CREATE TABLE cashier_shifts (
    shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cashier_id INTEGER NOT NULL,
    register_id INTEGER NOT NULL,
    opening_cash REAL NOT NULL DEFAULT 0,
    closing_cash REAL NULL,
    actual_cash REAL NULL,
    discrepancy REAL NULL,
    status TEXT NOT NULL DEFAULT 'open',
    locked_at TEXT NULL,
    opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    branch_id INTEGER NOT NULL DEFAULT 1
)");

$pdo->exec("CREATE TABLE cash_drawer_movements (
    drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
    shift_id INTEGER NOT NULL,
    movement_type TEXT NOT NULL,
    amount REAL NOT NULL,
    reason TEXT NULL,
    recorded_by INTEGER NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    sku TEXT NOT NULL,
    barcode TEXT NOT NULL DEFAULT '',
    case_barcode TEXT NOT NULL DEFAULT '',
    product_name TEXT NOT NULL,
    brand TEXT NOT NULL DEFAULT '',
    category_id INTEGER NOT NULL DEFAULT 1,
    unit_price REAL NOT NULL DEFAULT 10.0,
    cost_price REAL NOT NULL DEFAULT 0.0,
    reorder_level INTEGER NOT NULL DEFAULT 5,
    safety_stock INTEGER NOT NULL DEFAULT 2,
    minimum_order_quantity INTEGER NOT NULL DEFAULT 1,
    units_per_package INTEGER NOT NULL DEFAULT 1,
    base_unit TEXT NOT NULL DEFAULT 'piece',
    receiving_unit TEXT NOT NULL DEFAULT 'package',
    quantity_sold INTEGER NOT NULL DEFAULT 0,
    quantity_purchased INTEGER NOT NULL DEFAULT 0,
    supplier TEXT NULL,
    preferred_supplier TEXT NULL,
    supplier_lead_time_days INTEGER NOT NULL DEFAULT 7,
    status TEXT NOT NULL DEFAULT 'active',
    created_by INTEGER NULL
)");

$pdo->exec("CREATE TABLE inventory (
    product_id INTEGER PRIMARY KEY,
    quantity_on_hand INTEGER NOT NULL DEFAULT 0
)");

$pdo->exec("CREATE TABLE stock_receiving (
    receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NULL,
    product_id INTEGER NOT NULL,
    received_qty INTEGER NOT NULL,
    received_packages INTEGER NOT NULL DEFAULT 0,
    units_per_package_used INTEGER NOT NULL DEFAULT 1,
    accepted_qty INTEGER NOT NULL DEFAULT 0,
    damaged_qty INTEGER NOT NULL DEFAULT 0,
    cost_price REAL NULL,
    total_cost REAL NOT NULL DEFAULT 0.0,
    received_by INTEGER NOT NULL,
    received_at TEXT DEFAULT CURRENT_TIMESTAMP,
    supplier TEXT NULL,
    invoice_number TEXT NULL,
    batch_number TEXT NULL,
    expiration_date TEXT NULL,
    discrepancy_type TEXT NOT NULL DEFAULT 'none',
    discrepancy_qty INTEGER NOT NULL DEFAULT 0,
    discrepancy_notes TEXT NULL,
    notes TEXT NULL,
    pullout_id INTEGER NULL
)");

$pdo->exec("CREATE TABLE product_batches (
    batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    receiving_id INTEGER NULL,
    batch_number TEXT NULL,
    quantity INTEGER NOT NULL,
    remaining_quantity INTEGER NOT NULL,
    expiration_date TEXT NULL,
    date_received TEXT NOT NULL,
    supplier TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE sales (
    sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
    shift_id INTEGER NOT NULL,
    cashier_id INTEGER NOT NULL,
    gross_amount REAL NULL,
    total_amount REAL NOT NULL DEFAULT 0,
    discount_amount REAL NOT NULL DEFAULT 0,
    discount_type TEXT NOT NULL DEFAULT 'none',
    discount_value REAL NOT NULL DEFAULT 0,
    discount_reason TEXT NULL,
    discount_authorized_by INTEGER NULL,
    promotion_id INTEGER NULL,
    promotion_name TEXT NULL,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    cash_received REAL NULL,
    change_due REAL NULL,
    payment_reference TEXT NULL,
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
    quantity INTEGER NOT NULL
)");

$pdo->exec("CREATE TABLE stock_movements (
    stock_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    change_qty INTEGER NOT NULL,
    reason TEXT NULL,
    moved_by INTEGER NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE fiscal_periods (
    period_id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_name TEXT NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open'
)");
$pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('Current Month', '2026-01-01', '2026-12-31', 'open')");

$pdo->exec("CREATE TABLE fiscal_period_locks (
    lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_id INTEGER NOT NULL,
    table_name TEXT NULL
)");

$pdo->exec("CREATE TABLE activity_log (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    action TEXT NOT NULL,
    module TEXT NULL,
    category TEXT NULL,
    record_id INTEGER NULL,
    previous_value TEXT NULL,
    new_value TEXT NULL,
    metadata TEXT NULL,
    ip_address TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE checkout_attempts (
    cashier_id INTEGER NOT NULL,
    attempt_id TEXT PRIMARY KEY,
    request_hash TEXT NOT NULL,
    sale_id INTEGER NOT NULL,
    result_json TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE notifications (
    notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    title TEXT NULL,
    message TEXT NULL,
    reference_id INTEGER NULL,
    reference_type TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE store_write_gate (
    gate_key TEXT PRIMARY KEY,
    paused_at TEXT NULL,
    paused_by TEXT NULL
)");

$pdo->exec("CREATE TABLE sale_receipt_details (
    receipt_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    details_json TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE held_sales (
    held_sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cashier_id INTEGER NOT NULL,
    shift_id INTEGER NULL,
    reference_no TEXT NOT NULL UNIQUE,
    customer_label TEXT NULL,
    cart_json TEXT NOT NULL,
    item_count INTEGER NOT NULL DEFAULT 0,
    total_amount REAL NOT NULL DEFAULT 0.00,
    status TEXT NOT NULL DEFAULT 'held',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    expires_at TEXT NULL,
    resolved_at TEXT NULL,
    sale_id INTEGER NULL,
    discard_reason TEXT NULL,
    discard_note TEXT NULL
)");

// Insert a pre-existing historical sale before migration to test backward compatibility & non-fabrication
$pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, gross_amount, total_amount, payment_method) VALUES (1, 1, {$cashierId}, 50.00, 50.00, 'cash')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (1, 1, 999, 2, 25.00, 50.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity) VALUES (1, 1, 999, 2)");

// =========================================================================
// 1. Migration execution and schema upgrade
// =========================================================================
echo "\n--- Test 1: Migration Up & Additive Schema Upgrade ---\n";
$migration = require __DIR__ . '/../database/migrations/202610180005_sold_stock_cost_snapshots.php';
$assert($migration['key'] === '202610180005_sold_stock_cost_snapshots', 'Migration key matches ticket');

($migration['up'])($pdo);

// Verify columns added to product_batches
$pbCols = $pdo->query("PRAGMA table_info(product_batches)")->fetchAll(PDO::FETCH_COLUMN, 1);
$assert(in_array('cost_price', $pbCols, true), 'product_batches has cost_price column');

// Verify columns added to sale_item_batches
$sibCols = $pdo->query("PRAGMA table_info(sale_item_batches)")->fetchAll(PDO::FETCH_COLUMN, 1);
$assert(in_array('unit_cost', $sibCols, true), 'sale_item_batches has unit_cost column');
$assert(in_array('cost_price', $sibCols, true), 'sale_item_batches has cost_price column');
$assert(in_array('cost_status', $sibCols, true), 'sale_item_batches has cost_status column');
$assert(in_array('cost_source', $sibCols, true), 'sale_item_batches has cost_source column');
$assert(in_array('cost_provenance', $sibCols, true), 'sale_item_batches has cost_provenance column');

// Idempotency: running migration again does not throw or duplicate columns
($migration['up'])($pdo);
$assert(true, 'Migration is idempotent when executed multiple times');

// Pre-existing historical sale was not rewritten or fabricated
$preExisting = $pdo->query("SELECT * FROM sale_item_batches WHERE sale_item_batch_id = 1")->fetch();
$assert($preExisting['unit_cost'] === null, 'Pre-existing sale_item_batches unit_cost remains null (not fabricated)');
$assert($preExisting['cost_status'] === 'missing', 'Pre-existing sale_item_batches cost_status is missing');

// Setup services for checkout workflows
$quietNotifications = new class($pdo) extends NotificationService {
    public function checkAndNotifyLowStock(): void {}
    public function checkAndNotifyExpiringStock(int $days = 30): void {}
};
$shiftsService = new CashierShiftService($pdo);
$shiftId = $shiftsService->openShift($cashierId, 'cashier', 1, 1000.00);
$salesWorkflow = new SalesWorkflowService($pdo, null, $quietNotifications);
$heldSaleService = new HeldSaleService($pdo, $shiftsService);

$nowTime = (new DateTimeImmutable('now', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d H:i:s');
$futureExpiry = (new DateTimeImmutable('+30 days', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');

// =========================================================================
// 2. Mixed-Price Batches Checkout: individual batch purchase costs preserved
// =========================================================================
echo "\n--- Test 2: Mixed-Price Batches Checkout ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status)
            VALUES (201, 'SKU-MIXED', 'BC-MIXED', 'Mixed Batch Noodles', 25.00, 12.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (201, 10)");

// Batch 1: 3 units @ 10.00
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2011, 201, 3, 3, 10.00, ?, ?)")->execute([$futureExpiry, $nowTime]);

// Batch 2: 7 units @ 15.00
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2012, 201, 7, 7, 15.00, ?, ?)")->execute([$futureExpiry, $nowTime]);

try {
    $saleRes = $salesWorkflow->checkout(
        [['product_id' => 201, 'qty' => 5]],
        $cashierId,
        'cashier',
        'cash',
        ['cash_received' => 200.00, 'tendered' => 200.00]
    );
    $saleId = $saleRes['sale_id'];
    $assert($saleId > 0, "Sale completed successfully with ID {$saleId}");
} catch (Throwable $e) {
    echo "ERROR IN SALE: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

// Query allocations in sale_item_batches
$allocs = $pdo->query("SELECT sib.* FROM sale_item_batches sib
                       JOIN sale_items si ON si.sale_item_id = sib.sale_item_id
                       WHERE si.sale_id = {$saleId}
                       ORDER BY sib.batch_id ASC")->fetchAll();

$assert(count($allocs) === 2, 'Sale allocated across exactly 2 batches');

$assert((int)$allocs[0]['batch_id'] === 2011, 'Allocation 1 from batch 2011');
$assert((int)$allocs[0]['quantity'] === 3, 'Allocation 1 took 3 units');
$assert((float)$allocs[0]['unit_cost'] === 10.00, 'Allocation 1 preserved individual purchase cost 10.00');
$assert($allocs[0]['cost_status'] === 'recorded', 'Allocation 1 cost_status is recorded');
$assert($allocs[0]['cost_source'] === 'batch', 'Allocation 1 cost_source is batch');

$assert((int)$allocs[1]['batch_id'] === 2012, 'Allocation 2 from batch 2012');
$assert((int)$allocs[1]['quantity'] === 2, 'Allocation 2 took 2 units');
$assert((float)$allocs[1]['unit_cost'] === 15.00, 'Allocation 2 preserved individual purchase cost 15.00');
$assert($allocs[1]['cost_status'] === 'recorded', 'Allocation 2 cost_status is recorded');
$assert($allocs[1]['cost_source'] === 'batch', 'Allocation 2 cost_source is batch');

// Verify inventory and batch balances
$remBatch1 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 2011")->fetchColumn();
$remBatch2 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 2012")->fetchColumn();
$remInv = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 201")->fetchColumn();

$assert($remBatch1 === 0, 'Batch 2011 depleted to 0');
$assert($remBatch2 === 5, 'Batch 2012 decremented to 5 (7 - 2)');
$assert($remInv === 5, 'Inventory decremented to 5 (10 - 5)');

// =========================================================================
// 3. Package-to-Base-Unit Conversion & Receiving Conventions
// =========================================================================
echo "\n--- Test 3: Package-to-Base-Unit Receiving Cost Normalization ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, units_per_package, base_unit, receiving_unit, unit_price, cost_price, status)
            VALUES (202, 'SKU-CAN', 'BC-CAN', 'Canned Sardines', 24, 'can', 'case', 25.00, 15.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (202, 48)");

// Received 2 cases (48 cans). cost_price in stock_receiving is 12.50 per base unit
$pdo->prepare("INSERT INTO stock_receiving (receiving_id, product_id, received_qty, received_packages, units_per_package_used, accepted_qty, cost_price, total_cost, received_by)
               VALUES (501, 202, 48, 2, 24, 48, 12.50, 600.00, ?)")->execute([$managerId]);

// Batch created from this receiving record
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, receiving_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (2021, 202, 501, 48, 48, ?, ?)")->execute([$futureExpiry, $nowTime]);

// Cashier checks out 6 base units (cans)
$saleRes3 = $salesWorkflow->checkout(
    [['product_id' => 202, 'qty' => 6]],
    $cashierId,
    'cashier',
    'cash',
    ['cash_received' => 200.00, 'tendered' => 200.00]
);
$saleId3 = $saleRes3['sale_id'];

$alloc3 = $pdo->query("SELECT sib.* FROM sale_item_batches sib
                       JOIN sale_items si ON si.sale_item_id = sib.sale_item_id
                       WHERE si.sale_id = {$saleId3}")->fetch();

$assert((int)$alloc3['quantity'] === 6, 'Recorded allocation quantity in base units (6 cans)');
$assert((float)$alloc3['unit_cost'] === 12.50, 'Recorded unit_cost normalized to base unit (12.50/can)');
$assert($alloc3['cost_status'] === 'recorded', 'Cost status is recorded');
$assert($alloc3['cost_source'] === 'receiving', 'Cost source is receiving');

// Modify product package config and catalog cost afterwards
$pdo->exec("UPDATE products SET units_per_package = 48, cost_price = 88.00 WHERE product_id = 202");

// Verify historical sale allocation is unaffected by product catalog update
$alloc3After = $pdo->query("SELECT sib.* FROM sale_item_batches sib
                            JOIN sale_items si ON si.sale_item_id = sib.sale_item_id
                            WHERE si.sale_id = {$saleId3}")->fetch();
$assert((float)$alloc3After['unit_cost'] === 12.50, 'Historical unit_cost strictly preserved at 12.50 after catalog changes');
$assert((int)$alloc3After['quantity'] === 6, 'Historical allocation quantity strictly preserved at 6 cans');

// =========================================================================
// 4. Confirmed Zero Costs vs Ambiguous / Missing Costs
// =========================================================================
echo "\n--- Test 4: Confirmed Zero vs Ambiguous / Missing Costs ---\n";

// Scenario 4A: Confirmed Zero Cost (e.g. promo / free stock recorded as cost 0.00)
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status)
            VALUES (203, 'SKU-FREE', 'BC-FREE', 'Free Promo Water', 5.00, 0.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (203, 10)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2031, 203, 10, 10, 0.00, ?, ?)")->execute([$futureExpiry, $nowTime]);

$sale4A = $salesWorkflow->checkout(
    [['product_id' => 203, 'qty' => 2]],
    $cashierId,
    'cashier',
    'cash',
    ['cash_received' => 20.00, 'tendered' => 20.00]
);
$alloc4A = $pdo->query("SELECT sib.* FROM sale_item_batches sib
                        JOIN sale_items si ON si.sale_item_id = sib.sale_item_id
                        WHERE si.sale_id = {$sale4A['sale_id']}")->fetch();

$assert($alloc4A['unit_cost'] !== null && (float)$alloc4A['unit_cost'] === 0.00, 'Confirmed zero cost preserved as 0.00');
$assert($alloc4A['cost_status'] === 'recorded', 'Confirmed zero cost status is recorded');

// Scenario 4B: Ambiguous Default-Zero / Missing Cost (product cost_price is 0.00 and batch has no cost)
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status)
            VALUES (204, 'SKU-UNKNOWN', 'BC-UNKNOWN', 'Unknown Cost Biscuit', 10.00, 0.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (204, 10)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2041, 204, 10, 10, NULL, ?, ?)")->execute([$futureExpiry, $nowTime]);

$sale4B = $salesWorkflow->checkout(
    [['product_id' => 204, 'qty' => 2]],
    $cashierId,
    'cashier',
    'cash',
    ['cash_received' => 30.00, 'tendered' => 30.00]
);
$alloc4B = $pdo->query("SELECT sib.* FROM sale_item_batches sib
                        JOIN sale_items si ON si.sale_item_id = sib.sale_item_id
                        WHERE si.sale_id = {$sale4B['sale_id']}")->fetch();

$assert($alloc4B['unit_cost'] === null, 'Ambiguous zero cost is stored as NULL (uncertainty)');
$assert($alloc4B['cost_status'] === 'missing', 'Ambiguous zero cost status is missing');
$assert($alloc4A['cost_status'] !== $alloc4B['cost_status'], 'Confirmed zero is distinguishable from unknown/missing cost');

// Scenario 4C: Estimated Cost (batch has no cost, but catalog product has cost_price > 0)
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status)
            VALUES (205, 'SKU-EST', 'BC-EST', 'Estimated Cost Snack', 20.00, 14.50, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (205, 10)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2051, 205, 10, 10, NULL, ?, ?)")->execute([$futureExpiry, $nowTime]);

$sale4C = $salesWorkflow->checkout(
    [['product_id' => 205, 'qty' => 3]],
    $cashierId,
    'cashier',
    'cash',
    ['cash_received' => 100.00, 'tendered' => 100.00]
);
$alloc4C = $pdo->query("SELECT sib.* FROM sale_item_batches sib
                        JOIN sale_items si ON si.sale_item_id = sib.sale_item_id
                        WHERE si.sale_id = {$sale4C['sale_id']}")->fetch();

$assert((float)$alloc4C['unit_cost'] === 14.50, 'Estimated cost uses catalog cost 14.50');
$assert($alloc4C['cost_status'] === 'estimated', 'Cost status is estimated');
$assert($alloc4C['cost_source'] === 'product', 'Cost source is product');

// =========================================================================
// 5. Held Sale Completion Uses Costs at Final Completion
// =========================================================================
echo "\n--- Test 5: Held Sale Completion Uses Costs at Final Completion ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status)
            VALUES (206, 'SKU-HELD', 'BC-HELD', 'Held Cookie', 15.00, 8.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (206, 20)");

// Batch 1 (expires soon, cost 7.00)
$expSooner = (new DateTimeImmutable('+2 days', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2061, 206, 2, 2, 7.00, ?, ?)")->execute([$expSooner, $nowTime]);

// Batch 2 (expires later, cost 9.50)
$expLater = (new DateTimeImmutable('+20 days', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2062, 206, 10, 10, 9.50, ?, ?)")->execute([$expLater, $nowTime]);

// Put sale on hold for 2 units
$holdResult = $heldSaleService->hold(
    $cashierId,
    'cashier',
    [206 => ['qty' => 2]],
    'VIP Customer'
);
$heldSaleId = $holdResult['held_sale_id'];
$assert($heldSaleId > 0, "Sale held successfully with held_sale_id {$heldSaleId}");

// While held, no allocations exist in sale_item_batches
$heldAllocsCount = (int)$pdo->query("SELECT COUNT(*) FROM sale_item_batches sib
                                     JOIN product_batches pb ON pb.batch_id = sib.batch_id
                                     WHERE pb.product_id = 206")->fetchColumn();
$assert($heldAllocsCount === 0, 'No batch allocations created while sale is on hold');

// Deplete batch 2061 before held sale is completed (e.g. walk-in sale takes it)
$pdo->exec("UPDATE product_batches SET remaining_quantity = 0 WHERE batch_id = 2061");
$pdo->exec("UPDATE inventory SET quantity_on_hand = 18 WHERE product_id = 206");

// Resume held sale
$heldSaleService->resume($cashierId, 'cashier', $heldSaleId);

// Complete held sale at checkout
$resumedSale = $salesWorkflow->checkout(
    [['product_id' => 206, 'qty' => 2]],
    $cashierId,
    'cashier',
    'cash',
    ['cash_received' => 50.00, 'tendered' => 50.00, 'held_sale_id' => $heldSaleId]
);

$resumedAlloc = $pdo->query("SELECT sib.* FROM sale_item_batches sib
                             JOIN sale_items si ON si.sale_item_id = sib.sale_item_id
                             WHERE si.sale_id = {$resumedSale['sale_id']}")->fetch();

$assert((int)$resumedAlloc['batch_id'] === 2062, 'Held sale completed against batch available at completion (2062)');
$assert((float)$resumedAlloc['unit_cost'] === 9.50, 'Snapshot preserved cost of batch at completion time (9.50)');
$assert($resumedAlloc['cost_status'] === 'recorded', 'Status is recorded');

$heldStatus = $pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$heldSaleId}")->fetchColumn();
$assert($heldStatus === 'completed', 'Held sale marked completed');

// =========================================================================
// 6. Historical Stability After Catalog Price Changes
// =========================================================================
echo "\n--- Test 6: Historical Stability After Price Changes ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status)
            VALUES (207, 'SKU-STABLE', 'BC-STABLE', 'Stable Juice', 50.00, 30.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (207, 10)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2071, 207, 10, 10, 30.00, ?, ?)")->execute([$futureExpiry, $nowTime]);

$sale6 = $salesWorkflow->checkout(
    [['product_id' => 207, 'qty' => 2]],
    $cashierId,
    'cashier',
    'cash',
    ['cash_received' => 100.00, 'tendered' => 100.00]
);

// Supplier updates product prices and costs drastically
$pdo->exec("UPDATE products SET unit_price = 80.00, cost_price = 55.00 WHERE product_id = 207");
$pdo->exec("UPDATE product_batches SET cost_price = 55.00 WHERE batch_id = 2071");

// Verify that the historical record in sale_items and sale_item_batches remains unchanged
$saleItem6 = $pdo->query("SELECT * FROM sale_items WHERE sale_id = {$sale6['sale_id']}")->fetch();
$alloc6 = $pdo->query("SELECT * FROM sale_item_batches WHERE sale_item_id = {$saleItem6['sale_item_id']}")->fetch();

$assert((float)$saleItem6['unit_price'] === 50.00, 'Historical sale_item unit_price remains stable at 50.00');
$assert((float)$alloc6['unit_cost'] === 30.00, 'Historical sale_item_batch unit_cost remains stable at 30.00');
$assert((float)$alloc6['cost_price'] === 30.00, 'Historical sale_item_batch cost_price alias remains stable at 30.00');

// =========================================================================
// 7. Atomic Rollback on Checkout Failure (No Partial Cost Writes)
// =========================================================================
echo "\n--- Test 7: Atomic Rollback on Checkout Failure ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status)
            VALUES (208, 'SKU-ROLLBACK', 'BC-ROLLBACK', 'Rollback Milk', 100.00, 60.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (208, 10)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2081, 208, 10, 10, 60.00, ?, ?)")->execute([$futureExpiry, $nowTime]);

$salesCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
$saleItemsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM sale_items")->fetchColumn();
$allocsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM sale_item_batches")->fetchColumn();
$invBefore = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 208")->fetchColumn();
$batchBefore = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 2081")->fetchColumn();

// Attempt checkout with insufficient cash (50.00 for 100.00 sale)
$throws(
    static fn() => $salesWorkflow->checkout(
        [['product_id' => 208, 'qty' => 1]],
        $cashierId,
        'cashier',
        'cash',
        ['cash_received' => 50.00, 'tendered' => 50.00]
    ),
    RuntimeException::class,
    'Refuses checkout with insufficient cash'
);

$salesCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
$saleItemsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM sale_items")->fetchColumn();
$allocsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM sale_item_batches")->fetchColumn();
$invAfter = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 208")->fetchColumn();
$batchAfter = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 2081")->fetchColumn();

$assert($salesCountAfter === $salesCountBefore, 'No sales rows written on failure');
$assert($saleItemsCountAfter === $saleItemsCountBefore, 'No sale_items rows written on failure');
$assert($allocsCountAfter === $allocsCountBefore, 'No sale_item_batches cost snapshots written on failure');
$assert($invAfter === $invBefore, 'Inventory balance untouched');
$assert($batchAfter === $batchBefore, 'Batch balance untouched');

// =========================================================================
// 8. Existing Behavior Retention (Receipts, Discounts, Stock Movements)
// =========================================================================
echo "\n--- Test 8: Existing Sale Behaviors Preserved ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, status)
            VALUES (209, 'SKU-EXISTING', 'BC-EXISTING', 'Existing Features Bread', 40.00, 20.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (209, 10)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, cost_price, expiration_date, date_received)
               VALUES (2091, 209, 10, 10, 20.00, ?, ?)")->execute([$futureExpiry, $nowTime]);

$sale8 = $salesWorkflow->checkout(
    [['product_id' => 209, 'qty' => 2]],
    $cashierId,
    'cashier',
    'cash',
    [
        'cash_received' => 100.00,
        'tendered' => 100.00,
        'discount_type' => 'fixed',
        'discount_value' => 8.00,
        'discount_amount' => 8.00,
        'discount_reason' => 'Promotion Member'
    ]
);

$saleRow8 = $pdo->query("SELECT * FROM sales WHERE sale_id = {$sale8['sale_id']}")->fetch();
$assert((float)$saleRow8['gross_amount'] === 80.00, 'Sale gross_amount is 80.00');
$assert((float)$saleRow8['total_amount'] === 72.00, 'Sale net total_amount is 72.00 (80 - 8)');
$assert((float)$saleRow8['discount_amount'] === 8.00, 'Discount amount recorded as 8.00');
$assert((float)$saleRow8['change_due'] === 28.00, 'Change due is 28.00 (100 - 72)');

// Sale receipt details saved
$receiptDetails = $pdo->query("SELECT details_json FROM sale_receipt_details WHERE sale_id = {$sale8['sale_id']}")->fetchColumn();
$assert($receiptDetails !== false && strlen($receiptDetails) > 0, 'Receipt details preserved in sale_receipt_details');

// Stock movement created
$movement = $pdo->query("SELECT * FROM stock_movements WHERE product_id = 209 AND reason = 'sale'")->fetch();
$assert((int)$movement['change_qty'] === -2, 'Stock movement change_qty is -2');
$assert((int)$movement['moved_by'] === $cashierId, 'Stock movement moved_by is cashier');

echo "\n============================================================\n";
if (!empty($failures)) {
    echo "FAILED: " . count($failures) . " assertions failed.\n";
    exit(1);
}
echo "All Ticket #228 sold-stock cost preservation tests passed cleanly!\n";
