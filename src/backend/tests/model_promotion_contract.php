<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Forecasting/ModelPromotionService.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ModelPromotionService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

if (!function_exists('log_activity')) {
    function log_activity(\PDO $pdo, int $userId, string $action, ?string $module = null, ?int $recordId = null, ?string $prev = null, ?string $new = null) {
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, previous_value, new_value) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $prev, $new]);
    }
}

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
        CREATE TABLE activity_log (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            category TEXT,
            module TEXT,
            record_id INTEGER,
            previous_value TEXT,
            new_value TEXT,
            metadata TEXT,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $evalService = new CandidateEvaluationService($pdo);
    $promotionService = new ModelPromotionService($pdo, $evalService);

    // 1. Missing evaluation rejection
    try {
        $promotionService->promote('v_missing', 1);
        $assert(false, 'Should throw exception for missing evaluations');
    } catch (InvalidArgumentException $e) {
        $assert(strpos($e->getMessage(), 'incomplete') !== false || strpos($e->getMessage(), 'missing') !== false, 'Correct exception for missing eval');
    }

    // 2. Failed evaluation rejection
    $pdo->exec("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, candidate_gate_outcome, data_window_start, data_window_end) 
        VALUES ('v_failed', 7, 'passed', '2026-01-01', '2026-12-31'),
               ('v_failed', 14, 'failed', '2026-01-01', '2026-12-31'),
               ('v_failed', 28, 'passed', '2026-01-01', '2026-12-31')
    ");
    try {
        $promotionService->promote('v_failed', 1);
        $assert(false, 'Should throw exception for failed evaluations');
    } catch (InvalidArgumentException $e) {
        $assert(strpos($e->getMessage(), 'failed') !== false || strpos($e->getMessage(), 'rejected') !== false, 'Correct exception for failed eval');
    }

    // 3. Successful promotion
    $pdo->exec("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, candidate_gate_outcome, data_window_start, data_window_end) 
        VALUES ('v_success', 7, 'passed', '2026-01-01', '2026-12-31'),
               ('v_success', 14, 'passed', '2026-01-01', '2026-12-31'),
               ('v_success', 28, 'passed', '2026-01-01', '2026-12-31')
    ");
    $promotionService->promote('v_success', 2);
    
    $settings = $pdo->query("SELECT setting_key, setting_value FROM platform_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert($settings['ml_approved_model_version'] === 'v_success', 'Successfully sets approved model version');
    $assert(!isset($settings['ml_previous_approved_model_version']) || $settings['ml_previous_approved_model_version'] === '', 'Previous model properly null when none existed');
    
    // Check audit log
    $logs = $pdo->query("SELECT * FROM activity_log WHERE action = 'Promote Forecasting Model'")->fetchAll();
    $assert(count($logs) === 1, 'Audit log created for promotion');
    $assert((int)$logs[0]['user_id'] === 2, 'Audit log has correct user');
    $assert($logs[0]['new_value'] === 'v_success', 'Audit log has correct new value');

    // 4. Second promotion retains previous
    $pdo->exec("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, candidate_gate_outcome, data_window_start, data_window_end) 
        VALUES ('v_success_2', 7, 'passed', '2026-01-01', '2026-12-31'),
               ('v_success_2', 14, 'passed', '2026-01-01', '2026-12-31'),
               ('v_success_2', 28, 'passed', '2026-01-01', '2026-12-31')
    ");
    $promotionService->promote('v_success_2', 3);
    $settings = $pdo->query("SELECT setting_key, setting_value FROM platform_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert($settings['ml_approved_model_version'] === 'v_success_2', 'Successfully sets new approved model version');
    $assert($settings['ml_previous_approved_model_version'] === 'v_success', 'Successfully retains previous model version');

    // 5. Successful rollback
    $promotionService->rollback(3);
    $settings = $pdo->query("SELECT setting_key, setting_value FROM platform_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert($settings['ml_approved_model_version'] === 'v_success', 'Successfully restored previous model version');
    
    // Check audit log for rollback
    $logs = $pdo->query("SELECT * FROM activity_log WHERE action = 'Rollback Forecasting Model'")->fetchAll();
    $assert(count($logs) === 1, 'Audit log created for rollback');
    $assert((int)$logs[0]['user_id'] === 3, 'Audit log has correct user');

    // 6. Rollback fails if no previous
    $promotionService->promote('v_success_2', 3); // previous is v_success
    $pdo->exec("DELETE FROM platform_settings WHERE setting_key = 'ml_previous_approved_model_version'");
    try {
        $promotionService->rollback(3);
        $assert(false, 'Should throw exception if no previous model to rollback to');
    } catch (RuntimeException $e) {
        $assert(strpos($e->getMessage(), 'compatible') !== false || strpos($e->getMessage(), 'previous') !== false, 'Correct exception for missing previous');
    }

} catch (Throwable $e) {
    $failures[] = 'ModelPromotionService tests threw: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures) {
    fwrite(STDERR, "ModelPromotionService tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "ModelPromotionService tests: passed\n";
