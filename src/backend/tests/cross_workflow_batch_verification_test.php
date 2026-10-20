<?php
// Contract and integration test for Ticket #226: "Cross-workflow verification".
// Verifies complete end-to-end invariants across all inventory quantity writers and readers:
// 1. Opening stock with expiration date creates initial batch.
// 2. Stock Receiving with multiple/different expiration dates creates separate batches.
// 3. Stock Issue approval deducts batch and inventory atomically with 1 movement.
// 4. POS / FEFO Sales allocate earliest unexpired batch first, enforce Manila boundary (today sellable, yesterday expired), skip expired batches, use undated last.
// 5. Customer Returns restore restockable units to original sale batch; damaged returns do not double-deduct and do not restock.
// 6. Physical Count handles shortages, extras, and undated units atomically.
// 7. Supplier Pullouts deduct specific batch (including expired goods) and record 'supplier_pullout' movement.
// 8. Linked Replacements create new batch with fresh date, preserve old batch history, link pullout_id, and preserve balance.
// 9. Invariant: quantity_on_hand == sum(remaining_quantity) at EVERY step.
// 10. Atomic rollback: failure in write rolls back batches, inventory, movement, and receiving/pullout records.
// 11. Workspace boundary: only authorized Inventory Manager workspace can mutate stock or warning overrides; cashier and admin rejected.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/ReceivingService.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/CashRefundService.php';
require_once __DIR__ . '/../app/Services/InventoryCountService.php';
require_once __DIR__ . '/../app/Services/SupplierPulloutService.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';
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

$throws = static function (callable $operation, string $expectedClass, string $message, ?string $expectedMessage = null) use ($assert): void {
    try {
        $operation();
        $assert(false, $message . ' (expected ' . $expectedClass . ', none thrown)');
    } catch (Throwable $e) {
        $matchesType = $e instanceof $expectedClass;
        $matchesMsg = $expectedMessage === null || str_contains($e->getMessage(), $expectedMessage);
        $assert(
            $matchesType && $matchesMsg,
            $message . ' (threw ' . $e::class . ': ' . $e->getMessage() . ')'
        );
    }
};

echo "Starting Ticket #226 cross-workflow batch verification tests...\n";

// --- Database Setup (SQLite in-memory with MySQL compatibility) ---
class CrossWorkflowSqlitePdo extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), $options);
    }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return parent::query(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), ...$fetchModeArgs);
    }
}

$pdo = new CrossWorkflowSqlitePdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo'] = $pdo;

$pdo->sqliteCreateFunction('GET_LOCK', static fn(): int => 1, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', static fn(): int => 1, 1);
$pdo->sqliteCreateFunction('SHA2', static fn(string $val, int $len): string => hash('sha256', $val), 2);
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'test', 0);
$pdo->sqliteCreateFunction('CONCAT', static fn(...$args): string => implode('', $args));
$pdo->sqliteCreateFunction('LEFT', static fn(string $str, int $len): string => substr($str, 0, $len), 2);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
$pdo->sqliteCreateFunction('DATE', static fn(string $d): string => substr($d, 0, 10), 1);
$pdo->sqliteCreateFunction('DATEDIFF', static fn(string $d1, string $d2): int => (int)((strtotime($d1) - strtotime($d2)) / 86400), 2);
$pdo->sqliteCreateFunction('LEAST', static fn(...$values) => min($values));
$pdo->sqliteCreateFunction('GREATEST', static fn(...$values) => max($values));

// --- Schema Creation ---
$pdo->exec("
    CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_name TEXT NOT NULL UNIQUE,
        branch_code TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'active'
    );
    INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Store', 'MAIN', 'active');

    CREATE TABLE roles (
        role_id INTEGER PRIMARY KEY AUTOINCREMENT,
        role_name TEXT NOT NULL UNIQUE
    );
    INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NULL,
        role_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'active',
        must_change_password INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE user_roles (
        user_id INTEGER NOT NULL,
        role_id INTEGER NOT NULL,
        is_primary INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, role_id)
    );

    CREATE TABLE categories (
        category_id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_name TEXT NOT NULL
    );
    INSERT INTO categories (category_id, category_name) VALUES (1, 'Bakery');

    CREATE TABLE registers (
        register_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        branch_id INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'active',
        paper_width_mm INTEGER NOT NULL DEFAULT 80
    );
    INSERT INTO registers (register_id, name, branch_id) VALUES (1, 'Front Register', 1);

    CREATE TABLE cashier_shifts (
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
    );

    CREATE TABLE cash_drawer_movements (
        drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        amount REAL NOT NULL,
        reason TEXT NULL,
        recorded_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_id INTEGER NOT NULL DEFAULT 1,
        sku TEXT NOT NULL,
        barcode TEXT NOT NULL,
        case_barcode TEXT NULL,
        parent_product_id INTEGER NULL,
        variant_label TEXT NULL,
        product_name TEXT NOT NULL,
        brand TEXT NULL,
        category_id INTEGER NULL,
        unit_price REAL NOT NULL DEFAULT 0.0,
        cost_price REAL NOT NULL DEFAULT 0.0,
        quantity_purchased INTEGER NOT NULL DEFAULT 0,
        quantity_sold INTEGER NOT NULL DEFAULT 0,
        reorder_level INTEGER NOT NULL DEFAULT 5,
        safety_stock INTEGER NOT NULL DEFAULT 0,
        minimum_order_quantity INTEGER NOT NULL DEFAULT 1,
        units_per_package INTEGER NOT NULL DEFAULT 1,
        base_unit TEXT NOT NULL DEFAULT 'piece',
        receiving_unit TEXT NOT NULL DEFAULT 'package',
        supplier TEXT NULL,
        preferred_supplier TEXT NULL,
        supplier_lead_time_days INTEGER NOT NULL DEFAULT 7,
        product_image TEXT NULL,
        expiration_date TEXT NULL,
        warning_lead_time_days INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'active',
        created_by INTEGER NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inventory (
        inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL UNIQUE,
        quantity_on_hand INTEGER NOT NULL DEFAULT 0,
        reorder_level INTEGER NOT NULL DEFAULT 5,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        receiving_id INTEGER NULL,
        batch_number TEXT NULL,
        quantity INTEGER NOT NULL,
        remaining_quantity INTEGER NOT NULL,
        expiration_date TEXT NULL,
        date_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE stock_receiving_groups (
        group_id INTEGER PRIMARY KEY AUTOINCREMENT,
        received_by INTEGER NOT NULL,
        supplier TEXT NULL,
        invoice_number TEXT NULL,
        notes TEXT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE stock_receiving (
        receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NULL,
        pullout_id INTEGER NULL,
        product_id INTEGER NOT NULL,
        received_qty INTEGER NOT NULL,
        received_packages INTEGER NOT NULL DEFAULT 0,
        units_per_package_used INTEGER NOT NULL DEFAULT 1,
        accepted_qty INTEGER NOT NULL,
        damaged_qty INTEGER NOT NULL DEFAULT 0,
        cost_price REAL NULL,
        total_cost REAL NOT NULL DEFAULT 0.0,
        received_by INTEGER NOT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT NULL,
        invoice_number TEXT NULL,
        batch_number TEXT NULL,
        expiration_date TEXT NULL,
        discrepancy_type TEXT NOT NULL DEFAULT 'none',
        discrepancy_qty INTEGER NOT NULL DEFAULT 0,
        discrepancy_notes TEXT NULL,
        notes TEXT NULL
    );

    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT NOT NULL,
        moved_by INTEGER NULL,
        adjustment_id INTEGER NULL,
        pullout_id INTEGER NULL,
        moved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE supplier_pullouts (
        pullout_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        batch_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        reason TEXT NOT NULL,
        supplier TEXT NULL,
        pulled_by INTEGER NOT NULL,
        pulled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        notes TEXT NULL
    );

    CREATE TABLE purchase_history (
        history_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        supplier TEXT NULL,
        quantity_purchased INTEGER NOT NULL,
        cost_price REAL NOT NULL,
        total_cost REAL NOT NULL,
        recorded_by INTEGER NOT NULL,
        purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inventory_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        adjustment_qty INTEGER NOT NULL,
        adjustment_type TEXT NOT NULL,
        reported_by INTEGER NOT NULL,
        shift_id INTEGER NULL,
        reported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        approved_by INTEGER NULL,
        approved_at TEXT NULL,
        reason TEXT NULL,
        review_notes TEXT NULL,
        status TEXT NOT NULL DEFAULT 'pending'
    );

    CREATE TABLE inventory_adjustment_revisions (
        revision_id INTEGER PRIMARY KEY AUTOINCREMENT,
        adjustment_id INTEGER NOT NULL,
        actor_id INTEGER NULL,
        action TEXT NOT NULL,
        old_status TEXT NULL,
        new_status TEXT NULL,
        old_values TEXT NULL,
        new_values TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE stock_issue_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        adjustment_id INTEGER NOT NULL,
        batch_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL
    );

    CREATE TABLE inventory_counts (
        count_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        system_quantity INTEGER NOT NULL,
        physical_quantity INTEGER NOT NULL,
        difference_qty INTEGER NOT NULL,
        discrepancy_reason TEXT NOT NULL,
        counted_by INTEGER NOT NULL,
        counted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status TEXT NOT NULL DEFAULT 'pending',
        approved_by INTEGER NULL,
        approved_at TIMESTAMP NULL,
        related_adjustment_id INTEGER NULL
    );

    CREATE TABLE inventory_count_batches (
        count_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        count_id INTEGER NOT NULL,
        batch_id INTEGER NULL,
        system_quantity INTEGER NOT NULL DEFAULT 0,
        physical_quantity INTEGER NOT NULL DEFAULT 0,
        difference_qty INTEGER NOT NULL DEFAULT 0,
        expiration_date TEXT NULL,
        batch_number TEXT NULL,
        notes TEXT NULL
    );

    CREATE TABLE sales (
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
    );

    CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        subtotal REAL NOT NULL,
        discount_amount REAL NOT NULL DEFAULT 0,
        net_amount REAL NOT NULL DEFAULT 0
    );

    CREATE TABLE sale_item_batches (
        sale_item_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_item_id INTEGER NOT NULL,
        batch_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL
    );

    CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        reversal_type TEXT NOT NULL DEFAULT 'refund',
        status TEXT NOT NULL DEFAULT 'pending',
        reason TEXT NULL,
        settlement_method TEXT NULL,
        refund_amount REAL NOT NULL DEFAULT 0,
        requested_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE cash_refunds (
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
    );

    CREATE TABLE cash_refund_items (
        refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        refund_id INTEGER NOT NULL,
        sale_item_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        subtotal REAL NOT NULL,
        disposition TEXT NOT NULL
    );

    CREATE TABLE sale_receipt_details (
        sale_id INTEGER PRIMARY KEY,
        details_json TEXT NOT NULL
    );

    CREATE TABLE refund_receipt_details (
        refund_id INTEGER PRIMARY KEY,
        details_json TEXT NOT NULL
    );

    CREATE TABLE store_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL
    );
    INSERT INTO store_settings (setting_key, setting_value) VALUES
    ('store_name', 'Main Store'),
    ('store_address', '123 Bakery Lane, Manila'),
    ('currency_symbol', '₱');

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open'
    );
    INSERT INTO fiscal_periods (period_id, period_name, start_date, end_date, status)
    VALUES (1, 'FY2026', '2026-01-01', '2026-12-31', 'open');

    CREATE TABLE fiscal_period_locks (
        lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_id INTEGER NOT NULL,
        table_name TEXT NULL
    );

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        category TEXT NOT NULL,
        module TEXT NULL,
        record_id INTEGER NULL,
        previous_value TEXT NULL,
        new_value TEXT NULL,
        metadata TEXT NULL,
        ip_address TEXT NULL,
        timestamp TEXT DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE notification_preferences (
        user_id INTEGER PRIMARY KEY,
        notify_low_stock INTEGER DEFAULT 1,
        notify_expiring_stock INTEGER DEFAULT 1,
        low_stock_threshold INTEGER DEFAULT NULL
    );

    CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        reference_id INTEGER NULL,
        reference_type TEXT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
");

$pdo->exec("ALTER TABLE products ADD COLUMN pack_barcode TEXT NULL");
$pdo->exec("ALTER TABLE products ADD COLUMN pack_size INTEGER NULL");
$pdo->exec("ALTER TABLE products ADD COLUMN pack_price REAL NULL");
$pdo->exec("ALTER TABLE products ADD COLUMN selling_mode TEXT NOT NULL DEFAULT 'piece'");
$pdo->exec("ALTER TABLE product_batches ADD COLUMN sealed_quantity INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE product_batches ADD COLUMN loose_quantity INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE product_batches ADD COLUMN cost_price REAL NULL");
$pdo->exec("ALTER TABLE stock_receiving ADD COLUMN received_packs INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE stock_receiving ADD COLUMN accepted_packs INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE stock_receiving ADD COLUMN damaged_packs INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE stock_receiving ADD COLUMN rejected_packs INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE stock_receiving ADD COLUMN received_loose INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE stock_receiving ADD COLUMN accepted_loose INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE stock_receiving ADD COLUMN damaged_loose INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE stock_receiving ADD COLUMN rejected_loose INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE stock_receiving ADD COLUMN pack_size_used INTEGER NULL");
$pdo->exec("ALTER TABLE supplier_pullouts ADD COLUMN pulled_packs INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE supplier_pullouts ADD COLUMN pulled_loose INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE supplier_pullouts ADD COLUMN pack_size_used INTEGER NOT NULL DEFAULT 1");
$pdo->exec("ALTER TABLE sale_items ADD COLUMN selling_form TEXT NOT NULL DEFAULT 'piece'");
$pdo->exec("ALTER TABLE sale_items ADD COLUMN sold_form_qty INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE sale_items ADD COLUMN pack_size_used INTEGER NOT NULL DEFAULT 1");
$pdo->exec("ALTER TABLE notification_preferences ADD COLUMN notify_inapp INTEGER DEFAULT 1");

// Insert users
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
$adminId = $insertUser('Adam Admin', 'admin1', 'admin');

// Quiet notifications
$quietNotifications = new class($pdo) extends NotificationService {
    public function checkAndNotifyLowStock(): void {}
    public function checkAndNotifyExpiringStock(int $days = 30): void {}
};

// Initialize services
$productService = new ProductService($pdo, $quietNotifications);
$receivingService = new ReceivingService($pdo);
$stockIssueService = new StockIssueService($pdo, $quietNotifications);
$shiftsService = new CashierShiftService($pdo);
$salesWorkflow = new SalesWorkflowService($pdo, null, $quietNotifications);
$refundService = new CashRefundService($pdo, $shiftsService);
$inventoryCountService = new InventoryCountService($pdo);
$pulloutService = new SupplierPulloutService($pdo);
$inventoryService = new InventoryService($pdo);

// Helper closures
$getQoh = static fn(int $pid): int => (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$pid}")->fetchColumn();
$getBatchRem = static fn(int $bid): int => (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$bid}")->fetchColumn();

// Philippine calendar dates
$todayManila = PhilippineTime::today();
$manilaDate = static fn(string $mod): string => (new DateTimeImmutable("{$todayManila} {$mod}", new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$yesterdayManila = $manilaDate('-1 day');
$future1Manila = $manilaDate('+3 days');
$future2Manila = $manilaDate('+7 days');
$futureFreshManila = $manilaDate('+30 days');

// Invariant helper: verifies QOH == sum(remaining_quantity)
$assertInvariant = static function (int $productId, string $stepLabel) use ($pdo, $assert, $getQoh): void {
    $qoh = $getQoh($productId);
    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(remaining_quantity), 0) FROM product_batches WHERE product_id = ?');
    $sumStmt->execute([$productId]);
    $batchSum = (int)$sumStmt->fetchColumn();

    $assert(
        $qoh === $batchSum,
        "Invariant preserved: quantity_on_hand ({$qoh}) === sum(remaining_quantity) ({$batchSum}) at [{$stepLabel}]"
    );
};

// =========================================================================
// Scenario 1: Workspace boundary protection
// Only authorized Inventory Manager workspace can mutate stock or warning overrides;
// Cashier and Admin must be rejected.
// =========================================================================
echo "\n--- Scenario 1: Workspace Boundary Enforcement ---\n";

// 1.1 Product creation with starting stock by Cashier -> rejected
$_SESSION['role'] = 'cashier';
$throws(
    function () use ($productService, $cashierId, $future2Manila) {
        $productService->createProduct([
            'product_name' => 'Cashier Bread',
            'barcode' => 'CASHIER-BREAD-01',
            'initial_stock_quantity' => 10,
            'expiration_date' => $future2Manila,
        ], $cashierId);
    },
    RuntimeException::class,
    'Cashier rejected from creating product with starting stock'
);

// 1.2 Product creation with warning lead time override by Admin -> rejected
$_SESSION['role'] = 'admin';
$throws(
    function () use ($productService, $adminId, $future2Manila) {
        $productService->createProduct([
            'product_name' => 'Admin Bread',
            'barcode' => 'ADMIN-BREAD-01',
            'warning_lead_time_days' => 3,
        ], $adminId);
    },
    RuntimeException::class,
    'Admin rejected from creating product with warning override'
);

// 1.3 Supplier pullout recording by Cashier -> rejected
$cashierPullout = new SupplierPulloutService($pdo, null, 'cashier');
$throws(
    function () use ($cashierPullout, $cashierId) {
        $cashierPullout->recordPullout([
            'product_id' => 1,
            'batch_id' => 1,
            'quantity' => 2,
            'reason' => 'Unauthorized return',
        ], $cashierId);
    },
    RuntimeException::class,
    'Cashier rejected from recording supplier pullout'
);

// 1.4 Supplier pullout recording by Admin -> rejected
$adminPullout = new SupplierPulloutService($pdo, null, 'admin');
$throws(
    function () use ($adminPullout, $adminId) {
        $adminPullout->recordPullout([
            'product_id' => 1,
            'batch_id' => 1,
            'quantity' => 2,
            'reason' => 'Admin unauthorized return',
        ], $adminId);
    },
    RuntimeException::class,
    'Admin rejected from recording supplier pullout'
);

// =========================================================================
// Scenario 2: Opening stock with expiration date
// Product creation creates opening batch; total == batch remaining.
// =========================================================================
echo "\n--- Scenario 2: Opening Stock & Initial Batch Creation ---\n";
$_SESSION['role'] = 'inventory_manager';

$productId = $productService->createProduct([
    'product_name' => 'Special Ensaymada',
    'barcode' => 'ENS-SPECIAL-01',
    'sku' => 'SKU-ENS-01',
    'cost_price' => 20.00,
    'selling_price' => 35.00,
    'initial_stock_quantity' => 20,
    'expiration_date' => $future2Manila,
    'batch_number' => 'BATCH-OPEN',
    'warning_lead_time_days' => 2,
    'reorder_level' => 5,
    'category_id' => 1,
], $managerId);

$assert($productId > 0, "Product created with ID {$productId}");

// Verify initial inventory and batch
$invOnHand = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invOnHand === 20, "Inventory quantity on hand is 20 (got {$invOnHand})");

$openingBatch = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$productId}")->fetch();
$assert($openingBatch !== false, "Opening batch created in product_batches");
$assert((int)$openingBatch['remaining_quantity'] === 20, "Opening batch remaining quantity is 20");
$assert($openingBatch['expiration_date'] === $future2Manila, "Opening batch expiration date is {$future2Manila}");
$assert($openingBatch['batch_number'] === 'BATCH-OPEN', "Opening batch number is 'BATCH-OPEN'");

// Verify movement
$movement = $pdo->query("SELECT * FROM stock_movements WHERE product_id = {$productId}")->fetch();
$assert($movement !== false, "Initial stock movement recorded");
$assert((int)$movement['change_qty'] === 20 && $movement['reason'] === 'purchase', "Movement reason 'purchase' for +20");

$assertInvariant($productId, 'Opening Stock Created');

// =========================================================================
// Scenario 3: Stock Receiving with deliveries on different expiration dates
// Separate batches created; total equals sum of batch remaining.
// =========================================================================
echo "\n--- Scenario 3: Stock Receiving With Multiple Expiration Dates ---\n";

// Delivery 1: 15 units expiring in +3 days
$rec1 = $receivingService->receiveStock([
    'product_id' => $productId,
    'received_qty' => 15,
    'expiration_date' => $future1Manila,
    'batch_number' => 'BATCH-REC-1',
    'supplier' => 'Bakery Supply A',
], $managerId);
$assert($rec1['accepted_qty'] === 15, "Delivery 1 accepted 15 units");

// Delivery 2: 10 units expired yesterday
$rec2 = $receivingService->receiveStock([
    'product_id' => $productId,
    'received_qty' => 10,
    'expiration_date' => $yesterdayManila,
    'batch_number' => 'BATCH-EXPIRED',
    'supplier' => 'Bakery Supply B',
], $managerId);
$assert($rec2['accepted_qty'] === 10, "Delivery 2 accepted 10 units expired yesterday");

// Delivery 3: 5 units undated (NULL expiration)
$rec3 = $receivingService->receiveStock([
    'product_id' => $productId,
    'received_qty' => 5,
    'expiration_date' => null,
    'batch_number' => 'BATCH-UNDATED',
    'supplier' => 'Bakery Supply C',
], $managerId);
$assert($rec3['accepted_qty'] === 5, "Delivery 3 accepted 5 undated units");

// Delivery 4: 8 units expiring TODAY (boundary: expiration day is sellable)
$rec4 = $receivingService->receiveStock([
    'product_id' => $productId,
    'received_qty' => 8,
    'expiration_date' => $todayManila,
    'batch_number' => 'BATCH-TODAY',
    'supplier' => 'Bakery Supply D',
], $managerId);
$assert($rec4['accepted_qty'] === 8, "Delivery 4 accepted 8 units expiring today");

// Total stock should now be: 20 + 15 + 10 + 5 + 8 = 58
$invAfterReceiving = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invAfterReceiving === 58, "Inventory quantity on hand is 58 (got {$invAfterReceiving})");

$batchCount = (int)$pdo->query("SELECT COUNT(*) FROM product_batches WHERE product_id = {$productId}")->fetchColumn();
$assert($batchCount === 5, "Five distinct batches exist in product_batches");

$assertInvariant($productId, 'Stock Receiving Completed');

// Retrieve batch IDs for later scenarios
$batchOpenId = (int)$openingBatch['batch_id'];
$batchRec1Id = (int)$pdo->query("SELECT batch_id FROM product_batches WHERE product_id = {$productId} AND batch_number = 'BATCH-REC-1'")->fetchColumn();
$batchExpiredId = (int)$pdo->query("SELECT batch_id FROM product_batches WHERE product_id = {$productId} AND batch_number = 'BATCH-EXPIRED'")->fetchColumn();
$batchUndatedId = (int)$pdo->query("SELECT batch_id FROM product_batches WHERE product_id = {$productId} AND batch_number = 'BATCH-UNDATED'")->fetchColumn();
$batchTodayId = (int)$pdo->query("SELECT batch_id FROM product_batches WHERE product_id = {$productId} AND batch_number = 'BATCH-TODAY'")->fetchColumn();

// =========================================================================
// Scenario 4: Stock Issue Approval (Damage Deduction)
// Cashier reports damage -> Manager approves specifying batch ->
// Batch deducted, total deducted, 1 movement record, batch attribution preserved.
// =========================================================================
echo "\n--- Scenario 4: Stock Issue Approval (Damage Deduction) ---\n";

// Cashier opens shift
$shiftId = $shiftsService->openShift($cashierId, 'cashier', 1, 1000.00);
$assert($shiftId > 0, "Cashier shift #{$shiftId} opened");

// Cashier reports 4 units damaged
$adjustmentId = $stockIssueService->submitReport([
    'product_id' => $productId,
    'category' => 'damaged',
    'quantity' => 4,
    'explanation' => 'Four loaves crushed during shelf restocking',
], $cashierId);
$assert($adjustmentId > 0, "Stock issue report #{$adjustmentId} submitted as pending");

// Pending report does NOT deduct inventory or batches
$invBeforeApproval = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invBeforeApproval === 58, "Inventory unchanged while report is pending (58)");
$assertInvariant($productId, 'Stock Issue Pending');

// Inventory Manager approves damage specifying batch: 4 units from BATCH-REC-1
$movementsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = {$productId}")->fetchColumn();
$stockIssueService->approveReport($adjustmentId, $managerId, 'Confirmed damage on shelf', [$batchRec1Id => 4]);
$pdo->exec("UPDATE product_batches SET loose_quantity = remaining_quantity WHERE sealed_quantity = 0");

$remRec1After = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchRec1Id}")->fetchColumn();
$assert($remRec1After === 11, "BATCH-REC-1 deducted by 4 (15 -> 11)");

$invAfterApproval = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invAfterApproval === 54, "Inventory on hand deducted by 4 (58 -> 54)");

// Exactly 1 movement record added for this approval
$movementsCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = {$productId}")->fetchColumn();
$assert($movementsCountAfter === $movementsCountBefore + 1, "Exactly one stock movement record created");

$issueMovement = $pdo->query("SELECT * FROM stock_movements WHERE adjustment_id = {$adjustmentId}")->fetch();
$assert($issueMovement !== false, "Movement row linked to adjustment_id #{$adjustmentId}");
$assert((int)$issueMovement['change_qty'] === -4 && $issueMovement['reason'] === 'adjustment', "Movement change_qty is -4 with reason 'adjustment'");

// Batch attribution preserved in stock_issue_batches
$issueBatch = $pdo->query("SELECT * FROM stock_issue_batches WHERE adjustment_id = {$adjustmentId}")->fetch();
$assert($issueBatch !== false && (int)$issueBatch['batch_id'] === $batchRec1Id && (int)$issueBatch['quantity'] === 4, "Batch attribution preserved in stock_issue_batches");

$assertInvariant($productId, 'Stock Issue Approved');

// =========================================================================
// Scenario 4B: Canonical Balance Scenario (Spec Line 106)
// Begin with 20 loaves, approve three damaged, and verify both total and batch
// show 17 with one movement.
// =========================================================================
echo "\n--- Scenario 4B: Canonical Balance Scenario (Spec Line 106) ---\n";

$canonicalPid = $productService->createProduct([
    'product_name' => 'Monay Loaves',
    'barcode' => 'MONAY-CANONICAL-20',
    'sku' => 'SKU-MONAY-CANONICAL',
    'initial_stock_quantity' => 20,
    'expiration_date' => $future1Manila,
    'batch_number' => 'BATCH-MONAY-INIT',
], $managerId);

$canonicalBatchId = (int)$pdo->query("SELECT batch_id FROM product_batches WHERE product_id = {$canonicalPid}")->fetchColumn();
$assert($getQoh($canonicalPid) === 20, "Canonical initial total is 20");
$assert($getBatchRem($canonicalBatchId) === 20, "Canonical initial batch remaining is 20");
$assertInvariant($canonicalPid, 'Canonical Start 20 Loaves');

// Cashier reports 3 damaged loaves
$canonicalAdjId = $stockIssueService->submitReport([
    'product_id' => $canonicalPid,
    'category' => 'damaged',
    'quantity' => 3,
    'explanation' => 'Three loaves crushed on delivery shelf',
], $cashierId);

// Record movements count before approval
$canonicalMovementsBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = {$canonicalPid}")->fetchColumn();

// Manager approves 3 damaged loaves specifying batch
$stockIssueService->approveReport($canonicalAdjId, $managerId, 'Confirmed 3 damaged loaves', [$canonicalBatchId => 3]);

$canonicalQohAfter = $getQoh($canonicalPid);
$canonicalBatchAfter = $getBatchRem($canonicalBatchId);
$canonicalMovementsAfter = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = {$canonicalPid}")->fetchColumn();

$assert($canonicalQohAfter === 17, "Canonical total shows 17 after approving 3 damaged (got {$canonicalQohAfter})");
$assert($canonicalBatchAfter === 17, "Canonical batch shows 17 after approving 3 damaged (got {$canonicalBatchAfter})");
$assert($canonicalMovementsAfter === $canonicalMovementsBefore + 1, "Exactly one stock movement recorded for damage deduction");

$canonicalAttribution = $pdo->query("SELECT * FROM stock_issue_batches WHERE adjustment_id = {$canonicalAdjId}")->fetch();
$assert(
    $canonicalAttribution !== false && (int)$canonicalAttribution['batch_id'] === $canonicalBatchId && (int)$canonicalAttribution['quantity'] === 3,
    "Batch attribution saved in stock_issue_batches"
);
$assertInvariant($canonicalPid, 'Canonical Approved 3 Damaged -> 17');

// =========================================================================
// Scenario 5: POS / FEFO Sales & Manila Date Boundary
// Sales pick earliest unexpired first (FEFO);
// Expiration day is sellable (today sellable);
// Expired batches skipped (yesterday excluded);
// Undated stock used last.
// =========================================================================
echo "\n--- Scenario 5: POS / FEFO Sales & Manila Date Boundary ---\n";

// Current batch balances before sales:
// BATCH-TODAY: 8 units, exp = $todayManila (0 days)
// BATCH-REC-1: 11 units, exp = $future1Manila (+3 days)
// BATCH-OPEN: 20 units, exp = $future2Manila (+7 days)
// BATCH-EXPIRED: 10 units, exp = $yesterdayManila (-1 day, EXPIRED)
// BATCH-UNDATED: 5 units, exp = NULL (UNDATED)
// Total stock = 54. Sellable stock = 8 + 11 + 20 + 5 = 44. Expired stock = 10.

// Sale 1: Buy 13 units.
// FEFO must pick:
// 1. All 8 units from BATCH-TODAY (today is sellable! earliest unexpired first).
// 2. 5 units from BATCH-REC-1 (+3 days).
// Expired batch BATCH-EXPIRED (-1 day) must be SKIPPED!
// BATCH-OPEN and BATCH-UNDATED must NOT be touched.
$sale1 = $salesWorkflow->checkout(
    [['product_id' => $productId, 'qty' => 13]],
    $cashierId,
    'cashier',
    'cash',
    ['cash_received' => 500.00]
);
$assert(is_array($sale1) && ($sale1['sale_id'] ?? 0) > 0, "Sale 1 of 13 units checked out successfully");
$sale1Id = (int)$sale1['sale_id'];

$remToday = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchTodayId}")->fetchColumn();
$remRec1 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchRec1Id}")->fetchColumn();
$remExpired = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchExpiredId}")->fetchColumn();
$remOpen = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchOpenId}")->fetchColumn();
$remUndated = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchUndatedId}")->fetchColumn();

$assert($remToday === 0, "BATCH-TODAY (expiring today) fully sold: 8 units taken (remaining 0)");
$assert($remRec1 === 6, "BATCH-REC-1 partially sold: 5 units taken (11 -> 6)");
$assert($remExpired === 10, "BATCH-EXPIRED skipped by FEFO: 10 units remain untouched");
$assert($remOpen === 20, "BATCH-OPEN untouched: 20 units remain");
$assert($remUndated === 5, "BATCH-UNDATED untouched: 5 units remain");

$invAfterSale1 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invAfterSale1 === 41, "Inventory on hand is 41 (54 - 13)");
$assertInvariant($productId, 'FEFO Sale 1 Completed');

// Verify sale item batch allocation records
$allocations = $pdo->query("
    SELECT sib.batch_id, sib.quantity
    FROM sale_item_batches sib
    JOIN sale_items si ON si.sale_item_id = sib.sale_item_id
    WHERE si.sale_id = {$sale1Id}
    ORDER BY sib.batch_id ASC
")->fetchAll();
$assert(count($allocations) === 2, "Sale 1 allocated across exactly 2 batches");
$allocMap = array_column($allocations, 'quantity', 'batch_id');
$assert(($allocMap[$batchTodayId] ?? 0) === 8, "Allocated 8 units to BATCH-TODAY");
$assert(($allocMap[$batchRec1Id] ?? 0) === 5, "Allocated 5 units to BATCH-REC-1");

// Sale 2: Buy 28 units.
// Available sellable stock before Sale 2:
// - BATCH-REC-1: 6 units (+3 days)
// - BATCH-OPEN: 20 units (+7 days)
// - BATCH-UNDATED: 5 units (NULL)
// FEFO must take:
// 1. 6 units from BATCH-REC-1 (depletes to 0).
// 2. 20 units from BATCH-OPEN (depletes to 0).
// 3. 2 units from BATCH-UNDATED (undated stock used LAST!).
// Expired batch (10 units) must be SKIPPED!
$sale2 = $salesWorkflow->checkout(
    [['product_id' => $productId, 'qty' => 28]],
    $cashierId,
    'cashier',
    'cash',
    ['cash_received' => 1000.00]
);
$assert(is_array($sale2) && ($sale2['sale_id'] ?? 0) > 0, "Sale 2 of 28 units checked out successfully");

$remRec1_s2 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchRec1Id}")->fetchColumn();
$remOpen_s2 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchOpenId}")->fetchColumn();
$remExpired_s2 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchExpiredId}")->fetchColumn();
$remUndated_s2 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchUndatedId}")->fetchColumn();

$assert($remRec1_s2 === 0, "BATCH-REC-1 fully depleted (6 units taken)");
$assert($remOpen_s2 === 0, "BATCH-OPEN fully depleted (20 units taken)");
$assert($remExpired_s2 === 10, "BATCH-EXPIRED untouched at 10 units (skipped)");
$assert($remUndated_s2 === 3, "BATCH-UNDATED depleted last: 2 units taken (5 -> 3)");

$invAfterSale2 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invAfterSale2 === 13, "Inventory on hand is 13 (41 - 28)");
$assertInvariant($productId, 'FEFO Sale 2 Completed');

// Sale 3: Attempt to buy 4 units.
// Total inventory on hand is 13, BUT 10 are expired! Only 3 undated units are sellable.
// Must throw StockConflictException and preserve balances.
$throws(
    function () use ($salesWorkflow, $productId, $cashierId) {
        $salesWorkflow->checkout(
            [['product_id' => $productId, 'qty' => 4]],
            $cashierId,
            'cashier',
            'cash',
            ['cash_received' => 200.00]
        );
    },
    StockConflictException::class,
    'Checkout refused: sellable stock is 3, 10 expired units are strictly excluded'
);

$invAfterSale3 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invAfterSale3 === 13, "Inventory on hand preserved at 13 after refused sale");
$assertInvariant($productId, 'Refused Over-Selling Preserved Invariant');

// =========================================================================
// Scenario 6: Customer Returns (Restockable vs Damaged)
// Restockable refund restores units to original sale batch;
// Damaged refund does NOT double-deduct and does NOT restock.
// =========================================================================
echo "\n--- Scenario 6: Customer Returns (Restockable vs Damaged) ---\n";

$sale1ItemId = (int)$pdo->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$sale1Id}")->fetchColumn();

// 6.1 Customer returns 2 units as RESTOCKABLE:
// In Sale 1, BATCH-TODAY was the first batch allocated.
// Restockable return must restore 2 units into BATCH-TODAY!
$ref1 = $refundService->refund(
    $cashierId,
    'cashier',
    $sale1Id,
    [$sale1ItemId => ['quantity' => 2, 'disposition' => CashRefundService::RESTOCKABLE]],
    'customer_return'
);
$assert($ref1 > 0, "Restockable refund #{$ref1} recorded");

$remTodayAfterRef1 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchTodayId}")->fetchColumn();
$assert($remTodayAfterRef1 === 2, "BATCH-TODAY restored by 2 units (0 -> 2)");

$invAfterRef1 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invAfterRef1 === 15, "Inventory on hand restored by 2 (13 -> 15)");

$returnMovement = $pdo->query("SELECT * FROM stock_movements WHERE product_id = {$productId} AND reason = 'return' ORDER BY movement_id DESC LIMIT 1")->fetch();
$assert($returnMovement !== false && (int)$returnMovement['change_qty'] === 2, "Stock movement reason 'return' recorded for +2 units");

$assertInvariant($productId, 'Restockable Return Processed');

// 6.2 Customer returns 1 unit as DAMAGED:
// Damaged customer return must NOT restock to batches AND must NOT double-deduct!
$ref2 = $refundService->refund(
    $cashierId,
    'cashier',
    $sale1Id,
    [$sale1ItemId => ['quantity' => 1, 'disposition' => CashRefundService::DAMAGED]],
    'customer_return'
);
$assert($ref2 > 0, "Damaged refund #{$ref2} recorded");

$remTodayAfterRef2 = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchTodayId}")->fetchColumn();
$assert($remTodayAfterRef2 === 2, "BATCH-TODAY remaining quantity unchanged (still 2)");

$invAfterRef2 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invAfterRef2 === 15, "Inventory on hand unchanged (neither restocked nor double-deducted, still 15)");

$assertInvariant($productId, 'Damaged Return Processed');

// =========================================================================
// Scenario 7: Physical Count
// Batch count with shortages, extras, and undated units ->
// Updates batch remaining and total together atomically.
// =========================================================================
echo "\n--- Scenario 7: Physical Count (Shortages, Extras, Undated Units) ---\n";

// Current state:
// BATCH-TODAY: 2 remaining
// BATCH-EXPIRED: 10 remaining
// BATCH-UNDATED: 3 remaining
// Total on hand: 15.

// Physical audit finds:
// - BATCH-TODAY: physically counted 1 (shortage of 1)
// - BATCH-EXPIRED: physically counted 12 (extra of 2)
// - BATCH-UNDATED: physically counted 3 (exact match)
// - Brand new extra undated batch found on back shelf: 4 units (batch_id = null, expiration_date = null, batch_number = 'COUNT-EXTRA-UNDATED')
// Total physical quantity: 1 + 12 + 3 + 4 = 20 (system was 15, net difference = +5)
$countId = $inventoryCountService->recordCount([
    'product_id' => $productId,
    'discrepancy_reason' => 'Physical stock count discrepancy',
    'batches' => [
        ['batch_id' => $batchTodayId, 'physical_quantity' => 1],
        ['batch_id' => $batchExpiredId, 'physical_quantity' => 12],
        ['batch_id' => $batchUndatedId, 'physical_quantity' => 3],
        [
            'batch_id' => null,
            'physical_quantity' => 4,
            'expiration_date' => null,
            'batch_number' => 'COUNT-EXTRA-UNDATED',
            'notes' => 'Found unrecorded extra stock on storage shelf',
        ],
    ],
], $managerId);
$assert($countId > 0, "Physical count #{$countId} recorded in pending status");

// Approval applies batch updates and inventory atomically
$inventoryCountService->approveCount($countId, $managerId);

$remTodayCount = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchTodayId}")->fetchColumn();
$remExpiredCount = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchExpiredId}")->fetchColumn();
$remUndatedCount = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchUndatedId}")->fetchColumn();
$remExtraUndated = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE product_id = {$productId} AND batch_number = 'COUNT-EXTRA-UNDATED'")->fetchColumn();
$invAfterCount = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();

$assert($remTodayCount === 1, "BATCH-TODAY remaining adjusted to counted 1 (shortage recorded)");
$assert($remExpiredCount === 12, "BATCH-EXPIRED remaining adjusted to counted 12 (extra recorded)");
$assert($remUndatedCount === 3, "BATCH-UNDATED remaining confirmed at 3");
$assert($remExtraUndated === 4, "New undated batch created with remaining 4");
$assert($invAfterCount === 20, "Inventory quantity on hand atomically updated to 20");

// Check adjustment movement
$countMovement = $pdo->query("SELECT * FROM stock_movements WHERE product_id = {$productId} AND reason = 'adjustment' ORDER BY movement_id DESC LIMIT 1")->fetch();
$assert($countMovement !== false && (int)$countMovement['change_qty'] === 5, "Stock movement reason 'adjustment' recorded for net difference +5");

$assertInvariant($productId, 'Physical Count Approved');

// =========================================================================
// Scenario 8: Supplier Pullouts
// Record pullout of specific batch (including expired batches) ->
// Deducts batch and inventory together, records movement reason 'supplier_pullout'.
// =========================================================================
echo "\n--- Scenario 8: Supplier Pullout of Expired Stock ---\n";

// Pull out 8 units of the 12 expired loaves from BATCH-EXPIRED
$pulloutResult = $pulloutService->recordPullout([
    'product_id' => $productId,
    'batch_id' => $batchExpiredId,
    'quantity' => 8,
    'reason' => 'Defective / expired supplier collection',
    'supplier' => 'Bakery Supply B',
    'notes' => 'Collected by driver Juan',
], $managerId);

$pulloutId = (int)$pulloutResult['pullout_id'];
$assert($pulloutId > 0, "Supplier pullout #{$pulloutId} recorded");

$remExpiredAfterPullout = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = {$batchExpiredId}")->fetchColumn();
$assert($remExpiredAfterPullout === 4, "BATCH-EXPIRED deducted by 8 (12 -> 4)");

$invAfterPullout = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}")->fetchColumn();
$assert($invAfterPullout === 12, "Inventory on hand deducted by 8 (20 -> 12)");

// Verify movement reason 'supplier_pullout'
$pulloutMovement = $pdo->query("SELECT * FROM stock_movements WHERE pullout_id = {$pulloutId}")->fetch();
$assert($pulloutMovement !== false, "Movement row linked to pullout_id #{$pulloutId}");
$assert((int)$pulloutMovement['change_qty'] === -8 && $pulloutMovement['reason'] === 'supplier_pullout', "Movement reason 'supplier_pullout' with change_qty -8");

$assertInvariant($productId, 'Supplier Pullout Completed');

// =========================================================================
// Scenario 9: Linked Replacements
// Receive replacement linked to pullout ->
// Creates new batch with fresh date, preserves old batch date and history,
// links to pullout_id, total equals sum of batches. Supports partial and delayed deliveries (total = 30).
// =========================================================================
echo "\n--- Scenario 9: Linked Supplier Replacement Receiving (Partial & Delayed = 30) ---\n";

// Delivery 9.1: Partial replacement of 10 fresh loaves linked to pullout
$replacement1 = $receivingService->receiveStock([
    'product_id' => $productId,
    'received_qty' => 10,
    'expiration_date' => $futureFreshManila,
    'batch_number' => 'BATCH-REP-1',
    'pullout_id' => $pulloutId,
    'supplier' => 'Bakery Supply B',
    'notes' => 'Partial replacement delivery for pullout #' . $pulloutId,
], $managerId);

$assert($replacement1['accepted_qty'] === 10, "Partial replacement accepted 10 units");
$assert((int)$replacement1['pullout_id'] === $pulloutId, "Partial replacement links to pullout_id #{$pulloutId}");

// Delivery 9.2: Delayed replacement of 20 fresh loaves linked to same pullout
$futureDelayedManila = $manilaDate('+45 days');
$replacement2 = $receivingService->receiveStock([
    'product_id' => $productId,
    'received_qty' => 20,
    'expiration_date' => $futureDelayedManila,
    'batch_number' => 'BATCH-REP-2',
    'pullout_id' => $pulloutId,
    'supplier' => 'Bakery Supply B',
    'notes' => 'Delayed remaining replacement delivery for pullout #' . $pulloutId,
], $managerId);

$assert($replacement2['accepted_qty'] === 20, "Delayed replacement accepted 20 units");
$assert((int)$replacement2['pullout_id'] === $pulloutId, "Delayed replacement links to pullout_id #{$pulloutId}");

// Verify both replacement batches created with fresh dates
$repBatch1 = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$productId} AND batch_number = 'BATCH-REP-1'")->fetch();
$repBatch2 = $pdo->query("SELECT * FROM product_batches WHERE product_id = {$productId} AND batch_number = 'BATCH-REP-2'")->fetch();
$assert($repBatch1 !== false && (int)$repBatch1['remaining_quantity'] === 10, "Replacement batch 1 has 10 units");
$assert($repBatch1['expiration_date'] === $futureFreshManila, "Replacement batch 1 has fresh date ({$futureFreshManila})");
$assert($repBatch2 !== false && (int)$repBatch2['remaining_quantity'] === 20, "Replacement batch 2 has 20 units");
$assert($repBatch2['expiration_date'] === $futureDelayedManila, "Replacement batch 2 has fresh date ({$futureDelayedManila})");

// Verify old batch date and history preserved
$oldBatch = $pdo->query("SELECT * FROM product_batches WHERE batch_id = {$batchExpiredId}")->fetch();
$assert($oldBatch['expiration_date'] === $yesterdayManila, "Old batch expiration date preserved as {$yesterdayManila}");
$assert((int)$oldBatch['remaining_quantity'] === 4, "Old batch remaining quantity preserved as 4");

// Total on hand: 12 + 10 + 20 = 42
$invAfterReplacement = $getQoh($productId);
$assert($invAfterReplacement === 42, "Inventory on hand incremented to 42 (12 + 10 + 20)");

$assertInvariant($productId, 'Linked Replacement Receiving Completed (30 Units)');

// Verify pullout service summary shows replaced quantity linked (total 30)
$pulloutSummary = $pulloutService->getPullout($pulloutId);
$assert($pulloutSummary !== null, "getPullout({$pulloutId}) returns pullout details");
$assert((int)$pulloutSummary['total_replaced_qty'] === 30, "Pullout details show 30 total_replaced_qty across partial/delayed deliveries");

// =========================================================================
// Scenario 10: Atomic Rollback on Failure Across Writers
// Failure in write rolls back batches, inventory, movement, and pullout/receiving records.
// =========================================================================
echo "\n--- Scenario 10: Atomic Rollback on Failure & Safety Enforcements ---\n";

// 10.1 Multi-line receiving rollback on failure
// Create a second product for line 2 to avoid duplicate product in single trip
$product2Id = $productService->createProduct([
    'product_name' => 'Cheese Roll',
    'barcode' => 'CHEESE-ROLL-01',
    'sku' => 'SKU-CROLL-01',
    'initial_stock_quantity' => 5,
    'expiration_date' => $futureFreshManila,
    'batch_number' => 'BATCH-CROLL-INIT',
], $managerId);
$product2BatchId = (int)$pdo->query("SELECT batch_id FROM product_batches WHERE product_id = {$product2Id}")->fetchColumn();

$invBeforeTrip = $getQoh($productId);
$inv2BeforeTrip = $getQoh($product2Id);
$batchesBeforeTrip = (int)$pdo->query("SELECT COUNT(*) FROM product_batches")->fetchColumn();
$groupsBeforeTrip = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving_groups")->fetchColumn();
$receivingBeforeTrip = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving")->fetchColumn();
$movementsBeforeTrip = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

$throws(
    function () use ($receivingService, $productId, $product2Id, $pulloutId, $managerId) {
        $receivingService->receiveShoppingTrip([
            [
                'product_id' => $productId,
                'received_qty' => 5,
                'pullout_id' => $pulloutId, // Valid line
            ],
            [
                'product_id' => $product2Id,
                'received_qty' => 5,
                'pullout_id' => 999999, // Invalid: non-existent pullout
            ],
        ], [
            'supplier' => 'FailingTripSupplier',
        ], $managerId);
    },
    RuntimeException::class,
    'Multi-line trip threw expected pullout validation error',
    'Linked supplier pullout not found'
);

// Verify complete rollback: inventory, batches, groups, receiving rows, movements
$invAfterTrip = $getQoh($productId);
$inv2AfterTrip = $getQoh($product2Id);
$batchesAfterTrip = (int)$pdo->query("SELECT COUNT(*) FROM product_batches")->fetchColumn();
$groupsAfterTrip = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving_groups")->fetchColumn();
$receivingAfterTrip = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving")->fetchColumn();
$movementsAfterTrip = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

$assert($invAfterTrip === $invBeforeTrip, "Product 1 inventory unchanged after rollback ({$invAfterTrip} === {$invBeforeTrip})");
$assert($inv2AfterTrip === $inv2BeforeTrip, "Product 2 inventory unchanged after rollback ({$inv2AfterTrip} === {$inv2BeforeTrip})");
$assert($batchesAfterTrip === $batchesBeforeTrip, "Product batches count unchanged after rollback");
$assert($groupsAfterTrip === $groupsBeforeTrip, "No orphaned stock_receiving_groups row created");
$assert($receivingAfterTrip === $receivingBeforeTrip, "No orphaned stock_receiving row created");
$assert($movementsAfterTrip === $movementsBeforeTrip, "Stock movements count unchanged after trip rollback");
$assertInvariant($productId, 'Multi-Line Receiving Rollback (Product 1)');
$assertInvariant($product2Id, 'Multi-Line Receiving Rollback (Product 2)');

// 10.2 Supplier pullout rollback on excessive quantity
$remExpiredBeforeExcess = $getBatchRem($batchExpiredId);
$invBeforeExcess = $getQoh($productId);
$pulloutsCountBeforeExcess = (int)$pdo->query("SELECT COUNT(*) FROM supplier_pullouts")->fetchColumn();
$movementsBeforeExcess = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

$throws(
    function () use ($pulloutService, $productId, $batchExpiredId, $managerId) {
        $pulloutService->recordPullout([
            'product_id' => $productId,
            'batch_id' => $batchExpiredId,
            'quantity' => 999, // Exceeds remaining 4
            'reason' => 'Should fail due to excessive quantity',
        ], $managerId);
    },
    RuntimeException::class,
    'Excessive pullout threw expected error',
    'exceeds batch remaining balance'
);

$remExpiredAfterExcess = $getBatchRem($batchExpiredId);
$invAfterExcess = $getQoh($productId);
$pulloutsCountAfterExcess = (int)$pdo->query("SELECT COUNT(*) FROM supplier_pullouts")->fetchColumn();
$movementsAfterExcess = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

$assert($remExpiredAfterExcess === $remExpiredBeforeExcess, "Batch remaining unchanged after failed pullout");
$assert($invAfterExcess === $invBeforeExcess, "Inventory on hand unchanged after failed pullout");
$assert($pulloutsCountAfterExcess === $pulloutsCountBeforeExcess, "No orphaned supplier_pullouts row created");
$assert($movementsAfterExcess === $movementsBeforeExcess, "Stock movements count unchanged after pullout failure");
$assertInvariant($productId, 'Excessive Pullout Rollback');

// 10.3 Stock Issue approval rollback on mismatched batch allocation
$adjRollbackId = $stockIssueService->submitReport([
    'product_id' => $productId,
    'category' => 'damaged',
    'quantity' => 3,
    'explanation' => 'Rollback verification damage',
], $cashierId);

$movementsBeforeIssueFail = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

$throws(
    function () use ($stockIssueService, $adjRollbackId, $managerId) {
        $stockIssueService->approveReport($adjRollbackId, $managerId, 'Should fail', [999999 => 3]);
    },
    RuntimeException::class,
    'Invalid batch approval threw expected error',
    'not found'
);

$adjStatus = $pdo->query("SELECT status FROM inventory_adjustments WHERE adjustment_id = {$adjRollbackId}")->fetchColumn();
$assert($adjStatus === 'pending', "Stock issue report remains pending after failed approval");

$invAfterAdjFail = $getQoh($productId);
$movementsAfterIssueFail = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

$assert($invAfterAdjFail === $invBeforeExcess, "Inventory on hand untouched after failed approval ({$invAfterAdjFail})");
$assert($movementsAfterIssueFail === $movementsBeforeIssueFail, "Stock movements count unchanged after failed approval");
$assertInvariant($productId, 'Stock Issue Approval Rollback');

// 10.4 Safety: Reject Wrong-Product Batch Selection (Spec line 108)
$movementsBeforeWrongBatch = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

// Attempt pullout on Product 1 using Product 2's batch
$throws(
    function () use ($pulloutService, $productId, $product2BatchId, $managerId) {
        $pulloutService->recordPullout([
            'product_id' => $productId,
            'batch_id' => $product2BatchId,
            'quantity' => 1,
            'reason' => 'Wrong product batch pullout attempt',
        ], $managerId);
    },
    RuntimeException::class,
    'Pullout rejected batch belonging to different product',
    'does not belong'
);

// Attempt stock issue approval on Product 1 using Product 2's batch
$adjWrongBatchId = $stockIssueService->submitReport([
    'product_id' => $productId,
    'category' => 'damaged',
    'quantity' => 1,
    'explanation' => 'Wrong product batch damage test',
], $cashierId);

$throws(
    function () use ($stockIssueService, $adjWrongBatchId, $managerId, $product2BatchId) {
        $stockIssueService->approveReport($adjWrongBatchId, $managerId, 'Wrong batch test', [$product2BatchId => 1]);
    },
    RuntimeException::class,
    'Stock issue approval rejected batch belonging to different product',
    'does not belong'
);

$movementsAfterWrongBatch = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
$assert($movementsAfterWrongBatch === $movementsBeforeWrongBatch, "Movements unchanged after rejected wrong-product operations");
$assertInvariant($productId, 'Wrong-Product Batch Safety');

// =========================================================================
// Scenario 11: Legacy Reconciliation (Spec Line 109)
// Identify totals with missing/unequal batch coverage -> require explicit counted correction.
// =========================================================================
echo "\n--- Scenario 11: Legacy Reconciliation (Missing/Unequal Batch Coverage) ---\n";

// Insert a legacy product directly without batches
$pdo->prepare("INSERT INTO products (branch_id, sku, barcode, product_name, unit_price, cost_price, status) VALUES (1, 'SKU-LEGACY-01', 'LEGACY-BARCODE-01', 'Legacy Pan de Sal', 5.00, 3.00, 'active')")->execute();
$legacyPid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand, reorder_level) VALUES (?, 25, 5)")->execute([$legacyPid]);

// Query batch summary via InventoryService
$summaries = $inventoryService->getProductsWithBatchSummaries();
$legacySummary = null;
foreach ($summaries as $s) {
    if ((int)$s['product_id'] === $legacyPid) {
        $legacySummary = $s;
        break;
    }
}
$assert($legacySummary !== null, "getProductsWithBatchSummaries() includes legacy product");
$assert((bool)$legacySummary['has_unbatched_stock'] === true, "Legacy product identified as having unbatched stock");
$assert((int)$legacySummary['unbatched_quantity'] === 25, "Unbatched quantity accurately reported as 25");
$assert($legacySummary['next_expiration'] === null, "Legacy product does not invent batch date (next_expiration is null)");

// Perform explicit counted correction via InventoryCountService
$legacyCountId = $inventoryCountService->recordCount([
    'product_id' => $legacyPid,
    'discrepancy_reason' => 'Legacy unbatched stock initial physical count',
    'batches' => [
        [
            'batch_id' => null,
            'physical_quantity' => 15,
            'expiration_date' => $future1Manila,
            'batch_number' => 'COUNT-LEGACY-DATED',
            'notes' => 'Counted dated front shelf loaves',
        ],
        [
            'batch_id' => null,
            'physical_quantity' => 10,
            'expiration_date' => null,
            'batch_number' => 'COUNT-LEGACY-UNDATED',
            'notes' => 'Counted undated back storage loaves',
        ],
    ],
], $managerId);
$assert($legacyCountId > 0, "Counted correction #{$legacyCountId} recorded for legacy product");

// Approve counted correction
$inventoryCountService->approveCount($legacyCountId, $managerId);

// Verify legacy stock reconciled
$legacyQohAfter = $getQoh($legacyPid);
$assert($legacyQohAfter === 25, "Legacy inventory on hand confirmed at 25");

$reconciledSummaries = $inventoryService->getProductsWithBatchSummaries();
$reconciledLegacy = null;
foreach ($reconciledSummaries as $s) {
    if ((int)$s['product_id'] === $legacyPid) {
        $reconciledLegacy = $s;
        break;
    }
}
$assert($reconciledLegacy !== null, "Reconciled legacy product found in summaries");
$assert((bool)$reconciledLegacy['has_unbatched_stock'] === false, "Legacy product no longer has unbatched stock after count");
$assert((int)$reconciledLegacy['undated_quantity'] === 10, "Legacy product correctly shows 10 undated_quantity");
$assert($reconciledLegacy['next_expiration'] === $future1Manila, "Legacy product now correctly shows next expiration date ({$future1Manila})");
$assertInvariant($legacyPid, 'Legacy Product Reconciled Via Counted Correction');


// =========================================================================
// Scenario 12: Ticket #249 Cross-Workflow Stock Forms (Receiving -> Pullout -> Replacement -> Checkout)
// =========================================================================
echo "\n--- Scenario 12: Stock Forms Preserved Across Supplier Pullouts, Replacements & Checkout ---\n";
$_SESSION['user_id'] = $managerId;
$_SESSION['role'] = 'inventory_manager';
$_SESSION['active_role'] = 'inventory_manager';

$packPid = $productService->createProduct([
    'sku' => 'SKU-PACK-249',
    'barcode' => 'BC-PIECE-249',
    'pack_barcode' => 'BC-PACK-249',
    'product_name' => 'Cross-Workflow Biscuits 6-Pack',
    'category_id' => 1,
    'unit_price' => 10.00,
    'pack_price' => 55.00,
    'cost_price' => 7.00,
    'pack_size' => 6,
    'selling_mode' => 'both',
    'units_per_package' => 6,
    'selling_price' => 10.00,
    'initial_stock_quantity' => 0,
], $managerId);

// 1. Receive initial batch: 4 sealed packs + 5 loose pieces = 29 base units
$initRec = $receivingService->receiveStock([
    'product_id' => $packPid,
    'received_packs' => 4,
    'received_loose' => 5,
    'cost_price' => '7.00',
    'batch_number' => 'CW-PACK-SRC',
    'expiration_date' => $future1Manila,
    'supplier' => 'BiscuitCo',
], $managerId);
$srcBatchId = (int)$pdo->query("SELECT batch_id FROM product_batches WHERE product_id = {$packPid} AND batch_number = 'CW-PACK-SRC'")->fetchColumn();
$assert($srcBatchId > 0 && $getQoh($packPid) === 29, 'Initial pack+loose batch received with 29 base units');
$assertInvariant($packPid, 'Initial Pack+Loose Receiving');

// 2. Pull out 1 sealed pack + 2 loose pieces (8 base units) from source batch
$cwPullout = $pulloutService->recordPullout([
    'product_id' => $packPid,
    'batch_id' => $srcBatchId,
    'pulled_packs' => 1,
    'pulled_loose' => 2,
    'reason' => 'Crushed pack and loose wrappers',
], $managerId);
$cwPulloutId = $cwPullout['pullout_id'];
$assert($cwPullout['remaining_batch_packs'] === 3 && $cwPullout['remaining_batch_loose'] === 3 && $getQoh($packPid) === 21, 'Pullout leaves 3 packs + 3 loose = 21 base units in source batch');
$assertInvariant($packPid, 'After Stock-Form Supplier Pullout');

// 3. Receive linked replacement: 2 sealed packs + 1 loose piece (13 base units) with new expiration date
$cwRep = $receivingService->receiveStock([
    'product_id' => $packPid,
    'received_packs' => 2,
    'received_loose' => 1,
    'cost_price' => '7.25',
    'batch_number' => 'CW-PACK-REP',
    'expiration_date' => $futureFreshManila,
    'pullout_id' => $cwPulloutId,
], $managerId);
$assert($cwRep['accepted_qty'] === 13 && $getQoh($packPid) === 34, 'Replacement adds 2 packs + 1 loose = 13 base units (total 34)');
$assertInvariant($packPid, 'After Linked Stock-Form Replacement');

// 4. Checkout 1 sealed pack (6 base units) + 2 loose pieces (2 base units) = 8 base units via POS
$_SESSION['user_id'] = $cashierId;
$_SESSION['role'] = 'cashier';
$_SESSION['active_role'] = 'cashier';
$cwSale = $salesWorkflow->checkout([
    ['product_id' => $packPid, 'selling_form' => 'pack', 'qty' => 1],
    ['product_id' => $packPid, 'selling_form' => 'piece', 'qty' => 2],
], $cashierId, 'cashier', 'cash', ['cash_received' => 100.00]);
$assert($cwSale['sale_id'] > 0 && $getQoh($packPid) === 26, 'POS checkout of 1 pack + 2 pieces deducts 8 base units leaving 26');
$assertInvariant($packPid, 'After Stock-Form POS Checkout');

// Verify FEFO batch forms after pullout + replacement + checkout
$cwBatches = $inventoryService->getProductBatches($packPid);
$cwByNum = [];
foreach ($cwBatches as $b) { $cwByNum[$b['batch_number']] = $b; }
$assert($cwByNum['CW-PACK-SRC']['sealed_quantity'] === 2 && $cwByNum['CW-PACK-SRC']['loose_quantity'] === 1 && $cwByNum['CW-PACK-SRC']['remaining_quantity'] === 13, 'FEFO checkout deducted 1 pack + 2 loose from earliest batch CW-PACK-SRC (now 2 packs, 1 loose, 13 base units)');
$assert($cwByNum['CW-PACK-REP']['sealed_quantity'] === 2 && $cwByNum['CW-PACK-REP']['loose_quantity'] === 1 && $cwByNum['CW-PACK-REP']['remaining_quantity'] === 13, 'Replacement batch CW-PACK-REP retains 2 packs, 1 loose, 13 base units');

// =========================================================================
// Final Summary & Verification
// =========================================================================
echo "\n--- Final Summary ---\n";
if (empty($failures)) {
    echo "All cross-workflow batch verification tests passed successfully.\n";
    exit(0);
}

echo "Completed with " . count($failures) . " failure(s):\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
