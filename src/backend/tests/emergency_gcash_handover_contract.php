<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

use App\Services\GCashTransactionService;
use App\Services\CashierShiftService;
use App\Audit\AuditRecordCategory;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'super_admin');

    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, role_id INTEGER, status TEXT, must_change_password INTEGER DEFAULT 0);
    INSERT INTO users VALUES
        (1, 'Admin Bob', 'admin_bob', 1, 'active', 0),
        (2, 'Cashier Alice', 'cashier_alice', 2, 'active', 0),
        (3, 'Cashier Dave', 'cashier_dave', 2, 'active', 0),
        (4, 'Cashier Inactive', 'cashier_inactive', 2, 'disabled', 0);

    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));
    INSERT INTO user_roles VALUES (1, 1), (2, 2), (3, 2);

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active');
    INSERT INTO registers VALUES (10, 'Main Register', 'active'), (11, 'Second Register', 'active');

    CREATE TABLE held_sales (held_sale_id INTEGER PRIMARY KEY, reference_no TEXT, customer_label TEXT, cashier_id INTEGER, shift_id INTEGER, status TEXT, item_count INTEGER DEFAULT 0, total_amount REAL DEFAULT 0.00, created_at TEXT, expires_at TEXT);
    CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, shift_id INTEGER, payment_method TEXT, net_total REAL DEFAULT 0.00, total_amount REAL DEFAULT 0.00, discount_amount REAL DEFAULT 0.00, created_at TEXT);
    CREATE TABLE cash_drawer_movements (movement_id INTEGER PRIMARY KEY, shift_id INTEGER, movement_type TEXT, amount REAL, created_at TEXT);
    CREATE TABLE sale_reversals (reversal_id INTEGER PRIMARY KEY, sale_id INTEGER, refund_amount REAL DEFAULT 0.00, status TEXT DEFAULT 'approved', settlement_method TEXT DEFAULT 'cash', created_at TEXT);

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        register_id INTEGER,
        status TEXT DEFAULT 'open',
        opening_cash REAL DEFAULT 1000.0,
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
        variance_threshold REAL DEFAULT 100.0,
        variance_review_required INTEGER DEFAULT 0,
        payment_totals TEXT,
        locked_at TEXT
    );

    CREATE TABLE gcash_service_sessions (
        session_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER,
        cashier_id INTEGER,
        opening_service_cash REAL DEFAULT 0.00,
        opening_wallet_balance REAL DEFAULT 0.00,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        status TEXT DEFAULT 'open',
        expected_service_cash REAL,
        expected_wallet_balance REAL,
        closing_service_cash REAL,
        closing_wallet_balance REAL,
        cash_variance REAL,
        wallet_variance REAL,
        closing_notes TEXT,
        closed_at TEXT,
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
        wallet_check_explanation TEXT,
        responsible_user_id INTEGER,
        responsible_role TEXT,
        responsibility_notes TEXT,
        responsibility_assigned_at TEXT
    );

    CREATE TABLE gcash_transactions (
        transaction_id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER,
        shift_id INTEGER,
        transaction_type TEXT,
        reference_number TEXT,
        amount REAL,
        principal_amount REAL,
        fee_amount REAL,
        fee_method TEXT,
        service_cash_delta REAL DEFAULT 0.00,
        wallet_delta REAL DEFAULT 0.00,
        gross_fee REAL DEFAULT 0.00,
        cashier_id INTEGER,
        confirmed_wallet INTEGER DEFAULT 0,
        confirmed_cash INTEGER DEFAULT 0,
        notes TEXT,
        verification_status TEXT DEFAULT 'Pending',
        verified_by INTEGER,
        verification_note TEXT,
        reviewed_at TEXT,
        receipt_details_json TEXT,
        completed_at TEXT,
        cash_checked_by INTEGER,
        cash_finding TEXT,
        wallet_checked_by INTEGER,
        wallet_finding TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        responsible_user_id INTEGER,
        responsible_role TEXT,
        responsibility_notes TEXT,
        responsibility_assigned_at TEXT
    );

    CREATE TABLE gcash_funding_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER,
        movement_type TEXT,
        fund_type TEXT,
        amount REAL,
        direction TEXT,
        evidence_reference TEXT,
        reason TEXT,
        authorized_by INTEGER,
        recorded_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE gcash_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER,
        session_id INTEGER,
        service_cash_delta REAL DEFAULT 0.00,
        correction_mode TEXT DEFAULT 'money_movement',
        wallet_delta REAL DEFAULT 0.00,
        reason TEXT,
        authorized_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
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
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_code TEXT,
        period_name TEXT,
        start_date TEXT,
        end_date TEXT,
        status TEXT DEFAULT 'open',
        closed_at TEXT,
        closed_by INTEGER,
        closing_notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    INSERT INTO fiscal_periods (period_code, period_name, start_date, end_date, status)
    VALUES ('FP-2026-10', 'October 2026', '2026-10-01', '2026-10-31', 'open');

    CREATE TABLE fiscal_period_locks (
        period_id INTEGER,
        table_name TEXT,
        PRIMARY KEY (period_id, table_name)
    );
");

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
};

$refuse = static function (callable $fn, string $expectedMessagePattern = ''): void {
    try {
        $fn();
    } catch (DomainException $e) {
        if ($expectedMessagePattern !== '' && !preg_match($expectedMessagePattern, $e->getMessage())) {
            throw new RuntimeException("Expected message matching '{$expectedMessagePattern}', got: '{$e->getMessage()}'");
        }
        return;
    }
    throw new RuntimeException("Expected DomainException was not thrown");
};

$shiftService = new CashierShiftService($pdo);
$gcashService = new GCashTransactionService($pdo, ['min_length' => 13, 'max_length' => 13]);

// 1. Setup abandoned session for Dave (#3)
$shiftId3 = $shiftService->openShift(3, 'cashier', 10, 1000.00);
$sessStmt = $pdo->prepare("INSERT INTO gcash_service_sessions (shift_id, cashier_id, opening_service_cash, opening_wallet_balance, status) VALUES (?, 3, 2000.00, 5000.00, 'open')");
$sessStmt->execute([$shiftId3]);
$sessId1 = (int)$pdo->lastInsertId();

// Add unfinished exchange for Dave
$txId1 = $gcashService->save(3, '1111222233334', '500.00', 'Cash-In');
$pdo->exec("UPDATE gcash_transactions SET verification_status = 'Needs checking', confirmed_wallet = 0, confirmed_cash = 1 WHERE transaction_id = {$txId1}");

// 2. Test incomplete checks and negative balances refused
$refuse(fn() => $gcashService->emergencyHandover(1, 2, -100.0, 5000.0, 'Missing cash count'), '/balances must be 0 or greater/i');
$refuse(fn() => $gcashService->emergencyHandover(1, 2, 2000.0, -50.0, 'Missing wallet check'), '/balances must be 0 or greater/i');
$refuse(fn() => $gcashService->emergencyHandover(1, 2, 2000.0, 5000.0, '   '), '/reason/i');

// 3. Test ineligible replacement: User #4 has no open shift and is disabled
$refuse(fn() => $gcashService->emergencyHandover(1, 4, 2000.0, 5000.0, 'Emergency takeover by invalid user'), '/active open shift/i');

// State remains open and unchanged after refusal
$sessAfterRefusal = $pdo->query("SELECT * FROM gcash_service_sessions WHERE session_id = {$sessId1}")->fetch(PDO::FETCH_ASSOC);
$assert($sessAfterRefusal['status'] === 'open', 'Session status remains open after refused handover');

// 4. Test Stop Service (No replacement cashier, incomingCashierId = null)
$stopResult = $gcashService->emergencyHandover(1, null, 2000.00, 5000.00, 'Cashier abandoned counter, stopping service safely');
$assert($stopResult['service_stopped'] === true, 'Result reports service_stopped true');
$assert($stopResult['incoming_session_id'] === null, 'No new session opened');

$closedSess = $pdo->query("SELECT * FROM gcash_service_sessions WHERE session_id = {$sessId1}")->fetch(PDO::FETCH_ASSOC);
$assert($closedSess['status'] === 'closed', 'Original session closed');
$assert((int)$closedSess['closed_by'] === 1, 'Closed by admin #1');

// Unfinished exchange must be unaccepted
$tx1Row = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = {$txId1}")->fetch(PDO::FETCH_ASSOC);
$assert($tx1Row['responsible_user_id'] === null, 'Unfinished exchange has no responsible user after stop service');
$assert($tx1Row['verification_status'] === 'Needs checking', 'Exchange remains pending');

// Check activity log
$log = $pdo->query("SELECT * FROM activity_log WHERE action = 'GCash service stopped via emergency intervention'")->fetch(PDO::FETCH_ASSOC);
$assert($log !== false, 'Logged emergency stop service action');

// 5. Test Replacement Outcome:
// Open session on Dave's still-open shift
$pdo->prepare("INSERT INTO gcash_service_sessions (shift_id, cashier_id, opening_service_cash, opening_wallet_balance, status) VALUES (?, 3, 2000.00, 5000.00, 'open')")->execute([$shiftId3]);
$sessId2 = (int)$pdo->lastInsertId();

$txId2 = $gcashService->save(3, '4444555566667', '800.00', 'Cash-In');
$pdo->exec("UPDATE gcash_transactions SET verification_status = 'Needs checking', confirmed_wallet = 0, confirmed_cash = 1 WHERE transaction_id = {$txId2}");

// Open shift for Alice (#2) on register 11
$shiftIdAlice = $shiftService->openShift(2, 'cashier', 11, 1000.00);

// Emergency handover to Alice, accepting txId2
$handoverResult = $gcashService->emergencyHandover(1, 2, 2500.00, 4800.00, 'Dave fell ill, Alice takes over', [$txId2]);
$assert($handoverResult['service_stopped'] === false, 'Service not stopped');
$assert($handoverResult['incoming_session_id'] !== null, 'New session opened for Alice');

$newSess = $pdo->query("SELECT * FROM gcash_service_sessions WHERE session_id = {$handoverResult['incoming_session_id']}")->fetch(PDO::FETCH_ASSOC);
$assert($newSess['status'] === 'open', 'New session is open');
$assert((int)$newSess['cashier_id'] === 2, 'New session cashier is Alice');

// TxId2 accepted by Alice
$tx2Row = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = {$txId2}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$tx2Row['responsible_user_id'] === 2, 'Tx2 follow-up assigned to Alice');

echo "Emergency GCash handover contract: passed\n";
