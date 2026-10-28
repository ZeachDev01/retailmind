<?php
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Services\GCashTransactionService;
use App\Audit\AuditRecordCategory;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'super_admin');

    CREATE TABLE users (user_id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, role_id INTEGER, status TEXT, password_hash TEXT);
    INSERT INTO users VALUES
        (1, 'store_admin', 'Store Admin', 1, 'active', '" . password_hash('AdminPass@123', PASSWORD_DEFAULT) . "'),
        (2, 'cashier_alice', 'Alice Cashier', 2, 'active', '" . password_hash('CashierPass@123', PASSWORD_DEFAULT) . "'),
        (3, 'super_admin', 'Super Admin', 3, 'active', '" . password_hash('SuperPass@123', PASSWORD_DEFAULT) . "');

    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));
    INSERT INTO user_roles VALUES (1, 1), (2, 2), (3, 3);

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT);
    INSERT INTO registers VALUES (1, 'Register 1');

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY,
        cashier_id INTEGER,
        register_id INTEGER,
        status TEXT,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT
    );
    INSERT INTO cashier_shifts VALUES (10, 2, 1, 'open', datetime('now'), NULL);

    CREATE TABLE gcash_service_sessions (
        session_id INTEGER PRIMARY KEY,
        cashier_id INTEGER,
        shift_id INTEGER,
        opening_service_cash REAL,
        opening_wallet_balance REAL,
        closing_service_cash REAL,
        closing_wallet_balance REAL,
        status TEXT,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT
    );
    INSERT INTO gcash_service_sessions VALUES (20, 2, 10, 2000.00, 10000.00, NULL, NULL, 'open', datetime('now'), NULL);

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
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE gcash_adjustments (
        adjustment_id INTEGER PRIMARY KEY,
        transaction_id INTEGER,
        session_id INTEGER,
        service_cash_delta REAL DEFAULT 0.00,
        wallet_delta REAL DEFAULT 0.00,
        reason TEXT,
        authorized_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY,
        user_id INTEGER,
        action TEXT,
        module TEXT,
        record_id INTEGER,
        previous_value TEXT,
        new_value TEXT,
        category TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY,
        period_name TEXT,
        start_date TEXT,
        end_date TEXT,
        status TEXT
    );
    INSERT INTO fiscal_periods VALUES (1, 'Current Period', '2026-01-01', '2026-12-31', 'open');

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

// Helper to seed a 'Needs checking' transaction
$seedNeedsChecking = function (int $id, string $type = 'Cash-In', float $principal = 500.0, bool $confCash = true, bool $confWallet = false, string $ref = null) use ($pdo): int {
    $stmt = $pdo->prepare("
        INSERT INTO gcash_transactions (
            transaction_id, session_id, shift_id, transaction_type, reference_number,
            amount, principal_amount, fee_amount, fee_method, service_cash_delta,
            wallet_delta, gross_fee, cashier_id, confirmed_wallet, confirmed_cash,
            notes, verification_status, created_at
        ) VALUES (
            ?, 20, 10, ?, ?,
            ?, ?, 10.00, 'cash', ?,
            0.00, 0.00, 2, ?, ?,
            'Pending verification at counter', 'Needs checking', datetime('now')
        )
    ");
    $cashDelta = $confCash ? ($principal + 10.00) : 0.00;
    $stmt->execute([
        $id, $type, $ref,
        $principal, $principal, $cashDelta,
        $confWallet ? 1 : 0, $confCash ? 1 : 0
    ]);
    return $id;
};

// =========================================================================
// TEST 1: Explain Verify & Clear in UI and guidance for failed exchanges
// =========================================================================
$overviewPhp = file_get_contents(dirname(__DIR__, 2) . '/frontend/components/administrator/gcash_overview.php');
$assert(str_contains($overviewPhp, 'Verify &amp; Clear'), 'Verify & Clear button exists in overview');
$assert(str_contains($overviewPhp, 'posts only applicable remaining movements') || str_contains($overviewPhp, 'posts only the remaining movements'), 'Verify & Clear explains posting only remaining movements');
$assert(str_contains($overviewPhp, 'dismissal') || str_contains($overviewPhp, 'cannot dismiss'), 'Verify & Clear explains it is not dismissal or cancellation');
$assert(str_contains($overviewPhp, 'Cashier Workspace') || str_contains($overviewPhp, 'cashier'), 'UI routes failed handling to Cashier Workspace');

// =========================================================================
// TEST 2: Record who checked cash and wallet and what each found
// Responsible Cashier-supplied checks may support accountable Admin confirmation
// =========================================================================
$tx1 = $seedNeedsChecking(101, 'Cash-In', 500.0, true, false);

// Admin completes with Cashier cash check + Admin wallet check
$res1 = $service->resolveNeedsChecking(1, $tx1, 'complete', [
    'reference' => '1234567890123',
    'cash_checked_by' => 2, // Alice Cashier checked physical cash
    'cash_finding' => 'PHP 510 cash counted and collected in drawer',
    'wallet_checked_by' => 1, // Admin Bob checked wallet transfer
    'wallet_finding' => 'Transfer confirmed in GCash merchant app',
    'notes' => 'Counter check and wallet verified',
]);

$assert($res1['status'] === 'Completed', 'Transaction resolved to Completed');

$saved1 = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = 101")->fetch(PDO::FETCH_ASSOC);
$assert($saved1['verification_status'] === 'Completed', 'DB status is Completed');
$assert((int)$saved1['verified_by'] === 1, 'verified_by is Admin (1)');
$assert((int)$saved1['cash_checked_by'] === 2, 'cash_checked_by is Cashier (2)');
$assert($saved1['cash_finding'] === 'PHP 510 cash counted and collected in drawer', 'cash_finding recorded');
$assert((int)$saved1['wallet_checked_by'] === 1, 'wallet_checked_by is Admin (1)');
$assert($saved1['wallet_finding'] === 'Transfer confirmed in GCash merchant app', 'wallet_finding recorded');
$assert(!empty($saved1['completed_at']), 'completed_at recorded');
$assert(!empty($saved1['reviewed_at']), 'reviewed_at recorded');

// Audit log check
$log1 = $pdo->query("SELECT * FROM activity_log WHERE record_id = 101 ORDER BY log_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert($log1 !== false, 'Activity log entry created');
$assert($log1['category'] === AuditRecordCategory::STORE_OPERATION, 'Audit category is STORE_OPERATION');
$after1 = json_decode($log1['new_value'], true);
$assert(isset($after1['cash_checked_by']) && (int)$after1['cash_checked_by'] === 2, 'Audit log records cash_checked_by');
$assert(isset($after1['cash_finding']) && $after1['cash_finding'] === 'PHP 510 cash counted and collected in drawer', 'Audit log records cash_finding');
$assert(isset($after1['wallet_checked_by']) && (int)$after1['wallet_checked_by'] === 1, 'Audit log records wallet_checked_by');
$assert(isset($after1['wallet_finding']) && $after1['wallet_finding'] === 'Transfer confirmed in GCash merchant app', 'Audit log records wallet_finding');

// =========================================================================
// TEST 3: Uncertain evidence in either part leaves exchange pending
// System balances alone are insufficient
// =========================================================================
// Case 3a: Uncertain cash finding
$tx2 = $seedNeedsChecking(102, 'Cash-In', 300.0, true, false);
$refuse(function () use ($service, $tx2) {
    $service->resolveNeedsChecking(1, $tx2, 'complete', [
        'reference' => '1234567890124',
        'cash_checked_by' => 2,
        'cash_finding' => 'uncertain if customer handed over full cash',
        'wallet_checked_by' => 1,
        'wallet_finding' => 'Wallet transfer confirmed in app',
    ]);
}, '/uncertain/i');

$checkPending = $pdo->query("SELECT verification_status FROM gcash_transactions WHERE transaction_id = 102")->fetchColumn();
$assert($checkPending === 'Needs checking', 'Exchange remains Needs checking on uncertain cash');

// Case 3b: Uncertain wallet finding
$refuse(function () use ($service, $tx2) {
    $service->resolveNeedsChecking(1, $tx2, 'complete', [
        'reference' => '1234567890124',
        'cash_checked_by' => 2,
        'cash_finding' => 'Cash collected in drawer',
        'wallet_checked_by' => 1,
        'wallet_finding' => 'App offline, wallet transfer unverified and pending',
    ]);
}, '/uncertain/i');
$assert($pdo->query("SELECT verification_status FROM gcash_transactions WHERE transaction_id = 102")->fetchColumn() === 'Needs checking', 'Exchange remains Needs checking on uncertain wallet');

// Case 3c: Missing checks / expected balance alone claimed
$refuse(function () use ($service, $tx2) {
    $service->resolveNeedsChecking(1, $tx2, 'complete', [
        'reference' => '1234567890124',
        'cash_finding' => '',
        'wallet_finding' => '',
        'notes' => 'Expected system balance matches so approving',
    ]);
}, '/evidence|insufficient|finding|balance/i');

// Case 3d: confirmed_cash or confirmed_wallet false
$refuse(function () use ($service, $tx2) {
    $service->resolveNeedsChecking(1, $tx2, 'complete', [
        'reference' => '1234567890124',
        'confirmed_cash' => false,
        'cash_finding' => 'Cash verified',
        'wallet_finding' => 'Wallet verified',
    ]);
}, '/confirm|evidence|pending/i');

// =========================================================================
// TEST 4: Route failed handling to responsible Cashier
// Add no Administrator cancellation authority
// =========================================================================
$tx3 = $seedNeedsChecking(103, 'Cash-In', 400.0, true, false);

// Admin attempting cancellation via resolveNeedsChecking directly is rejected
$refuse(function () use ($service, $tx3) {
    $service->resolveNeedsChecking(1, $tx3, 'cancel', [
        'reason' => 'Customer walked away before wallet sent',
    ]);
}, '/Cashier Workspace|cancellation authority|responsible Cashier/i');

// Super admin attempting cancellation is rejected
$refuse(function () use ($service, $tx3) {
    $service->resolveNeedsChecking(3, $tx3, 'cancel', [
        'reason' => 'Super admin cancelled exchange',
    ]);
}, '/Cashier Workspace|cancellation authority|responsible Cashier/i');

// Cashier without admin credentials is rejected
$refuse(function () use ($service, $tx3) {
    $service->resolveNeedsChecking(2, $tx3, 'cancel', [
        'reason' => 'Customer walked away',
    ]);
});

// gcash_overview_api.php rejects cancellation
$overviewApi = file_get_contents(dirname(__DIR__, 2) . '/frontend/components/administrator/gcash_overview_api.php');
$assert(!str_contains($overviewApi, "'cancel'"), 'gcash_overview_api has no cancel branch');

// =========================================================================
// TEST 5: Repeated/competing confirmations and preserved audit trails
// =========================================================================
$tx4 = $seedNeedsChecking(104, 'Cash-In', 200.0, true, false);

// First completion succeeds
$service->resolveNeedsChecking(1, $tx4, 'complete', [
    'reference' => '1234567890125',
    'cash_checked_by' => 2,
    'cash_finding' => 'Cash in register verified',
    'wallet_checked_by' => 1,
    'wallet_finding' => 'Wallet balance increased in app',
]);

// Repeated completion fails (409 conflict)
$refuse(function () use ($service, $tx4) {
    $service->resolveNeedsChecking(1, $tx4, 'complete', [
        'reference' => '1234567890125',
        'cash_checked_by' => 2,
        'cash_finding' => 'Cash in register verified',
        'wallet_checked_by' => 1,
        'wallet_finding' => 'Wallet balance increased in app',
    ]);
}, '/no longer in Needs checking status/i');

// Competing cancellation fails
$refuse(function () use ($service, $tx4) {
    $service->resolveNeedsChecking(2, $tx4, 'cancel', [
        'reason' => 'Trying to cancel completed exchange',
        'approver_username' => 'store_admin',
        'approver_password' => 'AdminPass@123',
    ]);
}, '/no longer in Needs checking status/i');

echo "GCash verify & clear evidence contract: passed\n";
