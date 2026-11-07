<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';
require_once __DIR__ . '/../app/Forecasting/StoreModelGovernanceService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Forecasting\DemandForecastService;
use App\Forecasting\ReplenishmentQueueService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\StoreModelGovernanceService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

function createTestDatabase(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE ml_jobs (
            job_id INTEGER PRIMARY KEY AUTOINCREMENT,
            trigger_type TEXT NOT NULL,
            status TEXT NOT NULL,
            queued_at DATETIME NOT NULL,
            completed_at DATETIME
        );

        CREATE TABLE platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            updated_by INTEGER DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE model_training_runs (
            training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_name TEXT,
            model_version TEXT NOT NULL,
            status TEXT NOT NULL,
            metrics_json TEXT
        );

        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT,
            product_name TEXT,
            status TEXT DEFAULT 'active',
            supplier_lead_time_days INTEGER DEFAULT 7,
            minimum_order_quantity INTEGER DEFAULT 1,
            reorder_level INTEGER DEFAULT 10,
            safety_stock INTEGER DEFAULT 5,
            units_per_package INTEGER DEFAULT 1
        );

        CREATE TABLE inventory (
            inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            quantity_on_hand INTEGER DEFAULT 0
        );

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
        );
    ");

    return $pdo;
}

try {
    // -------------------------------------------------------------
    // Slice 1: Transactional Persistence & Rollback of Partial Writes
    // -------------------------------------------------------------
    $pdo = createTestDatabase();
    $service = new DemandForecastService($pdo);

    $pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at) VALUES (10, 'forecast_generation', 'running', '2026-10-09 00:00:00')");

    // Persist 28 days for Product 1
    $recordsP1 = [];
    $origin = '2026-10-09';
    for ($d = 1; $d <= 28; $d++) {
        $recordsP1[] = [
            'product_id' => 1,
            'forecast_origin' => $origin,
            'target_date' => date('Y-m-d', strtotime("$origin +$d days")),
            'horizon_day' => $d,
            'predicted_units' => 3,
            'model_version' => 'v_approved',
            'readiness_level' => 'High',
            'limitation_marker' => 'Operational forecast',
        ];
    }
    $service->persistForecasts(10, $recordsP1);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM demand_forecasts WHERE job_id = 10")->fetchColumn();
    $assert($count === 28, "Expected 28 rows persisted for job 10, got {$count}");

    // Test rejection & rollback on partial rows (only 20 days)
    $incompleteRecords = [];
    for ($d = 1; $d <= 20; $d++) {
        $incompleteRecords[] = [
            'product_id' => 2,
            'forecast_origin' => $origin,
            'target_date' => date('Y-m-d', strtotime("$origin +$d days")),
            'horizon_day' => $d,
            'predicted_units' => 2,
            'model_version' => 'v_approved',
            'readiness_level' => 'High',
            'limitation_marker' => 'Incomplete run',
        ];
    }
    $threw = false;
    try {
        $service->persistForecasts(10, $incompleteRecords);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    $assert($threw, "persistForecasts must throw InvalidArgumentException when given < 28 days");
    $p2Count = (int)$pdo->query("SELECT COUNT(*) FROM demand_forecasts WHERE product_id = 2")->fetchColumn();
    $assert($p2Count === 0, "Partial write must roll back completely (0 rows for product 2, got {$p2Count})");

    // Test rejection & rollback on negative predicted units
    $negativeRecords = [];
    for ($d = 1; $d <= 28; $d++) {
        $negativeRecords[] = [
            'product_id' => 2,
            'forecast_origin' => $origin,
            'target_date' => date('Y-m-d', strtotime("$origin +$d days")),
            'horizon_day' => $d,
            'predicted_units' => $d === 15 ? -5 : 2,
            'model_version' => 'v_approved',
            'readiness_level' => 'High',
            'limitation_marker' => 'Negative unit test',
        ];
    }
    $threw = false;
    try {
        $service->persistForecasts(10, $negativeRecords);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    $assert($threw, "persistForecasts must throw when predicted_units < 0");
    $p2CountAfterNeg = (int)$pdo->query("SELECT COUNT(*) FROM demand_forecasts WHERE product_id = 2")->fetchColumn();
    $assert($p2CountAfterNeg === 0, "Negative unit records must not be written (0 rows, got {$p2CountAfterNeg})");

    // Test database failure during insert rolls back entire transaction
    $pdo->exec("
        CREATE TRIGGER fail_midway BEFORE INSERT ON demand_forecasts
        WHEN NEW.job_id = 12 AND NEW.horizon_day = 14
        BEGIN
            SELECT RAISE(ABORT, 'Simulated mid-commit failure');
        END;
    ");
    $pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at) VALUES (12, 'forecast_generation', 'running', '2026-10-09')");
    $failingRecords = [];
    for ($d = 1; $d <= 28; $d++) {
        $failingRecords[] = [
            'product_id' => 3,
            'forecast_origin' => $origin,
            'target_date' => date('Y-m-d', strtotime("$origin +$d days")),
            'horizon_day' => $d,
            'predicted_units' => 4,
            'model_version' => 'v_approved',
            'readiness_level' => 'High',
            'limitation_marker' => 'Commit failure test',
        ];
    }
    $threw = false;
    try {
        $service->persistForecasts(12, $failingRecords);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $assert($threw, "Mid-commit failure must raise exception");
    $job12Count = (int)$pdo->query("SELECT COUNT(*) FROM demand_forecasts WHERE job_id = 12")->fetchColumn();
    $assert($job12Count === 0, "Mid-commit failure must roll back all rows for job (got {$job12Count})");
    $pdo->exec("DROP TRIGGER fail_midway");

    // -------------------------------------------------------------
    // Slice 2: Deriving Totals & Excluding Incomplete Daily Rows
    // -------------------------------------------------------------
    $pdo->exec("UPDATE ml_jobs SET status = 'succeeded', completed_at = '2026-10-09 01:00:00' WHERE job_id = 10");
    // Manually insert partial rows for product 99 (only 10 rows)
    $manualStmt = $pdo->prepare("
        INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level, limitation_marker)
        VALUES (10, 99, '2026-10-09', ?, ?, 5, 'v_approved', 'High', 'Partial leakage attempt')
    ");
    for ($d = 1; $d <= 10; $d++) {
        $manualStmt->execute([date('Y-m-d', strtotime("2026-10-09 +$d days")), $d]);
    }

    $aggs = $service->getForecastAggregates(10);
    $assert(count($aggs) === 1, "Aggregates must strictly exclude product 99 with partial 10 rows, got " . count($aggs));
    $assert($aggs[0]['product_id'] === 1, "Only complete Product 1 must appear in aggregates");
    $assert($aggs[0]['forecast_7_day'] === 21, "7-day total must be 7*3 = 21, got {$aggs[0]['forecast_7_day']}");
    $assert($aggs[0]['forecast_14_day'] === 42, "14-day total must be 14*3 = 42, got {$aggs[0]['forecast_14_day']}");
    $assert($aggs[0]['forecast_28_day'] === 84, "28-day total must be 28*3 = 84, got {$aggs[0]['forecast_28_day']}");

    // getProductForecast for partial product must return null aggregates and empty daily
    $prod99Forecast = $service->getProductForecast(10, 99);
    $assert($prod99Forecast['aggregates'] === null, "Partial product aggregates must be null");
    $assert($prod99Forecast['daily'] === [], "Partial product daily rows must be empty");

    // -------------------------------------------------------------
    // Slice 3: Unified Eligibility & Fallbacks for Purchasing Output
    // -------------------------------------------------------------
    // Setup products & approved model
    $pdo->exec("
        DELETE FROM products;
        DELETE FROM inventory;
        DELETE FROM demand_forecasts;
        DELETE FROM ml_jobs;

        INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_approved_model_version', 'model-v1');

        INSERT INTO products (product_id, sku, product_name, status, reorder_level, safety_stock, minimum_order_quantity, units_per_package)
        VALUES 
            (1, 'SKU-FRESH',       'Fresh Product',        'active', 10, 5, 6, 4),
            (2, 'SKU-STALE',       'Stale Product',        'active', 10, 5, 6, 4),
            (3, 'SKU-INCOMPLETE',  'Incomplete Product',   'active', 10, 5, 6, 4),
            (4, 'SKU-UNAPPROVED',  'Unapproved Model Prod','active', 10, 5, 6, 4),
            (5, 'SKU-INSUFFICIENT','Insufficient Read Prod','active', 10, 5, 6, 4),
            (6, 'SKU-MISSING',     'Missing Forecast Prod','active', 10, 5, 6, 4);

        INSERT INTO inventory (product_id, quantity_on_hand)
        VALUES (1, 0), (2, 0), (3, 0), (4, 0), (5, 0), (6, 0);

        INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at)
        VALUES (20, 'forecast_generation', 'succeeded', '2026-10-09 00:00:00', '2026-10-09 01:00:00');
    ");

    $asOfDate = '2026-10-10'; // Manila today. Cutoff (origin) = 2026-10-09.
    $insertHelper = function (int $pid, string $originDate, int $daysCount, string $modelVersion, string $readiness, int $unitsPerDay = 2) use ($pdo) {
        $stmt = $pdo->prepare("
            INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level, limitation_marker)
            VALUES (20, ?, ?, ?, ?, ?, ?, ?, 'Standard limitation')
        ");
        for ($d = 1; $d <= $daysCount; $d++) {
            $stmt->execute([$pid, $originDate, date('Y-m-d', strtotime("$originDate +$d days")), $d, $unitsPerDay, $modelVersion, $readiness]);
        }
    };

    // Product 1: Fresh, complete, approved, High readiness
    $insertHelper(1, '2026-10-09', 28, 'model-v1', 'High', 2);
    // Product 2: Stale origin (2026-10-05)
    $insertHelper(2, '2026-10-05', 28, 'model-v1', 'High', 2);
    // Product 3: Incomplete (only 25 days)
    $insertHelper(3, '2026-10-09', 25, 'model-v1', 'High', 2);
    // Product 4: Unapproved model (model-unapproved)
    $insertHelper(4, '2026-10-09', 28, 'model-unapproved', 'High', 2);
    // Product 5: Insufficient readiness
    $insertHelper(5, '2026-10-09', 28, 'model-v1', 'Insufficient', 2);
    // Product 6: No forecast rows inserted

    $queueService = new ReplenishmentQueueService($pdo);
    $recs = array_column($queueService->getRecommendations(20, $asOfDate), null, 'product_id');

    // Verify Product 1 (Eligible): Uses demand forecast guidance
    $assert($recs[1]['basis'] === 'Demand Forecast', "P1 basis must be Demand Forecast");
    $assert($recs[1]['expected_sales_28_day'] === 56, "P1 expected sales must be 56, got " . var_export($recs[1]['expected_sales_28_day'], true));
    $assert($recs[1]['expected_sales_period'] === '2026-10-10 – 2026-11-06', "P1 sales period must be current 28 days");
    $assert($recs[1]['stock_rule_quantity'] === 16, "P1 stock rule quantity must be 16 (reorder 10 + safety 5 - stock 0 = 15, rounded to MOQ 6 and package 4 = 16)");
    $assert($recs[1]['recommended_quantity'] !== $recs[1]['stock_rule_quantity'], "P1 recommendation should use forecast recommendation");
    $assert(str_contains($recs[1]['forecast_status'], 'High readiness forecast'), "P1 status must show High readiness");

    // Ineligible Products 2..6: All must fall back to labelled stock-rule recommendations
    $expectedFallbackQty = 16; // (10 + 5) rounded to MOQ 6, pack 4 = 16
    foreach ([2, 3, 4, 5, 6] as $pid) {
        $p = $recs[$pid];
        $assert($p['basis'] === 'Stock-rule suggestion', "P{$pid} basis must fall back to Stock-rule suggestion");
        $assert($p['recommended_quantity'] === $expectedFallbackQty, "P{$pid} recommended quantity must be {$expectedFallbackQty}, got {$p['recommended_quantity']}");
        $assert($p['expected_sales_28_day'] === null, "P{$pid} expected sales must be null, got " . var_export($p['expected_sales_28_day'], true));
        $assert($p['expected_sales_period'] === null, "P{$pid} expected sales period must be null");
        $assert($p['urgency_label'] === 'Unknown', "P{$pid} urgency must be Unknown");
        $assert($p['stock_exposure'] === null, "P{$pid} stock exposure must be null");
        $assert($p['job_id'] === 20, "P{$pid} must retain run job_id 20");
    }

    // Check specific labels
    $assert(str_contains($recs[2]['forecast_status'], 'Stale forecast: 2026-10-05'), "P2 must label stale origin: {$recs[2]['forecast_status']}");
    $assert(str_contains($recs[3]['forecast_status'], 'Incomplete forecast'), "P3 must label incomplete: {$recs[3]['forecast_status']}");
    $assert(str_contains($recs[4]['forecast_status'], 'Unapproved model: model-unapproved'), "P4 must label unapproved: {$recs[4]['forecast_status']}");
    $assert(str_contains($recs[5]['forecast_status'], 'Insufficient readiness forecast'), "P5 must label insufficient readiness: {$recs[5]['forecast_status']}");
    $assert($recs[6]['forecast_status'] === 'Forecast unavailable', "P6 must label unavailable: {$recs[6]['forecast_status']}");

    // Test Ineligible Case: Superseded Run
    // Insert a newer successful job 21
    $pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at) VALUES (21, 'forecast_generation', 'succeeded', '2026-10-10', '2026-10-10')");
    $supersededRecs = array_column($queueService->getRecommendations(20, $asOfDate), null, 'product_id');
    $assert($supersededRecs[1]['basis'] === 'Stock-rule suggestion', "Superseded job 20 P1 must fall back to stock rule");
    $assert($supersededRecs[1]['expected_sales_28_day'] === null, "Superseded job 20 P1 expected sales must be null");
    $assert(str_contains($supersededRecs[1]['forecast_status'], 'Superseded forecast: run #20'), "Superseded job status must label superseded: {$supersededRecs[1]['forecast_status']}");
    $pdo->exec("DELETE FROM ml_jobs WHERE job_id = 21");

    // Test Ineligible Case: Incompatible Model (Demo model on Store DB)
    $pdo->exec("
        INSERT INTO model_training_runs (model_name, model_version, status, metrics_json)
        VALUES ('Random Forest', 'model-v1', 'completed', '{\"metadata\":{\"dataset_kind\":\"demo\",\"feature_version\":\"2.0.0\"}}')
    ");
    $incompatRecs = array_column($queueService->getRecommendations(20, $asOfDate), null, 'product_id');
    $assert($incompatRecs[1]['basis'] === 'Stock-rule suggestion', "Incompatible model P1 must fall back to stock rule");
    $assert($incompatRecs[1]['expected_sales_28_day'] === null, "Incompatible model P1 expected sales must be null");
    $assert(str_contains($incompatRecs[1]['forecast_status'], 'Incompatible model'), "Incompatible model status must be labelled: {$incompatRecs[1]['forecast_status']}");

    // Test Ineligible Case: Obsolete feature schema
    $pdo->exec("
        UPDATE model_training_runs 
        SET metrics_json = '{\"metadata\":{\"dataset_kind\":\"store\",\"feature_version\":\"1.0.0\"}}'
        WHERE model_version = 'model-v1'
    ");
    $schemaRecs = array_column($queueService->getRecommendations(20, $asOfDate), null, 'product_id');
    $assert($schemaRecs[1]['basis'] === 'Stock-rule suggestion', "Obsolete schema P1 must fall back to stock rule");
    $assert(str_contains($schemaRecs[1]['forecast_status'], 'Incompatible model: Obsolete feature schema 1.0.0'), "Obsolete schema status must be labelled");

    // Restore Store training metadata with current feature version
    $pdo->exec("
        UPDATE model_training_runs 
        SET metrics_json = '{\"metadata\":{\"dataset_kind\":\"store\",\"feature_version\":\"2.0.0\"}}'
        WHERE model_version = 'model-v1'
    ");

    // Test Ineligible Case: Observation Mode
    $governance = new StoreModelGovernanceService($pdo);
    $governance->setObservationMode(true, 1);
    $obsRecs = array_column($queueService->getRecommendations(20, $asOfDate), null, 'product_id');
    $assert($obsRecs[1]['basis'] === 'Stock-rule suggestion', "Observation mode must fall back to stock rule");
    $assert($obsRecs[1]['expected_sales_28_day'] === null, "Observation mode expected sales must be null");
    $assert(str_contains($obsRecs[1]['forecast_status'], 'Observation Mode: forecast is observation telemetry only'), "Observation mode label must be present");
    $governance->setObservationMode(false, 1);

    // Test Ineligible Case: Recommendation Suspension
    $governance->setRecommendationsSuspended(true, 1);
    $suspRecs = array_column($queueService->getRecommendations(20, $asOfDate), null, 'product_id');
    $assert($suspRecs[1]['basis'] === 'Stock-rule suggestion', "Suspension must fall back to stock rule");
    $assert($suspRecs[1]['expected_sales_28_day'] === null, "Suspension expected sales must be null");
    $assert(str_contains($suspRecs[1]['forecast_status'], 'Recommendations Suspended by Administrator'), "Suspension label must be present");
    $governance->setRecommendationsSuspended(false, 1);

    // Test Ineligible Case: Failed Job
    $pdo->exec("UPDATE ml_jobs SET status = 'failed' WHERE job_id = 20");
    $failedRecs = array_column($queueService->getRecommendations(20, $asOfDate), null, 'product_id');
    $assert($failedRecs[1]['basis'] === 'Stock-rule suggestion', "Failed job must fall back to stock rule");
    $assert($failedRecs[1]['expected_sales_28_day'] === null, "Failed job expected sales must be null");
    $assert(str_contains($failedRecs[1]['forecast_status'], 'Failed forecast: run #20'), "Failed job label must be present: {$failedRecs[1]['forecast_status']}");
    $pdo->exec("UPDATE ml_jobs SET status = 'succeeded' WHERE job_id = 20");

    // -------------------------------------------------------------
    // Slice 4: Historical output retained & Stock safeguarded
    // -------------------------------------------------------------
    // Forecast rows must remain in database (not deleted or corrupted by fallback checks)
    $forecastCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM demand_forecasts WHERE job_id = 20")->fetchColumn();
    $assert($forecastCountAfter === (28 + 28 + 25 + 28 + 28), "Historical forecast rows must be preserved, got {$forecastCountAfter}");

    // Inventory must remain untouched (no purchase orders or stock mutations)
    $stockSum = (int)$pdo->query("SELECT SUM(quantity_on_hand) FROM inventory")->fetchColumn();
    $assert($stockSum === 0, "Inventory must remain strictly untouched");

    if (empty($failures)) {
        echo "PASS: demand_forecast_integration_test\n";
    } else {
        echo "FAIL: demand_forecast_integration_test\n" . implode("\n", $failures) . "\n";
        exit(1);
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
