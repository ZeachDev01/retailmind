<?php

use App\Database\Schema;

return [
    'key' => '202609180005_attention_foundation',
    'description' => 'Shared live attention settings, active state, and notification keys',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS attention_settings (
            setting_scope ENUM('platform','store') NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value VARCHAR(100) NOT NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (setting_scope, setting_key),
            CONSTRAINT fk_attention_setting_actor
                FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS attention_states (
            user_id INT NOT NULL,
            attention_key VARCHAR(120) NOT NULL,
            fingerprint CHAR(64) NOT NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            first_detected_at DATETIME NOT NULL,
            last_detected_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            PRIMARY KEY (user_id, attention_key),
            INDEX idx_attention_states_active (user_id, is_active, last_detected_at),
            CONSTRAINT fk_attention_state_user
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        Schema::addColumnIfMissing($pdo, 'notifications', 'attention_key', 'VARCHAR(120) NULL AFTER `reference_type`');
        Schema::addColumnIfMissing($pdo, 'notifications', 'attention_severity', 'VARCHAR(20) NULL AFTER `attention_key`');
        Schema::addColumnIfMissing($pdo, 'notifications', 'attention_count', 'INT NULL AFTER `attention_severity`');
        Schema::addColumnIfMissing($pdo, 'notifications', 'attention_destination', 'VARCHAR(255) NULL AFTER `attention_count`');
        Schema::addIndexIfMissing($pdo, 'notifications', 'idx_notifications_attention', '`user_id`, `attention_key`, `created_at`');
    },
];
