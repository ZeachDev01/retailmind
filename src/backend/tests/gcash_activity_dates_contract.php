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
        reason TEXT,
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

// Tx 1: Initiated 2026-10-25 23:30:00 (Needs checking), Completed on 2026-10-26 09:00:00
$pdo->exec("INSERT INTO gcash_transactions (
    transaction_id, session_id, shift_id, transaction_type, amount, principal_amount, fee_amount, fee_method,
    service_cash_delta, wallet_delta, reference_number, gross_fee, cashier_id, confirmed_wallet, confirmed_cash,
    verification_status, created_at, completed_at
) VALUES (
    1, 1, 1, 'Cash-In', 500.0, 500.0, 10.0, 'cash', 510.0, -500.0, '1000000000001', 10.0, 2, 1, 1,
    'Completed', '2026-10-25 23:30:00', '2026-10-26 09:00:00'
)");

// Tx 2: Initiated 2026-10-25 23:45:00, remaining uncompleted (Needs checking)
$pdo->exec("INSERT INTO gcash_transactions (
    transaction_id, session_id, shift_id, transaction_type, amount, principal_amount, fee_amount, fee_method,
    service_cash_delta, wallet_delta, reference_number, gross_fee, cashier_id, confirmed_wallet, confirmed_cash,
    verification_status, created_at, completed_at
) VALUES (
    2, 1, 1, 'Cash-In', 300.0, 300.0, 10.0, 'cash', 310.0, 0.0, '1000000000002', 0.0, 2, 0, 1,
    'Needs checking', '2026-10-25 23:45:00', NULL
)");

// 1. Filtering on 2026-10-26 (effective date of completion):
// Tx 1 must appear because completed_at is 2026-10-26.
// Tx 2 must NOT appear on 2026-10-26 because created_at is 2026-10-25 and not completed.
$overviewOct26 = $service->adminOverview([
    'period' => 'custom',
    'start_date' => '2026-10-26',
    'end_date' => '2026-10-26',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]);
$txIds26 = array_column($overviewOct26['transactions'], 'transaction_id');
$assert(in_array(1, $txIds26, true), 'Tx 1 appears in 2026-10-26 report based on completion date');
$assert(!in_array(2, $txIds26, true), 'Tx 2 does not appear in 2026-10-26 report');
$assert((int)$overviewOct26['completed_exchange_count'] === 1, 'Completed count on Oct 26 is 1');
$assert((float)$overviewOct26['filtered_activity']['cash_in_principal'] === 500.00, 'Cash-in principal on Oct 26 is 500');

// 2. Filtering on 2026-10-25:
// Tx 2 appears because its created_at is 2026-10-25.
// Tx 1 does NOT appear as completed activity on 2026-10-25!
$overviewOct25 = $service->adminOverview([
    'period' => 'custom',
    'start_date' => '2026-10-25',
    'end_date' => '2026-10-25',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]);
$txIds25 = array_column($overviewOct25['transactions'], 'transaction_id');
$assert(in_array(2, $txIds25, true), 'Tx 2 appears in 2026-10-25 report based on start date');
$assert(!in_array(1, $txIds25, true), 'Tx 1 does NOT appear in 2026-10-25 report');
$assert((int)$overviewOct25['completed_exchange_count'] === 0, 'Completed count on Oct 25 is 0');

// 3. Traversal (adminTransactions generator) parity:
$traversed26 = iterator_to_array($service->adminTransactions([
    'actor_role' => 'admin',
    'period' => 'custom',
    'start_date' => '2026-10-26',
    'end_date' => '2026-10-26',
]), false);
$assert(count($traversed26) === 1 && (int)$traversed26[0]['transaction_id'] === 1, 'Traversal matches overview filtering on completion date');

echo "Truthful GCash activity dates contract: passed\n";
