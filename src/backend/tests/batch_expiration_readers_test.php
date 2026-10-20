<?php
// Contract and integration tests for Ticket #222:
// Retire Product-level expiration as operational source of truth across
// product summaries, details, scanner/POS API, reports, and exports.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

use App\Services\PhilippineTime;
use App\Authorization\RoleCapabilityPolicy;

if (!class_exists('PhilippineTime', false)) {
    class_alias(PhilippineTime::class, 'PhilippineTime');
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

echo "Starting Ticket #222 batch expiration readers tests...\n";

// --- Database Setup ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->sqliteCreateFunction('LEAST', static fn(...$values) => min($values));
$pdo->sqliteCreateFunction('GREATEST', static fn(...$values) => max($values));
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => PhilippineTime::today(), 0);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$pdo->sqliteCreateFunction('DATEDIFF', static fn(string $d1, string $d2): int => (int)round((strtotime($d1) - strtotime($d2)) / 86400), 2);
$GLOBALS['pdo'] = $pdo;

$pdo->exec("CREATE TABLE branches (
    branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_name TEXT NOT NULL,
    branch_code TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Store', 'MAIN', 'active')");

$pdo->exec("CREATE TABLE roles (
    role_id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_name TEXT NOT NULL UNIQUE
)");
$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin'), (4, 'super_admin')");

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    username TEXT NOT NULL UNIQUE,
    role_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active'
)");
$pdo->exec("INSERT INTO users (user_id, full_name, username, role_id, branch_id) VALUES (1, 'Test Admin', 'admin', 3, 1)");

$pdo->exec("CREATE TABLE categories (
    category_id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_name TEXT NOT NULL
)");
$pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'Dairy'), (2, 'Bakery')");

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    sku TEXT NOT NULL,
    barcode TEXT NOT NULL DEFAULT '',
    case_barcode TEXT NOT NULL DEFAULT '',
    parent_product_id INTEGER NULL,
    variant_label TEXT NULL,
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
    expiration_date TEXT NULL,
    pack_size INTEGER NULL,
    pack_barcode TEXT NULL,
    pack_price REAL NULL,
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
    sealed_quantity INTEGER NOT NULL DEFAULT 0,
    loose_quantity INTEGER NOT NULL DEFAULT 0,
    expiration_date TEXT NULL,
    date_received TEXT NOT NULL,
    supplier TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$todayManila = PhilippineTime::today();
$yesterdayManila = (new DateTimeImmutable('yesterday', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$tomorrowManila = (new DateTimeImmutable('+1 day', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$nextWeekManila = (new DateTimeImmutable('+7 days', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
$nextMonthManila = (new DateTimeImmutable('+30 days', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');

// Seed test products:
// Product 1: Legacy product-level expiration ('2027-12-31'), NO batches.
$pdo->prepare("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, expiration_date, status)
               VALUES (1, 1, 'SKU-LEGACY', 'BAR-LEGACY', 'Legacy Item Without Batches', 1, '2027-12-31', 'active')")->execute();
$pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 15)")->execute();

// Product 2: Multiple batches:
// - Batch 1: Expired yesterday, remaining = 5
// - Batch 2: Expires tomorrow, remaining = 10 (earliest unexpired)
// - Batch 3: Expires next week, remaining = 20
$pdo->prepare("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, expiration_date, status)
               VALUES (2, 1, 'SKU-MULTI', 'BAR-MULTI', 'Multi-Batch Fresh Bread', 2, '2026-01-01', 'active')")->execute();
$pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 35)")->execute();
$pdo->prepare("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (2, 'B-EXP', 5, 5, ?, ?)")->execute([$yesterdayManila, $yesterdayManila]);
$pdo->prepare("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (2, 'B-TOMORROW', 10, 10, ?, ?)")->execute([$tomorrowManila, $todayManila]);
$pdo->prepare("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (2, 'B-WEEK', 20, 20, ?, ?)")->execute([$nextWeekManila, $todayManila]);

// Product 3: Only expired batches. Legacy expiration_date = '2030-01-01'.
$pdo->prepare("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, expiration_date, status)
               VALUES (3, 1, 'SKU-EXPIRED', 'BAR-EXPIRED', 'Spoiled Milk Only', 1, '2030-01-01', 'active')")->execute();
$pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 8)")->execute();
$pdo->prepare("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (3, 'B-PAST', 8, 8, ?, ?)")->execute([$yesterdayManila, $yesterdayManila]);

// Product 4: Depleted dated batch (remaining_quantity = 0), unexpired.
$pdo->prepare("INSERT INTO products (product_id, branch_id, sku, barcode, product_name, category_id, expiration_date, status)
               VALUES (4, 1, 'SKU-DEPLETED', 'BAR-DEPLETED', 'Sold Out Yogurt', 1, NULL, 'active')")->execute();
$pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (4, 0)")->execute();
$pdo->prepare("INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received)
               VALUES (4, 'B-EMPTY', 10, 0, ?, ?)")->execute([$nextMonthManila, $todayManila]);

// Migration 202610200001 backfill: legacy stock reads as loose in the stock-form model.
$pdo->exec("UPDATE product_batches SET loose_quantity = remaining_quantity WHERE sealed_quantity = 0 AND loose_quantity = 0");


// =========================================================================
// 1. ProductService::getProductsForManagement() tests
// =========================================================================
$productService = new ProductService($pdo);
$managedProducts = $productService->getProductsForManagement();
$managedById = [];
foreach ($managedProducts as $productRow) {
    $managedById[(int)$productRow['product_id']] = $productRow;
}

$assert(isset($managedById[1]), 'Product 1 found in getProductsForManagement');
$assert($managedById[1]['next_expiration_date'] === null,
    'Product with legacy products.expiration_date and no batches returns next_expiration_date = null (legacy date not used as fallback)');
$assert((int)$managedById[1]['expired_quantity'] === 0, 'Product 1 expired_quantity is 0');

$assert(isset($managedById[2]), 'Product 2 found in getProductsForManagement');
$assert($managedById[2]['next_expiration_date'] === $tomorrowManila,
    "Product with multiple batches returns earliest unexpired batch date ({$tomorrowManila}), ignoring expired batch ({$yesterdayManila})");
$assert((int)$managedById[2]['expired_quantity'] === 5, 'Product 2 expired_quantity accurately reflects expired batch quantity (5)');

$assert(isset($managedById[3]), 'Product 3 found in getProductsForManagement');
$assert($managedById[3]['next_expiration_date'] === null,
    'Product with only expired batches returns next_expiration_date = null (does not fall back to products.expiration_date)');
$assert((int)$managedById[3]['expired_quantity'] === 8, 'Product 3 expired_quantity is 8');

$assert(isset($managedById[4]), 'Product 4 found in getProductsForManagement');
$assert($managedById[4]['next_expiration_date'] === null,
    'Product with depleted batch (remaining_quantity = 0) returns next_expiration_date = null');


// =========================================================================
// 2. Expiry Reports (report_generation.php) tests
// =========================================================================
// Evaluate report functions directly from report_generation.php source
// The evaled source needs the role accessor that includes/auth.php would define.
if (!function_exists('current_role')) {
    function current_role(): ?string { return 'admin'; }
}
if (!function_exists('run_report')) {
    $reportSource = file_get_contents(__DIR__ . '/../../frontend/components/report/report_generation.php');
    $endPos = strpos($reportSource, '$selectedReport =');
    $fnSource = substr($reportSource, 0, $endPos);
    $fnSource = preg_replace('/^require_once .*;\R/m', '', $fnSource);
    $fnSource = preg_replace('/^use .*;\R/m', '', $fnSource);
    $fnSource = preg_replace('/^require_capability\(.*\);\R/m', '', $fnSource);
    eval('?>' . $fnSource);
}

$filters = [
    'store_id' => 1,
    'from' => $todayManila,
    'to' => $nextMonthManila,
    'product_id' => 0,
    'category_id' => 0,
    'granularity' => 'daily',
];

$expiryReport = run_report($pdo, 'expiration', $filters);
$reportRows = $expiryReport['rows'];

$reportProducts = array_column($reportRows, 'Product');
$assert(!in_array('Legacy Item Without Batches', $reportProducts, true),
    'Expiry report excludes legacy product without batches, completely ignoring legacy products.expiration_date');

$multiRows = array_filter($reportRows, static fn($row) => $row['Product'] === 'Multi-Batch Fresh Bread');
$assert(count($multiRows) === 3, 'Multi-batch product has exactly 3 batch entries in expiry report');

$multiBatches = array_column(array_values($multiRows), 'Batch');
$assert(in_array('B-EXP', $multiBatches, true), 'Multi-batch includes expired batch B-EXP');
$assert(in_array('B-TOMORROW', $multiBatches, true), 'Multi-batch includes tomorrow batch B-TOMORROW');
$assert(in_array('B-WEEK', $multiBatches, true), 'Multi-batch includes next week batch B-WEEK');

foreach ($multiRows as $batchRow) {
    if ($batchRow['Batch'] === 'B-EXP') {
        $assert($batchRow['Status'] === 'Expired', 'B-EXP has status Expired');
        $assert((int)$batchRow['Days Remaining'] < 0, 'B-EXP days remaining is negative');
    } elseif ($batchRow['Batch'] === 'B-TOMORROW') {
        $assert($batchRow['Status'] === 'Expiring soon', 'B-TOMORROW has status Expiring soon');
        $assert((int)$batchRow['Days Remaining'] === 1, 'B-TOMORROW has exactly 1 day remaining');
    }
}

$expiredRows = array_filter($reportRows, static fn($row) => $row['Product'] === 'Spoiled Milk Only');
$assert(count($expiredRows) === 1, 'Product 3 has 1 batch in expiry report');
$expiredRow = reset($expiredRows);
$assert($expiredRow['Expiration Date'] === $yesterdayManila,
    'Expiry report shows actual batch expiration date, NOT legacy products.expiration_date (2030-01-01)');
$assert($expiredRow['Status'] === 'Expired', 'Product 3 batch status is Expired');

$depletedRows = array_filter($reportRows, static fn($row) => $row['Product'] === 'Sold Out Yogurt');
$assert(count($depletedRows) === 0, 'Depleted batch with remaining_quantity = 0 is excluded from expiry report');


// =========================================================================
// 3. Scanner / POS API (apiScanner/products.php) tests
// =========================================================================
function queryScannerApi(PDO $testPdo, array $getParams, string $role = 'admin'): array {
    $_GET = $getParams;
    $source = file_get_contents(__DIR__ . '/../../frontend/components/barcodeScanner/apiScanner/products.php');
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    $source = preg_replace('/^header\(.*\);\R/m', '', $source);
    $source = preg_replace('/^require_role\(.*\);\R/m', '', $source);
    $source = preg_replace('/^App\\\\Core\\\\Session::closeWrite\(.*\);\R/m', '', $source);
    $source = preg_replace('/^use .*;\R/m', '', $source);
    $source = str_replace('current_role()', var_export($role, true), $source);

    $pdo = $testPdo;
    ob_start();
    try {
        eval('?>' . $source);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    return json_decode($output, true) ?: [];
}

// Product 1 (Legacy date, no batches):
$res1 = queryScannerApi($pdo, ['code' => 'BAR-LEGACY', 'scope' => 'all'], 'admin');
$assert($res1['success'] === true && !empty($res1['products']), 'Scanner API successfully finds BAR-LEGACY');
$legacyItem = $res1['products'][0];
$assert($legacyItem['next_expiration_date'] === null,
    'Scanner API returns next_expiration_date = null for product with legacy date and no batches');

// Product 2 (Multi-batch):
$res2 = queryScannerApi($pdo, ['code' => 'BAR-MULTI', 'scope' => 'all'], 'admin');
$assert($res2['success'] === true && !empty($res2['products']), 'Scanner API successfully finds BAR-MULTI');
$multiBatchItem = $res2['products'][0];
$assert($multiBatchItem['next_expiration_date'] === $tomorrowManila,
    "Scanner API returns earliest unexpired batch date ({$tomorrowManila}) for next_expiration_date");
// Sellable quantity should exclude expired batch (35 - 5 = 30)
$assert((int)$multiBatchItem['quantity_on_hand'] === 30,
    'Scanner API quantity_on_hand excludes expired batches (30 sellable out of 35 total)');

// Product 3 (Only expired batches):
// POS / Cashier search (not scope=all) must exclude it because sellable quantity is 0:
$res3Cashier = queryScannerApi($pdo, ['code' => 'BAR-EXPIRED'], 'cashier');
$assert($res3Cashier['success'] === true && empty($res3Cashier['products']),
    'Scanner POS cashier search returns empty products for item where all batches are expired');

// Management search (scope=all) returns it with 0 quantity_on_hand and null next_expiration_date:
$res3Mgmt = queryScannerApi($pdo, ['code' => 'BAR-EXPIRED', 'scope' => 'all'], 'inventory_manager');
$assert($res3Mgmt['success'] === true && !empty($res3Mgmt['products']), 'Scanner management search returns product');
$expiredItem = $res3Mgmt['products'][0];
$assert((int)$expiredItem['quantity_on_hand'] === 0,
    'Scanner API returns 0 sellable quantity_on_hand when all batches are expired');
$assert($expiredItem['next_expiration_date'] === null,
    'Scanner API returns next_expiration_date = null when all batches are expired, ignoring products.expiration_date');

// Product 4 (Out of stock product with remaining = 0):
$res4Mgmt = queryScannerApi($pdo, ['code' => 'BAR-DEPLETED', 'scope' => 'all'], 'inventory_manager');
$assert($res4Mgmt['success'] === true && !empty($res4Mgmt['products']), 'Scanner finds out of stock product');
$depletedItem = $res4Mgmt['products'][0];
$assert($depletedItem['next_expiration_date'] === null, 'Out of stock product returns next_expiration_date = null in scanner');

// =========================================================================
// 4. POS scanner query test (pos.php)
// =========================================================================
$posStmt = $pdo->prepare(
    "SELECT p.product_id, p.sku, p.barcode, p.product_name, p.unit_price,
            p.reorder_level, p.safety_stock,
            LEAST(COALESCE(i.quantity_on_hand, 0), COALESCE((
                SELECT SUM(CASE WHEN expiration_date IS NULL OR expiration_date >= ?
                                THEN remaining_quantity ELSE 0 END)
                FROM product_batches WHERE product_id = p.product_id AND remaining_quantity > 0
            ), i.quantity_on_hand, 0)) AS quantity_on_hand
     FROM products p LEFT JOIN inventory i ON i.product_id = p.product_id
     WHERE p.product_id = ? AND p.status = 'active'"
);
$posStmt->execute([$todayManila, 2]);
$posProduct2 = $posStmt->fetch();
$assert((int)$posProduct2['quantity_on_hand'] === 30, 'POS query respects Manila date and excludes expired stock (30 out of 35)');

$posStmt->execute([$todayManila, 3]);
$posProduct3 = $posStmt->fetch();
$assert((int)$posProduct3['quantity_on_hand'] === 0, 'POS query returns 0 available quantity when all batches expired');

// =========================================================================
// Finish and report
// =========================================================================
if (!empty($failures)) {
    echo "\nCompleted with " . count($failures) . " failure(s).\n";
    exit(1);
}

echo "\nAll Ticket #222 batch expiration reader tests passed successfully.\n";
exit(0);
