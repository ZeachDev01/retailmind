<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';
require_once __DIR__ . '/../app/Forecasting/StoreModelGovernanceService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Forecasting\DemandForecastService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\StoreModelGovernanceService;

$failures = [];
$assert = function (bool $condition, string $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] {$message}\n";
    } else {
        echo "  [PASS] {$message}\n";
    }
};

function createLandingTestDatabase(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE ml_jobs (
            job_id INTEGER PRIMARY KEY AUTOINCREMENT,
            trigger_type TEXT NOT NULL,
            status TEXT NOT NULL,
            attempts INTEGER DEFAULT 1,
            operator_message TEXT DEFAULT NULL,
            diagnostic_reference TEXT DEFAULT NULL,
            queued_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL
        );

        CREATE TABLE platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            updated_by INTEGER DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE model_training_runs (
            training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_name TEXT DEFAULT 'rf_demand',
            model_version TEXT NOT NULL,
            status TEXT NOT NULL,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            metrics_json TEXT DEFAULT NULL
        );

        CREATE TABLE ml_model_evaluations (
            evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
            model_version TEXT NOT NULL,
            horizon_days INTEGER NOT NULL,
            mae REAL DEFAULT NULL,
            rmse REAL DEFAULT NULL,
            wape REAL DEFAULT NULL,
            baseline_mae REAL DEFAULT NULL,
            baseline_rmse REAL DEFAULT NULL,
            baseline_wape REAL DEFAULT NULL,
            missing_metric_reason TEXT DEFAULT NULL,
            data_window_start DATE DEFAULT '2026-10-01',
            data_window_end DATE DEFAULT '2026-10-28',
            candidate_gate_outcome TEXT NOT NULL DEFAULT 'passed',
            overestimated_units INTEGER DEFAULT NULL,
            underestimated_units INTEGER DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT,
            product_name TEXT,
            status TEXT DEFAULT 'active',
            supplier_lead_time_days INTEGER DEFAULT 7,
            minimum_order_quantity INTEGER DEFAULT 1,
            reorder_level INTEGER DEFAULT 10,
            safety_stock INTEGER DEFAULT 5,
            units_per_package INTEGER DEFAULT 1
        );

        CREATE TABLE inventory (
            inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            quantity_on_hand INTEGER DEFAULT 0
        );

        CREATE TABLE demand_forecasts (
            forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            forecast_origin DATE NOT NULL,
            target_date DATE NOT NULL,
            horizon_day INTEGER NOT NULL,
            predicted_units INTEGER NOT NULL,
            model_version TEXT NOT NULL,
            readiness_level TEXT NOT NULL,
            limitation_marker TEXT DEFAULT NULL
        );
    ");

    return $pdo;
}

echo "Starting Ticket #412 Action-Focused Forecasting Landing Page Contract Test...\n\n";

try {
    // -------------------------------------------------------------------------
    // Scenario 1: Healthy No-Action State
    // All checks passing, full coverage, approved model, valid latest job.
    // -------------------------------------------------------------------------
    echo "--- Test 1: Healthy No-Action State ---\n";
    $pdo = createLandingTestDatabase();
    $service = new DemandForecastService($pdo);

    $pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_approved_model_version', 'rf_prod_v1')");
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'SKU-001', 'Milk', 'active')");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 20)");

    $asOf = '2026-11-02';
    $origin = '2026-11-01';

    $pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, attempts, queued_at, completed_at) VALUES
        (1, 'forecast_generation', 'succeeded', 1, '2026-11-01 01:00:00', '2026-11-01 01:05:00')");

    for ($h = 1; $h <= 28; $h++) {
        $target = date('Y-m-d', strtotime("$origin +$h days"));
        $pdo->exec("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
            VALUES (1, 1, '{$origin}', '{$target}', {$h}, 5, 'rf_prod_v1', 'High')");
    }

    $coverage = $service->getCoverage(null, $asOf);
    $issues = $service->getOperationalIssues($coverage, 'admin');

    $assert(count($issues) === 1, 'Healthy state returns exactly 1 issue entry representing no-action state');
    $assert($issues[0]['severity'] === 'healthy' || $issues[0]['severity'] === 'success', 'Healthy state severity is healthy/success');
    $assert(str_contains($issues[0]['condition'], 'All operational checks passing'), 'Healthy condition states all checks passing');
    $assert(str_contains($issues[0]['responsible_role'], 'None') || str_contains($issues[0]['responsible_role'], 'No action'), 'Healthy role indicates no action needed');
    $assert($issues[0]['action_url'] === null, 'Healthy state has no action URL');

    // -------------------------------------------------------------------------
    // Scenario 2: Paused Guidance (Recommendations Suspended)
    // -------------------------------------------------------------------------
    echo "\n--- Test 2: Paused Guidance ---\n";
    $pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_recommendations_suspended', '1')");
    $coveragePaused = $service->getCoverage(null, $asOf);
    $issuesPaused = $service->getOperationalIssues($coveragePaused, 'admin');

    $assert(count($issuesPaused) >= 1, 'Paused guidance yields operational issue');
    $pausedIssue = $issuesPaused[0];
    $assert($pausedIssue['priority'] === 1, 'Paused guidance is Priority 1 (affects current guidance)');
    $assert(str_contains(strtolower($pausedIssue['condition']), 'paused'), 'Condition explains guidance is paused');
    $assert(str_contains(strtolower($pausedIssue['effect']), 'stock rules'), 'Effect explains falling back to stock rules');
    $assert($pausedIssue['responsible_role'] === 'Administrator', 'Responsible role is Administrator');
    $assert(!empty($pausedIssue['action_url']), 'Admin has working link to manage guidance/review');

    // Test role boundary for Inventory Manager on paused guidance
    $imIssuesPaused = $service->getOperationalIssues($coveragePaused, 'inventory_manager');
    $assert(count($imIssuesPaused) >= 1, 'Inventory Manager also sees paused guidance explanation');
    $assert(str_contains($imIssuesPaused[0]['responsible_role'], 'Administrator'), 'Inventory manager sees Administrator is responsible');
    $assert($imIssuesPaused[0]['action_url'] === null, 'Inventory manager does not receive mutation link');

    // -------------------------------------------------------------------------
    // Scenario 3: Retry Scheduled (Attempts < 3) vs Exhausted Recovery (Attempts >= 3)
    // -------------------------------------------------------------------------
    echo "\n--- Test 3: Retry Scheduled vs Exhausted Recovery ---\n";
    $pdo2 = createLandingTestDatabase();
    $service2 = new DemandForecastService($pdo2);
    $pdo2->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_approved_model_version', 'rf_prod_v1')");
    $pdo2->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'SKU-001', 'Milk', 'active')");
    $pdo2->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 20)");

    // 3a: Failed attempt 1 of 3 (Retry Scheduled)
    $pdo2->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, attempts, operator_message, queued_at, completed_at) VALUES
        (1, 'forecast_generation', 'failed', 1, 'Connection timeout to feature pipeline.', '2026-11-02 01:00:00', '2026-11-02 01:02:00')");

    $covRetry = $service2->getCoverage(null, $asOf);
    $issuesRetry = $service2->getOperationalIssues($covRetry, 'admin');

    $assert(count($issuesRetry) >= 1, 'Failed job produces issue');
    $retryIssue = null;
    foreach ($issuesRetry as $iss) {
        if (str_contains(strtolower($iss['condition']), 'attempt 1 of 3') || str_contains(strtolower($iss['condition']), 'retry')) {
            $retryIssue = $iss;
        }
    }
    $assert($retryIssue !== null, 'Found retry scheduled issue');
    $assert(str_contains(strtolower($retryIssue['condition']), 'attempt 1 of 3'), 'Condition notes attempt 1 of 3');
    $assert(str_contains(strtolower($retryIssue['effect']), 'stock rules'), 'Effect notes stock rules used');
    $assert(str_contains(strtolower($retryIssue['responsible_role']), 'automatic') || str_contains(strtolower($retryIssue['action_label']), 'no action needed'), 'Action explains automatic retry scheduled; no action needed');
    $assert($retryIssue['action_url'] === null, 'No action needed has no required mutation link');

    // 3b: Failed attempt 3 of 3 (Exhausted Recovery)
    $pdo2->exec("UPDATE ml_jobs SET attempts = 3, operator_message = 'Job failed: lease expired and maximum retries (3) exceeded.', diagnostic_reference = 'LEASE_TIMEOUT_EXHAUSTED' WHERE job_id = 1");

    $covExhausted = $service2->getCoverage(null, $asOf);
    $issuesExhausted = $service2->getOperationalIssues($covExhausted, 'admin');

    $exhaustedIssue = null;
    foreach ($issuesExhausted as $iss) {
        if (str_contains(strtolower($iss['condition']), 'exhausted') || str_contains(strtolower($iss['condition']), 'technical support')) {
            $exhaustedIssue = $iss;
        }
    }
    $assert($exhaustedIssue !== null, 'Found exhausted recovery issue');
    $assert(str_contains(strtolower($exhaustedIssue['condition']), 'exhausted'), 'Condition mentions exhausted retries');
    $assert(str_contains(strtolower($exhaustedIssue['effect']), 'technical support') || str_contains(strtolower($exhaustedIssue['condition']), 'technical support'), 'Mentions technical support required');
    $assert($exhaustedIssue['responsible_role'] === 'Super Administrator', 'Responsible role is Super Administrator (technical support)');

    $assert(!str_contains($covExhausted['coverage_summary_text'], 'Automatic retry scheduled'), 'Coverage summary never promises retry after the final attempt');
    $queueExhausted = (new App\Forecasting\ReplenishmentQueueService($pdo2))->getRecommendations(1, $asOf);
    $assert(!str_contains($queueExhausted[0]['exclusion_action'], 'Automatic retry'), 'Product fallback does not promise exhausted retries');
    $pdo2->exec("UPDATE ml_jobs SET attempts = 1, diagnostic_reference = 'BLOCKED:MISSING_APPROVED_MODEL' WHERE job_id = 1");
    $blockedCoverage = $service2->getCoverage(null, $asOf);
    $blockedIssues = $service2->getOperationalIssues($blockedCoverage, 'admin');
    $assert(!str_contains($blockedCoverage['coverage_summary_text'], 'Automatic retry scheduled'), 'Blocked prerequisites never promise an automatic retry in coverage');
    $assert(count(array_filter($blockedIssues, static fn($issue) => $issue['category'] === 'retry_scheduled')) === 0, 'Blocked prerequisites do not create a no-action retry issue');
    $pdo2->exec("UPDATE ml_jobs SET attempts = 3, diagnostic_reference = 'LEASE_TIMEOUT_EXHAUSTED' WHERE job_id = 1");

    // For Super Admin, link points to ml_settings.php
    $saIssues = $service2->getOperationalIssues($covExhausted, 'super_admin');
    $saExhausted = null;
    foreach ($saIssues as $iss) {
        if (str_contains(strtolower($iss['condition']), 'exhausted')) $saExhausted = $iss;
    }
    $assert($saExhausted !== null && str_contains($saExhausted['action_url'] ?? '', 'ml_settings.php'), 'Super Admin receives link to ml_settings.php for exhausted recovery');

    // For Admin, technical settings remain in Super Admin workspace (boundary respected)
    $assert(!str_contains($exhaustedIssue['action_url'] ?? '', 'ml_settings.php'), 'Administrator does NOT receive ml_settings.php link (workspace boundary preserved)');

    // -------------------------------------------------------------------------
    // Scenario 4: Qualified Candidate Awaiting Review & Approval
    // -------------------------------------------------------------------------
    echo "\n--- Test 4: Qualified Candidate Awaiting Review & Approval ---\n";
    $pdo3 = createLandingTestDatabase();
    $service3 = new DemandForecastService($pdo3);
    $pdo3->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_approved_model_version', 'rf_prod_v1')");
    $pdo3->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'SKU-001', 'Milk', 'active')");
    $pdo3->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 20)");

    // Usable forecast for approved model
    $pdo3->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, attempts, queued_at, completed_at) VALUES
        (1, 'forecast_generation', 'succeeded', 1, '2026-11-01 01:00:00', '2026-11-01 01:05:00')");
    for ($h = 1; $h <= 28; $h++) {
        $target = date('Y-m-d', strtotime("$origin +$h days"));
        $pdo3->exec("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level)
            VALUES (1, 1, '{$origin}', '{$target}', {$h}, 5, 'rf_prod_v1', 'High')");
    }

    // New candidate trained and passed gates
    $pdo3->exec("INSERT INTO model_training_runs (training_run_id, model_version, status, started_at, completed_at) VALUES
        (2, 'rf_candidate_v2', 'completed', '2026-11-01 03:00:00', '2026-11-01 03:30:00')");
    $pdo3->exec("INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, candidate_gate_outcome) VALUES
        ('rf_candidate_v2', 7, 18.0, 'passed'),
        ('rf_candidate_v2', 14, 22.0, 'passed'),
        ('rf_candidate_v2', 28, 26.0, 'passed')");

    $covCandidate = $service3->getCoverage(null, $asOf);
    $issuesCandidate = $service3->getOperationalIssues($covCandidate, 'admin');

    $reviewIssue = null;
    foreach ($issuesCandidate as $iss) {
        if (str_contains(strtolower($iss['condition']), 'candidate') || str_contains(strtolower($iss['condition']), 'review')) {
            $reviewIssue = $iss;
        }
    }
    $assert($reviewIssue !== null, 'Found candidate awaiting review issue');
    $assert($reviewIssue['priority'] === 2, 'Available review action is Priority 2');
    $assert(str_contains($reviewIssue['condition'], 'rf_candidate_v2'), 'Condition names candidate model version');
    $assert(str_contains(strtolower($reviewIssue['effect']), 'existing guidance continues'), 'Effect explains existing guidance continues until approved');
    $assert($reviewIssue['responsible_role'] === 'Administrator', 'Responsible role is Administrator');
    $assert(str_contains($reviewIssue['action_url'], 'model_review.php'), 'Working link opens detailed model review (model_review.php)');
    $assert(!str_contains($reviewIssue['action_url'], 'action=approve'), 'Review link does NOT auto-activate model');

    // -------------------------------------------------------------------------
    // Scenario 5: Prospective Observation Progress
    // -------------------------------------------------------------------------
    echo "\n--- Test 5: Prospective Observation Progress ---\n";
    $pdo4 = createLandingTestDatabase();
    $service4 = new DemandForecastService($pdo4);
    $pdo4->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_approved_model_version', 'rf_prod_v1')");
    $pdo4->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_observation_mode', '1')");
    $pdo4->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'SKU-001', 'Milk', 'active')");
    $pdo4->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 20)");

    $covObs = $service4->getCoverage(null, $asOf);
    $issuesObs = $service4->getOperationalIssues($covObs, 'admin');

    $obsIssue = null;
    foreach ($issuesObs as $iss) {
        if (str_contains(strtolower($iss['condition']), 'observation')) {
            $obsIssue = $iss;
        }
    }
    $assert($obsIssue !== null, 'Found observation progress issue');
    $assert(str_contains(strtolower($obsIssue['condition']), 'observation'), 'Condition mentions observation mode');
    $assert(str_contains(strtolower($obsIssue['effect']), 'stock rules') || str_contains(strtolower($obsIssue['effect']), 'routine purchasing'), 'Effect explains not approved for routine purchasing / stock rules used');

    // -------------------------------------------------------------------------
    // Scenario 6: Performance Summary ("Is it helping?")
    // -------------------------------------------------------------------------
    echo "\n--- Test 6: Performance Summary & Still Collecting Evidence ---\n";
    // 6a: Insufficient genuine Store outcomes -> Still collecting evidence
    $perfEmpty = $service->getPerformanceSummary('rf_prod_v1');
    $assert($perfEmpty['status'] === 'still_collecting_evidence', 'Returns still_collecting_evidence when evaluations absent');
    $assert(!empty($perfEmpty['reason']), 'Explains reason why still collecting evidence');
    $assert(str_contains(strtolower($perfEmpty['reason']), 'evidence') || str_contains(strtolower($perfEmpty['reason']), 'outcomes'), 'Reason mentions genuine Store outcomes or evidence');

    // 6b: With genuine evaluations populated
    $pdo->exec("INSERT INTO ml_model_evaluations
        (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, overestimated_units, underestimated_units, data_window_start, data_window_end)
        VALUES
        ('rf_prod_v1', 7, 1.25, 1.85, 0.12, 2.10, 2.90, 0.22, 'passed', 15, 8, '2026-10-01', '2026-10-28'),
        ('rf_prod_v1', 14, 1.80, 2.40, 0.16, 2.80, 3.50, 0.28, 'passed', 32, 19, '2026-10-01', '2026-10-28'),
        ('rf_prod_v1', 28, 2.50, 3.20, 0.22, 3.40, 4.20, 0.35, 'passed', 58, 41, '2026-10-01', '2026-10-28')");

    $perfReady = $service->getPerformanceSummary('rf_prod_v1');
    $assert($perfReady['status'] === 'still_collecting_evidence', 'Historical evaluations do not become genuine subsequent Store outcome evidence');
    $assert($perfReady['horizons'] === [], 'Backtest metrics are not presented as live Store prediction usefulness');

} catch (Throwable $e) {
    $failures[] = "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    echo "  [FAIL] Exception: " . $e->getMessage() . "\n";
}

echo "\n================================================================================\n";
if (empty($failures)) {
    echo "PASS: Ticket #412 Landing Page Contract passed cleanly!\n";
    exit(0);
} else {
    echo "FAIL: " . count($failures) . " assertions failed:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}
