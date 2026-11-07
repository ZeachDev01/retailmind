<?php
declare(strict_types=1);

/**
 * Component and Route test for Administrator Model Review & Approval (Ticket #368).
 * Verifies authenticated rendering, role capabilities, plain-English summary,
 * incumbent comparison, observation holdout progress, covered/excluded product lists,
 * error metric formatting, CSRF-protected approval/rejection forms, and disabled states.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Forecasting/ModelPromotionService.php';
require_once __DIR__ . '/../app/Forecasting/StoreActivationService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\ModelPromotionService;
use App\Forecasting\StoreActivationService;
use App\Forecasting\StoreModelGovernanceService;

function assert_test(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FAILED: " . $message);
    }
    echo "  [PASS] " . $message . "\n";
}

echo "Testing Administrator Model Review & Approval Component (Ticket #368)...\n\n";

$pdo = new PDO('sqlite::memory:');
$GLOBALS['pdo'] = $pdo;
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

    CREATE TABLE roles (
        role_id INTEGER PRIMARY KEY,
        role_name VARCHAR(50)
    );

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name VARCHAR(255),
        display_name VARCHAR(255),
        username VARCHAR(100),
        email VARCHAR(255),
        profile_image VARCHAR(255),
        status VARCHAR(50) DEFAULT 'active',
        session_version INT DEFAULT 0,
        is_recovery_account INT DEFAULT 0,
        last_login_at DATETIME,
        password_changed_at DATETIME,
        must_change_password INT DEFAULT 0,
        created_at DATETIME,
        role_id INT
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

$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (2, 'admin')");
$pdo->exec("INSERT INTO users (user_id, full_name, username, email, role_id, status) VALUES (2, 'Local Administrator', 'admin', 'admin@store.local', 2, 'active')");
$pdo->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'BEV-COLA', 'Store Cola 500ml', 'active')");
$pdo->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES (2, 'SNK-CHIP', 'Store Potato Chips 150g', 'active')");

// Configure quality thresholds
$governance = new StoreModelGovernanceService($pdo);
$governance->setThresholds(['ml_max_wape_7d' => 0.25, 'ml_max_wape_14d' => 0.30, 'ml_max_wape_28d' => 0.35], 1);

// Render helper function
function render_model_review(PDO $pdo, string $role = 'admin', array $post = []): string {
    $_SESSION['user_id'] = 2;
    $_SESSION['role'] = $role;
    $_SESSION['session_version'] = 0;
    $_SESSION['full_name'] = 'Test Local Admin';
    $GLOBALS['pdo'] = $pdo;
    $_SESSION['csrf_token'] = 'valid-test-csrf-token';
    $_SERVER['REQUEST_METHOD'] = empty($post) ? 'GET' : 'POST';
    $_POST = $post;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/retailmind/components/administrator/model_review.php';

    ob_start();
    try {
        include __DIR__ . '/../../frontend/components/administrator/model_review.php';
    } catch (Throwable $e) {
        ob_end_clean();
        echo "TRACE:
" . $e->getTraceAsString() . "
";
        throw $e;
    }
    return (string)ob_get_clean();
}

// =========================================================================
echo "--- Test 1: Empty / Initial Candidate Rendering (Under evaluation) ---\n";
// =========================================================================
$candidateV1 = 'store_rf_v20261102';
$fixtureGovernance = new App\Forecasting\StoreModelGovernanceService($pdo);
require_once __DIR__ . '/support/governance_fixture.php';
$runnerConfigPath = dirname(__DIR__) . '/config/forecasting_runner.json';
$originalConfig = is_file($runnerConfigPath) ? file_get_contents($runnerConfigPath) : null;
register_shutdown_function(static function () use ($runnerConfigPath, $originalConfig): void {
    if ($originalConfig === null) unlink($runnerConfigPath);
    else file_put_contents($runnerConfigPath, $originalConfig);
});
foreach ([7 => [0.19, 0.28, 4.8, 7.1], 14 => [0.22, 0.32, 6.2, 9.4], 28 => [0.26, 0.36, 7.9, 12.0]] as $h => [$w, $bw, $mae, $bmae]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, data_window_start, data_window_end, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'passed', '2026-07-01', '2026-09-22', '2026-10-31 23:00:00')
    ")->execute([$candidateV1, $h, $mae, $mae * 1.25, $w, $bmae, $bmae * 1.25, $bw]);
}
$pdo->prepare("INSERT INTO model_training_runs (model_version, status, completed_at) VALUES (?, 'completed', '2026-10-31 23:00:00')")->execute([$candidateV1]);
governance_fixture($pdo, $fixtureGovernance, [$candidateV1]);
$modelsDir = $fixtureGovernance->getModelsDir();
file_put_contents($runnerConfigPath, json_encode(['model_storage' => $modelsDir], JSON_THROW_ON_ERROR));

// At this point, 0 observation days exist -> Under evaluation
$html1 = render_model_review($pdo, 'admin');

assert_test(str_contains($html1, 'Model Review &amp; Approval'), 'Page title is rendered');
assert_test(str_contains($html1, $candidateV1), 'Candidate model version is rendered');
assert_test(str_contains($html1, 'None yet (First model)'), 'First model identifies no previous approved model');
assert_test(str_contains($html1, 'Under evaluation'), 'Status shows "Under evaluation" while observation days remain incomplete');
assert_test(str_contains($html1, 'Approval is blocked until technical checks and observation are complete'), 'Approval is disabled with safe explanation');
assert_test(!str_contains($html1, 'Approve &amp; Activate Model</button>'), 'Approve button is not active when under evaluation');
assert_test(str_contains($html1, 'Weekly Seasonal Naive'), 'Weekly Seasonal Naive baseline comparison method named');
assert_test(str_contains($html1, '19.00% error'), 'WAPE is rendered as error percentage');
assert_test(str_contains($html1, '26.00% error'), '28-day WAPE is rendered as error percentage');
assert_test(str_contains($html1, 'First Store model qualification'), 'First Store model explicitly notes absence of previous incumbent comparison');
assert_test(str_contains($html1, 'BEV-COLA') || str_contains($html1, 'SNK-CHIP'), 'Products are listed in coverage/exclusions');
assert_test(str_contains($html1, 'stock rules'), 'Excluded products explain fallback to stock rules');
assert_test(str_contains($html1, 'error metric, not an accuracy percentage'), 'Limitations clarify WAPE is an error metric');

// =========================================================================
echo "\n--- Test 2: Qualified Model Rendering (Awaiting approval) ---\n";
// =========================================================================
// Complete 28 observation days for candidate V1 (synthetic workflow evidence)
echo "  [INFO] Synthetic observation evidence is isolated workflow evidence only.\n";
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 1000, 'receive', '2026-09-01 07:00:00')");
for ($i = 1; $i <= 28; $i++) {
    $tDate = sprintf('2026-10-%02d', $i);
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-09-30 00:00:00')")->execute([$tDate]);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version) VALUES (15, 1, '2026-09-30', ?, ?, 10, ?)")->execute([$tDate, $i, $candidateV1]);
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 10:00:00', 'completed')")->execute([5000 + $i, $tDate]);
    $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 1, 8, 100.0, 800.0)")->execute([5000 + $i]);
}

$evalObs = (new CandidateEvaluationService($pdo))->evaluateCompletedTargetDates($pdo, $candidateV1, (new DateTime('now', new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d'));
$html2 = render_model_review($pdo, 'admin');

assert_test(str_contains($html2, 'Awaiting approval'), 'Status transitions to "Awaiting approval" after 28 observation days');
assert_test(str_contains($html2, 'Approve &amp; Activate Model'), 'Approve button is enabled when candidate is qualified');
assert_test(str_contains($html2, 'Reject Candidate'), 'Reject button is enabled for qualified candidate');
assert_test(str_contains($html2, 'name="csrf_token"'), 'Forms include CSRF protection token');

// =========================================================================
echo "\n--- Test 3: Authenticated Local Administrator Approval Action ---\n";
// =========================================================================
$htmlApprove = render_model_review($pdo, 'admin', [
    'csrf_token' => 'valid-test-csrf-token',
    'action' => 'approve',
    'model_version' => $candidateV1,
    'justification' => 'Operational verification completed by local Store Administrator',
]);

assert_test(str_contains($htmlApprove, 'approved and activated successfully'), 'Approval success notice is rendered');
assert_test(str_contains($htmlApprove, 'Fresh operational forecast has been queued'), 'Operational forecast queueing is confirmed in notice');

// Verify database reflects approval
$stmtApproved = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'");
assert_test($stmtApproved->fetchColumn() === $candidateV1, 'Candidate V1 is active approved Store model');

// Check rendering of approved model
$htmlApproved = render_model_review($pdo, 'admin');
assert_test(str_contains($htmlApproved, 'Selected as approved model'), 'Status shows "Selected as approved model"');
assert_test(!str_contains($htmlApproved, 'Available for replenishment') && !str_contains($htmlApproved, 'Active operational guidance'), 'Approval without current saved forecasts never claims usable buying guidance');
assert_test(str_contains($htmlApproved, 'Pause Purchasing Suggestions'), 'Pause button is visible for operational controls');
preg_match('/<section class="forecast-status-summary".*?<\/section>/s', $htmlApproved, $approvedStatus);
assert_test(str_contains($approvedStatus[0] ?? '', 'Check product coverage and current forecasts before buying.') && !str_contains($approvedStatus[0] ?? '', 'Check the failed requirements'), 'Approved model points to current coverage instead of failed approval requirements');

// =========================================================================
echo "\n--- Test 4: Local Pause & Resume Actions ---\n";
// =========================================================================
$htmlSuspend = render_model_review($pdo, 'admin', [
    'csrf_token' => 'valid-test-csrf-token',
    'action' => 'suspend',
    'reason' => 'Annual physical inventory count in progress',
]);
assert_test(str_contains($htmlSuspend, 'paused locally'), 'Pause notice is rendered');

$stmtSuspended = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_recommendations_suspended'");
assert_test($stmtSuspended->fetchColumn() === '1', 'Recommendations marked suspended in database');

$htmlResume = render_model_review($pdo, 'admin', [
    'csrf_token' => 'valid-test-csrf-token',
    'action' => 'resume',
]);
assert_test(str_contains($htmlResume, 'resumed'), 'Resume notice is rendered');
$stmtResumed = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_recommendations_suspended'");
assert_test($stmtResumed->fetchColumn() === '0', 'Recommendations marked active in database');

// =========================================================================
echo "\n--- Test 5: Replacement & Incumbent Comparison Rendering ---\n";
// =========================================================================
$candidateV2 = 'store_rf_v20261115';
foreach ([7 => [0.15, 0.28, 4.0, 7.1], 14 => [0.18, 0.32, 5.5, 9.4], 28 => [0.21, 0.36, 7.0, 12.0]] as $h => [$w, $bw, $mae, $bmae]) {
    $pdo->prepare("
        INSERT INTO ml_model_evaluations (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, data_window_start, data_window_end, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'passed', '2026-08-01', '2026-10-22', '2026-11-14 23:00:00')
    ")->execute([$candidateV2, $h, $mae, $mae * 1.25, $w, $bmae, $bmae * 1.25, $bw]);
}
$pdo->prepare("INSERT INTO model_training_runs (model_version, status, completed_at) VALUES (?, 'completed', '2026-11-14 23:00:00')")->execute([$candidateV2]);
governance_fixture($pdo, $fixtureGovernance, [$candidateV1, $candidateV2]);
$modelsDir = $fixtureGovernance->getModelsDir();
file_put_contents($runnerConfigPath, json_encode(['model_storage' => $modelsDir], JSON_THROW_ON_ERROR));

// Seed 28 observation days for candidate V2
for ($i = 1; $i <= 28; $i++) {
    $tDate = sprintf('2026-10-%02d', $i);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version) VALUES (25, 1, '2026-09-30', ?, ?, 10, ?)")->execute([$tDate, $i, $candidateV2]);
}
(new CandidateEvaluationService($pdo))->evaluateCompletedTargetDates($pdo, $candidateV2, (new DateTime('now', new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d'));

$htmlV2 = render_model_review($pdo, 'admin');
assert_test(str_contains($htmlV2, 'Matching incumbent comparison against approved model ' . $candidateV1), 'Matching incumbent comparison is displayed');
assert_test(str_contains($htmlV2, 'Approved Model (' . $candidateV1 . ') 28-day WAPE:'), 'Approved incumbent 28-day WAPE displayed for comparison');

// Approve replacement model
render_model_review($pdo, 'admin', [
    'csrf_token' => 'valid-test-csrf-token',
    'action' => 'approve',
    'model_version' => $candidateV2,
    'justification' => 'Upgraded model with lower error holdouts',
]);

$stmtPrev = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_previous_approved_model_version'");
assert_test($stmtPrev->fetchColumn() === $candidateV1, 'Previous model preserved as candidate V1');

// Now rollback option should be available in UI
$htmlRollback = render_model_review($pdo, 'admin');
assert_test(str_contains($htmlRollback, 'Roll Back to ' . $candidateV1), 'Rollback button available for previous model V1');

// Execute Rollback via UI
$htmlRollbackExec = render_model_review($pdo, 'admin', [
    'csrf_token' => 'valid-test-csrf-token',
    'action' => 'rollback',
    'reason' => 'Candidate V2 showed erratic spikes on seasonal promotion items',
]);
assert_test(
    str_contains($htmlRollbackExec, 'Successfully rolled back to previous model') && str_contains($htmlRollbackExec, $candidateV1),
    'Rollback execution confirmed in notice'
);

$stmtRolled = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'");
assert_test($stmtRolled->fetchColumn() === $candidateV1, 'Approved model restored to V1');

// =========================================================================
echo "\n--- Test 6: Recovery Guardrails, Unavailable Notice & Local-Only Mutability (Ticket #370) ---\n";
// =========================================================================

// 6.1 Set previous model to a corrupted/unreadable artifact and verify notice in UI
$corruptVer = 'store_rf_v_corrupt';
file_put_contents($modelsDir . '/random_forest_candidate_' . $corruptVer . '.json', 'CORRUPT_BYTES_DATA');
$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_previous_approved_model_version'")->execute([$corruptVer]);

$htmlIncompatNotice = render_model_review($pdo, 'admin');
assert_test(str_contains($htmlIncompatNotice, 'Rollback to previous model') && str_contains($htmlIncompatNotice, 'unavailable'), 'Rollback unavailable notice is rendered when artifact is corrupted');
assert_test(!str_contains($htmlIncompatNotice, 'Roll Back to ' . $corruptVer . '</button>'), 'Rollback submit button is omitted when recovery artifact is invalid');

// Clean up corrupt artifact and restore empty previous model
@unlink($modelsDir . '/random_forest_candidate_' . $corruptVer . '.json');
$pdo->prepare("UPDATE platform_settings SET setting_value = '' WHERE setting_key = 'ml_previous_approved_model_version'")->execute();

// 6.2 Local-only mutation enforcement on POST (reject remote requests)
$_SESSION['is_remote'] = true;
assert_test(is_remote_request() === true, 'Remote session is detected as remote request');
unset($_SESSION['is_remote']);

$_SERVER['RETAILMIND_REMOTE'] = '1';
assert_test(is_remote_request() === true, 'Remote environment is detected as remote request');
unset($_SERVER['RETAILMIND_REMOTE']);

assert_test(is_remote_request() === false, 'Local Store console is detected as non-remote request');

$policy = new RoleCapabilityPolicy();
$remoteCtx = \App\Authorization\AuthorizationContext::standard(true);
assert_test(!$policy->allows('admin', RoleCapabilityPolicy::APPROVE_MODELS, null, $remoteCtx), 'Remote request is denied APPROVE_MODELS capability');
assert_test(!$policy->allows('admin', RoleCapabilityPolicy::ROLL_BACK_MODELS, null, $remoteCtx), 'Remote request is denied ROLL_BACK_MODELS capability');

// 6.3 CSRF Protection Enforcement
$_SESSION['csrf_token'] = 'valid-test-csrf-token';
assert_test(!csrf_token_is_valid('invalid-tampered-token'), 'CSRF verification rejects invalid token');
assert_test(!csrf_token_is_valid(''), 'CSRF verification rejects empty token');
assert_test(csrf_token_is_valid('valid-test-csrf-token'), 'CSRF verification accepts authentic token');

// 6.4 Role Capability & Active-Workspace Boundary Denials
assert_test(!$policy->allows('cashier', RoleCapabilityPolicy::APPROVE_MODELS), 'Cashier lacks APPROVE_MODELS capability');
assert_test(!$policy->allows('inventory_manager', RoleCapabilityPolicy::APPROVE_MODELS), 'Inventory Manager lacks APPROVE_MODELS capability');
assert_test(!$policy->allows('cashier', RoleCapabilityPolicy::ROLL_BACK_MODELS), 'Cashier lacks ROLL_BACK_MODELS capability');
assert_test(!$policy->allows('inventory_manager', RoleCapabilityPolicy::ROLL_BACK_MODELS), 'Inventory Manager lacks ROLL_BACK_MODELS capability');

// Wrong active workspace boundary: active workspace role takes precedence over assigned roles
$_SESSION['role'] = 'cashier';
assert_test(!has_capability(RoleCapabilityPolicy::APPROVE_MODELS), 'Wrong active workspace (cashier) is denied APPROVE_MODELS even for multi-role user');
$_SESSION['role'] = 'inventory_manager';
assert_test(!has_capability(RoleCapabilityPolicy::APPROVE_MODELS), 'Wrong active workspace (inventory_manager) is denied APPROVE_MODELS even for multi-role user');
$_SESSION['role'] = 'admin';
assert_test(has_capability(RoleCapabilityPolicy::APPROVE_MODELS), 'Active admin workspace retains APPROVE_MODELS capability');

echo "\n=========================================================================\n";
echo "PASS: All Administrator Model Review Component Tests Passed!\n";
echo "=========================================================================\n";
