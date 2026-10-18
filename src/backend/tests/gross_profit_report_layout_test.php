<?php
// Gross profit Store Reports layout C integration test.
// GitHub Issue #232 / Parent #227. Blocked by #229.

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Dashboard/StoreOperationsDashboardWorkspace.php';
require_once __DIR__ . '/../app/Services/GrossProfitService.php';
require_once __DIR__ . '/../app/Services/PhilippineTime.php';

use App\Attention\Clock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;
use App\Services\GrossProfitService;
use App\Services\PhilippineTime;

final class LayoutCTestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
    public function set(DateTimeImmutable $now): void { $this->now = $now; }
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

try {
    echo "Starting Ticket #232 Gross Profit Report Layout C Integration Tests...\n";

    StoreOperationsDashboardWorkspace::invalidateCache();

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
    $pdo->sqliteCreateFunction('GREATEST', static fn(...$vals) => max(...$vals));
    $pdo->sqliteCreateFunction('DATE_ADD', static fn(string $date, string $interval, int $days) => date('Y-m-d', strtotime("{$date} +{$days} days")), 3);

    $tables = [
        'CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT, updated_by INTEGER, updated_at TEXT, PRIMARY KEY (setting_scope, setting_key))',
        'CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT)',
        'CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, role_id INTEGER, status TEXT)',
        'CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, gross_amount REAL, total_amount REAL, payment_method TEXT, discount_type TEXT, discount_value REAL, discount_amount REAL, sale_date TEXT)',
        'CREATE TABLE sale_items (sale_item_id INTEGER PRIMARY KEY, sale_id INTEGER, product_id INTEGER, quantity INTEGER, unit_price REAL, subtotal REAL)',
        'CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT)',
        'CREATE TABLE sale_item_batches (sale_item_batch_id INTEGER PRIMARY KEY, sale_item_id INTEGER, batch_id INTEGER, quantity INTEGER, unit_cost REAL, cost_status TEXT)',
        'CREATE TABLE product_batches (batch_id INTEGER PRIMARY KEY, product_id INTEGER, remaining_quantity INTEGER, cost_price REAL, expiration_date TEXT, date_received TEXT)',
        'CREATE TABLE sale_reversals (reversal_id INTEGER PRIMARY KEY, sale_id INTEGER, reversal_type TEXT, status TEXT, reason TEXT, created_at TEXT)',
        'CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, opened_at TEXT, status TEXT, closed_at TEXT, cash_variance REAL, reviewed_at TEXT)',
        'CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT, barcode TEXT DEFAULT "", branch_id INTEGER NOT NULL DEFAULT 1, category_id INTEGER DEFAULT 1, cost_price REAL, reorder_level INTEGER, status TEXT)',
        'CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER)',
        'CREATE TABLE inventory_adjustments (adjustment_id INTEGER PRIMARY KEY, product_id INTEGER, adjustment_qty INTEGER, adjustment_type TEXT, reported_at TEXT, status TEXT)',
        'CREATE TABLE fiscal_periods (period_id INTEGER PRIMARY KEY, period_name TEXT, start_date TEXT, end_date TEXT, status TEXT)',
        'CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, completed_at TEXT)',
        'CREATE TABLE demand_forecasts (product_id INTEGER, job_id INTEGER, readiness_level TEXT)',
        'CREATE TABLE ml_model_evaluations (evaluation_id INTEGER PRIMARY KEY, horizon_days INTEGER, mae REAL, rmse REAL, wape REAL)',
        'CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, sale_id INTEGER, shift_id INTEGER, cashier_id INTEGER, refund_amount REAL, payment_method TEXT, reason TEXT, note TEXT, created_at TEXT)',
        'CREATE TABLE cash_refund_items (refund_item_id INTEGER PRIMARY KEY, refund_id INTEGER, sale_item_id INTEGER, product_id INTEGER, quantity INTEGER, unit_price REAL, subtotal REAL, disposition TEXT)',
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'inventory_manager'), (4, 'super_admin')");
    $pdo->exec("INSERT INTO users VALUES (1, 'Ada Admin', 1, 'active'), (2, 'Casey Cashier', 2, 'active'), (3, 'Ian Inventory', 3, 'active'), (4, 'Sam Super', 4, 'active')");
    $pdo->exec("INSERT INTO categories VALUES (1, 'Beverages'), (2, 'Snacks')");
    $pdo->exec("INSERT INTO products VALUES (101, 'Artisan Coffee', 'COF-101', '10101', 1, 1, 60.00, 5, 'active')");
    $pdo->exec("INSERT INTO products VALUES (102, 'Matcha Tea', 'TEA-102', '10202', 1, 1, 40.00, 5, 'active')");
    $pdo->exec("INSERT INTO inventory VALUES (101, 100), (102, 100)");
    $pdo->exec("INSERT INTO product_batches VALUES (1, 101, 100, 50.00, '2027-01-01', '2026-09-01'), (2, 102, 100, 30.00, '2027-01-01', '2026-09-01')");

    $service = new GrossProfitService($pdo);

    // ==========================================
    // 1. Administrator Workspace Authorization
    // ==========================================
    echo "\n--- 1. Administrator Workspace Authorization ---\n";
    $adminAllowed = true;
    try {
        GrossProfitService::enforceAdministratorWorkspace('admin');
    } catch (DomainException) {
        $adminAllowed = false;
    }
    $assert($adminAllowed, 'Administrator role allowed access to gross profit reporting');

    $rolesToDeny = ['cashier', 'inventory_manager', 'super_admin', 'unknown'];
    foreach ($rolesToDeny as $deniedRole) {
        $wasDenied = false;
        try {
            GrossProfitService::enforceAdministratorWorkspace($deniedRole);
        } catch (DomainException) {
            $wasDenied = true;
        }
        $assert($wasDenied, "Role '{$deniedRole}' must be denied gross profit reporting");
    }

    // ==========================================
    // 2. Date Validation & Range Handling
    // ==========================================
    echo "\n--- 2. Date Validation & Range Handling ---\n";
    $fromAfterTo = false;
    try {
        $service->resolveDateRange(['from' => '2026-10-20', 'to' => '2026-10-10']);
    } catch (InvalidArgumentException $e) {
        $fromAfterTo = $e->getMessage() === 'Start date must not be after end date.';
    }
    $assert($fromAfterTo, 'from > to throws exact message: Start date must not be after end date.');

    $invalidCal = false;
    try {
        $service->resolveDateRange(['from' => '2026-02-31', 'to' => '2026-10-10']);
    } catch (InvalidArgumentException $e) {
        $invalidCal = $e->getMessage() === 'Choose a valid Philippine calendar date.';
    }
    $assert($invalidCal, 'Invalid calendar date throws exact message: Choose a valid Philippine calendar date.');

    // Range preservation & resolution
    $fixedNow = new DateTimeImmutable('2026-10-17 14:00:00', new DateTimeZone(PhilippineTime::ZONE));
    $todayRange = $service->resolveDateRange(['period' => 'today', 'as_of' => $fixedNow]);
    $assert($todayRange['from'] === '2026-10-17' && $todayRange['to'] === '2026-10-17', 'Period today resolves to 2026-10-17');

    $weekRange = $service->resolveDateRange(['period' => 'week', 'as_of' => $fixedNow]);
    $assert($weekRange['from'] === '2026-10-12' && $weekRange['to'] === '2026-10-17', 'Period week resolves Monday 2026-10-12 to 2026-10-17');

    $monthRange = $service->resolveDateRange(['period' => 'month', 'as_of' => $fixedNow]);
    $assert($monthRange['from'] === '2026-10-01' && $monthRange['to'] === '2026-10-17', 'Period month resolves 1st of month 2026-10-01 to 2026-10-17');

    $customRange = $service->resolveDateRange(['from' => '2026-10-05', 'to' => '2026-10-15', 'as_of' => $fixedNow]);
    $assert($customRange['from'] === '2026-10-05' && $customRange['to'] === '2026-10-15', 'Custom range preserved 2026-10-05 to 2026-10-15');

    // ==========================================
    // 3. Shared Calculation & Reconciliation
    // ==========================================
    echo "\n--- 3. Financial Reconciliation with Dashboard ---\n";
    // Insert recorded sales for 2026-10-17:
    // Sale 1: 1 Coffee @ 100 with batch cost 50. Total = 100.
    $pdo->exec("INSERT INTO sales VALUES (1, 2, 100.00, 100.00, 'cash', 'none', 0, 0, '2026-10-17 10:00:00')");
    $pdo->exec("INSERT INTO sale_items VALUES (1, 1, 101, 1, 100.00, 100.00)");
    $pdo->exec("INSERT INTO sale_item_batches VALUES (1, 1, 1, 1, 50.00, 'recorded')");

    // Sale 2: 2 Matcha @ 50 with batch cost 30 each. Total = 100.
    $pdo->exec("INSERT INTO sales VALUES (2, 2, 100.00, 100.00, 'cash', 'none', 0, 0, '2026-10-17 11:00:00')");
    $pdo->exec("INSERT INTO sale_items VALUES (2, 2, 102, 2, 50.00, 100.00)");
    $pdo->exec("INSERT INTO sale_item_batches VALUES (2, 2, 2, 2, 30.00, 'recorded')");

    // Invalidate cache before load
    StoreOperationsDashboardWorkspace::invalidateCache();

    // Reconcile report calculation with dashboard calculation
    $reportRes = $service->calculate(['period' => 'today', 'as_of' => $fixedNow]);
    $reportSummary = $reportRes['summary'];

    $clock = new LayoutCTestClock($fixedNow);
    $policy = new RoleCapabilityPolicy();
    $workspace = new StoreOperationsDashboardWorkspace($pdo, $clock, $policy);
    $dashboardData = $workspace->load('admin', 30);
    $dashboardToday = $dashboardData['gross_profit']['periods']['today'];

    $assert($reportSummary['total_net_sales'] === $dashboardToday['total_net_sales'], 'Report Net Sales reconciles with Dashboard (200.00)');
    $assert($reportSummary['total_net_cost'] === $dashboardToday['total_net_cost'], 'Report Net Cost reconciles with Dashboard (110.00)');
    $assert($reportSummary['total_gross_profit'] === $dashboardToday['total_gross_profit'], 'Report Gross Profit reconciles with Dashboard (90.00)');
    $assert($reportSummary['total_margin'] === $dashboardToday['total_margin'], 'Report Margin reconciles with Dashboard (45.0%)');
    $assert($reportSummary['status'] === $dashboardToday['status'], 'Report Status reconciles with Dashboard (recorded)');

    // ==========================================
    // 4. Layout C HTML Template Rendering
    // ==========================================
    echo "\n--- 4. Layout C HTML Template Rendering ---\n";
    $root = dirname(__DIR__, 3);
    $reportPhp = file_get_contents($root . '/src/frontend/components/report/report_generation.php');
    $template = substr($reportPhp, strpos($reportPhp, '<!DOCTYPE html>'));
    $template = preg_replace('/<\?php include __DIR__ .*?\?>/', '', $template);

    $renderReportHtml = function(array $state) use ($template, $root): string {
        $phpBinary = PHP_BINARY;
        $cmd = [
            $phpBinary,
            '-r',
            '
            function retailmind_theme_head() {}
            function app_url($p = "") { return "/" . ltrim($p, "/"); }
            function theme_url($p = "") { return "/" . ltrim($p, "/"); }
            function csrf_field() { return "<input type=\"hidden\" name=\"csrf_token\" value=\"test_csrf\">"; }

            $state = json_decode($argv[1], true);
            $selectedReport = $state["selected_report"] ?? "gross_profit";
            $activePeriod = $state["active_period"] ?? "today";
            $filters = $state["filters"] ?? [
                "from" => "2026-10-17",
                "to" => "2026-10-17",
                "product_id" => 0,
                "category_id" => 0,
                "granularity" => "daily",
                "period" => $activePeriod,
            ];
            $reportCatalog = [
                "sales_period" => "Daily, Weekly and Monthly Sales",
                "gross_profit" => "Gross Profit Report",
            ];
            $products = [
                ["product_id" => 101, "sku" => "COF-101", "product_name" => "Artisan Coffee"],
                ["product_id" => 102, "sku" => "TEA-102", "product_name" => "Matcha Tea"],
            ];
            $categories = [
                ["category_id" => 1, "category_name" => "Beverages"],
            ];
            $validationError = $state["validation_error"] ?? null;
            $loadError = $state["load_error"] ?? null;
            $reportData = $state["report_data"] ?? [
                "headers" => ["SKU", "Product", "Category", "Net Units", "Net Sales", "Net Cost", "Gross Profit", "Margin", "Status"],
                "rows" => [],
                "summary" => null,
                "metrics" => [],
            ];
            $totalRows = count($reportData["rows"]);
            $pageTitle = $selectedReport === "gross_profit" ? "Gross Profit Report" : "Report Generation";

            eval("?>".stream_get_contents(STDIN));
            ',
            json_encode($state),
        ];

        $process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root . '/src/frontend/components/report'
        );

        fwrite($pipes[0], $template);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        if ($stderr && !str_contains($stderr, 'PHP Deprecated') && !str_contains($stderr, 'PHP Warning')) {
            if (str_contains($stderr, 'Fatal error') || str_contains($stderr, 'Uncaught')) {
                throw new RuntimeException("CLI render error: " . $stderr);
            }
        }

        return $stdout;
    };

    // 4A. Recorded State
    $recordedState = [
        'selected_report' => 'gross_profit',
        'active_period' => 'today',
        'filters' => [
            'from' => '2026-10-17',
            'to' => '2026-10-17',
            'product_id' => 0,
            'category_id' => 0,
            'granularity' => 'daily',
            'period' => 'today',
        ],
        'report_data' => [
            'headers' => ['SKU', 'Product', 'Category', 'Net Units', 'Net Sales', 'Net Cost', 'Gross Profit', 'Margin', 'Status'],
            'rows' => [
                [
                    'SKU' => 'COF-101',
                    'Product' => 'Artisan Coffee',
                    'Category' => 'Beverages',
                    'Net Units' => 1,
                    'Gross Sales' => '100.00',
                    'Discounts' => '0.00',
                    'Refunds' => '0.00',
                    'Net Sales' => '100.00',
                    'Net Cost' => '50.00',
                    'Product Cost' => '50.00',
                    'Gross Profit' => '50.00',
                    'Margin' => '50.00%',
                    'Status' => 'Recorded',
                    'raw' => [
                        'product_id' => 101,
                        'product_name' => 'Artisan Coffee',
                        'sku' => 'COF-101',
                        'category_name' => 'Beverages',
                        'net_units' => 1,
                        'net_sales' => 100.00,
                        'net_cost' => 50.00,
                        'gross_profit' => 50.00,
                        'margin' => 50.00,
                        'status' => 'recorded',
                        'is_incomplete' => false,
                        'is_estimated' => false,
                    ],
                ],
            ],
            'summary' => [
                'total_units' => 1,
                'total_gross_sales' => 100.00,
                'total_discounts' => 0.00,
                'total_refunds' => 0.00,
                'total_net_sales' => 100.00,
                'total_net_cost' => 50.00,
                'total_gross_profit' => 50.00,
                'total_margin' => 50.00,
                'status' => 'recorded',
                'affected_lines_count' => 0,
                'known_net_sales' => 100.00,
                'known_net_cost' => 50.00,
                'known_gross_profit' => 50.00,
                'known_margin' => 50.00,
                'is_empty' => false,
            ],
        ],
    ];

    $htmlRecorded = $renderReportHtml($recordedState);

    $assert(str_contains($htmlRecorded, 'Gross Profit Report'), 'Heading must contain Gross Profit Report');
    $assert(str_contains($htmlRecorded, 'id="back-to-dashboard"'), 'Must render Back to dashboard link');
    $assert(str_contains($htmlRecorded, 'period=today'), 'Back to dashboard preserves period=today');
    $assert(str_contains($htmlRecorded, 'gross-profit-layout-c'), 'Must render Layout C container');
    $assert(str_contains($htmlRecorded, 'gp-table-panel'), 'Must render left table panel');
    $assert(str_contains($htmlRecorded, 'gp-summary-panel'), 'Must render right summary panel');
    $assert(str_contains($htmlRecorded, 'gp-product-table'), 'Must render product table');
    $assert(str_contains($htmlRecorded, 'Net Units'), 'Must have Net Units column');
    $assert(str_contains($htmlRecorded, 'Net Sales'), 'Must have Net Sales column');
    $assert(str_contains($htmlRecorded, 'Product Cost'), 'Must have Product Cost column');
    $assert(str_contains($htmlRecorded, 'Gross Profit'), 'Must have Gross Profit column');
    $assert(str_contains($htmlRecorded, 'Status'), 'Must have Status column');
    $assert(str_contains($htmlRecorded, 'status-recorded'), 'Must render status-recorded badge');
    $assert(str_contains($htmlRecorded, 'id="net-units-explanation"'), 'Must render net units explanation');
    $assert(str_contains($htmlRecorded, 'id="at-a-glance-summary"'), 'Must render At a glance summary card');
    $assert(str_contains($htmlRecorded, 'Sales before deductions'), 'Summary lists Sales before deductions');
    $assert(str_contains($htmlRecorded, 'Less discounts'), 'Summary lists Less discounts');
    $assert(str_contains($htmlRecorded, 'Less refunds issued'), 'Summary lists Less refunds issued');
    $assert(str_contains($htmlRecorded, 'Less cost of products sold'), 'Summary lists Less cost of products sold');
    $assert(str_contains($htmlRecorded, 'glance-margin'), 'Summary renders valid profit margin');
    $assert(str_contains($htmlRecorded, 'id="profit-return-help"'), 'Must render Restockable / Damaged returns help');
    $assert(str_contains($htmlRecorded, 'id="profit-exclusion-note"'), 'Must render operating-expense exclusion note');

    // Period controls present and today active
    $assert(str_contains($htmlRecorded, 'data-period="today"'), 'Period controls have Today');
    $assert(str_contains($htmlRecorded, 'data-period="week"'), 'Period controls have This Week');
    $assert(str_contains($htmlRecorded, 'data-period="month"'), 'Period controls have This Month');
    $assert(str_contains($htmlRecorded, 'data-period="custom"'), 'Period controls have Custom');

    // 4B. Incomplete State
    $incompleteState = $recordedState;
    $incompleteState['report_data']['rows'][0]['Status'] = 'Incomplete';
    $incompleteState['report_data']['rows'][0]['raw']['status'] = 'incomplete';
    $incompleteState['report_data']['rows'][0]['raw']['is_incomplete'] = true;
    $incompleteState['report_data']['rows'][0]['raw']['net_cost'] = null;
    $incompleteState['report_data']['rows'][0]['raw']['gross_profit'] = null;
    $incompleteState['report_data']['rows'][0]['raw']['margin'] = null;
    $incompleteState['report_data']['summary']['status'] = 'incomplete';
    $incompleteState['report_data']['summary']['affected_lines_count'] = 1;
    $incompleteState['report_data']['summary']['total_net_cost'] = null;
    $incompleteState['report_data']['summary']['total_gross_profit'] = null;
    $incompleteState['report_data']['summary']['total_margin'] = null;

    $htmlIncomplete = $renderReportHtml($incompleteState);
    $assert(str_contains($htmlIncomplete, 'status-incomplete'), 'Row status must be incomplete');
    $assert(str_contains($htmlIncomplete, 'profit-incomplete-explanation'), 'Must render incomplete explanation notice');
    $assert(str_contains($htmlIncomplete, '1 affected sold line'), 'Must show 1 affected sold line');
    $assert(str_contains($htmlIncomplete, 'known-costs-subtotal'), 'Must render known-cost subtotal');
    $assert(!str_contains($htmlIncomplete, 'id="glance-margin"'), 'Margin must be withheld for incomplete totals');

    // 4C. Estimated State
    $estimatedState = $recordedState;
    $estimatedState['report_data']['rows'][0]['Status'] = 'Estimated';
    $estimatedState['report_data']['rows'][0]['raw']['status'] = 'estimated';
    $estimatedState['report_data']['rows'][0]['raw']['is_estimated'] = true;
    $estimatedState['report_data']['rows'][0]['raw']['margin'] = null;
    $estimatedState['report_data']['summary']['status'] = 'estimated';
    $estimatedState['report_data']['summary']['total_margin'] = null;

    $htmlEstimated = $renderReportHtml($estimatedState);
    $assert(str_contains($htmlEstimated, 'status-estimated'), 'Row status must be estimated');
    $assert(!str_contains($htmlEstimated, 'id="glance-margin"'), 'Margin must be withheld for estimated totals');

    // 4D. Loss Sale State
    $lossState = $recordedState;
    $lossState['report_data']['rows'][0]['raw']['gross_profit'] = -5.00;
    $lossState['report_data']['rows'][0]['raw']['margin'] = -50.00;
    $lossState['report_data']['summary']['total_gross_profit'] = -5.00;
    $lossState['report_data']['summary']['total_margin'] = -50.00;

    $htmlLoss = $renderReportHtml($lossState);
    $assert(str_contains($htmlLoss, '-₱5.00'), 'Loss sale gross profit is -₱5.00');
    $assert(str_contains($htmlLoss, '-50.00%'), 'Loss sale margin is -50.00%');

    // 4E. Empty State
    $emptyState = $recordedState;
    $emptyState['report_data']['rows'] = [];
    $emptyState['report_data']['summary'] = [
        'total_units' => 0,
        'total_gross_sales' => 0.00,
        'total_discounts' => 0.00,
        'total_refunds' => 0.00,
        'total_net_sales' => 0.00,
        'total_net_cost' => 0.00,
        'total_gross_profit' => 0.00,
        'total_margin' => null,
        'status' => 'recorded',
        'affected_lines_count' => 0,
        'known_net_sales' => 0.00,
        'known_net_cost' => 0.00,
        'known_gross_profit' => 0.00,
        'known_margin' => null,
        'is_empty' => true,
    ];

    $htmlEmpty = $renderReportHtml($emptyState);
    $assert(str_contains($htmlEmpty, 'empty-table-message'), 'Empty state table message rendered');
    $assert(str_contains($htmlEmpty, 'No sales or refund activity in the selected period'), 'Explains no sales or refund activity');

    // 4F. Validation Error Rendering (from > to)
    $valErrorState = $recordedState;
    $valErrorState['validation_error'] = 'Start date must not be after end date.';

    $htmlValError = $renderReportHtml($valErrorState);
    $assert(str_contains($htmlValError, 'Start date must not be after end date.'), 'Displays validation error when start date after end date');
    $assert(!str_contains($htmlValError, 'gp-product-table'), 'Does not render product table on validation error');

    // 4G. Negative Constraints (no layout B, no prototype arrows, no floating layout bar)
    $assert(!str_contains($htmlRecorded, 'layout-b'), 'Must NOT ship layout B');
    $assert(!str_contains($htmlRecorded, 'floating-layout-bar'), 'Must NOT ship floating layout bar');
    $assert(!str_contains($htmlRecorded, 'sample-state'), 'Must NOT ship sample-state controls');

    // 4H. Ordinary Reports Preserved
    $ordinaryState = [
        'selected_report' => 'sales_period',
        'active_period' => 'month',
        'filters' => [
            'from' => '2026-10-01',
            'to' => '2026-10-17',
            'product_id' => 0,
            'category_id' => 0,
            'granularity' => 'daily',
            'period' => 'month',
        ],
        'report_data' => [
            'headers' => ['Period', 'Receipts', 'Units Sold', 'Total Sales', 'Refunds'],
            'rows' => [
                ['Period' => '2026-10-17', 'Receipts' => 2, 'Units Sold' => 3, 'Total Sales' => '200.00', 'Refunds' => '0.00'],
            ],
            'metrics' => [],
            'summary' => null,
        ],
    ];

    $htmlOrdinary = $renderReportHtml($ordinaryState);
    $assert(!str_contains($htmlOrdinary, 'gross-profit-layout-c'), 'Ordinary report must NOT render Layout C');
    $assert(str_contains($htmlOrdinary, 'Daily, Weekly and Monthly Sales'), 'Ordinary sales_period report renders title');

} catch (Throwable $e) {
    $failures[] = 'Layout C test threw: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures) {
    fwrite(STDERR, "\nGross profit report layout tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nGross profit report layout tests: passed\n";
