<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/SystemHealthService.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$backendPath = sys_get_temp_dir() . '/retailmind-health-artifact-' . bin2hex(random_bytes(6));
$artifactDir = $backendPath . '/storage/ml_models/store/0123456789abcdef';
mkdir($artifactDir, 0700, true);

$pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE platform_settings (setting_key TEXT, setting_value TEXT)');
if (method_exists($pdo, 'createFunction')) $pdo->createFunction('DATABASE', static fn(): string => 'retailmind');
else $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'retailmind');
$pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
$pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT)');

$artifactCheck = static function () use ($pdo, $backendPath): ?array {
    $checks = (new SystemHealthService($pdo, $backendPath))->checks();
    return array_values(array_filter(
        $checks,
        static fn(array $check): bool => $check['name'] === 'Forecast model artifact'
    ))[0] ?? null;
};

$missing = $artifactCheck();
$assert(is_array($missing), 'System Health must expose a Forecast model artifact check');
$assert($missing['status'] === 'info', 'Cold start must be informational without a model artifact');
$assert(str_contains($missing['detail'], 'Gathering sales data'), 'Cold start must explain why the model is not expected');
$pdo->exec('CREATE TABLE ml_jobs (job_id INTEGER, status TEXT, queued_at TEXT, completed_at TEXT, diagnostic_reference TEXT)');
$pdo->exec("INSERT INTO ml_jobs VALUES (1, 'failed', datetime('now'), NULL, 'BLOCKED:MISSING_APPROVED_MODEL')");
$checkByName = static function (string $name) use ($pdo, $backendPath): array {
    return array_values(array_filter((new SystemHealthService($pdo, $backendPath))->checks(),
        static fn(array $check): bool => $check['name'] === $name))[0];
};
$assert($checkByName('Background Worker')['status'] === 'info', 'Blocked cold-start work must not be reported as a failed worker');
$pdo->exec("UPDATE ml_jobs SET diagnostic_reference = 'EXHAUSTED_RETRIES'");
$assert($checkByName('Background Worker')['status'] === 'critical', 'A real worker failure must remain critical during cold start');
$pdo->exec("UPDATE ml_jobs SET status = 'completed', completed_at = datetime('now', '-10 days')");
$assert($checkByName('Background Worker')['status'] === 'healthy', 'Idle time after completed work is not evidence of a worker failure');
$migration = $checkByName('Database migrations');
$assert($migration['status'] === 'warning' && str_contains($migration['detail'], 'Setup incomplete'), 'Missing setup must be actionable without a critical failure label');
$pdo->exec('CREATE TABLE schema_migrations (migration_key TEXT)');
$pdo->exec("INSERT INTO information_schema.tables VALUES ('retailmind', 'schema_migrations')");
$assert($checkByName('Database migrations')['status'] === 'healthy', 'Empty initialized migration ledger with no pending migrations must be healthy');

$pdo->exec("INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'store-20261109')");
\App\Forecasting\ForecastSetupStatus::forget($pdo);
$assert($artifactCheck()['status'] === 'warning', 'An active model with missing storage must still require attention');
mkdir($backendPath . '/storage/ml_models/demo', 0700, true);
file_put_contents($backendPath . '/storage/ml_models/demo/demo.joblib', 'demo');
$assert($artifactCheck()['status'] === 'warning', 'A demo artifact must not cover missing Store model storage');

$artifact = $artifactDir . '/random_forest_candidate_store-20261109.joblib';
file_put_contents($artifact, 'candidate');
$nested = $artifactCheck();
$assert($nested['status'] === 'healthy', 'A nested per-dataset artifact must be reported healthy');
$assert(str_contains($nested['detail'], basename($artifact)), 'Health detail must name the latest artifact');

file_put_contents($artifactDir . '/notes.txt', 'ignored');
$assert($artifactCheck()['status'] === 'healthy', 'Non-artifact files must not break the check');

unlink($artifact);
unlink($artifactDir . '/notes.txt');
unlink($backendPath . '/storage/ml_models/demo/demo.joblib');
rmdir($backendPath . '/storage/ml_models/demo');
$empty = $artifactCheck();
$assert($empty['status'] === 'warning', 'Empty model storage must require attention');

rmdir($artifactDir);
rmdir($backendPath . '/storage/ml_models/store');
rmdir($backendPath . '/storage/ml_models');
rmdir($backendPath . '/storage');
rmdir($backendPath);

echo "System Health artifact contract: passed\n";
