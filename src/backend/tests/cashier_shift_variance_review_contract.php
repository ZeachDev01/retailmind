<?php
require_once __DIR__ . '/../bootstrap/app.php';



use App\Services\CashierShiftService;
use App\Services\StoreShiftReportService;
use App\Dashboard\StoreOperationsDashboardWorkspace;
use App\Attention\SystemClock;
use App\Authorization\RoleCapabilityPolicy;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'super_admin');
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, role_id INTEGER, status TEXT);
    INSERT INTO users VALUES (1, 'Alice Cashier', 2, 'active'), (2, 'Bob Admin', 1, 'active'), (3, 'Super Admin', 3, 'active');
    CREATE TABLE sale_items (sale_item_id INTEGER PRIMARY KEY, sale_id INTEGER, product_id INTEGER, quantity INTEGER, unit_price REAL, total_price REAL, cost_price REAL, discount_amount REAL, cost_recovery_status TEXT);

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT);
    INSERT INTO registers VALUES (10, 'Main Register');

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY,
        cashier_id INTEGER,
        register_id INTEGER,
        status TEXT,
        locked_at TEXT,
        opening_cash REAL,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT,
        expected_cash REAL,
        actual_cash REAL,
        cash_variance REAL,
        closing_notes TEXT,
        closed_by INTEGER,
        intervention_reason TEXT,
        reviewed_by INTEGER,
        reviewed_at TEXT,
        variance_threshold REAL,
        variance_review_required INTEGER DEFAULT 0,
        payment_totals TEXT,
        review_draft_notes TEXT,
        review_notes TEXT,
        review_shortage REAL,
        review_history TEXT
    );

    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY,
        cashier_id INTEGER,
        shift_id INTEGER,
        sale_date TEXT,
        total_amount REAL,
        payment_method TEXT,
        discount_type TEXT,
        discount_value REAL,
        discount_amount REAL
    );

    CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY,
        sale_id INTEGER,
        shift_id INTEGER,
        cashier_id INTEGER,
        refund_amount REAL,
        payment_method TEXT,
        reason TEXT,
        note TEXT,
        created_at TEXT
    );

    CREATE TABLE cash_drawer_movements (
        shift_id INTEGER,
        movement_type TEXT,
        amount REAL
    );

    CREATE TABLE held_sales (
        held_sale_id INTEGER PRIMARY KEY,
        shift_id INTEGER,
        status TEXT,
        reference_no TEXT,
        customer_label TEXT,
        item_count INTEGER,
        total_amount REAL,
        created_at TEXT,
        expires_at TEXT
    );

    CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY,
        sale_id INTEGER,
        reversal_type TEXT,
        status TEXT,
        settlement_method TEXT,
        refund_amount REAL,
        reason TEXT,
        requested_by INTEGER,
        approved_at TEXT,
        created_at TEXT
    );

    CREATE TABLE attention_settings (
        setting_scope TEXT,
        setting_key TEXT,
        setting_value TEXT,
        updated_by INTEGER,
        updated_at TEXT,
        PRIMARY KEY (setting_scope, setting_key)
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
        ip_address TEXT
    );

    CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT, cost_price REAL, reorder_level INTEGER, status TEXT);
    CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER);
    CREATE TABLE inventory_adjustments (adjustment_id INTEGER PRIMARY KEY, product_id INTEGER, adjustment_qty INTEGER, adjustment_type TEXT, reported_at TEXT, status TEXT);
    CREATE TABLE fiscal_periods (period_id INTEGER PRIMARY KEY, period_name TEXT, start_date TEXT, end_date TEXT, status TEXT);
    CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, completed_at TEXT);
    CREATE TABLE demand_forecasts (product_id INTEGER, job_id INTEGER, readiness_level TEXT);
    CREATE TABLE ml_model_evaluations (evaluation_id INTEGER PRIMARY KEY, horizon_days INTEGER, mae REAL, rmse REAL, wape REAL);
");

$service = new CashierShiftService($pdo);
$reportService = new StoreShiftReportService($pdo);
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

// 1. Threshold rule: abs($variance) >= $threshold and $variance != 0
// Store setting default: cash_variance_amount = 100.00
$pdo->exec("INSERT INTO attention_settings (setting_scope, setting_key, setting_value) VALUES ('store', 'cash_variance_amount', '100.00')");

// 1a. Zero difference -> variance_review_required = 0
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash, opened_at) VALUES (101, 1, 10, 'open', 100.00, '2026-10-01 08:00:00')");
$summary101 = $service->closeShift(1, 100.00, '', null, 101);
$check((int)$summary101['variance_review_required'] === 0, 'zero difference needs no review');
$check((float)$summary101['cash_variance'] === 0.00, 'zero difference recorded exactly');

// 1b. Below threshold (variance = -50.00, threshold = 100.00) -> variance_review_required = 0
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash, opened_at) VALUES (102, 1, 10, 'open', 100.00, '2026-10-01 09:00:00')");
$summary102 = $service->closeShift(1, 50.00, 'Small difference', null, 102);
$check((int)$summary102['variance_review_required'] === 0, 'difference below threshold needs no review');
$check((float)$summary102['cash_variance'] === -50.00, 'negative variance recorded');

// 1c. Equal threshold (variance = -100.00, threshold = 100.00) -> variance_review_required = 1 (inclusive >=)
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash, opened_at) VALUES (103, 1, 10, 'open', 200.00, '2026-10-01 10:00:00')");
$refused(fn() => $service->closeShift(1, 100.00, '', null, 103), 'equal threshold requires a reason at closure');
$summary103 = $service->closeShift(1, 100.00, 'Counted twice, 100 missing', null, 103);
$check((int)$summary103['variance_review_required'] === 1, 'difference exactly equal to threshold requires review');
$check((float)$summary103['variance_threshold'] === 100.00, 'snapshot threshold 100.00 recorded at closure');

// 1d. Above threshold (positive overage: variance = +150.00, threshold = 100.00) -> variance_review_required = 1
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash, opened_at) VALUES (104, 1, 10, 'open', 100.00, '2026-10-01 11:00:00')");
$summary104 = $service->closeShift(1, 250.00, 'Extra cash in drawer', null, 104);
$check((int)$summary104['variance_review_required'] === 1, 'positive difference above threshold requires review');
$check((float)$summary104['cash_variance'] === 150.00, 'positive variance recorded');

// 1e. Zero limit ($threshold = 0.00)
$pdo->exec("UPDATE attention_settings SET setting_value = '0.00' WHERE setting_key = 'cash_variance_amount'");
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash, opened_at) VALUES (105, 1, 10, 'open', 100.00, '2026-10-01 12:00:00')");
$summary105 = $service->closeShift(1, 100.00, '', null, 105);
$check((int)$summary105['variance_review_required'] === 0, 'zero limit with zero difference needs no review');

$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash, opened_at) VALUES (106, 1, 10, 'open', 100.00, '2026-10-01 13:00:00')");
$summary106 = $service->closeShift(1, 99.00, '1 peso short', null, 106);
$check((int)$summary106['variance_review_required'] === 1, 'zero limit with nonzero difference requires review');

// 2. Changed store limits do not clear required reviews
// Restore threshold to 500.00; Shift 103 (-100.00) must still require review!
$pdo->exec("UPDATE attention_settings SET setting_value = '500.00' WHERE setting_key = 'cash_variance_amount'");
$shift103Row = $pdo->query("SELECT * FROM cashier_shifts WHERE shift_id = 103")->fetch(PDO::FETCH_ASSOC);
$check((int)$shift103Row['variance_review_required'] === 1, 'subsequent store limit increase does not clear required review');
$check((float)$shift103Row['variance_threshold'] === 100.00, 'closing threshold snapshot is immutable');

// Dashboard attention queue reflects shifts requiring review
$clock = new class implements App\Attention\Clock {
    public function now(): DateTimeImmutable {
        return new DateTimeImmutable('2026-10-31 23:59:59');
    }
};
$dashboard = new StoreOperationsDashboardWorkspace($pdo, $clock, new RoleCapabilityPolicy());
$dashboardData = $dashboard->load('admin');
$exceptions = $dashboardData['exceptions'];
$shift103Exceptions = array_filter($exceptions, static fn($e) => str_contains($e['destination'], 'shift_id=103'));
$check(count($shift103Exceptions) === 1, 'Shift 103 is present in Attention queue');
$shift103Exc = array_values($shift103Exceptions)[0];
$check($shift103Exc['severity'] === 'warning', 'variance alert severity is warning not critical');
$check($shift103Exc['title'] === 'Needs review', 'variance alert titled Needs review');
$check(str_contains($shift103Exc['destination'], 'components/administrator/shift_report.php?shift_id=103'), 'deep links directly to exact shift in shift_report.php');

// 3. Deep links outside default date filters
// Shift 103 was opened on 2026-10-01. Report with default range for November 2026 must still return Shift 103 when shift_id=103 is requested!
$reportOutside = $reportService->report('admin', ['from' => '2026-11-01', 'to' => '2026-11-30', 'shift_id' => '103']);
$check(count($reportOutside['rows']) === 1 && $reportOutside['rows'][0]['Shift ID'] === 103, 'deep link opens exact shift outside date filters');
$row103 = $reportOutside['rows'][0];
$check($row103['Cashier'] === 'Alice Cashier', 'report displays Cashier');
$check($row103['Register'] === 'Main Register', 'report displays Register');
$check($row103['Expected Cash'] === '200.00', 'report displays expected cash');
$check($row103['Counted Cash'] === '100.00', 'report displays counted cash');
$check($row103['Variance'] === '-100.00', 'report displays signed difference');
$check($row103['Closing Notes'] === 'Counted twice, 100 missing', 'report displays closing notes');
$check($row103['Variance Explanation'] === 'Shortage: PHP 100.00 short', 'report explains negative difference as short');

$report104 = $reportService->report('admin', ['shift_id' => '104']);
$check($report104['rows'][0]['Variance Explanation'] === 'Overage: PHP 150.00 extra', 'report explains positive difference as extra');

// Smaller difference remains reportable
$report102 = $reportService->report('admin', ['shift_id' => '102']);
$check($report102['rows'][0]['Material Variance'] === 'No', 'below threshold marked not material');
$check($report102['rows'][0]['Variance'] === '-50.00', 'below threshold variance remains fully reported');

// 4. Draft notes saved independently without clearing attention
$refused(fn() => $service->saveReviewDraft(103, 1, 'cashier', 'Investigating register'), 'cashier cannot save review draft');
$draftResult = $service->saveReviewDraft(103, 2, 'admin', 'Checked till roll and CCTV; awaiting cashier interview');
$check($draftResult['review_draft_notes'] === 'Checked till roll and CCTV; awaiting cashier interview', 'draft notes saved');
$shift103AfterDraft = $pdo->query("SELECT * FROM cashier_shifts WHERE shift_id = 103")->fetch(PDO::FETCH_ASSOC);
$check($shift103AfterDraft['reviewed_at'] === null, 'saving draft does not set reviewed_at');
$check((int)$shift103AfterDraft['variance_review_required'] === 1, 'saving draft retains pending review attention');

// 5. Mark reviewed
$refused(fn() => $service->markReviewed(103, 2, 'admin', '   '), 'mark reviewed requires an explanation');
$refused(fn() => $service->markReviewed(103, 1, 'cashier', 'Finished'), 'cashier cannot mark reviewed');
$refused(fn() => $service->markReviewed(103, 3, 'super_admin', 'Finished'), 'super administrator in platform workspace cannot review');

$reviewedResult = $service->markReviewed(103, 2, 'admin', 'Investigation finished: misplaced drop envelope recovered PHP 0; remaining shortage confirmed', 100.00);
$check($reviewedResult['reviewed_by'] === 2, 'records reviewer id');
$check(!empty($reviewedResult['reviewed_at']), 'records reviewed timestamp');
$check($reviewedResult['review_notes'] === 'Investigation finished: misplaced drop envelope recovered PHP 0; remaining shortage confirmed', 'records review explanation');
$check((float)$reviewedResult['review_shortage'] === 100.00, 'explicitly records remaining shortage');
$check($reviewedResult['review_draft_notes'] === 'Checked till roll and CCTV; awaiting cashier interview', 'retains draft notes');

// Immutable closing evidence
$check((float)$reviewedResult['expected_cash'] === 200.00 && (float)$reviewedResult['actual_cash'] === 100.00 && (float)$reviewedResult['cash_variance'] === -100.00 && $reviewedResult['closing_notes'] === 'Counted twice, 100 missing', 'closing figures remain immutable after review');

// Verify attention queue: Shift 103 is no longer in attention
$dashboardAfterReview = $dashboard->load('admin');
$shift103InAttnAfter = array_filter($dashboardAfterReview['exceptions'], static fn($e) => str_contains($e['destination'], 'shift_id=103'));
$check(count($shift103InAttnAfter) === 0, 'reviewed shift cleared from Attention queue');

// 6. Reopen review
$refused(fn() => $service->reopenReview(103, 2, 'admin', '   '), 'reopen requires a reason');
$refused(fn() => $service->reopenReview(103, 1, 'cashier', 'Need another look'), 'cashier cannot reopen review');
$refused(fn() => $service->reopenReview(104, 2, 'admin', 'Not reviewed yet'), 'unreviewed shift cannot be reopened');

$reopenedResult = $service->reopenReview(103, 2, 'admin', 'Cashier found additional till receipt voucher');
$check($reopenedResult['reviewed_at'] === null, 'reopen clears reviewed_at');
$check($reopenedResult['reviewed_by'] === null, 'reopen clears reviewed_by');
$check((int)$reopenedResult['variance_review_required'] === 1, 'reopen restores attention');
$history = json_decode((string)$reopenedResult['review_history'], true);
$check(is_array($history) && count($history) >= 2, 'review history records both review and reopen events');
$check($history[0]['action'] === 'reviewed' && $history[0]['reviewed_by'] === 2 && (float)$history[0]['remaining_shortage'] === 100.00, 'history retains previous review conclusion and shortage');
$check($history[1]['action'] === 'reopened' && $history[1]['reason'] === 'Cashier found additional till receipt voucher', 'history retains reopen reason');

// Shift 103 is back in attention queue
$dashboardAfterReopen = $dashboard->load('admin');
$shift103InAttnReopen = array_filter($dashboardAfterReopen['exceptions'], static fn($e) => str_contains($e['destination'], 'shift_id=103'));
$check(count($shift103InAttnReopen) === 1, 'reopened shift restored to Attention queue');

if ($failures) {
    echo "\nCashier Shift variance review contract failed (" . count($failures) . " errors):\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "Cashier Shift variance review contract: passed\n";
