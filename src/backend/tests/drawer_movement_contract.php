<?php
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\CashierShiftService;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};
$refused = static function (callable $action, string $message) use (&$failures): void {
    try {
        $action();
        $failures[] = $message;
    } catch (DomainException | InvalidArgumentException $expected) {
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, password_hash TEXT, role_id INTEGER, status TEXT DEFAULT 'active', must_change_password INTEGER DEFAULT 0);
        INSERT INTO users (user_id,full_name) VALUES (1, 'Casey'), (2, 'Dana');
        CREATE TABLE roles (role_id INTEGER PRIMARY KEY,role_name TEXT);
        INSERT INTO roles VALUES (1,'admin'),(2,'super_admin'),(3,'cashier');
        CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT);
        INSERT INTO registers VALUES (10, 'Front');
        CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER NOT NULL,
            register_id INTEGER, status TEXT NOT NULL, locked_at TEXT, opening_cash REAL NOT NULL,
            opened_at TEXT DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO cashier_shifts VALUES (20, 1, 10, 'open', NULL, 100.00, CURRENT_TIMESTAMP);
        CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, shift_id INTEGER, total_amount REAL,
            payment_method TEXT, discount_amount REAL);
        CREATE TABLE sale_reversals (reversal_id INTEGER PRIMARY KEY, sale_id INTEGER,
            refund_amount REAL, status TEXT, settlement_method TEXT);
        CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, shift_id INTEGER,
            refund_amount REAL, payment_method TEXT);
        CREATE TABLE cash_drawer_movements (drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER NOT NULL, cashier_id INTEGER NOT NULL, movement_type TEXT NOT NULL,
            amount REAL NOT NULL, reason TEXT NOT NULL, note TEXT, recorded_by INTEGER NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE activity_log (log_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
            action TEXT, category TEXT, module TEXT, record_id INTEGER, previous_value TEXT,
            new_value TEXT, ip_address TEXT);");
    $service = new CashierShiftService($pdo);
    $pdo->prepare("INSERT INTO users (user_id,full_name,username,password_hash,role_id) VALUES (3,'Owner','owner',?,1)")->execute([password_hash('owner-password', PASSWORD_DEFAULT)]);
    $refused(fn() => $service->addDrawerMovement(1, 'admin', 'cash_in', 10, 'additional_float'), 'non-Cashier workspace posts a movement');
    $refused(fn() => $service->addDrawerMovement(2, 'cashier', 'cash_in', 10, 'additional_float'), 'another Cashier posts to Casey’s shift');
    foreach ([0, -1, INF, NAN, 0.001] as $amount) {
        $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'cash_in', $amount, 'additional_float'), 'invalid amount is accepted');
    }
    $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'cash_out', 10, 'bank_deposit'), 'reason for a different type is accepted');
    $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'pay_in', 10, 'other'), 'legacy type is accepted for a new movement');

    $in = $service->addDrawerMovement(1, 'cashier', 'cash_in', 25.50, 'additional_float', 'Change float');
    $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'cash_out', 10.25, 'petty_cash'), 'spending without approval succeeds');
    $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'cash_out', 10.25, 'supplier_payment', null, 'owner', 'wrong'), 'incorrect approval succeeds');
    $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'cash_out', 1, 'other'), 'Other explanation missing');
    $out = $service->addDrawerMovement(1, 'cashier', 'cash_out', 10.25, 'petty_cash', null, 'owner', 'owner-password');
    $drop = $service->addDrawerMovement(1, 'cashier', 'safe_drop', 40.00, 'excess_cash');
    $check(count(array_unique([$in, $out, $drop])) === 3, 'each movement has its own identity');
    $rows = $pdo->query('SELECT * FROM cash_drawer_movements ORDER BY drawer_movement_id')->fetchAll(PDO::FETCH_ASSOC);
    $check(count($rows) === 3, 'three movements are stored separately from sales');
    $check((int)$rows[0]['shift_id'] === 20 && (int)$rows[0]['cashier_id'] === 1 && (int)$rows[0]['recorded_by'] === 1, 'movement names shift, Cashier, and actor');
    $check($rows[0]['note'] === 'Change float' && $rows[1]['note'] === null && $rows[0]['created_at'] !== null, 'note and timestamp are stored');
    $check((float)$service->calculateShift(20)['calculated_expected_cash'] === 75.25, 'cash-in adds and cash-out and safe-drop subtract from expected cash');
    $check((int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0, 'movements never become sales');
    $audits = $pdo->query('SELECT * FROM activity_log ORDER BY log_id')->fetchAll(PDO::FETCH_ASSOC);
    $check(count($audits) === 3, 'every movement has a Protected Audit Record');
    $check(json_decode($audits[1]['new_value'], true)['authorization']['approved_by'] === 3, 'spending approval bound to audited movement');
    foreach ($audits as $i => $audit) {
        $details = json_decode($audit['new_value'], true);
        $check((int)$audit['user_id'] === 1 && $audit['category'] === 'store_operation'
            && (int)$audit['record_id'] === (int)$rows[$i]['drawer_movement_id']
            && (int)$details['shift_id'] === 20 && (int)$details['actor_id'] === 1,
            'audit names its actor, movement, and shift');
    }
    $pdo->exec("CREATE TRIGGER refuse_movement_audit BEFORE INSERT ON activity_log
        BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    try {
        $service->addDrawerMovement(1, 'cashier', 'cash_in', 5, 'other', 'Actual top-up');
        $failures[] = 'movement survives a failed Protected Audit Record';
    } catch (PDOException $expected) {
    }
    $pdo->exec('DROP TRIGGER refuse_movement_audit');
    $check((int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn() === 3,
        'movement and Protected Audit Record commit together');
    $pdo->exec("UPDATE cashier_shifts SET locked_at = CURRENT_TIMESTAMP WHERE shift_id = 20");
    $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'cash_in', 10, 'other', 'Actual top-up'), 'locked shift accepts a movement');
    $pdo->exec("UPDATE cashier_shifts SET locked_at = NULL, status = 'closed' WHERE shift_id = 20");
    $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'cash_in', 10, 'other', 'Actual top-up'), 'closed shift accepts a movement');
    $check((int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn() === 3, 'refusals leave the ledger untouched');
    $pdo->exec("UPDATE cashier_shifts SET status='open' WHERE shift_id=20");
    foreach (['cash_out', 'safe_drop'] as $type) {
        $refused(fn() => $service->addDrawerMovement(1, 'cashier', $type, 75.26, 'other', 'Recorded withdrawal'), 'payout exceeds expected cash');
    }
    $pdo->exec("INSERT INTO sales VALUES (1,20,1000,'card',0),(2,20,1000,'ewallet',0)");
    $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'safe_drop', 80, 'excess_cash'), 'noncash sale finances a payout');
    $service->addDrawerMovement(1, 'cashier', 'cash_in', 4.75, 'additional_float');
    $service->addDrawerMovement(1, 'cashier', 'safe_drop', 80, 'excess_cash');
    $check((float)$service->calculateShift(20)['calculated_expected_cash'] === 0.0, 'real recorded top-up permits exactly funded payout');
    $pdo->exec("UPDATE users SET status='disabled' WHERE user_id=3");
    $refused(fn() => $service->addDrawerMovement(1, 'cashier', 'cash_out', 1, 'petty_cash', null, 'owner', 'owner-password'), 'disabled approver authorizes spending');
    $pdo->exec("CREATE TABLE emergency_access_sessions (session_id INTEGER PRIMARY KEY,actor_user_id INTEGER,reason TEXT,expires_at TEXT,status TEXT)");
    $pdo->exec("UPDATE users SET status='active',role_id=2 WHERE user_id=3");
    $service->addDrawerMovement(1,'cashier','cash_in',10,'additional_float');
    $refused(fn() => $service->addDrawerMovement(1,'cashier','cash_out',1,'supplier_payment',null,'owner','owner-password'), 'Super Administrator authorizes without Emergency Access');
    $pdo->exec("INSERT INTO emergency_access_sessions VALUES (1,3,'Emergency Store continuity','2099-01-01 00:00:00','active')");
    $service->addDrawerMovement(1,'cashier','cash_out',1,'supplier_payment',null,'owner','owner-password');
    $audit=json_decode($pdo->query('SELECT new_value FROM activity_log ORDER BY log_id DESC LIMIT 1')->fetchColumn(),true);
    $check($audit['authorization']['emergency_session_id']===1 && $audit['amount']===1 && $audit['shift_id']===20 && $audit['reason']==='supplier_payment', 'Emergency approval binds identity, session, amount, reason and shift');
    $pdo->exec("UPDATE emergency_access_sessions SET expires_at='2000-01-01 00:00:00'");
    $refused(fn() => $service->addDrawerMovement(1,'cashier','cash_out',1,'supplier_payment',null,'owner','owner-password'), 'Expired Emergency Access authorizes spending');
} catch (Throwable $error) {
    $failures[] = $error->getMessage();
}
if ($failures) {
    fwrite(STDERR, "Drawer movement contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Drawer movement contract: passed\n";
