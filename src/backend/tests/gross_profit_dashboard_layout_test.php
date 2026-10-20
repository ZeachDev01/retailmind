<?php
// Gross profit Administrator dashboard layout A integration test.
// GitHub Issue #231 / Parent #227.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Dashboard/StoreOperationsDashboardWorkspace.php';
require_once __DIR__ . '/../app/Services/GrossProfitService.php';

use App\Attention\Clock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;
use App\Services\GrossProfitService;

final class LayoutATestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
    public function set(DateTimeImmutable $now): void { $this->now = $now; }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
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

    $pdo->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'inventory_manager')");
    $pdo->exec("INSERT INTO users VALUES (1, 'Ada Admin', 1, 'active'), (2, 'Casey Cashier', 2, 'active')");
    $pdo->exec("INSERT INTO categories VALUES (1, 'Groceries')");
    $pdo->exec("INSERT INTO products VALUES (10, 'Coffee', 'COF-1', '', 1, 1, 60.00, 5, 'active')");
    $pdo->exec("INSERT INTO inventory VALUES (10, 50)");
    $pdo->exec("INSERT INTO product_batches VALUES (1, 10, 100, 50.00, '2027-01-01', '2026-09-01')");

    // 1. Role authorization check
    $clock = new LayoutATestClock(new DateTimeImmutable('2026-10-17 14:00:00'));
    $policy = new RoleCapabilityPolicy();
    $workspace = new StoreOperationsDashboardWorkspace($pdo, $clock, $policy);

    $nonAdminDenied = false;
    try {
        $workspace->load('cashier', 30);
    } catch (DomainException $e) {
        $nonAdminDenied = true;
    }
    $assert($nonAdminDenied, 'Non-admin role must be denied access to store operations dashboard');

    // 2. Admin workspace load with recorded gross profit
    // Insert recorded sale (1 coffee @ 100 with batch cost 50)
    $pdo->exec("INSERT INTO sales VALUES (1, 2, 100.00, 100.00, 'cash', 'none', 0, 0, '2026-10-17 10:00:00')");
    $pdo->exec("INSERT INTO sale_items VALUES (1, 1, 10, 1, 100.00, 100.00)");
    $pdo->exec("INSERT INTO sale_item_batches VALUES (1, 1, 1, 1, 50.00, 'recorded')");

    $adminView = $workspace->load('admin', 30);
    $assert(isset($adminView['gross_profit']), 'Admin workspace load must include gross_profit payload');
    $gp = $adminView['gross_profit'];
    $assert(isset($gp['periods']['today']), 'Gross profit payload must have today period');
    $assert(isset($gp['periods']['week']), 'Gross profit payload must have week period');
    $assert(isset($gp['periods']['month']), 'Gross profit payload must have month period');

    $todayGp = $gp['periods']['today'];
    $assert($todayGp['status'] === 'recorded', 'Status must be recorded for complete batch costs');
    $assert($todayGp['total_net_sales'] === 100.0, 'Today net sales must be 100.00');
    $assert($todayGp['total_net_cost'] === 50.0, 'Today net cost must be 50.00');
    $assert($todayGp['total_gross_profit'] === 50.0, 'Today gross profit must be 50.00');
    $assert($todayGp['total_margin'] === 50.0, 'Today margin must be 50.0%');
    $assert($todayGp['affected_lines_count'] === 0, 'Today affected lines must be 0 for recorded status');

    // 3. Layout A rendering verification
    // Render dashboard HTML using PHP CLI
    $root = dirname(__DIR__, 3);
    $dashboardPhp = file_get_contents($root . '/src/frontend/components/administrator/dashboard.php');
    $template = substr($dashboardPhp, strpos($dashboardPhp, '<!DOCTYPE html>'));
    $template = preg_replace('/<\?php include __DIR__ .*?\?>/', '', $template);

    $renderHtml = function(array $payload, string $period = 'today', int $range = 30) use ($template, $root): string {
        $phpBinary = PHP_BINARY;
        $cmd = [
            $phpBinary,
            '-r',
            '
            function retailmind_theme_head() {}
            function app_url($p) { return "/".$p; }
            function theme_url($p) { return "/".$p; }
            $displayName = "Ada Administrator";
            $selectedRange = (int)$argv[2];
            $selectedPeriod = $argv[3];
            $rangeUrl = "/components/administrator/dashboard.php?range=".$selectedRange."&period=".$selectedPeriod;
            $dashboard = json_decode($argv[1], true);
            $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
            $formatDate = static fn(?string $v): string => $v ? (new DateTimeImmutable($v))->format("m-d-Y g:i A") : "Unavailable";
            $formatMetric = static fn(float|int $v, bool $c = false): string => ($c ? "₱" : "") . number_format((float)$v, $c ? 2 : 0);
            $formatCurrency = static fn(float|int $v): string => ($v < 0 ? "-₱" : "₱") . number_format(abs((float)$v), 2);
            $formatChange = static fn(?float $c): string => $c === null ? "No prior baseline" : ($c > 0 ? "+" : "") . number_format($c, 1) . "%";
            $netSales = $dashboard["net_sales"] ?? null;
            $hasNetSales = $netSales !== null && is_array($netSales);
            $netSalesPeriods = $hasNetSales ? ($netSales["periods"] ?? ["today" => $netSales]) : [];
            $activePeriodData = $netSalesPeriods[$selectedPeriod] ?? [];
            $activePeriodTitle = match($selectedPeriod) { "week" => "This Week\'s Net Sales", "month" => "This Month\'s Net Sales", default => "Today\'s Net Sales" };
            $activePeriodTotal = $hasNetSales ? ($activePeriodData["total"] ?? 0.0) : null;
            $activePeriodPoints = $hasNetSales ? ($activePeriodData["points"] ?? []) : [];
            $activePeriodHasActivity = $hasNetSales && ($activePeriodData["has_activity"] ?? false);
            $activePeriodIntervalLabel = $selectedPeriod === "today" ? "Hour" : "Day";
            $netSalesPoints = $activePeriodPoints;
            $netSalesTotal = $activePeriodTotal;
            $netSalesHasActivity = $activePeriodHasActivity;
            $netSalesAsOf = $hasNetSales ? ($netSales["as_of"] ?? null) : null;

            $grossProfit = $dashboard["gross_profit"] ?? null;
            $hasGrossProfit = $grossProfit !== null && is_array($grossProfit);
            $grossProfitPeriods = $hasGrossProfit ? ($grossProfit["periods"] ?? []) : [];
            $activeProfitData = $grossProfitPeriods[$selectedPeriod] ?? ($grossProfitPeriods["today"] ?? []);
            $activeProfitTitle = match($selectedPeriod) { "week" => "This Week\'s Gross Profit", "month" => "This Month\'s Gross Profit", default => "Today\'s Gross Profit" };
            $activeCostTitle = match($selectedPeriod) { "week" => "This Week\'s Product Cost", "month" => "This Month\'s Product Cost", default => "Today\'s Product Cost" };
            $profitStatus = (string)($activeProfitData["status"] ?? "recorded");
            $profitTotal = $activeProfitData["total_gross_profit"] ?? null;
            $costTotal = $activeProfitData["total_net_cost"] ?? null;
            $profitMargin = $activeProfitData["total_margin"] ?? null;
            $affectedLinesCount = (int)($activeProfitData["affected_lines_count"] ?? 0);
            $profitRange = $activeProfitData["date_range"] ?? [];
            $activeProfitFrom = (string)($profitRange["from"] ?? date("Y-m-d"));
            $activeProfitTo = (string)($profitRange["to"] ?? date("Y-m-d"));
            $profitReportUrl = app_url("components/report/report_generation.php") . "?report=gross_profit&from=" . urlencode($activeProfitFrom) . "&to=" . urlencode($activeProfitTo);

            $headlineOrder = [];
            $comparisonOrder = [];
            eval("?>".stream_get_contents(STDIN));
            ',
            json_encode($payload),
            (string)$range,
            $period,
        ];

        $process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root . '/src/frontend/components/administrator'
        );

        fwrite($pipes[0], $template);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout;
    };

    $htmlRecorded = $renderHtml($adminView, 'today', 30);

    // Verify 3 Layout A cards
    $assert(str_contains($htmlRecorded, 'profit-cards-grid layout-a-cards'), 'Must render layout A profit cards grid');
    $assert(str_contains($htmlRecorded, 'card-net-sales'), 'Must render Net Sales card');
    $assert(str_contains($htmlRecorded, 'card-gross-profit'), 'Must render Gross Profit card');
    $assert(str_contains($htmlRecorded, 'card-cost-of-sales'), 'Must render Cost of Products Sold card');

    // Restrained green emphasis ONLY on positive complete result
    $assert(str_contains($htmlRecorded, 'card-gross-profit is-positive-complete'), 'Positive complete profit must have is-positive-complete class');
    $assert(str_contains($htmlRecorded, 'Margin: 50.0%'), 'Margin must be displayed for complete recorded sales');

    // Formula bar & operating exclusion note
    $assert(str_contains($htmlRecorded, 'Net Sales'), 'Formula bar must contain Net Sales');
    $assert(str_contains($htmlRecorded, 'Product Cost'), 'Formula bar must contain Product Cost');
    $assert(str_contains($htmlRecorded, 'Gross Profit'), 'Formula bar must contain Gross Profit');
    $assert(str_contains($htmlRecorded, 'Rent, wages, electricity'), 'Must contain operating expenses exclusion note');

    // Expandable return help
    $assert(str_contains($htmlRecorded, 'profit-return-help'), 'Must contain expandable return help details');
    $assert(str_contains($htmlRecorded, 'Restockable returns:'), 'Must explain restockable returns');
    $assert(str_contains($htmlRecorded, 'Damaged returns:'), 'Must explain damaged returns');

    // View profit report link preserving date range
    $assert(str_contains($htmlRecorded, 'report_generation.php?report=gross_profit&amp;from=' . urlencode('2026-10-17')), 'View profit report link must preserve date range from');

    // 4. Incomplete state verification
    // Insert incomplete sale (no batch or cost price recorded)
    $pdo->exec("INSERT INTO products VALUES (11, 'Untracked Item', 'UNT-1', '', 1, 1, NULL, 0, 'active')");
    $pdo->exec("INSERT INTO sales VALUES (2, 2, 80.00, 80.00, 'cash', 'none', 0, 0, '2026-10-17 11:00:00')");
    $pdo->exec("INSERT INTO sale_items VALUES (2, 2, 11, 1, 80.00, 80.00)");

    StoreOperationsDashboardWorkspace::invalidateCache();
    $incompleteView = $workspace->load('admin', 30);
    $incompleteToday = $incompleteView['gross_profit']['periods']['today'];
    $assert($incompleteToday['status'] === 'incomplete', 'Status must be incomplete when sold items lack costs');
    $assert($incompleteToday['affected_lines_count'] === 1, 'Affected lines count must be 1');
    $assert($incompleteToday['total_net_cost'] === null, 'Net cost must be null when incomplete');
    $assert($incompleteToday['total_gross_profit'] === null, 'Gross profit must be null when incomplete');
    $assert($incompleteToday['total_margin'] === null, 'Margin must be null when incomplete');

    $htmlIncomplete = $renderHtml($incompleteView, 'today', 30);
    $assert(str_contains($htmlIncomplete, 'is-incomplete'), 'Gross profit card must have is-incomplete indicator');
    $assert(!str_contains($htmlIncomplete, 'card-gross-profit is-positive-complete'), 'Incomplete profit must NEVER have is-positive-complete emphasis');
    $assert(str_contains($htmlIncomplete, '1 affected sold line missing cost'), 'Must display affected sold lines count');
    $assert(!str_contains($htmlIncomplete, 'id="profit-incomplete-explanation" hidden'), 'Incomplete explanation banner must NOT be hidden');

    // 5. Estimated state verification
    $pdo->exec("DELETE FROM sale_items WHERE sale_item_id = 2");
    $pdo->exec("DELETE FROM sales WHERE sale_id = 2");
    // Insert item with product catalog cost fallback (no batch record)
    $pdo->exec("INSERT INTO products VALUES (12, 'Catalog Costed Item', 'CAT-1', '', 1, 1, 40.00, 0, 'active')");
    $pdo->exec("INSERT INTO sales VALUES (3, 2, 70.00, 70.00, 'cash', 'none', 0, 0, '2026-10-17 12:00:00')");
    $pdo->exec("INSERT INTO sale_items VALUES (3, 3, 12, 1, 70.00, 70.00)");
    $pdo->exec("INSERT INTO sale_item_batches VALUES (3, 3, 1, 1, 40.00, 'estimated')");

    StoreOperationsDashboardWorkspace::invalidateCache();
    $estimatedView = $workspace->load('admin', 30);
    $estimatedToday = $estimatedView['gross_profit']['periods']['today'];
    $assert($estimatedToday['status'] === 'estimated', 'Status must be estimated when catalog cost is used');
    $assert($estimatedToday['total_margin'] === null, 'Margin must be withheld when estimated');
    $assert($estimatedToday['total_gross_profit'] !== null, 'Estimated profit total must be populated');

    $htmlEstimated = $renderHtml($estimatedView, 'today', 30);
    $assert(str_contains($htmlEstimated, 'status-estimated'), 'Must render status-estimated badge');
    $assert(!str_contains($htmlEstimated, 'card-gross-profit is-positive-complete'), 'Estimated profit must NOT have is-positive-complete emphasis');
    $assert(str_contains($htmlEstimated, 'Costs estimated &middot; Margin withheld'), 'Estimated profit must show margin withheld hint');

    // 6. Independent rolling comparison range
    $view7 = $workspace->load('admin', 7);
    $view30 = $workspace->load('admin', 30);
    $view90 = $workspace->load('admin', 90);
    $assert($view7['range_days'] === 7, '7-day view has range_days 7');
    $assert($view30['range_days'] === 30, '30-day view has range_days 30');
    $assert($view90['range_days'] === 90, '90-day view has range_days 90');
    // Periods in gross_profit remain today/week/month regardless of rolling range
    $assert(isset($view7['gross_profit']['periods']['today']), '7-day view retains today profit');
    $assert(isset($view90['gross_profit']['periods']['today']), '90-day view retains today profit');

} catch (Throwable $e) {
    $failures[] = 'Layout A test threw: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures) {
    fwrite(STDERR, "Gross profit dashboard layout tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Gross profit dashboard layout tests: passed\n";
