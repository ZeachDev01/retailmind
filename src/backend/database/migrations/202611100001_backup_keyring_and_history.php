<?php

use App\Database\Schema;

return [
    'key' => '202611100001_backup_keyring_and_history',
    'description' => 'Repair backup history metadata and record per-account recovery-key acknowledgements',
    'up' => static function (PDO $pdo): void {
        if (!Schema::tableExists($pdo, 'backup_history')) {
            $pdo->exec(
                "CREATE TABLE backup_history (
                    backup_id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    filename VARCHAR(255) NOT NULL,
                    backup_type ENUM('manual','scheduled','restore') NOT NULL DEFAULT 'manual',
                    file_size BIGINT NULL,
                    status ENUM('completed','failed','skipped') NOT NULL DEFAULT 'completed',
                    performed_by INT NULL,
                    notes TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    snapshot_at DATETIME NULL,
                    envelope_version VARCHAR(16) NULL,
                    cipher VARCHAR(64) NULL,
                    requested_by_role VARCHAR(32) NULL,
                    key_fingerprint CHAR(64) NULL,
                    artifact_path VARCHAR(512) NULL,
                    trigger_source VARCHAR(16) NULL,
                    verified_at DATETIME NULL,
                    removed_at DATETIME NULL,
                    KEY idx_backup_history_type_created (backup_type, created_at),
                    CONSTRAINT backup_history_ibfk_1 FOREIGN KEY (performed_by) REFERENCES users(user_id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        Schema::addColumnIfMissing($pdo, 'backup_history', 'snapshot_at', 'DATETIME NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'envelope_version', 'VARCHAR(16) NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'cipher', 'VARCHAR(64) NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'requested_by_role', 'VARCHAR(32) NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'key_fingerprint', 'CHAR(64) NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'artifact_path', 'VARCHAR(512) NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'trigger_source', 'VARCHAR(16) NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'verified_at', 'DATETIME NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'removed_at', 'DATETIME NULL');
        Schema::addIndexIfMissing($pdo, 'backup_history', 'idx_backup_history_type_created', '`backup_type`, `created_at`');

        // A skipped scheduled run is an outcome, not a failure.
        try {
            $pdo->exec("ALTER TABLE `backup_history` MODIFY COLUMN `status` ENUM('completed','failed','skipped') NOT NULL DEFAULT 'completed'");
        } catch (Throwable) {
            // Non-MySQL lineages do not support this enum change; the table is already usable.
        }

        // Acknowledgements are per account and follow the account lifecycle.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_backup_key_acknowledgements (
                user_id INT NOT NULL,
                key_fingerprint CHAR(64) NOT NULL,
                acknowledged_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, key_fingerprint),
                KEY idx_backup_key_ack_fingerprint (key_fingerprint),
                CONSTRAINT fk_backup_key_ack_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
];
