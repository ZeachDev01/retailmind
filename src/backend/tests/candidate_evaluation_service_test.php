<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';

use App\Forecasting\CandidateEvaluationService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE ml_model_evaluations (
            evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_version TEXT,
            horizon_days INTEGER,
            mae REAL,
            rmse REAL,
            wape REAL,
            baseline_mae REAL,
            baseline_rmse REAL,
            baseline_wape REAL,
            candidate_gate_outcome TEXT,
            missing_metric_reason TEXT,
            data_window_start TEXT,
            data_window_end TEXT
        );
        CREATE TABLE platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            updated_by INTEGER,
            updated_at TEXT
        );
    ");

    $service = new CandidateEvaluationService($pdo);

    // Test inserting thresholds
    $service->setThresholds([
        'ml_max_wape_7d' => '0.2',
        'ml_max_wape_14d' => '0.25',
        'ml_max_wape_28d' => '0.3'
    ], 1);

    $stmt = $pdo->query("SELECT setting_key, setting_value FROM platform_settings ORDER BY setting_key ASC");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $assert($settings['ml_max_wape_7d'] === '0.2', '7d threshold not set correctly');
    $assert($settings['ml_max_wape_14d'] === '0.25', '14d threshold not set correctly');
    $assert($settings['ml_max_wape_28d'] === '0.3', '28d threshold not set correctly');

    // Test evaluations
    $pdo->exec("
        INSERT INTO ml_model_evaluations 
        (model_version, horizon_days, mae, rmse, wape, candidate_gate_outcome, data_window_start, data_window_end) 
        VALUES 
        ('v_test_1', 7, 1.5, 2.0, 0.15, 'passed', '2026-01-01', '2026-12-31'),
        ('v_test_1', 14, 2.0, 2.5, 0.20, 'passed', '2026-01-01', '2026-12-31')
    ");

    $evals = $service->getEvaluationsByModelVersion('v_test_1');
    $assert(count($evals) === 2, 'Should return exactly 2 evaluations');
    $assert($evals[0]['horizon_days'] === 7, 'First evaluation should be 7 days');
    $assert($evals[1]['horizon_days'] === 14, 'Second evaluation should be 14 days');
    $assert($evals[0]['candidate_gate_outcome'] === 'passed', 'Gate outcome should be passed');
    
} catch (Throwable $e) {
    $failures[] = 'CandidateEvaluationService tests threw: ' . $e->getMessage();
}

if ($failures) {
    fwrite(STDERR, "CandidateEvaluationService tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "CandidateEvaluationService tests: passed\n";
