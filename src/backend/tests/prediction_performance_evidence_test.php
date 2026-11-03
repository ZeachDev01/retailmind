<?php
declare(strict_types=1);

/**
 * Test Ticket #414: Show honest prediction-performance evidence contract.
 *
 * Acceptance Criteria:
 * 1. Match forecasts recorded before outcomes with genuine subsequent Store observations
 *    and existing weekly comparison on matching eligible periods.
 * 2. Identify evaluated model, candidate/current/previous association, Products, dates,
 *    supported horizon, usable comparison count. No accuracy promises or new thresholds.
 * 3. Show overestimated and underestimated units separately at stated Product/horizon boundaries.
 *    No signed error cancellation; no overlapping windows as independent evidence; missing stays unavailable.
 * 4. Treat stockouts (including positive sales before sellout) as incomplete demand evidence.
 *    Preserve distinctions for closures, unknown, imported history; do not invent lost demand.
 * 5. Keep verified stockouts and expiry losses separate from prediction error, showing unavailable
 *    when evidence is absent. Make no causal benefit claim; label demo/synthetic evidence separately
 *    and describe current model inputs truthfully.
 * 6. Verify genuine-evidence, insufficient-evidence, and unavailable states and model association
 *    through public evaluation/display boundaries. Operational job success cannot become proof of prediction usefulness.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Forecasting/DemandHistoryService.php';

use App\Forecasting\DemandForecastService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\DemandHistoryService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] {$message}\n";
    } else {
        echo "  [PASS] {$message}\n";
    }
};

function create_test_pdo(): PDO
{
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
            cashier_id INT DEFAULT 1,
            total_amount REAL DEFAULT 0,
            sale_date DATETIME,
            status VARCHAR(20) DEFAULT 'completed'
        );
        CREATE TABLE sale_items (
            sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INT,
            product_id INT,
            quantity INT,
            unit_price REAL DEFAULT 10.0,
            subtotal REAL DEFAULT 10.0,
            selling_form VARCHAR(20) DEFAULT 'piece'
        );
        CREATE TABLE ml_jobs (
            job_id INTEGER PRIMARY KEY AUTOINCREMENT,
            trigger_type VARCHAR(50),
            status VARCHAR(50),
            model_version VARCHAR(100),
            evidence_cutoff DATETIME,
            lease_expires_at DATETIME,
            attempts INT DEFAULT 0,
            operator_message TEXT,
            diagnostic_reference VARCHAR(255),
            queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME,
            completed_at DATETIME
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
            limitation_marker VARCHAR(255),
            created_at DATETIME NOT NULL DEFAULT '2026-08-01 01:00:00'
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
            mae REAL, rmse REAL, wape REAL,
            baseline_mae REAL, baseline_rmse REAL, baseline_wape REAL,
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
    ");
    return $pdo;
}

echo "Starting Ticket #414 Prediction-Performance Evidence Contract Test...\n\n";

// -------------------------------------------------------------
// Test 1: Unavailable state (Empty Store / No evaluations)
// -------------------------------------------------------------
echo "--- Test 1: Unavailable State when no evidence or forecasts exist ---\n";
$pdo = create_test_pdo();
$forecastService = new DemandForecastService($pdo);

$evidence = $forecastService->getPredictionPerformanceEvidence('2026-10-31');
$assert($evidence['status'] === 'unavailable', "Empty store status is unavailable");
$assert($evidence['status_label'] === 'Unavailable', "Empty store status_label is 'Unavailable'");
$assert($evidence['usable_comparison_count'] === 0, "Empty store usable_comparison_count is 0");
$assert($evidence['completed_target_dates'] === 0, "Empty store completed_target_dates is 0");
$assert(str_contains($evidence['explanation'], 'No completed forecasts or usable Store sales observations'), "Explanation describes unavailable state");
$assert($evidence['metrics_by_horizon'][7]['mae'] === null, "MAE is null (unavailable), not fabricated zero");
$assert($evidence['verified_stockouts'] === null, "Verified stockouts is null when evidence absent");
$assert($evidence['verified_expiry_losses'] === null, "Verified expiry losses is null when evidence absent");

// -------------------------------------------------------------
// Test 2: Insufficient evidence state (< 28 days) & Operational job success != Prediction usefulness
// -------------------------------------------------------------
echo "\n--- Test 2: Still collecting evidence state & Operational success separation ---\n";
// Insert a successful forecast job (operational success!)
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, model_version, completed_at)
            VALUES (1, 'forecast_generation', 'succeeded', 'rf_store_v1', '2026-10-01 01:15:00')");
$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value)
            VALUES ('ml_approved_model_version', 'rf_store_v1'), ('ml_observation_mode', '0'), ('ml_recommendations_suspended', '0')");
$pdo->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'SKU-001', 'Test Soda', 'active')");

// Seed 5 completed target dates (origin is strictly before target date)
$origin = '2026-10-01';
for ($d = 2; $d <= 6; $d++) {
    $targetDate = sprintf("2026-10-%02d", $d);
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, readiness_level, model_version)
                VALUES (1, 1, " . ($d - 1) . ", '$targetDate', 12, '$origin', 'High', 'rf_store_v1')");
    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('$targetDate', 'open', '2026-09-30 00:00:00')");
    $saleId = 100 + $d;
    $pdo->exec("INSERT INTO sales (sale_id, total_amount, sale_date) VALUES ($saleId, 100.0, '$targetDate 12:00:00')");
    $pdo->exec("INSERT INTO sale_items (sale_id, product_id, quantity) VALUES ($saleId, 1, 10)");
    $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 50, 'delivery', '$targetDate 08:00:00')");
}

$evidence2 = $forecastService->getPredictionPerformanceEvidence('2026-10-06');
$assert($evidence2['status'] === 'collecting_evidence', "Status is collecting_evidence when days < 28");
$assert($evidence2['status_label'] === 'Still collecting evidence', "Status label is 'Still collecting evidence'");
$assert($evidence2['completed_target_dates'] === 5, "Completed target dates is 5");
$assert($evidence2['required_target_dates'] === 28, "Required target dates is 28");
$assert(str_contains($evidence2['explanation'], '5 of 28 completed target dates'), "Explanation details progress (5 of 28)");
$assert(str_contains($evidence2['explanation'], 'Requires at least 28 completed target dates'), "Explanation gives explicit reason");
$assert($evidence2['status'] !== 'genuine_evidence', "Operational job success did NOT falsely become genuine evidence");

$pdo->exec("UPDATE ml_jobs SET completed_at = '2026-10-07 01:00:00' WHERE job_id = 1");
$retrospective = $forecastService->getPredictionPerformanceEvidence('2026-10-06');
$assert($retrospective['usable_comparison_count'] === 0, 'Retrospectively generated forecasts are excluded despite historical origin');
$pdo->exec("UPDATE ml_jobs SET completed_at = '2026-10-01 01:15:00' WHERE job_id = 1");
$pdo->exec("UPDATE demand_forecasts SET created_at = '2026-10-07 01:00:00'");
$retrospectiveRows = $forecastService->getPredictionPerformanceEvidence('2026-10-06');
$assert($retrospectiveRows['usable_comparison_count'] === 0, 'Forecasts persisted after outcomes are excluded');
$pdo->exec("UPDATE demand_forecasts SET created_at = '2026-10-01 01:15:00'");

// -------------------------------------------------------------
// Test 3: Model association, Candidate/Current/Previous, and Truthful Inputs
// -------------------------------------------------------------
echo "\n--- Test 3: Model association, Demo labeling, and Truthful inputs ---\n";
$assert($evidence2['model_version'] === 'rf_store_v1', "Identifies evaluated model version");
$assert($evidence2['model_association'] === 'current', "Associated as current approved model");
$assert(str_contains($evidence2['model_inputs_description'], 'sales history'), "Describes truthful sales history input");
$assert(str_contains($evidence2['model_inputs_description'], 'not active model inputs'), "Truthfully states prices/promotions are not active model inputs");

// Test candidate association
$pdo->exec("UPDATE platform_settings SET setting_value = 'other_model' WHERE setting_key = 'ml_approved_model_version'");
$pdo->exec("INSERT INTO model_training_runs (training_run_id, model_name, model_version, trigger_type, status)
            VALUES (1, 'Random Forest', 'rf_store_v1', 'scheduled_training', 'completed')");
$evidenceCandidate = $forecastService->getPredictionPerformanceEvidence('2026-10-06', 'rf_store_v1');
$assert($evidenceCandidate['model_association'] === 'candidate', "Unapproved trained model is associated as 'candidate'");

// Test previous association
$pdo->exec("UPDATE model_training_runs SET model_version = 'newer_candidate' WHERE training_run_id = 1");
$evidencePrev = $forecastService->getPredictionPerformanceEvidence('2026-10-06', 'rf_store_v1');
$assert($evidencePrev['model_association'] === 'previous', "Older replaced model is associated as 'previous'");

// Test demo dataset flag
$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('demo_import_metadata', '{\"source_label\":\"S001\"}')");
$evidenceDemo = $forecastService->getPredictionPerformanceEvidence('2026-10-06', 'rf_store_v1');
$assert($evidenceDemo['is_demo'] === true, "Demo metadata marks is_demo as true");
$assert(str_contains($evidenceDemo['demo_label'], 'Demo Evidence (S001)'), "Demo evidence is labelled separately");
$pdo->exec("DELETE FROM platform_settings WHERE setting_key = 'demo_import_metadata'");
$pdo->exec("UPDATE platform_settings SET setting_value = 'rf_store_v1' WHERE setting_key = 'ml_approved_model_version'");

// -------------------------------------------------------------
// Test 4: Separate Overestimated and Underestimated Units (No cancellation)
// -------------------------------------------------------------
echo "\n--- Test 4: Separate Overestimated & Underestimated units (No cancellation) ---\n";
$pdo = create_test_pdo();
$forecastService = new DemandForecastService($pdo);

$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, model_version, completed_at)
            VALUES (1, 'forecast_generation', 'succeeded', 'rf_store_v1', '2026-10-01 01:15:00')");
$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value)
            VALUES ('ml_approved_model_version', 'rf_store_v1'), ('ml_observation_mode', '0'), ('ml_recommendations_suspended', '0')");
$pdo->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES
            (1, 'SKU-001', 'Product A', 'active'),
            (2, 'SKU-002', 'Product B', 'active')");

// Product 1: predicted 20 units, actual 15 units (+5 overestimate)
// Product 2: predicted 10 units, actual 15 units (-5 underestimate)
// Target date 2026-10-02, origin 2026-10-01
$pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, readiness_level, model_version) VALUES
            (1, 1, 1, '2026-10-02', 20, '2026-10-01', 'High', 'rf_store_v1'),
            (1, 2, 1, '2026-10-02', 10, '2026-10-01', 'High', 'rf_store_v1')");
$pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-02', 'open', '2026-09-30 00:00:00')");
$pdo->exec("INSERT INTO sales (sale_id, total_amount, sale_date) VALUES
            (101, 150.0, '2026-10-02 12:00:00'),
            (102, 150.0, '2026-10-02 13:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_id, product_id, quantity) VALUES
            (101, 1, 15),
            (102, 2, 15)");
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES
            (1, 20, 'delivery', '2026-10-02 08:00:00'),
            (2, 20, 'delivery', '2026-10-02 08:00:00')");

$evidenceUnits = $forecastService->getPredictionPerformanceEvidence('2026-10-02');
$h7 = $evidenceUnits['metrics_by_horizon'][7];
$assert($h7['overestimated_units'] === 5.0, "Overestimated units is exactly 5.0");
$assert($h7['underestimated_units'] === 5.0, "Underestimated units is exactly 5.0");
$assert($h7['mae'] === 5.0, "MAE is 5.0 (|20-15| + |10-15|) / 2");
$assert($h7['overestimated_count'] === 1, "Overestimated count is 1");
$assert($h7['underestimated_count'] === 1, "Underestimated count is 1");
$assert($h7['usable_comparisons'] === 2, "Usable comparison count is 2");

$pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, model_version)
    VALUES (1, 1, 2, '2026-10-02', 999, '2026-09-30', 'rf_store_v1')");
$overlap = $forecastService->getPredictionPerformanceEvidence('2026-10-02');
$assert($overlap['usable_comparison_count'] === 2, 'Overlapping windows do not multiply independent Product/date outcomes');
$assert($overlap['metrics_by_horizon'][7]['mae'] === 5.0, 'Latest eligible origin supplies each Product/date/horizon comparison');
$pdo->exec("DELETE FROM demand_forecasts WHERE predicted_units = 999");

// -------------------------------------------------------------
// Test 5: Weekly Seasonal Naive comparison on matching eligible periods
// -------------------------------------------------------------
echo "\n--- Test 5: Weekly Seasonal Naive comparison on matching eligible periods ---\n";
// Add prior week sales for 2026-10-02 (matching baseline is 2026-09-25)
$pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-09-25', 'open', '2026-09-20 00:00:00')");
$pdo->exec("INSERT INTO sales (sale_id, total_amount, sale_date) VALUES
            (91, 120.0, '2026-09-25 12:00:00'),
            (92, 180.0, '2026-09-25 13:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_id, product_id, quantity) VALUES
            (91, 1, 12),
            (92, 2, 18)");
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES
            (1, 25, 'delivery', '2026-09-25 08:00:00'),
            (2, 25, 'delivery', '2026-09-25 08:00:00')");

// Weekly baseline for P1 was 12 (actual was 15 => underest by 3)
// Weekly baseline for P2 was 18 (actual was 15 => overest by 3)
$evidenceBaseline = $forecastService->getPredictionPerformanceEvidence('2026-10-02');
$h7Base = $evidenceBaseline['metrics_by_horizon'][7];
$assert($h7Base['baseline_mae'] === 3.0, "Baseline MAE is 3.0 on matching eligible period");
$assert($h7Base['baseline_overestimated_units'] === 3.0, "Baseline overestimated units is 3.0");
$assert($h7Base['baseline_underestimated_units'] === 3.0, "Baseline underestimated units is 3.0");

$pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, model_version)
    VALUES (1, 1, 15, '2026-10-16', 20, '2026-10-01', 'rf_store_v1');
    INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES
        ('2026-10-09', 'open', '2026-09-20 00:00:00'), ('2026-10-16', 'open', '2026-09-20 00:00:00');
    INSERT INTO sales (sale_id, sale_date) VALUES (109, '2026-10-09 12:00:00'), (116, '2026-10-16 12:00:00');
    INSERT INTO sale_items (sale_id, product_id, quantity) VALUES (109, 1, 99), (116, 1, 15);
    INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 500, 'delivery', '2026-10-09 08:00:00')");
$frozenBaseline = $forecastService->getPredictionPerformanceEvidence('2026-10-16');
$assert($frozenBaseline['metrics_by_horizon'][28]['baseline_mae'] === 3.0, 'Weekly baseline repeats the last week known at forecast origin for long horizons');
$pdo->exec('DELETE FROM sale_items WHERE sale_id = 92');
$pdo->exec("DELETE FROM stock_movements WHERE product_id = 2 AND moved_at < '2026-10-01'");
$incomparable = $forecastService->getPredictionPerformanceEvidence('2026-10-02');
$assert($incomparable['metrics_by_horizon'][7]['baseline_mae'] === null, 'Baseline stays unavailable when it does not match the model comparison population');
// Restore baseline evidence for the stockout/closure checks below.
$pdo->exec("INSERT INTO sale_items (sale_id, product_id, quantity) VALUES (92, 2, 18);
    INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (2, 25, 'delivery', '2026-09-25 08:00:00')");

// -------------------------------------------------------------
// Test 6: Incomplete demand / Stockouts, Closures, Unknown history
// -------------------------------------------------------------
echo "\n--- Test 6: Stockouts treated as incomplete demand (not overestimation) ---\n";
// Target date 2026-10-03: Product 1 has forecast 25. Actual sales = 8 units before running out of stock!
// Inventory ended at 0. Stockout censored!
$pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, readiness_level, model_version) VALUES
            (1, 1, 2, '2026-10-03', 25, '2026-10-01', 'High', 'rf_store_v1')");
$pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-03', 'open', '2026-09-30 00:00:00')");
$pdo->exec("INSERT INTO sales (sale_id, total_amount, sale_date) VALUES (103, 80.0, '2026-10-03 10:00:00')");
$pdo->exec("INSERT INTO sale_items (sale_id, product_id, quantity) VALUES (103, 1, 8)");
// Deduction drops stock balance to 0 on 2026-10-03!
// Drop stock balance to 0 on 2026-10-03 (prior was 25 + 20 = 45)
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, -45, 'sale', '2026-10-03 10:00:00')");

$evidenceStockout = $forecastService->getPredictionPerformanceEvidence('2026-10-03');
// Product 1 on 2026-10-03 was a stockout. It MUST NOT be counted as an overestimation of 17 units (25 - 8)!
// Overestimated units should remain 5.0 (from 2026-10-02), NOT 22.0!
$h7Stockout = $evidenceStockout['metrics_by_horizon'][7];
$assert($h7Stockout['overestimated_units'] === 5.0, "Stockout day did NOT manufacture 17 units of false overestimation");
$assert($evidenceStockout['incomplete_demand_count'] >= 1, "Stockout is counted as incomplete demand evidence");

// Target date 2026-10-04: Closed Store.
$pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, readiness_level, model_version) VALUES
            (1, 1, 3, '2026-10-04', 15, '2026-10-01', 'High', 'rf_store_v1')");
$pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('2026-10-04', 'closed', '2026-09-30 00:00:00')");
$evidenceClosure = $forecastService->getPredictionPerformanceEvidence('2026-10-04');
$assert($evidenceClosure['closures_paused'] >= 1, "Closure paused accumulation and preserved distinction");

// -------------------------------------------------------------
// Test 7: Verified stockouts and Expiry losses separate from prediction error
// -------------------------------------------------------------
echo "\n--- Test 7: Verified stockouts and Expiry losses separate with no causal claim ---\n";
// Record verified stockout and expiry loss
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES
            (1, -4, 'expired', '2026-10-02 17:00:00')");
$evidenceLoss = $forecastService->getPredictionPerformanceEvidence('2026-10-04');
$assert($evidenceLoss['verified_expiry_losses'] === 4, "Verified expiry losses is 4 units from stock movements");
$assert($evidenceLoss['verified_stockouts'] >= 1, "Verified stockout events count is recorded");
$assert($evidenceLoss['causal_claim'] === null, "No causal reduction claim is made");
$assert(str_contains($evidenceLoss['causal_claim_note'], 'Advisory guidance'), "Notes advisory guidance without causal claims");

// -------------------------------------------------------------
// Test 8: Genuine Store Evidence state when 28 completed target dates exist
// -------------------------------------------------------------
echo "\n--- Test 8: Genuine Store evidence state (>= 28 days) ---\n";
$pdo = create_test_pdo();
$forecastService = new DemandForecastService($pdo);

$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, model_version, completed_at)
            VALUES (1, 'forecast_generation', 'succeeded', 'rf_store_v1', '2026-09-01 01:15:00')");
$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value)
            VALUES ('ml_approved_model_version', 'rf_store_v1'), ('ml_observation_mode', '0'), ('ml_recommendations_suspended', '0')");
$pdo->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'SKU-001', 'Test Product', 'active')");

$origin = '2026-09-01';
for ($d = 2; $d <= 30; $d++) {
    // 29 days total (d=2 to d=30)
    $targetDate = sprintf("2026-09-%02d", $d);
    $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, readiness_level, model_version)
                VALUES (1, 1, " . ($d - 1) . ", '$targetDate', 10, '$origin', 'High', 'rf_store_v1')");
    $pdo->exec("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES ('$targetDate', 'open', '2026-08-30 00:00:00')");
    $saleId = 200 + $d;
    $pdo->exec("INSERT INTO sales (sale_id, total_amount, sale_date) VALUES ($saleId, 80.0, '$targetDate 12:00:00')");
    $pdo->exec("INSERT INTO sale_items (sale_id, product_id, quantity) VALUES ($saleId, 1, 8)");
    $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 50, 'delivery', '$targetDate 08:00:00')");
}

$evidence28 = $forecastService->getPredictionPerformanceEvidence('2026-09-30');
$assert($evidence28['status'] === 'genuine_evidence', "Status is genuine_evidence when completed days >= 28");
$assert($evidence28['status_label'] === 'Genuine Store evidence', "Status label is 'Genuine Store evidence'");
$assert($evidence28['completed_target_dates'] >= 28, "Completed target dates is >= 28");
$assert($evidence28['metrics_by_horizon'][28]['overestimated_units'] > 0, "28-day overestimated units calculated");
$assert($evidence28['metrics_by_horizon'][28]['mae'] === 2.0, "28-day MAE is exactly 2.0 (|10-8|)");

$summary28 = $forecastService->getPerformanceSummary('rf_store_v1');
$assert($summary28['status'] === 'available', 'Landing summary uses genuine subsequent prediction outcomes');
$assert($summary28['horizons'][28]['mae'] === 2.0, 'Landing summary reports the same observed MAE as detailed evidence');

$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('demo_import_metadata', '{\"source_label\":\"S001\"}')");
$demo28 = $forecastService->getPredictionPerformanceEvidence('2026-09-30');
$assert($demo28['status'] !== 'genuine_evidence', '28 demo target dates never become genuine Store evidence');
$pdo->exec("DELETE FROM platform_settings WHERE setting_key = 'demo_import_metadata'");
$pdo->exec("INSERT INTO products (product_id, sku, product_name) VALUES (2, 'SKU-002', 'Unobserved Product');
    INSERT INTO demand_forecasts (job_id, product_id, horizon_day, target_date, predicted_units, forecast_origin, model_version)
        VALUES (1, 2, 1, '2026-09-02', 10, '2026-09-01', 'rf_store_v1')");
$partialProducts = $forecastService->getPredictionPerformanceEvidence('2026-09-30');
$assert($partialProducts['status'] !== 'genuine_evidence', '28 dates for one Product cannot qualify an unobserved Product');
$pdo->exec('DELETE FROM demand_forecasts WHERE product_id = 2');

// -------------------------------------------------------------
// Test 9: Public evaluation boundary on CandidateEvaluationService
// -------------------------------------------------------------
echo "\n--- Test 9: Public evaluation boundary on CandidateEvaluationService ---\n";
$evalService = new CandidateEvaluationService($pdo);
$candidateEvidence = $evalService->getPredictionPerformanceEvidence($pdo, 'rf_store_v1', '2026-09-30');
$assert($candidateEvidence['status'] === 'genuine_evidence', "CandidateEvaluationService returns genuine evidence");
$assert($candidateEvidence['metrics_by_horizon'][28]['mae'] === 2.0, "CandidateEvaluationService computes matching 28d MAE");

if ($failures) {
    echo "\n========================================================\n";
    echo "FAIL: " . count($failures) . " check(s) failed in Ticket #414 Contract Test:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    echo "========================================================\n";
    exit(1);
}

echo "\n========================================================\n";
echo "PASS: Ticket #414 Prediction-Performance Evidence Contract passed cleanly!\n";
echo "========================================================\n";
exit(0);
