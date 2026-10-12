<?php
/**
 * RetailMind ML Scheduler Entry Point
 * Designed to be called by cron or Windows Task Scheduler every 1-5 minutes.
 */

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/config/db.php';

use App\Forecasting\ForecastingSchedulerService;
use App\Services\MlJobService;

// Prevent HTTP execution, CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Forbidden: Scheduler must be run from the command line.");
}

$scheduler = new ForecastingSchedulerService($pdo);
$result = $scheduler->runScheduler();

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
