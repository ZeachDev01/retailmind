<?php

use App\Database\Schema;

return [
    'key' => '202609180004_recovery_account',
    'description' => 'Sealed Recovery Account identity and offline lifecycle state',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing(
            $pdo,
            'users',
            'is_recovery_account',
            'BOOLEAN NOT NULL DEFAULT FALSE AFTER `branch_id`'
        );

        $pdo->exec("CREATE TABLE IF NOT EXISTS recovery_accounts (
            account_key VARCHAR(20) PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            activation_secret_hash VARCHAR(255) NOT NULL,
            activated_at DATETIME NULL,
            sealed_at DATETIME NULL,
            credentials_rotated_at DATETIME NULL,
            last_used_at DATETIME NULL,
            CONSTRAINT fk_recovery_account_user
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
];
