<?php
// Deterministic metadata/opaque model files test governance, not Python model inference.
function governance_fixture(PDO $pdo, App\Forecasting\StoreModelGovernanceService $governance, array $versions): void
{
    $_ENV['DB_HOST'] = '127.0.0.1';
    $_ENV['DB_PORT'] = '3306';
    $_ENV['DB_NAME'] = 'isolated_governance_fixture';
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
    $pdo->exec("CREATE TABLE IF NOT EXISTS model_training_runs (
        training_run_id INTEGER PRIMARY KEY, model_version TEXT, status TEXT, metrics_json TEXT);
        CREATE TABLE IF NOT EXISTS ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, requester_id INTEGER, queued_at TEXT)");
    $directory = sys_get_temp_dir() . '/retailmind-governance-' . bin2hex(random_bytes(6));
    $scoped = $directory . '/store/' . substr(hash('sha256', '127.0.0.1:3306:isolated_governance_fixture'), 0, 16);
    mkdir($scoped, 0777, true);
    $files = [];
    foreach ($versions as $version) {
        $metadata = ['model_version' => $version, 'dataset_kind' => 'store',
            'dataset_host' => '127.0.0.1', 'dataset_port' => '3306', 'dataset_database' => 'isolated_governance_fixture',
            'feature_version' => '2.0.0', 'features' => ['product_id', 'horizon_day', 'lag_1', 'lag_7', 'lag_14', 'lag_28',
                'rolling_mean_7', 'rolling_std_7', 'rolling_mean_28', 'rolling_std_28', 'day_of_week', 'day_of_month', 'month', 'is_weekend']];
        $pdo->prepare("INSERT INTO model_training_runs (model_version, status, metrics_json) VALUES (?, 'completed', ?)")
            ->execute([$version, json_encode(['metadata' => $metadata], JSON_THROW_ON_ERROR)]);
        $files[] = $path = $scoped . '/random_forest_candidate_' . $version . '.joblib';
        file_put_contents($path, 'opaque model fixture');
    }
    $governance->setModelsDir($directory);
    register_shutdown_function(static function () use ($files, $scoped, $directory): void {
        foreach ($files as $file) if (is_file($file)) unlink($file);
        rmdir($scoped);
        rmdir($directory . '/store');
        rmdir($directory);
    });
}
