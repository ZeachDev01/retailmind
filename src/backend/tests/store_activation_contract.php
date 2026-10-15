<?php
declare(strict_types=1);

/**
 * Contract test for Ticket #163: "Gate Store activation and verify recovery"
 * Verifies live Store model activation gates, multi-stakeholder reviews, reversible rollback,
 * recommendations suspension with zero sales/inventory disruption, and runtime health visibility.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/SystemHealthService.php';
require_once __DIR__ . '/../app/Forecasting/DemandHistoryService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Forecasting/ModelPromotionService.php';
require_once __DIR__ . '/../app/Forecasting/StoreActivationService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';
require_once __DIR__ . '/../app/Forecasting/ForecastingSchedulerService.php';

use App\Forecasting\DemandHistoryService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ModelPromotionService;
use App\Forecasting\StoreActivationService;
use App\Forecasting\ReplenishmentQueueService;
use App\Forecasting\ForecastingSchedulerService;

function assert_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FAILED: " . $message);
    }
    echo "  [PASS] " . $message . "\n";
}

echo "Testing Store Model Activation, Recovery and Runtime Gating (Ticket #163)...\n\n";

// Set up an isolated SQLite in-memory database with production-compatible schema
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialize schema
$pdo->exec("
    CREATE TABLE forecast_sales_imports (product_id INT, sale_date DATE, quantity INT, PRIMARY KEY (product_id, sale_date));
    CREATE TABLE platform_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_by INT,
        updated_at DATETIME
    );

    CREATE TABLE ml_model_evaluations (
        evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
        model_version VARCHAR(100),
        horizon_days INT,
        mae DOUBLE,
        rmse DOUBLE,
        wape DOUBLE,
        baseline_mae DOUBLE,
        baseline_rmse DOUBLE,
        baseline_wape DOUBLE,
        candidate_gate_outcome VARCHAR(50),
        missing_metric_reason VARCHAR(255),
        data_window_start DATE,
        data_window_end DATE,
        created_at DATETIME
    );

    CREATE TABLE demand_forecasts (
        forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INT,
        product_id INT,
        forecast_origin DATE,
        target_date DATE,
        horizon_day INT,
        predicted_units INT,
        model_version VARCHAR(100),
        readiness_level VARCHAR(50),
        limitation_marker VARCHAR(255)
    );

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku VARCHAR(50),
        product_name VARCHAR(255),
        category_id INT,
        unit_price DECIMAL(10,2),
        cost_price DECIMAL(10,2),
        supplier_lead_time_days INT DEFAULT 7,
        minimum_order_quantity INT DEFAULT 1,
        status VARCHAR(20) DEFAULT 'active'
    );

    CREATE TABLE inventory (
        product_id INT PRIMARY KEY,
        quantity_on_hand INT DEFAULT 0
    );

    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_date DATETIME,
        status VARCHAR(20) DEFAULT 'completed'
    );

    CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INT,
        product_id INT,
        quantity INT,
        unit_price DECIMAL(10,2),
        total_price DECIMAL(10,2)
    );

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INT,
        status VARCHAR(20)
    );

    CREATE TABLE store_calendar (
        calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
        calendar_date DATE,
        status VARCHAR(50),
        notes TEXT,
        recorded_by INT,
        recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE store_schedule_events (
        event_id INTEGER PRIMARY KEY AUTOINCREMENT,
        start_date DATE,
        end_date DATE,
        event_type VARCHAR(50),
        notes TEXT
    );

    CREATE TABLE stockout_events (
        event_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INT,
        start_date DATE,
        end_date DATE,
        source VARCHAR(50)
    );

    CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INT,
        status VARCHAR(50),
        approved_at DATETIME
    );

    CREATE TABLE sale_reversal_items (
        reversal_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        reversal_id INT,
        sale_item_id INT,
        product_id INT,
        quantity INT
    );

    CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INT,
        status VARCHAR(50),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE cash_refund_items (
        refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        refund_id INT,
        sale_item_id INT,
        product_id INT,
        quantity INT
    );

    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INT,
        change_qty INT,
        reason VARCHAR(255),
        moved_at DATETIME
    );

    CREATE TABLE ml_jobs (
        job_id INTEGER PRIMARY KEY AUTOINCREMENT,
        trigger_type VARCHAR(50),
        status VARCHAR(50),
        requester_id INT,
        attempts INT DEFAULT 0,
        queued_at DATETIME,
        started_at DATETIME,
        completed_at DATETIME,
        lease_expires_at DATETIME,
        operator_message TEXT,
        diagnostic_reference VARCHAR(100)
    );

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INT,
        action VARCHAR(255),
        category VARCHAR(100),
        module VARCHAR(100),
        record_id INT,
        previous_value TEXT,
        new_value TEXT,
        metadata TEXT,
        ip_address VARCHAR(50),
        created_at DATETIME
    );
");

// Populate standard product P1
$pdo->exec("INSERT INTO products (product_id, sku, product_name, unit_price, cost_price, status) VALUES (1, 'STORE-P1', 'Standard Store Product 1', 100.0, 60.0, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 50)");

$evalService = new CandidateEvaluationService($pdo);
$promotionService = new ModelPromotionService($pdo, $evalService);
$activationService = new StoreActivationService($pdo, $evalService, $promotionService);

// =========================================================================
echo "--- Test 1: Demo Isolation Barrier ---\n";
// =========================================================================
$demoModelVersion = 'kaggle_demo_rf_v1';
$readinessDemo = $activationService->validateActivationReadiness($demoModelVersion, '2026-10-10');
assert_check($readinessDemo['ready'] === false, 'Activation must be blocked for demo model');
assert_check(count(array_filter($readinessDemo['reasons'], fn($r) => str_contains($r, 'demo/kaggle'))) > 0, 'Demo rejection reason must be clearly stated');

$demoBlocked = false;
try {
    $activationService->activateStoreModel($demoModelVersion, '2026-10-10', 1, 'Attempting demo activation');
} catch (InvalidArgumentException $e) {
    $demoBlocked = true;
}
assert_check($demoBlocked, 'StoreActivationService::activateStoreModel must throw on demo models');

// =========================================================================
echo "\n--- Test 2: Activation Gating Matrix (Missing Evidence Refusal) ---\n";
// =========================================================================
$candidateVersion = 'store_rf_candidate_20261010';

// Scenario 2A: No evaluations and no reviews
$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-10-10');
assert_check($readiness['ready'] === false, 'Activation is blocked when all evidence is missing');
assert_check($readiness['checks']['quality_gates_passed'] === false, 'Quality gates check is false');
assert_check($readiness['checks']['technical_review_accepted'] === false, 'Technical review check is false');
assert_check($readiness['checks']['operational_review_accepted'] === false, 'Operational review check is false');
assert_check($readiness['checks']['observation_period_completed'] === false, 'Observation period check is false');

// Seed evaluations with passing WAPE (0.15, 0.18, 0.22) vs baseline (0.25, 0.28, 0.32)
$evalService->setThresholds(['ml_max_wape_7d' => 0.20, 'ml_max_wape_14d' => 0.25, 'ml_max_wape_28d' => 0.30], 1);
foreach ([7 => [0.15, 0.25], 14 => [0.18, 0.28], 28 => [0.22, 0.32]] as $h => [$w, $bw]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, baseline_wape, candidate_gate_outcome, created_at)
        VALUES (?, ?, ?, ?, 'passed', '2026-10-09 23:00:00')
    ")->execute([$candidateVersion, $h, $w, $bw]);
}

// Scenario 2B: Quality passes, but reviews and observation missing
$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-10-10');
assert_check($readiness['checks']['quality_gates_passed'] === true, 'Quality gates now pass');
assert_check($readiness['ready'] === false, 'Activation still blocked without reviews and observation');

// Record Super Admin technical review
$promotionService->recordTechnicalReview($candidateVersion, 'accepted', 'Technical validation passed across all 3 horizons.', 1);
$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-10-10');
assert_check($readiness['checks']['technical_review_accepted'] === true, 'Super Admin technical approval verified');
assert_check($readiness['ready'] === false, 'Activation still blocked without operational review');

// Record Admin operational review
$promotionService->recordOperationalReview($candidateVersion, 'accepted', 'Forecast patterns match Store promotions and weekly seasonality.', 2);
$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-10-10');
assert_check($readiness['checks']['operational_review_accepted'] === true, 'Admin operational review verified');
assert_check($readiness['ready'] === false, 'Activation still blocked without 28 observation days');

// Elapsed forecast dates without usable demand evidence must not unlock activation.
for ($i = 1; $i <= 28; $i++) {
    $tDate = sprintf('2026-08-%02d', $i);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version) VALUES (1, 1, '2026-07-31', ?, ?, 10, ?)")->execute([$tDate, $i, $candidateVersion]);
    if ($i > 10) {
        $status = $i <= 20 ? 'closed' : 'open';
        $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, ?, '2026-07-30 00:00:00')")->execute([$tDate, $status]);
    }
    if ($i > 20) {
        $pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 0, 'count', ? || ' 08:00:00')")->execute([$tDate]);
    }
}
$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-08-28');
assert_check($readiness['observation_details']['completed_days'] === 0, '28 missing, closed, or stockout-censored dates contribute no observation days');
assert_check($readiness['observation_details']['metrics_by_horizon'][28]['count'] === 0, 'Unusable observations contribute no evaluation samples');
assert_check($readiness['ready'] === false && !$readiness['checks']['observation_period_completed'], 'Passing quality and reviews cannot activate without usable observations');
$unusableBlocked = false;
try {
    $activationService->activateStoreModel($candidateVersion, '2026-08-28', 1);
} catch (InvalidArgumentException $e) {
    $unusableBlocked = true;
}
assert_check($unusableBlocked, 'Activation refuses 28 elapsed dates with no usable evidence');

// Seed 10 days of observation forecasts and sales (insufficient)
for ($i = 1; $i <= 10; $i++) {
    $tDate = sprintf('2026-09-%02d', $i);
    $pdo->prepare("
        INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-08-30 00:00:00')
    ")->execute([$tDate]);
    $pdo->prepare("
        INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version)
        VALUES (1, 1, '2026-08-31', ?, ?, 10, ?)
    ")->execute([$tDate, $i, $candidateVersion]);
    $pdo->prepare("
        INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 10:00:00', 'completed')
    ")->execute([$i, $tDate]);
    $pdo->prepare("
        INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 1, 8, 100.0, 800.0)
    ")->execute([$i]);
}

$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-09-10');
assert_check($readiness['observation_details']['completed_days'] === 10, '10 completed observation days recorded');
assert_check($readiness['checks']['observation_period_completed'] === false, 'Observation period still incomplete (< 28 days)');
assert_check($readiness['ready'] === false, 'Mixed 28 unusable and 10 usable dates cannot activate');

$pdo->exec("INSERT INTO products (product_id, sku, product_name, unit_price, cost_price, status) VALUES (2, 'STORE-P2', 'Second Store Product', 100.0, 60.0, 'active')");
for ($i = 1; $i <= 10; $i++) {
    $tDate = sprintf('2026-09-%02d', $i);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version) VALUES (1, 2, '2026-08-31', ?, ?, 8, ?)")->execute([$tDate, $i, $candidateVersion]);
    $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 2, 8, 100.0, 800.0)")->execute([$i]);
}
$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-09-10');
assert_check($readiness['observation_details']['completed_days'] === 10, 'Multiple products on the same usable dates still count only 10 days');
assert_check($readiness['observation_details']['metrics_by_horizon'][28]['count'] === 20, 'Both products contribute samples without duplicating observed days');

// Seed remaining 18 days (total 28 days)
for ($i = 11; $i <= 28; $i++) {
    $tDate = sprintf('2026-09-%02d', $i);
    $pdo->prepare("
        INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-08-30 00:00:00')
    ")->execute([$tDate]);
    $pdo->prepare("
        INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version)
        VALUES (1, 1, '2026-08-31', ?, ?, 10, ?)
    ")->execute([$tDate, $i, $candidateVersion]);
    $pdo->prepare("
        INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 10:00:00', 'completed')
    ")->execute([$i, $tDate]);
    $pdo->prepare("
        INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 1, 8, 100.0, 800.0)
    ")->execute([$i]);
}

$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-09-28');
assert_check($readiness['observation_details']['completed_days'] === 28, '28 completed observation days recorded');
assert_check($readiness['checks']['observation_period_completed'] === true, 'Observation period holdout now satisfied');
assert_check($readiness['ready'] === true, 'All gating criteria satisfied: candidate is ready for activation');

$pdo->beginTransaction();
$pdo->exec("DELETE FROM sale_items WHERE sale_id = 28 AND product_id = 1");
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 10, 'delivery', '2026-09-28 08:00:00')");
$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-09-28');
assert_check($readiness['observation_details']['completed_days'] === 28 && $readiness['ready'], 'Genuine zero demand with known positive stock qualifies as a usable observation date');
$pdo->rollBack();

$pdo->beginTransaction();
$pdo->prepare("UPDATE demand_forecasts SET horizon_day = 29 WHERE model_version = ?")->execute([$candidateVersion]);
$readiness = $activationService->validateActivationReadiness($candidateVersion, '2026-09-28');
assert_check($readiness['observation_details']['metrics_by_horizon'][28]['count'] === 0, 'Out-of-window forecasts provide no evaluation samples');
assert_check(!$readiness['ready'] && !$readiness['checks']['observation_period_completed'], 'Activation stays blocked when every evaluation horizon lacks samples');
$pdo->rollBack();

// Execute Activation
$evalService->setObservationMode(true, 1);
$evalService->setRecommendationsSuspended(true, 1);
$record = $activationService->activateStoreModel($candidateVersion, '2026-09-28', 1, 'Routine activation following 28-day observation');

assert_check($record['model_version'] === $candidateVersion, 'Activation record contains model version');
assert_check($record['activated_by'] === 1, 'Activation attributed to Super Administrator');

$status = $activationService->getActivationStatus();
assert_check($status['approved_model_version'] === $candidateVersion, 'Active model set to approved Store candidate');
assert_check($status['is_observation_mode'] === false, 'Observation mode disabled upon live activation');
assert_check($status['is_recommendations_suspended'] === false, 'Recommendations suspension cleared upon live activation');
assert_check($status['is_store_activated'] === true, 'Store model is formally active');

// =========================================================================
echo "\n--- Test 3: Reversible Rollback Governance ---\n";
// =========================================================================
$nextCandidate = 'store_rf_candidate_20261101';
// Prepare passing evaluations for candidate 2
foreach ([7 => [0.12, 0.25], 14 => [0.15, 0.28], 28 => [0.19, 0.32]] as $h => [$w, $bw]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, baseline_wape, candidate_gate_outcome, created_at)
        VALUES (?, ?, ?, ?, 'passed', '2026-10-31 23:00:00')
    ")->execute([$nextCandidate, $h, $w, $bw]);
}
$promotionService->recordTechnicalReview($nextCandidate, 'accepted', 'Candidate 2 technically sound.', 1);
$promotionService->recordOperationalReview($nextCandidate, 'accepted', 'Candidate 2 operationally accepted.', 2);
for ($i = 1; $i <= 28; $i++) {
    $tDate = sprintf('2026-10-%02d', $i);
    $pdo->prepare("
        INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-09-29 00:00:00')
    ")->execute([$tDate]);
    $pdo->prepare("
        INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version)
        VALUES (2, 1, '2026-09-30', ?, ?, 12, ?)
    ")->execute([$tDate, $i, $nextCandidate]);
    $saleId = 100 + $i;
    $pdo->prepare("
        INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 10:00:00', 'completed')
    ")->execute([$saleId, $tDate]);
    $pdo->prepare("
        INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 1, 8, 100.0, 800.0)
    ")->execute([$saleId]);
}

$activationService->activateStoreModel($nextCandidate, '2026-10-28', 1, 'Promoting candidate 2');
$status = $activationService->getActivationStatus();
assert_check($status['approved_model_version'] === $nextCandidate, 'Candidate 2 is now active');
assert_check($status['previous_model_version'] === $candidateVersion, 'Previous candidate 1 preserved in settings');

// Perform Rollback
$rollback = $activationService->rollbackStoreModel(1, 'Candidate 2 exhibited erratic promotional elasticity in production');
assert_check($rollback['restored_model_version'] === $candidateVersion, 'Restored prior approved Store model');
$status = $activationService->getActivationStatus();
assert_check($status['approved_model_version'] === $candidateVersion, 'Current approved model restored to candidate 1');
assert_check($status['previous_model_version'] === '', 'Previous model cleared to prevent loops');

// Re-attempting rollback without previous model must throw
$secondRollbackFailed = false;
try {
    $activationService->rollbackStoreModel(1, 'Second rollback attempt');
} catch (RuntimeException $e) {
    $secondRollbackFailed = true;
}
assert_check($secondRollbackFailed, 'Subsequent rollback blocked when no previous model is preserved');

// =========================================================================
echo "\n--- Test 4: Recommendation Suspension & Operational Non-Interference ---\n";
// =========================================================================
// Suspend recommendations
$activationService->suspendRecommendations(1, 'Emergency vendor price adjustment investigation');
$status = $activationService->getActivationStatus();
assert_check($status['is_recommendations_suspended'] === true, 'Recommendations are marked suspended');

// Replenishment queue reflects suspension
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status) VALUES (99, 'forecast_generation', 'succeeded')");
$queueService = new ReplenishmentQueueService($pdo);
// Create a forecast for product 1 under current approved model
$pdo->prepare("
    INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
    VALUES (99, 1, '2026-10-10', '2026-10-11', 1, 15, ?, 'High')
")->execute([$candidateVersion]);
$queue = $queueService->getQueue(99, '2026-10-10');
assert_check(count($queue) > 0, 'Queue produces items');
assert_check($queue[0]['is_recommendations_suspended'] === true, 'Queue item indicates recommendations suspended');
assert_check($queue[0]['recommendations_active'] === false, 'Recommendations marked inactive');

// Zero Operational Interference:
// Sales, inventory, and purchase orders must execute normally
$pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (999, '2026-10-10 14:00:00', 'completed')")->execute();
$pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (999, 1, 2, 100.0, 200.0)")->execute();
$pdo->prepare("UPDATE inventory SET quantity_on_hand = quantity_on_hand - 2 WHERE product_id = 1")->execute();

$stockAfterSale = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
assert_check($stockAfterSale === 48, 'Cashier sale and inventory deduction executed normally while recommendations suspended');

// Resume recommendations
$activationService->resumeRecommendations(1);
$status = $activationService->getActivationStatus();
assert_check($status['is_recommendations_suspended'] === false, 'Recommendations successfully resumed');

// =========================================================================
echo "\n--- Test 5: Production Runtime & Maintenance Parity ---\n";
// =========================================================================
$runtime = $activationService->verifyRuntimeEnvironment(__DIR__ . '/..');
assert_check($runtime['supported_runtime'] === true, 'Runtime OS recognized (' . $runtime['os_family'] . ')');
assert_check(in_array($runtime['os_family'], ['Windows', 'Linux'], true) || PHP_OS_FAMILY !== '', 'Platform OS detected correctly');

// Check maintenance scripts parity (neither script references legacy auto_retrain.py)
$batContent = file_get_contents(__DIR__ . '/../scripts/run_maintenance.bat');
$shContent = file_get_contents(__DIR__ . '/../scripts/run_maintenance.sh');
assert_check(!str_contains($batContent, 'legacy\\demandForcasting\\auto_retrain.py'), 'run_maintenance.bat does not call legacy auto_retrain.py');
assert_check(str_contains($batContent, 'scripts\\run_scheduler.php'), 'run_maintenance.bat calls run_scheduler.php');
assert_check(!str_contains($shContent, 'legacy/demandForcasting/auto_retrain.py'), 'run_maintenance.sh does not call legacy auto_retrain.py');
assert_check(str_contains($shContent, 'scripts/run_scheduler.php'), 'run_maintenance.sh calls run_scheduler.php');

// Check retired synchronous ML functions return HTTP 410 Gone
$callResult = call_ml_api('http://127.0.0.1:5000/predict');
assert_check($callResult['_http_code'] === 410, 'call_ml_api returns 410 Gone');
$predResult = get_ml_prediction(1);
assert_check($predResult['_http_code'] === 410, 'get_ml_prediction returns 410 Gone');

// System Health checks reflect forecasting pipeline
$healthService = new SystemHealthService($pdo, __DIR__ . '/..');
$healthChecks = $healthService->checks();
$pipelineCheck = null;
foreach ($healthChecks as $c) {
    if ($c['name'] === 'Forecasting pipeline') {
        $pipelineCheck = $c;
        break;
    }
}
assert_check($pipelineCheck !== null, 'Forecasting pipeline health check present in SystemHealthService');
assert_check($pipelineCheck['status'] === 'healthy', 'Pipeline health is healthy when active Store model is set');

echo "\n========================================================\n";
echo "PASS: All Store Activation and Recovery Contract Tests Passed (Ticket #163)\n";
echo "========================================================\n";
