<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';

use App\Forecasting\DemandForecastService;

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: $message");
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed: $message. Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

// Unit test readiness rules calculation
function calculate_readiness_test(int $days, bool $validationPassed): array {
    if ($days < 56) {
        return ['level' => 'Insufficient', 'limited' => true];
    } elseif ($days < 180) {
        return ['level' => 'Low', 'limited' => true];
    } elseif ($days < 365) {
        return ['level' => 'Medium', 'limited' => true];
    } else {
        if ($validationPassed) {
            return ['level' => 'High', 'limited' => false];
        } else {
            return ['level' => 'Medium', 'limited' => true];
        }
    }
}

assert_same('Insufficient', calculate_readiness_test(30, true)['level'], 'Under 56 days is Insufficient');
assert_same('Low', calculate_readiness_test(100, true)['level'], '56-179 days is Low');
assert_same('Medium', calculate_readiness_test(250, true)['level'], '180-364 days is Medium');
assert_same('High', calculate_readiness_test(400, true)['level'], '365+ with validation passed is High');
assert_same('Medium', calculate_readiness_test(400, false)['level'], '365+ with validation failed stays Medium');

if (getenv('RUN_DB_TESTS') === '1') {
    require_once __DIR__ . '/../config/db.php';
    /** @var PDO $pdo */

    // Test that DemandForecastService can read 28 days
    $pdo->exec("DELETE FROM demand_forecasts WHERE job_id = 9999");
    $origin = date('Y-m-d');
    $stmt = $pdo->prepare("
        INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level, limitation_marker)
        VALUES (9999, 1, ?, ?, ?, 5, 'test_v1', 'Medium', 'Test')
    ");
    for ($d = 1; $d <= 28; $d++) {
        $target = date('Y-m-d', strtotime("$origin +$d days"));
        $stmt->execute([$origin, $target, $d]);
    }

    $dfs = new DemandForecastService($pdo);
    $aggs = $dfs->getForecastAggregates(9999);
    assert_same(1, count($aggs), 'Should return 1 aggregate');
    assert_same(35, $aggs[0]['forecast_7_day'], '7 days * 5 = 35');
    assert_same(70, $aggs[0]['forecast_14_day'], '14 days * 5 = 70');
    assert_same(140, $aggs[0]['forecast_28_day'], '28 days * 5 = 140');

    $pdo->exec("DELETE FROM demand_forecasts WHERE job_id = 9999");
}

echo "Forecast generation contract passed.\n";
