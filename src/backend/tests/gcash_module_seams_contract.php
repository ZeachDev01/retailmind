<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';

use App\GCash\GCashActivityScopeReport;
use App\GCash\GCashCashierOverviewReport;
use App\GCash\GCashExchangeReview;
use App\GCash\GCashExchangeWorkflow;
use App\GCash\GCashLiveCustodySession;
use App\Services\GCashTransactionService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'super_admin');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        full_name TEXT,
        display_name TEXT,
        username TEXT,
        email TEXT,
        password_hash TEXT,
        role_id INTEGER,
        status TEXT,
        must_change_password INTEGER DEFAULT 0,
        is_recovery_account INTEGER DEFAULT 0
    );

    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active');
    INSERT INTO registers VALUES (10, 'Main Register', 'active'), (11, 'Second Register', 'active');

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        register_id INTEGER,
        status TEXT DEFAULT 'open',
        opening_cash REAL DEFAULT 1000.0,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP
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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE gcash_adjustments (
        adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER,
        session_id INTEGER,
        service_cash_delta REAL DEFAULT 0.00,
        wallet_delta REAL DEFAULT 0.00,
        fee_delta REAL DEFAULT 0.00,
        correction_mode TEXT DEFAULT 'money_movement',
        reason TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

$insertUser = $pdo->prepare(
    'INSERT INTO users (user_id, full_name, display_name, username, email, password_hash, role_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertUser->execute([1, 'Admin Bob', 'Admin Bob', 'admin_bob', 'admin@example.test', password_hash('fixture-password', PASSWORD_DEFAULT), 1, 'active']);
$insertUser->execute([3, 'Cashier Alice', 'Cashier Alice', 'cashier_alice', 'alice@example.test', password_hash('fixture-password', PASSWORD_DEFAULT), 2, 'active']);
$insertUser->execute([4, 'Cashier Dave', 'Cashier Dave', 'cashier_dave', 'dave@example.test', password_hash('fixture-password', PASSWORD_DEFAULT), 2, 'active']);

$pdo->exec("
    INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status) VALUES (10, 3, 11, 'open'), (20, 4, 10, 'open');
    INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, opening_service_cash, opening_wallet_balance, status)
        VALUES (30, 20, 4, 1000.00, 2000.00, 'open');

    INSERT INTO gcash_transactions (transaction_id, session_id, shift_id, transaction_type, reference_number, amount, principal_amount, fee_amount, fee_method, service_cash_delta, wallet_delta, gross_fee, cashier_id, verification_status, completed_at, created_at)
        VALUES
        (1, 30, 20, 'Cash-In', '1000000000001', 500, 500, 5, 'cash', 505, -505, 5, 4, 'Completed', '2026-11-09 09:00:00', '2026-11-09 09:00:00'),
        (2, 30, 20, 'Cash-Out', '1000000000002', 300, 300, 5, 'wallet', -300, 305, 5, 4, 'Completed', '2026-11-09 10:00:00', '2026-11-09 10:00:00');

    INSERT INTO gcash_transactions (transaction_id, session_id, shift_id, transaction_type, reference_number, amount, principal_amount, fee_amount, fee_method, service_cash_delta, wallet_delta, gross_fee, cashier_id, verification_status, created_at, responsible_user_id, responsible_role)
        VALUES
        (3, 30, 20, 'Cash-In', '1000000000003', 100, 100, 0, 'cash', 0, 0, 0, 4, 'Needs checking', '2026-11-09 11:00:00', 4, 'cashier'),
        (4, 30, 20, 'Cash-Out', '1000000000004', 50, 50, 0, 'cash', 0, 0, 0, 3, 'Needs checking', '2026-11-09 12:00:00', 3, 'cashier');

    INSERT INTO gcash_adjustments (transaction_id, session_id, service_cash_delta, wallet_delta, fee_delta, correction_mode)
        VALUES (3, 30, -100, -50, -5, 'money_movement');
");

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
};

$custody = new GCashLiveCustodySession($pdo);
$activity = new GCashActivityScopeReport($pdo);
$review = new GCashExchangeReview($pdo, [], $custody);
$workflow = new GCashExchangeWorkflow($pdo, [], $custody, $review);
$cashierOverview = new GCashCashierOverviewReport($pdo, $custody, $review);

// ADR-0025: Activity Scope filters must never move Live Custody state.
$custodyBefore = $custody->custodyOverviewState();
$oldActivity = $activity->filteredOverview([
    'actor_role' => 'admin',
    'start_date' => '2020-01-01',
    'end_date' => '2020-01-02',
]);
$custodyAfter = $custody->custodyOverviewState();
$assert($oldActivity['completed_exchange_count'] === 0, 'Old activity range reports no completed exchanges');
$assert($custodyBefore === $custodyAfter, 'Activity Scope filters must not change Live Custody state');

// Review delegates session and responsible-role lookups to the Custody seam.
$assert($review->getSession(30) === $custody->getSession(30), 'Review session lookup must match Custody');
$assert($review->resolveEligibleRole(4) === 'cashier', 'Review eligible-role lookup delegates to Custody');

// Workflow delegates approval to the Review seam.
$assert($workflow->authorizeAdmin('admin_bob', 'fixture-password') === 1, 'Workflow approval delegates to Review');

// Cashier overview scopes unresolved exchanges to the responsible Cashier.
$overview = $cashierOverview->cashierOverview(4);
$assert($overview['is_controlling_cashier'] === true, 'Cashier controls the open session');
$assert($overview['cash_in_principal'] === 500.0, 'Completed cash-in principal');
$assert($overview['cash_out_principal'] === 300.0, 'Completed cash-out principal');
$assert($overview['gross_fees_cash'] === 5.0, 'Cash fee total');
$assert($overview['gross_fees_wallet'] === 5.0, 'Wallet fee total');
$assert($overview['needs_checking_count'] === 1, 'Only the responsible Cashier sees the unresolved exchange');
$assert((int)$overview['needs_checking'][0]['transaction_id'] === 3, 'Responsible exchange is Dave transaction 3');
$assert((float)$overview['needs_checking'][0]['remaining_cash'] === 100.0, 'Remaining cash from adjustments');
$assert((float)$overview['needs_checking'][0]['remaining_wallet'] === 50.0, 'Remaining wallet from adjustments');
$assert((float)$overview['needs_checking'][0]['fees_returned'] === 5.0, 'Returned fees from adjustments');

$assert(count($review->unresolvedExchanges()) === 2, 'All unresolved exchanges at the Review seam');
$assert(count($review->unresolvedExchanges(4)) === 1, 'Review seam filters unresolved exchanges by responsible Cashier');

// HTTP filter construction shares the AdminOverviewReport seam.
$facade = new GCashTransactionService($pdo);
$queryOverview = $facade->adminOverviewFromQuery(['period' => 'today', 'cashier_id' => 4], 'admin', 1);
$assert((int)$queryOverview['filters']['cashier_id'] === 4, 'HTTP filter seam scopes activity to the requested Cashier');
$assert($queryOverview['current_expected_service_cash'] === $custody->custodyOverviewState()['current_expected_service_cash'], 'HTTP overview keeps Live Custody values');

echo "GCash module seams contract: passed\n";
