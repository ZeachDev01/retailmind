<?php

use App\Authorization\EmergencyAccessService;
use App\Database\Schema;

return [
    'key' => '202609180003_emergency_access',
    'description' => 'Durable reason-bound Emergency Access sessions and audit correlation',
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
            'emergency_access_duration_minutes',
            (string)EmergencyAccessService::DEFAULT_DURATION_MINUTES,
        ]);

        $pdo->exec("CREATE TABLE IF NOT EXISTS emergency_access_sessions (
            session_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT NOT NULL,
            reason VARCHAR(500) NOT NULL,
            activated_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            duration_minutes SMALLINT UNSIGNED NOT NULL,
            status ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
            revoked_at DATETIME NULL,
            revoked_by INT NULL,
            INDEX idx_emergency_actor_status (actor_user_id, status, expires_at),
            CONSTRAINT fk_emergency_actor FOREIGN KEY (actor_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
            CONSTRAINT fk_emergency_revoker FOREIGN KEY (revoked_by) REFERENCES users(user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        Schema::addColumnIfMissing($pdo, 'activity_log', 'metadata', 'JSON NULL AFTER `new_value`');
    },
];
