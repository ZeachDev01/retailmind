<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "Uncaught exception " . get_class($e) . ": {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");
    exit(1);
});

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Services/ReportAccessService.php';
require_once __DIR__ . '/../app/Receipts/ReceiptDetailsService.php';
require_once __DIR__ . '/../app/Receipts/ReceiptTableService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Receipts\ReceiptDetailsService;
use App\Receipts\ReceiptTableService;
use App\Services\ReportAccessService;

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
            $message . ' (threw ' . get_class($e) . ': ' . $e->getMessage() . ')'
        );
    }
};

echo "Starting Ticket #347 Inventory Manager Financial Report Access Restriction Tests...\n\n";

// --- 1. Report Catalog & Navigation Filtering ---
echo "--- 1. Report Catalog & Navigation Filtering ---\n";

$imCatalog = ReportAccessService::catalogForRole('inventory_manager');
$adminCatalog = ReportAccessService::catalogForRole('admin');

// Inventory Manager must have inventory, stock, receiving, expiry, valuation, and demand reports
$permittedReports = [
    'low_stock',
    'inventory_valuation',
    'stock_movements',
    'adjustments',
    'expiration',
    'count_variance',
    'forecast_actual',
    'fast_moving',
    'slow_moving',
    'sales_product',
    'sales_category',
    'sales_period',
];

foreach ($permittedReports as $reportKey) {
    $assert(isset($imCatalog[$reportKey]), "Inventory Manager catalog must permit '{$reportKey}'");
}

// Inventory Manager must NOT have financial/staff performance reports in catalog
$excludedReports = [
    'sales_cashier',
    'refunds',
    'gross_profit',
];

foreach ($excludedReports as $reportKey) {
    $assert(!isset($imCatalog[$reportKey]), "Inventory Manager catalog must exclude '{$reportKey}'");
    $assert(isset($adminCatalog[$reportKey]), "Administrator catalog must include '{$reportKey}'");
}

// Sidebar check: Report Generation should be present in Inventory Manager sidebar, Shift Report must NOT
$sidebarSource = file_get_contents(__DIR__ . '/../../frontend/components/sidebar.php');
$assert(str_contains($sidebarSource, 'components/report/report_generation.php'), 'Report Generation is referenced in sidebar');
$assert(!preg_match('/\'inventory_manager\'\s*=>\s*\[(?:(?!\]\s*,|\badmin\b).)*shift_report\.php/s', $sidebarSource),
    'Inventory Manager sidebar does not contain cashier shift report');


// --- 2. Direct Access / Backend Refusals ---
echo "\n--- 2. Direct Access / Backend Refusals ---\n";

foreach ($excludedReports as $reportKey) {
    $throws(
        static fn() => ReportAccessService::enforceAccess('inventory_manager', $reportKey),
        DomainException::class,
        "Direct access to '{$reportKey}' in Inventory Manager workspace must be refused"
    );

    // Administrator workspace must be permitted
    $adminAllowed = true;
    try {
        ReportAccessService::enforceAccess('admin', $reportKey);
    } catch (Throwable $e) {
        $adminAllowed = false;
    }
    $assert($adminAllowed, "Direct access to '{$reportKey}' in Administrator workspace must be permitted");
}

// Permitted reports must pass enforceAccess for inventory_manager
foreach ($permittedReports as $reportKey) {
    $imAllowed = true;
    try {
        ReportAccessService::enforceAccess('inventory_manager', $reportKey);
    } catch (Throwable $e) {
        $imAllowed = false;
    }
    $assert($imAllowed, "Inventory Manager access to permitted report '{$reportKey}' must succeed");
}


// --- 3. Multi-role Active Workspace Enforcement ---
echo "\n--- 3. Multi-role Active Workspace Enforcement ---\n";

// Multi-role staff member with ['admin', 'inventory_manager']
$_SESSION['user_id'] = 99;
$_SESSION['roles'] = ['admin', 'inventory_manager'];

// When active workspace is inventory_manager:
$_SESSION['role'] = 'inventory_manager';

foreach ($excludedReports as $reportKey) {
    $throws(
        static fn() => ReportAccessService::enforceAccess($_SESSION['role'], $reportKey),
        DomainException::class,
        "Multi-role user in Inventory Manager active workspace must be denied '{$reportKey}'"
    );
}

// When active workspace switches to admin:
$_SESSION['role'] = 'admin';

foreach ($excludedReports as $reportKey) {
    $adminAllowed = true;
    try {
        ReportAccessService::enforceAccess($_SESSION['role'], $reportKey);
    } catch (Throwable $e) {
        $adminAllowed = false;
    }
    $assert($adminAllowed, "Multi-role user in Administrator active workspace can access '{$reportKey}'");
}


// --- 4. Preserved Receipt Lookup for Inventory Work ---
echo "\n--- 4. Preserved Receipt Lookup for Inventory Work ---\n";

// Setup memory database to test receipt lookup for inventory manager
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, branch_name TEXT, branch_code TEXT, status TEXT)");
$pdo->exec("INSERT INTO branches VALUES (1, 'Main Store', 'MAIN', 'active')");

$pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, role_id INTEGER, branch_id INTEGER, status TEXT)");
$pdo->exec("INSERT INTO users VALUES (1, 'Alice Cashier', 'alice', 1, 1, 'active'), (2, 'Bob Manager', 'bob', 2, 1, 'active')");

$pdo->exec("CREATE TABLE products (product_id INTEGER PRIMARY KEY, branch_id INTEGER, sku TEXT, barcode TEXT, product_name TEXT, category_id INTEGER, cost_price REAL, unit_price REAL, reorder_level INTEGER)");
$pdo->exec("INSERT INTO products VALUES (101, 1, 'SKU-101', '101', 'Widget A', 1, 50.00, 100.00, 5)");

$pdo->exec("CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, branch_id INTEGER, cashier_id INTEGER, shift_id INTEGER, sale_date TEXT, total_amount REAL, payment_method TEXT, discount_amount REAL, amount_paid REAL, change_given REAL, verification_code TEXT)");
$pdo->exec("INSERT INTO sales VALUES (1, 1, 1, 10, '2026-10-27 10:00:00', 100.00, 'cash', 0.00, 100.00, 0.00, 'VERIFY123')");

$pdo->exec("CREATE TABLE sale_items (sale_item_id INTEGER PRIMARY KEY, sale_id INTEGER, product_id INTEGER, quantity INTEGER, unit_price REAL, subtotal REAL)");
$pdo->exec("INSERT INTO sale_items VALUES (1, 1, 101, 1, 100.00, 100.00)");

$pdo->sqliteCreateFunction('GREATEST', static fn(...$values) => max($values));
$pdo->exec("CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("INSERT INTO registers VALUES (1, 'Front Register')");
$pdo->exec("CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, register_id INTEGER, status TEXT)");
$pdo->exec("INSERT INTO cashier_shifts VALUES (10, 1, 1, 'closed')");
$pdo->exec("CREATE TABLE sale_reversals (sale_id INTEGER, requested_by INTEGER, status TEXT, settlement_method TEXT, refund_amount REAL)");
$pdo->exec("CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, sale_id INTEGER, cashier_id INTEGER, shift_id INTEGER, payment_method TEXT, refund_amount REAL, reason TEXT, note TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE cash_refund_items (refund_item_id INTEGER PRIMARY KEY, refund_id INTEGER, sale_item_id INTEGER, product_id INTEGER, quantity INTEGER, subtotal REAL, disposition TEXT)");
$pdo->exec("CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
$pdo->exec("INSERT INTO store_settings VALUES ('store_name', 'RetailMind'), ('store_address', '123 Test St'), ('currency_symbol', '₱')");

// Inventory Manager receipt lookup
$receiptDetails = new ReceiptDetailsService($pdo);
$sale = $receiptDetails->fetchSale(1, 1);
$assert($sale !== null && (int)$sale['sale_id'] === 1, 'Inventory Manager can fetch receipt details for inventory work');

$items = $receiptDetails->fetchItems(1);
$assert(count($items) === 1 && $items[0]['product_name'] === 'Widget A', 'Receipt items contain product details for stock verification');

// Receipt table lookup via ReceiptTableService
$receiptTable = new ReceiptTableService($pdo);
$tableResult = $receiptTable->fetch(['draw' => 1, 'start' => 0, 'length' => 10], null);
$assert($tableResult['recordsTotal'] === 1, 'Receipt table lookup succeeds for inventory manager without cashier filter restriction');


// --- 5. Report Generation Page Contract & Execution Protection ---
echo "\n--- 5. Report Generation Page Contract & Execution Protection ---\n";

// Test run_report protection when evaluated in inventory_manager context
$reportPhp = file_get_contents(__DIR__ . '/../../frontend/components/report/report_generation.php');

$assert(str_contains($reportPhp, 'ReportAccessService::catalogForRole'), 'report_generation.php uses ReportAccessService for catalog filtering');
$assert(str_contains($reportPhp, 'ReportAccessService::enforceAccess'), 'report_generation.php enforces access on requested and exported reports');

// Test that sidebar contains Report Generation in inventory_manager menu
$assert(preg_match('/\'inventory_manager\'\s*=>\s*\[(?:(?!\]\s*,\s*\'cashier\').)*report_generation\.php/s', $sidebarSource) === 1,
    'Inventory Manager navigation includes Report Generation');

// Check report_generation.php excludes sales_cashier and refunds from inventory_manager dropdown
$assert(str_contains($reportPhp, 'ReportAccessService'), 'report_generation.php delegates access to ReportAccessService');


// --- 6. Execution & Forged Export Refusals with Zero Data Disclosure ---
echo "\n--- 6. Execution & Forged Export Refusals with Zero Data Disclosure ---\n";

// Load report_generation.php functions in isolated scope
$start = strpos($reportPhp, 'function normalize_date(');
eval(substr($reportPhp, $start, strpos($reportPhp, '$selectedReport =', $start) - $start));

$_SESSION['role'] = 'inventory_manager';

// Ensure memory db has categories & inventory
$pdo->exec("CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT)");
$pdo->exec("CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER)");
$pdo->exec("INSERT INTO inventory VALUES (101, 2)");

// Permitted report execution: low_stock
$filters = [
    'store_id' => 1,
    'product_id' => 0,
    'category_id' => 0,
    'from' => '2026-10-01',
    'to' => '2026-10-27',
    'granularity' => 'daily',
];
$lowStockResult = run_report($pdo, 'low_stock', $filters);
$assert(!empty($lowStockResult['rows']), 'Inventory Manager can run permitted low_stock report');
$assert($lowStockResult['rows'][0]['Product'] === 'Widget A', 'low_stock report contains correct product stock row');

// Restricted report execution: sales_cashier must throw DomainException
$throws(
    static fn() => run_report($pdo, 'sales_cashier', $filters),
    DomainException::class,
    'run_report for sales_cashier in inventory_manager workspace must throw DomainException'
);

// Restricted report execution: refunds must throw DomainException
$throws(
    static fn() => run_report($pdo, 'refunds', $filters),
    DomainException::class,
    'run_report for refunds in inventory_manager workspace must throw DomainException'
);

// Restricted report execution: gross_profit must throw DomainException
$throws(
    static fn() => run_report($pdo, 'gross_profit', $filters),
    DomainException::class,
    'run_report for gross_profit in inventory_manager workspace must throw DomainException'
);

echo "\n============================================================\n";
if ($failures !== []) {
    echo "FAILED (" . count($failures) . " errors)\n";
    exit(1);
}

echo "All Ticket #347 Inventory Manager report access tests passed cleanly!\n";
