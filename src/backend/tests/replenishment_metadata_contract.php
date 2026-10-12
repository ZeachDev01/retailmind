<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

function require_role(array $roles): void {}
function current_role(): string { return 'inventory_manager'; }
function has_capability(string $capability): bool { return false; }
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
        supplier_lead_time_days INTEGER, minimum_order_quantity INTEGER);
    CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER);
    CREATE TABLE supplier_products (supplier_product_id INTEGER PRIMARY KEY, product_id INTEGER, lead_time_days INTEGER,
        minimum_order_quantity INTEGER, is_preferred INTEGER);
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT);
    CREATE TABLE replenishment_requests (request_id INTEGER, product_id INTEGER, requested_by INTEGER,
        approved_by INTEGER, request_qty INTEGER, status TEXT, request_date TEXT);
    CREATE TABLE stock_receiving (replenishment_request_id INTEGER, accepted_qty INTEGER);
    INSERT INTO products VALUES (1, 'NEEDS-STOCK', 'Needs stock', 'active', 7, 1);
    INSERT INTO inventory VALUES (1, 0);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'newly-promoted-model')");

function renderReplenishmentPage(PDO $pdo, string $name): string
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $source = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/' . $name . '.php');
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    $source = str_replace('Session::start();', '', $source);
    $source = str_replace("<?php include __DIR__ . '/../sidebar.php'; ?>", '', $source);
    ob_start();
    try {
        eval('?>' . $source);
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

foreach (['replenishment_requests', 'replenishment_queue'] as $page) {
    $empty = renderReplenishmentPage($pdo, $page);
    if (str_contains($empty, 'Demo Mode') || str_contains($empty, 'newly-promoted-model')) {
        throw new RuntimeException("{$page}: empty history must not invent model or Demo metadata");
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
    foreach (['replenishment_requests', 'replenishment_queue'] as $page) {
        $html = renderReplenishmentPage($pdo, $page);
        if (!str_contains($html, 'displayed-run-model') || str_contains($html, 'newly-promoted-model')
            || str_contains($html, 'Demo Mode') !== $demo || !str_contains($html, 'NEEDS-STOCK')) {
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
echo "Replenishment metadata contract passed.\n";
