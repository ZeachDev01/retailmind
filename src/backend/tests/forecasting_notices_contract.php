<?php
// Contract for actionable forecasting notices (ticket #382, #376):
//
// 1. Qualified candidate awaiting review notifies Administrator with permitted review link.
// 2. Incomplete or failing candidate does not notify.
// 3. Demo artifacts and already-approved models do not notify.
// 4. Actionable forecasting failure notifies Administrator with safe explanation and permitted link.
// 5. Subsequent successful forecast job resolves the failure notice.
// 6. Routine successful forecast runs do not generate success notices.
// 7. Deduplication: repeated checks of unchanged conditions do not duplicate notifications.
// 8. Onboarding skip, defer, or completion never suppresses operational alerts or forecasting notices.
// 9. Administrator dashboard exceptions surface actionable failure and qualified review.
// 10. Active Workspace isolation: notices remain scoped to 'admin'.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/NotificationService.php';
require_once __DIR__ . '/../app/Attention/AttentionCenter.php';
require_once __DIR__ . '/../app/Attention/AttentionSettingsService.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Dashboard/StoreOperationsDashboardWorkspace.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

use App\Attention\AttentionCenter;
use App\Attention\AttentionSettingsService;
use App\Attention\Clock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'Forecasting notices contract threw: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    exit(1);
});

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo'] = $pdo;

$pdo->exec('CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)');
$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'inventory_manager'), (3, 'admin'), (4, 'super_admin')");

$pdo->exec("CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    branch_id INTEGER NOT NULL DEFAULT 1,
    role_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
)");

$pdo->exec("INSERT INTO users (user_id, full_name, branch_id, role_id, status) VALUES
    (1, 'Super Admin', 1, 4, 'active'),
    (2, 'Art Admin', 1, 3, 'active'),
    (3, 'Manny Manager', 1, 2, 'active')");

$pdo->exec("CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, is_primary INTEGER DEFAULT 0)");
$pdo->exec("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (2, 3, 1), (3, 2, 1)");

$pdo->exec("CREATE TABLE notification_preferences (
    user_id INTEGER PRIMARY KEY,
    notify_low_stock INTEGER NOT NULL DEFAULT 1,
    notify_email INTEGER NOT NULL DEFAULT 0,
    notify_inapp INTEGER NOT NULL DEFAULT 1
)");

$pdo->exec("CREATE TABLE notifications (
    notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    reference_id INTEGER NULL,
    reference_type TEXT NULL,
    attention_key TEXT NULL,
    attention_severity TEXT NULL,
    attention_count INTEGER NULL,
    attention_destination TEXT NULL,
    resolved_at TEXT NULL,
    is_read INTEGER DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    workspace TEXT NOT NULL DEFAULT '',
    inapp_enabled INTEGER NOT NULL DEFAULT 1,
    email_pending INTEGER NOT NULL DEFAULT 0
)");

$pdo->exec("CREATE TABLE attention_states (
    user_id INTEGER NOT NULL,
    attention_key TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    notification_level INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL,
    first_detected_at TEXT NOT NULL,
    last_detected_at TEXT NOT NULL,
    resolved_at TEXT NULL,
    PRIMARY KEY (user_id, attention_key)
)");

$pdo->exec("CREATE TABLE attention_settings (
    setting_scope TEXT NOT NULL,
    setting_key TEXT NOT NULL,
    setting_value TEXT NOT NULL,
    updated_by INTEGER NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (setting_scope, setting_key)
)");

$pdo->exec("CREATE TABLE platform_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NULL,
    updated_by INTEGER NULL,
    updated_at TEXT NULL
)");

$pdo->exec("CREATE TABLE workspace_guidance (
    user_id INTEGER NOT NULL,
    workspace TEXT NOT NULL,
    status TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, workspace)
)");

$pdo->exec("CREATE TABLE model_training_runs (
    training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
    model_version TEXT NOT NULL,
    status TEXT NOT NULL,
    error_message TEXT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT NULL
)");

$pdo->exec("CREATE TABLE ml_model_evaluations (
    evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    model_version TEXT NOT NULL,
    horizon_days INTEGER NOT NULL,
    mae REAL NULL,
    rmse REAL NULL,
    wape REAL NULL,
    baseline_mae REAL NULL,
    baseline_rmse REAL NULL,
    baseline_wape REAL NULL,
    candidate_gate_outcome TEXT NOT NULL,
    missing_metric_reason TEXT NULL,
    data_window_start TEXT NULL,
    data_window_end TEXT NULL,
    evaluated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE ml_jobs (
    job_id INTEGER PRIMARY KEY AUTOINCREMENT,
    trigger_type TEXT NOT NULL,
    status TEXT NOT NULL,
    requester_id INTEGER NULL,
    queued_at TEXT NOT NULL,
    started_at TEXT NULL,
    completed_at TEXT NULL,
    lease_expires_at TEXT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    operator_message TEXT NULL,
    diagnostic_reference TEXT NULL
)");

$pdo->exec("CREATE TABLE products (
    product_id INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL DEFAULT 1,
    sku TEXT NOT NULL,
    barcode TEXT NOT NULL DEFAULT '',
    product_name TEXT NOT NULL,
    cost_price REAL NOT NULL DEFAULT 10.0,
    reorder_level INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active'
)");

$pdo->exec("CREATE TABLE inventory (
    product_id INTEGER PRIMARY KEY,
    quantity_on_hand INTEGER NOT NULL DEFAULT 0
)");

$pdo->exec("CREATE TABLE cashier_shifts (
    shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cashier_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    cash_variance REAL NULL,
    reviewed_at TEXT NULL,
    closed_at TEXT NULL
)");

$pdo->exec("CREATE TABLE inventory_adjustments (
    adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    adjustment_qty INTEGER NOT NULL,
    adjustment_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    reported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE fiscal_periods (
    period_id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_name TEXT NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open'
);
CREATE TABLE sales (
    sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cashier_id INTEGER NOT NULL,
    total_amount REAL NOT NULL DEFAULT 0.0,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    discount_amount REAL NOT NULL DEFAULT 0.0,
    customer_id INTEGER NULL,
    sale_date TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE cash_refunds (
    refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
    shift_id INTEGER NOT NULL,
    refund_amount REAL NOT NULL DEFAULT 0.0,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE sale_reversals (
    reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);");

$pdo->exec("CREATE TABLE demand_forecasts (
    forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    forecast_origin TEXT NOT NULL,
    predicted_units REAL NOT NULL,
    readiness_level TEXT NOT NULL
)");

final class TestContractClock implements Clock
{
    public function __construct(public DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

$clock = new TestContractClock(new DateTimeImmutable('2026-11-01 10:00:00'));

echo "Starting actionable forecasting notices contract...\n\n";

// Configure default WAPE quality thresholds in platform_settings
$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES
    ('ml_max_wape_7d', '35.0'),
    ('ml_max_wape_14d', '40.0'),
    ('ml_max_wape_28d', '45.0'),
    ('ml_approved_model_version', 'rf_prod_v1')");

$settingsService = new AttentionSettingsService($pdo, $clock);
$attentionCenter = new AttentionCenter($pdo, $clock, $settingsService);
$notificationService = new NotificationService($pdo, $clock);

// --- Phase 1: Clean initial state with routine success ---
// Insert a routine successful forecast run
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at, attempts) VALUES
    (1, 'forecast_generation', 'succeeded', '2026-11-01 04:00:00', '2026-11-01 04:05:00', 1)");

$items = $attentionCenter->getAttention(0, 'admin');
$keys = array_map(static fn($item): string => $item->key, $items);
$assert(!in_array('store.forecasting.candidate-review', $keys, true), 'No candidate awaiting review on initial state');
$assert(!in_array('store.forecasting.failure', $keys, true), 'Routine successful run has 0 failure signal');
$assert(!in_array('store.forecasting.candidate-review', $keys, true), 'No candidate review notice on initial state');
$assert(!in_array('store.forecasting.failure', $keys, true), 'No failure notice on routine success');

$notifCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$notificationService->checkAndNotifyForecasts();
$notifCountAfter = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
$assert($notifCountBefore === $notifCountAfter, 'Routine success does not generate notifications for Administrator');

// --- Phase 2: Actionable forecasting failure ---
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at, attempts, operator_message, diagnostic_reference) VALUES
    (2, 'forecast_generation', 'failed', '2026-11-01 05:00:00', '2026-11-01 05:02:00', 3, 'Insufficient historical sales observations to generate 30-day forecast.', 'Trace: InsufficientDataException at line 42')");

$items = $attentionCenter->getAttention(0, 'admin');
$failureItem = null;
foreach ($items as $item) {
    if ($item->key === 'store.forecasting.failure') $failureItem = $item;
}
$assert($failureItem !== null, 'Failed forecast run produces active failure signal');
$assert(str_contains($failureItem?->explanation ?? '', 'Insufficient historical sales'), 'Signal uses safe operator message');
$assert(!str_contains($failureItem?->explanation ?? '', 'Trace: InsufficientDataException'), 'Signal hides raw diagnostic trace from explanation');
$assert($failureItem !== null, 'Forecasting failure produces attention item for Administrator');
$assert($failureItem?->severity === 'critical', 'Failure notice has critical severity');
$assert($failureItem?->destination === 'components/report/predictions.php', 'Failure notice destination is permitted predictions.php');

// Synchronize notification
$notificationService->checkAndNotifyForecasts();
$failureNotif = $pdo->query("SELECT * FROM notifications WHERE attention_key = 'store.forecasting.failure'")->fetch(PDO::FETCH_ASSOC);
$assert($failureNotif !== false, 'Failure notification is inserted into notifications table');
$assert($failureNotif['workspace'] === 'admin', 'Failure notification workspace is admin');
$assert($failureNotif['attention_destination'] === 'components/report/predictions.php', 'Notification carries destination');
$assert((int)$failureNotif['inapp_enabled'] === 1, 'Failure notification has in-app enabled');

// Deduplication: repeated check of unchanged failure condition must not insert duplicate notification
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key = 'store.forecasting.failure'")->fetchColumn();
$notificationService->checkAndNotifyForecasts();
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key = 'store.forecasting.failure'")->fetchColumn();
$assert($countBefore === $countAfter, 'Repeated failure check does not duplicate notifications');

// --- Phase 3: Resolution of forecasting failure by subsequent successful run ---
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at, attempts) VALUES
    (3, 'forecast_generation', 'succeeded', '2026-11-01 06:00:00', '2026-11-01 06:05:00', 1)");

$items = $attentionCenter->getAttention(0, 'admin');
$keys = array_map(static fn($item): string => $item->key, $items);
$assert(!in_array('store.forecasting.failure', $keys, true), 'Subsequent successful run clears failure signal');
$notificationService->checkAndNotifyForecasts();
$state = $pdo->query("SELECT is_active, resolved_at FROM attention_states WHERE attention_key = 'store.forecasting.failure'")->fetch(PDO::FETCH_ASSOC);
$assert($state !== false && (int)$state['is_active'] === 0, 'Failure attention state marked inactive on resolution');
$assert($state['resolved_at'] !== null, 'Resolved timestamp recorded on resolution');

// --- Phase 4: Candidate evaluation gating and review notice ---
// 4a. Candidate with failing quality gates does NOT notify
$pdo->exec("INSERT INTO model_training_runs (training_run_id, model_version, status, started_at, completed_at) VALUES
    (1, 'rf_candidate_v2', 'completed', '2026-11-01 07:00:00', '2026-11-01 07:30:00')");
$pdo->exec("INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, candidate_gate_outcome) VALUES
    ('rf_candidate_v2', 7, 55.0, 'failed'),
    ('rf_candidate_v2', 14, 60.0, 'failed'),
    ('rf_candidate_v2', 28, 65.0, 'failed')");

$items = $attentionCenter->getAttention(0, 'admin');
$keys = array_map(static fn($item): string => $item->key, $items);
$assert(!in_array('store.forecasting.candidate-review', $keys, true), 'Candidate failing quality gates does not produce review signal');

// 4b. Demo artifact does NOT notify even if passed
$pdo->exec("INSERT INTO model_training_runs (training_run_id, model_version, status, started_at, completed_at) VALUES
    (2, 'demo_candidate_v1', 'completed', '2026-11-01 08:00:00', '2026-11-01 08:30:00')");
$pdo->exec("INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, candidate_gate_outcome) VALUES
    ('demo_candidate_v1', 7, 20.0, 'passed'),
    ('demo_candidate_v1', 14, 25.0, 'passed'),
    ('demo_candidate_v1', 28, 30.0, 'passed')");

$items = $attentionCenter->getAttention(0, 'admin');
$keys = array_map(static fn($item): string => $item->key, $items);
$assert(!in_array('store.forecasting.candidate-review', $keys, true), 'Demo candidate does not produce review signal');

// 4c. Qualified production candidate awaiting review DOES notify
$pdo->exec("INSERT INTO model_training_runs (training_run_id, model_version, status, started_at, completed_at) VALUES
    (3, 'rf_candidate_v3', 'completed', '2026-11-01 09:00:00', '2026-11-01 09:30:00')");
$pdo->exec("INSERT INTO ml_model_evaluations (model_version, horizon_days, wape, candidate_gate_outcome) VALUES
    ('rf_candidate_v3', 7, 22.0, 'passed'),
    ('rf_candidate_v3', 14, 28.0, 'passed'),
    ('rf_candidate_v3', 28, 33.0, 'passed')");

$items = $attentionCenter->getAttention(0, 'admin');
$reviewItem = null;
foreach ($items as $item) {
    if ($item->key === 'store.forecasting.candidate-review') $reviewItem = $item;
}
$assert($reviewItem !== null, 'Qualified candidate awaiting review produces active signal');
$assert(str_contains($reviewItem?->explanation ?? '', 'rf_candidate_v3'), 'Signal explanation mentions candidate model version');
$assert($reviewItem !== null, 'Qualified candidate produces attention item for Administrator');
$assert($reviewItem?->severity === 'warning', 'Review item has warning severity');
$assert($reviewItem?->destination === 'components/administrator/model_review.php', 'Review item links to model review');

$notificationService->checkAndNotifyForecasts();
$reviewNotif = $pdo->query("SELECT * FROM notifications WHERE attention_key = 'store.forecasting.candidate-review'")->fetch(PDO::FETCH_ASSOC);
$assert($reviewNotif !== false, 'Candidate review notification inserted into notifications table');
$assert($reviewNotif['workspace'] === 'admin', 'Candidate review notification workspace is admin');
$assert($reviewNotif['attention_destination'] === 'components/administrator/model_review.php', 'Notification links to model review');

// Deduplication on candidate review
$revCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key = 'store.forecasting.candidate-review'")->fetchColumn();
$notificationService->checkAndNotifyForecasts();
$revCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key = 'store.forecasting.candidate-review'")->fetchColumn();
$assert($revCountBefore === $revCountAfter, 'Repeated candidate review check does not duplicate notifications');

// 4d. Once reviewed/accepted or approved, candidate notice resolves
$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES
    ('ml_review_operational_rf_candidate_v3', '{\"model_version\":\"rf_candidate_v3\",\"decision\":\"accepted\",\"reviewed_by\":2}')");
$items = $attentionCenter->getAttention(0, 'admin');
$keys = array_map(static fn($item): string => $item->key, $items);
$assert(!in_array('store.forecasting.candidate-review', $keys, true), 'Reviewed/accepted candidate no longer produces review signal');
$notificationService->checkAndNotifyForecasts();
$revState = $pdo->query("SELECT is_active FROM attention_states WHERE attention_key = 'store.forecasting.candidate-review'")->fetch(PDO::FETCH_ASSOC);
$assert((int)$revState['is_active'] === 0, 'Review attention state resolved after operational acceptance');

// --- Phase 5: Onboarding status never suppresses operational alerts ---
// Simulate completed onboarding for admin
$pdo->exec("INSERT INTO workspace_guidance (user_id, workspace, status) VALUES (2, 'admin', 'completed')");

// Trigger a new failure job
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at, attempts, operator_message) VALUES
    (4, 'forecast_generation', 'failed', '2026-11-01 10:00:00', '2026-11-01 10:02:00', 3, 'Worker timeout during forecast inference.')");

$items = $attentionCenter->getAttention(0, 'admin');
$keys = array_map(static fn($item): string => $item->key, $items);
$assert(in_array('store.forecasting.failure', $keys, true), 'Failure signal generated despite onboarding completed');
$notificationService->checkAndNotifyForecasts();
$failState = $pdo->query("SELECT is_active FROM attention_states WHERE attention_key = 'store.forecasting.failure'")->fetch(PDO::FETCH_ASSOC);
$assert((int)$failState['is_active'] === 1, 'Forecasting failure alert active despite completed onboarding');

// Verify with skipped and deferred status
foreach (['skipped', 'deferred'] as $onboardingStatus) {
    $pdo->exec("UPDATE workspace_guidance SET status = '{$onboardingStatus}' WHERE user_id = 2 AND workspace = 'admin'");
    $items = $attentionCenter->getAttention(0, 'admin');
    $keys = array_map(static fn($item): string => $item->key, $items);
    $assert(in_array('store.forecasting.failure', $keys, true), "Failure signal active when onboarding is {$onboardingStatus}");
}

// --- Phase 6: StoreOperationsDashboardWorkspace surfaces exceptions ---
StoreOperationsDashboardWorkspace::invalidateCache();
$dashboardWorkspace = new StoreOperationsDashboardWorkspace($pdo, $clock, new RoleCapabilityPolicy());
$dashboardData = $dashboardWorkspace->load('admin', 30);

$dashboardExceptions = $dashboardData['exceptions'] ?? [];
$dashboardExceptionTitles = array_column($dashboardExceptions, 'title');

$assert(in_array('Forecasting failure', $dashboardExceptionTitles, true), 'Administrator dashboard exceptions include forecasting failure');

// --- Phase 7: Ticket #361 Notice Extensions ---
// 7a. Model retraining failure notifies Administrator
$signalSource = new \App\Attention\DatabaseAttentionSignalSource($pdo, $clock);
$pdo->exec("INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at, attempts, operator_message, diagnostic_reference) VALUES
    (5, 'model_retraining', 'failed', '2026-11-01 10:30:00', '2026-11-01 10:35:00', 3, 'Model candidate training failed on cross-validation.', 'EXHAUSTED:CVException')");
$signals = $signalSource->store();
$assert(($signals['forecasting_failure']['value'] ?? 0) === 1, 'Model retraining failure produces failure signal');
$assert(str_contains($signals['forecasting_failure']['explanation'] ?? '', 'Model candidate training failed'), 'Signal explains retraining failure');

// Synchronize and verify notification deduplication
$notificationService->checkAndNotifyForecasts();
$retrainCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key = 'store.forecasting.failure'")->fetchColumn();
$notificationService->checkAndNotifyForecasts();
$retrainCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key = 'store.forecasting.failure'")->fetchColumn();
$assert($retrainCountBefore === $retrainCountAfter, 'Repeated retraining failure check does not duplicate notifications');

// 7b. Persistent background inactivity notice
$pdo->exec("DELETE FROM ml_jobs WHERE job_id = 5;
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, attempts) VALUES
    (6, 'forecast_generation', 'queued', '2026-11-01 07:00:00', 0)");
$signals = $signalSource->store();
$assert(($signals['forecasting_failure']['value'] ?? 0) === 1, 'Persistent background inactivity produces failure signal');
$assert(str_contains($signals['forecasting_failure']['explanation'] ?? '', 'inactive or stalled'), 'Signal explains background worker inactivity');

// 7c. Preserved local diagnostics during database outage surfaced after recovery
$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$diagFile = $logDir . '/forecasting_diagnostics.json';
$pdo->exec("DELETE FROM ml_jobs;
    INSERT INTO ml_jobs (job_id, trigger_type, status, queued_at, completed_at, attempts) VALUES
    (7, 'forecast_generation', 'succeeded', '2026-11-01 05:00:00', '2026-11-01 05:05:00', 1)");
$outageEntry = [
    [
        'timestamp' => '2026-11-01 09:30:00',
        'error' => 'Database connection refused on port 3306',
        'diagnostic_reference' => 'DB_UNAVAILABLE'
    ]
];
file_put_contents($diagFile, json_encode($outageEntry));

$signals = $signalSource->store();
$assert(($signals['forecasting_failure']['value'] ?? 0) === 1, 'Preserved local diagnostic from database outage produces failure signal');
$assert(str_contains($signals['forecasting_failure']['explanation'] ?? '', 'connection refused'), 'Signal explanation contains preserved outage diagnostic');

@unlink($diagFile);

if ($failures) {
    fwrite(STDERR, "\nForecasting notices contract failed (" . count($failures) . " errors):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nForecasting notices contract: passed\n";
