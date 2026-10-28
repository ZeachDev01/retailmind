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
        cash_variance REAL DEFAULT 0.0,
        wallet_variance REAL DEFAULT 0.0,
        expected_service_cash REAL,
        expected_wallet_balance REAL,
        unresolved_carryover_count INTEGER DEFAULT 0,
        closing_notes TEXT,
        closed_at TEXT,
        waiver_notes TEXT,
        waived_by INTEGER,
        waived_at TEXT,
        responsible_user_id INTEGER,
        responsible_role TEXT,
        reviewed_by INTEGER,
        reviewed_at TEXT,
        review_notes TEXT,
        review_shortage REAL DEFAULT 0.0,
        recovered_shortage REAL DEFAULT 0.0,
        review_history TEXT,
        cash_check_explanation TEXT,
        wallet_check_explanation TEXT
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
        linked_session_id INTEGER,
        linked_transaction_id INTEGER,
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

// 1. Setup closed historical session 1 with 300 shortage reviewed by Admin Bob
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, cashier_id, status, opening_service_cash, opening_wallet_balance, closing_service_cash, closing_wallet_balance, cash_variance, wallet_variance, responsible_user_id, responsible_role, reviewed_by, reviewed_at, review_notes, review_shortage, recovered_shortage, cash_check_explanation, wallet_check_explanation)
            VALUES (1, 2, 'closed', 2000.0, 5000.0, 1700.0, 5000.0, -300.0, 0.0, 2, 'cashier', 1, '2026-10-25 18:00:00', 'Cashier short 300 at closing', 300.0, 0.0, 'Verified drawer physical count', 'Verified GCash app balance')");

// Alice opens current session 2 today
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, status) VALUES (2, 2, 'open')");
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status, opening_service_cash, opening_wallet_balance)
            VALUES (2, 2, 2, 'open', 2000.0, 5000.0)");

// 2. Refusal: Recovering shortage without valid approval
$refuse(fn() => $service->recordShortageRecovery(2, [
    'session_id' => 1,
    'amount' => 300.0,
    'fund_type' => 'cash',
    'reason' => 'Cashier paid back shortage',
    'approver_username' => 'admin_bob',
    'approver_password' => 'WrongPass',
    'receipt_confirmed' => true,
]), '/credentials|password|authorization/i');

// 3. Refusal: Recovering without Cashier receipt confirmation
$refuse(fn() => $service->recordShortageRecovery(2, [
    'session_id' => 1,
    'amount' => 300.0,
    'fund_type' => 'cash',
    'reason' => 'Cashier paid back shortage',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
    'receipt_confirmed' => false,
]), '/receipt|confirmed/i');

// 4. Successful recovery: 300 PHP paid by Alice into current session 2
$recId = $service->recordShortageRecovery(2, [
    'session_id' => 1,
    'amount' => 300.0,
    'fund_type' => 'cash',
    'reason' => 'Cashier settled verified 300 PHP shortage in cash',
    'evidence' => 'Receipt-SHORT-001',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
    'receipt_confirmed' => true,
]);
$assert($recId > 0, 'Shortage recovery recorded');

// Check historical session 1: original variance (-300) is preserved, recovered_shortage updated to 300
$sess1 = $pdo->query("SELECT * FROM gcash_service_sessions WHERE session_id = 1")->fetch(PDO::FETCH_ASSOC);
$assert((float)$sess1['cash_variance'] === -300.0, 'Original cash variance is NOT rewritten');
$assert((float)$sess1['review_shortage'] === 300.0, 'Original review shortage preserved');
$assert((float)$sess1['recovered_shortage'] === 300.0, 'Recovered shortage updated to 300.0');

// Check current session 2: cash expected is now 2000 + 300 = 2300
$exp2 = $service->calculateSessionExpected(2);
$assert((float)$exp2['expected_service_cash'] === 2300.00, 'Current session expected cash reflects recovered money');

// 5. Money History Trace: getMoneyHistory returns timeline with distinct items
$history = $service->getMoneyHistory(['session_id' => 2]);
$assert(is_array($history), 'Money history returns array');
$assert(count($history) >= 1, 'Contains recovery movement');
$foundRecovery = false;
foreach ($history as $item) {
    if ($item['movement_type'] === 'shortage_recovery') {
        $foundRecovery = true;
        $assert((float)$item['amount'] === 300.00, 'Recovery amount is 300');
        $assert($item['linked_session_id'] === 1, 'Links to historical session 1');
        $assert($item['is_actual_movement'] === true, 'Flagged as actual money movement');
    }
}
$assert($foundRecovery, 'Found shortage_recovery item in Money history');

// 6. Multiple links counted once: recording a movement linking both exchange and session does not double-count
$countBefore = (float)$service->calculateSessionExpected(2)['expected_service_cash'];
$singleId = $service->recordFundingMovement(2, 'cashier', [
    'movement_type' => 'replenishment',
    'fund_type' => 'cash',
    'direction' => 'in',
    'amount' => 100.0,
    'reason' => 'Multi-link test',
    'approver_username' => 'admin_bob',
    'approver_password' => 'AdminPass@123',
    'receipt_confirmed' => true,
    'linked_transaction_id' => 101,
    'linked_session_id' => 1,
]);
$countAfter = (float)$service->calculateSessionExpected(2)['expected_service_cash'];
$assert(round($countAfter - $countBefore, 2) === 100.00, 'Counted exactly once (+100) despite multiple links');

$refuse(fn() => $service->recordShortageRecovery(2, [
    'session_id' => 1, 'amount' => 50, 'fund_type' => 'cash', 'receipt_confirmed' => true,
    'evidence_reference' => 'Additional actual receipt', 'reason' => 'Cannot recover more than shortage',
    'approver_username' => 'admin_bob', 'approver_password' => 'AdminPass@123',
]), '/remaining|exceeds/i');

echo "GCash Money history and linked shortage recovery contract: passed\n";
