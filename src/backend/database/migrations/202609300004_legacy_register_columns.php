<?php

use App\Database\Schema;

return [
    'key' => '202609300004_legacy_register_columns',
    'description' => 'Upgrade existing Register columns to the current Cashier schema',
    'up' => static function (PDO $pdo): void {
        if (!Schema::tableExists($pdo, 'registers')) {
            throw new RuntimeException('The Registers table is required before upgrading its columns.');
        }

        // Older installations already had a registers table, so the original
        // CREATE TABLE IF MISSING migration left its old columns untouched.
        // Copy values before relaxing the old required fields; Register IDs and
        // every existing Cashier Shift reference remain unchanged.
        $hasLegacyName = Schema::columnExists($pdo, 'registers', 'register_name');
        if (!Schema::columnExists($pdo, 'registers', 'name')) {
            if (!$hasLegacyName) {
                throw new RuntimeException('Register names are unavailable for the upgrade.');
            }
            Schema::addColumnIfMissing($pdo, 'registers', 'name', 'VARCHAR(100) NULL');
        }
        if ($hasLegacyName) {
            $pdo->exec('UPDATE registers SET name = register_name WHERE name IS NULL');
            if ((int)$pdo->query("SELECT COUNT(*) FROM registers WHERE name IS NULL OR TRIM(name) = ''")->fetchColumn() > 0) {
                throw new RuntimeException('Every Register needs a name before the upgrade can finish.');
            }
            $pdo->exec('ALTER TABLE registers MODIFY COLUMN name VARCHAR(100) NOT NULL');
        }

        $hasLegacyAvailability = Schema::columnExists($pdo, 'registers', 'is_enabled');
        if (!Schema::columnExists($pdo, 'registers', 'status')) {
            if (!$hasLegacyAvailability) {
                throw new RuntimeException('Register availability is unavailable for the upgrade.');
            }
            Schema::addColumnIfMissing($pdo, 'registers', 'status', "ENUM('active','disabled') NULL");
        }
        if ($hasLegacyAvailability) {
            $pdo->exec("UPDATE registers SET status = IF(is_enabled = 1, 'active', 'disabled') WHERE status IS NULL");
            $pdo->exec("ALTER TABLE registers MODIFY COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active'");
        }

        Schema::addColumnIfMissing($pdo, 'registers', 'disabled_at', 'DATETIME NULL');

        // Preserve the legacy values, but allow the current Register service
        // to create rows without supplying columns it no longer uses.
        if (Schema::columnExists($pdo, 'registers', 'register_code')) {
            $pdo->exec('ALTER TABLE registers MODIFY COLUMN register_code VARCHAR(30) NULL');
        }
        if ($hasLegacyName) {
            $pdo->exec('ALTER TABLE registers MODIFY COLUMN register_name VARCHAR(100) NULL');
        }

        if (!Schema::indexExists($pdo, 'registers', 'register_name')) {
            Schema::addUniqueKeyIfMissing($pdo, 'registers', 'register_name_current', '`name`');
        }
        Schema::addIndexIfMissing($pdo, 'registers', 'idx_registers_status', '`status`');
    },
];
