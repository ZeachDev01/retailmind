<?php

use App\Database\Schema;

return [
    'key' => '202609230002_stock_issue_corrections',
    'description' => 'Stock-issue correction lifecycle: returned/cancelled states and append-only revision trail (ticket #30)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS inventory_adjustment_revisions (
                    revision_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    adjustment_id INTEGER NOT NULL,
                    actor_id INTEGER NULL,
                    action TEXT NOT NULL,
                    old_status TEXT NULL,
                    new_status TEXT NULL,
                    old_values TEXT NULL,
                    new_values TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            $pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_adjustment_revisions_adjustment ON inventory_adjustment_revisions (adjustment_id)'
            );
            return;
        }

        // Extend the status enum for the correction lifecycle. INFORMATION_SCHEMA
        // reads keep this idempotent across repeated runs.
        $columnType = '';
        try {
            $typeStmt = $pdo->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'inventory_adjustments' AND column_name = 'status'"
            );
            $typeStmt->execute();
            $columnType = strtolower((string)$typeStmt->fetchColumn());
        } catch (Throwable $e) {
            $columnType = '';
        }
        if ($columnType !== '' && (strpos($columnType, "'returned'") === false || strpos($columnType, "'cancelled'") === false)) {
            $pdo->exec(
                "ALTER TABLE `inventory_adjustments`
                  MODIFY COLUMN `status` ENUM('pending','approved','rejected','returned','cancelled') NOT NULL DEFAULT 'pending'"
            );
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `inventory_adjustment_revisions` (
                `revision_id` INT NOT NULL AUTO_INCREMENT,
                `adjustment_id` INT NOT NULL,
                `actor_id` INT NULL,
                `action` VARCHAR(50) NOT NULL,
                `old_status` VARCHAR(20) NULL,
                `new_status` VARCHAR(20) NULL,
                `old_values` TEXT NULL,
                `new_values` TEXT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`revision_id`),
                KEY `idx_adjustment_revisions_adjustment` (`adjustment_id`),
                CONSTRAINT `fk_adjustment_revisions_adjustment` FOREIGN KEY (`adjustment_id`)
                    REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Schema::addIndexIfMissing($pdo, 'inventory_adjustment_revisions', 'idx_adjustment_revisions_adjustment', '`adjustment_id`');
    },
];
