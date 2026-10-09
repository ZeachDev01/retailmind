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

    $pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at) VALUES (10, 'forecast_generation', 'succeeded', '2026-10-09 10:00:00')");
    
    $origin = '2026-10-09';
    $stmt = $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    for ($i = 1; $i <= 28; $i++) {
        $targetDate = date('Y-m-d', strtotime("$origin +$i days"));
        $stmt->execute([10, 1, $origin, $targetDate, $i, 2, 'v1', 'High']);
    }

    $service = new DemandForecastService($pdo);
    $investigation = $service->getProductForecast(10, 1);
    
    $assert(isset($investigation['aggregates']), "Missing aggregates");
    if (isset($investigation['aggregates'])) {
        $assert($investigation['aggregates']['forecast_7_day'] === 14, "Expected 7-day aggregate 14, got " . ($investigation['aggregates']['forecast_7_day'] ?? 'null'));
        $assert($investigation['aggregates']['forecast_14_day'] === 28, "Expected 14-day aggregate 28");
        $assert($investigation['aggregates']['forecast_28_day'] === 56, "Expected 28-day aggregate 56");
        $assert($investigation['aggregates']['readiness_level'] === 'High', "Expected readiness High");
    }

    $assert(isset($investigation['daily']), "Missing daily forecast");
    if (isset($investigation['daily'])) {
        $assert(count($investigation['daily']) === 28, "Expected 28 daily records, got " . count($investigation['daily']));
        $assert(($investigation['daily'][0]['predicted_units'] ?? null) === 2, "Expected 2 units for day 1");
        $assert(($investigation['daily'][0]['horizon_day'] ?? null) === 1, "Expected horizon_day 1 for day 1");
    }

    if (empty($failures)) {
        echo "PASS: test_product_investigation_contract\n";
    } else {
        echo "FAIL:\n" . implode("\n", $failures) . "\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
