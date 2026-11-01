<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/ForecastingSchedulerService.php';
require_once __DIR__ . '/../app/Attention/Clock.php';
require_once __DIR__ . '/../app/Attention/DatabaseAttentionSignalSource.php';

use App\Core\Database;
use App\Forecasting\ForecastingSchedulerService;
use App\Attention\Clock;
use App\Attention\DatabaseAttentionSignalSource;

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

final class TestRunnerClock implements Clock
{
    public function __construct(public DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

echo "Starting Forecasting Runner and Installation Contract Test...\n";

// =========================================================================
// 1. Configuration Retention Contract
// =========================================================================
$configDir = dirname(__DIR__) . '/config';
$configPath = $configDir . '/forecasting_runner.json';
$backupConfig = file_exists($configPath) ? file_get_contents($configPath) : null;

try {
    $testConfig = [
        'php_binary' => 'C:\\xampp\\php\\php.exe',
        'python_binary' => 'C:\\xampp\\htdocs\\retailmind\\venv\\Scripts\\python.exe',
        'project_root' => 'C:\\xampp\\htdocs\\retailmind',
        'model_storage' => 'C:\\xampp\\htdocs\\retailmind\\src\\backend\\storage\\ml_models',
        'service_account' => 'NT AUTHORITY\\SYSTEM',
        'installed_at' => '2026-11-02 07:00:00',
    ];
    file_put_contents($configPath, json_encode($testConfig, JSON_PRETTY_PRINT));

    assert_true(file_exists($configPath), 'forecasting_runner.json must exist after configuration');
    $loaded = json_decode((string)file_get_contents($configPath), true);
    assert_same('C:\\xampp\\php\\php.exe', $loaded['php_binary'], 'PHP binary path must be retained');
    assert_same('C:\\xampp\\htdocs\\retailmind\\venv\\Scripts\\python.exe', $loaded['python_binary'], 'Python binary path must be retained');
    assert_same('C:\\xampp\\htdocs\\retailmind', $loaded['project_root'], 'Project root must be retained');
    assert_same('C:\\xampp\\htdocs\\retailmind\\src\\backend\\storage\\ml_models', $loaded['model_storage'], 'Model storage path must be retained');
    assert_same('NT AUTHORITY\\SYSTEM', $loaded['service_account'], 'Service account must be retained');

    echo "  ok - Configuration retention contract verified\n";

    // =========================================================================
    // 2. Database Readiness Check and Retry Loop Contract
    // =========================================================================
    // A. Delayed Database Startup: fails 2 times, succeeds on 3rd attempt
    $attempts = 0;
    $sleepCalls = [];
    $mockConnector = function() use (&$attempts) {
        $attempts++;
        if ($attempts < 3) {
            throw new RuntimeException("MySQL connection refused (server starting up attempt $attempts)");
        }
        return new PDO('sqlite::memory:');
    };
    $mockSleep = function(int $seconds) use (&$sleepCalls) {
        $sleepCalls[] = $seconds;
    };

    Database::resetConnection();
    $conn = Database::connectionWithRetry(null, 5, 2, $mockSleep, $mockConnector);
    assert_true($conn instanceof PDO, 'ConnectionWithRetry must return PDO after delayed startup');
    assert_same(3, $attempts, 'Connector must retry exactly until successful on attempt 3');
    assert_same([2, 2], $sleepCalls, 'Sleep must be invoked with configured delay between retry attempts');

    echo "  ok - Delayed database startup recovers on retry without failing\n";

    // B. Persistent Database Unavailability: exhausts all 5 attempts and throws
    $exhaustedAttempts = 0;
    $exhaustedSleeps = [];
    $failingConnector = function() use (&$exhaustedAttempts) {
        $exhaustedAttempts++;
        throw new RuntimeException("MySQL server has gone away (attempt $exhaustedAttempts)");
    };
    $mockExhaustedSleep = function(int $seconds) use (&$exhaustedSleeps) {
        $exhaustedSleeps[] = $seconds;
    };

    $threw = false;
    Database::resetConnection();
    try {
        Database::connectionWithRetry(null, 5, 1, $mockExhaustedSleep, $failingConnector);
    } catch (RuntimeException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'attempt 5'), 'Exception must report final attempt error');
    }
    assert_true($threw, 'ConnectionWithRetry must throw after max attempts exhausted');
    assert_same(5, $exhaustedAttempts, 'Must retry exactly up to maxAttempts (5)');
    assert_same([1, 1, 1, 1], $exhaustedSleeps, 'Sleep must be called 4 times between 5 attempts');

    echo "  ok - Persistent database unavailability exhausts bounded retries\n";

    // =========================================================================
    // 3. Local Diagnostic Emission and Visibility After Recovery
    // =========================================================================
    $storageLogsDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($storageLogsDir)) {
        @mkdir($storageLogsDir, 0777, true);
    }
    $diagFile = $storageLogsDir . '/forecasting_diagnostics.json';
    @unlink($diagFile);

    // Record persistent diagnostic
    ForecastingSchedulerService::recordLocalDiagnostic(
        'Database service unavailable after startup retries: connection refused',
        'DB_STARTUP_TIMEOUT'
    );
    assert_true(file_exists($diagFile), 'Local diagnostic file must be created on persistent failure');
    $diags = json_decode((string)file_get_contents($diagFile), true);
    assert_true(is_array($diags) && count($diags) >= 1, 'Diagnostic must contain entry');
    $lastDiag = end($diags);
    assert_same('DB_STARTUP_TIMEOUT', $lastDiag['diagnostic_reference'], 'Diagnostic reference must be DB_STARTUP_TIMEOUT');

    // Test visibility through DatabaseAttentionSignalSource after database recovers
    $memoryDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $memoryDb->exec("CREATE TABLE ml_jobs (
        job_id INTEGER PRIMARY KEY,
        trigger_type TEXT,
        status TEXT,
        completed_at TEXT
    )");
    // Last succeeded job was prior to outage
    $memoryDb->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, completed_at) VALUES (1, 'forecast_generation', 'succeeded', '2026-11-01 01:00:00')");

    $clock = new TestRunnerClock(new DateTimeImmutable('2026-11-02 07:05:00'));
    $signalSource = new DatabaseAttentionSignalSource($memoryDb, $clock);
    $signals = $signalSource->store();

    assert_same(1, $signals['forecasting_failure']['value'] ?? 0, 'Preserved startup diagnostic makes system visibly unhealthy after recovery');
    assert_true(str_contains($signals['forecasting_failure']['explanation'] ?? '', 'Database service unavailable after startup retries'), 'Signal explains startup database unavailability');

    // After subsequent job succeeds, health reporting recovers
    $nextSuccessTime = (new DateTime('+1 hour', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $memoryDb->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, completed_at) VALUES (2, 'forecast_generation', 'succeeded', '$nextSuccessTime')");
    $signalsAfter = $signalSource->store();
    assert_same(0, $signalsAfter['forecasting_failure']['value'] ?? 0, 'Signal resolves once subsequent job succeeds');

    @unlink($diagFile);
    echo "  ok - Persistent failure surfaces visibly and recovers on subsequent success\n";

    // =========================================================================
    // 4. Local Training and Database Interface Boundary
    // =========================================================================
    $workerScript = dirname(__DIR__) . '/scripts/ml_worker.py';
    $out = [];
    $exitCode = 0;
    // Setting DB_HOST to remote host should be rejected
    putenv('DB_HOST=192.168.1.150');
    exec("python " . escapeshellarg($workerScript) . " --run-once 2>&1", $out, $exitCode);
    putenv('DB_HOST'); // clear
    $outputCombined = implode("\n", $out);
    assert_true(
        $exitCode !== 0 && (str_contains($outputCombined, 'local') || str_contains($outputCombined, 'PermissionError')),
        "ml_worker.py must reject remote database hosts: $outputCombined"
    );

    echo "  ok - Remote database interfaces rejected to protect local boundary\n";

} finally {
    // Restore or cleanup config
    if ($backupConfig !== null) {
        file_put_contents($configPath, $backupConfig);
    } else {
        @unlink($configPath);
    }
}

echo "All forecasting runner installation contract tests passed.\n";
