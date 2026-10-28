<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

use App\Services\GCashTransactionService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier');

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
        (2, 'Cashier Alice', 'cashier_alice', 2, 'active', '" . password_hash('CashierPass@123', PASSWORD_DEFAULT) . "');

    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));
    INSERT INTO user_roles VALUES (1, 1), (2, 2);

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
        cash_variance REAL DEFAULT 0.0,
        wallet_variance REAL DEFAULT 0.0,
        expected_service_cash REAL,
        expected_wallet_balance REAL,
        unresolved_carryover_count INTEGER DEFAULT 0,
        closing_notes TEXT,
        closed_at TEXT,
        closed_by INTEGER,
        responsible_user_id INTEGER,
        responsible_role TEXT,
        waived_by INTEGER,
        waived_at TEXT,
        waiver_notes TEXT,
        reviewed_by INTEGER,
        reviewed_at TEXT,
        review_notes TEXT,
        review_shortage REAL DEFAULT 0.0,
        review_history TEXT,
        cash_check_explanation TEXT,
        wallet_check_explanation TEXT
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
        reviewed_at TEXT,
        completed_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        responsible_user_id INTEGER,
        responsible_role TEXT,
        responsibility_notes TEXT,
        responsibility_assigned_at TEXT,
        receipt_details_json TEXT
    );

    CREATE TABLE gcash_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER,
        session_id INTEGER,
        correction_mode TEXT DEFAULT 'money_movement',
        service_cash_delta REAL DEFAULT 0.00,
        wallet_delta REAL DEFAULT 0.00,
        fee_delta REAL DEFAULT 0.00,
        reason TEXT,
        evidence TEXT,
        authorized_by INTEGER,
        effective_date TEXT DEFAULT CURRENT_TIMESTAMP,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT,
        module TEXT,
        record_id INTEGER,
        previous_value TEXT,
        new_value TEXT,
        category TEXT,
        metadata TEXT,
        ip_address TEXT,
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

$service = new GCashTransactionService($pdo, ['min_length' => 13, 'max_length' => 13]);

// Day 1 (2026-10-25): Alice completes transaction 1: Fee 20.00 charged on Oct 25
$pdo->exec("INSERT INTO gcash_transactions (
    transaction_id, session_id, shift_id, transaction_type, amount, principal_amount, fee_amount, fee_method,
    service_cash_delta, wallet_delta, reference_number, gross_fee, cashier_id, confirmed_wallet, confirmed_cash,
    verification_status, created_at, completed_at
) VALUES (
    1, 1, 1, 'Cash-In', 1000.0, 1000.0, 20.0, 'cash', 1020.0, -1000.0, '1000000000001', 20.0, 2, 1, 1,
    'Completed', '2026-10-25 10:00:00', '2026-10-25 10:00:00'
)");

// Day 2 (2026-10-26): Failed exchange transaction 2 (Needs checking) where customer returned 10.00 fee
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, status, opened_at) VALUES (2, 2, 'open', '2026-10-26 08:00:00')");
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status, opened_at) VALUES (2, 2, 2, 'open', '2026-10-26 08:00:00')");

$pdo->exec("INSERT INTO gcash_transactions (
    transaction_id, session_id, shift_id, transaction_type, amount, principal_amount, fee_amount, fee_method,
    service_cash_delta, wallet_delta, reference_number, gross_fee, cashier_id, confirmed_wallet, confirmed_cash,
    verification_status, created_at, completed_at
) VALUES (
    2, 2, 2, 'Cash-In', 500.0, 500.0, 10.0, 'cash', 510.0, 0.0, '1000000000002', 10.0, 2, 0, 1,
    'Needs checking', '2026-10-26 09:00:00', NULL
)");

// Record return on Tx 2 on 2026-10-26: returning 510 cash (500 principal + 10 fee)
$service->recordFailedExchangeReturn(2, 2, [
    'returned_cash' => 510.0,
    'returned_wallet' => 0.0,
    'fee_returned' => 10.0,
    'reason' => 'Customer refunded principal and fee',
    'evidence' => 'Return slip 001',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]);
$pdo->exec("UPDATE gcash_adjustments SET created_at = '2026-10-26 11:00:00' WHERE transaction_id = 2");

// 1. Overview on Day 1 (2026-10-25):
// Fees charged: 20.00, Fees returned: 0.00, Fees after returns: 20.00
$ov1 = $service->adminOverview([
    'period' => 'custom',
    'start_date' => '2026-10-25',
    'end_date' => '2026-10-25',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]);
$assert((float)$ov1['fees_charged'] === 20.00, 'Oct 25 fees charged is 20.00');
$assert((float)$ov1['fees_returned'] === 0.00, 'Oct 25 fees returned is 0.00');
$assert((float)$ov1['fees_after_returns'] === 20.00, 'Oct 25 fees after returns is 20.00');

// 2. Overview on Day 2 (2026-10-26):
// Fees charged: 0.00 (Tx 2 was not completed), Fees returned: 10.00, Fees after returns: -10.00 (allowed negative!)
$ov2 = $service->adminOverview([
    'period' => 'custom',
    'start_date' => '2026-10-26',
    'end_date' => '2026-10-26',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]);
$assert((float)$ov2['fees_charged'] === 0.00, 'Oct 26 fees charged is 0.00');
$assert((float)$ov2['fees_returned'] === 10.00, 'Oct 26 fees returned is 10.00');
$assert((float)$ov2['fees_after_returns'] === -10.00, 'Oct 26 fees after returns is -10.00 (allows negative)');

// 3. Combined Month-to-date (covering Oct 25 and 26):
// Fees charged: 20.00, Fees returned: 10.00, Fees after returns: 10.00
$ovCombined = $service->adminOverview([
    'period' => 'custom',
    'start_date' => '2026-10-25',
    'end_date' => '2026-10-26',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]);
$assert((float)$ovCombined['fees_charged'] === 20.00, 'Combined fees charged is 20.00');
$assert((float)$ovCombined['fees_returned'] === 10.00, 'Combined fees returned is 10.00');
$assert((float)$ovCombined['fees_after_returns'] === 10.00, 'Combined fees after returns is 10.00');

echo "Explicit GCash fee returns contract: passed\n";
