<?php
declare(strict_types=1);

/**
 * Contract test for Ticket #395: "Unify model qualification, reviews, and activation"
 * Verifies StoreModelGovernanceService consolidates model qualification, reviews, activation,
 * rollback, recommendation suspension, and platform settings persistence.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Forecasting/DemandHistoryService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Forecasting/StoreModelGovernanceService.php';
require_once __DIR__ . '/../app/Forecasting/StoreActivationService.php';
require_once __DIR__ . '/../app/Forecasting/ModelPromotionService.php';

use App\Forecasting\DemandHistoryService;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\StoreModelGovernanceService;
use App\Forecasting\StoreActivationService;
use App\Forecasting\ModelPromotionService;

function assert_test(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FAILED: " . $message);
    }
    echo "  [PASS] " . $message . "\n";
}

echo "Testing Unified Store Model Governance Contract (Ticket #395)...\n\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialize schema
$pdo->exec("
    CREATE TABLE forecast_sales_imports (product_id INT, sale_date DATE, quantity INT, PRIMARY KEY (product_id, sale_date));
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

    CREATE TABLE inventory (
        product_id INT PRIMARY KEY,
        quantity_on_hand INT DEFAULT 0
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

    CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INT,
        status VARCHAR(50),
        approved_at DATETIME
    );

    CREATE TABLE sale_reversal_items (
        reversal_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        reversal_id INT,
        sale_item_id INT,
        product_id INT,
        quantity INT
    );

    CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INT,
        status VARCHAR(50),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE cash_refund_items (
        refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        refund_id INT,
        sale_item_id INT,
        product_id INT,
        quantity INT
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

// Populate product P1
$pdo->exec("INSERT INTO products (product_id, sku, product_name, unit_price, cost_price, status) VALUES (1, 'STORE-P1', 'Standard Store Product 1', 100.0, 60.0, 'active')");
$pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 50)");

$governance = new StoreModelGovernanceService($pdo);
require_once __DIR__ . '/support/governance_fixture.php';
governance_fixture($pdo, $governance, ['store_rf_candidate_20261010', 'store_rf_candidate_20261101', 'store_rf_promo_v1']);

// =========================================================================
echo "--- Test 1: Synthetic Demo Model Exclusion & Isolation Barrier ---\n";
// =========================================================================
$demoModel = 'kaggle_demo_rf_v1';
$demoReadiness = $governance->validateActivationReadiness($demoModel, '2026-10-10');
assert_test($demoReadiness['ready'] === false, 'Activation is blocked for demo model');
assert_test($demoReadiness['checks']['not_demo_artifact'] === false, 'not_demo_artifact check is false');
assert_test(count(array_filter($demoReadiness['reasons'], fn($r) => str_contains($r, 'demo/kaggle'))) > 0, 'Demo rejection reason is explicitly stated');

$demoThrows = false;
try {
    $governance->activateStoreModel($demoModel, '2026-10-10', 1, 'Attempting demo activation');
} catch (InvalidArgumentException $e) {
    $demoThrows = true;
}
assert_test($demoThrows, 'activateStoreModel throws InvalidArgumentException on demo models');

// Empty version string check
$emptyThrows = false;
try {
    $governance->activateStoreModel('', '2026-10-10', 1);
} catch (InvalidArgumentException $e) {
    $emptyThrows = true;
}
assert_test($emptyThrows, 'activateStoreModel throws on empty model version');

// =========================================================================
echo "\n--- Test 2: Quality Gates, Observation, and ADR-0022 Local Approval ---\n";
// =========================================================================
$candidateVersion = 'store_rf_candidate_20261010';

// Scenario 2A: Missing all evidence
$readiness = $governance->validateActivationReadiness($candidateVersion, '2026-10-10');
assert_test($readiness['ready'] === false, 'Blocked when all evidence is missing');
assert_test($readiness['checks']['quality_gates_passed'] === false, 'Quality gates check is false');
assert_test($readiness['checks']['technical_review_accepted'] === false, 'Technical review check is false');
assert_test($readiness['checks']['operational_review_accepted'] === false, 'Operational review check is false');
assert_test($readiness['checks']['observation_period_completed'] === false, 'Observation period check is false');

// Set quality thresholds via governance service
$governance->setThresholds(['ml_max_wape_7d' => 0.20, 'ml_max_wape_14d' => 0.25, 'ml_max_wape_28d' => 0.30], 1);
$thresholds = $governance->getThresholds();
assert_test($thresholds['7'] === 0.20 && $thresholds['14'] === 0.25 && $thresholds['28'] === 0.30, 'Thresholds centralized in governance service');

// Seed evaluations passing quality gates
foreach ([7 => [0.15, 0.25], 14 => [0.18, 0.28], 28 => [0.22, 0.32]] as $h => [$w, $bw]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, baseline_wape, candidate_gate_outcome, created_at)
        VALUES (?, ?, ?, ?, 'passed', '2026-10-09 23:00:00')
    ")->execute([$candidateVersion, $h, $w, $bw]);
}

$readiness = $governance->validateActivationReadiness($candidateVersion, '2026-10-10');
assert_test($readiness['checks']['quality_gates_passed'] === true, 'Quality gates now pass');
assert_test($readiness['ready'] === false, 'Activation blocked without operational review and observation');

// Seed 28 completed observation days
for ($i = 1; $i <= 28; $i++) {
    $tDate = sprintf('2026-09-%02d', $i);
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-08-30 00:00:00')")->execute([$tDate]);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version) VALUES (1, 1, '2026-08-31', ?, ?, 10, ?)")->execute([$tDate, $i, $candidateVersion]);
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 10:00:00', 'completed')")->execute([$i, $tDate]);
    $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 1, 8, 100.0, 800.0)")->execute([$i]);
}

$readiness = $governance->validateActivationReadiness($candidateVersion, '2026-09-28');
assert_test($readiness['checks']['observation_period_completed'] === true, '28 completed observation days satisfied');
assert_test($readiness['ready'] === false, 'Activation still blocked without operational review');

// Record Admin operational review (without Super Admin technical review)
$adminId = 2;
$governance->recordOperationalReview($candidateVersion, 'accepted', 'Operational review approved by local store administrator.', $adminId);

// ADR-0022 verification: Local Administrator operational approval permits activation without mandatory Super Admin review
$readiness = $governance->validateActivationReadiness($candidateVersion, '2026-09-28');
assert_test($readiness['checks']['operational_review_accepted'] === true, 'Operational review accepted');
assert_test($readiness['checks']['technical_review_accepted'] === false, 'Technical review not yet recorded');
assert_test($readiness['ready'] === true, 'ADR-0022: Local Administrator approval qualifies candidate without mandatory Super Admin sign-off');

// Completed training metadata is authoritative; an opaque file alone cannot prove compatibility.
$metadataStatement = $pdo->prepare('SELECT metrics_json FROM model_training_runs WHERE model_version = ?');
$metadataStatement->execute([$candidateVersion]);
$qualifiedMetrics = $metadataStatement->fetchColumn();
$pdo->prepare('UPDATE model_training_runs SET metrics_json = ? WHERE model_version = ?')->execute(['{}', $candidateVersion]);
try {
    assert_test(!$governance->validateRollbackArtifact($candidateVersion)['valid'], 'Rollback rejects missing Store feature and dataset metadata');
    assert_test(!$governance->validateActiveModelForGuidance($candidateVersion)['valid'], 'Resume rejects missing Store feature and dataset metadata');
} finally {
    $pdo->prepare('UPDATE model_training_runs SET metrics_json = ? WHERE model_version = ?')->execute([$qualifiedMetrics, $candidateVersion]);
}

foreach ([
    'dataset_database' => 'another_store',
    'dataset_kind' => 'demo',
    'model_version' => 'different_version',
    'feature_version' => '1.0.0',
    'features' => ['product_id'],
] as $field => $invalidValue) {
    $invalidMetrics = json_decode($qualifiedMetrics, true);
    $invalidMetrics['metadata'][$field] = $invalidValue;
    $pdo->prepare('UPDATE model_training_runs SET metrics_json = ? WHERE model_version = ?')
        ->execute([json_encode($invalidMetrics, JSON_THROW_ON_ERROR), $candidateVersion]);
    try {
        assert_test(!$governance->validateRollbackArtifact($candidateVersion)['valid'], 'Rollback rejects incompatible ' . $field);
    } finally {
        $pdo->prepare('UPDATE model_training_runs SET metrics_json = ? WHERE model_version = ?')->execute([$qualifiedMetrics, $candidateVersion]);
    }
}

// A qualified review cannot approve an artifact that is no longer available.
$artifactPath = $governance->validateRollbackArtifact($candidateVersion)['artifact_path'];
rename($artifactPath, $artifactPath . '.missing');
try {
    $decoy = $governance->getModelsDir() . '/random_forest_candidate_' . $candidateVersion . '.json';
    file_put_contents($decoy, $qualifiedMetrics);
    assert_test(!$governance->validateRollbackArtifact($candidateVersion)['valid'], 'Root JSON metadata cannot replace a scoped Store model artifact');
    unlink($decoy);
    $otherDirectory = $governance->getModelsDir() . '/store/another_store';
    mkdir($otherDirectory);
    $otherArtifact = $otherDirectory . '/random_forest_candidate_' . $candidateVersion . '.joblib';
    copy($artifactPath . '.missing', $otherArtifact);
    try {
        assert_test(!$governance->validateRollbackArtifact($candidateVersion)['valid'], 'Another Store namespace cannot supply a missing model artifact');
    } finally {
        unlink($otherArtifact);
        rmdir($otherDirectory);
    }
    $missingArtifactReadiness = $governance->validateActivationReadiness($candidateVersion, '2026-09-28');
    assert_test(!$missingArtifactReadiness['ready'], 'Activation readiness blocks a missing qualified Store artifact');
    try {
        $governance->activateStoreModel($candidateVersion, '2026-09-28', $adminId);
        throw new RuntimeException('Missing candidate artifact was activated');
    } catch (InvalidArgumentException $e) {
        assert_test($governance->getApprovedModelVersion() === '', 'Blocked activation leaves approved model unchanged');
    }
} finally {
    rename($artifactPath . '.missing', $artifactPath);
}

// Super Admin explicitly rejects candidate -> activation MUST be blocked
$superAdminId = 1;
$governance->recordTechnicalReview($candidateVersion, 'rejected', 'Critical architectural flaw identified.', $superAdminId);
$readiness = $governance->validateActivationReadiness($candidateVersion, '2026-09-28');
assert_test($readiness['ready'] === false, 'Super Admin technical review rejection blocks activation');
assert_test(count(array_filter($readiness['reasons'], fn($r) => str_contains($r, 'technical review rejected'))) > 0, 'Technical review rejection reason stated');

// Super Admin accepts candidate -> candidate is ready again
$governance->recordTechnicalReview($candidateVersion, 'accepted', 'Technical validation passed across all horizons.', $superAdminId);
$readiness = $governance->validateActivationReadiness($candidateVersion, '2026-09-28');
assert_test($readiness['checks']['technical_review_accepted'] === true, 'Technical review accepted');
assert_test($readiness['ready'] === true, 'All gating criteria satisfied');

// =========================================================================
echo "\n--- Test 3: Atomic Activation & Setting Persistence ---\n";
// =========================================================================
$governance->setObservationMode(true, $superAdminId);
$governance->setRecommendationsSuspended(true, $superAdminId, 'Pre-activation check');
assert_test($governance->isObservationMode() === true, 'Observation mode active prior to activation');
assert_test($governance->isRecommendationsSuspended() === true, 'Recommendations suspended prior to activation');

$record = $governance->activateStoreModel($candidateVersion, '2026-09-28', $adminId, 'Local administrator routine store activation');
assert_test($record['model_version'] === $candidateVersion, 'Activation record contains model version');
assert_test($record['activated_by'] === $adminId, 'Activation attributed to Administrator');

$status = $governance->getActivationStatus();
assert_test($status['approved_model_version'] === $candidateVersion, 'Approved model updated in settings');
assert_test($status['is_observation_mode'] === false, 'Observation mode atomically cleared');
assert_test($status['is_recommendations_suspended'] === false, 'Recommendations suspension atomically cleared');
assert_test($status['is_store_activated'] === true, 'Store model formally activated');
assert_test($status['previous_model_version'] === '', 'No previous version on initial activation');

// Check activity log
$actLogs = $pdo->query("SELECT * FROM activity_log WHERE action = 'Activate Store Forecasting Model'")->fetchAll();
assert_test(count($actLogs) === 1, 'Activity log recorded for activation');
assert_test((int)$actLogs[0]['user_id'] === $adminId, 'Activity log attributed to local admin');

// =========================================================================
echo "\n--- Test 4: Model Replacement & Non-Circular Rollback ---\n";
// =========================================================================
$candidate2 = 'store_rf_candidate_20261101';
foreach ([7 => [0.12, 0.25], 14 => [0.15, 0.28], 28 => [0.19, 0.32]] as $h => [$w, $bw]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, baseline_wape, candidate_gate_outcome, created_at)
        VALUES (?, ?, ?, ?, 'passed', '2026-10-31 23:00:00')
    ")->execute([$candidate2, $h, $w, $bw]);
}
$governance->recordOperationalReview($candidate2, 'accepted', 'Candidate 2 accepted by store admin.', $adminId);

for ($i = 1; $i <= 28; $i++) {
    $tDate = sprintf('2026-10-%02d', $i);
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-09-29 00:00:00')")->execute([$tDate]);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version) VALUES (2, 1, '2026-09-30', ?, ?, 12, ?)")->execute([$tDate, $i, $candidate2]);
    $saleId = 100 + $i;
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 10:00:00', 'completed')")->execute([$saleId, $tDate]);
    $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 1, 8, 100.0, 800.0)")->execute([$saleId]);
}

// Activate candidate 2 (replacement)
$governance->activateStoreModel($candidate2, '2026-10-28', $adminId, 'Upgrading to candidate 2');
$status = $governance->getActivationStatus();
assert_test($status['approved_model_version'] === $candidate2, 'Candidate 2 is now approved');
assert_test($status['previous_model_version'] === $candidateVersion, 'Candidate 1 preserved as previous version');

// Perform rollback
$rollback = $governance->rollbackStoreModel($adminId, 'Candidate 2 regression observed');
assert_test($rollback['restored_model_version'] === $candidateVersion, 'Restored candidate 1');
$status = $governance->getActivationStatus();
assert_test($status['approved_model_version'] === $candidateVersion, 'Approved version restored to candidate 1');
assert_test($status['previous_model_version'] === '', 'Previous version pointer atomically cleared to prevent circular loops');

// Subsequent rollback must fail
$secondRollbackFailed = false;
try {
    $governance->rollbackStoreModel($adminId, 'Second rollback attempt');
} catch (RuntimeException $e) {
    $secondRollbackFailed = true;
}
assert_test($secondRollbackFailed, 'Subsequent rollback blocked when previous pointer is cleared');

// Failed rollback must preserve current state, including on metadata mismatch.
$pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_previous_approved_model_version', 'missing_model')
    ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value")->execute();
$before = $pdo->query('SELECT setting_key, setting_value FROM platform_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_KEY_PAIR);
try {
    $governance->rollbackStoreModel($adminId, 'Missing artifact');
    throw new RuntimeException('Missing rollback artifact was accepted');
} catch (RuntimeException $e) {
    assert_test($before === $pdo->query('SELECT setting_key, setting_value FROM platform_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_KEY_PAIR),
        'Invalid rollback leaves all lifecycle state unchanged');
}
$pdo->exec("UPDATE platform_settings SET setting_value = '' WHERE setting_key = 'ml_previous_approved_model_version'");

// =========================================================================
echo "\n--- Test 5: Backwards Compatibility Shims ---\n";
// =========================================================================
// StoreActivationService backward compatibility
$activationShim = new StoreActivationService($pdo);
assert_test($activationShim instanceof StoreModelGovernanceService, 'StoreActivationService extends StoreModelGovernanceService');
$shimStatus = $activationShim->getActivationStatus();
assert_test($shimStatus['approved_model_version'] === $candidateVersion, 'StoreActivationService shim returns correct status');

// ModelPromotionService backward compatibility
$promotionShim = new ModelPromotionService($pdo);
assert_test($promotionShim instanceof StoreModelGovernanceService, 'ModelPromotionService extends StoreModelGovernanceService');
$reviewStatus = $promotionShim->getReviewStatus($candidateVersion);
assert_test($reviewStatus['operational_review']['decision'] === 'accepted', 'ModelPromotionService shim returns review status');

// CandidateEvaluationService delegates settings to governance
$evalService = new CandidateEvaluationService($pdo);
$evalService->setObservationMode(true, $adminId);
assert_test($governance->isObservationMode() === true, 'CandidateEvaluationService delegates setObservationMode to governance');
$evalService->setObservationMode(false, $adminId);
assert_test($governance->isObservationMode() === false, 'CandidateEvaluationService delegates observation mode reset to governance');

// =========================================================================
echo "\n--- Test 6: Transactional Failure & Promotion Mechanics ---\n";
// =========================================================================
// Assert transactional failure leaves state completely unchanged
$statusBefore = $governance->getActivationStatus();
try {
    $governance->activateStoreModel('non_existent_unqualified_model', '2026-10-28', $adminId);
    assert_test(false, 'Should throw on unqualified candidate');
} catch (InvalidArgumentException $e) {
    assert_test(true, 'Rejected unqualified candidate activation');
}
$statusAfter = $governance->getActivationStatus();
assert_test($statusBefore['approved_model_version'] === $statusAfter['approved_model_version'], 'Failed activation leaves approved version unchanged');
assert_test($statusBefore['previous_model_version'] === $statusAfter['previous_model_version'], 'Failed activation leaves previous version unchanged');
assert_test($statusBefore['is_observation_mode'] === $statusAfter['is_observation_mode'], 'Failed activation leaves observation mode unchanged');

// Direct platform promotion (promote & rollback)
$promoCandidate = 'store_rf_promo_v1';
foreach ([7 => [0.10, 0.20], 14 => [0.12, 0.22], 28 => [0.15, 0.25]] as $h => [$w, $bw]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, baseline_wape, candidate_gate_outcome, created_at)
        VALUES (?, ?, ?, ?, 'passed', '2026-11-01 23:00:00')
    ")->execute([$promoCandidate, $h, $w, $bw]);
}

$governance->promote($promoCandidate, $superAdminId);
assert_test($governance->getApprovedModelVersion() === $promoCandidate, 'Promoted model via governance service');
assert_test($governance->getPreviousApprovedModelVersion() === $candidateVersion, 'Previous approved model version tracked');

// Idempotent duplicate promotion
$governance->promote($promoCandidate, $superAdminId);
assert_test($governance->getApprovedModelVersion() === $promoCandidate, 'Duplicate promotion is idempotent');

// Rollback via governance service
$governance->rollback($superAdminId);
assert_test($governance->getApprovedModelVersion() === $candidateVersion, 'Platform rollback restored previous version');
assert_test($governance->getPreviousApprovedModelVersion() === '', 'Platform rollback cleared previous version');

// The merged review workflow must use the shared owner and retain atomic qualification.
$summary = $governance->getApprovalSummary($candidateVersion, '2026-09-28');
assert_test($summary['is_current_approved'], 'Review summary sees the shared approved model');
$pdo->prepare('DELETE FROM platform_settings WHERE setting_key = ?')->execute(['ml_review_operational_' . $candidateVersion]);
$record = $governance->activateStoreModel($candidateVersion, '2026-09-28', $adminId, 'Approve from review screen');
assert_test($governance->getReviewStatus($candidateVersion)['operational_review']['decision'] === 'accepted', 'Activation records local approval through shared governance');
assert_test($record['decision'] === 'approved', 'Activation records the approval decision');
assert_test($governance->getReviewStatus('non_existent_unqualified_model')['operational_review'] === null, 'Failed qualification rolls back the approval review');
$rejected = 'store_rf_rejected';
$governance->rejectCandidate($rejected, $adminId, 'Unsuitable candidate');
assert_test($governance->getReviewStatus($rejected)['operational_review']['decision'] === 'rejected', 'Rejection reaches the shared review store');
try {
    $governance->activateStoreModel($rejected, '2026-09-28', $adminId);
    assert_test(false, 'Rejected candidate cannot be approved');
} catch (InvalidArgumentException $e) {
    assert_test($governance->getApprovedModelVersion() === $candidateVersion, 'Rejected candidate preserves the approved model');
}

// Review rows and the overall quality decision must describe the same evidence.
$qualityCandidate = 'store_rf_review_evidence';
foreach ([7, 14, 28] as $horizon) {
    $pdo->prepare('INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, baseline_wape) VALUES (?, ?, 0.1, 0.2)')
        ->execute([$qualityCandidate, $horizon]);
}
foreach ([
    ['0.1', 'NULL', 'blocked'],
    ['NULL', '0.2', 'blocked'],
    ['1e999', '0.2', 'blocked'],
    ['0.1', '1e999', 'blocked'],
    ['0.1', '0.1', 'failed'],
    ['0.1', '0.2', 'passed'],
] as [$wape, $baseline, $expectedStatus]) {
    $pdo->exec("UPDATE ml_model_evaluations SET wape={$wape}, baseline_wape={$baseline} WHERE model_version='{$qualityCandidate}' AND horizon_days=7");
    $quality = $governance->getApprovalSummary($qualityCandidate, '2026-09-28')['candidate_quality'];
    assert_test($quality['horizons'][7]['gate_status'] === $expectedStatus,
        "Review row reports {$expectedStatus} for model WAPE {$wape} and baseline {$baseline}");
    assert_test($quality['passed'] === ($expectedStatus === 'passed'), 'Review quality decision agrees with the horizon gate');
}

echo "\n========================================================\n";
echo "PASS: All Unified Store Model Governance Contract Tests Passed (Ticket #395)\n";
echo "========================================================\n";
