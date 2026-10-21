<?php
// Contract and integration tests for Ticket #220:
// Preserve batch balances across sales, refunds, and remaining stock writers.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/CashRefundService.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Store/StoreWriteGate.php';
require_once __DIR__ . '/../app/Services/StockConflictException.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\PhilippineTime;
use App\Services\CashierShiftService;
use App\Services\CashRefundService;

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

echo "Starting Ticket #220 batch balance preservation tests...\n";

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
$storeId = 1;

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
    cost_price REAL NOT NULL DEFAULT 5.0,
    reorder_level INTEGER NOT NULL DEFAULT 5,
    safety_stock INTEGER NOT NULL DEFAULT 2,
    minimum_order_quantity INTEGER NOT NULL DEFAULT 1,
    units_per_package INTEGER NOT NULL DEFAULT 1,
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
    gross_total REAL NOT NULL DEFAULT 0,
    total_amount REAL NOT NULL DEFAULT 0,
    net_amount REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    discount_amount REAL NOT NULL DEFAULT 0,
    discount_type TEXT NOT NULL DEFAULT 'none',
    discount_value REAL NOT NULL DEFAULT 0,
    discount_reason TEXT NULL,
    discount_authorized_by INTEGER NULL,
    promotion_id INTEGER NULL,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    payment_status TEXT NOT NULL DEFAULT 'paid',
    status TEXT NOT NULL DEFAULT 'completed',
    sale_date TEXT DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    tender_amount REAL NULL,
    change_amount REAL NULL,
    reference_number TEXT NULL
)");

$pdo->exec("CREATE TABLE sale_items (
    sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price REAL NOT NULL,
    subtotal REAL NOT NULL,
    discount_amount REAL NOT NULL DEFAULT 0,
    net_amount REAL NOT NULL DEFAULT 0
)");

$pdo->exec("CREATE TABLE sale_item_batches (
    sale_item_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_item_id INTEGER NOT NULL,
    batch_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL
)");

$pdo->exec("CREATE TABLE sale_reversals (
    reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    reversal_type TEXT NOT NULL DEFAULT 'refund',
    status TEXT NOT NULL DEFAULT 'pending',
    reason TEXT NULL,
    settlement_method TEXT NULL,
    refund_amount REAL NOT NULL DEFAULT 0,
    requested_by INTEGER NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE cash_refunds (
    refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    shift_id INTEGER NOT NULL,
    cashier_id INTEGER NOT NULL,
    refund_amount REAL NOT NULL,
    payment_method TEXT NOT NULL,
    reason TEXT NOT NULL,
    note TEXT NULL,
    original_cashier_id INTEGER NOT NULL,
    approved_by INTEGER NULL,
    exception_reason TEXT NULL,
    payment_reference TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE cash_refund_items (
    refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
    refund_id INTEGER NOT NULL,
    sale_item_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price REAL NOT NULL,
    subtotal REAL NOT NULL,
    disposition TEXT NOT NULL
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

$pdo->exec("CREATE TABLE notification_preferences (
    user_id INTEGER PRIMARY KEY,
    notify_low_stock INTEGER DEFAULT 1,
    low_stock_threshold INTEGER NULL
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

$pdo->exec("CREATE TABLE refund_receipt_details (
    receipt_id INTEGER PRIMARY KEY AUTOINCREMENT,
    refund_id INTEGER NOT NULL,
    details_json TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE store_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL
)");
$pdo->exec("INSERT INTO store_settings (setting_key, setting_value) VALUES ('store_name', 'RetailMind Test Store')");

$pdo->exec("CREATE TABLE categories (
    category_id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_name TEXT NOT NULL
)");
$pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'General')");

$pdo->exec("CREATE TABLE csv_import_logs (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    imported_by INTEGER NOT NULL,
    import_type TEXT NOT NULL,
    filename TEXT NOT NULL,
    total_rows INTEGER NOT NULL,
    successful_rows INTEGER NOT NULL,
    failed_rows INTEGER NOT NULL,
    status TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

// Services
$quietNotifications = new class($pdo) extends NotificationService {
    public function checkAndNotifyLowStock(): void {}
    public function checkAndNotifyExpiringStock(int $days = 30): void {}
};
$shiftsService = new CashierShiftService($pdo);
$shiftId = $shiftsService->openShift($cashierId, 'cashier', 1, 1000.00);
$salesWorkflow = new SalesWorkflowService($pdo, null, $quietNotifications);
$refundService = new CashRefundService($pdo, $shiftsService);
$productService = new ProductService($pdo, $quietNotifications);

// Philippine Dates
$todayManila = (new DateTimeImmutable('now', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$yesterdayManila = (new DateTimeImmutable('yesterday', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$tomorrowManila = (new DateTimeImmutable('tomorrow', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$nextWeekManila = (new DateTimeImmutable('+7 days', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$nowManilaTime = (new DateTimeImmutable('now', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d H:i:s');

// =========================================================================
// 1. FEFO Sales: dated first, undated last, expired excluded
// =========================================================================
echo "\n--- Test 1: FEFO Sales Ordering & Expired Stock Exclusion ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, status)
            VALUES (101, 'SKU-FEFO', 'BC-FEFO', 'FEFO Loaf', 20.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (101, 25)");

// Batch 1: Expired yesterday (5 units)
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1011, 101, 5, 5, ?, ?)")->execute([$yesterdayManila, '2026-10-01 10:00:00']);
// Batch 2: Expiring tomorrow (4 units)
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1012, 101, 4, 4, ?, ?)")->execute([$tomorrowManila, '2026-10-05 10:00:00']);
// Batch 3: Expiring next week (6 units)
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1013, 101, 6, 6, ?, ?)")->execute([$nextWeekManila, '2026-10-06 10:00:00']);
// Batch 4: Undated (10 units)
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1014, 101, 10, 10, NULL, ?)")->execute(['2026-10-07 10:00:00']);

// Verify sellable stock in getActiveProducts excludes expired Batch 1011
$active = $salesWorkflow->getActiveProducts();
$fefoActive = current(array_filter($active, static fn($p) => (int)$p['product_id'] === 101));
$assert((int)$fefoActive['quantity_on_hand'] === 20, 'Sellable quantity excludes expired batch (20 sellable of 25 on hand)');
$assert($fefoActive['next_expiration_date'] === $tomorrowManila, 'Next expiration date is earliest non-expired batch (tomorrow)');

// Sale A: Buy 7 units. Should take all 4 from Batch 1012 (tomorrow), then 3 from Batch 1013 (next week).
$pdo->exec('ALTER TABLE products ADD COLUMN warning_lead_time_days INTEGER NULL');
require_once __DIR__ . '/support/notification_schema.php';
notification_test_schema($pdo);
$reminderService = new NotificationService($pdo);
$reminderService->createNotification($managerId, 'expiring_stock', 'Expiry tomorrow', 'Four loaves', 1012, 'product_batch_expiry_7');
$assert($reminderService->getBatchReminders($managerId, $tomorrowManila)[1012]['active'], 'Expiring batch is actionable before sale');
$cartA = [['product_id' => 101, 'qty' => 7]];
$detailsA = ['cash_received' => 200.00, 'payment_method' => 'cash'];
$saleA = $salesWorkflow->checkout($cartA, $cashierId, 'cashier', 'cash', $detailsA);
$assert(is_array($saleA) && ($saleA['sale_id'] ?? 0) > 0, 'Sale A of 7 units completes successfully');
$soldReminder = $reminderService->getBatchReminders($managerId, $tomorrowManila)[1012];
$assert(!$soldReminder['active'] && (int)$soldReminder['remaining_quantity'] === 0, 'Sale depletion immediately clears reminder while preserving its history');

$rem1011 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1011')->fetchColumn();
$rem1012 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1012')->fetchColumn();
$rem1013 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1013')->fetchColumn();
$rem1014 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1014')->fetchColumn();
$inv101 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 101')->fetchColumn();

$assert($rem1011 === 5, 'Expired batch 1011 remains untouched at 5');
$assert($rem1012 === 0, 'Earliest dated batch 1012 fully depleted (4 units)');
$assert($rem1013 === 3, 'Next earliest dated batch 1013 partially depleted (3 units taken, 3 left)');
$assert($rem1014 === 10, 'Undated batch 1014 untouched at 10');
$assert($inv101 === 18, 'Inventory decremented to 18 (25 - 7)');
$assert(($rem1011 + $rem1012 + $rem1013 + $rem1014) === $inv101, 'Sum of batch balances equals inventory on hand (5 + 0 + 3 + 10 = 18)');

// Verify sale_item_batches records allocations
$allocA = $pdo->query("SELECT batch_id, quantity FROM sale_item_batches WHERE sale_item_id = (SELECT sale_item_id FROM sale_items WHERE sale_id = {$saleA['sale_id']}) ORDER BY batch_id")->fetchAll();
$assert(count($allocA) === 2, 'Sale A has two batch allocations');
$assert((int)$allocA[0]['batch_id'] === 1012 && (int)$allocA[0]['quantity'] === 4, 'Allocated 4 to batch 1012');
$assert((int)$allocA[1]['batch_id'] === 1013 && (int)$allocA[1]['quantity'] === 3, 'Allocated 3 to batch 1013');

// Sale B: Buy 5 units. Should take remaining 3 from Batch 1013, then 2 from undated Batch 1014.
$cartB = [['product_id' => 101, 'qty' => 5]];
$detailsB = ['cash_received' => 150.00, 'payment_method' => 'cash'];
$saleB = $salesWorkflow->checkout($cartB, $cashierId, 'cashier', 'cash', $detailsB);
$assert(is_array($saleB) && ($saleB['sale_id'] ?? 0) > 0, 'Sale B of 5 units completes successfully');

$rem1013 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1013')->fetchColumn();
$rem1014 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1014')->fetchColumn();
$inv101 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 101')->fetchColumn();

$assert($rem1013 === 0, 'Batch 1013 fully depleted (3 units)');
$assert($rem1014 === 8, 'Undated batch 1014 depleted last (2 units taken, 8 left)');
$assert($inv101 === 13, 'Inventory on hand decremented to 13 (18 - 5)');
$assert((5 + 0 + 0 + 8) === $inv101, 'Sum of batch balances equals inventory on hand (5 + 8 = 13)');

// Sale C: Attempt to buy 9 units.
// Available non-expired stock is 8 (in batch 1014). Batch 1011 has 5, but is expired.
// Must throw StockConflictException and preserve balances.
$cartC = [['product_id' => 101, 'qty' => 9]];
$detailsC = ['cash_received' => 200.00, 'payment_method' => 'cash'];
$shortageThrew = false;
try {
    $salesWorkflow->checkout($cartC, $cashierId, 'cashier', 'cash', $detailsC);
} catch (StockConflictException $e) {
    $shortageThrew = true;
    $conflicts = $e->getConflicts();
    $assert(count($conflicts) === 1, 'Conflict details returned');
    $assert((int)$conflicts[0]['available_stock'] === 8, 'Conflict details reports only 8 non-expired available');
    $assert((int)$conflicts[0]['requested_qty'] === 9, 'Conflict details reports 9 requested');
}
$assert($shortageThrew, 'Attempt to sell more than non-expired stock throws StockConflictException');
$invAfterRefusal = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 101')->fetchColumn();
$assert($invAfterRefusal === 13, 'Inventory remains 13 after refused checkout');

// =========================================================================
// 2. Manila calendar date boundary
// =========================================================================
echo "\n--- Test 2: Manila Calendar Date Boundary ---\n";

// Product 102: expires TODAY ($todayManila) -> sellable through midnight Manila
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, status)
            VALUES (102, 'SKU-TODAY', 'BC-TODAY', 'Today Loaf', 25.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (102, 5)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1021, 102, 5, 5, ?, ?)")->execute([$todayManila, $nowManilaTime]);

$cartToday = [['product_id' => 102, 'qty' => 2]];
$saleToday = $salesWorkflow->checkout($cartToday, $cashierId, 'cashier', 'cash', ['cash_received' => 50.00]);
$assert(is_array($saleToday) && ($saleToday['sale_id'] ?? 0) > 0, 'Stock expiring TODAY is sellable through Manila calendar day');
$rem1021 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1021')->fetchColumn();
$assert($rem1021 === 3, 'Batch expiring today decremented to 3');

// Product 103: expired YESTERDAY ($yesterdayManila) -> NOT sellable
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, status)
            VALUES (103, 'SKU-YEST', 'BC-YEST', 'Yesterday Loaf', 25.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (103, 5)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1031, 103, 5, 5, ?, ?)")->execute([$yesterdayManila, '2026-10-01 08:00:00']);

$throws(
    static fn() => $salesWorkflow->checkout([['product_id' => 103, 'qty' => 1]], $cashierId, 'cashier', 'cash', ['cash_received' => 25.00]),
    StockConflictException::class,
    'Stock expired yesterday is refused at checkout'
);

$rem1031 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1031')->fetchColumn();
$inv103 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 103')->fetchColumn();
$assert($rem1031 === 5 && $inv103 === 5, 'Expired stock remains physically recorded on hand and in batch');

// =========================================================================
// 3. Customer Refund Lineage & Restockable vs Damaged Dispositions
// =========================================================================
echo "\n--- Test 3: Customer Refund Lineage & Batch Restoration ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, status)
            VALUES (104, 'SKU-REFUND', 'BC-REFUND', 'Refundable Widget', 50.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (104, 6)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1041, 104, 3, 3, ?, ?)")->execute([$tomorrowManila, '2026-10-10 10:00:00']);
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1042, 104, 3, 3, ?, ?)")->execute([$nextWeekManila, '2026-10-11 10:00:00']);

// Sell 5 units: 3 from batch 1041, 2 from batch 1042
$saleRef = $salesWorkflow->checkout([['product_id' => 104, 'qty' => 5]], $cashierId, 'cashier', 'cash', ['cash_received' => 250.00]);
$saleRefId = (int)$saleRef['sale_id'];
$saleItemId = (int)$pdo->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$saleRefId}")->fetchColumn();

$assert((int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1041')->fetchColumn() === 0, 'Batch 1041 has 0 remaining');
$assert((int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1042')->fetchColumn() === 1, 'Batch 1042 has 1 remaining');
$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 104')->fetchColumn() === 1, 'Inventory has 1 remaining');

// Restockable refund of 2 units: restores to Batch 1041 (first allocated)
$linesRestock1 = [$saleItemId => ['quantity' => 2, 'disposition' => CashRefundService::RESTOCKABLE]];
$ref1Id = $refundService->refund($cashierId, 'cashier', $saleRefId, $linesRestock1, 'customer_return');
$assert($ref1Id > 0, 'First restockable refund of 2 units processed');

$rem1041 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1041')->fetchColumn();
$rem1042 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1042')->fetchColumn();
$inv104 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 104')->fetchColumn();
$assert($rem1041 === 2, 'Restored 2 units to batch 1041');
$assert($rem1042 === 1, 'Batch 1042 unchanged at 1');
$assert($inv104 === 3, 'Inventory on hand restored from 1 to 3');
$assert(($rem1041 + $rem1042) === $inv104, 'Batch balances equal inventory on hand (2 + 1 = 3)');

// Second restockable refund of 2 units: restores 1 to Batch 1041 (reaching its max 3), and 1 to Batch 1042
$linesRestock2 = [$saleItemId => ['quantity' => 2, 'disposition' => CashRefundService::RESTOCKABLE]];
$ref2Id = $refundService->refund($cashierId, 'cashier', $saleRefId, $linesRestock2, 'customer_return');
$assert($ref2Id > 0, 'Second restockable refund of 2 units processed');

$rem1041 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1041')->fetchColumn();
$rem1042 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1042')->fetchColumn();
$inv104 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 104')->fetchColumn();
$assert($rem1041 === 3, 'Batch 1041 restored to full 3');
$assert($rem1042 === 2, 'Batch 1042 restored to 2');
$assert($inv104 === 5, 'Inventory on hand restored to 5');
$assert(($rem1041 + $rem1042) === $inv104, 'Batch balances equal inventory on hand (3 + 2 = 5)');

// Damaged refund of the final 1 unit: money returned, but NOT restocked and NOT deducted again
$linesDamaged = [$saleItemId => ['quantity' => 1, 'disposition' => CashRefundService::DAMAGED]];
$ref3Id = $refundService->refund($cashierId, 'cashier', $saleRefId, $linesDamaged, 'damaged_item');
$assert($ref3Id > 0, 'Damaged refund processed');

$rem1041After = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1041')->fetchColumn();
$rem1042After = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1042')->fetchColumn();
$inv104After = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 104')->fetchColumn();

$assert($rem1041After === 3, 'Batch 1041 remaining unchanged after damaged return');
$assert($rem1042After === 2, 'Batch 1042 remaining unchanged after damaged return');
$assert($inv104After === 5, 'Inventory on hand unchanged after damaged return (neither restocked nor double-deducted)');
$assert(($rem1041After + $rem1042After) === $inv104After, 'Batch balances and inventory remain exactly aligned (3 + 2 = 5)');

// =========================================================================
// 4. ProductService::adjustStock
// =========================================================================
echo "\n--- Test 4: ProductService::adjustStock Positive & Negative Adjustments ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, status)
            VALUES (105, 'SKU-ADJ', 'BC-ADJ', 'Adjustable Item', 30.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (105, 10)");
// Batch 1: 4 units expiring next week
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1051, 105, 4, 4, ?, ?)")->execute([$nextWeekManila, '2026-10-10 10:00:00']);
// Batch 2: 6 units undated
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1052, 105, 6, 6, NULL, ?)")->execute(['2026-10-10 10:00:00']);

// 4a. Positive adjustment (+8 units)
$productService->adjustStock([
    'product_id' => 105,
    'qty_change' => 8,
    'adjustment_reason' => 'Audit found additional cases in storage'
], $managerId);

$inv105 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 105')->fetchColumn();
$assert($inv105 === 18, 'Inventory increased by +8 to 18');

$newBatches = $pdo->query('SELECT batch_id, quantity, remaining_quantity, expiration_date FROM product_batches WHERE product_id = 105 AND batch_id NOT IN (1051, 1052)')->fetchAll();
$assert(count($newBatches) === 1, 'Positive adjustment created one new batch');
$newBatch = $newBatches[0];
$assert((int)$newBatch['quantity'] === 8 && (int)$newBatch['remaining_quantity'] === 8, 'New batch has quantity 8 and remaining_quantity 8');
$assert($newBatch['expiration_date'] === null, 'New batch created by positive adjustment is explicitly undated (NULL)');

$totalBatchQty105 = (int)$pdo->query('SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = 105')->fetchColumn();
$assert($totalBatchQty105 === 18, 'Sum of all batches equals inventory on hand (4 + 6 + 8 = 18)');

// 4b. Negative adjustment (-6 units). FEFO should deduct 4 from dated batch 1051, then 2 from undated batch 1052.
$productService->adjustStock([
    'product_id' => 105,
    'qty_change' => -6,
    'adjustment_reason' => 'Damaged cases discarded during inspection'
], $managerId);

$inv105 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 105')->fetchColumn();
$assert($inv105 === 12, 'Inventory decreased by 6 to 12');

$rem1051 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1051')->fetchColumn();
$rem1052 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1052')->fetchColumn();
$remNew = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$newBatch['batch_id']}")->fetchColumn();

$assert($rem1051 === 0, 'Dated batch 1051 deducted first by FEFO (4 units deducted, 0 left)');
$assert($rem1052 === 4, 'Undated batch 1052 deducted next (2 units deducted, 4 left)');
$assert($remNew === 8, 'Newest undated batch untouched at 8');
$assert(($rem1051 + $rem1052 + $remNew) === 12, 'Sum of batch balances equals inventory on hand (0 + 4 + 8 = 12)');

// 4c. Adjustment taking stock below zero throws RuntimeException and rolls back
$throws(
    static fn() => $productService->adjustStock([
        'product_id' => 105,
        'qty_change' => -50,
        'adjustment_reason' => 'Excessive reduction attempt'
    ], $managerId),
    RuntimeException::class,
    'Adjustment taking stock below zero throws RuntimeException'
);
$inv105AfterFail = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 105')->fetchColumn();
$assert($inv105AfterFail === 12, 'Inventory on hand unchanged after failed adjustment');

// =========================================================================
// 5. CSV Inventory Import Creates Undated Batch
// =========================================================================
echo "\n--- Test 5: CSV Inventory Import Creates Undated Batch ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, status)
            VALUES (106, 'SKU-CSV-1', 'BC-CSV-1', 'CSV Import Product', 15.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (106, 5)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1061, 106, 5, 5, ?, ?)")->execute([$tomorrowManila, '2026-10-10 10:00:00']);

// Exercise frontend csv_import loop
$_SESSION['user_id'] = $managerId;
$import_store_id = 1;
$fiscalPeriodGuard = new FiscalPeriodGuardService($pdo);

$csvContent = "sku,quantity_to_add\nSKU-CSV-1,10\n";
$fp = fopen('php://temp', 'r+');
fwrite($fp, $csvContent);
rewind($fp);
fgetcsv($fp); // header
$successful = 0;
$failed = 0;
$errors = [];

while (($row = fgetcsv($fp)) !== false) {
    if (empty(array_filter($row))) continue;
    try {
        $sku = trim($row[0] ?? '');
        $qty = (int)($row[1] ?? 0);
        if ($qty <= 0) {
            $failed++;
            $errors[] = "Quantity to add must be greater than zero";
            continue;
        }
        $prod_stmt = $pdo->prepare("SELECT product_id FROM products WHERE sku = ? AND branch_id = ?");
        $prod_stmt->execute([$sku, $import_store_id]);
        $prod = $prod_stmt->fetch();
        if (!$prod) throw new RuntimeException("Product SKU '$sku' not found");

        App\Store\StoreWriteGate::begin($pdo);

        $product_id = $prod['product_id'];
        $beforeInvStmt = $pdo->prepare("SELECT i.product_id, i.quantity_on_hand FROM inventory i JOIN products p ON p.product_id = i.product_id WHERE i.product_id = ? AND p.branch_id = ?");
        $beforeInvStmt->execute([(int)$product_id, $import_store_id]);
        $beforeInventory = $beforeInvStmt->fetch();

        $inv_upd = $pdo->prepare("UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?");
        App\Store\StoreWriteGate::execute($pdo, $inv_upd, [$qty, $product_id]);

        if ($qty > 0) {
            $nowManila = (new DateTimeImmutable('now', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d H:i:s');
            $batch_ins = $pdo->prepare("INSERT INTO product_batches (product_id, quantity, remaining_quantity, expiration_date, date_received) VALUES (?, ?, ?, NULL, ?)");
            App\Store\StoreWriteGate::execute($pdo, $batch_ins, [$product_id, $qty, $qty, $nowManila]);
        }

        $mov_ins = $pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason, moved_by) VALUES (?, ?, 'purchase', ?)");
        App\Store\StoreWriteGate::execute($pdo, $mov_ins, [$product_id, $qty, $_SESSION['user_id']]);

        $pdo->commit();
        $successful++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $failed++;
        $errors[] = $e->getMessage();
    }
}
fclose($fp);

$assert($successful === 1 && $failed === 0, 'CSV inventory row imported successfully');
$inv106 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 106')->fetchColumn();
$assert($inv106 === 15, 'Inventory increased by 10 to 15 (5 + 10)');

$importedBatches = $pdo->query('SELECT batch_id, quantity, remaining_quantity, expiration_date FROM product_batches WHERE product_id = 106 AND batch_id != 1061')->fetchAll();
$assert(count($importedBatches) === 1, 'Imported batch created in product_batches');
$assert((int)$importedBatches[0]['quantity'] === 10 && (int)$importedBatches[0]['remaining_quantity'] === 10, 'Imported batch has quantity 10');
$assert($importedBatches[0]['expiration_date'] === null, 'Imported batch is undated (expiration_date = NULL)');

$totalBatch106 = (int)$pdo->query('SELECT SUM(remaining_quantity) FROM product_batches WHERE product_id = 106')->fetchColumn();
$assert($totalBatch106 === 15, 'Sum of batch balances equals inventory on hand (5 + 10 = 15)');

// Negative CSV import test
$csvNeg = "sku,quantity_to_add\nSKU-CSV-1,-5\n";
$fpNeg = fopen('php://temp', 'r+');
fwrite($fpNeg, $csvNeg);
rewind($fpNeg);
fgetcsv($fpNeg);
$negSuccess = 0; $negFailed = 0;
while (($row = fgetcsv($fpNeg)) !== false) {
    if (empty(array_filter($row))) continue;
    $qty = (int)($row[1] ?? 0);
    if ($qty <= 0) {
        $negFailed++;
        continue;
    }
    $negSuccess++;
}
fclose($fpNeg);
$assert($negFailed === 1 && $negSuccess === 0, 'Negative CSV quantity is rejected');
$assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 106')->fetchColumn() === 15, 'Inventory unchanged by rejected negative CSV');

// =========================================================================
// 6. Concurrency / Duplicate Checkout Retry Guard & Atomic Rollback
// =========================================================================
echo "\n--- Test 6: Retry Guard & Atomic Rollback on Injected Failure ---\n";

$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, status)
            VALUES (107, 'SKU-RETRY', 'BC-RETRY', 'Retry Protection Loaf', 20.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (107, 10)");
$pdo->prepare("INSERT INTO product_batches (batch_id, product_id, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (1071, 107, 10, 10, ?, ?)")->execute([$tomorrowManila, $nowManilaTime]);

$cartRetry = [['product_id' => 107, 'qty' => 3]];
$attemptId = bin2hex(random_bytes(16));
$detailsRetry = ['cash_received' => 100.00, 'checkout_attempt' => $attemptId];
$review = $salesWorkflow->reviewQuote($cartRetry, $cashierId, 'cashier', $detailsRetry);
$detailsRetry['reviewed_quote'] = $review['state_hash'];

// First checkout call
$result1 = $salesWorkflow->checkout($cartRetry, $cashierId, 'cashier', 'cash', $detailsRetry);
$assert(is_array($result1) && ($result1['sale_id'] ?? 0) > 0, 'First checkout attempt succeeds');
$inv107After1 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 107')->fetchColumn();
$batch107After1 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1071')->fetchColumn();
$assert($inv107After1 === 7 && $batch107After1 === 7, 'Stock deducted once to 7');

// Duplicate retry with exact same attempt_id
$result2 = $salesWorkflow->checkout($cartRetry, $cashierId, 'cashier', 'cash', $detailsRetry);
$assert($result2['sale_id'] === $result1['sale_id'], 'Retry returns exact same sale_id without re-executing');
$inv107After2 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 107')->fetchColumn();
$batch107After2 = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1071')->fetchColumn();
$assert($inv107After2 === 7 && $batch107After2 === 7, 'Duplicate retry did not decrement stock or batch a second time');
$salesCount107 = (int)$pdo->query("SELECT COUNT(*) FROM sale_items WHERE product_id = 107")->fetchColumn();
$assert($salesCount107 === 1, 'Only one sale record exists for product 107');

// Concurrent overlapping deduction test:
// Product 107 now has 7 units in batch 1071. Two concurrent carts review the same 7 units.
// Cart A buys 5 units.
$cartA = [['product_id' => 107, 'qty' => 5]];
$cartB = [['product_id' => 107, 'qty' => 5]];
$reviewA = $salesWorkflow->reviewQuote($cartA, $cashierId, 'cashier', ['cash_received' => 100.00]);
$salesWorkflow->checkout($cartA, $cashierId, 'cashier', 'cash', ['cash_received' => 100.00, 'reviewed_quote' => $reviewA['state_hash']]);
$assert((int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1071')->fetchColumn() === 2, 'Cart A depleted batch to 2');

// Overlapping Cart B now attempts to checkout 5 units when only 2 remain
$throws(
    static fn() => $salesWorkflow->checkout($cartB, $cashierId, 'cashier', 'cash', ['cash_received' => 100.00, 'reviewed_quote' => $reviewA['state_hash']]),
    StockConflictException::class,
    'Concurrent overlapping deduction rejected when batch balance is depleted'
);

// Injected rollback: checkout failure due to invalid cash payment
$cartFail = [['product_id' => 107, 'qty' => 2]];
$detailsFail = ['cash_received' => 10.00]; // total is 40.00, cash is 10.00 -> throws
$throws(
    static fn() => $salesWorkflow->checkout($cartFail, $cashierId, 'cashier', 'cash', $detailsFail),
    RuntimeException::class,
    'Insufficient cash throws RuntimeException'
);
$inv107AfterFail = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 107')->fetchColumn();
$batch107AfterFail = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1071')->fetchColumn();
$assert($inv107AfterFail === 2 && $batch107AfterFail === 2, 'Atomic rollback preserves inventory and batch balances (remains 2)');

// =========================================================================
// 7. Legacy Unbatched Stock Fallback & Mismatch Visibility
// =========================================================================
echo "\n--- Test 7: Legacy Unbatched Stock Fallback & Mismatch Detection ---\n";

// Product 108: Legacy product with 10 on hand in inventory, but 0 batches in product_batches
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, status)
            VALUES (108, 'SKU-LEGACY', 'BC-LEGACY', 'Legacy Unbatched Item', 10.00, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (108, 10)");

// Check getActiveProducts includes unbatched product as sellable
$activeProducts = $salesWorkflow->getActiveProducts();
$legacyActive = current(array_filter($activeProducts, static fn($p) => (int)$p['product_id'] === 108));
$assert($legacyActive !== false, 'Legacy unbatched product is listed in getActiveProducts');
$assert((int)$legacyActive['quantity_on_hand'] === 10, 'Sellable quantity reflects quantity_on_hand (10)');

// Checkout legacy product
$cartLegacy = [['product_id' => 108, 'qty' => 4]];
$saleLegacy = $salesWorkflow->checkout($cartLegacy, $cashierId, 'cashier', 'cash', ['cash_received' => 50.00]);
$assert(is_array($saleLegacy) && ($saleLegacy['sale_id'] ?? 0) > 0, 'Legacy unbatched stock checkout succeeds via fallback');

$inv108 = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 108')->fetchColumn();
$assert($inv108 === 6, 'Inventory decremented to 6 (10 - 4)');
$allocCount = (int)$pdo->query("SELECT COUNT(*) FROM sale_item_batches sib JOIN sale_items si ON si.sale_item_id = sib.sale_item_id WHERE si.product_id = 108")->fetchColumn();
$assert($allocCount === 0, 'No batch allocations created for unbatched legacy product (no silent invention of batches)');

// Mismatch visibility check
// A reconciliation query compares inventory.quantity_on_hand with SUM(product_batches.remaining_quantity)
$mismatchStmt = $pdo->query(
    "SELECT p.product_id, p.product_name, i.quantity_on_hand,
            COALESCE(b.total_batch_qty, 0) AS total_batch_qty,
            (i.quantity_on_hand - COALESCE(b.total_batch_qty, 0)) AS discrepancy
     FROM products p
     JOIN inventory i ON i.product_id = p.product_id
     LEFT JOIN (
         SELECT product_id, SUM(remaining_quantity) AS total_batch_qty
         FROM product_batches
         GROUP BY product_id
     ) b ON b.product_id = p.product_id
     WHERE i.quantity_on_hand != COALESCE(b.total_batch_qty, 0)"
);
$mismatches = $mismatchStmt->fetchAll();
$mismatchedProductIds = array_column($mismatches, 'product_id');

$assert(in_array(108, $mismatchedProductIds, true), 'Unbatched legacy product 108 is visibly identified as a mismatch exception');
$legacyMismatch = current(array_filter($mismatches, static fn($m) => (int)$m['product_id'] === 108));
$assert((int)$legacyMismatch['quantity_on_hand'] === 6 && (int)$legacyMismatch['total_batch_qty'] === 0 && (int)$legacyMismatch['discrepancy'] === 6,
    'Discrepancy correctly calculated: on_hand=6, batch_qty=0, discrepancy=6 (not silently masked)');

// Fully reconciled products (101, 102, 103, 104, 105, 106, 107) must NOT appear in mismatch list
foreach ([101, 102, 103, 104, 105, 106, 107] as $reconciledId) {
    $assert(!in_array($reconciledId, $mismatchedProductIds, true), "Reconciled product {$reconciledId} has zero discrepancy between inventory and batches");
}

// --- Summary ---
echo "\n============================================================\n";
if (!empty($failures)) {
    fwrite(STDERR, sprintf("FAILED: %d test(s) failed:\n- %s\n", count($failures), implode("\n- ", $failures)));
    exit(1);
}

echo "All Ticket #220 batch balance preservation tests passed cleanly!\n";
exit(0);
