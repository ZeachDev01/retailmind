<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SystemHealthService.php';

use App\Forecasting\ForecastingSchedulerService;

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

if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Database/MigrationRunner.php';

echo "Testing App\\Forecasting\\ForecastingSchedulerService domain seam...\n";

$runner = new \App\Database\MigrationRunner($pdo, __DIR__ . '/../database/migrations');
$runner->runPending();

$pdo->exec("DELETE FROM ml_jobs");
$pdo->exec("DELETE FROM ml_settings WHERE setting_key IN ('schedule_daily_forecast_time', 'schedule_weekly_retrain_day', 'schedule_weekly_retrain_time')");
$pdo->exec("INSERT INTO ml_settings (setting_key, setting_value) VALUES ('schedule_daily_forecast_time', '23:00')");
$pdo->exec("INSERT INTO ml_settings (setting_key, setting_value) VALUES ('schedule_weekly_retrain_day', 'Sunday')");
$pdo->exec("INSERT INTO ml_settings (setting_key, setting_value) VALUES ('schedule_weekly_retrain_time', '01:00')");

$service = new ForecastingSchedulerService($pdo);

// Test 1: Given a time before the schedule, it does not queue jobs
$notDueTime = new DateTime('2026-10-09 22:00:00'); // Friday 22:00
$result = $service->runScheduler($notDueTime);
assert_same(0, $result['forecast_jobs_queued'], 'Should not queue forecast before 23:00');
assert_same(0, $result['retrain_jobs_queued'], 'Should not queue retrain on Friday');

// Test 2: Given a time after daily schedule, it queues a forecast job once (idempotent)
$dueTime = new DateTime('2026-10-09 23:05:00'); // Friday 23:05
$result = $service->runScheduler($dueTime);
assert_same(1, $result['forecast_jobs_queued'], 'Should queue forecast at 23:05');

// Second tick should not duplicate
$result2 = $service->runScheduler($dueTime);
assert_same(0, $result2['forecast_jobs_queued'], 'Should not queue duplicate forecast job');

// Test 3: Weekly retrain logic
$sundayTime = new DateTime('2026-10-11 01:10:00'); // Sunday 01:10
$result = $service->runScheduler($sundayTime);
assert_same(1, $result['retrain_jobs_queued'], 'Should queue retrain on Sunday at 01:10');

// Job identity should be attributed to scheduler
$stmt = $pdo->query("SELECT * FROM ml_jobs WHERE trigger_type = 'model_retraining' ORDER BY job_id DESC LIMIT 1");
$job = $stmt->fetch(PDO::FETCH_ASSOC);
assert_true($job !== false, 'Job should exist');
assert_true($job['requester_id'] === null, 'Requester should be system (NULL)');

// Health Status Test
$healthService = new \SystemHealthService($pdo, dirname(__DIR__, 2));
$checks = $healthService->checks();
$workerCheck = null;
foreach ($checks as $c) {
    if ($c['name'] === 'Background Worker') {
        $workerCheck = $c;
        break;
    }
}
assert_true($workerCheck !== null, 'Background Worker check must exist');
assert_same('healthy', $workerCheck['status'], 'Status should be healthy when processing jobs');

echo "Domain seam tests passed.\n";
