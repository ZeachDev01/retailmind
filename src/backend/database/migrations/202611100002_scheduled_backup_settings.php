<?php

use App\Backup\ScheduledBackupSettings;

return [
    'key' => '202611100002_scheduled_backup_settings',
    'description' => 'Seed the Sunday 23:00 Scheduled Backup schedule, Backup Destination, and last-run state',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS platform_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_platform_settings_updated_by
                FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $setting = $pdo->prepare(
            'INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES (?, ?)'
        );
        foreach ([
            ScheduledBackupSettings::WEEKDAY_KEY => (string)ScheduledBackupSettings::DEFAULT_WEEKDAY,
            ScheduledBackupSettings::TIME_KEY => ScheduledBackupSettings::DEFAULT_TIME,
            ScheduledBackupSettings::DESTINATION_KEY => '',
            ScheduledBackupSettings::LAST_SUCCESS_KEY => '',
            ScheduledBackupSettings::LAST_ATTEMPT_KEY => '',
            ScheduledBackupSettings::LAST_OUTCOME_KEY => '',
            ScheduledBackupSettings::LAST_MESSAGE_KEY => '',
            ScheduledBackupSettings::LAST_BACKUP_ID_KEY => '',
            ScheduledBackupSettings::CATCHUP_CHECKED_KEY => '',
        ] as $key => $value) {
            $setting->execute([$key, $value]);
        }
    },
];
