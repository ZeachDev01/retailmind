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

// 14. A generated set is continuous, calendar-aligned and opens the first due range.
$pdo->exec("UPDATE attention_settings SET setting_value = '0' WHERE setting_scope = 'store' AND setting_key = 'fiscal_period_auto_rollover'");
$generated = $guard->generatePeriodSchedule('monthly', 3, '', 1, '2027-01-05');
$assert(count($generated['periods']) === 3, 'Three monthly periods generated');
$assert(array_column($generated['periods'], 'period_name') === ['2027-01', '2027-02', '2027-03'], 'Monthly names follow the calendar');
$assert($generated['start_date'] === '2027-01-01' && $generated['end_date'] === '2027-03-31', 'Generated set covers the full quarter');
$assert(array_column($generated['periods'], 'status') === ['open', 'scheduled', 'scheduled'], 'The first covering range opens, the rest stay scheduled');
$assert($generated['auto_rollover_enabled'] === true, 'Scheduling future periods turns automatic rollover on');
$assert((float)$pdo->query("SELECT setting_value FROM attention_settings WHERE setting_scope = 'store' AND setting_key = 'fiscal_period_auto_rollover'")->fetchColumn() === 1.0, 'Automatic rollover setting persisted');

$monthlyRows = $pdo->query("SELECT start_date, end_date FROM fiscal_periods WHERE period_name LIKE '2027-0%' ORDER BY start_date")->fetchAll(PDO::FETCH_ASSOC);
for ($i = 1; $i < count($monthlyRows); $i++) {
    $assert(date('Y-m-d', strtotime($monthlyRows[$i - 1]['end_date'] . ' +1 day')) === $monthlyRows[$i]['start_date'], 'Generated months are contiguous');
}

// 15. Quarterly generation aligns to calendar quarters and honours the prefix.
$quarterly = $guard->generatePeriodSchedule('quarterly', 2, 'FY', 1, '2027-01-05');
$assert(array_column($quarterly['periods'], 'period_name') === ['FY Q2 2027', 'FY Q3 2027'], 'Quarterly names carry the prefix and quarter');
$assert($quarterly['start_date'] === '2027-04-01' && $quarterly['end_date'] === '2027-09-30', 'Quarters cover complete calendar quarters');
$assert(array_column($quarterly['periods'], 'status') === ['scheduled', 'scheduled'], 'Later generated quarters stay scheduled');

// 16. A duplicate generated name aborts the whole batch.
$outOfSequenceId = $guard->createPeriod('2027-12', '2027-10-01', '2027-10-31', 1, '2027-01-05');
$before = (int)$pdo->query('SELECT COUNT(*) FROM fiscal_periods')->fetchColumn();
$expectError(fn() => $guard->generatePeriodSchedule('monthly', 4, '', 1, '2027-01-05'), 'already exists');
$assert((int)$pdo->query('SELECT COUNT(*) FROM fiscal_periods')->fetchColumn() === $before, 'Nothing is inserted when one generated name collides');
$assert($pdo->query("SELECT status FROM fiscal_periods WHERE period_id = {$outOfSequenceId}")->fetchColumn() === 'scheduled', 'The colliding existing period is untouched');

// 17. Invalid cadence and counts are refused before writing anything.
$expectError(fn() => $guard->generatePeriodSchedule('weekly', 2, '', 1, '2027-01-05'), 'monthly or quarterly');
$expectError(fn() => $guard->generatePeriodSchedule('monthly', 0, '', 1, '2027-01-05'), 'between 1 and 24');
$expectError(fn() => $guard->generatePeriodSchedule('monthly', 25, '', 1, '2027-01-05'), 'between 1 and 24');

echo "Fiscal period generation contract: passed\n";

// 18. Rollover outcomes notify Administrators once per event.
$pdo->exec('
    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        status TEXT,
        role_id INTEGER
    );
    CREATE TABLE roles (
        role_id INTEGER PRIMARY KEY,
        role_name TEXT
    );
    CREATE TABLE user_roles (
        user_id INTEGER,
        role_id INTEGER
    );
    CREATE TABLE notifications (
        notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        reference_id INTEGER,
        reference_type TEXT,
        attention_key TEXT,
        attention_severity TEXT,
        attention_count INTEGER,
        attention_destination TEXT,
        is_read INTEGER DEFAULT 0,
        created_at TEXT,
        workspace TEXT DEFAULT \'\',
        inapp_enabled INTEGER DEFAULT 1,
        email_pending INTEGER DEFAULT 0
    );
    INSERT INTO roles (role_id, role_name) VALUES (1, \'admin\');
    INSERT INTO users (user_id, status, role_id) VALUES (10, \'active\', 1);
');

$notified = $guard->rolloverIfDue('2027-02-01', 1);
$assert($notified['rolled'] === true, 'Rollover runs when a notification test account exists');
$assert(
    (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 10 AND type = 'fiscal_period'")->fetchColumn() === 1,
    'Administrator receives one fiscal period rollover notification'
);
$notice = $pdo->query("SELECT * FROM notifications WHERE user_id = 10 ORDER BY notification_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert($notice['attention_destination'] === 'components/system_administrator/fiscal_periods.php', 'Notification links to the Fiscal Periods page');
$assert((int)$notice['email_pending'] === 1, 'Rollover notification queues the daily Brevo email');

$guard->rolloverIfDue('2027-02-01', 1);
$assert(
    (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = 10 AND type = 'fiscal_period'")->fetchColumn() === 1,
    'A repeated rollover check does not duplicate the notification'
);

$pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('Manual close', '2026-01-01', '2026-01-31', 'open')");
$manualId = (int)$pdo->lastInsertId();
$guard->closePeriod($manualId, 1, '2026-02-01');
$manualNotice = $pdo->query("SELECT * FROM notifications WHERE attention_key = 'store.fiscal-period.closed.{$manualId}'")->fetch(PDO::FETCH_ASSOC);
$assert($manualNotice !== false, 'Manual closure notifies Administrators');
$assert((int)$manualNotice['email_pending'] === 1, 'Manual closure queues the daily Brevo email');

echo "Fiscal period rollover notification contract: passed\n";
