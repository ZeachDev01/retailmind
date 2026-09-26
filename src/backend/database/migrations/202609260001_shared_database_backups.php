<?php

use App\Database\Schema;

return [
    'key' => '202609260001_shared_database_backups',
    'description' => 'Shared encrypted Database Backup coordination for Administrator and Super Administrator',
    'up' => static function (PDO $pdo): void {
        if (!Schema::tableExists($pdo, 'store_write_gate')) {
            $pdo->exec(
                "CREATE TABLE store_write_gate (
                    gate_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    paused_at DATETIME NULL,
                    paused_by INT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $pdo->exec(
            "INSERT IGNORE INTO store_write_gate (gate_key) VALUES ('store_writes')"
        );

        // At most one active operation: a finished operation clears
        // active_slot to NULL, and a UNIQUE index allows many NULLs.
        if (!Schema::tableExists($pdo, 'backup_operations')) {
            $pdo->exec(
                "CREATE TABLE backup_operations (
                    operation_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    active_slot TINYINT NULL DEFAULT 1,
                    state ENUM('capturing','completed','failed','abandoned') NOT NULL DEFAULT 'capturing',
                    requested_by INT NULL,
                    requested_by_role VARCHAR(32) NULL,
                    artifact_token VARCHAR(64) NULL,
                    artifact_path VARCHAR(255) NULL,
                    filename VARCHAR(255) NULL,
                    file_size BIGINT NULL,
                    envelope_version VARCHAR(16) NULL,
                    cipher VARCHAR(64) NULL,
                    snapshot_at DATETIME NULL,
                    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    heartbeat_at DATETIME NULL,
                    finished_at DATETIME NULL,
                    detail TEXT NULL,
                    UNIQUE KEY uq_backup_operations_active (active_slot),
                    KEY idx_backup_operations_started (started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!Schema::tableExists($pdo, 'backup_history')) {
            $pdo->exec(
                "CREATE TABLE backup_history (
                    backup_id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    filename VARCHAR(255) NOT NULL,
                    backup_type ENUM('manual','scheduled','restore') NOT NULL DEFAULT 'manual',
                    file_size BIGINT NULL,
                    status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
                    performed_by INT NULL,
                    notes TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (performed_by) REFERENCES users(user_id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        Schema::addColumnIfMissing($pdo, 'backup_history', 'snapshot_at', 'DATETIME NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'envelope_version', "VARCHAR(16) NULL");
        Schema::addColumnIfMissing($pdo, 'backup_history', 'cipher', 'VARCHAR(64) NULL');
        Schema::addColumnIfMissing($pdo, 'backup_history', 'requested_by_role', 'VARCHAR(32) NULL');

        // Recovery evidence that must survive restoring an older snapshot.
        // Created from activity_log so every column, including later additions,
        // is preserved verbatim; the restore service drops its foreign keys so
        // evidence outlives a reset of the accounts it referenced.
        if (!Schema::tableExists($pdo, 'restore_preserved_activity')) {
            $pdo->exec('CREATE TABLE restore_preserved_activity LIKE activity_log');
        }
    },
];
