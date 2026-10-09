<?php

require_once __DIR__ . '/../bootstrap/app.php';

use App\Forecasting\DemandHistoryService;

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: $message");
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed: $message. Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Database/MigrationRunner.php';

echo "Testing App\\Forecasting\\DemandHistoryService...\n";

$runner = new \App\Database\MigrationRunner($pdo, __DIR__ . '/../database/migrations');
$runner->runPending();

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("DELETE FROM cash_refund_items");
$pdo->exec("DELETE FROM cash_refunds");
$pdo->exec("DELETE FROM sale_reversal_items");
$pdo->exec("DELETE FROM sale_reversals");
$pdo->exec("DELETE FROM sale_items");
$pdo->exec("DELETE FROM sales");
$pdo->exec("DELETE FROM stock_movements");
$pdo->exec("DELETE FROM inventory_adjustments");
$pdo->exec("DELETE FROM inventory");
$pdo->exec("DELETE FROM store_calendar");
$pdo->exec("DELETE FROM product_batches");
$pdo->exec("DELETE FROM supplier_products");
$pdo->exec("DELETE FROM products");
$pdo->exec("DELETE FROM users");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (1, 'Test User', 'test', 'hash', 1, 1)");
$pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'Test Category') ON DUPLICATE KEY UPDATE category_name='Test Category'");
$pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, category_id) VALUES (1, 'SKU1', 'BAR1', 'Test Product', 10.00, 5.00, 1)");

$service = new DemandHistoryService();
$cutoff = date('Y-m-d H:i:s');

// Test 1: Empty state (Missing Evidence)
$evidence = $service->getDailyEvidence($pdo, 1, 1, '2026-10-01', '2026-10-01', $cutoff);
assert_same(1, count($evidence), 'Should return one day of evidence');
assert_same('2026-10-01', $evidence[0]['date'], 'Date should match requested');
assert_same(0, $evidence[0]['valid_sales_units'], 'No sales means 0 demand');
assert_same(null, $evidence[0]['calendar_status'], 'Unrecorded calendar means null status');
assert_same(0, $evidence[0]['end_of_day_stock'], 'No stock movements means 0 end of day stock');
assert_same('missing_evidence', $evidence[0]['classification'], 'No calendar or stock makes it missing evidence');

// Setup Store Calendar and Stock
$pdo->exec("INSERT INTO store_calendar (branch_id, calendar_date, status) VALUES (1, '2026-10-02', 'open')");
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 50, 'purchase', '2026-10-02 08:00:00')");

// Test 2: Genuine Zero
$evidence = $service->getDailyEvidence($pdo, 1, 1, '2026-10-02', '2026-10-02', $cutoff);
assert_same('genuine_zero', $evidence[0]['classification'], 'Open with stock but no sales is genuine_zero');
assert_same(50, $evidence[0]['end_of_day_stock'], 'Stock should be 50');

// Test 3: Valid Demand
// Add a sale on 2026-10-03
$pdo->exec("INSERT INTO store_calendar (branch_id, calendar_date, status) VALUES (1, '2026-10-03', 'open')");
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, total_amount, sale_date) VALUES (100, 1, 10.00, '2026-10-03 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (100, 100, 1, 2, 10.00, 20.00)");
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, -2, 'sale', '2026-10-03 10:00:00')");

$evidence = $service->getDailyEvidence($pdo, 1, 1, '2026-10-03', '2026-10-03', $cutoff);
assert_same('valid_demand', $evidence[0]['classification'], 'Open with sales is valid_demand');
assert_same(2, $evidence[0]['valid_sales_units'], 'Sold 2 units');
assert_same(48, $evidence[0]['end_of_day_stock'], 'Stock drops to 48');

// Test 4: Refund/Reversal Exclusion
$pdo->exec("INSERT INTO store_calendar (branch_id, calendar_date, status) VALUES (1, '2026-10-04', 'open')");
// Sale of 3
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, total_amount, sale_date) VALUES (101, 1, 30.00, '2026-10-04 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (101, 101, 1, 3, 10.00, 30.00)");
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, -3, 'sale', '2026-10-04 10:00:00')");
// Reversal of 1
$pdo->exec("INSERT INTO sale_reversals (reversal_id, sale_id, reversal_type, status, reason, requested_by, created_at, approved_at) VALUES (1, 101, 'cancel', 'approved', 'Test', 1, '2026-10-04 11:00:00', '2026-10-04 11:00:00')");
$pdo->exec("INSERT INTO sale_reversal_items (reversal_id, sale_item_id, product_id, quantity, unit_price, subtotal) VALUES (1, 101, 1, 1, 10.00, 10.00)");

$evidence = $service->getDailyEvidence($pdo, 1, 1, '2026-10-04', '2026-10-04', $cutoff);
assert_same(2, $evidence[0]['valid_sales_units'], 'Sold 3, reversed 1 = 2 valid units');

// Test 5: Readiness Summary
$summary = $service->getReadinessSummary($pdo, 1, 1, '2026-10-01', '2026-10-04', $cutoff);
assert_same(3, $summary['usable_history_days'], 'Days 2, 3, and 4 are usable (genuine_zero or valid_demand)');
assert_same(1, $summary['excluded_days']['missing_evidence'] ?? 0, 'Day 1 is missing evidence');
assert_same(0, $summary['excluded_days']['closed'] ?? 0, 'No closed days');
assert_same(1, $summary['excluded_days']['missing_evidence'] ?? 0, 'Day 1 is missing evidence');
assert_same(0, $summary['excluded_days']['closed'] ?? 0, 'No closed days');

echo "Demand history contract passed.\n";
