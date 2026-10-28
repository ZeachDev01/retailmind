<?php

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/FiscalPeriodGuardService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

$pdo->exec("
    CREATE TABLE fiscal_periods (
        period_id INTEGER PRIMARY KEY AUTOINCREMENT,
        period_name TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        created_by INTEGER,
        status TEXT NOT NULL DEFAULT 'open',
        closed_at TEXT,
        closed_by INTEGER
    );

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        status TEXT DEFAULT 'open'
    );

    CREATE TABLE gcash_service_sessions (
        session_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER,
        status TEXT DEFAULT 'closed',
        unresolved_carryover_count INTEGER DEFAULT 0
    );

    CREATE TABLE gcash_transactions (
        transaction_id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER,
        verification_status TEXT DEFAULT 'Completed',
        responsible_user_id INTEGER,
        responsible_role TEXT
    );

    CREATE TABLE inventory_counts (
        count_id INTEGER PRIMARY KEY AUTOINCREMENT,
        status TEXT DEFAULT 'completed',
        counted_at TEXT DEFAULT CURRENT_TIMESTAMP
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
");

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
};

$guard = new FiscalPeriodGuardService($pdo);

// Insert October period (ended 2026-10-25)
$pdo->exec("INSERT INTO fiscal_periods (period_id, period_name, start_date, end_date, status) VALUES (1, 'October 2026', '2026-10-01', '2026-10-25', 'open')");

// 1. Blocker: Open shift blocks period closure
$pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, status) VALUES (101, 1, 'open')");
$shiftBlocked = false;
try {
    $guard->closePeriod(1, 1, '2026-10-26');
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'open Cashier Shifts')) {
        $shiftBlocked = true;
    }
}
$assert($shiftBlocked, 'Open cashier shift blocks fiscal period closure');

// Close shift
$pdo->exec("UPDATE cashier_shifts SET status = 'closed' WHERE shift_id = 101");

// 2. Blocker: Open GCash session blocks period closure
$pdo->exec("INSERT INTO gcash_service_sessions (session_id, cashier_id, status) VALUES (201, 1, 'open')");
$gcashBlocked = false;
try {
    $guard->closePeriod(1, 1, '2026-10-26');
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'open or unreconciled GCash sessions')) {
        $gcashBlocked = true;
    }
}
$assert($gcashBlocked, 'Open GCash session blocks fiscal period closure');

// Close GCash session
$pdo->exec("UPDATE gcash_service_sessions SET status = 'closed' WHERE session_id = 201");

// 3. Blocker: Unaccepted / unassigned unsettled GCash exchange blocks period closure
$pdo->exec("INSERT INTO gcash_transactions (transaction_id, session_id, verification_status, responsible_user_id) VALUES (301, 201, 'Needs checking', NULL)");
$unassignedBlocked = false;
try {
    $guard->closePeriod(1, 1, '2026-10-26');
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'open or unreconciled GCash sessions')) {
        $unassignedBlocked = true;
    }
}
$assert($unassignedBlocked, 'Unassigned pending GCash exchange blocks fiscal period closure');

// 4. Accepted carryover: Explicitly assigned to a responsible cashier -> closure permitted!
$pdo->exec("UPDATE gcash_transactions SET responsible_user_id = 2, responsible_role = 'cashier' WHERE transaction_id = 301");

// 5. Blocker: Pending inventory count in period
$pdo->exec("INSERT INTO inventory_counts (count_id, status, counted_at) VALUES (401, 'pending', '2026-10-20 10:00:00')");
$countBlocked = false;
try {
    $guard->closePeriod(1, 1, '2026-10-26');
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'pending Inventory Counts')) {
        $countBlocked = true;
    }
}
$assert($countBlocked, 'In-period pending inventory count blocks period closure');

// Complete inventory count
$pdo->exec("UPDATE inventory_counts SET status = 'completed' WHERE count_id = 401");

// 6. Successful closure with carried-over obligation
$guard->closePeriod(1, 1, '2026-10-26');
$closedPeriod = $pdo->query("SELECT * FROM fiscal_periods WHERE period_id = 1")->fetch(PDO::FETCH_ASSOC);
$assert($closedPeriod['status'] === 'closed', 'Period 1 successfully closed');
$assert($closedPeriod['closed_by'] == 1, 'Closed by actor 1');

// Carried-over obligation is intact
$txRow = $pdo->query("SELECT * FROM gcash_transactions WHERE transaction_id = 301")->fetch(PDO::FETCH_ASSOC);
$assert($txRow['verification_status'] === 'Needs checking', 'Exchange remains pending across period closure');
$assert((int)$txRow['responsible_user_id'] === 2, 'Responsible user preserved');

// 7. Open next period: must start following day (2026-10-26)
$nextStartError = false;
try {
    $guard->openNextPeriod(1, 'November 2026', '2026-10-20', 1); // end date before start
} catch (InvalidArgumentException $e) {
    $nextStartError = true;
}
$assert($nextStartError, 'End date before next start date rejected');

$newPeriodId = $guard->openNextPeriod(1, 'Late Oct / Nov 2026', '2026-11-25', 1);
$assert($newPeriodId > 1, 'New period created');
$newPeriod = $pdo->query("SELECT * FROM fiscal_periods WHERE period_id = {$newPeriodId}")->fetch(PDO::FETCH_ASSOC);
$assert($newPeriod['start_date'] === '2026-10-26', 'Next period starts exactly the day following closed period end date');
$assert($newPeriod['status'] === 'open', 'New period is open');

echo "Fiscal period custody closure contract: passed\n";
