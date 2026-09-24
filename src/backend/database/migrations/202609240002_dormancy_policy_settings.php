<?php

use App\Authorization\DormancyPolicyService;

return [
    'key' => '202609240002_dormancy_policy_settings',
    'description' => 'Seed Dormancy Policy disable-days and warn-days Platform Settings (ticket #60)',
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
        $setting->execute([
            DormancyPolicyService::DISABLE_DAYS_KEY,
            (string)DormancyPolicyService::DEFAULT_DISABLE_DAYS,
        ]);
        $setting->execute([
            DormancyPolicyService::WARN_DAYS_KEY,
            (string)DormancyPolicyService::DEFAULT_WARN_DAYS,
        ]);
    },
];
