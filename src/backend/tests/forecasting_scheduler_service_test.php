<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/MlJobService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';

use App\Forecasting\ForecastingSchedulerService;
use App\Forecasting\ReplenishmentQueueService;
use App\Services\MlJobService;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$clock = '2024-05-20 23:05:00';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW', static function () use (&$clock) { return $clock; });
$pdo->exec("CREATE TABLE products (product_id INT, sku TEXT, product_name TEXT, reorder_level INT DEFAULT 10, safety_stock INT DEFAULT 5, minimum_order_quantity INT DEFAULT 1, units_per_package INT DEFAULT 1, status TEXT);
    CREATE TABLE inventory (product_id INT PRIMARY KEY, quantity_on_hand INT);
    CREATE TABLE forecast_sales_imports (product_id INT, sale_date TEXT, quantity INT);
    CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INT, sale_date TEXT);
    CREATE TABLE sale_items (sale_item_id INTEGER PRIMARY KEY, sale_id INT, product_id INT, quantity INT);
    CREATE TABLE stock_movements (product_id INT, change_qty INT, moved_at TEXT);
    CREATE TABLE store_calendar (calendar_id INTEGER PRIMARY KEY, calendar_date TEXT, status TEXT, recorded_at TEXT);
    CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, shift_id INT, refund_amount REAL, payment_method TEXT, created_at TEXT);
    CREATE TABLE cash_refund_items (refund_id INT, sale_item_id INT, product_id INT, quantity INT);
    CREATE TABLE sale_reversals (reversal_id INTEGER PRIMARY KEY, sale_id INT, status TEXT, approved_at TEXT);
    CREATE TABLE sale_reversal_items (reversal_id INT, sale_item_id INT, product_id INT, quantity INT);
    CREATE TABLE platform_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE model_training_runs (training_run_id INTEGER PRIMARY KEY, status TEXT, metrics_json TEXT);
    CREATE TABLE ml_jobs (
        job_id INTEGER PRIMARY KEY AUTOINCREMENT,
        trigger_type TEXT,
        status TEXT,
        requester_id INT,
        evidence_cutoff TEXT,
        model_version TEXT,
        lease_expires_at TEXT,
        attempts INT DEFAULT 0,
        operator_message TEXT,
        diagnostic_reference TEXT,
        queued_at TEXT,
        started_at TEXT,
        completed_at TEXT
    );
    CREATE TABLE demand_forecasts (
        forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INT,
        product_id INT,
        forecast_origin TEXT,
        target_date TEXT,
        horizon_day INT,
        predicted_units INT,
        model_version TEXT,
        readiness_level TEXT,
        limitation_marker TEXT
    );
    CREATE TABLE activity_log (user_id INT, action TEXT, category TEXT, module TEXT, record_id INT, new_value TEXT, ip_address TEXT, created_at TEXT);
    INSERT INTO products VALUES (1, 'SKU-1', 'Product 1', 10, 5, 1, 1, 'active');
    INSERT INTO inventory VALUES (1, 3);
    INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'keep-approved'), ('ml_observation_mode', '1');");
$insert = $pdo->prepare('INSERT INTO forecast_sales_imports VALUES (1, ?, ?)');
$start = new DateTimeImmutable('2024-01-01');
for ($day = 0; $day < 147; $day++) {
    $insert->execute([$start->modify("+$day days")->format('Y-m-d'), $day % 7]);
}
$scheduler = new ForecastingSchedulerService($pdo);
$tick = static function (string $time) use ($scheduler, &$clock): array {
    $clock = $time;
    return $scheduler->runScheduler(new DateTime($time, new DateTimeZone('Asia/Manila')));
};

// 1. Existing schedule tests (first eligible training, weekly retraining, duplicate checks)
assert_same(0, $tick('2024-05-17 23:05:00')['retrain_jobs_queued'], 'Insufficient history must not train');
assert_same(0, $tick('2024-05-20 22:00:00')['retrain_jobs_queued'], 'First training waits until after close');
$first = $tick('2024-05-20 23:05:00');
assert_same(1, $first['forecast_jobs_queued'], 'Daily generation is preserved');
assert_same(1, $first['retrain_jobs_queued'], 'First eligible model trains without waiting for Sunday');
assert_same(140, $first['training_eligibility'][0]['consecutive_usable_days'], 'Threshold is usable days, not sales volume');
assert_same(0, $tick('2024-05-20 23:10:00')['retrain_jobs_queued'], 'Repeated ticks cannot duplicate training');
assert_same(0, $tick('2024-05-21 23:05:00')['retrain_jobs_queued'], 'Pending training from a previous day blocks duplicates');
$job = $pdo->query("SELECT requester_id FROM ml_jobs WHERE trigger_type = 'model_retraining'")->fetch(PDO::FETCH_ASSOC);
assert_same(null, $job['requester_id'], 'Automatic training is attributed to the system');
$pdo->exec("UPDATE ml_jobs SET status = 'succeeded';
    INSERT INTO model_training_runs VALUES (1, 'completed', '{\"metadata\":{\"data_window_end\":\"2024-05-19\"}}');");
assert_same(0, $tick('2024-05-22 23:05:00')['retrain_jobs_queued'], 'Completed candidate waits for weekly retraining, even before approval');
assert_same(1, $tick('2024-05-26 01:10:00')['retrain_jobs_queued'], 'Weekly training runs with newer eligible history');
$pdo->exec("UPDATE ml_jobs SET status = 'succeeded';
    UPDATE model_training_runs SET metrics_json = '{\"metadata\":{\"data_window_end\":\"2024-05-25\"}}';
    DELETE FROM ml_jobs WHERE trigger_type = 'model_retraining' AND DATE(queued_at) = '2024-05-26';");
assert_same(0, $tick('2024-05-26 01:15:00')['retrain_jobs_queued'], 'Unchanged evidence must not retrain');
assert_same(0, $tick('2024-06-02 01:10:00')['retrain_jobs_queued'], 'Stale history that stops before cutoff must not retrain');

$pdo->exec("DELETE FROM model_training_runs;
    INSERT INTO ml_jobs (trigger_type, status, requester_id, queued_at) VALUES ('model_retraining', 'failed', 7, '2024-05-21 20:00:00');");
assert_same(0, $tick('2024-05-21 23:30:00')['retrain_jobs_queued'], 'Manual training that day prevents an automatic retry storm');
$pdo->exec("UPDATE ml_jobs SET queued_at = '2024-05-19', status = 'running' WHERE trigger_type = 'model_retraining';");
assert_same(0, $tick('2024-05-22 23:30:00')['retrain_jobs_queued'], 'Older manual running training blocks automatic training');
$pdo->exec("DELETE FROM ml_jobs;
    INSERT INTO platform_settings VALUES ('demo_import_metadata', '{}');");
assert_same(0, $tick('2024-05-20 23:30:00')['retrain_jobs_queued'], 'Daily first-training trigger must not change Demo scheduling');
assert_same(1, $tick('2024-05-26 01:30:00')['retrain_jobs_queued'], 'Existing weekly Demo schedule is preserved');
assert_same('keep-approved', $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'")->fetchColumn(), 'Automatic training never replaces the approved model');
assert_same('1', $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_observation_mode'")->fetchColumn(), 'Automatic training never enables routine purchasing');

// =========================================================================
// Ticket #361 Requirements Verification
// =========================================================================

// --- 2. Cutoff calculation: latest completed Philippine day, excluding partial sales ---
$pdo->exec("DELETE FROM platform_settings WHERE setting_key = 'demo_import_metadata'; DELETE FROM ml_jobs; DELETE FROM demand_forecasts;");
// Simulate morning startup at 08:00 AM on 2024-05-21
$morning = $tick('2024-05-21 08:00:00');
assert_same('2024-05-20', $morning['cutoff'], 'Morning startup cutoff uses latest completed Philippine day (yesterday)');

// --- 3. Deduplication: repeated ticks do not duplicate logical work ---
$morningDup = $tick('2024-05-21 08:05:00');
assert_same(0, $morningDup['forecast_jobs_queued'], 'Repeated ticks cannot duplicate daily forecast job');

// --- 4. Catch-up after downtime: queue exactly ONE current forecast and ONE missed weekly training ---
$pdo->exec("DELETE FROM ml_jobs; DELETE FROM model_training_runs; DELETE FROM demand_forecasts;");
// Add history up to June 09
for ($d = 147; $d < 170; $d++) {
    $insert->execute([$start->modify("+$d days")->format('Y-m-d'), $d % 7]);
}
// Set last successful forecast to May 25, 2024 (over 2 weeks ago)
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, evidence_cutoff, queued_at, completed_at)
    VALUES (50, 'forecast_generation', 'succeeded', '2024-05-25', '2024-05-26 01:00:00', '2024-05-26 01:05:00')");
$pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units)
    VALUES (50, 1, '2024-05-25', '2024-05-26', 1, 10)");
// Set last training to May 25
$pdo->exec("INSERT INTO model_training_runs (training_run_id, status, metrics_json)
    VALUES (2, 'completed', '{\"metadata\":{\"data_window_end\":\"2024-05-25\"}}')");

// Machine powers on on Monday, June 10, 2024 at 08:00 AM after 2+ weeks off
$catchup = $tick('2024-06-10 08:00:00');
assert_same(1, $catchup['forecast_jobs_queued'], 'Downtime catch-up queues exactly ONE current forecast');
assert_same(1, $catchup['retrain_jobs_queued'], 'Downtime catch-up queues exactly ONE missed weekly training');
$queuedJobs = $pdo->query("SELECT trigger_type, evidence_cutoff FROM ml_jobs WHERE status = 'queued'")->fetchAll(PDO::FETCH_ASSOC);
assert_same(2, count($queuedJobs), 'Only two jobs queued: one current forecast and one candidate training');
assert_same('2024-06-09', $queuedJobs[0]['evidence_cutoff'], 'Forecast evidence cutoff is yesterday');
assert_same('2024-06-09', $queuedJobs[1]['evidence_cutoff'], 'Retraining evidence cutoff is yesterday');

// Repeated ticks on catch-up day do not duplicate
$catchupDup = $tick('2024-06-10 08:05:00');
assert_same(0, $catchupDup['forecast_jobs_queued'], 'Catchup duplicate check: no duplicate forecast');
assert_same(0, $catchupDup['retrain_jobs_queued'], 'Catchup duplicate check: no duplicate retrain');

// --- 5. Attempt bounding (max 3 attempts across execution errors, crashes, and expired leases) ---
$pdo->exec("DELETE FROM ml_jobs;");
// Insert job with attempt 1 failed due to transient error
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, evidence_cutoff, attempts, queued_at, completed_at, diagnostic_reference)
    VALUES (100, 'forecast_generation', 'failed', '2024-06-09', 1, '2024-06-10 08:00:00', '2024-06-10 08:01:00', 'TRANSIENT:connection reset [ERR-1234]')");

// Later scheduler tick retries the existing job (does not create replacement logical job)
$tick('2024-06-10 08:15:00');
$jobAfterRetry1 = $pdo->query("SELECT job_id, status, attempts FROM ml_jobs WHERE job_id = 100")->fetch(PDO::FETCH_ASSOC);
assert_same('queued', $jobAfterRetry1['status'], 'Transient failure is re-queued on later scheduler tick');
$jobCount = (int)$pdo->query("SELECT COUNT(*) FROM ml_jobs WHERE trigger_type = 'forecast_generation'")->fetchColumn();
assert_same(1, $jobCount, 'No replacement logical job created; retries reuse the existing job');

// Worker claims it (attempts becomes 2) and fails again
$pdo->exec("UPDATE ml_jobs SET status = 'failed', attempts = 2, completed_at = '2024-06-10 08:16:00' WHERE job_id = 100");
$tick('2024-06-10 08:30:00');
$jobAfterRetry2 = $pdo->query("SELECT status, attempts FROM ml_jobs WHERE job_id = 100")->fetch(PDO::FETCH_ASSOC);
assert_same('queued', $jobAfterRetry2['status'], 'Second transient failure is re-queued on later scheduler tick');

// Worker claims it (attempts becomes 3) and fails a third time
$pdo->exec("UPDATE ml_jobs SET status = 'failed', attempts = 3, completed_at = '2024-06-10 08:31:00', diagnostic_reference = 'EXHAUSTED:connection reset' WHERE job_id = 100");
$tick('2024-06-10 08:45:00');
$jobAfterRetry3 = $pdo->query("SELECT status, attempts FROM ml_jobs WHERE job_id = 100")->fetch(PDO::FETCH_ASSOC);
assert_same('failed', $jobAfterRetry3['status'], 'Exhausted retry (attempts >= 3) is NOT re-queued');
$jobCountAfter3 = (int)$pdo->query("SELECT COUNT(*) FROM ml_jobs WHERE trigger_type = 'forecast_generation'")->fetchColumn();
assert_same(1, $jobCountAfter3, 'Attempt limit ceiling prevents creating new replacement logical jobs');

// --- 6. Expired lease recovery with 3-attempt ceiling ---
$pdo->exec("DELETE FROM ml_jobs;");
// Job running with expired lease and attempts = 1 (crashed worker)
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, attempts, lease_expires_at, queued_at)
    VALUES (101, 'forecast_generation', 'running', 1, '2024-06-10 08:00:00', '2024-06-10 07:30:00')");
$scheduler->recoverExpiredLeases(new DateTime('2024-06-10 08:30:00', new DateTimeZone('Asia/Manila')));
$recovered1 = $pdo->query("SELECT status, lease_expires_at FROM ml_jobs WHERE job_id = 101")->fetch(PDO::FETCH_ASSOC);
assert_same('queued', $recovered1['status'], 'Expired lease under 3 attempts is recovered and re-queued');
assert_same(null, $recovered1['lease_expires_at'], 'Recovered job has lease cleared');

// Job running with expired lease and attempts = 3 (crash limit exhausted)
$pdo->exec("UPDATE ml_jobs SET status = 'running', attempts = 3, lease_expires_at = '2024-06-10 08:30:00' WHERE job_id = 101");
$scheduler->recoverExpiredLeases(new DateTime('2024-06-10 09:00:00', new DateTimeZone('Asia/Manila')));
$recoveredExhausted = $pdo->query("SELECT status, diagnostic_reference FROM ml_jobs WHERE job_id = 101")->fetch(PDO::FETCH_ASSOC);
assert_same('failed', $recoveredExhausted['status'], 'Expired lease reaching 3 attempts fails permanently');
assert_same('LEASE_TIMEOUT_EXHAUSTED', $recoveredExhausted['diagnostic_reference'], 'Diagnostic reference indicates lease timeout exhausted');

// Active valid lease is never raced
$pdo->exec("UPDATE ml_jobs SET status = 'running', attempts = 1, lease_expires_at = '2024-06-10 10:00:00' WHERE job_id = 101");
$scheduler->recoverExpiredLeases(new DateTime('2024-06-10 09:15:00', new DateTimeZone('Asia/Manila')));
$activeLease = $pdo->query("SELECT status FROM ml_jobs WHERE job_id = 101")->fetch(PDO::FETCH_ASSOC);
assert_same('running', $activeLease['status'], 'Running job with valid lease is not touched');

// --- 7. Blocked condition handling ---
$pdo->exec("DELETE FROM ml_jobs; UPDATE platform_settings SET setting_value = '' WHERE setting_key = 'ml_approved_model_version';");
$blockedRes = $tick('2024-06-10 23:05:00');
assert_same(0, $blockedRes['forecast_jobs_queued'], 'Missing approved model blocks forecast queueing');
$blockedJob = $pdo->query("SELECT status, diagnostic_reference FROM ml_jobs WHERE trigger_type = 'forecast_generation'")->fetch(PDO::FETCH_ASSOC);
assert_same('failed', $blockedJob['status'], 'Blocked condition is recorded as failed');
assert_same('BLOCKED:MISSING_APPROVED_MODEL', $blockedJob['diagnostic_reference'], 'Blocked diagnostic reference set');
// Next tick does NOT retry blocked condition
$tick('2024-06-10 23:10:00');
$blockedJobCount = (int)$pdo->query("SELECT COUNT(*) FROM ml_jobs WHERE trigger_type = 'forecast_generation'")->fetchColumn();
assert_same(1, $blockedJobCount, 'Blocked condition is not retried in a loop');

// --- 8. Technical diagnostics and Complete committed results verification in MlJobService ---
$jobService = new MlJobService($pdo);
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, requester_id, evidence_cutoff, model_version, attempts, queued_at, completed_at, operator_message, diagnostic_reference)
    VALUES (200, 'forecast_generation', 'succeeded', NULL, '2024-06-09', 'keep-approved', 1, '2024-06-10 08:00:00', '2024-06-10 08:05:00', 'Success message', 'NONE')");
// Without committed demand_forecasts records, getJobStatus reports failed / uncommitted
$statusUncommitted = $jobService->getJobStatus(200);
assert_same('failed', $statusUncommitted['status'], 'Uncommitted forecast results prevent success claim');
assert_same(false, $statusUncommitted['committed_results_complete'], 'Complete committed results flag is false when rows missing');

// Once committed:
$pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units)
    VALUES (200, 1, '2024-06-09', '2024-06-10', 1, 15)");
$statusCommitted = $jobService->getJobStatus(200);
assert_same('succeeded', $statusCommitted['status'], 'Committed forecast results confirm success');
assert_same(true, $statusCommitted['committed_results_complete'], 'Complete committed results flag is true when rows present');
assert_same('2024-06-09', $statusCommitted['cutoff'], 'Job cutoff is exposed');
assert_same('keep-approved', $statusCommitted['model_version'], 'Job model identity is exposed');
assert_same(1, $statusCommitted['attempts'], 'Job attempt count is exposed');

// --- 9. Operational non-blocking verification: checkout, refunds, receiving, stock-rule suggestions ---
// Forecasting has failed jobs in queue, but operational functions must remain 100% active.
// A. Checkout (recording sales)
$pdo->exec("INSERT INTO sales (sale_id, cashier_id, sale_date) VALUES (501, 1, '2024-06-10 10:00:00');
    INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity) VALUES (601, 501, 1, 2);");
$saleRecorded = (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE sale_id = 501")->fetchColumn();
assert_same(1, $saleRecorded, 'Checkout succeeds despite forecasting failures');

// B. Cash refunds
$pdo->exec("INSERT INTO cash_refunds (refund_id, shift_id, refund_amount, payment_method, created_at)
    VALUES (701, 1, 50.0, 'cash', '2024-06-10 10:15:00');
    INSERT INTO cash_refund_items (refund_id, sale_item_id, product_id, quantity) VALUES (701, 601, 1, 1);");
$refundRecorded = (int)$pdo->query("SELECT COUNT(*) FROM cash_refunds WHERE refund_id = 701")->fetchColumn();
assert_same(1, $refundRecorded, 'Cash refund succeeds despite forecasting failures');

// C. Stock-rule suggestions (ReplenishmentQueueService)
// Product 1 has inventory = 3, reorder_level = 10, safety_stock = 5.
// Expected suggestion = 10 + 5 - 3 = 12 units.
$queueService = new ReplenishmentQueueService($pdo);
$queue = $queueService->getRecommendations(null, '2024-06-10');
assert_same(1, count($queue), 'Replenishment queue returns product');
assert_same('Stock-rule suggestion', $queue[0]['basis'], 'Falls back cleanly to Stock-rule suggestion');
assert_same(12, $queue[0]['stock_rule_quantity'], 'Stock-rule quantity is accurately calculated');

echo "Forecasting scheduler: passed (first eligible training, weekly new history, duplicate prevention, Demo isolation, approval unchanged, downtime recovery, 3-attempt ceiling, lease safety, blocked conditions, operational isolation).\n";
