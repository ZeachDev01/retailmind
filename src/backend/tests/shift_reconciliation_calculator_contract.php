<?php
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\CashierShiftService;
use App\Services\StoreShiftReportService;
use App\Services\ShiftReconciliationCalculator;

class CountingPDO extends PDO
{
    public int $queries = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries++;
        return parent::prepare($query, $options);
    }
}

$pdo = new CountingPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, display_name TEXT, full_name TEXT, role_id INTEGER, status TEXT);
    INSERT INTO users VALUES (1, 'Alice Walker', 'Alice Walker', 2, 'active'), (2, 'Bob Admin', 'Bob Admin', 1, 'active');

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, status TEXT);
    INSERT INTO registers VALUES (10, 'Register 1', 'active'), (11, 'Register 2', 'active');

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, register_id INTEGER,
        status TEXT, locked_at TEXT, opening_cash REAL, opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT, expected_cash REAL, actual_cash REAL, cash_variance REAL, closing_notes TEXT,
        closed_by INTEGER, intervention_reason TEXT,
        reviewed_by INTEGER, reviewed_at TEXT, variance_threshold REAL, variance_review_required INTEGER,
        payment_totals TEXT
    );

    CREATE TABLE cashier_shift_payment_totals (
        shift_id INTEGER, payment_type TEXT, amount REAL, PRIMARY KEY (shift_id, payment_type)
    );

    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, shift_id INTEGER, sale_date TEXT,
        total_amount REAL, payment_method TEXT, discount_amount REAL
    );

    CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY, shift_id INTEGER, refund_amount REAL, payment_method TEXT
    );

    CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY, sale_id INTEGER, requested_by INTEGER, status TEXT,
        settlement_method TEXT, refund_amount REAL, approved_at TEXT
    );

    CREATE TABLE cash_drawer_movements (
        shift_id INTEGER, movement_type TEXT, amount REAL, recorded_by INTEGER, created_at TEXT
    );

    CREATE TABLE held_sales (
        held_sale_id INTEGER PRIMARY KEY, shift_id INTEGER, status TEXT,
        reference_no TEXT, customer_label TEXT, item_count INTEGER, total_amount REAL,
        created_at TEXT, expires_at TEXT
    );

    CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT);
    INSERT INTO attention_settings VALUES ('store', 'cash_variance_amount', '50');

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, category TEXT,
        module TEXT, record_id INTEGER, previous_value TEXT, new_value TEXT, ip_address TEXT
    );
");

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

// Fixture setup:
// Shift 101: mixed cash, card, ewallet, movements (cash_in, pay_in, cash_out, pay_out, safe_drop), refunds, reversals
// Opening cash = 150.00
// Cash sales: 100.50 + 25.25 = 125.75
// Card sales: 80.00
// E-wallet sales: 45.00
// Total sales: 250.75
// Cash in: 50.00 (cash_in) + 20.00 (pay_in) = 70.00
// Cash out: 15.00 (cash_out) + 10.00 (pay_out) = 25.00
// Safe drop: 30.00
// Cash refunds: 12.50 (cash) (plus 10.00 card refund which must NOT affect drawer cash)
// Approved cash reversals: 8.25 (plus 20.00 pending cash reversal and 15.00 approved card reversal which must NOT affect drawer cash)
// Expected cash:
// 150.00 + 125.75 + 70.00 - 25.00 - 30.00 - 12.50 - 8.25 = 270.00
$pdo->exec("
    INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash, opened_at)
    VALUES (101, 1, 10, 'open', 150.00, '2026-11-01 08:00:00');

    INSERT INTO sales (sale_id, cashier_id, shift_id, sale_date, total_amount, payment_method, discount_amount) VALUES
    (1, 1, 101, '2026-11-01 09:00:00', 100.50, 'cash', 0.00),
    (2, 1, 101, '2026-11-01 09:15:00', 25.25, 'cash', 0.00),
    (3, 1, 101, '2026-11-01 09:30:00', 80.00, 'card', 5.00),
    (4, 1, 101, '2026-11-01 09:45:00', 45.00, 'ewallet', 0.00);

    INSERT INTO cash_drawer_movements (shift_id, movement_type, amount, recorded_by, created_at) VALUES
    (101, 'cash_in', 50.00, 1, '2026-11-01 10:00:00'),
    (101, 'pay_in', 20.00, 1, '2026-11-01 10:10:00'),
    (101, 'cash_out', 15.00, 1, '2026-11-01 10:20:00'),
    (101, 'pay_out', 10.00, 1, '2026-11-01 10:30:00'),
    (101, 'safe_drop', 30.00, 1, '2026-11-01 10:40:00');

    INSERT INTO cash_refunds (refund_id, shift_id, refund_amount, payment_method) VALUES
    (1, 101, 12.50, 'cash'),
    (2, 101, 10.00, 'card');

    INSERT INTO sale_reversals (reversal_id, sale_id, requested_by, status, settlement_method, refund_amount, approved_at) VALUES
    (1, 1, 1, 'approved', 'cash', 8.25, '2026-11-01 11:00:00'),
    (2, 1, 1, 'pending', 'cash', 20.00, '2026-11-01 11:10:00'),
    (3, 3, 1, 'approved', 'card', 15.00, '2026-11-01 11:20:00');
");

// Shift 102: Empty shift (no sales, no movements, no refunds, no reversals)
// Opening cash = 200.00 -> Expected cash = 200.00
$pdo->exec("
    INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash, opened_at)
    VALUES (102, 2, 11, 'open', 200.00, '2026-11-01 12:00:00');
");

// 1. Direct Calculator Unit Assertions
$calculator = new ShiftReconciliationCalculator($pdo);

// Arithmetic sanity & penny exactness
$expectedExact = ShiftReconciliationCalculator::calculateExpectedCash(150.00, 125.75, 70.00, 25.00, 30.00, 12.50, 8.25);
$check($expectedExact === 270.00, 'authoritative arithmetic computes exact 270.00 for mixed shift');
$check(ShiftReconciliationCalculator::calculateVariance(260.00, 270.00) === -10.00, 'variance calculation computes exact shortage');
$check(ShiftReconciliationCalculator::calculateVariance(275.50, 270.00) === 5.50, 'variance calculation computes exact overage');

// Empty batch executes 0 queries
$pdo->queries = 0;
$emptyBatch = $calculator->calculateBatch([]);
$check($emptyBatch === [] && $pdo->queries === 0, 'empty batch runs 0 queries and returns empty array');

// Single shift calculation
$pdo->queries = 0;
$calc101 = $calculator->calculateForShift(101, 150.00);
$check($pdo->queries === 4, 'single shift calculation runs exactly 4 aggregate queries');
$check($calc101['sale_count'] === 4, 'single shift sale count matches');
$check($calc101['total_sales'] === 250.75, 'single shift total sales matches');
$check($calc101['cash_sales'] === 125.75, 'single shift cash sales matches');
$check($calc101['card_sales'] === 80.00, 'single shift card sales matches');
$check($calc101['ewallet_sales'] === 45.00, 'single shift ewallet sales matches');
$check($calc101['cash_in'] === 70.00, 'single shift cash in matches (including pay_in alias)');
$check($calc101['cash_out'] === 25.00, 'single shift cash out matches (including pay_out alias)');
$check($calc101['safe_drop'] === 30.00, 'single shift safe drop matches');
$check($calc101['cash_refunds'] === 12.50, 'single shift cash refunds matches');
$check($calc101['reversal_cash'] === 8.25, 'single shift approved cash reversal matches');
$check($calc101['calculated_expected_cash'] === 270.00, 'single shift expected cash matches authoritative calculation');

// Empty shift calculation
$calc102 = $calculator->calculateForShift(102, 200.00);
$check($calc102['sale_count'] === 0 && $calc102['cash_sales'] === 0.0, 'empty shift has 0 sales');
$check($calc102['calculated_expected_cash'] === 200.00, 'empty shift expected cash matches opening cash');

// Batch calculation across multiple shifts
$pdo->queries = 0;
$batch = $calculator->calculateBatch([101, 102], [101 => 150.00, 102 => 200.00]);
$check($pdo->queries === 4, 'batched calculation runs exactly 4 aggregate queries regardless of shift count');
$check(isset($batch[101]) && isset($batch[102]), 'batch result contains both shifts');
$check($batch[101]['calculated_expected_cash'] === 270.00, 'batch shift 101 expected cash matches single shift');
$check($batch[102]['calculated_expected_cash'] === 200.00, 'batch shift 102 expected cash matches single shift');

// 2. Integration with CashierShiftService (preview & closing)
$shiftService = new CashierShiftService($pdo, null, $calculator);

// Preview reconciliation for shift 101
$preview = $shiftService->previewReconciliation(1, 260.00);
$check($preview['shift_id'] === 101, 'preview returns shift ID');
$check($preview['expected_cash'] === 270.00, 'preview expected cash matches calculator');
$check((float)$preview['cash_variance'] === -10.00, 'preview cash variance matches calculator');
$check($preview['variance_review_required'] === false, '10 PHP shortage does not trigger 50 PHP threshold');

// 3. Integration with StoreShiftReportService (batched report)
$reportService = new StoreShiftReportService($pdo, null, $calculator);
$filters = ['from' => '2026-11-01', 'to' => '2026-11-01'];

$pdo->queries = 0;
$report = $reportService->report('admin', $filters);
$reportQueries = $pdo->queries;

$row101 = array_values(array_filter($report['rows'], static fn($r) => ($r['Shift ID'] ?? null) === 101))[0] ?? null;
$row102 = array_values(array_filter($report['rows'], static fn($r) => ($r['Shift ID'] ?? null) === 102))[0] ?? null;

$check($row101 !== null && $row102 !== null, 'report includes both open shifts');
$check($row101['Expected Cash'] === '270.00', 'report displays calculated expected cash for open shift 101 matching calculator');
$check($row102['Expected Cash'] === '200.00', 'report displays calculated expected cash for empty open shift 102');
$check($row101['Cash Sales'] === '125.75' && $row101['Total Sales'] === '250.75', 'report displays sales matching calculator');
$check($row101['Cash In'] === '70.00' && $row101['Cash Out'] === '25.00' && $row101['Safe Drops'] === '30.00', 'report displays movements matching calculator');
$check($row101['Cash Refunds'] === '12.50' && $row101['Total Refunds'] === '22.50', 'report displays refunds matching calculator');

// Verify 7-query ceiling in store shift reports
$check($reportQueries === 7, "store shift report runs exactly 7 queries (ran {$reportQueries})");

// No-match report runs 0 shift aggregate queries (ceiling <= 3 queries)
$pdo->queries = 0;
$noMatchReport = $reportService->report('admin', ['from' => '2026-10-01', 'to' => '2026-10-01']);
$check($pdo->queries <= 3, 'no-match report runs at most 3 queries (no per-shift queries)');
$check(count($noMatchReport['rows']) === 0, 'no-match report has 0 rows');

// 4. Closing shift 101 and verifying closed evidence immutability
$closeSummary = $shiftService->closeShift(1, 260.00, 'Ten pesos short');
$check((float)$closeSummary['expected_cash'] === 270.00, 'closeShift persists expected cash matching calculator');
$check((float)$closeSummary['cash_variance'] === -10.00, 'closeShift persists variance matching calculator');

// Report now reflects closed shift's frozen recorded evidence
$reportAfterClose = $reportService->report('admin', $filters);
$closedRow101 = array_values(array_filter($reportAfterClose['rows'], static fn($r) => ($r['Shift ID'] ?? null) === 101))[0] ?? null;
$check($closedRow101['Status'] === 'closed', 'shift 101 is now closed');
$check($closedRow101['Expected Cash'] === '270.00', 'closed shift continues displaying recorded expected cash');
$check($closedRow101['Counted Cash'] === '260.00', 'closed shift displays counted cash');
$check($closedRow101['Variance'] === '-10.00', 'closed shift displays recorded variance');
$check($closedRow101['Variance Explanation'] === 'Shortage: PHP 10.00 short', 'closed shift displays variance explanation');

if ($failures) {
    fwrite(STDERR, "Shift Reconciliation Calculator contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Shift Reconciliation Calculator contract: passed\n";
