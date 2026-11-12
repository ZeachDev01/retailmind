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

// 14. A generated set covers one fiscal year and opens the first due range.
$historyBefore = $pdo->query('SELECT * FROM fiscal_periods ORDER BY period_id')->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec("UPDATE attention_settings SET setting_value = '0' WHERE setting_scope = 'store' AND setting_key = 'fiscal_period_auto_rollover'");
$generated = $guard->generatePeriodSchedule(12, '', 1, '2027-01-05');
$assert(count($generated['periods']) === 12, 'Twelve monthly periods generated');
$assert($generated['periods'][0]['period_name'] === 'FY2027 P1' && $generated['periods'][11]['period_name'] === 'FY2027 P12', 'Monthly names number the fiscal year');
$assert($generated['start_date'] === '2027-01-01' && $generated['end_date'] === '2027-12-31', 'Generated set covers the full year');
$assert(array_column($generated['periods'], 'status') === array_merge(['open'], array_fill(0, 11, 'scheduled')), 'The first covering range opens, the rest stay scheduled');
$assert($generated['auto_rollover_enabled'] === true, 'Scheduling future periods turns automatic rollover on');
$assert((float)$pdo->query("SELECT setting_value FROM attention_settings WHERE setting_scope = 'store' AND setting_key = 'fiscal_period_auto_rollover'")->fetchColumn() === 1.0, 'Automatic rollover setting persisted');

$monthlyRows = $generated['periods'];
for ($i = 1; $i < count($monthlyRows); $i++) {
    $assert(date('Y-m-d', strtotime($monthlyRows[$i - 1]['end_date'] . ' +1 day')) === $monthlyRows[$i]['start_date'], 'Generated months are contiguous');
}

// 15. The next fiscal year restarts numbering and honours the prefix.
$quarterly = $guard->generatePeriodSchedule(4, 'Store', 1, '2027-01-05');
$assert(array_column($quarterly['periods'], 'period_name') === ['Store FY2028 Q1', 'Store FY2028 Q2', 'Store FY2028 Q3', 'Store FY2028 Q4'], 'Quarterly names carry the prefix and fiscal year');
$assert($quarterly['start_date'] === '2028-01-01' && $quarterly['end_date'] === '2028-12-31', 'Quarters cover one full fiscal year');
$assert(array_column($quarterly['periods'], 'status') === array_fill(0, 4, 'scheduled'), 'Later generated quarters stay scheduled');

// 16. A duplicate generated name aborts the whole batch.
$outOfSequenceId = $guard->createPeriod('FY2029 P3', '2029-01-01', '2029-01-31', 1, '2027-01-05');
$before = (int)$pdo->query('SELECT COUNT(*) FROM fiscal_periods')->fetchColumn();
$expectError(fn() => $guard->generatePeriodSchedule(6, '', 1, '2027-01-05'), 'already exists');
$assert((int)$pdo->query('SELECT COUNT(*) FROM fiscal_periods')->fetchColumn() === $before, 'Nothing is inserted when one generated name collides');
$assert($pdo->query("SELECT status FROM fiscal_periods WHERE period_id = {$outOfSequenceId}")->fetchColumn() === 'scheduled', 'The colliding existing period is untouched');

// 17. Only counts that divide twelve months are accepted.
foreach ([0, 5, 7, 24, 25] as $invalidCount) {
    $expectError(fn() => $guard->generatePeriodSchedule($invalidCount, '', 1, '2027-01-05'), 'periods per year');
}
$expectError(fn() => $guard->generatePeriodSchedule(4, str_repeat('x', 21), 1, '2027-01-05'), '20 characters');
$assert((int)$pdo->query('SELECT COUNT(*) FROM fiscal_periods')->fetchColumn() === $before, 'Invalid input leaves existing periods unchanged');
$historyAfter = $pdo->query('SELECT * FROM fiscal_periods ORDER BY period_id LIMIT ' . count($historyBefore))->fetchAll(PDO::FETCH_ASSOC);
$assert($historyAfter === $historyBefore, 'Generating schedules preserves every existing period');

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

// 17. Automatic rollover is owned by the Fiscal Periods page only. Store
// Settings saving attention thresholds must not reset the stored choice.
$root = dirname(__DIR__, 3);
$storeSettings = (string)file_get_contents($root . '/src/frontend/components/administrator/store_settings.php');
$fiscalPeriods = (string)file_get_contents($root . '/src/frontend/components/system_administrator/fiscal_periods.php');
$assert(!str_contains($storeSettings, 'fiscal_period_auto_rollover'), 'Store Settings must not offer automatic rollover');
$assert(str_contains($fiscalPeriods, 'value="set_rollover"'), 'Fiscal Periods page owns the automatic rollover switch');
$assert(str_contains($fiscalPeriods, 'fiscal_period_auto_rollover'), 'Fiscal Periods page writes the automatic rollover setting');

echo "Fiscal period rollover setting location contract: passed\n";

// Every supported count divides the same twelve-month span, independent of calendar quarters.
$expectedStarts = [
    1 => ['2026-11-12'],
    2 => ['2026-11-12', '2027-05-12'],
    3 => ['2026-11-12', '2027-03-12', '2027-07-12'],
    4 => ['2026-11-12', '2027-02-12', '2027-05-12', '2027-08-12'],
    6 => ['2026-11-12', '2027-01-12', '2027-03-12', '2027-05-12', '2027-07-12', '2027-09-12'],
    12 => ['2026-11-12', '2026-12-12', '2027-01-12', '2027-02-12', '2027-03-12', '2027-04-12', '2027-05-12', '2027-06-12', '2027-07-12', '2027-08-12', '2027-09-12', '2027-10-12'],
];
foreach ($expectedStarts as $count => $starts) {
    $pdo->exec('DELETE FROM fiscal_periods');
    $schedule = $guard->generatePeriodSchedule($count, '', 1, '2026-11-12');
    $assert(array_column($schedule['periods'], 'start_date') === $starts, "{$count} divisions have the expected starts");
    $assert($schedule['end_date'] === '2027-11-11', "{$count} divisions cover exactly one fiscal year");
    $assert($schedule['periods'][0]['status'] === 'open', 'Initial generation opens the period covering today');
    $assert(count(array_filter($schedule['periods'], fn(array $period): bool => $period['status'] === 'scheduled')) === $count - 1, 'Every later period stays scheduled');
    foreach ($schedule['periods'] as $index => $period) {
        $expectedName = 'FY2026 ' . ($count === 4 ? 'Q' : 'P') . ($index + 1);
        $assert($period['period_name'] === $expectedName, 'Names use sequence and the starting fiscal year');
        if (isset($starts[$index + 1])) {
            $assert((new DateTimeImmutable($period['end_date']))->modify('+1 day')->format('Y-m-d') === $starts[$index + 1], 'Periods have no gap or overlap');
        }
    }
}

// Month-end clamping never drifts later boundaries; leap years keep real February dates.
foreach ([
    ['2027-01-31', 12, ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], '2028-01-30'],
    ['2028-01-31', 12, ['2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30'], '2029-01-30'],
    ['2028-02-29', 4, ['2028-02-29', '2028-05-29', '2028-08-29', '2028-11-29'], '2029-02-27'],
    ['2027-04-01', 4, ['2027-04-01', '2027-07-01', '2027-10-01', '2028-01-01'], '2028-03-31'],
] as [$start, $count, $starts, $end]) {
    $pdo->exec('DELETE FROM fiscal_periods');
    $schedule = $guard->generatePeriodSchedule($count, 'Store', 1, $start);
    $assert(array_slice(array_column($schedule['periods'], 'start_date'), 0, count($starts)) === $starts, "Boundaries stay anchored for {$start}");
    $assert($schedule['end_date'] === $end, "Fiscal year ends correctly for {$start}");
    $assert($schedule['periods'][0]['period_name'] === 'Store FY' . substr($start, 0, 4) . ' ' . ($count === 4 ? 'Q1' : 'P1'), 'Optional prefix remains supported');
}

// An existing chain can start the generated fiscal year mid-month, without history edits.
$pdo->exec('DELETE FROM fiscal_periods');
$guard->createPeriod('Existing November', '2026-11-01', '2026-11-11', 1, '2026-11-05');
$existing = $pdo->query('SELECT * FROM fiscal_periods')->fetchAll(PDO::FETCH_ASSOC);
$schedule = $guard->generatePeriodSchedule(4, '', 1, '2026-11-05');
$assert($schedule['start_date'] === '2026-11-12' && $schedule['end_date'] === '2027-11-11', 'Continued fiscal year preserves the exact next day');
$assert(array_column($schedule['periods'], 'status') === array_fill(0, 4, 'scheduled'), 'Existing open period stays the only open period');
$assert($pdo->query('SELECT * FROM fiscal_periods ORDER BY period_id LIMIT 1')->fetchAll(PDO::FETCH_ASSOC) === $existing, 'Mid-month generation leaves existing history unchanged');

echo "Fiscal-year divisions and month boundaries: passed\n";
