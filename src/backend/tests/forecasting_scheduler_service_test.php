<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Forecasting\ForecastingSchedulerService;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$clock = '2024-05-20 23:05:00';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW', static function () use (&$clock) { return $clock; });
$pdo->exec("CREATE TABLE products (product_id INT, status TEXT);
    CREATE TABLE forecast_sales_imports (product_id INT, sale_date TEXT, quantity INT);
    CREATE TABLE sales (sale_id INT, sale_date TEXT);
    CREATE TABLE sale_items (sale_item_id INT, sale_id INT, product_id INT, quantity INT);
    CREATE TABLE stock_movements (product_id INT, change_qty INT, moved_at TEXT);
    CREATE TABLE store_calendar (calendar_id INTEGER PRIMARY KEY, calendar_date TEXT, status TEXT, recorded_at TEXT);
    CREATE TABLE cash_refunds (refund_id INT, created_at TEXT);
    CREATE TABLE cash_refund_items (refund_id INT, sale_item_id INT, product_id INT, quantity INT);
    CREATE TABLE sale_reversals (reversal_id INT, status TEXT, approved_at TEXT);
    CREATE TABLE sale_reversal_items (reversal_id INT, sale_item_id INT, product_id INT, quantity INT);
    CREATE TABLE platform_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE model_training_runs (training_run_id INTEGER PRIMARY KEY, status TEXT, metrics_json TEXT);
    CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, requester_id INT, queued_at TEXT);
    CREATE TABLE activity_log (user_id INT, action TEXT, category TEXT, module TEXT, record_id INT, new_value TEXT, ip_address TEXT, created_at TEXT);
    INSERT INTO products VALUES (1, 'active');
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

echo "Forecasting scheduler: passed (first eligible training, weekly new history, duplicate prevention, Demo isolation, approval unchanged).\n";
