<?php
// Database-backed contract for the Administrator Store-operations workspace.
require_once __DIR__ . '/../bootstrap/app.php';
ini_set('error_log', sys_get_temp_dir() . '/retailmind_administrator_dashboard_test.log');

use App\Attention\Clock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

final class StoreDashboardContractClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

try {
    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ([
        'CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT, updated_by INTEGER, updated_at TEXT, PRIMARY KEY (setting_scope, setting_key))',
        'CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT)',
        'CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, role_id INTEGER, status TEXT)',
        'CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, total_amount REAL, discount_type TEXT, discount_value REAL, discount_amount REAL, sale_date TEXT)',
        'CREATE TABLE sale_reversals (reversal_id INTEGER PRIMARY KEY, sale_id INTEGER, reversal_type TEXT, status TEXT, reason TEXT, created_at TEXT)',
        'CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, opened_at TEXT, status TEXT, closed_at TEXT, cash_variance REAL, reviewed_at TEXT)',
        'CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT, cost_price REAL, reorder_level INTEGER, status TEXT)',
        'CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER)',
        'CREATE TABLE inventory_adjustments (adjustment_id INTEGER PRIMARY KEY, product_id INTEGER, adjustment_qty INTEGER, adjustment_type TEXT, reported_at TEXT, status TEXT)',
        'CREATE TABLE fiscal_periods (period_id INTEGER PRIMARY KEY, period_name TEXT, start_date TEXT, end_date TEXT, status TEXT)',
        'CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, completed_at TEXT)',
        'CREATE TABLE demand_forecasts (product_id INTEGER, job_id INTEGER, readiness_level TEXT)',
        'CREATE TABLE ml_model_evaluations (evaluation_id INTEGER PRIMARY KEY, horizon_days INTEGER, mae REAL, rmse REAL, wape REAL)',
        'CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, sale_id INTEGER, shift_id INTEGER, cashier_id INTEGER, refund_amount REAL, payment_method TEXT, reason TEXT, note TEXT, created_at TEXT)',
    ] as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'inventory_manager')");
    $pdo->exec("INSERT INTO users VALUES (1, 'Ada Admin', 1, 'active'), (2, 'Casey Cashier', 2, 'active'), (3, 'Ivy Inventory', 3, 'disabled')");
    $pdo->exec("INSERT INTO products VALUES (10, 'Coffee', 'COF-1', 20, 5, 'active')");
    $pdo->exec("INSERT INTO inventory VALUES (10, 2)");
    $pdo->exec("INSERT INTO sales VALUES
        (1, 2, 150, 'percentage', 30, 45, '2026-09-17 09:00:00'),
        (2, 2, 80, 'none', 0, 0, '2026-08-10 09:00:00')");
    $pdo->exec("INSERT INTO sale_reversals VALUES (1, 1, 'refund', 'pending', 'Customer dispute', '2026-09-17 10:00:00')");
    $pdo->exec("INSERT INTO cashier_shifts VALUES
        (1, 2, '2026-09-18 08:00:00', 'open', NULL, NULL, NULL),
        (2, 2, '2026-09-17 08:00:00', 'closed', '2026-09-17 17:00:00', 150, NULL)");
    $pdo->exec("INSERT INTO inventory_adjustments VALUES (1, 10, -3, 'missing', '2026-09-16 09:00:00', 'pending')");
    $pdo->exec("INSERT INTO fiscal_periods VALUES (1, 'September 2026', '2026-09-01', '2026-09-20', 'open')");
    $pdo->exec("INSERT INTO ml_jobs VALUES (1, 'forecast_generation', 'succeeded', '2026-09-17 07:00:00')");
    $pdo->exec("INSERT INTO demand_forecasts VALUES (10, 1, 'Low')");
    $pdo->exec('INSERT INTO ml_model_evaluations VALUES (1, 7, 2, 3, 20)');

    // Net Sales test data:
    // Clock is UTC: 2026-09-18 02:00:00Z -> 2026-09-18 10:00:00 Philippine time (UTC+08:00)
    // 1. Midnight boundary event
    $pdo->exec("INSERT INTO sales VALUES (101, 2, 50.00, 'none', 0, 0, '2026-09-18 00:00:00')");
    // 2. Already-discounted sale in hour 8
    $pdo->exec("INSERT INTO sales VALUES (102, 2, 150.00, 'percentage', 25, 50, '2026-09-18 08:15:00')");
    // 3. Multiple sales in same bucket: hour 8
    $pdo->exec("INSERT INTO sales VALUES (103, 2, 75.25, 'none', 0, 0, '2026-09-18 08:45:00')");
    // 4. Partial cash refund in hour 8
    $pdo->exec("INSERT INTO cash_refunds VALUES (101, 102, 1, 2, 30.00, 'cash', 'damaged', 'partial return', '2026-09-18 08:50:00')");
    // 5. Refund-only negative hour 9: non-cash refund (card) for an older sale (from 2026-08-10)
    $pdo->exec("INSERT INTO cash_refunds VALUES (102, 2, 1, 2, 40.00, 'card', 'customer_return', 'card refund', '2026-09-18 09:20:00')");
    // 6. Current partial hour 10 at exact as-of boundary
    $pdo->exec("INSERT INTO sales VALUES (104, 2, 100.00, 'none', 0, 0, '2026-09-18 10:00:00')");
    // 7. Future events (must be excluded)
    $pdo->exec("INSERT INTO sales VALUES (105, 2, 999.00, 'none', 0, 0, '2026-09-18 10:00:01')");
    $pdo->exec("INSERT INTO sales VALUES (106, 2, 500.00, 'none', 0, 0, '2026-09-18 14:00:00')");
    $pdo->exec("INSERT INTO cash_refunds VALUES (103, 104, 1, 2, 200.00, 'cash', 'cancel', NULL, '2026-09-18 11:00:00')");
    // 8. Yesterday boundary events (must be excluded)
    $pdo->exec("INSERT INTO sales VALUES (107, 2, 300.00, 'none', 0, 0, '2026-09-17 23:59:59')");
    $pdo->exec("INSERT INTO cash_refunds VALUES (104, 107, 1, 2, 100.00, 'cash', 'cancel', NULL, '2026-09-17 23:59:59')");

    // Clock supplied in UTC to verify Philippine timezone conversion
    $clock = new StoreDashboardContractClock(new DateTimeImmutable('2026-09-18 02:00:00', new DateTimeZone('UTC')));
    $workspace = new StoreOperationsDashboardWorkspace($pdo, $clock, new RoleCapabilityPolicy());
    $view = $workspace->load('admin', 30);

    $assert($view['role'] === 'admin', 'Workspace must identify the Administrator role');
    $assert($view['range_days'] === 30, 'Workspace must preserve a supported selected range');
    $assert($view['state'] === 'ready', 'Seeded actionable workspace should be ready');

    // Net Sales assertions
    $assert(isset($view['net_sales']), 'Payload must include net_sales');
    $ns = $view['net_sales'];
    $assert($ns['period'] === 'today', 'Net sales period must be today');
    $assert($ns['status'] === 'ongoing', 'Period must be marked ongoing');
    $assert($ns['as_of'] === '2026-09-18 10:00:00', 'As-of time must match Philippine time');
    $assert($ns['timezone'] === 'Asia/Manila', 'Timezone must be Asia/Manila');
    $assert($ns['total'] === 305.25, 'Today net sales total must be 305.25 (sales 375.25 - refunds 70.00), got: ' . $ns['total']);
    $assert($ns['sales_total'] === 375.25, 'Sales total must equal 375.25');
    $assert($ns['refunds_total'] === 70.00, 'Refunds total must equal 70.00');
    $assert($ns['has_activity'] === true, 'has_activity must be true when sales/refunds occur');
    $assert(count($ns['points']) === 11, 'Hourly points must cover elapsed hours 0 through 10 (11 buckets), got: ' . count($ns['points']));

    // Verify hourly point sums equal total with currency precision
    $pointSum = 0.0;
    foreach ($ns['points'] as $pt) {
        $pointSum = round($pointSum + $pt['net_sales'], 2);
    }
    $assert($pointSum === 305.25, 'Hourly points sum (' . $pointSum . ') must exactly match displayed total (' . $ns['total'] . ')');

    // Specific bucket assertions:
    // Hour 0: midnight boundary sale 50.00
    $assert($ns['points'][0]['net_sales'] === 50.00, 'Hour 0 must include midnight sale: 50.00');
    // Hour 1: elapsed zero-activity hour
    $assert($ns['points'][1]['net_sales'] === 0.00 && $ns['points'][1]['sales'] === 0.00, 'Hour 1 must be zero-activity');
    // Hour 8: multiple sales (150.00 + 75.25 = 225.25) - refund (30.00) = 195.25
    $assert($ns['points'][8]['sales'] === 225.25, 'Hour 8 sales must be 225.25');
    $assert($ns['points'][8]['refunds'] === 30.00, 'Hour 8 refunds must be 30.00');
    $assert($ns['points'][8]['net_sales'] === 195.25, 'Hour 8 net sales must be 195.25');
    // Hour 9: refund-only negative hour (-40.00)
    $assert($ns['points'][9]['sales'] === 0.00, 'Hour 9 must have 0 sales');
    $assert($ns['points'][9]['refunds'] === 40.00, 'Hour 9 must have 40.00 non-cash refund');
    $assert($ns['points'][9]['net_sales'] === -40.00, 'Hour 9 must be negative: -40.00');
    // Hour 10: current partial hour boundary sale (100.00)
    $assert($ns['points'][10]['net_sales'] === 100.00, 'Hour 10 must include as-of boundary sale: 100.00');

    // Week assertions (2026-09-18 is Friday, 5 days elapsed in week)
    $assert(isset($ns['periods']['week']), 'Net sales must include week period');
    $w = $ns['periods']['week'];
    $assert($w['period'] === 'week', 'Week period identifier must be week');
    $assert($w['status'] === 'ongoing', 'Week status must be ongoing');
    $assert($w['as_of'] === '2026-09-18 10:00:00', 'Week as-of must match Philippine time');
    $assert(count($w['points']) === 5, 'Week points must have 5 daily buckets (Mon..Fri), got: ' . count($w['points']));
    $assert($w['sales_total'] === 825.25, 'Week sales total must equal 825.25, got: ' . $w['sales_total']);
    $assert($w['refunds_total'] === 170.00, 'Week refunds total must equal 170.00, got: ' . $w['refunds_total']);
    $assert($w['total'] === 655.25, 'Week net sales total must equal 655.25, got: ' . $w['total']);
    $assert($w['has_activity'] === true, 'Week has_activity must be true');

    $weekPointSum = 0.0;
    foreach ($w['points'] as $pt) {
        $weekPointSum = round($weekPointSum + $pt['net_sales'], 2);
    }
    $assert($weekPointSum === 655.25, 'Week points sum (' . $weekPointSum . ') must match week total (' . $w['total'] . ')');
    $assert($w['points'][0]['net_sales'] === 0.0, 'Monday must be zero activity');
    $assert($w['points'][3]['sales'] === 450.00, 'Thursday sales must be 450.00 (150.00 + 300.00)');
    $assert($w['points'][3]['refunds'] === 100.00, 'Thursday refunds must be 100.00');
    $assert($w['points'][3]['net_sales'] === 350.00, 'Thursday net sales must be 350.00');
    $assert($w['points'][4]['net_sales'] === 305.25, 'Friday net sales must match Friday today total');

    // Month assertions (September 1 to 18, 18 daily buckets)
    $assert(isset($ns['periods']['month']), 'Net sales must include month period');
    $m = $ns['periods']['month'];
    $assert($m['period'] === 'month', 'Month period identifier must be month');
    $assert($m['status'] === 'ongoing', 'Month status must be ongoing');
    $assert($m['as_of'] === '2026-09-18 10:00:00', 'Month as-of must match Philippine time');
    $assert(count($m['points']) === 18, 'Month points must have 18 daily buckets (1..18), got: ' . count($m['points']));
    $assert($m['sales_total'] === 825.25, 'Month sales total must equal 825.25');
    $assert($m['refunds_total'] === 170.00, 'Month refunds total must equal 170.00');
    $assert($m['total'] === 655.25, 'Month net sales total must equal 655.25');
    $assert($m['has_activity'] === true, 'Month has_activity must be true');

    $monthPointSum = 0.0;
    foreach ($m['points'] as $pt) {
        $monthPointSum = round($monthPointSum + $pt['net_sales'], 2);
    }
    $assert($monthPointSum === 655.25, 'Month points sum (' . $monthPointSum . ') must match month total (' . $m['total'] . ')');
    $assert($m['points'][16]['net_sales'] === 350.00, 'Day 17 net sales must be 350.00');
    $assert($m['points'][17]['net_sales'] === 305.25, 'Day 18 net sales must be 305.25');

    $assert(($view['headline']['sales_cash_exceptions']['value'] ?? 0) === 1, 'Headline must show unreviewed cash variances');
    $assert(!isset($view['headline']['pending_approvals']), 'Retired purchasing approvals must not become a second Stock Issue count');
    $assert($view['headline']['inventory_risks']['label'] === 'Pending Stock Issues', 'Inventory headline must describe retained Stock Issue oversight');
    $actions = array_column($view['actions'], null, 'label');
    $assert(isset($actions['Stock Issue Oversight']), 'Administrator must retain read-only Stock Issue oversight');
    $assert(!isset($actions['Store Approvals']), 'Stock Issues must not be presented as Administrator approvals');
    $assert(($view['headline']['inventory_risks']['value'] ?? 0) === 1, 'Headline must show escalated inventory risks');
    $assert(($view['headline']['fiscal_period']['value'] ?? '') === 'September 2026', 'Headline must show the current Fiscal Period');
    $assert(($view['comparison']['sales']['current'] ?? 0) === 725.25, 'Comparison must total the selected period');
    $assert(($view['comparison']['sales']['previous'] ?? 0) === 80.0, 'Comparison must use the immediately preceding equivalent period');
    $assert(count($view['exceptions']) >= 2, 'Action queue must contain operational exceptions instead of ordinary transactions');
    $assert(($view['staff']['active_cashiers'] ?? 0) === 1, 'Staff oversight must show active Cashiers');
    $assert(($view['staff']['disabled_staff'] ?? 0) === 1, 'Staff oversight must show Disabled Accounts');
    $assert(($view['shifts']['open'] ?? 0) === 1, 'Cashier-shift oversight must show open shifts');
    $assert(($view['forecast']['evaluated_products'] ?? 0) === 1, 'Forecast performance must be visible');
    $assert(($view['forecast']['operational_exceptions'] ?? 0) === 1, 'Operational forecast exceptions must be visible');
    $assert(($view['inventory']['escalated'] ?? 0) === 1, 'Inventory summary must expose escalations');
    $assert(isset($view['freshness']['generated_at'], $view['freshness']['stale_after']), 'Freshness metadata must be returned');
    $assert(($view['freshness']['refresh_seconds'] ?? 0) === 300, 'Workspace must refresh every five minutes');

    foreach (['system_health', 'backup_restore', 'ml_controls', 'platform_settings', 'platform_audit', 'recent_transactions', 'stock_mutations'] as $forbidden) {
        $assert(!array_key_exists($forbidden, $view), "Forbidden dashboard data must be absent: {$forbidden}");
    }
    foreach ($view['actions'] as $action) {
        $assert(!str_contains($action['destination'], 'adjustments.php'), 'Administrator dashboard must expose no direct stock mutation destination');
        $assert(!str_contains($action['destination'], 'stock_receiving.php'), 'Administrator dashboard must expose no receiving control');
    }

    $fallbackRange = $workspace->load('admin', 365);
    $assert($fallbackRange['range_days'] === 30, 'Unsupported ranges must fall back to 30 days');

    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo->exec("DELETE FROM sale_reversals; DELETE FROM cashier_shifts; DELETE FROM inventory_adjustments; DELETE FROM sales; DELETE FROM cash_refunds; DELETE FROM demand_forecasts; DELETE FROM ml_jobs; DELETE FROM ml_model_evaluations");
    $empty = $workspace->load('admin', 7);
    $assert($empty['state'] === 'empty', 'No actionable conditions must produce an explicit empty state');
    $assert($empty['exceptions'] === [], 'Empty state must have no exception rows');
    $assert($empty['net_sales']['total'] === 0.0, 'Zero activity must produce 0.0 total net sales');
    $assert($empty['net_sales']['has_activity'] === false, 'Zero activity must set has_activity to false');
    $assert(count($empty['net_sales']['points']) === 11, 'Zero activity still returns elapsed buckets');

    // --- Additional Edge Cases (Issue #210 Requirement 8) ---
    // 1. Monday boundary: week starts Monday 00:00:00
    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo->exec("INSERT INTO sales VALUES (201, 2, 200.00, 'none', 0, 0, '2026-09-20 23:59:59')"); // Sunday night (previous week)
    $pdo->exec("INSERT INTO sales VALUES (202, 2, 100.00, 'none', 0, 0, '2026-09-21 00:00:00')"); // Monday midnight (new week)
    $mondayClock = new StoreDashboardContractClock(new DateTimeImmutable('2026-09-21 08:00:00', new DateTimeZone('Asia/Manila')));
    $mondayWs = new StoreOperationsDashboardWorkspace($pdo, $mondayClock, new RoleCapabilityPolicy());
    $mondayView = $mondayWs->load('admin', 30);
    $mondayWeek = $mondayView['net_sales']['periods']['week'];
    $assert(count($mondayWeek['points']) === 1, 'Monday must have exactly 1 daily bucket');
    $assert($mondayWeek['points'][0]['net_sales'] === 100.00, 'Sunday night sale must be excluded from Monday week');
    $assert($mondayWeek['total'] === 100.00, 'Monday week total must be 100.00');

    // 2. Week crossing month/year boundary: 2027-01-01 (Friday)
    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo->exec("DELETE FROM sales; DELETE FROM cash_refunds");
    $pdo->exec("INSERT INTO sales VALUES (301, 2, 150.00, 'none', 0, 0, '2026-12-28 10:00:00')"); // Monday in Dec 2026
    $pdo->exec("INSERT INTO sales VALUES (302, 2, 250.00, 'none', 0, 0, '2027-01-01 09:00:00')"); // Friday in Jan 2027
    $pdo->exec("INSERT INTO cash_refunds VALUES (301, 301, 1, 2, 50.00, 'cash', 'damaged', NULL, '2027-01-01 09:30:00')"); // Refund issued in 2027
    $yearClock = new StoreDashboardContractClock(new DateTimeImmutable('2027-01-01 10:00:00', new DateTimeZone('Asia/Manila')));
    $yearWs = new StoreOperationsDashboardWorkspace($pdo, $yearClock, new RoleCapabilityPolicy());
    $yearView = $yearWs->load('admin', 30);
    $yearWeek = $yearView['net_sales']['periods']['week'];
    $yearMonth = $yearView['net_sales']['periods']['month'];
    $assert(count($yearWeek['points']) === 5, 'Crossing year boundary: week must have 5 daily buckets (Mon Dec 28 to Fri Jan 1)');
    $assert($yearWeek['total'] === 350.00, 'Week total must include Dec 28 sale (150) + Jan 1 sale (250) - refund (50) = 350');
    $assert(count($yearMonth['points']) === 1, 'Jan 1 must have exactly 1 daily bucket for month');
    $assert($yearMonth['total'] === 200.00, 'Month total must only include Jan 1 sale (250) - refund (50) = 200');

    // 3. First and last month days
    StoreOperationsDashboardWorkspace::invalidateCache();
    $lastDayClock = new StoreDashboardContractClock(new DateTimeImmutable('2026-10-31 22:00:00', new DateTimeZone('Asia/Manila')));
    $lastDayWs = new StoreOperationsDashboardWorkspace($pdo, $lastDayClock, new RoleCapabilityPolicy());
    $lastDayView = $lastDayWs->load('admin', 30);
    $assert(count($lastDayView['net_sales']['periods']['month']['points']) === 31, 'October 31 must have 31 daily buckets');

    // 4. Leap February: 2028-02-29
    StoreOperationsDashboardWorkspace::invalidateCache();
    $leapClock = new StoreDashboardContractClock(new DateTimeImmutable('2028-02-29 14:00:00', new DateTimeZone('Asia/Manila')));
    $leapWs = new StoreOperationsDashboardWorkspace($pdo, $leapClock, new RoleCapabilityPolicy());
    $leapView = $leapWs->load('admin', 30);
    $assert(count($leapView['net_sales']['periods']['month']['points']) === 29, 'Leap February 29 must have 29 daily buckets');

    // 5. Negative daily interval
    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo->exec("INSERT INTO cash_refunds VALUES (401, 302, 1, 2, 75.00, 'cash', 'cancel', NULL, '2028-02-29 10:00:00')");
    $leapNegView = $leapWs->load('admin', 30);
    $assert($leapNegView['net_sales']['periods']['month']['points'][28]['net_sales'] === -75.00, 'Day 29 net sales must be negative (-75.00)');
    $assert($leapNegView['net_sales']['periods']['month']['total'] === -75.00, 'Month total must be negative (-75.00)');

    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo->exec('DROP TABLE sales');
    $error = $workspace->load('admin', 30);
    $assert($error['state'] === 'error', 'Query failures must produce an explicit error state');
    $assert($error['headline'] === [], 'Errors must not masquerade as healthy headline data');
    $assert($error['net_sales'] === null, 'Error state must set net_sales to null, never 0.0');

    try {
        $workspace->load('super_admin', 30);
        $assert(false, 'Backend workspace must deny non-Administrator roles');
    } catch (DomainException) {
        $assert(true, 'Unsupported role was denied');
    }
} catch (Throwable $exception) {
    $failures[] = 'Administrator dashboard workspace contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Administrator dashboard workspace tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Administrator dashboard workspace tests: passed\n";
