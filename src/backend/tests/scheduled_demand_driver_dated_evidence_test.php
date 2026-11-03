<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Store/ScheduledDemandDriverService.php';
require_once __DIR__ . '/../app/Forecasting/DemandHistoryService.php';
require_once __DIR__ . '/../app/Services/ProductService.php';

use App\Store\ScheduledDemandDriverService;
use App\Forecasting\DemandHistoryService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] $message\n";
    } else {
        echo "  [PASS] $message\n";
    }
};

echo "Testing Ticket #372: Dated Price and Promotion Evidence Capture & Export...\n\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Set up minimal schema
$pdo->exec("
    CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT,
        message TEXT,
        status TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE notification_preferences (
        user_id INTEGER PRIMARY KEY,
        email_notifications INTEGER DEFAULT 0
    );
    CREATE TABLE categories (
        category_id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_name TEXT NOT NULL
    );
    CREATE TABLE roles (
        role_id INTEGER PRIMARY KEY AUTOINCREMENT,
        role_name TEXT NOT NULL
    );
    CREATE TABLE user_roles (
        user_id INTEGER NOT NULL,
        role_id INTEGER NOT NULL
    );
    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open',
        start_date TEXT,
        end_date TEXT
    );
    CREATE TABLE fiscal_period_locks (
        lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_id INTEGER,
        table_name TEXT,
        record_id INTEGER,
        locked_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_code TEXT NOT NULL,
        branch_name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active'
    );
    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        role_id INTEGER NOT NULL DEFAULT 1,
        branch_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'active'
    );
    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT NOT NULL,
        barcode TEXT,
        case_barcode TEXT,
        product_name TEXT NOT NULL,
        brand TEXT,
        category_id INTEGER,
        unit_price REAL NOT NULL,
        cost_price REAL NOT NULL,
        quantity_purchased INTEGER NOT NULL DEFAULT 0,
        quantity_sold INTEGER NOT NULL DEFAULT 0,
        base_unit TEXT NOT NULL DEFAULT 'piece',
        pack_size INTEGER NULL,
        pack_barcode TEXT NULL,
        pack_price REAL NULL,
        parent_product_id INTEGER NULL,
        variant_label TEXT NULL,
        reorder_level INTEGER NOT NULL DEFAULT 0,
        supplier TEXT NULL,
        preferred_supplier TEXT NULL,
        supplier_lead_time_days INTEGER NOT NULL DEFAULT 0,
        safety_stock INTEGER NOT NULL DEFAULT 0,
        minimum_order_quantity INTEGER NOT NULL DEFAULT 1,
        units_per_package INTEGER NOT NULL DEFAULT 1,
        receiving_unit TEXT NULL,
        expiration_date TEXT NULL,
        product_expiration_date TEXT NULL,
        warning_lead_time_days INTEGER NULL DEFAULT 0,
        product_image TEXT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        created_by INTEGER NULL,
        branch_id INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NULL
    );
    CREATE TABLE inventory (
        inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL UNIQUE,
        quantity_on_hand INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        receiving_id INTEGER NULL,
        batch_number TEXT,
        quantity INTEGER NOT NULL DEFAULT 0,
        remaining_quantity INTEGER NOT NULL,
        sealed_quantity INTEGER NOT NULL DEFAULT 0,
        loose_quantity INTEGER NOT NULL DEFAULT 0,
        expiration_date TEXT,
        cost_price REAL,
        date_received TEXT DEFAULT CURRENT_TIMESTAMP,
        supplier TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT,
        moved_by INTEGER NULL,
        moved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE scheduled_demand_drivers (
        driver_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        driver_type TEXT NOT NULL,
        effective_date TEXT NOT NULL,
        scheduled_at TEXT NOT NULL,
        details_json TEXT NOT NULL
    );
    CREATE TABLE promotions (
        promotion_id INTEGER PRIMARY KEY AUTOINCREMENT,
        promotion_name TEXT NOT NULL,
        discount_type TEXT NOT NULL,
        discount_value REAL NOT NULL,
        scope TEXT NOT NULL DEFAULT 'all',
        product_id INTEGER NULL,
        category_id INTEGER NULL,
        minimum_quantity INTEGER NOT NULL DEFAULT 1,
        starts_at TEXT NOT NULL,
        ends_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        created_by INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE store_calendar (
        calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
        calendar_date TEXT NOT NULL,
        status TEXT NOT NULL,
        reason TEXT,
        recorded_by INTEGER,
        recorded_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        total_amount REAL,
        sale_date TEXT NOT NULL
    );
    CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        selling_form TEXT NOT NULL DEFAULT 'piece',
        pack_size INTEGER NULL
    );
    CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER,
        reversal_type TEXT,
        status TEXT,
        reason TEXT,
        approved_at TEXT
    );
    CREATE TABLE sale_reversal_items (
        reversal_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        reversal_id INTEGER,
        sale_item_id INTEGER,
        product_id INTEGER,
        quantity INTEGER
    );
    CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE cash_refund_items (
        refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        refund_id INTEGER,
        sale_item_id INTEGER,
        product_id INTEGER,
        quantity INTEGER
    );
    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT,
        category TEXT,
        record_id INTEGER,
        details TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE forecast_sales_imports (
        product_id INTEGER,
        sale_date TEXT,
        quantity INTEGER,
        PRIMARY KEY (product_id, sale_date)
    );
");

$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'Admin')");
$pdo->exec("INSERT INTO fiscal_periods (period_id, period_name, status, start_date, end_date) VALUES (1, 'FY2026', 'open', '2026-01-01', '2026-12-31')");
$pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1, 1)");
$pdo->exec("INSERT INTO branches (branch_id, branch_code, branch_name) VALUES (1, 'MAIN', 'Main Store')");
$pdo->exec("INSERT INTO users (user_id, username) VALUES (1, 'admin')");
$_SESSION['user_id'] = 1;
$_SESSION['branch_id'] = 1;
$_SESSION['role_id'] = 1;
$_SESSION['role'] = 'inventory_manager';

$driverService = new ScheduledDemandDriverService();
$historyService = new DemandHistoryService();
$productService = new ProductService($pdo);

// --------------------------------------------------------------------------
// 1. Price capture on Product creation & updates
// --------------------------------------------------------------------------
echo "--- Step 1: Product Price Driver Recording on Create and Updates ---\n";

$productId = $productService->createProduct([
    'product_name' => 'Coffee Beans 250g',
    'barcode' => 'COFFEE-250',
    'selling_price' => 150.00,
    'cost_price' => 90.00,
    'base_unit' => 'bag',
    'initial_stock' => 20,
], 1);

$drivers = $pdo->query("SELECT * FROM scheduled_demand_drivers WHERE product_id = $productId AND driver_type = 'price'")->fetchAll();
$assert(count($drivers) === 1, "Price driver recorded on product creation");
$d0 = json_decode($drivers[0]['details_json'] ?? '{}', true);
$assert((float)($d0['unit_price'] ?? 0) === 150.00, "Recorded price matches selling_price (150.00)");
$assert(($d0['base_unit'] ?? '') === 'bag', "Recorded price preserves base_unit (bag)");

// Update price via purchaseStock
$productService->purchaseStock([
    'search_term' => 'COFFEE-250',
    'purchase_quantity' => 10,
    'purchase_cost_price' => 95.00,
    'selling_price' => 175.00,
], 1);

$driversAfterPurchase = $pdo->query("SELECT * FROM scheduled_demand_drivers WHERE product_id = $productId AND driver_type = 'price' ORDER BY scheduled_at ASC")->fetchAll();
$assert(count($driversAfterPurchase) === 2, "Second price driver recorded on purchaseStock price update");
$d1 = json_decode($driversAfterPurchase[1]['details_json'] ?? '{}', true);
$assert((float)($d1['unit_price'] ?? 0) === 175.00, "Updated price matches new selling_price (175.00)");

// Update price via updateProductCatalogDetails
$productService->updateProductCatalogDetails($productId, [
    'product_name' => 'Coffee Beans 250g Premium',
    'selling_price' => 180.00,
    'cost_price' => 100.00,
], 1);

$driversAfterCatalog = $pdo->query("SELECT * FROM scheduled_demand_drivers WHERE product_id = $productId AND driver_type = 'price' ORDER BY scheduled_at ASC")->fetchAll();
$assert(count($driversAfterCatalog) === 3, "Third price driver recorded on catalog update");
$d2 = json_decode($driversAfterCatalog[2]['details_json'] ?? '{}', true);
$assert((float)($d2['unit_price'] ?? 0) === 180.00, "Updated catalog price is 180.00");

// --------------------------------------------------------------------------
// 2. Promotion driver capture: Identity, Revisions, Cancellations & Non-interference
// --------------------------------------------------------------------------
echo "\n--- Step 2: Promotion Drivers: Coexistence, Cancellations & Newer Price Non-Interference ---\n";

// Clear drivers for clean scenario testing
$pdo->exec("DELETE FROM scheduled_demand_drivers");

// Setup dates
$targetDate = '2026-11-10';

// Schedule Promotion 1 (10% off) recorded on 2026-11-01 09:00:00
$pdo->exec("INSERT INTO promotions (promotion_id, promotion_name, discount_type, discount_value, scope, product_id, starts_at, ends_at, status, created_by)
    VALUES (101, 'Promo 101 Early Bird', 'percentage', 10.0, 'product', $productId, '2026-11-10 00:00:00', '2026-11-10 23:59:59', 'active', 1)");
$driverService->syncPromotionDrivers($pdo, 101, false, '2026-11-01 09:00:00');

// Schedule Promotion 2 (20.00 fixed off) recorded on 2026-11-02 10:00:00
$pdo->exec("INSERT INTO promotions (promotion_id, promotion_name, discount_type, discount_value, scope, product_id, starts_at, ends_at, status, created_by)
    VALUES (102, 'Promo 102 Flash Coupon', 'fixed', 20.0, 'product', $productId, '2026-11-10 00:00:00', '2026-11-10 23:59:59', 'active', 1)");
$driverService->syncPromotionDrivers($pdo, 102, false, '2026-11-02 10:00:00');

// Record a newer price change on 2026-11-03 14:00:00
$driverService->recordDriver($pdo, $productId, 'price', $targetDate, '2026-11-03 14:00:00', json_encode(['unit_price' => 199.00]));

// Cutoff at 2026-11-04 00:00:00: Both promotions and price must be present!
// Newer price must NOT hide promotions.
$effectiveAtNov4 = $driverService->getEffectiveDrivers($pdo, $productId, $targetDate, '2026-11-04 00:00:00');
$driverTypes = array_column($effectiveAtNov4, 'driver_type');
$assert(in_array('price', $driverTypes, true), "Price driver present");
$promoCount = 0;
foreach ($effectiveAtNov4 as $ed) {
    if ($ed['driver_type'] === 'promotion') {
        $promoCount++;
    }
}
$assert($promoCount === 2, "Both distinct promotions (101 and 102) survive alongside the newer price driver");

// Cancel Promotion 101 on 2026-11-05 12:00:00
$driverService->syncPromotionDrivers($pdo, 101, true, '2026-11-05 12:00:00');

// Cutoff at 2026-11-06 00:00:00: Promo 101 cancelled, but Promo 102 is STILL active!
$effectiveAtNov6 = $driverService->getEffectiveDrivers($pdo, $productId, $targetDate, '2026-11-06 00:00:00');
$activePromoOffers = 0;
$cancelledPromoOffers = 0;
foreach ($effectiveAtNov6 as $ed) {
    if ($ed['driver_type'] === 'promotion') {
        $det = json_decode($ed['details_json'] ?? '{}', true);
        if (!empty($det['cancelled'])) {
            $cancelledPromoOffers++;
            $assert((int)$det['promotion_id'] === 101, "Cancelled driver explicitly identifies Promo 101");
        } else {
            $activePromoOffers++;
            $assert((int)$det['promotion_id'] === 102, "Active driver explicitly identifies Promo 102");
        }
    }
}
$assert($cancelledPromoOffers === 1 && $activePromoOffers === 1, "Cancelling Promo 101 does NOT erase Promo 102");

// Now cancel Promotion 102 on 2026-11-07 15:00:00
$driverService->syncPromotionDrivers($pdo, 102, true, '2026-11-07 15:00:00');

// --------------------------------------------------------------------------
// 3. Deterministic Cutoff Reproduction for is_on_promotion
// --------------------------------------------------------------------------
echo "\n--- Step 3: Deterministic Cutoff Reproduction for is_on_promotion ---\n";

$pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('$targetDate', 'open', '2026-11-01 00:00:00')");

// Cutoff before any promo cancellation (2026-11-04 12:00:00): is_on_promotion is true
$evBefore = $historyService->getDailyEvidence($pdo, $productId, $targetDate, $targetDate, '2026-11-04 12:00:00')[0];
$assert($evBefore['is_on_promotion'] === true, "As of Nov 4 cutoff, is_on_promotion is TRUE (both active)");

// Cutoff between promo 101 cancellation and promo 102 cancellation (2026-11-06 12:00:00): is_on_promotion is STILL true
$evMid = $historyService->getDailyEvidence($pdo, $productId, $targetDate, $targetDate, '2026-11-06 12:00:00')[0];
$assert($evMid['is_on_promotion'] === true, "As of Nov 6 cutoff, is_on_promotion is TRUE (Promo 102 still applicable)");

// Cutoff after both cancellations (2026-11-08 12:00:00): is_on_promotion is false
$evAfter = $historyService->getDailyEvidence($pdo, $productId, $targetDate, $targetDate, '2026-11-08 12:00:00')[0];
$assert($evAfter['is_on_promotion'] === false, "As of Nov 8 cutoff, is_on_promotion is FALSE (all cancelled)");

// --------------------------------------------------------------------------
// 4. Base Unit Price & Historical Transaction Form Conversion
// --------------------------------------------------------------------------
echo "\n--- Step 4: Base Unit Price & Transaction Form Conversion ---\n";

// Product 2: has no price driver, but has historical sales with pack selling form
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, base_unit, status)
    VALUES (2, 'BEER-01', 'BEER-01-BAR', 'Pale Pilsen 330ml', 60.00, 40.00, 'bottle', 'active')");
$pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-15', 'open', '2026-10-01 00:00:00')");

// Sale on 2026-10-15: 2 packs of 6 bottles sold at 330.00 per pack
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, total_amount, sale_date) VALUES (501, 1, 660.00, '2026-10-15 14:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, selling_form, pack_size)
    VALUES (501, 501, 2, 2, 330.00, 'pack', 6)");

// Evidence on 2026-10-15: Base Unit price should be pack unit_price / pack_size = 330 / 6 = 55.00
$evTrans = $historyService->getDailyEvidence($pdo, 2, '2026-10-15', '2026-10-15', '2026-10-15 23:59:59')[0];
$assert($evTrans['price'] !== null && abs($evTrans['price'] - 55.00) < 0.01, "Historical transaction converts pack line (330 / 6 = 55.00 per Base Unit)");

// Day with no sales and no price driver (2026-10-16): price must be null! Never backfilled from today's price.
$pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-16', 'open', '2026-10-01 00:00:00')");
$evNoTrans = $historyService->getDailyEvidence($pdo, 2, '2026-10-16', '2026-10-16', '2026-10-16 23:59:59')[0];
$assert($evNoTrans['price'] === null, "Day with no transactions and no driver has price = null (no backfilling)");

// --------------------------------------------------------------------------
// 5. Shared Export Contract Integrity
// --------------------------------------------------------------------------
echo "\n--- Step 5: Shared Export Contract Integrity ---\n";

$observed = $historyService->getObservedHistory($pdo, '2026-10-16');
$p2Rows = array_values(array_filter($observed, fn($r) => $r['product_id'] === 2));
$assert(count($p2Rows) >= 1, "Export returns observed rows for product 2");
$rowOct15 = array_values(array_filter($p2Rows, fn($r) => $r['date'] === '2026-10-15'))[0] ?? null;
$assert($rowOct15 !== null, "Oct 15 row exists in export");
$assert(array_key_exists('price', $rowOct15), "Export carries 'price'");
$assert(array_key_exists('is_on_promotion', $rowOct15), "Export carries 'is_on_promotion'");
$assert(array_key_exists('out_of_stock', $rowOct15), "Export carries 'out_of_stock'");
$assert($rowOct15['price'] !== null && abs($rowOct15['price'] - 55.00) < 0.01, "Export carries converted transaction price (55.00)");
$assert($rowOct15['is_on_promotion'] === false, "Export carries false for is_on_promotion when store open with no promotion");

echo "\n========================================================\n";
if (empty($failures)) {
    echo "PASS: All Ticket #372 Evidence Capture & Export Tests Passed!\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo "FAIL: " . count($failures) . " check(s) failed:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    echo "========================================================\n";
    exit(1);
}
