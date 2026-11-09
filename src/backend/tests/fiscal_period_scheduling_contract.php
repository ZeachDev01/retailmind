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

    CREATE TABLE attention_settings (
        setting_scope TEXT NOT NULL,
        setting_key TEXT NOT NULL,
        setting_value TEXT NOT NULL,
        updated_by INTEGER,
        updated_at TEXT,
        PRIMARY KEY (setting_scope, setting_key)
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
        metadata TEXT,
        ip_address TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
};

$expectError = static function (callable $work, string $needle): void {
    try {
        $work();
    } catch (Throwable $error) {
        if (stripos($error->getMessage(), $needle) === false) {
            throw new RuntimeException("Expected error containing '{$needle}', got '{$error->getMessage()}'");
        }
        return;
    }
    throw new RuntimeException("Expected an error containing '{$needle}' but none was raised");
};

$guard = new FiscalPeriodGuardService($pdo);
$today = '2026-11-10';

// 1. The first period that covers today is open.
$novemberId = $guard->createPeriod('November 2026', '2026-11-01', '2026-11-30', 1, $today);
$november = $pdo->query("SELECT * FROM fiscal_periods WHERE period_id = {$novemberId}")->fetch(PDO::FETCH_ASSOC);
$assert($november['status'] === 'open', 'First covering period is open');

// 2. A future period is accepted as scheduled while another period is open.
$decemberId = $guard->createPeriod('December 2026', '2026-12-01', '2026-12-31', 1, $today);
$december = $pdo->query("SELECT * FROM fiscal_periods WHERE period_id = {$decemberId}")->fetch(PDO::FETCH_ASSOC);
$assert($december['status'] === 'scheduled', 'Future period is scheduled, not a duplicate open period');
$assert((int)$pdo->query("SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open'")->fetchColumn() === 1, 'Only one period is open');

// 3. Periods can be queued ahead of time.
$januaryId = $guard->createPeriod('January 2027', '2027-01-01', '2027-01-31', 1, $today);
$assert((int)$pdo->query("SELECT COUNT(*) FROM fiscal_periods WHERE status = 'scheduled'")->fetchColumn() === 2, 'Two scheduled periods queued');

// 4. Gaps and non-continuous ranges are still refused.
$expectError(fn() => $guard->createPeriod('Gap', '2027-02-03', '2027-02-28', 1, $today), 'start the day after');
$expectError(fn() => $guard->createPeriod('Backdated overlap', '2027-01-15', '2027-02-28', 1, $today), 'start the day after');

// 5. A scheduled period cannot open early or while another period is open.
$expectError(fn() => $guard->openScheduledPeriod($decemberId, 1, $today), 'cannot be opened before');
$expectError(fn() => $guard->openScheduledPeriod($decemberId, 1, '2026-12-01'), 'Only one fiscal period may be open');

// 6. Automatic rollover is off by default.
$off = $guard->rolloverIfDue('2026-12-01', 1);
$assert($off['enabled'] === false && $off['rolled'] === false, 'Rollover stays inactive until the Store setting allows it');
$assert($pdo->query("SELECT status FROM fiscal_periods WHERE period_id = {$novemberId}")->fetchColumn() === 'open', 'Period stays open when rollover is off');

// 7. Turn automatic rollover on.
$pdo->prepare("INSERT INTO attention_settings (setting_scope, setting_key, setting_value, updated_by, updated_at) VALUES ('store', 'fiscal_period_auto_rollover', '1', 1, '2026-11-10 08:00:00')")->execute();

// 8. Unfinished closure work still blocks the automatic rollover.
$pdo->exec("INSERT INTO cashier_shifts (cashier_id, status) VALUES (1, 'open')");
$blocked = $guard->rolloverIfDue('2026-12-01', 1);
$assert($blocked['enabled'] === true && $blocked['rolled'] === false, 'Blockers stop the automatic rollover');
$assert(in_array('open Cashier Shifts', $blocked['blockers'], true), 'Blocked rollover explains the open Cashier Shift');
$assert($pdo->query("SELECT status FROM fiscal_periods WHERE period_id = {$novemberId}")->fetchColumn() === 'open', 'Blocked rollover leaves the period open');

// 9. Clear work rolls over exactly one period and opens the next scheduled range.
$pdo->exec("UPDATE cashier_shifts SET status = 'closed'");
$rolled = $guard->rolloverIfDue('2026-12-01', 1);
$assert($rolled['rolled'] === true, 'Rollover succeeds once blockers are cleared');
$assert($rolled['closed_period_id'] === $novemberId, 'The due period is the one that closes');
$assert($rolled['opened_period_id'] === $decemberId, 'The scheduled successor opens');
$assert($pdo->query("SELECT status FROM fiscal_periods WHERE period_id = {$januaryId}")->fetchColumn() === 'scheduled', 'Later scheduled periods stay scheduled');
$rolledPeriod = $pdo->query("SELECT * FROM fiscal_periods WHERE period_id = {$novemberId}")->fetch(PDO::FETCH_ASSOC);
$assert($rolledPeriod['status'] === 'closed', 'Automatic rollover closes the due period');
$assert($rolledPeriod['closed_by'] === null, 'Automatic rollover is not attributed to a staff account');

// 10. A second rollover on the same day does nothing.
$again = $guard->rolloverIfDue('2026-12-01', 1);
$assert($again['rolled'] === false, 'A repeated rollover is a no-op');

// 11. Scheduled ranges can be edited while they stay continuous.
$amended = $guard->amendScheduledEndDate($januaryId, '2027-01-20');
$assert($amended['end_date'] === '2027-01-20', 'Scheduled end date amended');
$expectError(fn() => $guard->amendScheduledEndDate($januaryId, '2026-12-15'), 'before the start date');

// 12. Deleting respects continuity: the last period first.
$februaryId = $guard->createPeriod('February 2027', '2027-01-21', '2027-02-28', 1, '2026-12-01');
$expectError(fn() => $guard->deleteScheduledPeriod($januaryId, 1), 'Delete later fiscal periods first');
$guard->deleteScheduledPeriod($februaryId, 1);
$guard->deleteScheduledPeriod($januaryId, 1);
$assert((int)$pdo->query("SELECT COUNT(*) FROM fiscal_periods WHERE period_id IN ({$januaryId}, {$februaryId})")->fetchColumn() === 0, 'Scheduled periods deleted');

// 13. Open periods still carry the unchanged manual lifecycle.
$expectError(fn() => $guard->deleteScheduledPeriod($decemberId, 1), 'Only a scheduled fiscal period can be deleted');
$guard->closePeriod($decemberId, 1, '2027-01-01');

echo "Fiscal period scheduling contract: passed\n";
