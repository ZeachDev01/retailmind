<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

use App\Services\GCashTransactionService;
use App\Dashboard\StoreOperationsDashboardWorkspace;
use App\Attention\SystemClock;
use App\Authorization\RoleCapabilityPolicy;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$pdo->sqliteCreateFunction('CURRENT_TIMESTAMP', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'super_admin');

    CREATE TABLE users (user_id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, role_id INTEGER, status TEXT, must_change_password INTEGER DEFAULT 0);
    INSERT INTO users VALUES
        (1, 'alice', 'Alice Cashier', 2, 'active', 0),
        (2, 'bob', 'Bob Admin', 1, 'active', 0),
        (3, 'charlie', 'Charlie Super', 3, 'active', 0);

    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY,
        cashier_id INTEGER,
        register_id INTEGER DEFAULT 1,
        status TEXT DEFAULT 'closed',
        opening_cash REAL DEFAULT 100.0,
        opened_at TEXT DEFAULT '2026-10-01 08:00:00',
        closed_at TEXT DEFAULT '2026-10-01 17:00:00',
        expected_cash REAL DEFAULT 100.0,
        actual_cash REAL DEFAULT 100.0,
        cash_variance REAL DEFAULT 0.0,
        variance_review_required INTEGER DEFAULT 0,
        reviewed_at TEXT,
        reviewed_by INTEGER
    );
    INSERT INTO cashier_shifts (shift_id, cashier_id) VALUES (1, 1), (2, 1), (3, 1), (4, 1), (5, 1);

    CREATE TABLE gcash_service_sessions (
        session_id INTEGER PRIMARY KEY,
        shift_id INTEGER,
        cashier_id INTEGER,
        opening_service_cash REAL DEFAULT 500.0,
        opening_wallet_balance REAL DEFAULT 1000.0,
        opened_at TEXT DEFAULT '2026-10-01 08:00:00',
        status TEXT DEFAULT 'closed',
        expected_service_cash REAL,
        expected_wallet_balance REAL,
        closing_service_cash REAL,
        closing_wallet_balance REAL,
        cash_variance REAL DEFAULT 0.0,
        wallet_variance REAL DEFAULT 0.0,
        closing_notes TEXT,
        closed_at TEXT DEFAULT '2026-10-01 17:00:00',
        closed_by INTEGER,
        unresolved_carryover_count INTEGER DEFAULT 0,
        waiver_notes TEXT,
        waived_by INTEGER,
        waived_at TEXT,
        reviewed_by INTEGER,
        reviewed_at TEXT,
        review_notes TEXT,
        review_draft_notes TEXT,
        review_shortage REAL,
        review_history TEXT,
        cash_check_explanation TEXT,
        wallet_check_explanation TEXT
    );

    CREATE TABLE gcash_transactions (
        transaction_id INTEGER PRIMARY KEY,
        session_id INTEGER,
        shift_id INTEGER,
        cashier_id INTEGER,
        type TEXT,
        amount REAL,
        fee REAL,
        status TEXT,
        created_at TEXT
    );

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY,
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

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY,
        period_name TEXT,
        start_date TEXT,
        end_date TEXT,
        status TEXT
    );
    INSERT INTO fiscal_periods VALUES (1, 'October 2026', '2026-10-01', '2026-10-31', 'open');

    CREATE TABLE attention_settings (
        setting_scope TEXT,
        setting_key TEXT,
        setting_value TEXT,
        updated_by INTEGER,
        updated_at TEXT,
        PRIMARY KEY (setting_scope, setting_key)
    );
    CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT, cost_price REAL, reorder_level INTEGER, status TEXT);
    CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER);
    CREATE TABLE inventory_adjustments (adjustment_id INTEGER PRIMARY KEY, product_id INTEGER, adjustment_qty INTEGER, adjustment_type TEXT, reported_at TEXT, status TEXT);
    CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, completed_at TEXT);
    CREATE TABLE demand_forecasts (product_id INTEGER, job_id INTEGER, readiness_level TEXT);
    CREATE TABLE ml_model_evaluations (evaluation_id INTEGER PRIMARY KEY, horizon_days INTEGER, mae REAL, rmse REAL, wape REAL);
    CREATE TABLE held_sales (held_sale_id INTEGER PRIMARY KEY, shift_id INTEGER, status TEXT, created_at TEXT, expires_at TEXT);
    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT);
");

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
        echo "FAIL: {$message}\n";
    }
};
$refused = static function (callable $action, string $message) use (&$failures): void {
    try {
        $action();
        $failures[] = $message;
        echo "FAIL: {$message}\n";
    } catch (DomainException | InvalidArgumentException $e) {
        // Expected
    }
};

$service = new GCashTransactionService($pdo, ['enabled' => true]);

// Session 1: Shortage (cash_variance = -150.00, wallet_variance = 0.00)
$pdo->exec("INSERT INTO gcash_service_sessions
    (session_id, shift_id, cashier_id, status, expected_service_cash, closing_service_cash, cash_variance, expected_wallet_balance, closing_wallet_balance, wallet_variance, closing_notes, closed_by)
    VALUES (1, 1, 1, 'closed', 500.00, 350.00, -150.00, 1000.00, 1000.00, 0.00, 'Drawer envelope missing 150', 1)");

// Session 2: Excess (cash_variance = 0.00, wallet_variance = 200.00)
$pdo->exec("INSERT INTO gcash_service_sessions
    (session_id, shift_id, cashier_id, status, expected_service_cash, closing_service_cash, cash_variance, expected_wallet_balance, closing_wallet_balance, wallet_variance, closing_notes, closed_by)
    VALUES (2, 2, 1, 'closed', 500.00, 500.00, 0.00, 1000.00, 1200.00, 200.00, 'Extra wallet balance', 1)");

// Session 3: Mixed shortage and excess (cash_variance = -100.00, wallet_variance = 50.00)
// Excess must NEVER conceal shortage: remaining shortage must be 100.00, not 50.00!
$pdo->exec("INSERT INTO gcash_service_sessions
    (session_id, shift_id, cashier_id, status, expected_service_cash, closing_service_cash, cash_variance, expected_wallet_balance, closing_wallet_balance, wallet_variance, closing_notes, closed_by)
    VALUES (3, 3, 1, 'closed', 500.00, 400.00, -100.00, 1000.00, 1050.00, 50.00, 'Mixed cash short wallet extra', 1)");

// 1. Role and Workspace restrictions
$refused(fn() => $service->markReviewed(1, 'cashier', 1, [
    'explanation' => 'Cashier self-review',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Checked physical cash',
    'wallet_explanation' => 'Checked GCash wallet app',
]), 'cashier workspace cannot mark closing difference reviewed');

$refused(fn() => $service->markReviewed(3, 'super_admin', 1, [
    'explanation' => 'Super admin review in governance workspace',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Checked physical cash',
    'wallet_explanation' => 'Checked GCash wallet app',
]), 'super administrator in platform workspace cannot review');

// Remote context refusal
$_SERVER['HTTP_CF_RAY'] = 'test-remote-ray-123';
$refused(fn() => $service->markReviewed(2, 'admin', 1, [
    'explanation' => 'Remote review attempt',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Checked physical cash',
    'wallet_explanation' => 'Checked GCash wallet app',
]), 'cannot review during remote monitoring');
unset($_SERVER['HTTP_CF_RAY']);

// 2. Draft notes saved independently without clearing attention
$refused(fn() => $service->saveReviewDraft(1, 'cashier', 1, 'Draft investigation'), 'cashier cannot save review draft');
$draftResult = $service->saveReviewDraft(2, 'admin', 1, 'Counting physical envelope and cross-checking shift drawer');
$check($draftResult['review_draft_notes'] === 'Counting physical envelope and cross-checking shift drawer', 'draft notes saved');

$session1AfterDraft = $pdo->query("SELECT * FROM gcash_service_sessions WHERE session_id = 1")->fetch(PDO::FETCH_ASSOC);
$check($session1AfterDraft['waived_at'] === null && $session1AfterDraft['reviewed_at'] === null, 'saving draft leaves attention pending');

// 3. Required explanations and checks
$refused(fn() => $service->markReviewed(2, 'admin', 1, [
    'explanation' => '   ',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Checked cash',
    'wallet_explanation' => 'Checked wallet',
]), 'empty explanation refused');

$refused(fn() => $service->markReviewed(2, 'admin', 1, [
    'explanation' => 'Valid investigation conclusion',
    'cash_checked' => false,
    'wallet_checked' => true,
    'cash_explanation' => 'Checked cash',
    'wallet_explanation' => 'Checked wallet',
]), 'unconfirmed cash check refused');

$refused(fn() => $service->markReviewed(2, 'admin', 1, [
    'explanation' => 'Valid investigation conclusion',
    'cash_checked' => true,
    'wallet_checked' => false,
    'cash_explanation' => 'Checked cash',
    'wallet_explanation' => 'Checked wallet',
]), 'unconfirmed wallet check refused');

$refused(fn() => $service->markReviewed(2, 'admin', 1, [
    'explanation' => 'Valid investigation conclusion',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => '   ',
    'wallet_explanation' => 'Checked wallet',
]), 'empty cash explanation refused');

$refused(fn() => $service->markReviewed(2, 'admin', 1, [
    'explanation' => 'Valid investigation conclusion',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Checked cash',
    'wallet_explanation' => '   ',
]), 'empty wallet explanation refused');

// 4. Mark reviewed: Shortage (Session 1: -150.00 cash variance)
$reviewed1 = $service->markReviewed(2, 'admin', 1, [
    'explanation' => 'Shortage confirmed after full audit: physical drop envelope misplaced, unrecoverable',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Physical cash counted twice; PHP 150 missing from expected service pouch',
    'wallet_explanation' => 'GCash app transaction history and balance match expected balance exactly',
]);

$check((int)$reviewed1['reviewed_by'] === 2, 'records reviewer ID');
$check(!empty($reviewed1['reviewed_at']), 'records review timestamp');
$check((int)$reviewed1['waived_by'] === 2 && !empty($reviewed1['waived_at']), 'maintains backwards-compatible waived_by and waived_at');
$check($reviewed1['review_notes'] === 'Shortage confirmed after full audit: physical drop envelope misplaced, unrecoverable', 'records investigation explanation');
$check((float)$reviewed1['review_shortage'] === 150.00, 'shortage explicitly recorded as positive 150.00');
$check($reviewed1['cash_check_explanation'] === 'Physical cash counted twice; PHP 150 missing from expected service pouch', 'records cash check explanation');
$check($reviewed1['wallet_check_explanation'] === 'GCash app transaction history and balance match expected balance exactly', 'records wallet check explanation');

// Immutable closing evidence verification
$check((float)$reviewed1['expected_service_cash'] === 500.00 &&
       (float)$reviewed1['closing_service_cash'] === 350.00 &&
       (float)$reviewed1['cash_variance'] === -150.00 &&
       (float)$reviewed1['expected_wallet_balance'] === 1000.00 &&
       (float)$reviewed1['closing_wallet_balance'] === 1000.00 &&
       (float)$reviewed1['wallet_variance'] === 0.00 &&
       $reviewed1['closing_notes'] === 'Drawer envelope missing 150',
       'Session 1 closing counts and variances remain strictly immutable');

// Check history and non-implication disclaimer
$hist1 = json_decode((string)$reviewed1['review_history'], true);
$check(is_array($hist1) && count($hist1) === 1, 'review history recorded');
$check($hist1[0]['action'] === 'reviewed', 'history action is reviewed');
$check((float)$hist1[0]['remaining_shortage'] === 150.00, 'history preserves explicit shortage');
$check(str_contains((string)($hist1[0]['disclaimer'] ?? ''), 'neither recovery, debt assignment nor wage-deduction'),
       'history contains disclaimer that review implies neither recovery nor payroll deductions');

// Activity log check
$logStmt = $pdo->prepare("SELECT * FROM activity_log WHERE module = 'GCash' AND record_id = 1 ORDER BY log_id DESC LIMIT 1");
$logStmt->execute();
$log1 = $logStmt->fetch(PDO::FETCH_ASSOC);
$check($log1 !== false && $log1['action'] === 'GCash closing difference reviewed', 'activity log records review action');
$meta1 = json_decode((string)$log1['metadata'], true);
$check((float)$meta1['remaining_shortage'] === 150.00, 'activity log metadata includes remaining shortage');
$check(str_contains((string)($meta1['disclaimer'] ?? ''), 'neither recovery, debt assignment nor wage-deduction'),
       'activity log includes disclaimer against wage deduction and debt assignment');

// Duplicate review refusal
$refused(fn() => $service->markReviewed(2, 'admin', 1, [
    'explanation' => 'Second review',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Checked cash',
    'wallet_explanation' => 'Checked wallet',
]), 'already reviewed session cannot be reviewed again');

// 5. Mark reviewed: Excess (Session 2: +200.00 wallet variance)
$reviewed2 = $service->markReviewed(2, 'admin', 2, [
    'explanation' => 'Excess verified: customer overpayment refunded outside system',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Physical cash pouch reconciled with no variance',
    'wallet_explanation' => 'Wallet balance has PHP 200 excess attributable to extra transfer',
]);
$check((float)$reviewed2['review_shortage'] === 0.00, 'excess records zero remaining shortage');

// 6. Mark reviewed: Mixed shortage and excess (Session 3: -100 cash, +50 wallet)
// Excess cannot offset or conceal shortage!
$reviewed3 = $service->markReviewed(2, 'admin', 3, [
    'explanation' => 'Physical shortage investigated; wallet excess does not offset cash missing',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Cash is 100 short in physical count',
    'wallet_explanation' => 'Wallet is 50 extra in GCash balance',
]);
$check((float)$reviewed3['review_shortage'] === 100.00, 'excess does not conceal shortage; remaining shortage is 100.00');

// Explicit custom remaining shortage (e.g. 40.00 recovered, 60.00 remains)
$pdo->exec("INSERT INTO gcash_service_sessions
    (session_id, shift_id, cashier_id, status, expected_service_cash, closing_service_cash, cash_variance, expected_wallet_balance, closing_wallet_balance, wallet_variance, closing_notes, closed_by)
    VALUES (4, 4, 1, 'closed', 500.00, 400.00, -100.00, 1000.00, 1000.00, 0.00, '100 short', 1)");

$refused(fn() => $service->markReviewed(2, 'admin', 4, [
    'explanation' => 'Negative shortage test',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Checked',
    'wallet_explanation' => 'Checked',
    'remaining_shortage' => -10.00,
]), 'negative remaining shortage refused');

$reviewed4 = $service->markReviewed(2, 'admin', 4, [
    'explanation' => 'Partial recovery investigated; 60 remains unresolved',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'Cash envelope had 40 misplaced behind drawer',
    'wallet_explanation' => 'Wallet balance matches',
    'remaining_shortage' => 60.00,
]);
$check((float)$reviewed4['review_shortage'] === 60.00, 'explicit remaining shortage recorded accurately');

// 7. Reopening review
$refused(fn() => $service->reopenReview(2, 'admin', 1, '   '), 'empty reopen reason refused');
$refused(fn() => $service->reopenReview(1, 'cashier', 1, 'Cashier reopening'), 'cashier cannot reopen review');
$refused(fn() => $service->reopenReview(3, 'super_admin', 1, 'Super admin reopening'), 'super admin cannot reopen review');

// Session 5: Unreviewed session cannot be reopened
$pdo->exec("INSERT INTO gcash_service_sessions
    (session_id, shift_id, cashier_id, status, expected_service_cash, closing_service_cash, cash_variance, expected_wallet_balance, closing_wallet_balance, wallet_variance, closing_notes, closed_by)
    VALUES (5, 5, 1, 'closed', 500.00, 400.00, -100.00, 1000.00, 1000.00, 0.00, 'Unreviewed', 1)");
$refused(fn() => $service->reopenReview(2, 'admin', 5, 'Session not reviewed yet'), 'unreviewed session cannot be reopened');

// Reopen Session 1
$reopened1 = $service->reopenReview(2, 'admin', 1, 'Cashier provided supplemental statement and CCTV review requested');
$check($reopened1['reviewed_at'] === null && $reopened1['waived_at'] === null, 'reopen clears reviewed_at and waived_at');
$check($reopened1['reviewed_by'] === null && $reopened1['waived_by'] === null, 'reopen clears reviewed_by and waived_by');

// History retains previous conclusions and reopen reason
$histReopen = json_decode((string)$reopened1['review_history'], true);
$check(is_array($histReopen) && count($histReopen) === 2, 'review history retains previous review and append reopen event');
$check($histReopen[0]['action'] === 'reviewed' && (float)$histReopen[0]['remaining_shortage'] === 150.00, 'previous review conclusion preserved in history');
$check($histReopen[1]['action'] === 'reopened' && $histReopen[1]['reason'] === 'Cashier provided supplemental statement and CCTV review requested', 'reopen reason preserved in history');

// Immutable closing evidence verification after reopen
$check((float)$reopened1['expected_service_cash'] === 500.00 &&
       (float)$reopened1['closing_service_cash'] === 350.00 &&
       (float)$reopened1['cash_variance'] === -150.00 &&
       $reopened1['closing_notes'] === 'Drawer envelope missing 150',
       'Session 1 closing figures remain immutable after reopening');

// Reopened session can be reviewed again
$reReviewed1 = $service->markReviewed(2, 'admin', 1, [
    'explanation' => 'Second review concluded after CCTV check: shortage confirmed unrecoverable',
    'cash_checked' => true,
    'wallet_checked' => true,
    'cash_explanation' => 'CCTV verified no cash added to envelope',
    'wallet_explanation' => 'Wallet balance re-verified with provider',
]);
$check((int)$reReviewed1['reviewed_by'] === 2 && !empty($reReviewed1['reviewed_at']), 'reopened session successfully re-reviewed');
$histReReviewed = json_decode((string)$reReviewed1['review_history'], true);
$check(count($histReReviewed) === 3, 'history retains all 3 events (review, reopen, re-review)');

// 8. Backwards compatibility: waiveDiscrepancy still works
$pdo->exec("INSERT INTO gcash_service_sessions
    (session_id, shift_id, cashier_id, status, expected_service_cash, closing_service_cash, cash_variance, expected_wallet_balance, closing_wallet_balance, wallet_variance, closing_notes, closed_by)
    VALUES (6, 1, 1, 'closed', 500.00, 450.00, -50.00, 1000.00, 1000.00, 0.00, 'Legacy waive test', 1)");
$service->waiveDiscrepancy(2, 'admin', 6, 'Legacy waiver notes for session 6');
$s6Row = $pdo->query("SELECT * FROM gcash_service_sessions WHERE session_id = 6")->fetch(PDO::FETCH_ASSOC);
$check($s6Row['waived_at'] !== null && (int)$s6Row['waived_by'] === 2, 'waiveDiscrepancy retains backwards-compatible execution');

if ($failures) {
    echo "\nGCash closing difference review contract failed (" . count($failures) . " errors):\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "GCash closing difference review contract: passed\n";
