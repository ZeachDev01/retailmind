<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Forecasting\DemandHistoryService;
use App\Store\ScheduledDemandDriverService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] $message\n";
    } else {
        echo "  [PASS] $message\n";
    }
};

echo "Testing App\\Forecasting\\DemandHistoryService (Ticket #161)...\n\n";

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT,
            product_name TEXT,
            status TEXT DEFAULT 'active'
        );
        CREATE TABLE forecast_sales_imports (
            product_id INTEGER,
            sale_date TEXT,
            quantity INTEGER,
            PRIMARY KEY (product_id, sale_date)
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
            sale_id INTEGER,
            product_id INTEGER,
            quantity INTEGER,
            unit_price REAL,
            subtotal REAL
        );
        CREATE TABLE sale_reversals (
            reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            reversal_type TEXT,
            status TEXT,
            reason TEXT,
            requested_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            approved_at TEXT
        );
        CREATE TABLE sale_reversal_items (
            reversal_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            reversal_id INTEGER,
            sale_item_id INTEGER,
            product_id INTEGER,
            quantity INTEGER,
            unit_price REAL,
            subtotal REAL
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
        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            change_qty INTEGER,
            reason TEXT,
            moved_at TEXT NOT NULL
        );
        CREATE TABLE scheduled_demand_drivers (
            driver_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            driver_type TEXT,
            effective_date TEXT NOT NULL,
            scheduled_at TEXT NOT NULL,
            details_json TEXT
        );
    ");

    $pdo->exec("INSERT INTO products (product_id, sku, product_name) VALUES (1, 'SKU-001', 'Test Product')");

    $service = new DemandHistoryService();
    $cutoff = '2026-10-10 18:00:00';

    // -------------------------------------------------------------
    // Test 1: Empty state (Missing Evidence)
    // -------------------------------------------------------------
    echo "--- Test 1: Empty state (Missing Evidence) ---\n";
    $evidence = $service->getDailyEvidence($pdo, 1, '2026-10-01', '2026-10-01', $cutoff);
    $assert(count($evidence) === 1, "Returns exactly one day of evidence");
    $assert($evidence[0]['date'] === '2026-10-01', "Date matches requested");
    $assert($evidence[0]['valid_sales_units'] === 0, "No sales recorded = 0 demand");
    $assert($evidence[0]['calendar_status'] === null, "Unrecorded calendar status is null");
    $assert($evidence[0]['classification'] === 'missing_evidence', "Unrecorded date classifies as missing_evidence");

    // -------------------------------------------------------------
    // Test 2: Genuine Zero Demand (Store open, stock > 0, 0 sales)
    // -------------------------------------------------------------
    echo "\n--- Test 2: Genuine Zero Demand ---\n";
    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-02', 'open', '2026-10-01 12:00:00')");
    $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 50, 'purchase', '2026-10-02 08:00:00')");

    $evidence = $service->getDailyEvidence($pdo, 1, '2026-10-02', '2026-10-02', $cutoff);
    $assert($evidence[0]['classification'] === 'genuine_zero', "Open store with stock but 0 sales classifies as genuine_zero");
    $assert($evidence[0]['end_of_day_stock'] === 50, "End of day stock is 50");
    $assert($evidence[0]['valid_sales_units'] === 0, "Valid sales units is 0");

    // -------------------------------------------------------------
    // Test 3: Valid Demand (Store open, sales > 0)
    // -------------------------------------------------------------
    echo "\n--- Test 3: Valid Demand ---\n";
    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-03', 'open', '2026-10-01 12:00:00')");
    $pdo->exec("INSERT INTO sales (sale_id, cashier_id, total_amount, sale_date) VALUES (100, 1, 20.00, '2026-10-03 10:00:00')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (100, 100, 1, 2, 10.00, 20.00)");
    $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, -2, 'sale', '2026-10-03 10:00:00')");

    $evidence = $service->getDailyEvidence($pdo, 1, '2026-10-03', '2026-10-03', $cutoff);
    $assert($evidence[0]['classification'] === 'valid_demand', "Open store with sales classifies as valid_demand");
    $assert($evidence[0]['valid_sales_units'] === 2, "Observed valid sales is 2 units");
    $assert($evidence[0]['end_of_day_stock'] === 48, "End of day stock is 48 (50 - 2)");

    // -------------------------------------------------------------
    // Test 4: Reversal & Refund Deduction & Cutoff Reproduction
    // -------------------------------------------------------------
    echo "\n--- Test 4: Reversal & Refund Deduction & Cutoff Reproduction ---\n";
    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-04', 'open', '2026-10-01 12:00:00')");
    // Sale of 5 units on 2026-10-04 at 09:00
    $pdo->exec("INSERT INTO sales (sale_id, cashier_id, total_amount, sale_date) VALUES (101, 1, 50.00, '2026-10-04 09:00:00')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (101, 101, 1, 5, 10.00, 50.00)");
    // Reversal of 1 unit approved at 11:00 (before early cutoff)
    $pdo->exec("INSERT INTO sale_reversals (reversal_id, sale_id, reversal_type, status, reason, approved_at) VALUES (1, 101, 'cancel', 'approved', 'Customer change', '2026-10-04 11:00:00')");
    $pdo->exec("INSERT INTO sale_reversal_items (reversal_id, sale_item_id, product_id, quantity) VALUES (1, 101, 1, 1)");
    // Reversal of 2 units approved later at 16:00 (after early cutoff of 12:00)
    $pdo->exec("INSERT INTO sale_reversals (reversal_id, sale_id, reversal_type, status, reason, approved_at) VALUES (2, 101, 'return', 'approved', 'Defect', '2026-10-04 16:00:00')");
    $pdo->exec("INSERT INTO sale_reversal_items (reversal_id, sale_item_id, product_id, quantity) VALUES (2, 101, 1, 2)");

    // Early Cutoff at 12:00: only reversal 1 should be reflected (5 - 1 = 4 units)
    $earlyEvidence = $service->getDailyEvidence($pdo, 1, '2026-10-04', '2026-10-04', '2026-10-04 12:00:00');
    $assert($earlyEvidence[0]['valid_sales_units'] === 4, "Early cutoff (12:00) includes only 1 reversal: valid sales = 4");

    // Later Cutoff at 18:00: both reversals reflected (5 - 1 - 2 = 2 units)
    $laterEvidence = $service->getDailyEvidence($pdo, 1, '2026-10-04', '2026-10-04', '2026-10-04 18:00:00');
    $assert($laterEvidence[0]['valid_sales_units'] === 2, "Later cutoff (18:00) includes both reversals: valid sales = 2");

    // -------------------------------------------------------------
    // Test 5: Stockout Censoring vs Closures
    // -------------------------------------------------------------
    echo "\n--- Test 5: Stockout Censoring vs Closures ---\n";
    // 2026-10-05: Store open, but stock moved to 0, 0 sales
    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-05', 'open', '2026-10-01 12:00:00')");
    $currentStock = (int)$pdo->query("SELECT SUM(change_qty) FROM stock_movements WHERE product_id = 1")->fetchColumn();
    $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, -" . $currentStock . ", 'adjustment', '2026-10-05 08:00:00')");

    $stockoutEv = $service->getDailyEvidence($pdo, 1, '2026-10-05', '2026-10-05', $cutoff);
    $assert($stockoutEv[0]['classification'] === 'stockout_censored', "Open store with 0 stock and 0 sales classifies as stockout_censored");
    $assert($stockoutEv[0]['end_of_day_stock'] === 0, "End of day stock is 0");

    // 2026-10-06: Exceptional Closure
    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-06', 'exceptional_closure', '2026-10-01 12:00:00')");
    $closedEv = $service->getDailyEvidence($pdo, 1, '2026-10-06', '2026-10-06', $cutoff);
    $assert($closedEv[0]['classification'] === 'closed', "exceptional_closure classifies as closed");

    // 2026-10-07: Holiday
    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-07', 'holiday', '2026-10-01 12:00:00')");
    $holidayEv = $service->getDailyEvidence($pdo, 1, '2026-10-07', '2026-10-07', $cutoff);
    $assert($holidayEv[0]['classification'] === 'closed', "holiday classifies as closed");

    // -------------------------------------------------------------
    // Test 6: Scheduled Demand Drivers known by origin vs after origin
    // -------------------------------------------------------------
    echo "\n--- Test 6: Scheduled Demand Drivers Timing ---\n";
    $driverService = new ScheduledDemandDriverService();
    // Driver scheduled on 2026-10-07 for effective date 2026-10-08
    $driverService->recordDriver($pdo, 1, 'promotion', '2026-10-08', '2026-10-07 10:00:00', json_encode(['discount_pct' => 15]));
    // Driver scheduled later on 2026-10-09 for effective date 2026-10-08
    $driverService->recordDriver($pdo, 1, 'flash_sale', '2026-10-08', '2026-10-09 15:00:00', json_encode(['flash' => true]));

    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-08', 'open', '2026-10-01 12:00:00')");

    // Cutoff as of 2026-10-08 08:00: only the promotion scheduled on 10-07 is visible
    $evAtCutoff = $service->getDailyEvidence($pdo, 1, '2026-10-08', '2026-10-08', '2026-10-08 08:00:00');
    $assert($evAtCutoff[0]['has_scheduled_driver'] === true, "Scheduled driver known before cutoff is present");
    $assert($evAtCutoff[0]['scheduled_drivers'][0]['driver_type'] === 'promotion', "Driver known at cutoff is 'promotion'");

    // Cutoff as of 2026-10-10: later flash_sale driver supersedes promotion
    $evLaterCutoff = $service->getDailyEvidence($pdo, 1, '2026-10-08', '2026-10-08', '2026-10-10 08:00:00');
    $assert($evLaterCutoff[0]['scheduled_drivers'][0]['driver_type'] === 'flash_sale', "Driver known at later cutoff is 'flash_sale'");

    // -------------------------------------------------------------
    // Test 7: Forecast Readiness Summary (ADR-0005 Policy)
    // -------------------------------------------------------------
    echo "\n--- Test 7: Forecast Readiness Summary (ADR-0005 Policy) ---\n";
    $summary = $service->getReadinessSummary($pdo, 1, '2026-10-01', '2026-10-07', $cutoff);
    $assert($summary['usable_history_days'] === 3, "Usable history days: 3 (genuine_zero on 10-02, valid_demand on 10-03 and 10-04)");
    $assert($summary['readiness_level'] === 'Insufficient', "History under 56 days classifies as Insufficient");
    $assert($summary['excluded_days']['closed'] === 2, "Excluded closed days: 2 (exceptional_closure, holiday)");
    $assert($summary['excluded_days']['stockout_censored'] === 1, "Excluded stockout censored days: 1");
    $assert($summary['excluded_days']['missing_evidence'] === 1, "Excluded missing evidence days: 1");

    // Daily-close observations must not absorb later corrections during backtests.
    $pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, created_at) VALUES (999, 101, '2026-10-05 12:00:00')");
    $pdo->exec("INSERT INTO cash_refund_items (refund_id, sale_item_id, product_id, quantity) VALUES (999, 101, 1, 1)");
    $observed = $service->getObservedHistory($pdo, '2026-10-04');
    $oct4 = array_values(array_filter($observed, fn ($row) => $row['date'] === '2026-10-04' && $row['product_id'] === 1));
    $assert(count($oct4) === 1 && $oct4[0]['units_sold'] === 2, 'Training retains demand known at daily close, excluding later refunds');
    $revised = $service->getObservedHistory($pdo, '2026-10-05');
    $knownRefund = array_values(array_filter($revised, fn ($row) => $row['date'] === '2026-10-04' && $row['available_date'] === '2026-10-05'));
    $assert(count($knownRefund) === 1 && $knownRefund[0]['units_sold'] === 1, 'Later origins receive corrections once the refund is known');
    $unknown = array_filter($observed, fn ($row) => $row['date'] === '2026-10-01');
    $assert(count($unknown) === 0, 'Unknown calendar evidence does not become training demand');

    $pdo->exec("INSERT INTO forecast_sales_imports VALUES
        (2, '2026-09-01', 7), (2, '2026-09-03', 0), (2, '2026-10-11', 99),
        (1, '2026-10-04', 999), (1, '2026-10-05', 0), (1, '2026-10-06', 20)");
    $importedHistory = $service->getObservedHistory($pdo, '2026-09-03');
    $assert(array_column($importedHistory, 'units_sold') === [7, null, 0], 'Import-only product preserves observed totals, unknown gaps, and explicit zero');
    $assert(array_column($importedHistory, 'available_date') === ['2026-09-01', '2026-09-02', '2026-09-03'], 'Historical totals become available at their completed historical day');
    $earlyImport = $service->getDailyEvidence($pdo, 2, '2026-09-01', '2026-09-01', '2026-09-01 12:00:00')[0];
    $assert($earlyImport['classification'] === 'missing_evidence', 'Full-day CSV total cannot leak into an intraday cutoff');
    $futureImport = $service->getDailyEvidence($pdo, 2, '2026-10-11', '2026-10-11', $cutoff)[0];
    $assert($futureImport['classification'] === 'future_unobserved', 'Future imported totals remain unavailable');
    $overlap = $service->getDailyEvidence($pdo, 1, '2026-10-04', '2026-10-04', '2026-10-05 23:59:59')[0];
    $assert($overlap['valid_sales_units'] === 1 && $overlap['observed_gross_sales'] === 5, 'POS and audited corrections win over overlapping imported history');
    $stockoutImport = $service->getDailyEvidence($pdo, 1, '2026-10-05', '2026-10-05', $cutoff)[0];
    $assert($stockoutImport['classification'] === 'stockout_censored', 'Explicit imported zero does not override known stockout');
    $closedImport = $service->getDailyEvidence($pdo, 1, '2026-10-06', '2026-10-06', $cutoff)[0];
    $assert($closedImport['classification'] === 'closed', 'Imported totals do not override known closure');
    $importSummary = $service->getReadinessSummary($pdo, 2, '2026-09-01', '2026-09-03', $cutoff);
    $assert($importSummary['usable_history_days'] === 2 && $importSummary['excluded_days']['missing_evidence'] === 1, 'Readiness counts imports without treating absent dates as zero');

    echo "\n========================================================\n";
    if (empty($failures)) {
        echo "PASS: All Demand History Contract Tests Passed (Ticket #161)\n";
        echo "========================================================\n";
        exit(0);
    } else {
        echo "FAIL: " . count($failures) . " check(s) failed in Demand History Contract:\n";
        foreach ($failures as $f) {
            echo " - $f\n";
        }
        echo "========================================================\n";
        exit(1);
    }

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
