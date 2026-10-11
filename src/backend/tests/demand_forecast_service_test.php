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
