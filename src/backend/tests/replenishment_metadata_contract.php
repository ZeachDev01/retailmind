<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Support/OperatorAlert.php';

function require_role(array $roles): void {}
function current_role(): string { return 'inventory_manager'; }
function has_capability(string $capability): bool { return (bool)($GLOBALS['test_can_receive'] ?? false); }
function retailmind_theme_head(): void {}
function app_url(string $path): string { return '/' . $path; }
function generate_csrf_token(): string { return 'test'; }
function store_product_scope(string $alias): array { return ['', []]; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('GREATEST', static fn ($a, $b) => max($a, $b));
$pdo->exec("CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, completed_at TEXT);
    CREATE TABLE demand_forecasts (forecast_id INTEGER PRIMARY KEY, job_id INTEGER, product_id INTEGER,
        forecast_origin TEXT, horizon_day INTEGER, predicted_units INTEGER, model_version TEXT, readiness_level TEXT);
    CREATE TABLE platform_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE model_training_runs (training_run_id INTEGER PRIMARY KEY, model_version TEXT,
        status TEXT, metrics_json TEXT);
    CREATE TABLE products (product_id INTEGER PRIMARY KEY, sku TEXT, product_name TEXT, status TEXT,
        supplier_lead_time_days INTEGER, minimum_order_quantity INTEGER,
        reorder_level INTEGER, safety_stock INTEGER, units_per_package INTEGER);
    CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER);
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT);

    INSERT INTO products VALUES (1, 'NEEDS-STOCK', 'Needs stock', 'active', 7, 1, 10, 5, 1);
    INSERT INTO inventory VALUES (1, 0);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'newly-promoted-model')");

function renderReplenishmentPage(PDO $pdo, string $name): string
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $source = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/' . $name . '.php');
    $source = str_replace('declare(strict_types=1);', '', $source);
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    $source = str_replace('Session::start();', '', $source);
    $source = str_replace("<?php include __DIR__ . '/../sidebar.php'; ?>", '', $source);
    $source = str_replace('__DIR__', var_export(realpath(__DIR__ . '/../../frontend/components/inventory_management'), true), $source);
    ob_start();
    try {
        eval('?>' . $source);
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

foreach (['replenishment_queue'] as $page) {
    $empty = renderReplenishmentPage($pdo, $page);
    if (str_contains($empty, 'Demo Mode') || str_contains($empty, 'newly-promoted-model')) {
        throw new RuntimeException("{$page}: empty history must not invent model or Demo metadata");
    }
    if (str_contains($empty, 'Add bought goods') || str_contains($empty, 'Record bought stock')) {
        throw new RuntimeException('Planning comparison must not expose receiving actions');
    }
}

$pdo->exec("INSERT INTO ml_jobs VALUES (10, 'forecast_generation', 'succeeded', '2026-10-09');
    INSERT INTO demand_forecasts VALUES (1, 10, 1, '2026-10-09', 7, 14, 'displayed-run-model', 'High')");
foreach ([false, true, false] as $demo) {
    $pdo->exec("DELETE FROM platform_settings WHERE setting_key = 'demo_import_metadata'");
    if ($demo) {
        $pdo->exec("INSERT INTO platform_settings VALUES ('demo_import_metadata', '{\"store_id\":\"S001\",\"products_mapped\":20}')");
    }
    $pdo->exec('DELETE FROM model_training_runs');
    $pdo->prepare('INSERT INTO model_training_runs VALUES (1, ?, ?, ?)')->execute([
        'displayed-run-model', 'completed', json_encode(['metadata' => ['dataset_kind' => $demo ? 'demo' : 'store']]),
    ]);
    foreach (['replenishment_queue'] as $page) {
        $html = renderReplenishmentPage($pdo, $page);
        $runMetadata = (new App\Forecasting\DemandForecastService($pdo))->getJobMetadata(10);
        if ($runMetadata['model_version'] !== 'displayed-run-model' || str_contains($html, 'newly-promoted-model')
            || str_contains($html, 'Demo: ') !== $demo || !str_contains($html, 'NEEDS-STOCK')
            || !str_contains($html, 'Stock-rule suggestion') || !str_contains($html, 'Expected sales')) {
            throw new RuntimeException("{$page}: recommendations and metadata must follow displayed run and persisted provenance");
        }
    }
}
$pdo->exec("INSERT INTO platform_settings VALUES ('demo_import_metadata', '{}')");
$storeMetadata = (new App\Forecasting\DemandForecastService($pdo))->getJobMetadata(10);
if ($storeMetadata['is_demo']) {
    throw new RuntimeException('Current Demo settings must not relabel persisted Store model');
}
foreach ([null, 'invalid-json'] as $metrics) {
    $pdo->prepare('UPDATE model_training_runs SET metrics_json = ?')->execute([$metrics]);
    $metadata = (new App\Forecasting\DemandForecastService($pdo))->getJobMetadata(10);
    if ($metadata['model_version'] !== 'displayed-run-model' || $metadata['is_demo']) {
        throw new RuntimeException('Missing training provenance must retain forecast model without inventing Demo label');
    }
}
$pdo->exec("UPDATE products SET status='inactive'");
$empty = renderReplenishmentPage($pdo, 'replenishment_queue');
if (!str_contains($empty, 'No active products available') || str_contains($empty, 'Print all recommendations')) {
    throw new RuntimeException('Empty catalog must show an empty state without a print action');
}
$pdo->exec('DROP TABLE inventory');
$unavailable = renderReplenishmentPage($pdo, 'replenishment_queue');
if (!str_contains($unavailable, 'Recommendations could not be loaded')
    || !str_contains($unavailable, '>Reload</a>') || !str_contains($unavailable, 'role="status"')
    || str_contains($unavailable, 'No active products available') || str_contains($unavailable, 'SQLSTATE')) {
    throw new RuntimeException('Loading failure must offer recovery without an empty catalog claim or technical details');
}
echo "Replenishment metadata and error-state contract passed.\n";
