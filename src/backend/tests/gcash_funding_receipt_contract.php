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
        (3, 'Dual Role Dan', 'dan_admin_cashier', 2, 'active', '" . password_hash('DanPass@123', PASSWORD_DEFAULT) . "'),
        (4, 'Cashier Dave', 'cashier_dave', 2, 'active', '" . password_hash('DavePass@123', PASSWORD_DEFAULT) . "');

    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));
    INSERT INTO user_roles VALUES (1, 1), (2, 2), (3, 1), (3, 2), (4, 2);

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active');
    INSERT INTO registers VALUES (1, 'Register 1', 'active'), (2, 'Register 2', 'active');

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        register_id INTEGER,
        status TEXT DEFAULT 'open',
        opening_cash REAL DEFAULT 1000.0,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT
    );

    CREATE TABLE cash_drawer_movements (
        drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER,
        cashier_id INTEGER,
        movement_type TEXT,
        amount REAL,
        reason TEXT,
        note TEXT,
        recorded_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
        received_by INTEGER,
        receipt_confirmed INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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

// 1. Setup Alice shift on Register 1 and open GCash session
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status) VALUES (1, 2, 1, 'open')");
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status) VALUES (1, 1, 2, 'open')");

// Preview drawer funding
$prev = $service->previewProductDrawerFunding(2, 500.0);
$assert($prev['shift_id'] === 1, 'Preview matches Alice open shift');
$assert($prev['register_id'] === 1, 'Preview identifies Register 1');
$assert($prev['register_name'] === 'Register 1', 'Preview shows Register 1 name');
$assert((float)$prev['amount'] === 500.0, 'Preview matches amount');

// 2. Refusal: unreceived funds (receipt_confirmed false / not confirmed by cashier)
$refuse(fn() => $service->recordFundingMovement(2, 'cashier', [
    'movement_type' => 'replenishment',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 500.0,
    'reason' => 'Morning replenishment',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
    'receipt_confirmed' => false,
]), '/receipt|confirmed/i');

// 3. Refusal: approval alone cannot post money (wrong or missing approver)
$refuse(fn() => $service->recordFundingMovement(2, 'cashier', [
    'movement_type' => 'replenishment',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 500.0,
    'reason' => 'Morning replenishment',
    'approver_username' => 'admin_bob',
    'approver_password' => 'WrongPass',
    'receipt_confirmed' => true,
]), '/credentials|password|authorization/i');

// 4. Refusal: Product drawer funding from another cashier's drawer or closed drawer
// If Alice (user 2) has no open product shift/drawer (shift closed), transfer from drawer is refused.
$pdo->exec("UPDATE cashier_shifts SET status = 'closed' WHERE shift_id = 1");
$refuse(fn() => $service->recordFundingMovement(2, 'cashier', [
    'movement_type' => 'product_drawer_transfer',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 300.0,
    'reason' => 'Transfer from closed shift',
    'evidence' => 'Slip-001',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
    'receipt_confirmed' => true,
]), '/open drawer|shift/i');

// Reopen Alice shift 1
$pdo->exec("UPDATE cashier_shifts SET status = 'open' WHERE shift_id = 1");

// 5. Successful confirmed funding with supervisor approval:
$fundId = $service->recordFundingMovement(2, 'cashier', [
    'movement_type' => 'product_drawer_transfer',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 500.0,
    'reason' => 'Transfer from own drawer to GCash cash',
    'evidence' => 'Slip-001',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
    'receipt_confirmed' => true,
]);

$fRow = $pdo->query("SELECT * FROM gcash_funding_movements WHERE movement_id = {$fundId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$fRow['authorized_by'] === 1, 'Authorized by Admin Bob');
$assert((int)$fRow['recorded_by'] === 2, 'Recorded by Alice');
$assert((int)$fRow['receipt_confirmed'] === 1, 'Receipt confirmed');
$assert((int)$fRow['received_by'] === 2, 'Received by responsible Cashier Alice');

// Verify drawer movement linked to Alice's shift
$dMove = $pdo->query("SELECT * FROM cash_drawer_movements WHERE shift_id = 1 ORDER BY drawer_movement_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert((float)$dMove['amount'] === 500.0, '500 taken from product drawer');

// 6. Dual-role self-approval & receipt confirmation (Dan: user 3 is cashier and admin)
$pdo->exec("UPDATE gcash_service_sessions SET status = 'closed' WHERE session_id = 1");
$pdo->exec("UPDATE cashier_shifts SET status = 'closed' WHERE shift_id = 1");

$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status) VALUES (2, 3, 2, 'open')");
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status) VALUES (2, 2, 3, 'open')");

$danFundId = $service->recordFundingMovement(3, 'cashier', [
    'movement_type' => 'replenishment',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 1000.0,
    'reason' => 'Dual-role Dan replenishes drawer',
    'approver_username' => 'dan_admin_cashier',
    'approver_password' => 'DanPass@123',
    'receipt_confirmed' => true,
]);

$danRow = $pdo->query("SELECT * FROM gcash_funding_movements WHERE movement_id = {$danFundId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$danRow['authorized_by'] === 3, 'Authorized by Dan');
$assert((int)$danRow['recorded_by'] === 3, 'Recorded by Dan');
$assert((int)$danRow['received_by'] === 3, 'Received by Dan');
$assert((int)$danRow['receipt_confirmed'] === 1, 'Receipt confirmed by Dan');

echo "Confirmed receipt for GCash funding contract: passed\n";
