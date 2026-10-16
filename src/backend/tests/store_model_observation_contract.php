<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ModelPromotionService;
use App\Forecasting\ReplenishmentQueueService;
use App\Forecasting\DemandHistoryService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] $message\n";
    } else {
        echo "  [PASS] $message\n";
    }
};

echo "Testing Store Model Observation Mode and Quality Evaluation (Ticket #162)...\n\n";

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE forecast_sales_imports (product_id INT, sale_date DATE, quantity INT, PRIMARY KEY (product_id, sale_date));
        CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT);
        INSERT INTO ml_jobs VALUES (200, 'forecast_generation', 'succeeded');
        CREATE TABLE platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            updated_by INTEGER,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT,
            product_name TEXT,
            status TEXT DEFAULT 'active',
            supplier_lead_time_days INTEGER DEFAULT 7,
            minimum_order_quantity INTEGER DEFAULT 5
        );
        CREATE TABLE inventory (
            inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            quantity_on_hand INTEGER DEFAULT 0
        );
        CREATE TABLE store_calendar (
            calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_date TEXT NOT NULL,
            status TEXT NOT NULL,
            reason TEXT,
            recorded_by INTEGER,
            recorded_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE sales (
            sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER,
            total_amount REAL,
            sale_date TEXT NOT NULL
        );
        CREATE TABLE sale_items (
            sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            product_id INTEGER,
            quantity INTEGER,
            unit_price REAL,
            subtotal REAL
        );
        CREATE TABLE sale_reversals (
            reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            reversal_type TEXT,
            status TEXT,
            reason TEXT,
            approved_at TEXT
        );
        CREATE TABLE sale_reversal_items (
            reversal_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            reversal_id INTEGER,
            sale_item_id INTEGER,
            product_id INTEGER,
            quantity INTEGER
        );
        CREATE TABLE cash_refunds (
            refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE cash_refund_items (
            refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            refund_id INTEGER,
            sale_item_id INTEGER,
            product_id INTEGER,
            quantity INTEGER
        );
        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            change_qty INTEGER,
            reason TEXT,
            moved_at TEXT NOT NULL
        );
        CREATE TABLE demand_forecasts (
            forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER,
            product_id INTEGER,
            horizon_day INTEGER,
            target_date TEXT,
            predicted_units INTEGER,
            forecast_origin TEXT,
            readiness_level TEXT,
            model_version TEXT
        );
        CREATE TABLE ml_model_evaluations (
            evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_version TEXT,
            dataset_type TEXT,
            horizon_days INTEGER,
            mae REAL,
            rmse REAL,
            wape REAL,
            baseline_mae REAL,
            baseline_rmse REAL,
            baseline_wape REAL,
            candidate_gate_outcome TEXT DEFAULT 'pending',
            missing_metric_reason TEXT,
            data_window_start TEXT,
            data_window_end TEXT,
            evaluated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $evalService = new CandidateEvaluationService($pdo);
    $promotionService = new ModelPromotionService($pdo, $evalService);

    // -------------------------------------------------------------
    // Test 1: Store Evidence & Identity Separation
    // -------------------------------------------------------------
    echo "--- Test 1: Store Evidence & Identity Separation ---\n";
    $storeModelVersion = 'rf-store-20261010-candidate';
    $demoModelVersion = 'rf-demo-20261009-candidate';

    // Store candidate evaluation records dataset_type = 'store'
    $pdo->prepare("
        INSERT INTO ml_model_evaluations 
        (model_version, dataset_type, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, data_window_start, data_window_end)
        VALUES 
        (?, 'store', 7, 2.5, 3.4, 0.15, 3.8, 4.9, 0.22, 'passed', '2026-01-01', '2026-09-30'),
        (?, 'store', 14, 3.1, 4.1, 0.18, 4.2, 5.5, 0.25, 'passed', '2026-01-01', '2026-09-30'),
        (?, 'store', 28, 3.8, 4.9, 0.21, 5.0, 6.4, 0.29, 'passed', '2026-01-01', '2026-09-30')
    ")->execute([$storeModelVersion, $storeModelVersion, $storeModelVersion]);

    $evals = $evalService->getEvaluationsByModelVersion($storeModelVersion);
    $assert(count($evals) === 3, "Retrieved 3 horizon evaluations for store candidate");

    // -------------------------------------------------------------
    // Test 2: Quality Gates Configuration & Missing Threshold Blocking
    // -------------------------------------------------------------
    echo "\n--- Test 2: Quality Gates Configuration & Missing Threshold Blocking ---\n";
    // Gate evaluation fails when thresholds are missing
    $gateMissing = $evalService->evaluateCandidateQuality($storeModelVersion);
    $assert(!$gateMissing['passed'], "Candidate blocked when per-horizon quality thresholds are missing");
    $assert(str_contains($gateMissing['reason'], 'Missing quality threshold'), "Failure message specifies missing threshold");

    // Super Administrator configures numerical thresholds
    $superAdminId = 1;
    $thresholds = [
        'ml_max_wape_7d' => 0.20,
        'ml_max_wape_14d' => 0.25,
        'ml_max_wape_28d' => 0.30
    ];
    $evalService->setThresholds($thresholds, $superAdminId);
    $activeThresholds = $evalService->getThresholds();
    $assert($activeThresholds['7'] === 0.20, "7-day WAPE threshold set to 0.20");
    $assert($activeThresholds['14'] === 0.25, "14-day WAPE threshold set to 0.25");
    $assert($activeThresholds['28'] === 0.30, "28-day WAPE threshold set to 0.30");

    // Re-evaluate quality with thresholds set
    $gatePassed = $evalService->evaluateCandidateQuality($storeModelVersion);
    $assert($gatePassed['passed'], "Candidate passes quality gates when WAPE satisfies configured thresholds and beats baseline");

    // Test rejection when candidate WAPE exceeds threshold
    $failingModelVersion = 'rf-store-poor-accuracy';
    $pdo->prepare("
        INSERT INTO ml_model_evaluations 
        (model_version, dataset_type, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome)
        VALUES 
        (?, 'store', 7, 2.5, 3.4, 0.15, 3.8, 4.9, 0.22, 'passed'),
        (?, 'store', 14, 3.1, 4.1, 0.18, 4.2, 5.5, 0.25, 'passed'),
        (?, 'store', 28, 6.5, 8.2, 0.38, 5.0, 6.4, 0.29, 'failed')
    ")->execute([$failingModelVersion, $failingModelVersion, $failingModelVersion]);

    $gateFailed = $evalService->evaluateCandidateQuality($failingModelVersion);
    $assert(!$gateFailed['passed'], "Candidate rejected when 28d WAPE (0.38) exceeds threshold (0.30)");

    // -------------------------------------------------------------
    // Test 3: Observation Mode & Recommendation Suspension
    // -------------------------------------------------------------
    echo "\n--- Test 3: Observation Mode & Recommendation Suspension ---\n";
    $assert(!$evalService->isObservationMode(), "Observation mode initially inactive");

    // Enable observation mode
    $evalService->setObservationMode(true, $superAdminId);
    $assert($evalService->isObservationMode(), "Observation mode successfully enabled by Super Administrator");

    // Seed product and forecast
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, supplier_lead_time_days, minimum_order_quantity) VALUES (1, 'SKU-01', 'Store Item 1', 7, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 10)");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, readiness_level, model_version) VALUES (200, 1, 7, '2026-10-17', 25, '2026-10-10', 'High', '$storeModelVersion')");
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, readiness_level, model_version) VALUES (200, 1, 28, '2026-11-07', 80, '2026-10-10', 'High', '$storeModelVersion')");

    $queueService = new ReplenishmentQueueService($pdo);
    $queue = $queueService->getQueue(200, '2026-10-10');

    $assert(count($queue) === 1, "Queue loaded in observation mode");
    $assert($queue[0]['is_observation_mode'] === true, "Queue item reflects observation mode");
    $assert($queue[0]['recommendations_active'] === false, "Recommendations marked inactive / withheld from routine purchasing");
    $assert(str_contains($queue[0]['explanation'], 'Observation Mode'), "Item explanation states observation telemetry notice");

    // Verify recommendations suspension toggle
    $evalService->setRecommendationsSuspended(true, $superAdminId);
    $assert($evalService->isRecommendationsSuspended(), "Recommendations suspension toggle successfully enabled");

    // -------------------------------------------------------------
    // Test 4: Completed Target Dates Holdout Comparison
    // -------------------------------------------------------------
    echo "\n--- Test 4: Completed Target Dates Holdout Comparison ---\n";
    // Test with only 5 completed target dates (less than 28 minimum required)
    $eval5Days = $evalService->evaluateCompletedTargetDates($pdo, $storeModelVersion, '2026-10-15');
    $assert(!$eval5Days['observation_complete'], "Observation marked incomplete when completed days < 28");
    $assert($eval5Days['min_required_days'] === 28, "Minimum required completed target days is 28");

    // Seed 28 completed target dates with sales and calendar
    $historyService = new DemandHistoryService();
    $origin = '2026-09-01';
    for ($d = 1; $d <= 28; $d++) {
        $targetDate = sprintf("2026-09-%02d", $d);
        // Persist forecast
        $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, readiness_level, model_version) 
                    VALUES (201, 1, $d, '$targetDate', 10, '$origin', 'High', '$storeModelVersion')");
        // Persist calendar
        $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('$targetDate', 'open', '2026-08-30 00:00:00')");
        // Persist actual sale (8 units)
        $saleId = 1000 + $d;
        $pdo->exec("INSERT INTO sales (sale_id, cashier_id, total_amount, sale_date) VALUES ($saleId, 1, 80.00, '$targetDate 14:00:00')");
        $pdo->exec("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES ($saleId, 1, 8, 10.00, 80.00)");
        $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 50, 'delivery', '$targetDate 08:00:00')");
    }

    $eval28Days = $evalService->evaluateCompletedTargetDates($pdo, $storeModelVersion, '2026-09-28');
    $assert($eval28Days['observation_complete'] === true, "Observation marked complete when at least 28 target days are evaluated");
    $assert($eval28Days['completed_days'] >= 28, "Completed target days is at least 28");
    $assert(isset($eval28Days['metrics_by_horizon'][28]['mae']), "28-day MAE calculated against actual Store sales");
    $assert($eval28Days['metrics_by_horizon'][28]['mae'] === 2.0, "MAE is exactly 2.0 (|10 predicted - 8 actual|)");

    // -------------------------------------------------------------
    // Test 5: Technical and Operational Reviews Recording
    // -------------------------------------------------------------
    echo "\n--- Test 5: Technical and Operational Reviews Recording ---\n";
    $adminId = 2;

    // Super Administrator technical review
    $promotionService->recordTechnicalReview(
        $storeModelVersion,
        'accepted',
        'Model satisfies all 7/14/28-day WAPE thresholds and outperforms seasonal baseline across 28 completed target days.',
        $superAdminId
    );

    // Administrator operational review
    $promotionService->recordOperationalReview(
        $storeModelVersion,
        'accepted',
        'Replenishment exposure profiles align with physical shelf space and lead times. Verified operational usefulness.',
        $adminId
    );

    $reviewStatus = $promotionService->getReviewStatus($storeModelVersion);
    $assert($reviewStatus['both_accepted'] === true, "Both Super Admin technical and Admin operational reviews recorded as accepted");
    $assert($reviewStatus['technical_review']['reviewed_by'] === $superAdminId, "Technical review attributed to Super Administrator");
    $assert($reviewStatus['operational_review']['reviewed_by'] === $adminId, "Operational review attributed to Administrator");

    echo "\n========================================================\n";
    if (empty($failures)) {
        echo "PASS: All Store Model Observation Contract Tests Passed (Ticket #162)\n";
        echo "========================================================\n";
        exit(0);
    } else {
        echo "FAIL: " . count($failures) . " check(s) failed in Store Model Observation Contract:\n";
        foreach ($failures as $f) {
            echo " - $f\n";
        }
        echo "========================================================\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
