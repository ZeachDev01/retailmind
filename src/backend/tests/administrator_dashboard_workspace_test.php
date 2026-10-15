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

    $clock = new StoreDashboardContractClock(new DateTimeImmutable('2026-09-18 10:00:00'));
    $workspace = new StoreOperationsDashboardWorkspace($pdo, $clock, new RoleCapabilityPolicy());
    $view = $workspace->load('admin', 30);

    $assert($view['role'] === 'admin', 'Workspace must identify the Administrator role');
    $assert($view['range_days'] === 30, 'Workspace must preserve a supported selected range');
    $assert($view['state'] === 'ready', 'Seeded actionable workspace should be ready');
    $assert(($view['headline']['sales_cash_exceptions']['value'] ?? 0) === 3, 'Headline must combine reversal, cash-variance, and unusual-discount exceptions');
    $assert(!isset($view['headline']['pending_approvals']), 'Retired purchasing approvals must not become a second Stock Issue count');
    $assert($view['headline']['inventory_risks']['label'] === 'Pending Stock Issues', 'Inventory headline must describe retained Stock Issue oversight');
    $actions = array_column($view['actions'], null, 'label');
    $assert(isset($actions['Stock Issue Oversight']), 'Administrator must retain read-only Stock Issue oversight');
    $assert(!isset($actions['Store Approvals']), 'Stock Issues must not be presented as Administrator approvals');
    $assert(($view['headline']['inventory_risks']['value'] ?? 0) === 1, 'Headline must show escalated inventory risks');
    $assert(($view['headline']['fiscal_period']['value'] ?? '') === 'September 2026', 'Headline must show the current Fiscal Period');
    $assert(($view['comparison']['sales']['current'] ?? 0) === 150.0, 'Comparison must total the selected period');
    $assert(($view['comparison']['sales']['previous'] ?? 0) === 80.0, 'Comparison must use the immediately preceding equivalent period');
    $assert(count($view['exceptions']) >= 4, 'Action queue must contain operational exceptions instead of ordinary transactions');
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

    $pdo->exec("DELETE FROM sale_reversals; DELETE FROM cashier_shifts; DELETE FROM inventory_adjustments; DELETE FROM sales; DELETE FROM demand_forecasts; DELETE FROM ml_jobs; DELETE FROM ml_model_evaluations");
    $empty = $workspace->load('admin', 7);
    $assert($empty['state'] === 'empty', 'No actionable conditions must produce an explicit empty state');
    $assert($empty['exceptions'] === [], 'Empty state must have no exception rows');

    $pdo->exec('DROP TABLE sales');
    $error = $workspace->load('admin', 30);
    $assert($error['state'] === 'error', 'Query failures must produce an explicit error state');
    $assert($error['headline'] === [], 'Errors must not masquerade as healthy headline data');

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
