<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';

use App\Forecasting\DemandForecastService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("
        CREATE TABLE ml_jobs (
            job_id INTEGER PRIMARY KEY AUTOINCREMENT,
            trigger_type TEXT NOT NULL,
            status TEXT NOT NULL,
            queued_at DATETIME NOT NULL,
            completed_at DATETIME
        )
    ");
    
    $pdo->exec("
        CREATE TABLE demand_forecasts (
            forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            forecast_origin DATE NOT NULL,
            target_date DATE NOT NULL,
            horizon_day INTEGER NOT NULL,
            predicted_units INTEGER NOT NULL,
            model_version TEXT NOT NULL,
            readiness_level TEXT NOT NULL,
            limitation_marker TEXT
        )
    ");
    
    // Insert a job
    $pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at) VALUES (10, 'forecast_generation', 'succeeded', '2026-10-09 10:00:00')");
    
    // Insert forecasts: 28 days for product 1
    $origin = '2026-10-09';
    $stmt = $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    for ($i = 1; $i <= 28; $i++) {
        $targetDate = date('Y-m-d', strtotime("$origin +$i days"));
        // 2 units per day, so 7 days = 14, 14 days = 28, 28 days = 56
        $stmt->execute([10, 1, $origin, $targetDate, $i, 2, 'v1', 'High']);
    }
    
    $service = new DemandForecastService($pdo);
    $aggregates = $service->getForecastAggregates(10);
    
    $assert(count($aggregates) === 1, "Expected 1 aggregated product forecast, got " . count($aggregates));
    
    $agg = $aggregates[0];
    $assert($agg['product_id'] == 1, "Wrong product");
    $assert($agg['forecast_7_day'] == 14, "Expected 7-day forecast 14, got {$agg['forecast_7_day']}");
    $assert($agg['forecast_14_day'] == 28, "Expected 14-day forecast 28, got {$agg['forecast_14_day']}");
    $assert($agg['forecast_28_day'] == 56, "Expected 28-day forecast 56, got {$agg['forecast_28_day']}");

    $pdo->exec("UPDATE ml_jobs SET trigger_type = 'forecast_observation' WHERE job_id = 10");
    $assert($service->getLatestSuccessfulJobId() === null, 'Observation job must not become routine latest forecast');
    $assert($service->getForecastAggregates(10) === [], 'Explicit observation job ID must not expose routine aggregates');
    $investigation = $service->getProductForecast(10, 1);
    $assert($investigation['aggregates'] === null && $investigation['daily'] === [], 'Product investigation must exclude observation forecasts');

    $pdo->exec("
        CREATE TABLE products (product_id INTEGER, product_name TEXT, sku TEXT, barcode TEXT, category_id INTEGER);
        INSERT INTO products VALUES (1, 'Product', 'P1', NULL, 1);
        CREATE TABLE categories (category_id INTEGER, category_name TEXT);
        CREATE TABLE sales (sale_id INTEGER, sale_date TEXT);
        CREATE TABLE sale_items (sale_id INTEGER, product_id INTEGER, quantity INTEGER,
            selling_form TEXT NOT NULL DEFAULT 'piece', pack_size INTEGER NULL);
        INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at) VALUES (11, 'forecast_generation', 'succeeded', '2026-10-09');
        INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
        VALUES (11, 1, '2026-10-09', '2026-10-10', 1, 7, 'approved', 'High');
    ");
    $pdo->sqliteCreateFunction('CURDATE', fn() => '2026-12-01');
    $pdo->sqliteCreateFunction('NOW', fn() => '2026-12-01');
    $reportRoot = __DIR__ . '/../../frontend/components/report/';
    foreach (['forecast_analytics.php', 'report_generation.php'] as $reportFile) {
        $source = file_get_contents($reportRoot . $reportFile);
        if ($reportFile === 'report_generation.php') {
            $source = substr($source, strpos($source, "case 'forecast_actual':"));
        }
        if (!preg_match('/"\s*(SELECT[^"]*FROM demand_forecasts df[^"]*)"/s', $source, $match)) {
            throw new RuntimeException("Missing forecast report query in $reportFile");
        }
        $sql = str_replace(['DATE_ADD(df.forecast_origin, INTERVAL 28 DAY)', '{$productSql}'],
                           ["DATE(df.forecast_origin, '+28 days')", ''], $match[1]);
        $query = $pdo->prepare($sql);
        $query->execute($reportFile === 'report_generation.php' ? ['2026-01-01', '2026-12-31'] : []);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $assert(count($rows) === 1, "$reportFile must exclude observation forecasts");
        $assert((int)($rows[0]['predicted_units'] ?? $rows[0]['forecast_quantity'] ?? 0) === 7,
                "$reportFile must preserve approved forecast values");
    }
    
    if (empty($failures)) {
        echo "PASS: test_aggregates_forecast_horizons\n";
    } else {
        echo "FAIL:\n" . implode("\n", $failures) . "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
