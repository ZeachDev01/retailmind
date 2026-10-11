<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';
require_once __DIR__ . '/../app/Store/ScheduledDemandDriverService.php';

// Render shipped pages with isolated data; auth, theme, and sidebar are separate contracts.
function require_capability(string $capability): void {}
function has_capability(string $capability): bool { return false; }
function retailmind_theme_head(): void {}
function app_url(string $path): string { return '/' . $path; }
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT,
    barcode TEXT, category_id INTEGER, unit_price REAL);
    CREATE TABLE inventory (inventory_id INTEGER PRIMARY KEY, product_id INTEGER UNIQUE, quantity_on_hand INTEGER NOT NULL);
    CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT,
        operator_message TEXT, queued_at TEXT, completed_at TEXT);
    CREATE TABLE demand_forecasts (job_id INTEGER, product_id INTEGER, forecast_origin TEXT,
        target_date TEXT, horizon_day INTEGER, predicted_units INTEGER, model_version TEXT,
        readiness_level TEXT, limitation_marker TEXT);
    CREATE TABLE platform_settings (setting_key TEXT, setting_value TEXT);
    CREATE TABLE scheduled_demand_drivers (product_id INTEGER, effective_date TEXT,
        driver_type TEXT, details_json TEXT, scheduled_at TEXT);
    INSERT INTO products VALUES (1, 'Known stock', 'STOCK-37', '111', 1, 10),
        (2, 'Missing inventory', 'STOCK-0', '222', 1, 10);
    INSERT INTO inventory VALUES (1, 1, 37);
    INSERT INTO ml_jobs VALUES (10, 'forecast_generation', 'succeeded', NULL,
        '2026-10-09 10:00:00', '2026-10-09 10:01:00')");
$insert = $pdo->prepare('INSERT INTO demand_forecasts VALUES (10, ?, ?, ?, ?, 2, ?, ?, NULL)');
foreach ([1, 2] as $productId) {
    for ($day = 1; $day <= 28; $day++) {
        $insert->execute([$productId, '2026-10-09', date('Y-m-d', strtotime("2026-10-09 +{$day} days")), $day, 'v1', 'High']);
    }
}

function renderForecastPage(PDO $pdo, string $path, array $query = []): string
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $source = file_get_contents(__DIR__ . '/../../frontend/components/' . $path);
    $source = preg_replace('/^require_once .*;\R/m', '', $source);
    $source = str_replace("<?php include __DIR__ . '/../sidebar.php'; ?>", '', $source);
    ob_start();
    try {
        eval('?>' . $source);
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

$overview = renderForecastPage($pdo, 'report/predictions.php');
foreach (['STOCK-37' => 37, 'STOCK-0' => 0] as $sku => $stock) {
    if (!preg_match('~<td><code>' . $sku . '</code></td>\s*<td>' . $stock . '</td>\s*<td>14</td>\s*<td>28</td>\s*<td>56</td>~', $overview)) {
        throw new RuntimeException("Overview must display {$sku} stock {$stock} alongside persisted forecast totals");
    }
}
foreach ([1 => 37, 2 => 0] as $productId => $stock) {
    $investigation = renderForecastPage($pdo, 'inventory_management/product_investigation.php', ['product_id' => $productId]);
    if (!str_contains($investigation, 'Current Stock: ' . $stock . '</p>')
        || !str_contains($investigation, 'Day 28')
        || !str_contains($investigation, '<td><strong>56</strong></td>')
        || str_contains($investigation, 'Unable to provide forecast data')) {
        throw new RuntimeException("Investigation must display product {$productId} stock {$stock} and persisted forecasts");
    }
}
echo "Forecast page stock contract: passed\n";
