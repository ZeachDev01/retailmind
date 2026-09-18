<?php
// Database-backed contract for the Super Administrator control-center workspace.
require_once __DIR__ . '/../bootstrap/app.php';
ini_set('error_log', sys_get_temp_dir() . '/retailmind_dashboard_workspace_test.log');

use App\Attention\Clock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\DashboardWorkspace;
use App\Dashboard\PlatformHealthSource;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

final class DashboardContractClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

final class DashboardContractHealth implements PlatformHealthSource
{
    public function __construct(private array $results = [
        ['name' => 'Database', 'status' => 'critical', 'detail' => 'Unavailable', 'category' => 'Core'],
        ['name' => 'Forecast API', 'status' => 'warning', 'detail' => 'Model unavailable', 'category' => 'Forecasting'],
    ]) {
    }

    public function checks(): array
    {
        return $this->results;
    }
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ([
        'CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT, updated_by INTEGER, updated_at TEXT, PRIMARY KEY (setting_scope, setting_key))',
        'CREATE TABLE login_attempts (was_successful INTEGER, attempted_at TEXT)',
        'CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT)',
        'CREATE TABLE users (user_id INTEGER PRIMARY KEY, role_id INTEGER, status TEXT, locked_until TEXT, is_recovery_account INTEGER)',
        'CREATE TABLE backup_history (backup_type TEXT, status TEXT, created_at TEXT)',
        'CREATE TABLE model_training_runs (status TEXT, started_at TEXT, completed_at TEXT, metrics_json TEXT)',
        'CREATE TABLE activity_log (category TEXT, created_at TEXT)',
        'CREATE TABLE emergency_access_sessions (status TEXT, expires_at TEXT)',
        'CREATE TABLE recovery_accounts (user_id INTEGER, sealed_at TEXT, last_used_at TEXT)',
        'CREATE TABLE sales (sale_date TEXT)',
        'CREATE TABLE cashier_shifts (status TEXT, reviewed_at TEXT, cash_variance REAL)',
        'CREATE TABLE products (product_id INTEGER PRIMARY KEY, status TEXT)',
        'CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER)',
        'CREATE TABLE sale_reversals (status TEXT)',
        'CREATE TABLE inventory_adjustments (status TEXT)',
    ] as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO roles VALUES (1, 'super_admin'), (2, 'admin'), (3, 'cashier')");
    $pdo->exec("INSERT INTO users VALUES
        (1, 1, 'active', '2026-09-18 11:00:00', 0),
        (2, 1, 'active', NULL, 1),
        (3, 2, 'active', NULL, 0),
        (4, 3, 'active', NULL, 0)");
    $pdo->exec("INSERT INTO login_attempts VALUES (0, '2026-09-18 09:00:00'), (0, '2026-09-18 09:01:00'), (0, '2026-09-18 09:02:00'), (0, '2026-09-18 09:03:00'), (0, '2026-09-18 09:04:00'), (0, '2026-09-18 09:05:00')");
    $pdo->exec("INSERT INTO backup_history VALUES ('scheduled', 'completed', '2026-09-08 08:00:00'), ('scheduled', 'failed', '2026-09-18 09:30:00')");
    $pdo->exec("INSERT INTO model_training_runs VALUES ('completed', '2026-09-01 08:00:00', '2026-09-01 08:30:00', '{\"accuracy\":0.72}')");
    $pdo->exec("INSERT INTO activity_log VALUES ('platform_setting', '2026-09-18 08:00:00')");
    $pdo->exec("INSERT INTO emergency_access_sessions VALUES ('active', '2026-09-18 10:30:00')");
    $pdo->exec("INSERT INTO recovery_accounts VALUES (2, NULL, '2026-09-10 07:00:00')");
    $pdo->exec("INSERT INTO sales VALUES ('2026-09-18 09:45:00')");
    $pdo->exec("INSERT INTO cashier_shifts VALUES ('open', NULL, NULL)");
    $pdo->exec("INSERT INTO products VALUES (10, 'active')");
    $pdo->exec("INSERT INTO inventory VALUES (10, 0)");
    $pdo->exec("INSERT INTO sale_reversals VALUES ('pending')");
    $pdo->exec("INSERT INTO inventory_adjustments VALUES ('pending')");

    $clock = new DashboardContractClock(new DateTimeImmutable('2026-09-18 10:00:00'));
    $workspace = new DashboardWorkspace($pdo, $clock, new DashboardContractHealth(), new RoleCapabilityPolicy());
    $view = $workspace->load('super_admin', 30);

    $assert($view['role'] === 'super_admin', 'Workspace must identify the actor role');
    $assert($view['state'] === 'ready', 'Seeded workspace should load successfully');
    $assert($view['attention'][0]['severity'] === 'critical', 'Actionable conditions must be severity ordered');
    $assert(($view['headline']['critical_attention']['value'] ?? 0) >= 1, 'Headline must count critical attention');
    $assert(($view['headline']['latest_backup']['status'] ?? '') === 'critical', 'Stale latest successful backup must be critical');
    $assert(($view['headline']['platform_health']['status'] ?? '') === 'critical', 'Platform health must reflect critical services');
    $assert(($view['headline']['ml_health']['status'] ?? '') === 'warning', 'ML health must be independently visible');
    $assert(($view['headline']['privileged_accounts']['value'] ?? 0) >= 1, 'Privileged-account anomalies must be visible');
    $assert(($view['access']['emergency']['status'] ?? '') === 'active', 'Emergency Access status must be visible');
    $assert(($view['access']['recovery']['status'] ?? '') === 'unsealed', 'Recovery Account seal status must be visible');
    $assert(!array_key_exists('activation_secret_hash', $view['access']['recovery']), 'Recovery Account credentials must never be returned');
    $assert($view['continuity']['sales_flowing']['ok'] === true, 'Store continuity must show whether sales are flowing');
    $assert($view['continuity']['active_shifts']['value'] === 1, 'Store continuity must show active shifts');
    $assert($view['continuity']['severe_inventory_disruption']['ok'] === false, 'Store continuity must expose severe inventory disruption');
    $assert($view['continuity']['critical_controls']['ok'] === false, 'Store continuity must expose unresolved critical controls');
    $assert(isset($view['freshness']['generated_at'], $view['freshness']['stale_after']), 'Workspace must expose refresh freshness metadata');
    $assert(!isset($view['recent_sales'], $view['revenue'], $view['inventory_value']), 'Forbidden routine and valuation data must be absent');

    $destinations = array_column($view['actions'], 'destination');
    foreach ($view['attention'] as $item) {
        $assert(str_starts_with($item['destination'], 'components/'), 'Attention actions must use permitted component destinations');
    }
    $assert(!in_array('components/inventory_management/inventory_adjustments.php', $destinations, true), 'Routine stock actions must be absent');

    $pdo->exec("DELETE FROM login_attempts; DELETE FROM backup_history; DELETE FROM model_training_runs; DELETE FROM activity_log; DELETE FROM emergency_access_sessions; DELETE FROM recovery_accounts; DELETE FROM sales; DELETE FROM cashier_shifts; DELETE FROM inventory; DELETE FROM sale_reversals; DELETE FROM inventory_adjustments; UPDATE users SET locked_until = NULL");
    $pdo->exec("INSERT INTO backup_history VALUES ('scheduled', 'completed', '2026-09-18 09:00:00')");
    $pdo->exec("INSERT INTO model_training_runs VALUES ('completed', '2026-09-18 09:00:00', '2026-09-18 09:30:00', '{\"accuracy\":0.95}')");
    $healthy = new DashboardContractHealth([
        ['name' => 'Database', 'status' => 'healthy', 'detail' => 'Available', 'category' => 'Core'],
        ['name' => 'Forecast API', 'status' => 'healthy', 'detail' => 'Available', 'category' => 'Forecasting'],
    ]);
    $workspace = new DashboardWorkspace($pdo, $clock, $healthy, new RoleCapabilityPolicy());
    $empty = $workspace->load('super_admin', 30);
    $assert($empty['state'] === 'empty', 'A workspace without actionable conditions must expose an empty state');
    $assert($empty['attention'] === [], 'Resolved conditions must disappear from the workspace');

    $pdo->exec('DROP TABLE attention_settings');
    $error = $workspace->load('super_admin', 30);
    $assert($error['state'] === 'error', 'Query failures must return an explicit error state');
    $assert($error['headline'] === [], 'Errors must not be presented as healthy headline data');

    try {
        $workspace->load('admin', 30);
        $assert(false, 'This slice must reject unsupported role workspaces');
    } catch (DomainException $exception) {
        $assert(true, 'Unsupported role was rejected');
    }
} catch (Throwable $exception) {
    $failures[] = 'Dashboard workspace contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Super Administrator dashboard workspace tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Super Administrator dashboard workspace tests: passed\n";
