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
require_once __DIR__ . '/../app/Services/PhilippineTime.php';
require_once __DIR__ . '/../app/Services/ReportAccessService.php';

use App\Services\PhilippineTime;
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

echo "Starting Ticket #346 Inventory Valuation & Truthful Scope Contract Tests...\n\n";

if (!function_exists('current_role')) {
    function current_role(): ?string {
        return $_SESSION['role'] ?? 'admin';
    }
}

// Load report_generation functions without executing page lifecycle
$source = file_get_contents(__DIR__ . '/../../frontend/components/report/report_generation.php');
$fnStart = strpos($source, 'function normalize_date(');
$fnEnd = strpos($source, '$selectedReport =', $fnStart);
eval(substr($source, $fnStart, $fnEnd - $fnStart));

// Setup SQLite in-memory test database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('DATEDIFF', static function (string $d1, string $d2): int {
    return (int)round((strtotime($d1) - strtotime($d2)) / 86400);
}, 2);

$pdo->exec('CREATE TABLE products (product_id INTEGER PRIMARY KEY, branch_id INTEGER, sku TEXT, barcode TEXT, product_name TEXT, category_id INTEGER, cost_price REAL, unit_price REAL, reorder_level INTEGER)');
$pdo->exec('CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT)');
$pdo->exec('CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER)');
$pdo->exec('CREATE TABLE product_batches (batch_id INTEGER PRIMARY KEY, product_id INTEGER, receiving_id INTEGER, batch_number TEXT, remaining_quantity INTEGER, cost_price REAL, expiration_date TEXT)');
$pdo->exec('CREATE TABLE stock_receiving (receiving_id INTEGER PRIMARY KEY, cost_price REAL, total_cost REAL, accepted_qty INTEGER, received_qty INTEGER)');

// --- Test Case 1: Valuation based on remaining batch quantities and recorded purchase costs (The PHP 110 example) ---
// Product 1: 10 units at PHP 5 + 10 units at PHP 6. Total on hand = 20. Current product catalog cost = 10 (should NOT be used for all 20 units!).
// Recorded Stock Value must be PHP 110.00.
$pdo->exec("INSERT INTO products VALUES (1, 1, 'P1', NULL, 'Multi-Batch Item', 1, 10.00, 15.00, 5)");
$pdo->exec("INSERT INTO categories VALUES (1, 'Groceries')");
$pdo->exec("INSERT INTO inventory VALUES (1, 20)");
$pdo->exec("INSERT INTO product_batches VALUES (1, 1, NULL, 'B1', 10, 5.00, '2027-01-01')");
$pdo->exec("INSERT INTO product_batches VALUES (2, 1, NULL, 'B2', 10, 6.00, '2027-02-01')");

$valResult = run_report($pdo, 'inventory_valuation', [
    'store_id' => 1, 'product_id' => 0, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-28',
]);

$expectedHeaders = [
    'SKU', 'Product', 'Category', 'Current Stock', 'Cost Price', 'Retail Price',
    'Price minus cost per item', 'Recorded Value', 'Estimated Value', 'Cost Missing Qty',
    'Valuation Basis', 'Valuation Status'
];
$assert($valResult['headers'] === $expectedHeaders, 'Valuation headers reflect Price minus cost per item, Current Stock, Recorded Value, Estimated Value, Cost Missing Qty, Valuation Basis, and Valuation Status');

$row1 = null;
foreach ($valResult['rows'] as $r) {
    if ($r['Product'] === 'Multi-Batch Item') {
        $row1 = $r;
        break;
    }
}
$assert($row1 !== null, 'Row for Multi-Batch Item found');
$assert(($row1['Recorded Value'] ?? '') === '110.00', '10 units at PHP 5 + 10 units at PHP 6 yields Recorded Value PHP 110.00 (not 20 * 10 = 200)');
$assert(($row1['Estimated Value'] ?? '') === '0.00', 'Estimated value is 0.00 when all units have recorded batch costs');
$assert(($row1['Cost Missing Qty'] ?? 0) === 0, 'Cost missing qty is 0');
$assert(($row1['Price minus cost per item'] ?? '') === '5.00', 'Price minus cost per item is retail minus catalog cost (15 - 10 = 5)');
$assert(($row1['Current Stock'] ?? 0) === 20, 'Current Stock is 20');
$assert(($row1['Valuation Status'] ?? '') === 'recorded', 'Valuation status is recorded');

// --- Test Case 2: Receiving table provenance when batch cost_price is null ---
// Product 2: 5 units in batch linked to stock_receiving with total_cost = 100, accepted_qty = 5 (unit cost 20).
$pdo->exec("INSERT INTO products VALUES (2, 1, 'P2', NULL, 'Receiving Batch Item', 1, 25.00, 30.00, 5)");
$pdo->exec("INSERT INTO inventory VALUES (2, 5)");
$pdo->exec("INSERT INTO stock_receiving VALUES (10, NULL, 100.00, 5, 5)");
$pdo->exec("INSERT INTO product_batches VALUES (3, 2, 10, 'B3', 5, NULL, '2027-01-01')");

$valResult2 = run_report($pdo, 'inventory_valuation', [
    'store_id' => 1, 'product_id' => 2, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-28',
]);
$row2 = $valResult2['rows'][0] ?? [];
$assert(($row2['Recorded Value'] ?? '') === '100.00', 'Batch linked to receiving resolves purchase cost (5 * 20 = 100.00)');
$assert(($row2['Estimated Value'] ?? '') === '0.00', 'Estimated value is 0.00');
$assert(($row2['Valuation Status'] ?? '') === 'recorded', 'Status is recorded');

// --- Test Case 3: Explicit zero batch cost vs default-zero / missing cost ---
// Product 3: Explicit recorded zero purchase cost (e.g. promotional free goods or free sample received at 0.00)
$pdo->exec("INSERT INTO products VALUES (3, 1, 'P3', NULL, 'Explicit Zero Cost', 1, 0.00, 10.00, 0)");
$pdo->exec("INSERT INTO inventory VALUES (3, 4)");
$pdo->exec("INSERT INTO product_batches VALUES (4, 3, NULL, 'B4', 4, 0.00, '2027-01-01')");

$valResult3 = run_report($pdo, 'inventory_valuation', [
    'store_id' => 1, 'product_id' => 3, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-28',
]);
$row3 = $valResult3['rows'][0] ?? [];
$assert(($row3['Recorded Value'] ?? '') === '0.00', 'Explicit zero cost batch is recorded value 0.00');
$assert(($row3['Estimated Value'] ?? '') === '0.00', 'Estimated value is 0.00');
$assert(($row3['Cost Missing Qty'] ?? 0) === 0, 'Cost Missing Qty is 0 for explicit zero');
$assert(($row3['Valuation Status'] ?? '') === 'recorded', 'Valuation status is recorded for explicit zero');

// Product 4: Missing purchase cost (unbatched or batch cost null, catalog cost = 0.00 / default zero)
// Default-zero catalog cost is NOT evidence of free goods! It must be treated as cost missing!
$pdo->exec("INSERT INTO products VALUES (4, 1, 'P4', NULL, 'Default Zero Missing', 1, 0.00, 10.00, 0)");
$pdo->exec("INSERT INTO inventory VALUES (4, 8)");

$valResult4 = run_report($pdo, 'inventory_valuation', [
    'store_id' => 1, 'product_id' => 4, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-28',
]);
$row4 = $valResult4['rows'][0] ?? [];
$assert(($row4['Recorded Value'] ?? '') === '0.00', 'Recorded value is 0.00 when cost missing');
$assert(($row4['Estimated Value'] ?? '') === '0.00', 'Estimated value is 0.00 when catalog cost is default 0.00');
$assert(($row4['Cost Missing Qty'] ?? 0) === 8, 'Cost Missing Qty is 8 because default-zero catalog cost is not free goods');
$assert(($row4['Valuation Status'] ?? '') === 'missing', 'Valuation status is missing');

// Product 5: Missing batch purchase cost, but positive current Product cost (e.g. 50.00).
// Positive current Product cost serves as an identified ESTIMATE.
$pdo->exec("INSERT INTO products VALUES (5, 1, 'P5', NULL, 'Estimated Item', 1, 50.00, 80.00, 0)");
$pdo->exec("INSERT INTO inventory VALUES (5, 3)");

$valResult5 = run_report($pdo, 'inventory_valuation', [
    'store_id' => 1, 'product_id' => 5, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-28',
]);
$row5 = $valResult5['rows'][0] ?? [];
$assert(($row5['Recorded Value'] ?? '') === '0.00', 'Recorded value is 0.00 when batch cost missing');
$assert(($row5['Estimated Value'] ?? '') === '150.00', 'Estimated value is 150.00 (3 * 50.00) using positive product catalog cost');
$assert(($row5['Cost Missing Qty'] ?? 0) === 0, 'Cost Missing Qty is 0 because estimated via catalog cost');
$assert(($row5['Valuation Status'] ?? '') === 'estimated', 'Valuation status is estimated');
$assert(str_contains(strtolower($row5['Valuation Basis'] ?? ''), 'estimate'), 'Valuation basis explains product cost estimate');

// --- Test Case 4: Stock-total / batch quantity mismatches flagged ---
// Product 6: Inventory total says 15, but batches only sum to 10 (mismatch: 5 unbatched / missing batch units).
// Batch has cost 20.00. Product catalog cost is 25.00.
// Should NOT estimate over unreliable quantities or hide mismatch:
// Recorded value = 10 * 20 = 200.00.
// 5 units are unbatched (mismatch flagged!).
$pdo->exec("INSERT INTO products VALUES (6, 1, 'P6', NULL, 'Mismatch Item', 1, 25.00, 40.00, 0)");
$pdo->exec("INSERT INTO inventory VALUES (6, 15)");
$pdo->exec("INSERT INTO product_batches VALUES (5, 6, NULL, 'B5', 10, 20.00, '2027-01-01')");

$valResult6 = run_report($pdo, 'inventory_valuation', [
    'store_id' => 1, 'product_id' => 6, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-28',
]);
$row6 = $valResult6['rows'][0] ?? [];
$assert(($row6['Recorded Value'] ?? '') === '200.00', 'Recorded value from batches is 200.00');
$assert(($row6['Valuation Status'] ?? '') === 'mismatch', 'Valuation status flags mismatch between stock total and batches');
$assert(($row6['Cost Missing Qty'] ?? 0) === 5, '5 units unbatched counted as cost missing');

// --- Test Case 5: Summary metrics and honest combined/partial totals ---
// Check screen metrics for all products combined:
$allResult = run_report($pdo, 'inventory_valuation', [
    'store_id' => 1, 'product_id' => 0, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-28',
]);
$metrics = $allResult['metrics'] ?? [];
$assert(isset($metrics['Total Recorded Value']), 'Metrics have Total Recorded Value');
$assert(isset($metrics['Total Estimated Value']), 'Metrics have Total Estimated Value');
$assert(isset($metrics['Cost Missing Qty']), 'Metrics have Cost Missing Qty');
$assert(isset($metrics['Combined (Partial: Recorded + Estimated)']), 'Metrics label combined total honestly as partial when costs are missing');

// Check screen metrics when all costs are complete (no missing cost or mismatch):
$completeResult = run_report($pdo, 'inventory_valuation', [
    'store_id' => 1, 'product_id' => 1, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-28',
]);
$completeMetrics = $completeResult['metrics'] ?? [];
$assert(isset($completeMetrics['Combined Total (Recorded + Estimated)']), 'Metrics label combined total honestly as complete when no costs are missing');

// --- Test Case 6: Low Stock report headers and labels ---
$lowResult = run_report($pdo, 'low_stock', [
    'store_id' => 1, 'product_id' => 0, 'category_id' => 0,
    'from' => '2026-10-01', 'to' => '2026-10-28',
]);
$assert(in_array('Current Stock', $lowResult['headers'], true), 'Low Stock report header uses "Current Stock" (not historical)');

// --- Test Case 7: Expiration report discloses Today-based scope ---
$today = PhilippineTime::today();
$pdo->exec("INSERT INTO product_batches VALUES (6, 1, NULL, 'B_EXP', 5, 5.00, '{$today}')");
$expResult = run_report($pdo, 'expiration', [
    'store_id' => 1, 'product_id' => 0, 'category_id' => 0,
    'from' => '2025-01-01', 'to' => '2025-01-31', // irrelevant past dates
]);
$expMetrics = $expResult['metrics'] ?? [];
$assert(isset($expMetrics['Scope']), 'Expiration report discloses Today-based scope in metrics');
$assert(str_contains($expMetrics['Scope'], $today) || str_contains($expMetrics['Scope'], 'Today'), 'Expiration scope clarifies evaluation as of Today');

// --- Test Case 8: UI Template & Control Verification ---
// Verify report_generation.php hides ineffective historical date controls for low_stock, inventory_valuation, and expiration
$rgSource = file_get_contents(__DIR__ . '/../../frontend/components/report/report_generation.php');
$assert(str_contains($rgSource, "reportSelect.value === 'low_stock'") || str_contains($rgSource, "['low_stock', 'inventory_valuation', 'expiration']"), 'JS sync hides ineffective date controls for current stock and expiration reports');

// Verify products.php / inventory_overview.php column renaming
$prodSource = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/products.php');
$assert(str_contains($prodSource, 'Price minus cost per item'), 'products.php table or column headers include "Price minus cost per item"');

$imOverviewSource = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/inventory_overview.php');
$assert(str_contains($imOverviewSource, 'Price minus cost per item'), 'inventory_overview.php table or column headers include "Price minus cost per item"');

if (!empty($failures)) {
    echo "\nFailed assertions (" . count($failures) . "):\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}

echo "\nAll Ticket #346 contract tests passed cleanly!\n";
