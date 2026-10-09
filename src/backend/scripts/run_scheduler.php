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

if ($queued > 0) {
    echo "Scheduler queued {$queued} jobs.\n";
    // Now trigger the worker to process the queue
    $workerScript = dirname(__DIR__) . '/scripts/ml_worker.py';
    if (file_exists($workerScript)) {
        echo "Triggering ml_worker.py...\n";
        $command = escapeshellcmd('python ' . escapeshellarg($workerScript) . ' --run-once');
        exec($command . ' > /dev/null 2>&1 &');
    }
} else {
    // Optional: check if there are any jobs stuck in queued/running that need lease recovery,
    // and if so, trigger the worker. The ml_worker.py --run-once handles lease recovery internally.
    $stmt = $pdo->query("SELECT COUNT(*) FROM ml_jobs WHERE status IN ('queued', 'running')");
    if ((int)$stmt->fetchColumn() > 0) {
        $workerScript = dirname(__DIR__) . '/scripts/ml_worker.py';
        if (file_exists($workerScript)) {
            $command = escapeshellcmd('python ' . escapeshellarg($workerScript) . ' --run-once');
            exec($command . ' > /dev/null 2>&1 &');
        }
    }
}
