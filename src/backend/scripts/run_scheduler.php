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

// Load retained configuration if present
$runnerConfigPath = dirname(__DIR__) . '/config/forecasting_runner.json';
$runnerConfig = [];
if (file_exists($runnerConfigPath)) {
    $runnerConfig = json_decode((string)file_get_contents($runnerConfigPath), true) ?: [];
}

try {
    $pdo = \App\Core\Database::connectionWithRetry();
} catch (\Throwable $e) {
    ForecastingSchedulerService::recordLocalDiagnostic(
        'Database service unavailable after startup retries: ' . $e->getMessage(),
        'DB_STARTUP_TIMEOUT'
    );
    fwrite(STDERR, "Forecasting scheduler: database unavailable after startup retries: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$scheduler = new ForecastingSchedulerService($pdo);
$result = $scheduler->runScheduler();
foreach ($result['training_eligibility'] as $product) {
    if (!$product['eligible']) {
        echo 'Training skipped product ' . $product['product_id'] . ': ' . $product['reason'] . PHP_EOL;
    }
}

$queued = $result['forecast_jobs_queued'] + $result['retrain_jobs_queued'];

function triggerWorkerAsync(string $workerScript, string $pythonBinary = 'python'): void {
    if (!file_exists($workerScript)) return;
    $command = escapeshellarg($pythonBinary) . ' ' . escapeshellarg($workerScript) . ' --run-once';
    echo "Triggering ml_worker.py asynchronously...\n";
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen('start /B "" ' . $command . ' > NUL 2>&1', 'r'));
    } else {
        exec($command . ' > /dev/null 2>&1 &');
    }
}

$pythonBinary = $runnerConfig['python_binary'] ?? 'python';
$workerScript = dirname(__DIR__) . '/scripts/ml_worker.py';
if ($queued > 0) {
    echo "Scheduler queued {$queued} jobs.\n";
    triggerWorkerAsync($workerScript, $pythonBinary);
} else {
    // Check for queued or stalled jobs needing worker execution
    $stmt = $pdo->query("SELECT COUNT(*) FROM ml_jobs WHERE status IN ('queued', 'running')");
    if ((int)$stmt->fetchColumn() > 0) {
        triggerWorkerAsync($workerScript, $pythonBinary);
    }
}
