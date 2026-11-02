<?php
declare(strict_types=1);

/**
 * Test Ticket #367: Generate observation forecasts and preserve candidate progress.
 * Tests:
 * - 28 completed target dates backed by usable Store observations for activation.
 * - Missing/stockout observations cannot fake coverage.
 * - Recorded closures pause accumulation without resetting completed evidence.
 * - Genuine origins and recorded predictions strictly required (no backdating).
 * - No evidence transfer between candidates.
 * - Exposure of usable_observed_days, required_observed_days, status, and not-ready explanations.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/DemandHistoryService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Forecasting/StoreActivationService.php';

use App\Forecasting\DemandHistoryService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\StoreActivationService;

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
        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku VARCHAR(50),
            product_name VARCHAR(255),
            status VARCHAR(20) DEFAULT 'active'
        );
        CREATE TABLE store_calendar (
            calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_date DATE,
            status VARCHAR(50),
            notes TEXT,
            recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INT,
            change_qty INT,
            reason VARCHAR(50),
            moved_at DATETIME
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
            selling_form VARCHAR(20) DEFAULT 'piece'
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
            mae DOUBLE, rmse DOUBLE, wape DOUBLE,
            baseline_mae DOUBLE, baseline_rmse DOUBLE, baseline_wape DOUBLE,
            candidate_gate_outcome VARCHAR(50), missing_metric_reason VARCHAR(255),
            data_window_start DATE, data_window_end DATE, created_at DATETIME
        );
        CREATE TABLE model_training_runs (
            training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_name VARCHAR(100),
            model_version VARCHAR(100),
            trigger_type VARCHAR(50),
            status VARCHAR(50),
            metrics_json TEXT,
            started_at DATETIME,
            completed_at DATETIME,
            sales_records_used INT,
            eligible_products INT
        );
        CREATE TABLE ml_jobs (
            job_id INTEGER PRIMARY KEY AUTOINCREMENT,
            trigger_type VARCHAR(50),
            status VARCHAR(50),
            requester_id INT,
            evidence_cutoff VARCHAR(20),
            model_version VARCHAR(50),
            lease_expires_at DATETIME,
            attempts INT DEFAULT 0,
            operator_message TEXT,
            diagnostic_reference TEXT,
            queued_at DATETIME,
            started_at DATETIME,
            completed_at DATETIME
        );
    ");

    $evalService = new CandidateEvaluationService($pdo);
    $candidateVersion = 'v_candidate_c1';

    // 1. Initially no forecasts: 0 completed days, status = under_evaluation, clear not-ready explanation
    $evalInitial = $evalService->evaluateCompletedTargetDates($pdo, $candidateVersion, '2026-06-30');
    $assert($evalInitial['completed_days'] === 0, 'Initial completed_days should be 0');
    $assert($evalInitial['usable_observed_days'] === 0, 'Initial usable_observed_days should be 0');
    $assert($evalInitial['required_observed_days'] === 28, 'Required observed days should be 28');
    $assert($evalInitial['observation_complete'] === false, 'Initial observation should be incomplete');
    $assert(isset($evalInitial['status']), 'Status must be set');
    $assert($evalInitial['status'] === 'under_evaluation', 'Status should be under_evaluation when incomplete');
    $assert(str_contains($evalInitial['explanation'], '28'), 'Not-ready explanation must reference 28 required days');

    // 2. Insert product and initial stock before observation begins
    $pdo->exec("INSERT INTO products (product_id, sku, product_name) VALUES (1, 'SKU-1', 'Product 1')");
    $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 1000, 'receive', '2026-05-31 07:00:00')");

    // 3. Accumulate 14 usable observation dates (Day 1..14)
    // Forecast origin: 2026-05-31. Target dates: 2026-06-01 to 2026-06-14.
    for ($d = 1; $d <= 14; $d++) {
        $tDate = sprintf('2026-06-%02d', $d);
        $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-05-31 00:00:00')")->execute([$tDate]);
        $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level, limitation_marker)
            VALUES (1, 1, '2026-05-31', ?, ?, 5, ?, 'Medium', 'Observation only')")->execute([$tDate, $d, $candidateVersion]);
        $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 10:00:00', 'completed')")->execute([$d, $tDate]);
        $pdo->prepare("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity) VALUES (?, ?, 1, 4)")->execute([$d, $d]);
    }

    $eval14 = $evalService->evaluateCompletedTargetDates($pdo, $candidateVersion, '2026-06-14');
    $assert($eval14['usable_observed_days'] === 14, "Expected 14 usable days, got {$eval14['usable_observed_days']}");
    $assert($eval14['observation_complete'] === false, '14 days is not complete');
    $assert($eval14['status'] === 'under_evaluation', 'Status is still under_evaluation at 14 days');

    // 4. Recorded closures pause accumulation without resetting completed evidence
    // Days 15 and 16 are closed
    for ($d = 15; $d <= 16; $d++) {
        $tDate = sprintf('2026-06-%02d', $d);
        $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'closed', '2026-05-31 00:00:00')")->execute([$tDate]);
        $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
            VALUES (1, 1, '2026-05-31', ?, ?, 5, ?, 'Medium')")->execute([$tDate, $d, $candidateVersion]);
    }

    $evalClosures = $evalService->evaluateCompletedTargetDates($pdo, $candidateVersion, '2026-06-16');
    $assert($evalClosures['usable_observed_days'] === 14, "Closures must pause accumulation without resetting: expected 14, got {$evalClosures['usable_observed_days']}");

    // 5. Stockout day targets cannot advance observation
    // Day 17: stockout occurs (all stock depleted, stockout_censored)
    $tDate17 = '2026-06-17';
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-05-31 00:00:00')")->execute([$tDate17]);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
        VALUES (1, 1, '2026-05-31', ?, 17, 5, ?, 'Medium')")->execute([$tDate17, $candidateVersion]);
    // Deplete stock to 0 on Day 17
    $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, -1000, 'adjustment', '2026-06-17 08:30:00')");
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (17, '2026-06-17 10:00:00', 'completed')")->execute();
    $pdo->prepare("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity) VALUES (17, 17, 1, 2)")->execute();

    $evalStockout = $evalService->evaluateCompletedTargetDates($pdo, $candidateVersion, '2026-06-17');
    $assert($evalStockout['usable_observed_days'] === 14, "Stockout Day must not advance observation: expected 14, got {$evalStockout['usable_observed_days']}");

    // 6. Restock and continue accumulating usable days
    $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 1000, 'receive', '2026-06-18 07:00:00')");

    // Add 13 more usable days (Days 18 to 30) -> total 14 + 13 = 27 usable days (1 day short of 28)
    for ($d = 18; $d <= 30; $d++) {
        $tDate = sprintf('2026-06-%02d', $d);
        $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-05-31 00:00:00')")->execute([$tDate]);
        $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
            VALUES (1, 1, '2026-05-31', ?, ?, 5, ?, 'Medium')")->execute([$tDate, $d, $candidateVersion]);
        $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 10:00:00', 'completed')")->execute([$d, $tDate]);
        $pdo->prepare("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity) VALUES (?, ?, 1, 4)")->execute([$d, $d]);
    }

    $eval27 = $evalService->evaluateCompletedTargetDates($pdo, $candidateVersion, '2026-06-30');
    $assert($eval27['usable_observed_days'] === 27, "Expected 27 usable days, got {$eval27['usable_observed_days']}");
    $assert($eval27['observation_complete'] === false, '27 days must NOT be observation_complete');
    $assert($eval27['status'] === 'under_evaluation', 'Status remains under_evaluation at 27 days');

    // 7. Backdated forecasts cannot advance coverage:
    // If a forecast has forecast_origin >= target_date, it is NOT a valid prospective observation forecast!
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
        VALUES (1, 1, '2026-07-02', '2026-07-01', 1, 5, ?, 'Medium')")->execute([$candidateVersion]);
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-07-01', 'open', '2026-06-30 00:00:00')")->execute();
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (101, '2026-07-01 10:00:00', 'completed')")->execute();
    $pdo->prepare("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity) VALUES (101, 101, 1, 4)")->execute();

    $evalBackdated = $evalService->evaluateCompletedTargetDates($pdo, $candidateVersion, '2026-07-01');
    $assert($evalBackdated['usable_observed_days'] === 27, "Backdated forecast cannot count as observation date: expected 27, got {$evalBackdated['usable_observed_days']}");

    // 8. Forecasts for a different candidate model cannot transfer evidence:
    $otherCandidate = 'v_candidate_c2_weekly';
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
        VALUES (2, 1, '2026-06-30', '2026-07-02', 2, 5, ?, 'Medium')")->execute([$otherCandidate]);
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-07-02', 'open', '2026-06-30 00:00:00')")->execute();
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (102, '2026-07-02 10:00:00', 'completed')")->execute();
    $pdo->prepare("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity) VALUES (102, 102, 1, 4)")->execute();

    $evalCrossCandidate = $evalService->evaluateCompletedTargetDates($pdo, $candidateVersion, '2026-07-02');
    $assert($evalCrossCandidate['usable_observed_days'] === 27, "Other candidate evidence cannot transfer: expected 27, got {$evalCrossCandidate['usable_observed_days']}");

    // 9. Genuine 28th completed date for candidate C1 reaches activation readiness!
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
        VALUES (1, 1, '2026-05-31', '2026-07-03', 28, 5, ?, 'Medium')")->execute([$candidateVersion]);
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-07-03', 'open', '2026-06-30 00:00:00')")->execute();
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (103, '2026-07-03 10:00:00', 'completed')")->execute();
    $pdo->prepare("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity) VALUES (103, 103, 1, 4)")->execute();

    $eval28 = $evalService->evaluateCompletedTargetDates($pdo, $candidateVersion, '2026-07-03');
    $assert($eval28['usable_observed_days'] === 28, "Expected 28 usable days, got {$eval28['usable_observed_days']}");
    $assert($eval28['observation_complete'] === true, '28 usable days must satisfy observation_complete');
    $assert($eval28['status'] === 'awaiting_approval', 'Status becomes awaiting_approval once 28 days complete');

    // 10. Helper method getCandidateObservationProgress returns complete review state
    $prog = $evalService->getCandidateObservationProgress($candidateVersion, '2026-07-03');
    $assert($prog['usable_observed_days'] === 28, 'Progress helper returns usable_observed_days');
    $assert($prog['required_observed_days'] === 28, 'Progress helper returns required_observed_days');
    $assert($prog['status'] === 'awaiting_approval', 'Progress helper returns status');
    $assert($prog['observation_complete'] === true, 'Progress helper returns observation_complete');
    $assert(!empty($prog['explanation']), 'Progress helper returns explanation');

} catch (Throwable $e) {
    $failures[] = 'CandidateObservationProgress tests threw: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures) {
    fwrite(STDERR, "CandidateObservationProgress tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "CandidateObservationProgress tests: passed\n";
