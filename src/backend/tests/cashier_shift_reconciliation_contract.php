<?php
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\CashierShiftService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
$pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT);
    INSERT INTO users VALUES (1, 'Casey'),(2, 'Morgan');
    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT);
    INSERT INTO registers VALUES (10, 'Front');
    CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, register_id INTEGER,
        status TEXT, locked_at TEXT, opening_cash REAL, opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT, expected_cash REAL, actual_cash REAL, cash_variance REAL, closing_notes TEXT,
        closed_by INTEGER, intervention_reason TEXT,
        reviewed_by INTEGER, reviewed_at TEXT, variance_threshold REAL, variance_review_required INTEGER,
        payment_totals TEXT);
    INSERT INTO cashier_shifts (shift_id,cashier_id,register_id,status,opening_cash) VALUES (20,1,10,'open',200);
    CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, shift_id INTEGER, total_amount REAL,
        payment_method TEXT, discount_amount REAL);
    INSERT INTO sales VALUES (1,20,250,'cash',0),(2,20,75,'card',0),(3,20,50,'ewallet',0);
    CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, shift_id INTEGER, refund_amount REAL, payment_method TEXT);
    INSERT INTO cash_refunds VALUES (1,20,20,'cash'),(2,20,10,'card');
    CREATE TABLE sale_reversals (reversal_id INTEGER PRIMARY KEY, sale_id INTEGER, refund_amount REAL,
        status TEXT, settlement_method TEXT);
    CREATE TABLE cash_drawer_movements (shift_id INTEGER, movement_type TEXT, amount REAL);
    INSERT INTO cash_drawer_movements VALUES (20,'cash_in',30),(20,'cash_out',15),(20,'safe_drop',25);
    CREATE TABLE held_sales (held_sale_id INTEGER PRIMARY KEY, shift_id INTEGER, status TEXT,
        reference_no TEXT, customer_label TEXT, item_count INTEGER, total_amount REAL, created_at TEXT, expires_at TEXT);
    CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT);
    CREATE TABLE activity_log (log_id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, category TEXT,
        module TEXT, record_id INTEGER, previous_value TEXT, new_value TEXT, ip_address TEXT);");

$service = new CashierShiftService($pdo);
$expected = 420.00; // 200 + 250 + 30 - 20 - 15 - 25; non-cash entries are excluded.
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};
$refused = static function (callable $action, string $message) use (&$failures): void {
    try { $action(); $failures[] = $message; } catch (DomainException | InvalidArgumentException $expected) {}
};

$check((float)$service->calculateShift(20)['calculated_expected_cash'] === $expected, 'expected cash follows only drawer cash');
$refused(fn() => $service->closeShift(1, 310, ''), 'material variance without a reason is refused');
$check((int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status='open'")->fetchColumn() === 1,
    'refused count leaves shift open');
$summary = $service->closeShift(1, 320, 'Drawer was short after count');
$check((float)$summary['expected_cash'] === $expected && (float)$summary['cash_variance'] === -100.00,
    'Cashier receives expected cash and variance after submission');
$check((int)$summary['variance_review_required'] === 0, 'exactly PHP 100 needs no review');
$totals = json_decode((string)$summary['payment_totals'], true);
$check($totals === ['cash' => 250, 'card' => 75, 'ewallet' => 50], 'closure stores payment-method totals');
$audit = $pdo->query("SELECT * FROM activity_log WHERE action='Cashier Shift closed'")->fetch(PDO::FETCH_ASSOC);
$details = $audit ? json_decode((string)$audit['new_value'], true) : null;
$check($audit !== false && (int)$audit['record_id'] === 20 && $audit['category'] === 'store_operation',
    'closure creates a Protected Audit Record');
$check(($details['expected_cash'] ?? null) == $expected && ($details['cash_variance'] ?? null) == -100,
    'audit records reconciliation result');
$check((int)$summary['closed_by'] === 1 && $summary['intervention_reason'] === null,
    'owner closure records the owning Cashier as closing actor');

$pdo->exec("INSERT INTO cashier_shifts (shift_id,cashier_id,register_id,status,opening_cash) VALUES (21,1,10,'open',100);
    INSERT INTO attention_settings VALUES ('store','cash_variance_amount','50')");
$refused(fn() => $service->closeShift(1, 0, ''), 'configured threshold applies to closure');
$preview = $service->previewReconciliation(1, 0);
$check($preview['expected_cash'] === 100.0 && $preview['variance_review_required'],
    'submitted count reveals expected cash and review flag');
$flagged = $service->closeShift(1, 0, 'Counted twice; float is missing');
$check((int)$flagged['variance_review_required'] === 1 && (float)$flagged['variance_threshold'] === 50.0,
    'material variance is flagged with its threshold snapshot without blocking closure');

$pdo->exec("INSERT INTO cashier_shifts (shift_id,cashier_id,register_id,status,opening_cash,locked_at) VALUES (23,1,10,'open',100,'2026-09-30 09:00:00')");
$refused(fn() => $service->closeShift(1, 100, ''), 'owner cannot close a locked Register');
$refused(fn() => $service->closeAbandonedShift(2, 'cashier', 1, 100, '', 'Cashier left'), 'Cashier cannot intervene');
$refused(fn() => $service->closeAbandonedShift(2, 'super_admin', 1, 100, '', 'Cashier left'), 'Super Administrator cannot intervene');
$refused(fn() => $service->closeAbandonedShift(2, 'admin', 1, 100, '', '  '), 'intervention reason is required');
$check((int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE shift_id=23 AND status='open'")->fetchColumn() === 1,
    'refused intervention leaves the Cashier Shift open');
$intervened = $service->closeAbandonedShift(2, 'admin', 1, 100, '', 'Cashier left without reconciling');
$check((int)$intervened['cashier_id'] === 1 && (int)$intervened['closed_by'] === 2
    && $intervened['intervention_reason'] === 'Cashier left without reconciling'
    && (float)$intervened['cash_variance'] === 0.0 && empty($intervened['variance_review_required']),
    'Administrator closes locked shift with original ownership and standard reconciliation');
$interventionAudit = $pdo->query("SELECT * FROM activity_log WHERE record_id=23 AND action='Cashier Shift closed'")->fetch(PDO::FETCH_ASSOC);
$interventionDetails = $interventionAudit ? json_decode((string)$interventionAudit['new_value'], true) : null;
$check($interventionAudit && (int)$interventionAudit['user_id'] === 2
    && ($interventionDetails['cashier_id'] ?? null) === 1
    && ($interventionDetails['closing_actor_id'] ?? null) === 2
    && ($interventionDetails['intervention_reason'] ?? null) === 'Cashier left without reconciling',
    'Protected Audit Record identifies both owner and intervening Administrator');

$pdo->exec("INSERT INTO cashier_shifts (shift_id,cashier_id,register_id,status,opening_cash) VALUES (24,1,10,'open',100);
    INSERT INTO held_sales (held_sale_id,shift_id,status,reference_no,item_count,total_amount) VALUES (1,24,'held','H-1',1,20)");
$refused(fn() => $service->closeAbandonedShift(2, 'admin', 1, 100, '', 'Cashier left'),
    'Administrator cannot close over an unresolved Held Sale');
$pdo->exec("UPDATE held_sales SET status='discarded' WHERE held_sale_id=1");
$refused(fn() => $service->closeAbandonedShift(2, 'admin', 1, 0, '', 'Cashier left'),
    'Administrator must explain a material variance as well as the intervention');
$flaggedIntervention = $service->closeAbandonedShift(2, 'admin', 1, 0, 'Drawer short on recount', 'Cashier left');
$check((int)$flaggedIntervention['variance_review_required'] === 1 && (float)$flaggedIntervention['variance_threshold'] === 50.0,
    'Administrator intervention preserves material-variance flagging');

$pdo->exec("INSERT INTO cashier_shifts (shift_id,cashier_id,register_id,status,opening_cash) VALUES (22,1,10,'open',0);
    CREATE TRIGGER fail_closing_audit BEFORE INSERT ON activity_log
    BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
try {
    $service->closeShift(1, 0, '');
    $failures[] = 'closure survives a failed Protected Audit Record';
} catch (PDOException $expected) {}
$check((int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE shift_id=22 AND status='open'")->fetchColumn() === 1,
    'audit failure rolls back closure');

if ($failures) {
    fwrite(STDERR, "Cashier Shift reconciliation contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Cashier Shift reconciliation contract: passed\n";
