<?php
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\StoreShiftReportService;

class ShiftReportCountingPDO extends PDO
{
    public int $queries = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries++;
        return parent::prepare($query, $options);
    }
}

$pdo = new ShiftReportCountingPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT);
 INSERT INTO users VALUES (1,'Alice'),(2,'Bob'),(3,'Administrator');
 CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT);
 INSERT INTO registers VALUES (10,'Front'),(11,'Back');
 CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, register_id INTEGER,
 opened_at TEXT, closed_at TEXT, status TEXT, opening_cash REAL, expected_cash REAL, actual_cash REAL,
 cash_variance REAL, variance_review_required INTEGER, closing_notes TEXT, closed_by INTEGER, intervention_reason TEXT);
 INSERT INTO cashier_shifts VALUES
 (20,1,10,'2026-09-30 09:00:00','2026-09-30 17:00:00','closed',100,120,90,-30,1,'Short',3,'Cashier left'),
 (21,2,11,'2026-09-30 10:00:00',NULL,'open',50,NULL,NULL,NULL,0,NULL,NULL,NULL),
 (19,1,NULL,'2026-09-29 09:00:00','2026-09-29 17:00:00','closed',20,20,20,0,0,NULL,1,NULL);
 CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, shift_id INTEGER, sale_date TEXT,
 total_amount REAL, payment_method TEXT, discount_amount REAL);
 INSERT INTO sales VALUES
 (1,1,20,'2026-09-30 12:00:00',50,'cash',0),(2,1,20,'2026-09-30 12:01:00',80,'card',0),
 (3,2,21,'2026-09-30 12:02:00',70,'ewallet',0),(4,1,NULL,'2026-09-30 12:03:00',30,'cash',0),
 (5,2,NULL,'2026-09-29 12:03:00',40,'card',0);
 CREATE TABLE cash_refunds (shift_id INTEGER, payment_method TEXT, refund_amount REAL);
 INSERT INTO cash_refunds VALUES (20,'cash',10),(20,'card',5);
 CREATE TABLE cash_drawer_movements (shift_id INTEGER, movement_type TEXT, amount REAL);
 INSERT INTO cash_drawer_movements VALUES (20,'cash_in',10),(20,'safe_drop',30);
 CREATE TABLE sale_reversals (sale_id INTEGER, requested_by INTEGER, status TEXT, settlement_method TEXT, refund_amount REAL, approved_at TEXT);
 INSERT INTO sale_reversals VALUES (4,1,'approved','cash',2,'2026-09-30 16:00:00');");

$service = new StoreShiftReportService($pdo);
$filters = ['from' => '2026-09-30', 'to' => '2026-09-30'];
$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    if (!$passed) $failures[] = $message;
};
$report = $service->report('admin', $filters);
$check(count($report['rows']) === 4, 'store-wide result includes both shifts and both unassigned record kinds');
$shift = array_values(array_filter($report['rows'], static fn($row) => $row['Shift ID'] === 20))[0] ?? [];
$check(($shift['Cash Sales'] ?? null) === '50.00' && ($shift['Card Sales'] ?? null) === '80.00'
    && ($shift['Cash Refunds'] ?? null) === '10.00' && ($shift['Card Refunds'] ?? null) === '5.00'
    && ($shift['Expected Cash'] ?? null) === '120.00' && ($shift['Counted Cash'] ?? null) === '90.00',
    'payments, refunds and counted drawer cash are separate');
$check(($shift['Material Variance'] ?? null) === 'Yes' && str_contains($shift['Closed By'] ?? '', 'Administrator')
    && ($shift['Intervention Reason'] ?? null) === 'Cashier left', 'variance and intervention are identifiable');
$open = array_values(array_filter($report['rows'], static fn($row) => $row['Shift ID'] === 21))[0] ?? [];
$check(($open['Expected Cash'] ?? null) === '50.00' && ($open['Counted Cash'] ?? null) === '',
    'open shift shows live expected cash without inventing a count');
$check($report['totals']['Total Sales'] === '230.00' && $report['totals']['Total Refunds'] === '17.00',
    'totals derive from visible rows');
$cashier = $service->report('admin', array_merge($filters, ['cashier_id' => '1', 'register_id' => '10', 'shift_id' => '20']));
$check(count($cashier['rows']) === 1 && $cashier['rows'][0]['Shift ID'] === 20, 'four filters compose');
$check(count($service->report('admin', array_merge($filters, ['shift_id' => 'legacy']))['rows']) === 2,
    'legacy shift filter excludes attributed shifts');
$check(count($service->report('admin', array_merge($filters, ['register_id' => '11']))['rows']) === 1,
    'register filter excludes unassigned records');
$check(count($service->report('admin', ['from' => '2026-09-29', 'to' => '2026-09-29'])['rows']) === 2,
    'date filter applies to shift opening and legacy event dates');
try { $service->report('cashier', $filters); $failures[] = 'Cashier access was allowed'; } catch (DomainException $expected) {}
try { $service->report('inventory_manager', $filters); $failures[] = 'Inventory Manager access was allowed'; } catch (DomainException $expected) {}
$stream = fopen('php://temp', 'w+');
StoreShiftReportService::csv($stream, $cashier);
rewind($stream);
$csv = [];
while (($line = fgetcsv($stream)) !== false) $csv[] = $line;
$check(count($csv) === 3 && $csv[0] === StoreShiftReportService::HEADERS
    && $csv[1][array_search('Shift ID', $csv[0], true)] === '20'
    && $csv[2][array_search('Total Sales', $csv[0], true)] === '130.00',
    'CSV exports the exact filtered row and its totals');
$page = file_get_contents(__DIR__ . '/../../frontend/components/administrator/shift_report.php');
$filterCode = substr($page, strpos($page, '$defaultFrom ='), strpos($page, '$service =') - strpos($page, '$defaultFrom ='));
foreach (['save_draft', 'mark_reviewed', 'reopen_review', 'export_csv'] as $action) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['from' => '2026-09-01', 'to' => '2026-09-30', 'cashier_id' => '1', 'register_id' => '10', 'shift_id' => '19'];
    $_POST = ['action' => $action, 'shift_id' => '20'];
    $retained = (static function () use ($filterCode): array { eval($filterCode); return $filters; })();
    $check($retained === array_replace($_GET, ['shift_id' => '20']), $action . ' retains query filters and gives posted shift precedence');
}
$check(str_contains($page, 'require_capability(RoleCapabilityPolicy::STORE_OPERATIONS)')
    && str_contains($page, 'csrf_verify()'), 'the report and export route enforce access and CSRF');
$pdo->exec("UPDATE users SET full_name='=2+2' WHERE user_id=1");
$stream = fopen('php://temp', 'w+');
StoreShiftReportService::csv($stream, $service->report('admin', array_merge($filters, ['shift_id' => '20'])));
rewind($stream);
fgetcsv($stream);
$formulaRow = fgetcsv($stream);
$check($formulaRow[array_search('Cashier', StoreShiftReportService::HEADERS, true)] === "'=2+2",
    'CSV neutralizes spreadsheet formulas in account names');
$pdo->exec("INSERT INTO sales VALUES (6,2,21,'2026-09-30 13:00:00',40,'cash',0);
    INSERT INTO cash_drawer_movements VALUES (21,'pay_in',7),(21,'cash_in',3),(21,'pay_out',4),(21,'cash_out',1),(21,'safe_drop',2);
    INSERT INTO cash_refunds VALUES (21,'cash',6),(21,'card',5),(21,'ewallet',4);
    INSERT INTO sale_reversals VALUES (6,2,'approved','cash',9,'2026-09-30 16:00:00'),
        (6,2,'pending','cash',100,'2026-09-30 16:00:00'),(6,2,'approved','card',20,'2026-09-30 16:00:00');");
$openReport = $service->report('admin', array_merge($filters, ['shift_id' => '21']))['rows'][0];
$drawer = (new App\Services\CashierShiftService($pdo))->calculateShift(21);
$check($openReport['Expected Cash'] === '78.00'
    && (float)$openReport['Expected Cash'] === $drawer['calculated_expected_cash'],
    'batched expected cash matches drawer calculation with movement aliases and approved cash reversals');
$check($openReport['Total Refunds'] === '15.00' && $openReport['E-Wallet Sales'] === '70.00'
    && $openReport['Sale Count'] === 2, 'independent aggregates do not multiply sales or refunds');
$pdo->queries = 0;
$smallReport = $service->report('admin', $filters);
$smallReportQueries = $pdo->queries;
for ($i = 1; $i <= 100; $i++) {
    $pdo->exec('INSERT INTO cashier_shifts SELECT shift_id + ' . ($i * 100) . ', cashier_id, register_id,
        opened_at, closed_at, status, opening_cash, expected_cash, actual_cash, cash_variance,
        variance_review_required, closing_notes, closed_by, intervention_reason FROM cashier_shifts WHERE shift_id = 20');
}
$pdo->queries = 0;
$largeReport = $service->report('admin', $filters);
echo 'Report queries (2 / 102 shifts): ' . $smallReportQueries . ' / ' . $pdo->queries . "\n";
$check($smallReportQueries === 7 && $pdo->queries === $smallReportQueries, 'report query count stays constant as shift count grows');
$check(count($largeReport['rows']) === count($smallReport['rows']) + 100, 'batching preserves every shift and legacy record');
if ($failures) {
    fwrite(STDERR, "Store Cashier Shift report contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Store Cashier Shift report contract: passed\n";
