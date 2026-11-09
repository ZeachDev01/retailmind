<?php
// Scheduled Backup settings and permissions contract (#460).
//
// Proves the Super Administrator-owned settings seam plus the wiring that
// cannot be observed through the service alone: the Platform Settings section,
// the migration and fresh-schema seeds, the admin read-only schedule summary,
// the Super Administrator forced run, the CLI entry point, the Windows task
// installer, and the history Kind/File columns.
$private = sys_get_temp_dir() . '/rm-sched-settings-' . bin2hex(random_bytes(6));
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

final class ScheduledSettingsContractClock implements Clock
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
    $clock = new ScheduledSettingsContractClock(new DateTimeImmutable('2026-11-08 10:00:00', new DateTimeZone('Asia/Manila')));
    $settings = new ScheduledBackupSettings($pdo, $clock);

    $state = $settings->get();
    $assert($state['weekday'] === 0 && $state['time'] === '23:00', 'Defaults should be Sunday 23:00');
    $assert($state['last_outcome'] === '' && $state['last_success_at'] === null, 'A fresh schedule has no outcome');
    $assert($state['next_run_at'] === '2026-11-08 23:00:00', 'Next run should use the application timezone');

    $throws(
        static fn() => $settings->update('admin', ['weekday' => 1, 'time' => '01:00', 'destination' => ''], 2),
        DomainException::class,
        'An ordinary Administrator must not change the Scheduled Backup settings'
    );

    foreach ([['weekday' => '9'], ['time' => '25:00']] as $bad) {
        $payload = array_merge(['weekday' => 0, 'time' => '23:00', 'destination' => ''], $bad);
        $throws(
            static fn() => $settings->update('super_admin', $payload, 1),
            InvalidArgumentException::class,
            'Invalid schedule input ' . json_encode($bad) . ' must be rejected'
        );
    }

    // --- Platform Settings UI ----------------------------------------------
    $root = dirname(__DIR__, 3);
    $read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);

    $ui = $read('src/frontend/components/system_administrator/system_settings.php');
    foreach ([
        'RoleCapabilityPolicy::PLATFORM_GOVERNANCE',
        "action === 'save_backup_schedule'",
        'ScheduledBackupSettings',
        'name="backup_schedule_weekday"',
        'name="backup_schedule_time"',
        'name="backup_destination_path"',
        'heading-backup-schedule',
        'Scheduled Backup',
        'Backup Destination',
        'Next scheduled run',
        'Last successful run',
        'Last outcome',
        'csrf_field()',
        "log_activity(\$pdo, (int)\$_SESSION['user_id'], 'Scheduled backup settings updated', 'Platform Settings'",
        'OperatorAlert::message',
    ] as $needle) {
        $assert(str_contains($ui, $needle), "Platform Settings UI must contain {$needle}");
    }
    $assert(
        strpos($ui, 'heading-dormancy') < strpos($ui, 'heading-backup-schedule')
            && strpos($ui, 'heading-backup-schedule') < strpos($ui, 'email-heading'),
        'The Scheduled Backup section should follow Dormancy Policy and precede Email'
    );

    $migration = $read('src/backend/database/migrations/202611100002_scheduled_backup_settings.php');
    foreach ([
        '202611100002_scheduled_backup_settings',
        'INSERT IGNORE',
        'ScheduledBackupSettings::WEEKDAY_KEY',
        'ScheduledBackupSettings::TIME_KEY',
        'ScheduledBackupSettings::DESTINATION_KEY',
        'ScheduledBackupSettings::CATCHUP_CHECKED_KEY',
    ] as $needle) {
        $assert(str_contains($migration, $needle), "Scheduled Backup migration must contain {$needle}");
    }

    $schema = $read('src/backend/database/sql/schema.sql');
    foreach ([
        "'backup_schedule_weekday'",
        "'backup_schedule_time'",
        "'backup_destination_path'",
        "'backup_schedule_last_success_at'",
        "'backup_schedule_catchup_checked_at'",
        '202611100002_scheduled_backup_settings',
    ] as $needle) {
        $assert(str_contains($schema, $needle), "Fresh schema must seed/ledger {$needle}");
    }
    $update = $read('src/backend/database/sql/update.sql');
    foreach (['backup_schedule_weekday', 'backup_schedule_time', 'backup_destination_path', '202611100002_scheduled_backup_settings'] as $needle) {
        $assert(str_contains($update, $needle), "Upgrade SQL must seed/ledger {$needle}");
    }

    // --- Administrator page stays read-only --------------------------------
    $adminPage = $read('src/frontend/components/administrator/database_backup.php');
    foreach (['MANAGE_DATABASE_BACKUP', 'Next scheduled run', 'Last outcome'] as $needle) {
        $assert(str_contains($adminPage, $needle), "Administrator backup page must contain {$needle}");
    }
    foreach (['save_backup_schedule', 'name="backup_destination_path"', 'name="backup_schedule_weekday"', 'run_scheduled_backup'] as $forbidden) {
        $assert(!str_contains($adminPage, $forbidden), "Administrator backup page must not expose {$forbidden}");
    }
    $assert(
        !str_contains($adminPage, 'Backup Destination'),
        'The Administrator backup page must not show the Backup Destination control'
    );

    // --- Super Administrator in-app catch-up -------------------------------
    $superPage = $read('src/frontend/components/system_administrator/backup_restore.php');
    foreach ([
        'PLATFORM_GOVERNANCE',
        "action === 'run_scheduled_catchup'",
        'run_scheduled_catchup',
        'ScheduledBackupService',
        'runCatchup()',
        'Catch up missed backup',
        'require_local_request(',
        'csrf_field()',
        'csrf_verify()',
        'Next scheduled run',
        'Last outcome',
    ] as $needle) {
        $assert(str_contains($superPage, $needle), "Super Administrator backup page must contain {$needle}");
    }

    // --- Shared history labels replace per-page copies ----------------------
    $backupService = $read('src/backend/app/Backup/DatabaseBackupService.php');
    $scheduledSettings = $read('src/backend/app/Backup/ScheduledBackupSettings.php');
    foreach (['Manual', 'Scheduled', 'Restore', 'Available on server', 'Removed after replacement', 'Not retained', 'Missing'] as $needle) {
        $assert(str_contains($backupService, $needle), 'DatabaseBackupService must own history label ' . $needle);
    }
    foreach (['Skipped — retried next run', 'Completed', 'Failed'] as $needle) {
        $assert(str_contains($scheduledSettings, $needle), 'ScheduledBackupSettings must own outcome label ' . $needle);
    }
    foreach (['System Administrator page' => $superPage, 'Administrator page' => $adminPage] as $name => $page) {
        foreach (['<th scope="col">Kind</th>', '<th scope="col">File</th>', 'colspan="7"'] as $needle) {
            $assert(str_contains($page, $needle), $name . ' history must contain ' . $needle);
        }
        foreach (["\$row['backup_type_label']", 'DatabaseBackupService::artifactLabel', 'DatabaseBackupService::artifactClass', 'ScheduledBackupSettings::outcomeLabel'] as $needle) {
            $assert(str_contains($page, $needle), $name . ' history must use the shared label helper ' . $needle);
        }
    }
    $assert(str_contains($superPage, 'Available on server') || str_contains($backupService, 'Available on server'), 'The Super Administrator history must label a retained file');

    // --- CLI + Windows task installer --------------------------------------
    $cli = $read('src/backend/scripts/run_scheduled_backup.php');
    foreach (['PHP_SAPI !== \'cli\'', 'runDue()', 'ScheduledBackupService', 'exit('] as $needle) {
        $assert(str_contains($cli, $needle), "Scheduled Backup CLI must contain {$needle}");
    }
    $assert(!str_contains($cli, 'artifact_path'), 'The Scheduled Backup CLI must not print private artifact paths');

    $installer = $read('src/backend/scripts/install_scheduled_tasks.ps1');
    foreach ([
        'RetailMind Weekly Backup',
        'run_scheduled_backup.php',
        'weekly-backup.log',
        'New-ScheduledTaskTrigger -AtStartup',
        '-RepetitionInterval (New-TimeSpan -Hours 1)',
        'StartWhenAvailable',
    ] as $needle) {
        $assert(str_contains($installer, $needle), "Windows task installer must contain {$needle}");
    }

    // --- Suite wiring -------------------------------------------------------
    $runAll = $read('src/backend/tests/run_all.sh');
    foreach ([
        'php src/backend/tests/scheduled_backup_schedule_contract.php',
        'php src/backend/tests/scheduled_backup_service_contract.php',
        'php src/backend/tests/scheduled_backup_settings_contract.php',
    ] as $command) {
        $assert(str_contains($runAll, $command), "The full suite must run: {$command}");
    }
} catch (Throwable $exception) {
    $failures[] = 'Scheduled Backup settings contract threw: ' . $exception->getMessage();
}

$cleanup($private);

if ($failures) {
    fwrite(STDERR, "Scheduled Backup settings contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Scheduled Backup settings contract: passed\n";
