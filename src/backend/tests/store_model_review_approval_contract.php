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
require_once __DIR__ . '/../app/Services/MlJobService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Authorization\AuthorizationContext;
use App\Forecasting\DemandHistoryService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ModelPromotionService;
use App\Forecasting\StoreActivationService;
use App\Forecasting\StoreTrainingEligibilityService;
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
        dataset_kind VARCHAR(50) DEFAULT 'store',
        created_at DATETIME
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

$evalService = new CandidateEvaluationService($pdo);
$promotionService = new ModelPromotionService($pdo, $evalService);
$activationService = new StoreActivationService($pdo, $evalService, $promotionService);

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

// Configure quality thresholds: 7d <= 0.25, 14d <= 0.30, 28d <= 0.35
$evalService->setThresholds(['ml_max_wape_7d' => 0.25, 'ml_max_wape_14d' => 0.30, 'ml_max_wape_28d' => 0.35], 1);

// Seed evaluations for candidateV1: WAPE passes thresholds AND outperforms baseline
foreach ([7 => [0.18, 0.28, 5.2, 7.1], 14 => [0.21, 0.32, 6.8, 9.4], 28 => [0.24, 0.36, 8.5, 12.0]] as $h => [$w, $bw, $mae, $bmae]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, data_window_start, data_window_end, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'passed', '2026-07-01', '2026-09-22', '2026-10-14 23:00:00')
    ")->execute([$candidateV1, $h, $mae, $mae * 1.25, $w, $bmae, $bmae * 1.25, $bw]);
}
$pdo->prepare("INSERT INTO model_training_runs (model_version, status, dataset_kind, created_at) VALUES (?, 'completed', 'store', '2026-10-14 23:00:00')")->execute([$candidateV1]);

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

// Seed 28 days of prospective observation for candidateV1
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
$pdo->prepare("INSERT INTO model_training_runs (model_version, status, dataset_kind, created_at) VALUES (?, 'completed', 'store', '2026-10-31 23:00:00')")->execute([$candidateV2]);

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

echo "\n=========================================================================\n";
echo "PASS: All Ticket #368 Model Review & Approval Contract Tests Passed!\n";
echo "=========================================================================\n";
