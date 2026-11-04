<?php
declare(strict_types=1);

/**
 * Contract test for Ticket #368: "Let the local Administrator review and approve qualified models"
 * Verifies local Administrator approval/replacement, automated technical qualification (ADR-0022),
 * server-side gating matrices, atomic activation, audit logging, rejection preservation,
 * plain-English approval summary, incumbent comparison, product coverage, and status transitions.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Authorization/AuthorizationContext.php';
require_once __DIR__ . '/../app/Forecasting/DemandHistoryService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Forecasting/ModelPromotionService.php';
require_once __DIR__ . '/../app/Forecasting/StoreActivationService.php';
require_once __DIR__ . '/../app/Forecasting/StoreTrainingEligibilityService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';
require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';
require_once __DIR__ . '/../app/Services/MlJobService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Authorization\AuthorizationContext;
use App\Forecasting\DemandHistoryService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ModelPromotionService;
use App\Forecasting\StoreActivationService;
use App\Forecasting\StoreTrainingEligibilityService;
use App\Forecasting\ReplenishmentQueueService;
use App\Forecasting\DemandForecastService;
use App\Services\MlJobService;

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FAILED: " . $message);
    }
    echo "  [PASS] " . $message . "\n";
}

echo "Testing Ticket #368: Local Administrator Model Review & Approval Contract...\n\n";

// Set up isolated SQLite in-memory database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE platform_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_by INT,
        updated_at DATETIME
    );

    CREATE TABLE ml_model_evaluations (
        evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
        model_version VARCHAR(100),
        horizon_days INT,
        mae DOUBLE,
        rmse DOUBLE,
        wape DOUBLE,
        baseline_mae DOUBLE,
        baseline_rmse DOUBLE,
        baseline_wape DOUBLE,
        candidate_gate_outcome VARCHAR(50),
        missing_metric_reason VARCHAR(255),
        data_window_start DATE,
        data_window_end DATE,
        created_at DATETIME
    );

    CREATE TABLE model_training_runs (
        training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
        model_version VARCHAR(100),
        status VARCHAR(50),
        metrics_json TEXT,
        completed_at DATETIME
    );

    CREATE TABLE inventory (
        product_id INT PRIMARY KEY,
        quantity_on_hand INT DEFAULT 0
    );

    CREATE TABLE demand_forecasts (
        forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INT,
        product_id INT,
        forecast_origin DATE,
        target_date DATE,
        horizon_day INT,
        predicted_units INT,
        model_version VARCHAR(100),
        readiness_level VARCHAR(50),
        limitation_marker VARCHAR(255)
    );

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku VARCHAR(50),
        product_name VARCHAR(255),
        category_id INT,
        unit_price DECIMAL(10,2),
        cost_price DECIMAL(10,2),
        supplier_lead_time_days INT DEFAULT 7,
        minimum_order_quantity INT DEFAULT 1,
        reorder_level INT DEFAULT 10,
        safety_stock INT DEFAULT 5,
        units_per_package INT DEFAULT 1,
        status VARCHAR(20) DEFAULT 'active'
    );

    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_date DATETIME,
        status VARCHAR(20) DEFAULT 'completed'
    );

    CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INT,
        product_id INT,
        quantity INT,
        unit_price DECIMAL(10,2),
        total_price DECIMAL(10,2)
    );

    CREATE TABLE forecast_sales_imports (
        product_id INT,
        sale_date DATE,
        quantity INT,
        PRIMARY KEY (product_id, sale_date)
    );

    CREATE TABLE store_calendar (
        calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
        calendar_date DATE,
        status VARCHAR(50),
        notes TEXT,
        recorded_by INT,
        recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INT,
        change_qty INT,
        reason VARCHAR(255),
        moved_at DATETIME
    );

    CREATE TABLE ml_jobs (
        job_id INTEGER PRIMARY KEY AUTOINCREMENT,
        trigger_type VARCHAR(50),
        status VARCHAR(50),
        requester_id INT,
        evidence_cutoff DATE,
        model_version VARCHAR(100),
        attempts INT DEFAULT 0,
        queued_at DATETIME,
        started_at DATETIME,
        completed_at DATETIME,
        lease_expires_at DATETIME,
        operator_message TEXT,
        diagnostic_reference VARCHAR(100)
    );

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INT,
        action VARCHAR(255),
        category VARCHAR(100),
        module VARCHAR(100),
        record_id INT,
        previous_value TEXT,
        new_value TEXT,
        metadata TEXT,
        ip_address VARCHAR(50),
        created_at DATETIME
    );
");

// Populate initial products: P1 (mature), P2 (immature/excluded)
$pdo->exec("INSERT INTO products (product_id, sku, product_name, unit_price, cost_price, status) VALUES (1, 'STORE-P1', 'Standard Store Product 1', 100.0, 60.0, 'active')");
$pdo->exec("INSERT INTO products (product_id, sku, product_name, unit_price, cost_price, status) VALUES (2, 'STORE-P2', 'Immature Product 2', 50.0, 30.0, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 50)");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 5)");

$evalService = new CandidateEvaluationService($pdo);
$promotionService = new ModelPromotionService($pdo, $evalService);
$testModelsDir = sys_get_temp_dir() . '/retailmind_test_models_' . uniqid();
mkdir($testModelsDir, 0777, true);
$activationService = new StoreActivationService($pdo, $evalService, $promotionService, $testModelsDir);

register_shutdown_function(function() use ($testModelsDir) {
    if (is_dir($testModelsDir)) {
        foreach (glob($testModelsDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($testModelsDir);
    }
});

// =========================================================================
echo "--- Section 1: Role Capability Boundaries (ADR-0022) ---\n";
// =========================================================================
$policy = new RoleCapabilityPolicy();
$localCtx = AuthorizationContext::standard(false);
$remoteCtx = AuthorizationContext::standard(true);

// 1.1 Local Administrator permissions
assert_true($policy->allows('admin', RoleCapabilityPolicy::APPROVE_MODELS, null, $localCtx), 'Local Administrator has APPROVE_MODELS locally');
assert_true($policy->allows('admin', RoleCapabilityPolicy::REPLACE_MODELS, null, $localCtx), 'Local Administrator has REPLACE_MODELS locally');
assert_true($policy->allows('admin', RoleCapabilityPolicy::ROLL_BACK_MODELS, null, $localCtx), 'Local Administrator has ROLL_BACK_MODELS locally');

// 1.2 Remote mutation rejected
assert_true(!$policy->allows('admin', RoleCapabilityPolicy::APPROVE_MODELS, null, $remoteCtx), 'Remote Administrator is denied APPROVE_MODELS');
assert_true(!$policy->allows('admin', RoleCapabilityPolicy::REPLACE_MODELS, null, $remoteCtx), 'Remote Administrator is denied REPLACE_MODELS');
assert_true(!$policy->allows('admin', RoleCapabilityPolicy::ROLL_BACK_MODELS, null, $remoteCtx), 'Remote Administrator is denied ROLL_BACK_MODELS');

// 1.3 Technical setup authority retained by Super Administrator
assert_true(!$policy->allows('admin', RoleCapabilityPolicy::CONFIGURE_MODELS, null, $localCtx), 'Local Administrator lacks CONFIGURE_MODELS');
assert_true(!$policy->allows('admin', RoleCapabilityPolicy::RETRAIN_MODELS, null, $localCtx), 'Local Administrator lacks RETRAIN_MODELS');
assert_true($policy->allows('super_admin', RoleCapabilityPolicy::CONFIGURE_MODELS, null, $localCtx), 'Super Administrator retains CONFIGURE_MODELS');
assert_true($policy->allows('super_admin', RoleCapabilityPolicy::RETRAIN_MODELS, null, $localCtx), 'Super Administrator retains RETRAIN_MODELS');

// 1.4 Cashier and Inventory Manager denied
assert_true(!$policy->allows('cashier', RoleCapabilityPolicy::APPROVE_MODELS), 'Cashier lacks APPROVE_MODELS');
assert_true(!$policy->allows('inventory_manager', RoleCapabilityPolicy::APPROVE_MODELS), 'Inventory Manager lacks APPROVE_MODELS');

// =========================================================================
echo "\n--- Section 2: Server-Side Gating & Automated Qualification ---\n";
// =========================================================================
$candidateV1 = 'store_rf_candidate_20261015';
// 2.1 Demo artifact rejection server-side
$demoThrown = false;
try {
    $activationService->activateStoreModel('demo_rf_v1', '2026-10-15', 2, 'Attempting demo activation', 'admin');
} catch (InvalidArgumentException $e) {
    $demoThrown = true;
    assert_true(str_contains($e->getMessage(), 'demo/kaggle'), 'Rejection mentions demo/kaggle isolation');
}
assert_true($demoThrown, 'Server-side activation rejects demo artifact');

// 2.2 Missing quality gates / evaluations rejection
$missingGatesThrown = false;
try {
    $activationService->activateStoreModel($candidateV1, '2026-10-15', 2, 'Approving candidate without evals', 'admin');
} catch (InvalidArgumentException $e) {
    $missingGatesThrown = true;
    assert_true(str_contains($e->getMessage(), 'quality gates'), 'Rejection mentions incomplete quality gates');
}
assert_true($missingGatesThrown, 'Server-side activation rejects missing evaluations');

// 2.3 Unauthorized role rejection
$unauthThrown = false;
try {
    $activationService->activateStoreModel($candidateV1, '2026-10-15', 5, 'Cashier approving', 'cashier');
} catch (DomainException $e) {
    $unauthThrown = true;
    assert_true(str_contains($e->getMessage(), 'Unauthorized'), 'Rejection identifies unauthorized role');
}
assert_true($unauthThrown, 'Server-side activation rejects unauthorized role');

// 2.3b Inventory Manager role rejection
$imThrown = false;
try {
    $activationService->activateStoreModel($candidateV1, '2026-10-15', 6, 'Inventory Manager approving', 'inventory_manager');
} catch (DomainException $e) {
    $imThrown = true;
    assert_true(str_contains($e->getMessage(), 'Unauthorized'), 'Inventory Manager rejection identifies unauthorized role');
}
assert_true($imThrown, 'Server-side activation rejects Inventory Manager role');

// 2.3c Wrong-active-Workspace rejection (e.g. multi-role account whose active workspace is Cashier or Inventory Manager)
$wrongWsThrown = false;
try {
    $activationService->activateStoreModel($candidateV1, '2026-10-15', 2, 'Admin operating in wrong active Cashier workspace', 'cashier');
} catch (DomainException $e) {
    $wrongWsThrown = true;
    assert_true(str_contains($e->getMessage(), 'Unauthorized'), 'Wrong active workspace rejection identifies unauthorized workspace');
}
assert_true($wrongWsThrown, 'Server-side activation rejects wrong active workspace');

// 2.3d Remote activation rejection
$remoteActivationThrown = false;
try {
    $activationService->activateStoreModel($candidateV1, '2026-10-15', 2, 'Remote administrator approval attempt', 'admin', '192.168.1.100', true);
} catch (DomainException $e) {
    $remoteActivationThrown = true;
    assert_true(str_contains($e->getMessage(), 'Remote mutation rejected'), 'Remote activation rejection identifies remote mutation refusal');
}
assert_true($remoteActivationThrown, 'Server-side activation rejects remote mutation');

// Configure quality thresholds: 7d <= 0.25, 14d <= 0.30, 28d <= 0.35
$evalService->setThresholds(['ml_max_wape_7d' => 0.25, 'ml_max_wape_14d' => 0.30, 'ml_max_wape_28d' => 0.35], 1);

// Seed evaluations for candidateV1: WAPE passes thresholds AND outperforms baseline
foreach ([7 => [0.18, 0.28, 5.2, 7.1], 14 => [0.21, 0.32, 6.8, 9.4], 28 => [0.24, 0.36, 8.5, 12.0]] as $h => [$w, $bw, $mae, $bmae]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, data_window_start, data_window_end, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'passed', '2026-07-01', '2026-09-22', '2026-10-14 23:00:00')
    ")->execute([$candidateV1, $h, $mae, $mae * 1.25, $w, $bmae, $bmae * 1.25, $bw]);
}
$pdo->prepare("INSERT INTO model_training_runs (model_version, status, completed_at) VALUES (?, 'completed', '2026-10-14 23:00:00')")->execute([$candidateV1]);
require_once __DIR__ . '/support/governance_fixture.php';
governance_fixture($pdo, $activationService, [$candidateV1]);
$testModelsDir = $activationService->getModelsDir();

// 2.4 Failed evaluation check (e.g. regression against baseline)
$pdo->beginTransaction();
$pdo->prepare("UPDATE ml_model_evaluations SET wape = 0.40 WHERE model_version = ? AND horizon_days = 28")->execute([$candidateV1]);
$failedGateReadiness = $activationService->validateActivationReadiness($candidateV1, '2026-10-15');
assert_true(!$failedGateReadiness['ready'], 'Candidate with WAPE exceeding threshold is not ready');
assert_true($failedGateReadiness['checks']['quality_gates_passed'] === false, 'Quality gates check is false');
$pdo->rollBack();

// 2.5 Incomplete observation rejection (< 28 days)
$readinessObsIncomplete = $activationService->validateActivationReadiness($candidateV1, '2026-10-15');
assert_true(!$readinessObsIncomplete['ready'], 'Candidate without 28 observation days is not ready');
assert_true($readinessObsIncomplete['status'] === 'under_evaluation', 'Status is under_evaluation while checks remain');
assert_true($readinessObsIncomplete['status_label'] === 'Under evaluation', 'Status label is "Under evaluation"');

// Seed 28 days of prospective observation for candidateV1 (synthetic workflow evidence)
echo "  [INFO] Synthetic observation evidence is isolated workflow evidence only.\n";
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 500, 'receive', '2026-09-01 07:00:00')");
for ($i = 1; $i <= 28; $i++) {
    $tDate = sprintf('2026-09-%02d', $i);
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-08-30 00:00:00')")->execute([$tDate]);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version) VALUES (10, 1, '2026-08-31', ?, ?, 10, ?)")->execute([$tDate, $i, $candidateV1]);
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 11:00:00', 'completed')")->execute([1000 + $i, $tDate]);
    $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 1, 8, 100.0, 800.0)")->execute([1000 + $i]);
}

// 2.6 Qualification without routine Super Admin technical sign-off (ADR-0022)
// Super Admin did NOT call recordTechnicalReview. Automated checks qualify the model!
$readinessQualified = $activationService->validateActivationReadiness($candidateV1, '2026-09-28');
assert_true($readinessQualified['checks']['quality_gates_passed'] === true, 'Quality gates pass');
assert_true($readinessQualified['checks']['observation_period_completed'] === true, 'Observation holdout satisfied (28/28)');
assert_true($readinessQualified['checks']['technical_review_accepted'] === true, 'Automated technical qualification accepted without Super Admin manual sign-off (ADR-0022)');
assert_true($readinessQualified['status_label'] === 'Awaiting approval', 'Status label transitions to "Awaiting approval"');

// =========================================================================
echo "\n--- Section 3: Plain-English Approval Summary & First Model Workflow ---\n";
// =========================================================================
$summary = $activationService->getApprovalSummary($candidateV1, '2026-09-28');

// 3.1 Candidate & Approved Identities
assert_true($summary['candidate_version'] === $candidateV1, 'Summary names candidate version');
assert_true($summary['approved_version'] === '', 'Summary identifies no previous approved model (First Store model)');
assert_true($summary['can_approve'] === true, 'Qualified model can be approved by local Administrator');

// 3.2 Incumbent comparison absent and explicitly stated
assert_true($summary['incumbent_comparison']['available'] === false, 'Incumbent comparison is not available for first model');
assert_true(str_contains($summary['incumbent_comparison']['status_message'], 'First Store model qualification'), 'Summary explicitly states absence of previous incumbent comparison');

// 3.3 Method and periods
assert_true(str_contains($summary['comparison_method'], 'Weekly Seasonal Naive'), 'Summary names weekly Seasonal Naive method');
assert_true($summary['evaluation_periods']['start'] === '2026-07-01', 'Summary reports evaluation start period');
assert_true($summary['evaluation_periods']['end'] === '2026-09-22', 'Summary reports evaluation end period');

// 3.4 7/14/28-day metrics & limits
assert_true(isset($summary['candidate_quality']['horizons'][7]), '7-day horizon metrics present');
assert_true(isset($summary['candidate_quality']['horizons'][14]), '14-day horizon metrics present');
assert_true(isset($summary['candidate_quality']['horizons'][28]), '28-day horizon metrics present');
assert_true($summary['candidate_quality']['horizons'][28]['max_wape_limit'] === 0.35, '28-day threshold limit snapshot is 0.35');
assert_true($summary['candidate_quality']['horizons'][28]['gate_status'] === 'passed', '28-day horizon status is passed');

// 3.5 Product coverage & exclusion naming
assert_true($summary['products']['covered_count'] >= 0, 'Covered products count reported');
assert_true($summary['products']['excluded_count'] >= 1, 'Excluded immature products count reported');
$excludedP2 = null;
foreach ($summary['products']['excluded'] as $ex) {
    if ($ex['product_id'] === 2) {
        $excludedP2 = $ex;
        break;
    }
}
assert_true($excludedP2 !== null, 'Immature Product 2 explicitly named in excluded products');
assert_true(str_contains($excludedP2['exclusion_reason'], 'accumulated usable days'), 'Product 2 exclusion reason names accumulated usable days requirement');
assert_true(str_contains($excludedP2['blocked_next_action'], 'stock rules'), 'Product 2 explains blocked next action and stock rules fallback');

// 3.6 Limitations
assert_true(count($summary['limitations']) >= 4, 'Model limitations enumerated');
assert_true(str_contains(implode(' ', $summary['limitations']), 'accuracy percentage'), 'Limitations clarify WAPE is error, not an accuracy percentage');

// =========================================================================
echo "\n--- Section 4: Atomic First-Model Activation by Local Administrator ---\n";
// =========================================================================
$adminId = 2; // Local Administrator
$cutoff = '2026-09-28';
$record = $activationService->activateStoreModel($candidateV1, $cutoff, $adminId, 'Approved first Store forecasting model after qualification', 'admin', '192.168.1.100');

assert_true($record['model_version'] === $candidateV1, 'Activation record contains candidate version');
assert_true($record['activated_by'] === $adminId, 'Activation attributed to local Administrator');
assert_true($record['actor_role'] === 'admin', 'Actor role recorded as admin');
assert_true($record['decision'] === 'approved', 'Decision recorded as approved');
assert_true(isset($record['operational_forecast_job_id']), 'Activation queued operational forecast job');

// Verify platform settings updated atomically
$status = $activationService->getActivationStatus();
assert_true($status['approved_model_version'] === $candidateV1, 'Approved model is set to candidate V1');
assert_true($status['previous_model_version'] === '', 'Previous model is empty for first model');
assert_true($status['is_observation_mode'] === false, 'Observation mode disabled upon approval');
assert_true($status['is_recommendations_suspended'] === false, 'Recommendations active');

// Verify fresh operational forecast was queued for the completed-day cutoff
$stmtJob = $pdo->prepare("SELECT trigger_type, status, requester_id, evidence_cutoff, model_version FROM ml_jobs WHERE job_id = ?");
$stmtJob->execute([$record['operational_forecast_job_id']]);
$queuedJob = $stmtJob->fetch(PDO::FETCH_ASSOC);
assert_true($queuedJob['trigger_type'] === 'forecast_generation', 'Queued job trigger is forecast_generation');
assert_true($queuedJob['status'] === 'queued', 'Queued job status is queued');
assert_true($queuedJob['evidence_cutoff'] === $cutoff, "Job cutoff matches completed day ({$cutoff})");
assert_true($queuedJob['model_version'] === $candidateV1, 'Job targets newly approved model version');

// Verify Protected Audit Record in activity_log
$stmtAudit = $pdo->prepare("SELECT action, user_id, module, category, new_value, metadata FROM activity_log WHERE action = 'Activate Store Forecasting Model' ORDER BY log_id DESC LIMIT 1");
$stmtAudit->execute();
$audit = $stmtAudit->fetch(PDO::FETCH_ASSOC);
assert_true($audit !== false, 'Protected audit record created');
assert_true((int)$audit['user_id'] === $adminId, 'Audit attributed to Administrator');
assert_true($audit['new_value'] === $candidateV1, 'Audit record new_value is approved candidate');
$auditMeta = json_decode($audit['metadata'] ?? '{}', true);
assert_true($auditMeta['decision'] === 'approved', 'Audit metadata decision is approved');
assert_true(isset($auditMeta['effective_gates']['28']), 'Audit metadata captures effective gates snapshot');

// Historical forecasts were not relabeled
$stmtHist = $pdo->prepare("SELECT DISTINCT model_version FROM demand_forecasts WHERE job_id = 10");
$stmtHist->execute();
$histModel = $stmtHist->fetchColumn();
assert_true($histModel === $candidateV1, 'Historical forecast rows retain their origin model label');

// =========================================================================
echo "\n--- Section 5: Replacement Model Workflow with Incumbent Comparison ---\n";
// =========================================================================
$candidateV2 = 'store_rf_candidate_20261101';

// Seed candidate V2 evaluations (lower WAPE)
foreach ([7 => [0.15, 0.28, 4.5, 7.1], 14 => [0.18, 0.32, 5.9, 9.4], 28 => [0.20, 0.36, 7.2, 12.0]] as $h => [$w, $bw, $mae, $bmae]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, data_window_start, data_window_end, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'passed', '2026-08-01', '2026-10-22', '2026-10-31 23:00:00')
    ")->execute([$candidateV2, $h, $mae, $mae * 1.25, $w, $bmae, $bmae * 1.25, $bw]);
}
$pdo->prepare("INSERT INTO model_training_runs (model_version, status, completed_at) VALUES (?, 'completed', '2026-10-31 23:00:00')")->execute([$candidateV2]);
governance_fixture($pdo, $activationService, [$candidateV1, $candidateV2]);
$testModelsDir = $activationService->getModelsDir();

// Seed 28 days of observation for candidate V2
for ($i = 1; $i <= 28; $i++) {
    $tDate = sprintf('2026-10-%02d', $i);
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-09-30 00:00:00')")->execute([$tDate]);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version) VALUES (20, 1, '2026-09-30', ?, ?, 12, ?)")->execute([$tDate, $i, $candidateV2]);
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 11:00:00', 'completed')")->execute([2000 + $i, $tDate]);
    $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 1, 9, 100.0, 900.0)")->execute([2000 + $i]);
}

// 5.1 Replacement summary with incumbent comparison
$summaryV2 = $activationService->getApprovalSummary($candidateV2, '2026-10-28');
assert_true($summaryV2['incumbent_comparison']['available'] === true, 'Matching incumbent comparison is now available');
assert_true($summaryV2['incumbent_comparison']['version'] === $candidateV1, 'Incumbent version matches previous approved model (V1)');
assert_true($summaryV2['incumbent_comparison']['horizons'][28]['wape'] === 0.24, 'Incumbent 28-day WAPE is 0.24');
assert_true($summaryV2['candidate_quality']['horizons'][28]['wape'] === 0.20, 'Candidate 28-day WAPE is 0.20');
assert_true($summaryV2['can_approve'] === true, 'Replacement candidate is ready to approve');

// 5.2 Approve replacement model
$cutoffV2 = '2026-10-28';
$replacementRecord = $activationService->activateStoreModel($candidateV2, $cutoffV2, $adminId, 'Replaced V1 with V2 following lower error holdouts', 'admin');
assert_true($replacementRecord['previous_model_version'] === $candidateV1, 'Previous model recorded as candidate V1');

$statusV2 = $activationService->getActivationStatus();
assert_true($statusV2['approved_model_version'] === $candidateV2, 'Approved model updated to candidate V2');
assert_true($statusV2['previous_model_version'] === $candidateV1, 'Compatible previous approved model preserved');

// =========================================================================
echo "\n--- Section 6: Candidate Rejection Workflow ---\n";
// =========================================================================
$candidateV3 = 'store_rf_candidate_20261115';

// Reject candidate V3
$rejection = $activationService->rejectCandidate($candidateV3, $adminId, 'Erratic variance on weekend promotional holdouts', 'admin');
assert_true($rejection['decision'] === 'rejected', 'Rejection decision recorded');
assert_true($rejection['model_version'] === $candidateV3, 'Rejection names candidate V3');

// Verify approved model unchanged
$statusAfterReject = $activationService->getActivationStatus();
assert_true($statusAfterReject['approved_model_version'] === $candidateV2, 'Active approved model unchanged following rejection');
assert_true($statusAfterReject['previous_model_version'] === $candidateV1, 'Previous approved model unchanged following rejection');

// Verify rejection blocks future activation
$rejectedBlocked = false;
try {
    $activationService->activateStoreModel($candidateV3, '2026-11-15', $adminId, 'Attempting to activate rejected model', 'admin');
} catch (InvalidArgumentException $e) {
    $rejectedBlocked = true;
    assert_true(str_contains($e->getMessage(), 'rejected'), 'Activation refusal cites previous operational rejection');
}
assert_true($rejectedBlocked, 'Activation refused for previously rejected candidate');

// =========================================================================
echo "\n--- Section 7: Reversible Rollback to Compatible Prior Model ---\n";
// =========================================================================
$rollback = $activationService->rollbackStoreModel($adminId, 'Candidate V2 showed distribution drift; restoring V1', 'admin', '192.168.1.100', '2026-11-02');
assert_true($rollback['restored_model_version'] === $candidateV1, 'Restored prior approved Store model V1');
assert_true($rollback['rolled_back_from'] === $candidateV2, 'Rolled back from candidate V2');
assert_true(isset($rollback['operational_forecast_job_id']), 'Rollback queued operational forecast for restored model');

$statusRollback = $activationService->getActivationStatus();
assert_true($statusRollback['approved_model_version'] === $candidateV1, 'Active model restored to V1');
assert_true($statusRollback['previous_model_version'] === '', 'Previous model cleared to prevent circular loops');
assert_true(!empty($statusRollback['rollback_history']), 'Rollback history preserved in activation status');
assert_true($statusRollback['rollback_history'][0]['restored_model_version'] === $candidateV1, 'Rollback history records restored model V1');

// Re-attempting rollback without previous model throws
$circularBlocked = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Second rollback attempt', 'admin');
} catch (RuntimeException $e) {
    $circularBlocked = true;
}
assert_true($circularBlocked, 'Circular rollback rejected when no previous model is preserved');

// =========================================================================
echo "\n--- Section 8: Local Pause / Resume Purchasing Suggestions ---\n";
// =========================================================================
$activationService->suspendRecommendations($adminId, 'Inventory audit in progress', 'admin');
$statusSuspended = $activationService->getActivationStatus();
assert_true($statusSuspended['is_recommendations_suspended'] === true, 'Recommendations suspended locally');

$activationService->resumeRecommendations($adminId, 'admin');
$statusResumed = $activationService->getActivationStatus();
assert_true($statusResumed['is_recommendations_suspended'] === false, 'Recommendations resumed locally');

// =========================================================================
echo "\n--- Section 9: Recovery Artifact Validation & Rejection Matrix (Ticket #370) ---\n";
// =========================================================================

governance_fixture($pdo, $activationService, [$candidateV1, $candidateV2,
    'store_rf_candidate_absent', 'store_rf_candidate_empty', 'store_rf_candidate_isolatedmeta',
    'store_rf_candidate_v1schema', 'store_rf_candidate_noeval']);
$testModelsDir = $activationService->getModelsDir();
$scopedModelsDir = $testModelsDir . '/store/' . substr(hash('sha256', '127.0.0.1:3306:isolated_governance_fixture'), 0, 16);
unlink($scopedModelsDir . '/random_forest_candidate_store_rf_candidate_absent.joblib');
file_put_contents($scopedModelsDir . '/random_forest_candidate_store_rf_candidate_empty.joblib', '');
foreach (['store_rf_candidate_isolatedmeta' => ['dataset_kind', 'demo'],
    'store_rf_candidate_v1schema' => ['feature_version', '1.0.0']] as $version => [$field, $value]) {
    $stmt = $pdo->prepare('SELECT metrics_json FROM model_training_runs WHERE model_version = ? ORDER BY training_run_id DESC LIMIT 1');
    $stmt->execute([$version]);
    $metrics = json_decode($stmt->fetchColumn(), true);
    $metrics['metadata'][$field] = $value;
    $pdo->prepare('UPDATE model_training_runs SET metrics_json = ? WHERE model_version = ?')
        ->execute([json_encode($metrics, JSON_THROW_ON_ERROR), $version]);
}

// 9.1 Reject absent recovery artifact (file does not exist on disk)
$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_previous_approved_model_version'")->execute(['store_rf_candidate_absent']);
$absentThrown = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Attempt rollback to absent file', 'admin');
} catch (RuntimeException $e) {
    $absentThrown = true;
    assert_true(str_contains($e->getMessage(), 'missing or unreadable'), 'Rollback rejection mentions artifact not found on disk');
}
assert_true($absentThrown, 'Rollback rejects absent model artifact on disk');
// Verify state unmodified
$statusAbsentCheck = $activationService->getActivationStatus();
assert_true($statusAbsentCheck['approved_model_version'] === $candidateV1, 'Approved model unmodified following absent artifact rejection');

// 9.2 Reject 0-byte / empty recovery artifact

$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_previous_approved_model_version'")->execute(['store_rf_candidate_empty']);
$emptyThrown = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Attempt rollback to 0-byte file', 'admin');
} catch (RuntimeException $e) {
    $emptyThrown = true;
    assert_true(str_contains($e->getMessage(), 'missing or unreadable'), 'Rollback rejection mentions empty or corrupted file');
}
assert_true($emptyThrown, 'Rollback rejects 0-byte recovery artifact');

// 9.3 Untrusted JSON bytes cannot replace qualified Store training and a namespaced joblib artifact
file_put_contents($testModelsDir . '/random_forest_candidate_store_rf_candidate_corrupt.json', 'CORRUPT_BYTES_DATA_UNREADABLE');
$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_previous_approved_model_version'")->execute(['store_rf_candidate_corrupt']);
$corruptThrown = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Attempt rollback to corrupted file', 'admin');
} catch (RuntimeException $e) {
    $corruptThrown = true;
    assert_true(str_contains($e->getMessage(), 'metadata does not match'), 'Rollback rejection identifies missing trusted training metadata');
}
assert_true($corruptThrown, 'Rollback rejects untrusted JSON recovery content');

// 9.4 Reject demo recovery artifact by name
$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_previous_approved_model_version'")->execute(['demo_rf_recovery_v1']);
$demoNameThrown = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Attempt rollback to demo artifact', 'admin');
} catch (RuntimeException $e) {
    $demoNameThrown = true;
    assert_true(str_contains($e->getMessage(), 'non-demo Store model'), 'Rollback rejection cites demo isolation');
}
assert_true($demoNameThrown, 'Rollback rejects demo recovery artifact by name');

// 9.5 Reject demo recovery artifact by metadata

$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_previous_approved_model_version'")->execute(['store_rf_candidate_isolatedmeta']);
$demoMetaThrown = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Attempt rollback to demo metadata', 'admin');
} catch (RuntimeException $e) {
    $demoMetaThrown = true;
    assert_true(str_contains($e->getMessage(), 'metadata does not match'), 'Rollback rejection cites demo dataset');
}
assert_true($demoMetaThrown, 'Rollback rejects demo recovery artifact by metadata');

// 9.6 Reject incompatible recovery artifact (obsolete feature schema != 2.0.0)

$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_previous_approved_model_version'")->execute(['store_rf_candidate_v1schema']);
$incompatThrown = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Attempt rollback to v1.0.0 schema', 'admin');
} catch (RuntimeException $e) {
    $incompatThrown = true;
    assert_true(str_contains($e->getMessage(), 'metadata does not match'), 'Rollback rejection cites incompatible feature version');
}
assert_true($incompatThrown, 'Rollback rejects incompatible recovery artifact schema');

// 9.7 Reject unqualified recovery artifact (lacks evaluations & training records)

$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_previous_approved_model_version'")->execute(['store_rf_candidate_noeval']);
$noEvalThrown = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Attempt rollback without evals', 'admin');
} catch (RuntimeException $e) {
    $noEvalThrown = true;
    assert_true(str_contains($e->getMessage(), 'quality gates'), 'Rollback rejection cites lack of qualification metadata');
}
assert_true($noEvalThrown, 'Rollback rejects unqualified recovery artifact');

// 9.8 Remote rollback rejection server-side
$remoteThrown = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Remote attempt', 'admin', null, null, true);
} catch (DomainException $e) {
    $remoteThrown = true;
    assert_true(str_contains($e->getMessage(), 'Remote mutation rejected'), 'Rejection identifies remote mutation refusal');
}
assert_true($remoteThrown, 'Server-side rollback rejects remote mutation');

foreach (glob($testModelsDir . '/*.json') ?: [] as $file) unlink($file);

// Reset previous model to empty
$pdo->prepare("UPDATE platform_settings SET setting_value = '' WHERE setting_key = 'ml_previous_approved_model_version'")->execute();

// =========================================================================
echo "\n--- Section 10: Local Pause & Resume Guardrails & Eligible Guidance Verification (Ticket #370) ---\n";
// =========================================================================

// 10.1 Remote pause and resume rejected
$remotePauseThrown = false;
try {
    $activationService->suspendRecommendations($adminId, 'Remote pause', 'admin', null, true);
} catch (DomainException $e) {
    $remotePauseThrown = true;
}
assert_true($remotePauseThrown, 'Server-side pause rejects remote mutation');

$remoteResumeThrown = false;
try {
    $activationService->resumeRecommendations($adminId, 'admin', null, true);
} catch (DomainException $e) {
    $remoteResumeThrown = true;
}
assert_true($remoteResumeThrown, 'Server-side resume rejects remote mutation');

// 10.2 Authenticated local pause
$pauseRecord = $activationService->suspendRecommendations($adminId, 'Routine physical store inventory audit', 'admin', '192.168.1.50');
assert_true($pauseRecord['reason'] === 'Routine physical store inventory audit', 'Pause record contains reason');
$statusPaused = $activationService->getActivationStatus();
assert_true($statusPaused['is_recommendations_suspended'] === true, 'Recommendations marked suspended');

// Verify Protected Audit Record for pause
$stmtLogPause = $pdo->query("SELECT action, user_id, ip_address, metadata FROM activity_log WHERE action = 'Suspend Replenishment Recommendations' ORDER BY log_id DESC LIMIT 1");
$logPause = $stmtLogPause->fetch(PDO::FETCH_ASSOC);
assert_true($logPause !== false, 'Protected Audit Record logged for pause');
assert_true((int)$logPause['user_id'] === $adminId, 'Pause audit attributed to local Administrator');
assert_true($logPause['ip_address'] === '192.168.1.50', 'Pause audit records IP address');

// 10.3 Resume verification: refuse when active model is missing
$pdo->prepare("UPDATE platform_settings SET setting_value = '' WHERE setting_key = 'ml_approved_model_version'")->execute();
$resumeEmptyThrown = false;
try {
    $activationService->resumeRecommendations($adminId, 'admin');
} catch (RuntimeException $e) {
    $resumeEmptyThrown = true;
    assert_true(str_contains($e->getMessage(), 'non-demo Store model'), 'Resume rejection cites missing approved model');
}
assert_true($resumeEmptyThrown, 'Resume refused when no approved model is configured');
assert_true($activationService->getActivationStatus()['is_recommendations_suspended'] === true, 'Recommendations remain suspended after failed resume');

// 10.4 Resume verification: refuse when active model is demo artifact
$pdo->prepare("UPDATE platform_settings SET setting_value = 'demo_rf_store_model' WHERE setting_key = 'ml_approved_model_version'")->execute();
$resumeDemoThrown = false;
try {
    $activationService->resumeRecommendations($adminId, 'admin');
} catch (RuntimeException $e) {
    $resumeDemoThrown = true;
    assert_true(str_contains($e->getMessage(), 'non-demo Store model'), 'Resume rejection cites demo isolation');
}
assert_true($resumeDemoThrown, 'Resume refused when active model is demo artifact');

// 10.5 Resume verification: refuse when active model artifact is missing from disk
$pdo->prepare("UPDATE platform_settings SET setting_value = 'store_rf_candidate_absent' WHERE setting_key = 'ml_approved_model_version'")->execute();
$resumeAbsentThrown = false;
try {
    $activationService->resumeRecommendations($adminId, 'admin');
} catch (RuntimeException $e) {
    $resumeAbsentThrown = true;
    assert_true(str_contains($e->getMessage(), 'missing or unreadable'), 'Resume rejection cites artifact missing on disk');
}
assert_true($resumeAbsentThrown, 'Resume refused when active model artifact missing from disk');

// 10.6 Resume verification: refuse when active model is incompatible schema
$pdo->prepare("UPDATE platform_settings SET setting_value = 'store_rf_candidate_v1schema' WHERE setting_key = 'ml_approved_model_version'")->execute();
$resumeIncompatThrown = false;
try {
    $activationService->resumeRecommendations($adminId, 'admin');
} catch (RuntimeException $e) {
    $resumeIncompatThrown = true;
    assert_true(str_contains($e->getMessage(), 'metadata does not match'), 'Resume rejection cites incompatible schema');
}
assert_true($resumeIncompatThrown, 'Resume refused when active model has incompatible schema');

// 10.7 Resume verification: success when active model is approved, eligible, and compatible
$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_approved_model_version'")->execute([$candidateV1]);
$resumeRecord = $activationService->resumeRecommendations($adminId, 'admin', '192.168.1.50');
assert_true($resumeRecord['active_model'] === $candidateV1, 'Resume record records eligible active model');
assert_true($activationService->getActivationStatus()['is_recommendations_suspended'] === false, 'Recommendations successfully resumed');

// Verify Protected Audit Record for resume
$stmtLogResume = $pdo->query("SELECT action, user_id, ip_address FROM activity_log WHERE action = 'Resume Replenishment Recommendations' ORDER BY log_id DESC LIMIT 1");
$logResume = $stmtLogResume->fetch(PDO::FETCH_ASSOC);
assert_true($logResume !== false, 'Protected Audit Record logged for resume');
assert_true((int)$logResume['user_id'] === $adminId, 'Resume audit attributed to local Administrator');

// =========================================================================
echo "\n--- Section 11: Rollback & Purchasing Guidance / Stock-Rule Fallback (Ticket #370) ---\n";
// =========================================================================

// Set up replenishment scenario:
// Product 1 has inventory = 50, reorder_level = 10, safety_stock = 5 (In stock, stock_rule_qty = 0)
// Product 2 has inventory = 5, reorder_level = 10, safety_stock = 5 (Low stock, deficit = 10, stock_rule_qty = 10)
$queueService = new ReplenishmentQueueService($pdo);

// 11.1 Simulate active Candidate V2 with completed forecast generation run #100
$job100Date = '2026-10-31';
$pdo->prepare("INSERT INTO ml_jobs (job_id, trigger_type, status, requester_id, evidence_cutoff, model_version, attempts, queued_at, completed_at) VALUES (100, 'forecast_generation', 'succeeded', ?, ?, ?, 1, '2026-10-31 01:00:00', '2026-10-31 01:05:00')")->execute([$adminId, $job100Date, $candidateV2]);
// Seed 28 forecast rows for Product 1 under candidate V2
for ($d = 1; $d <= 28; $d++) {
    $tDate = sprintf('2026-11-%02d', $d);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level) VALUES (100, 1, '2026-10-31', ?, ?, 2, ?, 'High')")->execute([$tDate, $d, $candidateV2]);
}

// 11.2 Administrator rolls back from V2 to V1
$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_approved_model_version'")->execute([$candidateV2]);
$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_previous_approved_model_version'")->execute([$candidateV1]);

$rollbackResult = $activationService->rollbackStoreModel($adminId, 'V2 distribution drift observed; restoring V1', 'admin', '192.168.1.10', '2026-11-01');
assert_true($rollbackResult['restored_model_version'] === $candidateV1, 'Rollback restored V1');
$freshJobId = (int)$rollbackResult['operational_forecast_job_id'];
assert_true($freshJobId > 0, 'Fresh operational forecast job queued');

// 11.3 While fresh forecast is still pending (status = queued), purchasing uses labelled stock-rule fallback
$recsBeforeFreshSuccess = $queueService->getRecommendations(100, '2026-11-01');
$recsByPid = array_column($recsBeforeFreshSuccess, null, 'product_id');

assert_true($recsByPid[1]['basis'] === 'Stock-rule suggestion', 'Product 1 falls back to Stock-rule suggestion before fresh forecast succeeds');
assert_true($recsByPid[1]['expected_sales_28_day'] === null, 'Expected sales cleared on unapproved/rolled-back model');
assert_true($recsByPid[1]['recommended_quantity'] === $recsByPid[1]['stock_rule_quantity'], 'Recommended quantity equals stock-rule quantity');
// Dated history is preserved
assert_true($recsByPid[1]['forecast_origin'] === '2026-10-31', 'Dated history forecast origin is preserved');
assert_true($recsByPid[1]['model_version'] === $candidateV2, 'Dated history model version is preserved');
assert_true(str_contains($recsByPid[1]['forecast_status'], 'Unapproved model'), 'Forecast status indicates unapproved model');

// Product 2 (low stock, no forecast) also uses stock-rule suggestion
assert_true($recsByPid[2]['basis'] === 'Stock-rule suggestion', 'Product 2 uses stock-rule suggestion');
assert_true($recsByPid[2]['recommended_quantity'] === 10, 'Product 2 suggests 10 units based on reorder + safety stock deficit');

// 11.4 When fresh operational forecast succeeds for restored model V1
$pdo->prepare("UPDATE ml_jobs SET status = 'succeeded', completed_at = '2026-11-01 01:10:00' WHERE job_id = ?")->execute([$freshJobId]);
for ($d = 1; $d <= 28; $d++) {
    $tDate = sprintf('2026-11-%02d', $d);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level) VALUES (?, 1, '2026-10-31', ?, ?, 3, ?, 'High')")->execute([$freshJobId, $tDate, $d, $candidateV1]);
}

$recsAfterFreshSuccess = $queueService->getRecommendations($freshJobId, '2026-11-01');
$recsFreshByPid = array_column($recsAfterFreshSuccess, null, 'product_id');
assert_true($recsFreshByPid[1]['basis'] === 'Demand Forecast', 'Product 1 uses Demand Forecast once fresh forecast succeeds');
assert_true($recsFreshByPid[1]['model_version'] === $candidateV1, 'Product 1 uses restored model V1');
assert_true($recsFreshByPid[1]['expected_sales_28_day'] === 84, 'Expected sales 28-day populated (28 * 3 = 84)');

// =========================================================================
echo "\n--- Section 12: Repeated & Circular Rollback Audit Preservation (Ticket #370) ---\n";
// =========================================================================

// 12.1 Circular rollback prevention: previous model is currently empty
$secondRollbackBlocked = false;
try {
    $activationService->rollbackStoreModel($adminId, 'Circular rollback attempt', 'admin');
} catch (RuntimeException $e) {
    $secondRollbackBlocked = true;
    assert_true(str_contains($e->getMessage(), 'non-demo Store model'), 'Rejection identifies empty previous model');
}
assert_true($secondRollbackBlocked, 'Circular rollback prevented without previous model');

// 12.2 Multi-step rollback: Administrator upgrades to candidate V2 again, then rolls back to V1 a second time
$activationService->activateStoreModel($candidateV2, '2026-11-01', $adminId, 'Re-approving V2 with updated hyperparameters', 'admin');
assert_true($activationService->getActivationStatus()['approved_model_version'] === $candidateV2, 'Candidate V2 re-approved');
assert_true($activationService->getActivationStatus()['previous_model_version'] === $candidateV1, 'Candidate V1 preserved as previous');

// Execute second rollback
$secondRollback = $activationService->rollbackStoreModel($adminId, 'Second distribution drift detected; returning to stable V1', 'admin', '192.168.1.10', '2026-11-02');
assert_true($secondRollback['restored_model_version'] === $candidateV1, 'Second rollback restored V1');
assert_true($secondRollback['rolled_back_from'] === $candidateV2, 'Second rollback from V2');

// Verify rollback history contains both rollback records
$finalStatus = $activationService->getActivationStatus();
assert_true(count($finalStatus['rollback_history']) >= 2, 'Rollback history preserved across repeated rollbacks');
$histories = $finalStatus['rollback_history'];
assert_true($histories[0]['rollback_id'] !== $histories[1]['rollback_id'], 'Rollback IDs are distinct');

// Verify activity_log contains every activation, pause, resume, and rollback event without overwrite
$stmtAllLogs = $pdo->query("SELECT action, COUNT(*) as cnt FROM activity_log GROUP BY action");
$logCounts = $stmtAllLogs->fetchAll(PDO::FETCH_KEY_PAIR);

assert_true(($logCounts['Activate Store Forecasting Model'] ?? 0) >= 3, 'All activation audit records preserved');
assert_true(($logCounts['Rollback Store Forecasting Model'] ?? 0) >= 2, 'All rollback audit records preserved');
assert_true(($logCounts['Suspend Replenishment Recommendations'] ?? 0) >= 1, 'Pause audit records preserved');
assert_true(($logCounts['Resume Replenishment Recommendations'] ?? 0) >= 1, 'Resume audit records preserved');

echo "\n=========================================================================\n";
echo "PASS: All Ticket #368 and Ticket #370 Model Review, Pause, Resume & Rollback Tests Passed!\n";
echo "=========================================================================\n";
