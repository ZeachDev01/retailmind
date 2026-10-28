<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';

use App\Services\GCashTransactionService;
use App\Services\CashierShiftService;
use App\Attention\SystemClock;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'super_admin'), (4, 'inventory_manager');

    CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, role_id INTEGER, status TEXT, must_change_password INTEGER DEFAULT 0);
    INSERT INTO users VALUES
        (1, 'Admin Bob', 'admin_bob', 1, 'active', 0),
        (2, 'Super Charlie', 'super_c', 3, 'active', 0),
        (3, 'Cashier Alice', 'cashier_alice', 2, 'active', 0),
        (4, 'Cashier Dave', 'cashier_dave', 2, 'active', 0),
        (5, 'Manager Eve', 'manager_eve', 4, 'active', 0);

    CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id));

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active');
    INSERT INTO registers VALUES (10, 'Main Register', 'active'), (11, 'Second Register', 'active');

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
        ip_address TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE fiscal_period_locks (lock_id INTEGER PRIMARY KEY AUTOINCREMENT, period_id INTEGER NOT NULL, table_name TEXT NULL);
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
");

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
};

$shiftService = new CashierShiftService($pdo);
$gcashService = new GCashTransactionService($pdo, ['min_length' => 6, 'max_length' => 32]);

// Open shift for Alice (#3) on register 10
$shiftId3 = $shiftService->openShift(3, 'cashier', 10, 1000.00);

// Open session for Alice
$sessStmt = $pdo->prepare("INSERT INTO gcash_service_sessions (shift_id, cashier_id, opening_service_cash, opening_wallet_balance, status) VALUES (?, ?, 2000.0, 5000.0, 'open')");
$sessStmt->execute([$shiftId3, 3]);
$sessId1 = (int)$pdo->lastInsertId();

// 1. Create transaction as Alice. Responsible role/user must be recorded as Alice / cashier.
$txId1 = $gcashService->save(3, '123456789012', '500.00', 'Cash-In');
$tx1Row = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = {$txId1}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$tx1Row['cashier_id'] === 3, 'Original cashier is Alice');
$assert((int)$tx1Row['responsible_user_id'] === 3, 'Initial responsible user is Alice');
$assert($tx1Row['responsible_role'] === 'cashier', 'Initial responsible role is cashier');

// Create second transaction held for checking
$txId2 = $gcashService->save(3, '987654321098', '1000.00', 'Cash-In');
// Mark both as Needs checking
$pdo->exec("UPDATE gcash_transactions SET verification_status = 'Needs checking', confirmed_wallet = 0, confirmed_cash = 1 WHERE transaction_id IN ({$txId1}, {$txId2})");

// Open shift for Dave (#4) on register 11
$shiftId4 = $shiftService->openShift(4, 'cashier', 11, 1000.00);

// 2. Handover session from Alice to Dave. Dave accepts only $txId1, NOT $txId2.
$handover = $gcashService->handoverSession(3, 4, 2000.00, 5000.00, 'Shift handover', [$txId1]);

// Check txId1: accepted by Dave
$tx1After = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = {$txId1}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$tx1After['cashier_id'] === 3, 'Attribution preserved: original cashier remains Alice');
$assert((int)$tx1After['responsible_user_id'] === 4, 'Follow-up responsibility transferred to Dave');
$assert($tx1After['responsible_role'] === 'cashier', 'Responsible role is cashier');

// Check txId2: unaccepted -> needs responsible cashier
$tx2After = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = {$txId2}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$tx2After['cashier_id'] === 3, 'Attribution preserved: original cashier remains Alice');
$assert($tx2After['responsible_user_id'] === null, 'Unaccepted exchange has no responsible user');
$assert($tx2After['responsible_role'] === null, 'Unaccepted exchange has no responsible role');

// 3. Clarification in audit logging: Handover transfers investigation responsibility, not ownership of underlying money shortage
$auditStmt = $pdo->query("SELECT * FROM activity_log WHERE action LIKE '%responsibility%' OR action LIKE '%handover%' OR new_value LIKE '%investigation%' ORDER BY log_id DESC");
$auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
$auditFound = false;
foreach ($auditRows as $ar) {
    $text = ($ar['action'] ?? '') . ' ' . ($ar['new_value'] ?? '');
    if (stripos($text, 'investigation responsibility') !== false && stripos($text, 'shortage') !== false) {
        $auditFound = true;
        break;
    }
}
$assert($auditFound, 'Audit log clarifies that handover transfers investigation responsibility, not ownership of underlying shortage');

// 4. Visible responsibility in overview and queues
$overview = $gcashService->overview();
$unresolvedItems = $overview['unresolved_activity'];
$tx1Overview = null;
$tx2Overview = null;
foreach ($unresolvedItems as $item) {
    if ((int)$item['transaction_id'] === $txId1) $tx1Overview = $item;
    if ((int)$item['transaction_id'] === $txId2) $tx2Overview = $item;
}
$assert($tx1Overview !== null, 'tx1 in unresolved overview');
$assert((int)$tx1Overview['responsible_user_id'] === 4, 'tx1 shows responsible user Dave');
$assert(str_contains($tx1Overview['responsible_label'], 'Dave') || str_contains($tx1Overview['responsible_label'], 'cashier_dave'), 'tx1 label shows Dave');

$assert($tx2Overview !== null, 'tx2 in unresolved overview');
$assert($tx2Overview['responsible_user_id'] === null, 'tx2 shows no responsible user');
$assert(str_contains($tx2Overview['responsible_label'], 'Needs a responsible'), 'tx2 label shows Needs a responsible Cashier');

// 5. Cashier overview shows accepted items to responsible cashier Dave even if originally created by Alice
$daveOverview = $gcashService->cashierOverview(4);
$daveNeedsCheckingIds = array_map(static fn($i) => (int)$i['transaction_id'], $daveOverview['needs_checking']);
$assert(in_array($txId1, $daveNeedsCheckingIds, true), 'Accepted txId1 is accessible in Dave cashier overview');
$assert(!in_array($txId2, $daveNeedsCheckingIds, true), 'Unaccepted txId2 is not in Dave cashier overview');

// 6. Block clearing unaccepted exchange without an accepted responsible role on file
$blockClear = false;
try {
    $gcashService->resolveNeedsChecking(1, $txId2, 'complete', ['reference' => '111222333444']);
} catch (DomainException $e) {
    if (stripos($e->getMessage(), 'responsible') !== false) {
        $blockClear = true;
    }
}
$assert($blockClear, 'Clearing unaccepted exchange without responsible role is blocked');

// 7. Transfer responsibility requires explanation and enforces role constraints
// 7a. Non-existent or empty explanation rejected
$emptyExp = false;
try {
    $gcashService->transferExchangeResponsibility(1, $txId2, 4, '');
} catch (DomainException $e) {
    $emptyExp = true;
}
$assert($emptyExp, 'Transferring responsibility without explanation is rejected');

// 7b. Super admin or non-store role rejected
$superRejected = false;
try {
    $gcashService->transferExchangeResponsibility(1, $txId2, 2, 'Transfer to super admin'); // User #2 is super_admin only
} catch (DomainException $e) {
    $superRejected = true;
}
$assert($superRejected, 'Target user must have cashier or admin role');

$invMgrRejected = false;
try {
    $gcashService->transferExchangeResponsibility(1, $txId2, 5, 'Transfer to inventory manager'); // User #5 is inventory_manager
} catch (DomainException $e) {
    $invMgrRejected = true;
}
$assert($invMgrRejected, 'Inventory manager cannot be responsible for GCash follow-up');

// 7c. Valid transfer to Admin Bob (#1) with explanation
$gcashService->transferExchangeResponsibility(1, $txId2, 1, 'Admin Bob will investigate missing receipt');
$tx2Transferred = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = {$txId2}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$tx2Transferred['responsible_user_id'] === 1, 'tx2 transferred to Admin Bob');
$assert($tx2Transferred['responsible_role'] === 'admin', 'tx2 responsible role is admin');

// Now clearing txId2 is permitted because responsible role is on file
$cleared = $gcashService->resolveNeedsChecking(1, $txId2, 'complete', ['reference' => '111222333444']);
$assert($cleared['verification_status'] === 'Completed', 'tx2 completed after assigning responsible role');

// 8. Closing differences across closures and handovers:
// Create a session closure with cash shortage variance
$closeDiff = $gcashService->closeSession(4, 1900.00, 5000.00, 'Closed with 100 shortage', 0, 4);
$newSessId = (int)$closeDiff['session_id'];
$sessRow = $pdo->query("SELECT * FROM gcash_service_sessions WHERE session_id = {$newSessId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$sessRow['cashier_id'] === 4, 'Session cashier is Dave');
$assert((int)$sessRow['responsible_user_id'] === 4, 'Closing difference responsible user recorded');
$assert($sessRow['responsible_role'] === 'cashier', 'Closing difference responsible role is cashier');

// 9. Block waiving discrepancy without an accepted responsible role on file
// Set responsible_user_id to NULL to simulate unassigned closing difference
$pdo->exec("UPDATE gcash_service_sessions SET responsible_user_id = NULL, responsible_role = NULL WHERE session_id = {$newSessId}");

$waiveBlocked = false;
try {
    $gcashService->waiveDiscrepancy(1, 'admin', $newSessId, 'Waiving without responsible person');
} catch (DomainException $e) {
    if (stripos($e->getMessage(), 'responsible') !== false) {
        $waiveBlocked = true;
    }
}
$assert($waiveBlocked, 'Waiving closing discrepancy without responsible role is blocked');

// 10. Transfer difference responsibility to Admin Bob with explanation
$gcashService->transferDifferenceResponsibility(1, $newSessId, 1, 'Admin Bob assumes investigation of cash box shortage');
$diffRow = $pdo->query("SELECT * FROM gcash_service_sessions WHERE session_id = {$newSessId}")->fetch(PDO::FETCH_ASSOC);
$assert((int)$diffRow['responsible_user_id'] === 1, 'Session difference transferred to Admin Bob');
$assert($diffRow['responsible_role'] === 'admin', 'Session difference responsible role is admin');

// Now waiving is allowed
$gcashService->waiveDiscrepancy(1, 'admin', $newSessId, 'Count audited and verified by Admin Bob');
$waivedRow = $pdo->query("SELECT * FROM gcash_service_sessions WHERE session_id = {$newSessId}")->fetch(PDO::FETCH_ASSOC);
$assert($waivedRow['waived_at'] !== null, 'Closing discrepancy waived successfully');

echo "GCash follow-up responsibility contract: passed\n";
