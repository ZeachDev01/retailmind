<?php
// Requires a disposable MySQL database loaded with src/backend/sql/schema.sql.
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Database integration tests: skipped (set RUN_DB_TESTS=1)\n";
    exit(0);
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';

use App\Database\Schema;
use App\Services\MlJobService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(Schema::tableExists($pdo, 'ml_jobs'), 'ml_jobs table is missing');
$assert(Schema::columnExists($pdo, 'ml_jobs', 'lease_expires_at'), 'ml_jobs.lease_expires_at is missing');

$service = new MlJobService($pdo);

// Test Authorization
try {
    $service->enqueue('forecast_generation', 1, 'cashier');
    $assert(false, 'Cashier should not be able to enqueue forecast_generation');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'Unauthorized'), 'Expected auth error for cashier');
}

try {
    $service->enqueue('model_retraining', 1, 'admin');
    $assert(false, 'Admin should not be able to enqueue model_retraining');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'Unauthorized'), 'Expected auth error for admin');
}

$pdo->exec("TRUNCATE TABLE ml_jobs");
$pdo->exec("TRUNCATE TABLE activity_log");
// We need a dummy user with ID=1 to satisfy foreign keys
$pdo->exec("INSERT IGNORE INTO users (user_id, username, password_hash, full_name, role_id) VALUES (1, 'test', 'hash', 'Test User', 1)");


// Test Enqueue
$jobId = $service->enqueue('forecast_generation', 1, 'super_admin');
$assert($jobId > 0, 'Job should be enqueued');

$stmt = $pdo->query("SELECT * FROM activity_log WHERE record_id = $jobId");
$log = $stmt->fetch(PDO::FETCH_ASSOC);
$assert($log !== false, 'Audit log should be recorded');
$assert($log['action'] === 'Enqueued ML Job', 'Correct audit log action');

$status = $service->getJobStatus($jobId);
$assert($status['status'] === 'queued', 'Initial status should be queued');

// Python worker is tested via subprocess
$dbName = getenv('DB_NAME') ?: 'retailmind_test';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';

$env = [
    'DB_NAME' => $dbName,
    'DB_USER' => $dbUser,
    'DB_PASS' => $dbPass,
    'DB_HOST' => $dbHost,
    'SYSTEMROOT' => getenv('SYSTEMROOT'),
    'PATH' => getenv('PATH'),
    'APPDATA' => getenv('APPDATA'),
    'LOCALAPPDATA' => getenv('LOCALAPPDATA'),
    'USERPROFILE' => getenv('USERPROFILE')
];

// Run worker
$workerPath = __DIR__ . '/../scripts/ml_worker.py';
$descriptorSpec = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"],
];

$process = proc_open('python ' . escapeshellarg($workerPath) . ' --run-once', $descriptorSpec, $pipes, null, $env);
if (is_resource($process)) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

$status = $service->getJobStatus($jobId);
$assert($status['status'] === 'succeeded', 'Worker should process job successfully. Stdout: ' . $stdout . ' Stderr: ' . $stderr);

// Test lease expiry and retry
$pdo->exec("INSERT INTO ml_jobs (trigger_type, status, requester_id, queued_at, lease_expires_at, attempts) VALUES ('model_retraining', 'running', 1, NOW(), DATE_SUB(NOW(), INTERVAL 1 HOUR), 1)");
$jobId2 = (int)$pdo->lastInsertId();

$process = proc_open('python ' . escapeshellarg($workerPath) . ' --run-once', $descriptorSpec, $pipes, null, $env);
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

$status2 = $service->getJobStatus($jobId2);
$assert($status2['status'] === 'succeeded', 'Worker should claim and process expired lease job');

if (!empty($failures)) {
    echo "FAILURES:\n" . implode("\n", $failures) . "\n";
    exit(1);
}

echo "OK\n";
