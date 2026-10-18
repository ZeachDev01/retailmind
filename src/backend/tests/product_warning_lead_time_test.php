<?php
// Contract and integration tests for Ticket #223:
// Optional warning_lead_time_days on products, Manila midnight boundary, and notification milestone overrides.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/NotificationService.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

echo "Starting Ticket #223 Product Warning Lead Time tests...\n";

// --- 1. Test Schema Migration ---
echo "Testing Schema Migration adds column cleanly...\n";
$migPdo = new PDO('sqlite::memory:');
$migPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migPdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_name TEXT NOT NULL,
    expiration_date TEXT NULL
)");

$migration = require __DIR__ . '/../database/migrations/202610180003_product_warning_lead_time.php';
$assert(is_array($migration) && isset($migration['up']), 'Migration file exports up callable');
$migration['up']($migPdo);

$cols = $migPdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_COLUMN, 1);
$assert(in_array('warning_lead_time_days', $cols, true), 'Migration adds warning_lead_time_days to products table');

// Idempotent re-run
$migration['up']($migPdo);
$colsAfter = $migPdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_COLUMN, 1);
$assert(in_array('warning_lead_time_days', $colsAfter, true), 'Migration re-run is safe and idempotent');


// --- 2. Full Test Environment Setup ---
class SqliteTestPdo extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), $options);
    }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return parent::query(preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), ...$fetchModeArgs);
    }
}

$pdo = new SqliteTestPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->sqliteCreateFunction('GET_LOCK', static fn(): int => 1, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', static fn(): int => 1, 1);
$pdo->sqliteCreateFunction('SHA2', static fn(string $val, int $len): string => hash('sha256', $val), 2);
$pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'test', 0);
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => '2026-10-18', 0);
$pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-10-18 10:00:00', 0);
$pdo->sqliteCreateFunction('DATE', static fn(string $d): string => substr($d, 0, 10), 1);
$pdo->sqliteCreateFunction('DATEDIFF', static fn(string $d1, string $d2): int => (int)round((strtotime($d1) - strtotime($d2)) / 86400), 2);

$pdo->exec("
    CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY,
        branch_name TEXT,
        branch_code TEXT,
        status TEXT DEFAULT 'active'
    );
    INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Branch', 'MAIN', 'active');

    CREATE TABLE roles (
        role_id INTEGER PRIMARY KEY AUTOINCREMENT,
        role_name TEXT NOT NULL UNIQUE
    );
    INSERT INTO roles (role_id, role_name) VALUES (1, 'admin'), (2, 'cashier'), (3, 'super_admin'), (4, 'inventory_manager');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL DEFAULT 'User',
        username TEXT NULL,
        password_hash TEXT NULL,
        role_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'active',
        branch_id INTEGER NOT NULL DEFAULT 1
    );
    INSERT INTO users (user_id, full_name, username, role_id, branch_id) VALUES
        (1, 'Inventory Mgr', 'mgr', 4, 1),
        (2, 'Store Cashier', 'cashier1', 2, 1);

    CREATE TABLE user_roles (
        user_id INTEGER,
        role_id INTEGER
    );
    INSERT INTO user_roles (user_id, role_id) VALUES (1, 4), (2, 2);

    CREATE TABLE categories (
        category_id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_name TEXT NOT NULL
    );
    INSERT INTO categories (category_id, category_name) VALUES (1, 'Bakery'), (2, 'Dairy');

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT,
        barcode TEXT,
        case_barcode TEXT,
        parent_product_id INTEGER DEFAULT NULL,
        variant_label TEXT DEFAULT NULL,
        product_name TEXT NOT NULL,
        brand TEXT DEFAULT NULL,
        category_id INTEGER DEFAULT NULL,
        unit_price REAL NOT NULL DEFAULT 0.0,
        cost_price REAL NOT NULL DEFAULT 0.0,
        quantity_purchased INTEGER NOT NULL DEFAULT 0,
        quantity_sold INTEGER NOT NULL DEFAULT 0,
        reorder_level INTEGER NOT NULL DEFAULT 10,
        supplier TEXT DEFAULT NULL,
        preferred_supplier TEXT DEFAULT NULL,
        supplier_lead_time_days INTEGER NOT NULL DEFAULT 7,
        safety_stock INTEGER NOT NULL DEFAULT 0,
        minimum_order_quantity INTEGER NOT NULL DEFAULT 1,
        units_per_package INTEGER NOT NULL DEFAULT 1,
        base_unit TEXT NOT NULL DEFAULT 'piece',
        receiving_unit TEXT NOT NULL DEFAULT 'package',
        expiration_date TEXT DEFAULT NULL,
        warning_lead_time_days INTEGER DEFAULT NULL,
        product_image TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        branch_id INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inventory (
        product_id INTEGER PRIMARY KEY,
        quantity_on_hand INTEGER NOT NULL DEFAULT 0,
        branch_id INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        receiving_id INTEGER DEFAULT NULL,
        batch_number TEXT DEFAULT NULL,
        quantity INTEGER NOT NULL DEFAULT 0,
        remaining_quantity INTEGER NOT NULL DEFAULT 0,
        expiration_date TEXT DEFAULT NULL,
        date_received TEXT NOT NULL,
        supplier TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE stock_receiving (
        receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        accepted_qty INTEGER NOT NULL,
        supplier TEXT DEFAULT NULL,
        batch_number TEXT DEFAULT NULL,
        expiration_date TEXT DEFAULT NULL,
        discrepancy_type TEXT DEFAULT 'none',
        discrepancy_notes TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT NOT NULL,
        moved_by INTEGER NOT NULL,
        moved_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE purchase_history (
        purchase_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        quantity_purchased INTEGER NOT NULL,
        cost_price REAL NOT NULL,
        purchase_date TEXT NOT NULL,
        total_cost REAL NOT NULL,
        recorded_by INTEGER NOT NULL
    );

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open'
    );
    INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open');

    CREATE TABLE fiscal_period_locks (
        lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_id INTEGER NOT NULL,
        table_name TEXT NULL
    );

    CREATE TABLE store_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );
    INSERT INTO store_settings (setting_key, setting_value) VALUES ('expiry_alert_days', '90,60,30,14,7');

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT,
        category TEXT,
        module TEXT,
        record_id INTEGER,
        previous_value TEXT,
        new_value TEXT,
        metadata TEXT,
        ip_address TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        type TEXT,
        title TEXT,
        message TEXT,
        reference_id INTEGER,
        reference_type TEXT,
        is_read INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE notification_preferences (
        user_id INTEGER PRIMARY KEY,
        notify_low_stock INTEGER DEFAULT 1,
        notify_expiring_stock INTEGER DEFAULT 1,
        low_stock_threshold INTEGER DEFAULT NULL
    );
    INSERT INTO notification_preferences (user_id) VALUES (1), (2);
");

$GLOBALS['pdo'] = $pdo;

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'inventory_manager';
$_SESSION['user'] = ['role_name' => 'inventory_manager', 'user_id' => 1];

$productService = new ProductService($pdo);
$inventoryService = new InventoryService($pdo);
$notificationClock = new class implements \App\Attention\Clock {
    public string $instant = '2026-10-18T00:00:00+08:00';
    public function now(): DateTimeImmutable { return new DateTimeImmutable($this->instant); }
};
$notificationService = new NotificationService($pdo, $notificationClock);

// --- 3. Test Product Service Validation & Permissions ---
echo "Testing ProductService createProduct and updateProductCatalogDetails validation & workspace checks...\n";

// A. Validation in createProduct
$invalidValues = [-1, '-2', 0, '0', 1.5, '1.5', '2.5', 'not-a-number', []];
foreach ($invalidValues as $val) {
    $threw = false;
    try {
        $productService->createProduct([
            'product_name' => 'Invalid Lead Time Test',
            'warning_lead_time_days' => $val,
        ], 1);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    $assert($threw, "createProduct rejects invalid warning_lead_time_days: " . json_encode($val));
}

// B. Workspace enforcement in createProduct
$_SESSION['role'] = 'cashier';
$cashierThrew = false;
try {
    $productService->createProduct([
        'product_name' => 'Cashier Override Test',
        'warning_lead_time_days' => 2,
    ], 2);
} catch (RuntimeException $e) {
    $cashierThrew = true;
}
$assert($cashierThrew, 'createProduct rejects non-inventory-manager setting warning override');

// C. Successful creation with override as Inventory Manager
$_SESSION['role'] = 'inventory_manager';
$breadId = $productService->createProduct([
    'product_name' => 'Artisan Bread',
    'barcode' => 'BREAD-001',
    'selling_price' => 50.0,
    'cost_price' => 25.0,
    'warning_lead_time_days' => 2,
    'initial_stock_quantity' => 0,
], 1);

$breadRow = $pdo->query("SELECT * FROM products WHERE product_id = {$breadId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$breadRow['warning_lead_time_days'] === 2, 'createProduct persists integer warning_lead_time_days (2 days for bread)');

// D. Blank / null lead time creates product with NULL override
$milkId = $productService->createProduct([
    'product_name' => 'Fresh Milk',
    'barcode' => 'MILK-001',
    'selling_price' => 80.0,
    'cost_price' => 50.0,
    'warning_lead_time_days' => '',
    'initial_stock_quantity' => 0,
], 1);

$milkRow = $pdo->query("SELECT * FROM products WHERE product_id = {$milkId}")->fetch(PDO::FETCH_ASSOC);
$assert($milkRow['warning_lead_time_days'] === null, 'createProduct persists blank warning_lead_time_days as NULL');

// E. Validation & workspace enforcement in updateProductCatalogDetails
$_SESSION['role'] = 'cashier';
$cashierEditThrew = false;
try {
    $productService->updateProductCatalogDetails($breadId, [
        'product_name' => 'Bread Edit Attempt',
        'warning_lead_time_days' => 5,
    ], 2);
} catch (RuntimeException $e) {
    $cashierEditThrew = true;
}
$assert($cashierEditThrew, 'updateProductCatalogDetails rejects non-inventory-manager workspace');

$_SESSION['role'] = 'inventory_manager';
foreach ([-1, 0, '0', 2.3, 'invalid'] as $val) {
    $threw = false;
    try {
        $productService->updateProductCatalogDetails($breadId, [
            'product_name' => 'Artisan Bread',
            'warning_lead_time_days' => $val,
        ], 1);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    $assert($threw, "updateProductCatalogDetails rejects invalid warning_lead_time_days: " . json_encode($val));
}

// F. Update with positive integer & blank -> null
$updated = $productService->updateProductCatalogDetails($breadId, [
    'product_name' => 'Artisan Bread Updated',
    'warning_lead_time_days' => 3,
], 1);
$assert((int)$updated['warning_lead_time_days'] === 3, 'updateProductCatalogDetails updates warning_lead_time_days to 3');

// Reset bread back to 2
$productService->updateProductCatalogDetails($breadId, [
    'product_name' => 'Artisan Bread',
    'warning_lead_time_days' => 2,
], 1);

// Clearing override to null
$cleared = $productService->updateProductCatalogDetails($breadId, [
    'product_name' => 'Artisan Bread',
    'warning_lead_time_days' => '',
], 1);
$assert($cleared['warning_lead_time_days'] === null, 'updateProductCatalogDetails clears override to null on empty string');

// Set back to 2 for bread
$productService->updateProductCatalogDetails($breadId, [
    'product_name' => 'Artisan Bread',
    'warning_lead_time_days' => 2,
], 1);


// --- 4. Test Two-Day Bread Boundary in Inventory Warnings ---
echo "Testing Two-day bread boundary (3 days out, 2 days out, 1 day out, 0 days out, -1 day out)...\n";
// Reference Date (Today): 2026-10-18
// Lead time: 2 days
// Batches:
// 3 days out: 2026-10-21 -> NO warning (days_remaining = 3 > 2)
// 2 days out: 2026-10-20 -> WARNS (days_remaining = 2 <= 2)
// 1 day out:  2026-10-19 -> WARNS (days_remaining = 1 <= 2)
// 0 days out: 2026-10-18 -> WARNS (days_remaining = 0, expiration day)
// -1 day out: 2026-10-17 -> EXPIRED (days_remaining = -1 < 0)

$today = '2026-10-18';

// Insert test batches for bread (product_id = $breadId)
$insertBatch = $pdo->prepare("
    INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
    VALUES (?, ?, ?, ?, ?, ?)
");

$insertBatch->execute([$breadId, 'BREAD-3DAYS', 10, 10, '2026-10-21', '2026-10-15']); // batch_id = 1
$insertBatch->execute([$breadId, 'BREAD-2DAYS', 10, 10, '2026-10-20', '2026-10-15']); // batch_id = 2
$insertBatch->execute([$breadId, 'BREAD-1DAY',  10, 10, '2026-10-19', '2026-10-15']); // batch_id = 3
$insertBatch->execute([$breadId, 'BREAD-0DAY',  10, 10, '2026-10-18', '2026-10-15']); // batch_id = 4
$insertBatch->execute([$breadId, 'BREAD-EXPIRED', 10, 10, '2026-10-17', '2026-10-15']); // batch_id = 5

// Query expiring soon batches
$expiringSoon = $inventoryService->getExpiringSoonBatches(30, $today);
$expiringBatchNumbers = array_column($expiringSoon, 'batch_number');

$assert(!in_array('BREAD-3DAYS', $expiringBatchNumbers, true), '3 days out does NOT trigger warning for 2-day lead time');
$assert(in_array('BREAD-2DAYS', $expiringBatchNumbers, true), '2 days out DOES trigger warning for 2-day lead time');
$assert(in_array('BREAD-1DAY', $expiringBatchNumbers, true), '1 day out DOES trigger warning for 2-day lead time');
$assert(in_array('BREAD-0DAY', $expiringBatchNumbers, true), '0 days out (expiration day) DOES trigger warning for 2-day lead time');
$assert(!in_array('BREAD-EXPIRED', $expiringBatchNumbers, true), '-1 day out is excluded from expiring soon batches');

// Query expired batches
$expired = $inventoryService->getExpiredBatches($today);
$expiredBatchNumbers = array_column($expired, 'batch_number');

$assert(in_array('BREAD-EXPIRED', $expiredBatchNumbers, true), '-1 day out is correctly categorized as expired');
$assert(!in_array('BREAD-0DAY', $expiredBatchNumbers, true), '0 days out (expiration day) remains eligible and not categorized as expired');


// --- 5. Test Products Without Override (Default Behavior) ---
echo "Testing Products without override preserve 30-day default and 7-day FEFO urgency...\n";
// Milk has warning_lead_time_days = NULL
// Insert Milk batches:
// 35 days out: 2026-11-22 -> not expiring soon (> 30 days)
// 25 days out: 2026-11-12 -> expiring soon (<= 30 days)
// 8 days out:  2026-10-26 -> FEFO not urgent (> 7 days)
// 6 days out:  2026-10-24 -> FEFO urgent (<= 7 days)
$insertBatch->execute([$milkId, 'MILK-35DAYS', 10, 10, '2026-11-22', '2026-10-15']);
$insertBatch->execute([$milkId, 'MILK-25DAYS', 10, 10, '2026-11-12', '2026-10-15']);
$insertBatch->execute([$milkId, 'MILK-8DAYS',  10, 10, '2026-10-26', '2026-10-15']);
$insertBatch->execute([$milkId, 'MILK-6DAYS',  10, 10, '2026-10-24', '2026-10-15']);

$expiringSoonAll = $inventoryService->getExpiringSoonBatches(30, $today);
$allExpiringNumbers = array_column($expiringSoonAll, 'batch_number');

$assert(!in_array('MILK-35DAYS', $allExpiringNumbers, true), 'Milk 35 days out is not in 30-day overview window');
$assert(in_array('MILK-25DAYS', $allExpiringNumbers, true), 'Milk 25 days out is in 30-day overview window by default');
$assert(in_array('MILK-8DAYS', $allExpiringNumbers, true), 'Milk 8 days out is in 30-day overview window by default');
$assert(in_array('MILK-6DAYS', $allExpiringNumbers, true), 'Milk 6 days out is in 30-day overview window by default');

// Test FEFO Urgency Calculation:
// FEFO urgency is COALESCE(p.warning_lead_time_days, 7)
// Bread: warning_lead_time_days = 2 -> urgency threshold = 2
// Milk:  warning_lead_time_days = null -> urgency threshold = 7
$fefoRows = $inventoryService->getFefoRecommendations($today);
$fefoByBatch = [];
foreach ($fefoRows as $row) {
    $threshold = isset($row['warning_lead_time_days']) && $row['warning_lead_time_days'] !== null ? (int)$row['warning_lead_time_days'] : 7;
    $daysRemaining = (int)round((strtotime($row['expiration_date']) - strtotime($today)) / 86400);
    $isUrgent = ($daysRemaining <= $threshold);
    $fefoByBatch[$row['batch_number']] = ['days_remaining' => $daysRemaining, 'threshold' => $threshold, 'urgent' => $isUrgent];
}

$assert(isset($fefoByBatch['BREAD-2DAYS']) && $fefoByBatch['BREAD-2DAYS']['urgent'] === true, 'Bread 2 days out is FEFO urgent (threshold = 2)');
$assert(isset($fefoByBatch['BREAD-3DAYS']) && $fefoByBatch['BREAD-3DAYS']['urgent'] === false, 'Bread 3 days out is NOT FEFO urgent (threshold = 2)');
$assert(isset($fefoByBatch['MILK-6DAYS']) && $fefoByBatch['MILK-6DAYS']['urgent'] === true, 'Milk 6 days out is FEFO urgent (default threshold = 7)');
$assert(isset($fefoByBatch['MILK-8DAYS']) && $fefoByBatch['MILK-8DAYS']['urgent'] === false, 'Milk 8 days out is NOT FEFO urgent (default threshold = 7)');


// --- 6. Test Notification Milestones & Deduplication ---
echo "Testing Notification milestones and deduplication...\n";
// Wipe notifications table
$pdo->exec("DELETE FROM notifications");

// Product with override (Bread, lead time = 2):
// BREAD-3DAYS: days_remaining = 3 -> NOT eligible (no alert)
// BREAD-2DAYS: days_remaining = 2 -> eligible under milestone 2
// Product without override (Milk, lead time = null):
// MILK-25DAYS: days_remaining = 25 -> eligible under global milestone 30
// MILK-6DAYS:  days_remaining = 6 -> eligible under global milestone 7

$notificationService->checkAndNotifyExpiringStock(30);

$notifications = $pdo->query("SELECT * FROM notifications ORDER BY notification_id")->fetchAll(PDO::FETCH_ASSOC);

// Check Bread notifications:
// User 1 is active inventory manager, should receive alerts
$breadAlerts = array_values(array_filter($notifications, static fn($n) => str_contains($n['title'], 'Artisan Bread')));
$breadRefTypes = array_column($breadAlerts, 'reference_type');

$assert(in_array('product_batch_expiry_2', $breadRefTypes, true), 'Bread generated milestone 2 alert');
$assert(!in_array('product_batch_expiry_30', $breadRefTypes, true), 'Bread did NOT generate milestone 30 alert');
$assert(!in_array('product_batch_expiry_60', $breadRefTypes, true), 'Bread did NOT generate milestone 60 alert');
$assert(!in_array('product_batch_expiry_90', $breadRefTypes, true), 'Bread did NOT generate milestone 90 alert');

// Check Milk notifications (no override -> global milestones 30, 7)
$milkAlerts = array_values(array_filter($notifications, static fn($n) => str_contains($n['title'], 'Fresh Milk')));
$milkRefTypes = array_column($milkAlerts, 'reference_type');

$assert(in_array('product_batch_expiry_30', $milkRefTypes, true), 'Milk generated global milestone 30 alert');
$assert(in_array('product_batch_expiry_7', $milkRefTypes, true), 'Milk generated global milestone 7 alert');

// Check expired stock notification:
$expiredAlerts = array_values(array_filter($notifications, static fn($n) => $n['type'] === 'expired_stock'));
$assert(count($expiredAlerts) > 0, 'Expired batch generated separate expired_stock notification');

// Test Deduplication:
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$notificationService->checkAndNotifyExpiringStock(30);
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();

$assert($countBefore === $countAfter, 'checkAndNotifyExpiringStock does not duplicate existing milestone or expired notifications');

$notificationClock->instant = '2026-11-06T00:00:00+08:00';
$notificationService->checkAndNotifyExpiringStock();
$currentReminders = $notificationService->getBatchReminders(1);
$milk25 = array_values(array_filter($currentReminders, static fn(array $row): bool => $row['batch_number'] === 'MILK-25DAYS'));
$assert(count($milk25) === 1 && $milk25[0]['active'], 'Later global milestone presents one current condition for the same batch');
$milestones = $pdo->query("SELECT reference_type FROM notifications WHERE user_id = 1 AND reference_id = " . (int)$milk25[0]['batch_id'])->fetchAll(PDO::FETCH_COLUMN);
$assert(in_array('product_batch_expiry_30', $milestones, true) && in_array('product_batch_expiry_7', $milestones, true), 'Current condition retains applicable default milestone history');
$notificationClock->instant = '2026-10-18T00:00:00+08:00';


// --- 7. Test Depleted Stock Excluded From Warnings and Notifications ---
echo "Testing Depleted stock (remaining_quantity = 0) is strictly excluded...\n";
// Insert depleted batches for bread and milk expiring today and yesterday
$insertBatch->execute([$breadId, 'BREAD-DEPLETED-TODAY', 10, 0, '2026-10-18', '2026-10-15']);
$insertBatch->execute([$breadId, 'BREAD-DEPLETED-EXPIRED', 10, 0, '2026-10-17', '2026-10-15']);
$insertBatch->execute([$milkId, 'MILK-DEPLETED-2DAYS', 10, 0, '2026-10-20', '2026-10-15']);

// Verify in expiring soon
$expiringCheck = $inventoryService->getExpiringSoonBatches(30, $today);
$expiringNums = array_column($expiringCheck, 'batch_number');
$assert(!in_array('BREAD-DEPLETED-TODAY', $expiringNums, true), 'Depleted batch is excluded from getExpiringSoonBatches');
$assert(!in_array('MILK-DEPLETED-2DAYS', $expiringNums, true), 'Depleted batch is excluded from getExpiringSoonBatches');

// Verify in expired batches
$expiredCheck = $inventoryService->getExpiredBatches($today);
$expiredNums = array_column($expiredCheck, 'batch_number');
$assert(!in_array('BREAD-DEPLETED-EXPIRED', $expiredNums, true), 'Depleted batch is excluded from getExpiredBatches');

// Verify in notifications
$pdo->exec("DELETE FROM notifications");
$notificationService->checkAndNotifyExpiringStock(30);
$notifs = $pdo->query("SELECT * FROM notifications")->fetchAll(PDO::FETCH_ASSOC);
$notifMessages = array_column($notifs, 'message');

$hasDepletedNotif = false;
foreach ($notifMessages as $msg) {
    if (str_contains($msg, 'BREAD-DEPLETED') || str_contains($msg, 'MILK-DEPLETED')) {
        $hasDepletedNotif = true;
        break;
    }
}
$assert(!$hasDepletedNotif, 'Depleted batches never generate expiring or expired stock notifications');

if (!empty($failures)) {
    echo "\nTest failed with " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}

echo "\nAll Ticket #223 Product Warning Lead Time tests PASSED successfully!\n";
