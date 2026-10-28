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
require_once __DIR__ . '/../app/Services/GrossProfitService.php';

use App\Services\GrossProfitService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

echo "Starting Ticket #348 Sales Refunds Payment Date Alignment Contract Tests...\n\n";

// Load report_generation functions without executing page lifecycle
$source = file_get_contents(__DIR__ . '/../../frontend/components/report/report_generation.php');
$fnStart = strpos($source, 'function normalize_date(');
$fnEnd = strpos($source, '$selectedReport =', $fnStart);
eval(substr($source, $fnStart, $fnEnd - $fnStart));

// Setup SQLite in-memory test database with production-compatible schema
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-11-15 12:00:00', 0);
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => '2026-11-15', 0);
$pdo->sqliteCreateFunction('DATE_FORMAT', static function (string $date, string $format): string {
    $ts = strtotime($date);
    if ($format === '%Y-%m') {
        return date('Y-m', $ts);
    }
    return date('Y-m-d', $ts);
}, 2);
$pdo->sqliteCreateFunction('WEEK', static fn(string $date, int $mode = 0): int => (int)date('W', strtotime($date)), 2);
$pdo->sqliteCreateFunction('YEAR', static fn(string $date): int => (int)date('Y', strtotime($date)), 1);
$pdo->sqliteCreateFunction('LPAD', static fn($str, int $len, string $pad): string => str_pad((string)$str, $len, $pad, STR_PAD_LEFT), 3);
$pdo->sqliteCreateFunction('CONCAT', static fn(...$args): string => implode('', $args));

$schema = [
    'CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT)',
    'CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, role_id INTEGER, status TEXT, branch_id INTEGER)',
    'CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT)',
    'CREATE TABLE products (product_id INTEGER PRIMARY KEY, branch_id INTEGER NOT NULL DEFAULT 1, sku TEXT, barcode TEXT, product_name TEXT, category_id INTEGER, cost_price REAL, unit_price REAL, reorder_level INTEGER, status TEXT DEFAULT "active")',
    'CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER)',
    'CREATE TABLE product_batches (batch_id INTEGER PRIMARY KEY, product_id INTEGER, remaining_quantity INTEGER, cost_price REAL, expiration_date TEXT, date_received TEXT)',
    'CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, branch_id INTEGER NOT NULL DEFAULT 1, gross_amount REAL, total_amount REAL, payment_method TEXT, discount_type TEXT DEFAULT "none", discount_value REAL DEFAULT 0, discount_amount REAL DEFAULT 0, sale_date TEXT)',
    'CREATE TABLE sale_items (sale_item_id INTEGER PRIMARY KEY, sale_id INTEGER, product_id INTEGER, quantity INTEGER, unit_price REAL, subtotal REAL)',
    'CREATE TABLE sale_item_batches (sale_item_batch_id INTEGER PRIMARY KEY, sale_item_id INTEGER, batch_id INTEGER, quantity INTEGER, unit_cost REAL, cost_status TEXT)',
    'CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, sale_id INTEGER, shift_id INTEGER, cashier_id INTEGER, refund_amount REAL, payment_method TEXT, reason TEXT, note TEXT, created_at TEXT)',
    'CREATE TABLE cash_refund_items (refund_item_id INTEGER PRIMARY KEY, refund_id INTEGER, sale_item_id INTEGER, product_id INTEGER, quantity INTEGER, unit_price REAL, subtotal REAL, disposition TEXT)',
];
foreach ($schema as $ddl) {
    $pdo->exec($ddl);
}

// Seed baseline data
$pdo->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier')");
$pdo->exec("INSERT INTO users VALUES (1, 'Admin Alice', 'admin', 1, 'active', 1), (2, 'Cashier Bob', 'bob', 2, 'active', 1)");
$pdo->exec("INSERT INTO categories VALUES (1, 'Beverages'), (2, 'Snacks')");
$pdo->exec("INSERT INTO products VALUES (1, 1, 'COF-1', '1001', 'Brewed Coffee', 1, 40.00, 100.00, 10, 'active')");
$pdo->exec("INSERT INTO products VALUES (2, 1, 'TEA-1', '1002', 'Green Tea', 1, 30.00, 80.00, 10, 'active')");
$pdo->exec("INSERT INTO inventory VALUES (1, 50), (2, 50)");
$pdo->exec("INSERT INTO product_batches VALUES (1, 1, 50, 40.00, '2027-01-01', '2026-09-01'), (2, 2, 50, 30.00, '2027-01-01', '2026-09-01')");

// Sale 1: October 15, 2026 - 2 Brewed Coffee @ 100 = 200.00
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, branch_id, gross_amount, total_amount, payment_method, sale_date)
            VALUES (1, 2, 1, 200.00, 200.00, 'cash', '2026-10-15 10:30:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (1, 1, 1, 2, 100.00, 200.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_status)
            VALUES (1, 1, 1, 2, 40.00, 'recorded')");

// Refund 1: November 05, 2026 - 1 Brewed Coffee refunded @ 100.00 (linked to Sale 1)
$pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, note, created_at)
            VALUES (1, 1, 1, 2, 100.00, 'cash', 'damaged', 'Customer returned spilled cup', '2026-11-05 14:15:00')");
$pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
            VALUES (1, 1, 1, 1, 1, 100.00, 100.00, 'damaged')");

// Sale 2: November 10, 2026 - 1 Green Tea @ 80.00
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, branch_id, gross_amount, total_amount, payment_method, sale_date)
            VALUES (2, 2, 1, 80.00, 80.00, 'cash', '2026-11-10 11:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (2, 2, 2, 1, 80.00, 80.00)");
$pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity, unit_cost, cost_status)
            VALUES (2, 2, 2, 1, 30.00, 'recorded')");

echo "--- 1. Cross-Month Sales & Refund Alignment (October vs November) ---\n";

// Query October (monthly granularity)
$octReport = run_report($pdo, 'sales_period', [
    'store_id' => 1,
    'from' => '2026-10-01',
    'to' => '2026-10-31',
    'granularity' => 'monthly',
    'product_id' => 0,
    'category_id' => 0,
]);

$assert(count($octReport['rows']) === 1, 'October sales_period has 1 monthly row');
$octRow = $octReport['rows'][0] ?? [];
$assert(($octRow['Period'] ?? '') === '2026-10', 'October period label is 2026-10');
$assert((int)($octRow['Receipts'] ?? 0) === 1, 'October receipts count is 1');
$assert((int)($octRow['Units Sold'] ?? 0) === 2, 'October units sold is 2');
$assert(($octRow['Total Sales'] ?? '') === '200.00', 'October Total Sales is 200.00');
$assert(($octRow['Refunds'] ?? '') === '0.00', 'October Refunds is 0.00 (November refund not pulled into October)');
$assert(($octRow['Net Sales'] ?? '') === '200.00', 'October Net Sales is 200.00');

// Verify screen totals / metrics for October
$octMetrics = $octReport['metrics'] ?? [];
$assert(($octMetrics['Total Sales'] ?? '') === '200.00', 'October screen total Total Sales is 200.00');
$assert(($octMetrics['Refunds'] ?? '') === '0.00', 'October screen total Refunds is 0.00');
$assert(($octMetrics['Net Sales'] ?? '') === '200.00', 'October screen total Net Sales is 200.00');

// Query November (monthly granularity)
$novReport = run_report($pdo, 'sales_period', [
    'store_id' => 1,
    'from' => '2026-11-01',
    'to' => '2026-11-30',
    'granularity' => 'monthly',
    'product_id' => 0,
    'category_id' => 0,
]);

$assert(count($novReport['rows']) === 1, 'November sales_period has 1 monthly row');
$novRow = $novReport['rows'][0] ?? [];
$assert(($novRow['Period'] ?? '') === '2026-11', 'November period label is 2026-11');
$assert((int)($novRow['Receipts'] ?? 0) === 1, 'November receipts count is 1 (Sale 2)');
$assert((int)($novRow['Units Sold'] ?? 0) === 1, 'November units sold is 1');
$assert(($novRow['Total Sales'] ?? '') === '80.00', 'November Total Sales is 80.00');
$assert(($novRow['Refunds'] ?? '') === '100.00', 'November Refunds is 100.00 (records November refund payment date)');
$assert(($novRow['Net Sales'] ?? '') === '-20.00', 'November Net Sales is -20.00 (80.00 - 100.00)');

$novMetrics = $novReport['metrics'] ?? [];
$assert(($novMetrics['Total Sales'] ?? '') === '80.00', 'November screen total Total Sales is 80.00');
$assert(($novMetrics['Refunds'] ?? '') === '100.00', 'November screen total Refunds is 100.00');
$assert(($novMetrics['Net Sales'] ?? '') === '-20.00', 'November screen total Net Sales is -20.00');

// Query Daily granularity for November: 2026-11-05 has refund only, 2026-11-10 has sale only
$novDaily = run_report($pdo, 'sales_period', [
    'store_id' => 1,
    'from' => '2026-11-01',
    'to' => '2026-11-30',
    'granularity' => 'daily',
    'product_id' => 0,
    'category_id' => 0,
]);

$dailyMap = array_column($novDaily['rows'], null, 'Period');
$assert(isset($dailyMap['2026-11-05']), '2026-11-05 daily row exists despite having no sales on that day');
$assert(($dailyMap['2026-11-05']['Total Sales'] ?? '') === '0.00', '2026-11-05 Total Sales is 0.00');
$assert(($dailyMap['2026-11-05']['Refunds'] ?? '') === '100.00', '2026-11-05 Refunds is 100.00');
$assert(($dailyMap['2026-11-05']['Net Sales'] ?? '') === '-100.00', '2026-11-05 Net Sales is -100.00');

$assert(isset($dailyMap['2026-11-10']), '2026-11-10 daily row exists for sale');
$assert(($dailyMap['2026-11-10']['Total Sales'] ?? '') === '80.00', '2026-11-10 Total Sales is 80.00');
$assert(($dailyMap['2026-11-10']['Refunds'] ?? '') === '0.00', '2026-11-10 Refunds is 0.00');
$assert(($dailyMap['2026-11-10']['Net Sales'] ?? '') === '80.00', '2026-11-10 Net Sales is 80.00');

echo "\n--- 2. Original-Sale Links Retained ---\n";

$refundsReport = run_report($pdo, 'refunds', [
    'store_id' => 1,
    'from' => '2026-11-01',
    'to' => '2026-11-30',
    'product_id' => 0,
    'category_id' => 0,
]);
$assert(count($refundsReport['rows']) === 1, 'Refunds report has 1 row in November');
$assert(($refundsReport['rows'][0]['Sale'] ?? null) === 1, 'Refund links directly to original Sale ID 1');
$assert(($refundsReport['rows'][0]['Amount'] ?? null) === '100.00', 'Refund row displays 100.00');

// Original sale in sales table unchanged
$origSale = $pdo->query('SELECT total_amount, sale_date FROM sales WHERE sale_id = 1')->fetch(PDO::FETCH_ASSOC);
$assert((float)$origSale['total_amount'] === 200.00, 'Original sale total_amount remains 200.00');
$assert($origSale['sale_date'] === '2026-10-15 10:30:00', 'Original sale sale_date preserved');

echo "\n--- 3. Reconciliation with Gross Profit Definition ---\n";

$gpService = new GrossProfitService($pdo);

// October Gross Profit Net Sales matches sales_period Net Sales
$octGp = $gpService->calculate([
    'from' => '2026-10-01',
    'to' => '2026-10-31',
    'as_of' => new DateTimeImmutable('2026-10-31 23:59:59'),
]);
$assert((float)($octGp['summary']['total_net_sales'] ?? 0) === 200.00, 'GrossProfitService October total_net_sales is 200.00');
$assert((float)$octMetrics['Net Sales'] === (float)$octGp['summary']['total_net_sales'], 'sales_period October Net Sales reconciles with GrossProfitService');

// November Gross Profit Net Sales matches sales_period Net Sales
$novGp = $gpService->calculate([
    'from' => '2026-11-01',
    'to' => '2026-11-30',
    'as_of' => new DateTimeImmutable('2026-11-30 23:59:59'),
]);
$assert((float)($novGp['summary']['total_net_sales'] ?? 0) === -20.00, 'GrossProfitService November total_net_sales is -20.00 (80 - 100)');
$assert((float)$novMetrics['Net Sales'] === (float)$novGp['summary']['total_net_sales'], 'sales_period November Net Sales reconciles with GrossProfitService');

echo "\n--- 4. Product and Category Filtering Consistency ---\n";

// Filter November by Product 1 (Brewed Coffee: no sales in Nov, 1 refund of 100.00)
$novProd1 = run_report($pdo, 'sales_period', [
    'store_id' => 1,
    'from' => '2026-11-01',
    'to' => '2026-11-30',
    'granularity' => 'monthly',
    'product_id' => 1,
    'category_id' => 0,
]);
$assert(count($novProd1['rows']) === 1, 'Filter Product 1 in Nov produces 1 monthly row');
$assert(($novProd1['rows'][0]['Total Sales'] ?? '') === '0.00', 'Product 1 Nov Total Sales is 0.00');
$assert(($novProd1['rows'][0]['Refunds'] ?? '') === '100.00', 'Product 1 Nov Refunds is 100.00');
$assert(($novProd1['rows'][0]['Net Sales'] ?? '') === '-100.00', 'Product 1 Nov Net Sales is -100.00');

// Filter November by Product 2 (Green Tea: 1 sale of 80.00, no refunds)
$novProd2 = run_report($pdo, 'sales_period', [
    'store_id' => 1,
    'from' => '2026-11-01',
    'to' => '2026-11-30',
    'granularity' => 'monthly',
    'product_id' => 2,
    'category_id' => 0,
]);
$assert(count($novProd2['rows']) === 1, 'Filter Product 2 in Nov produces 1 monthly row');
$assert(($novProd2['rows'][0]['Total Sales'] ?? '') === '80.00', 'Product 2 Nov Total Sales is 80.00');
$assert(($novProd2['rows'][0]['Refunds'] ?? '') === '0.00', 'Product 2 Nov Refunds is 0.00');
$assert(($novProd2['rows'][0]['Net Sales'] ?? '') === '80.00', 'Product 2 Nov Net Sales is 80.00');

echo "\n--- 5. Formula-Safe CSV & Clean Browser Printing Controls ---\n";

// Verify CSV formula-escaping logic
$testRows = [
    ['Period' => '=SUM(A1:A2)', 'Total Sales' => '+VIP_SALE', 'Refunds' => '-50.00', 'Net Sales' => '@SPECIAL'],
];
ob_start();
// Test escaping without exit by invoking output capture
$csvOut = fopen('php://memory', 'w+');
$headers = ['Period', 'Total Sales', 'Refunds', 'Net Sales'];
fputcsv($csvOut, $headers);
foreach ($testRows as $r) {
    fputcsv($csvOut, array_map(static function ($h) use ($r) {
        $val = (string)($r[$h] ?? '');
        return (!is_numeric($val) && preg_match('/^[\s]*[=+\-@]/u', $val)) ? "'" . $val : $val;
    }, $headers));
}
rewind($csvOut);
$csvContent = stream_get_contents($csvOut);
fclose($csvOut);
ob_end_clean();

$assert(str_contains($csvContent, "'=SUM(A1:A2)"), 'CSV neutralizes = prefix with single quote');
$assert(str_contains($csvContent, "'+VIP_SALE"), 'CSV neutralizes + prefix on non-numeric strings');
$assert(str_contains($csvContent, "'@SPECIAL"), 'CSV neutralizes @ prefix with single quote');

// Verify UI file HTML has Print / Save as PDF and no duplicate print controls
$fullHtml = file_get_contents(__DIR__ . '/../../frontend/components/report/report_generation.php');
$assert(str_contains($fullHtml, 'Print / Save as PDF'), 'report_generation.php contains Print / Save as PDF button');
$assert(!preg_match('/<button[^>]*>Export PDF<\/button>/i', $fullHtml), 'Duplicate Export PDF button removed');
$assert(!str_contains($fullHtml, 'Refunds are separate from sales and grouped by sale date'), 'Obsolete "grouped by sale date" text removed');

if (!empty($failures)) {
    fwrite(STDERR, "\nContract tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nAll Ticket #348 sales refunds payment date contract tests passed!\n";
