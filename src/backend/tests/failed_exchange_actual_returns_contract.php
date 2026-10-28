<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Services\GCashTransactionService;
use App\Services\CashierShiftService;
use App\Audit\AuditRecordCategory;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'super_admin');

    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, role_id INTEGER, status TEXT, password_hash TEXT, must_change_password INTEGER DEFAULT 0, is_recovery_account INTEGER DEFAULT 0);
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
        closed_at TEXT,
        closed_by INTEGER,
        responsible_user_id INTEGER,
        responsible_role TEXT
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
        service_cash_delta REAL DEFAULT 0.00,
        wallet_delta REAL DEFAULT 0.00,
        fee_delta REAL DEFAULT 0.00,
        reason TEXT,
        evidence TEXT,
        authorized_by INTEGER,
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
    } catch (DomainException $e) {
        if ($expectedMessagePattern !== '' && !preg_match($expectedMessagePattern, $e->getMessage())) {
            throw new RuntimeException("Expected message matching '{$expectedMessagePattern}', got: '{$e->getMessage()}'");
        }
        return;
    }
    throw new RuntimeException("Expected DomainException was not thrown");
};

$service = new GCashTransactionService($pdo, ['min_length' => 13, 'max_length' => 13]);

// 1. Setup a Cash-In exchange for Alice that collected cash (PHP 500 + 10 fee = PHP 510 service cash)
// but wallet send failed or needs checking.
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, status) VALUES (10, 2, 'open')");
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status) VALUES (20, 10, 2, 'open')");

$txId = $service->recordCashIn(2, [
    'principal' => 500,
    'outcome' => 'Needs checking',
    'confirmed_cash' => true,
    'confirmed_wallet' => false,
    'notes' => 'Cash received, wallet timed out',
]);
$txRow = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = {$txId}")->fetch(PDO::FETCH_ASSOC);
$assert($txRow['verification_status'] === 'Needs checking', 'Transaction is in Needs checking');
$assert((float)$txRow['service_cash_delta'] === 510.00, 'Collected 510.00 cash');

// 2. Administrator cannot execute cancellation: belongs to responsible Cashier
$refuse(fn() => $service->resolveNeedsChecking(1, $txId, 'cancel', [
    'reason' => 'Admin cancellation attempt',
]), '/Cancellation authority belongs to the responsible Cashier/i');

// 3. Simple Cancel without return evidence is refused: Clicking Cancel is not evidence of movement!
$refuse(fn() => $service->recordFailedExchangeReturn(2, $txId, [
    'returned_cash' => 0.0,
    'returned_wallet' => 0.0,
    'reason' => 'Just clicked cancel',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]), '/return evidence|amount/i');

// 4. Invalid supervisor credentials refused
$refuse(fn() => $service->recordFailedExchangeReturn(2, $txId, [
    'returned_cash' => 510.0,
    'returned_wallet' => 0.0,
    'evidence' => 'Physical cash returned to customer',
    'reason' => 'Customer refunded',
    'approver_username' => 'admin_bob',
    'approver_password' => 'WrongPass',
]), '/credentials|password|authorization/i');

// 5. If original session is closed and Cashier has NO current open session, posting is refused with clear explanation
$pdo->exec("UPDATE gcash_service_sessions SET status = 'closed' WHERE session_id = 20");
$refuse(fn() => $service->recordFailedExchangeReturn(2, $txId, [
    'returned_cash' => 510.0,
    'returned_wallet' => 0.0,
    'evidence' => 'Physical cash returned to customer',
    'reason' => 'Customer refunded after shift',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]), '/open GCash session/i');

// Reopen a current session for Alice
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status) VALUES (21, 10, 2, 'open')");

// 6. Test partial return: returns PHP 250 out of PHP 510
$partial = $service->recordFailedExchangeReturn(2, $txId, [
    'returned_cash' => 250.0,
    'returned_wallet' => 0.0,
    'evidence' => 'Returned 250 cash partial',
    'reason' => 'Customer accepted partial return, remaining pending',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]);
$assert($partial['completed'] === false, 'Partial return does not complete cancellation');
$assert((float)$partial['remaining_obligation'] === 260.00, 'Remaining obligation is 260.00');

$txPartial = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = {$txId}")->fetch(PDO::FETCH_ASSOC);
$assert($txPartial['verification_status'] === 'Needs checking', 'Transaction remains in Needs checking after partial return');

// 7. Full return of remaining PHP 260 completes cancellation
$full = $service->recordFailedExchangeReturn(2, $txId, [
    'returned_cash' => 260.0,
    'returned_wallet' => 0.0,
    'evidence' => 'Returned remaining 260 cash',
    'reason' => 'Complete customer cash return',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
]);
$assert($full['completed'] === true, 'Full return completes cancellation');
$assert((float)$full['remaining_obligation'] === 0.00, 'Remaining obligation is 0');

$txFull = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = {$txId}")->fetch(PDO::FETCH_ASSOC);
$assert($txFull['verification_status'] === 'Rejected', 'Transaction verification_status is Rejected/Cancelled');

// Original exchange attribution preserved
$assert((int)$txFull['cashier_id'] === 2, 'Original cashier preserved');
$assert((float)$txFull['service_cash_delta'] === 510.00, 'Original service_cash_delta preserved');

// Adjustments recorded with current session (21)
$adjs = $pdo->query("SELECT * FROM gcash_adjustments WHERE transaction_id = {$txId} ORDER BY adjustment_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$assert(count($adjs) === 2, 'Two return adjustments recorded');
$assert((int)$adjs[0]['session_id'] === 21, 'First return posted to current session 21');
$assert((float)$adjs[0]['service_cash_delta'] === -250.00, 'First return deducted 250');
$assert((int)$adjs[1]['session_id'] === 21, 'Second return posted to current session 21');
$assert((float)$adjs[1]['service_cash_delta'] === -260.00, 'Second return deducted 260');

echo "Failed exchange actual returns contract: passed\n";
