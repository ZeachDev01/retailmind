<?php
/**
 * RetailMind ML Scheduler Entry Point
 * Designed to be called by cron or Windows Task Scheduler every 1-5 minutes.
 */

require_once dirname(__DIR__) . '/bootstrap/app.php';

use App\Forecasting\ForecastingSchedulerService;

// Prevent HTTP execution, CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Forbidden: Scheduler must be run from the command line.");
}

$lock = fopen(dirname(__DIR__) . '/storage/logs/ml_scheduler.lock', 'c');
if ($lock === false) {
    throw new RuntimeException('Could not open the forecasting scheduler lock.');
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
$pdo = \App\Core\Database::connection();

$scheduler = new ForecastingSchedulerService($pdo);
$result = $scheduler->runScheduler();
foreach ($result['training_eligibility'] as $product) {
    if (!$product['eligible']) {
        echo 'Training skipped product ' . $product['product_id'] . ': ' . $product['reason'] . PHP_EOL;
    }
}

$queued = $result['forecast_jobs_queued'] + $result['retrain_jobs_queued'];

function triggerWorkerAsync(string $workerScript): void {
    if (!file_exists($workerScript)) return;
    $command = 'python ' . escapeshellarg($workerScript) . ' --run-once';
    echo "Triggering ml_worker.py asynchronously...\n";
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen('start /B "" ' . $command . ' > NUL 2>&1', 'r'));
    } else {
        exec($command . ' > /dev/null 2>&1 &');
    }
}

$workerScript = dirname(__DIR__) . '/scripts/ml_worker.py';
if ($queued > 0) {
    echo "Scheduler queued {$queued} jobs.\n";
    triggerWorkerAsync($workerScript);
} else {
    // Check for queued or stalled jobs needing worker execution
    $stmt = $pdo->query("SELECT COUNT(*) FROM ml_jobs WHERE status IN ('queued', 'running')");
    if ((int)$stmt->fetchColumn() > 0) {
        triggerWorkerAsync($workerScript);
    }
}
