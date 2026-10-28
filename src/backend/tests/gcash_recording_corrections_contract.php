<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

use App\Services\GCashTransactionService;
use App\Audit\AuditRecordCategory;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'super_admin');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        full_name TEXT,
        username TEXT,
        role_id INTEGER,
        status TEXT,
        password_hash TEXT,
        must_change_password INTEGER DEFAULT 0,
        is_recovery_account INTEGER DEFAULT 0
    );
    INSERT INTO users (user_id, full_name, username, role_id, status, password_hash) VALUES
        (1, 'Admin Bob', 'admin_bob', 1, 'active', '" . password_hash('AdminPass@123', PASSWORD_DEFAULT) . "'),
        (2, 'Cashier Alice', 'cashier_alice', 2, 'active', '" . password_hash('CashierPass@123', PASSWORD_DEFAULT) . "'),
        (3, 'Cashier Dave', 'cashier_dave', 2, 'active', '" . password_hash('DavePass@123', PASSWORD_DEFAULT) . "');

    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));
    INSERT INTO user_roles VALUES (1, 1), (2, 2), (3, 2);

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active');
    INSERT INTO registers VALUES (1, 'Register 1', 'active');

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        register_id INTEGER,
        status TEXT DEFAULT 'open',
        opening_cash REAL DEFAULT 1000.0,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT
    );

    CREATE TABLE gcash_service_sessions (
        session_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER,
        cashier_id INTEGER,
        opening_service_cash REAL DEFAULT 2000.0,
        opening_wallet_balance REAL DEFAULT 5000.0,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        status TEXT DEFAULT 'open',
        closing_service_cash REAL,
        closing_wallet_balance REAL,
        cash_variance REAL,
        wallet_variance REAL,
        expected_service_cash REAL,
        expected_wallet_balance REAL,
        unresolved_carryover_count INTEGER DEFAULT 0,
        closing_notes TEXT,
        closed_at TEXT
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

    CREATE TABLE gcash_transactions (
        transaction_id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER,
        shift_id INTEGER,
        transaction_type TEXT,
        amount REAL,
        principal_amount REAL,
        fee_amount REAL,
        fee_method TEXT,
        service_cash_delta REAL DEFAULT 0.00,
        wallet_delta REAL DEFAULT 0.00,
        reference_number TEXT,
        gross_fee REAL DEFAULT 0.00,
        cashier_id INTEGER,
        confirmed_wallet INTEGER DEFAULT 0,
        confirmed_cash INTEGER DEFAULT 0,
        notes TEXT,
        verification_status TEXT DEFAULT 'Completed',
        verified_by INTEGER,
        completed_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        responsible_user_id INTEGER,
        responsible_role TEXT,
        responsibility_notes TEXT,
        responsibility_assigned_at TEXT
    );

    CREATE TABLE gcash_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER,
        session_id INTEGER,
        correction_mode TEXT DEFAULT 'money_movement',
        service_cash_delta REAL DEFAULT 0.00,
        wallet_delta REAL DEFAULT 0.00,
        reason TEXT,
        evidence TEXT,
        authorized_by INTEGER,
        effective_date TEXT DEFAULT CURRENT_TIMESTAMP,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT,
        module TEXT,
        record_id INTEGER,
        previous_value TEXT,
        new_value TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT,
        start_date TEXT,
        end_date TEXT,
        status TEXT DEFAULT 'open'
    );
    INSERT INTO fiscal_periods (period_name, start_date, end_date, status)
    VALUES ('Current Period', '2026-01-01', '2026-12-31', 'open');

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
    } catch (Throwable $e) {
        if ($expectedMessagePattern !== '' && !preg_match($expectedMessagePattern, $e->getMessage())) {
            throw new RuntimeException("Expected message matching '{$expectedMessagePattern}', got: '{$e->getMessage()}'");
        }
        return;
    }
    throw new RuntimeException("Expected exception was not thrown");
};

$service = new GCashTransactionService($pdo, ['min_length' => 13, 'max_length' => 13]);

// Setup: Alice has completed shift 1 and session 1 (closed historically)
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, status, opened_at, closed_at) VALUES (1, 2, 'closed', '2026-10-20 08:00:00', '2026-10-20 17:00:00')");
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status, opening_service_cash, opening_wallet_balance, closing_service_cash, closing_wallet_balance, expected_service_cash, expected_wallet_balance, opened_at, closed_at)
            VALUES (1, 1, 2, 'closed', 2000.0, 5000.0, 2510.0, 4500.0, 2510.0, 4500.0, '2026-10-20 08:00:00', '2026-10-20 17:00:00')");

// Alice recorded a Cash-In transaction in session 1: 500 principal + 10 fee = 510 cash, -500 wallet
$pdo->exec("INSERT INTO gcash_transactions (transaction_id, session_id, shift_id, transaction_type, amount, principal_amount, fee_amount, fee_method, service_cash_delta, wallet_delta, reference_number, gross_fee, cashier_id, confirmed_wallet, confirmed_cash, verification_status, created_at, completed_at)
            VALUES (101, 1, 1, 'Cash-In', 500.0, 500.0, 10.0, 'cash', 510.0, -500.0, '1234567890123', 10.0, 2, 1, 1, 'Completed', '2026-10-20 10:00:00', '2026-10-20 10:00:00')");

// Alice now has a current open shift 2 and open session 2 (current day)
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, status, opened_at) VALUES (2, 2, 'open', '2026-10-28 08:00:00')");
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status, opening_service_cash, opening_wallet_balance, opened_at)
            VALUES (2, 2, 2, 'open', 2000.0, 5000.0, '2026-10-28 08:00:00')");

// --- 1. Preview linked correction ---
// Preview mode 'recording_mistake' (no money moves now)
$prevMistake = $service->previewLinkedCorrection(101, 'recording_mistake', -10.0, 0.0);
$assert($prevMistake['affects_present_money'] === false, 'Recording mistake does not affect present session cash/wallet');
$assert($prevMistake['mode'] === 'recording_mistake', 'Mode is recording_mistake');

// Preview mode 'money_movement' (actual money moves now in open session)
$prevMovement = $service->previewLinkedCorrection(101, 'money_movement', -10.0, 0.0);
$assert($prevMovement['affects_present_money'] === true, 'Money movement affects present session cash');
$assert($prevMovement['target_session_id'] === 2, 'Money movement targets current open session 2');

// --- 2. Record linked correction: Mode A - Fix a recording mistake ---
// Must require reason (>= 5 chars) and admin role
$refuse(fn() => $service->recordLinkedCorrection(2, 'cashier', 101, -10.0, 0.0, 'Mistake noted', null, 'recording_mistake'), '/Only an Administrator/i');
$refuse(fn() => $service->recordLinkedCorrection(1, 'admin', 101, -10.0, 0.0, 'bad', null, 'recording_mistake'), '/between 5 and 255 characters/i');

// Valid recording mistake correction
$adjMistakeId = $service->recordLinkedCorrection(1, 'admin', 101, -10.0, 0.0, 'Incorrect fee logged originally, no money moves', null, 'recording_mistake');
$adjRow = $pdo->query("SELECT * FROM gcash_adjustments WHERE adjustment_id = {$adjMistakeId}")->fetch(PDO::FETCH_ASSOC);
$assert($adjRow['correction_mode'] === 'recording_mistake', 'Correction mode is recording_mistake');

// Verify: Historical closing counts and original transaction entry remain intact
$origTx = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = 101")->fetch(PDO::FETCH_ASSOC);
$assert((float)$origTx['service_cash_delta'] === 510.0, 'Original transaction service_cash_delta unchanged');
$assert((float)$origTx['amount'] === 500.0, 'Original transaction amount unchanged');

// Verify: Current open session 2 expected balances are UNCHANGED by recording mistake
$sess2Expected = $service->calculateSessionExpected(2);
$assert((float)$sess2Expected['expected_service_cash'] === 2000.00, 'Session 2 expected cash unaffected by recording mistake');
$assert((float)$sess2Expected['expected_wallet_balance'] === 5000.00, 'Session 2 expected wallet unaffected by recording mistake');

// Historical session 1 expected balances also remain historically truthful
$sess1Expected = $service->calculateSessionExpected(1);
$assert((float)$sess1Expected['expected_service_cash'] === 2510.00, 'Session 1 expected cash unaffected by recording mistake');

// --- 3. Record linked correction: Mode B - Actual money movement ---
// Valid money movement: customer returned 10 pesos fee overcharge into drawer today
$adjMoveId = $service->recordLinkedCorrection(1, 'admin', 101, -10.0, 0.0, 'Physical 10 pesos returned to customer from drawer today', null, 'money_movement');
$adjMoveRow = $pdo->query("SELECT * FROM gcash_adjustments WHERE adjustment_id = {$adjMoveId}")->fetch(PDO::FETCH_ASSOC);
$assert($adjMoveRow['correction_mode'] === 'money_movement', 'Correction mode is money_movement');
$assert((int)$adjMoveRow['session_id'] === 2, 'Money movement is linked to Alice current open session 2');

// Verify: Current open session 2 expected balance is updated (2000 - 10 = 1990)
$sess2ExpectedAfter = $service->calculateSessionExpected(2);
$assert((float)$sess2ExpectedAfter['expected_service_cash'] === 1990.00, 'Session 2 expected cash reflects -10 money movement');

// --- 4. Refusals: Actual money movement requires responsible Cashier to have eligible current open session ---
// Close session 2 for Alice
$pdo->exec("UPDATE gcash_service_sessions SET status = 'closed' WHERE session_id = 2");

// Attempting money movement when responsible Cashier has NO open session is refused and directs to open session
$refuse(
    fn() => $service->recordLinkedCorrection(1, 'admin', 101, -10.0, 0.0, 'Attempt movement while session closed', null, 'money_movement'),
    '/open GCash session/i'
);

// But recording mistake correction CAN still be logged because no money moves
$adjMistake2 = $service->recordLinkedCorrection(1, 'admin', 101, -5.0, 0.0, 'Another recording mistake logged post-shift', null, 'recording_mistake');
$assert($adjMistake2 > 0, 'Recording mistake allowed even when session closed');

// --- 5. Period Lock Refusal ---
$pdo->exec("UPDATE fiscal_periods SET status = 'closed' WHERE period_id = 1");
$refuse(
    fn() => $service->recordLinkedCorrection(1, 'admin', 101, -5.0, 0.0, 'Cannot correct in closed period', null, 'recording_mistake'),
    '/fiscal period/i'
);

echo "GCash recording corrections contract: passed\n";
