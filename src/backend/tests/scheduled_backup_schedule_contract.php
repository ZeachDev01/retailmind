<?php
// Scheduled Backup schedule contract (#460).
//
// Proves the pure scheduling math and the Super Administrator-owned settings
// seam: Sunday 23:00 defaults in the application timezone, exact-time due,
// next-week wait, catch-up age and throttle, destination fail-closed rules,
// and the weekday/time validators. No MySQL and no package capture here.
$private = sys_get_temp_dir() . '/rm-sched-schedule-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
require_once __DIR__ . '/../bootstrap/app.php';
$_ENV['BACKUP_STORAGE_PATH'] = $private;

use App\Attention\Clock;
use App\Backup\ScheduledBackupSettings;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$throws = static function (callable $operation, string $exceptionClass, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert($exception instanceof $exceptionClass, $message . ' (received ' . $exception::class . ')');
    }
};

final class ScheduledScheduleContractClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

$cleanup = static function (string $path) use (&$cleanup): void {
    if (is_dir($path)) {
        foreach (glob($path . '/*') ?: [] as $child) {
            $cleanup($child);
        }
        @rmdir($path);
    } elseif (is_file($path)) {
        @unlink($path);
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE platform_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_by INTEGER NULL,
        updated_at TEXT NULL
    )');

    $manila = new DateTimeZone('Asia/Manila');
    $clock = new ScheduledScheduleContractClock(new DateTimeImmutable('2026-11-08 22:59:00', $manila));
    $settings = new ScheduledBackupSettings($pdo, $clock);

    // --- Defaults -----------------------------------------------------------
    $defaults = $settings->get();
    $assert($defaults['weekday'] === 0, 'Default weekday should be Sunday (0), got ' . var_export($defaults['weekday'], true));
    $assert($defaults['time'] === '23:00', 'Default time should be 23:00, got ' . var_export($defaults['time'], true));
    $assert(
        str_ends_with(str_replace('\\', '/', (string)$defaults['destination']), '/scheduled'),
        'Default destination should resolve under the private recovery store, got ' . $defaults['destination']
    );
    $assert($defaults['last_success_at'] === null, 'A fresh schedule has no last success');
    $assert($defaults['last_outcome'] === '', 'A fresh schedule has no last outcome');
    $assert($defaults['next_run_at'] === '2026-11-08 23:00:00', 'Next run should be today 23:00, got ' . $defaults['next_run_at']);

    // --- Due evaluation in the application timezone -------------------------
    $previous = '2026-11-01 23:00:00';
    $before = new DateTimeImmutable('2026-11-08 22:59:00', $manila);
    $exact = new DateTimeImmutable('2026-11-08 23:00:00', $manila);
    $after = new DateTimeImmutable('2026-11-08 23:01:00', $manila);
    $nextDay = new DateTimeImmutable('2026-11-09 00:00:00', $manila);
    // Exercise the page's visibility decision against the same fixed schedule.
    $page = file_get_contents(__DIR__ . '/../../frontend/components/system_administrator/backup_restore.php');
    preg_match('/\$missedScheduledBackup = .*?;/s', $page, $visibility);
    $scheduleNow = $nextDay;
    $scheduledSchedule = ['next_run_at' => '2026-11-15 23:00:00', 'weekday' => 0, 'time' => '23:00'];
    foreach (['2026-11-08 23:00:00' => false, '2026-11-01 23:00:00' => true, '' => true] as $success => $expected) {
        $scheduledSchedule['last_success_at'] = $success ?: null;
        eval($visibility[0]);
        $assert($missedScheduledBackup === $expected, 'Catch-up card visibility follows the last successful scheduled backup: ' . $success);
    }
    $scheduledSchedule['next_run_at'] = '';
    eval($visibility[0]);
    $assert(!$missedScheduledBackup, 'Unavailable schedule must not claim a missed backup');
    $assert(str_contains($page, '<?php if ($missedScheduledBackup): ?>'), 'Catch-up card must use the schedule visibility decision');

    $assert(!ScheduledBackupSettings::isDue($previous, 0, '23:00', $before), 'Not due one minute before the scheduled time');
    $assert(ScheduledBackupSettings::isDue($previous, 0, '23:00', $exact), 'Due at exactly the scheduled time');
    $assert(ScheduledBackupSettings::isDue($previous, 0, '23:00', $after), 'Due one minute after the scheduled time');
    $assert(
        !ScheduledBackupSettings::isDue('2026-11-08 23:00:00', 0, '23:00', $exact),
        'A success exactly at the configured slot is not due that minute'
    );
    $assert(
        !ScheduledBackupSettings::isDue('2026-11-08 23:00:00', 0, '23:00', $nextDay),
        'Not due again until the next scheduled week'
    );
    $assert(
        ScheduledBackupSettings::isDue('2026-11-08 23:00:00', 0, '23:00', new DateTimeImmutable('2026-11-15 23:00:00', $manila)),
        'Due again at the next scheduled week slot'
    );
    $assert(ScheduledBackupSettings::isDue(null, 0, '23:00', $before), 'A first-ever schedule is a normal due run');

    // --- Next run -----------------------------------------------------------
    $assert(
        ScheduledBackupSettings::nextScheduledAt(0, '23:00', $before)->format('Y-m-d H:i:s') === '2026-11-08 23:00:00',
        'Next run before the scheduled time is today'
    );
    $assert(
        ScheduledBackupSettings::nextScheduledAt(0, '23:00', $exact)->format('Y-m-d H:i:s') === '2026-11-15 23:00:00',
        'Next run at the scheduled instant is the following week'
    );
    $nextClock = new ScheduledScheduleContractClock($exact);
    $assert(
        (new ScheduledBackupSettings($pdo, $nextClock))->nextRunAt() === '2026-11-15 23:00:00',
        'nextRunAt should format in Asia/Manila local time'
    );

    // --- Catch-up age and one-run collapse ----------------------------------
    $now = new DateTimeImmutable('2026-11-08 10:00:00', $manila);
    $assert(ScheduledBackupSettings::isCatchUp('2026-10-31 23:00:00', $now), 'A success older than seven days is catch-up eligible');
    $assert(ScheduledBackupSettings::isCatchUp('2026-10-09 23:00:00', $now), 'Thirty missed days are catch-up eligible');
    $assert(!ScheduledBackupSettings::isCatchUp(null, $now), 'A first-ever run is not a catch-up');
    $assert(!ScheduledBackupSettings::isCatchUp('2026-11-03 23:00:00', $now), 'A recent success is not catch-up eligible');
    $assert(
        !ScheduledBackupSettings::isCatchUp('2026-11-08 10:00:00', $now)
            && !ScheduledBackupSettings::isDue('2026-11-08 10:00:00', 0, '23:00', $now),
        'One completed catch-up collapses all missed weeks into a single run'
    );

    // --- Catch-up throttle --------------------------------------------------
    $assert(ScheduledBackupSettings::catchUpThrottled('2026-11-08 09:00:00', $now), 'A catch-up check one hour ago is throttled');
    $assert(!ScheduledBackupSettings::catchUpThrottled('2026-11-08 03:00:00', $now), 'A catch-up check seven hours ago is allowed');
    $assert(!ScheduledBackupSettings::catchUpThrottled(null, $now), 'A missing catch-up check stamp is allowed');

    // --- Super Administrator-only update ------------------------------------
    foreach (['admin', 'inventory_manager', 'cashier', ''] as $role) {
        $throws(
            static fn() => $settings->update($role, ['weekday' => 0, 'time' => '23:00', 'destination' => ''], 7),
            DomainException::class,
            'Role ' . var_export($role, true) . ' must not change the Scheduled Backup settings'
        );
    }

    // --- Weekday and time validation ---------------------------------------
    foreach (['7', '-1', 'abc', '', '1.5'] as $weekday) {
        $throws(
            static fn() => $settings->update('super_admin', ['weekday' => $weekday, 'time' => '23:00', 'destination' => ''], 1),
            InvalidArgumentException::class,
            'Weekday ' . var_export($weekday, true) . ' must be rejected'
        );
    }
    foreach (['24:00', '9:5', '23:60', '2300', '', 'aa:bb'] as $time) {
        $throws(
            static fn() => $settings->update('super_admin', ['weekday' => 0, 'time' => $time, 'destination' => ''], 1),
            InvalidArgumentException::class,
            'Time ' . var_export($time, true) . ' must be rejected'
        );
    }
    $assert(
        ScheduledBackupSettings::isValidWeekday('0') && ScheduledBackupSettings::isValidWeekday('6')
            && !ScheduledBackupSettings::isValidWeekday('7'),
        'Weekday validation accepts 0 through 6 only'
    );
    $assert(
        ScheduledBackupSettings::isValidTime('00:00') && ScheduledBackupSettings::isValidTime('23:59')
            && !ScheduledBackupSettings::isValidTime('24:00') && !ScheduledBackupSettings::isValidTime('9:5'),
        'Time validation accepts 00:00 through 23:59 only'
    );

    // --- Destination fail-closed rules -------------------------------------
    $tmp = sys_get_temp_dir() . '/rm-sched-destination-' . bin2hex(random_bytes(6));
    mkdir($tmp, 0700, true);
    $projectRoot = dirname(__DIR__, 3);
    $previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $documentRoot = $tmp . '/docroot';
    mkdir($documentRoot, 0700, true);
    $_SERVER['DOCUMENT_ROOT'] = $documentRoot;

    $invalidDestinations = [
        'relative-folder',
        $tmp . '/../escape',
        $projectRoot . '/src/backend/storage/scheduled-contract-tmp',
        $documentRoot . '/backups',
        $tmp . '/not-a-directory.txt',
    ];
    file_put_contents($tmp . '/not-a-directory.txt', 'file');
    foreach ($invalidDestinations as $destination) {
        $caught = null;
        try {
            $settings->update('super_admin', ['weekday' => 0, 'time' => '23:00', 'destination' => $destination], 1);
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $assert($caught instanceof InvalidArgumentException, 'Destination ' . $destination . ' must be rejected');
        if ($caught !== null) {
            $assert(
                !\App\Support\OperatorAlert::isTechnicalMessage($caught->getMessage()),
                'Destination rejection must be a plain operator message, got: ' . $caught->getMessage()
            );
        }
    }

    // --- Accepted values persist -------------------------------------------
    $accepted = $tmp . '/scheduled-copy';
    $settings->update('super_admin', ['weekday' => 3, 'time' => '01:30', 'destination' => $accepted], 9);
    $saved = $settings->get();
    $assert($saved['weekday'] === 3, 'Accepted weekday should persist');
    $assert($saved['time'] === '01:30', 'Accepted time should persist');
    $assert(
        str_replace('\\', '/', (string)realpath($saved['destination'])) === str_replace('\\', '/', (string)realpath($accepted)),
        'Accepted destination should persist as the resolved folder'
    );
    $stored = $pdo->query('SELECT setting_key, setting_value FROM platform_settings ORDER BY setting_key')->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert(($stored['backup_schedule_weekday'] ?? null) === '3', 'Weekday should persist in the platform key-value store');
    $assert(($stored['backup_schedule_time'] ?? null) === '01:30', 'Time should persist in the platform key-value store');
    $assert(($stored['backup_destination_path'] ?? null) === $saved['destination'], 'Destination should persist in the platform key-value store');
    $updatedBy = $pdo->query("SELECT updated_by FROM platform_settings WHERE setting_key = 'backup_schedule_weekday'")->fetchColumn();
    $assert((int)$updatedBy === 9, 'Persisted settings should record the saving actor');

    // A blank destination restores the private-store default.
    $settings->update('super_admin', ['weekday' => 0, 'time' => '23:00', 'destination' => ''], 1);
    $assert(str_ends_with(str_replace('\\', '/', (string)$settings->get()['destination']), '/scheduled'), 'A blank destination should restore the default');

    if ($previousDocumentRoot === null) {
        unset($_SERVER['DOCUMENT_ROOT']);
    } else {
        $_SERVER['DOCUMENT_ROOT'] = $previousDocumentRoot;
    }
    $cleanup($tmp);
} catch (Throwable $exception) {
    $failures[] = 'Scheduled Backup schedule contract threw: ' . $exception->getMessage();
}

$cleanup($private);

if ($failures) {
    fwrite(STDERR, "Scheduled Backup schedule contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Scheduled Backup schedule contract: passed\n";
