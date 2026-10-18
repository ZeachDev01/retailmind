<?php

use App\Database\Schema;

return [
    'key' => '202610180001_inventory_count_batches',
    'description' => 'Track batch-level counted quantities, shortages, extras, and undated reconciliation items',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS inventory_count_batches (
                    count_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    count_id INTEGER NOT NULL,
                    batch_id INTEGER NULL,
                    system_quantity INTEGER NOT NULL DEFAULT 0,
                    physical_quantity INTEGER NOT NULL DEFAULT 0,
                    difference_qty INTEGER NOT NULL DEFAULT 0,
                    expiration_date TEXT NULL,
                    batch_number TEXT NULL,
                    notes TEXT NULL,
                    FOREIGN KEY (count_id) REFERENCES inventory_counts (count_id) ON DELETE CASCADE,
                    FOREIGN KEY (batch_id) REFERENCES product_batches (batch_id) ON DELETE SET NULL
                )'
            );
            $pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_inventory_count_batches_count_id ON inventory_count_batches (count_id)'
            );
            $pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_inventory_count_batches_batch_id ON inventory_count_batches (batch_id)'
            );
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `inventory_count_batches` (
                `count_batch_id` int(11) NOT NULL AUTO_INCREMENT,
                `count_id` int(11) NOT NULL,
                `batch_id` int(11) DEFAULT NULL,
                `system_quantity` int(11) NOT NULL DEFAULT 0,
                `physical_quantity` int(11) NOT NULL DEFAULT 0,
                `difference_qty` int(11) NOT NULL DEFAULT 0,
                `expiration_date` date DEFAULT NULL,
                `batch_number` varchar(100) DEFAULT NULL,
                `notes` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`count_batch_id`),
                KEY `idx_inventory_count_batches_count_id` (`count_id`),
                KEY `idx_inventory_count_batches_batch_id` (`batch_id`),
                CONSTRAINT `fk_inventory_count_batches_count` FOREIGN KEY (`count_id`) REFERENCES `inventory_counts` (`count_id`) ON DELETE CASCADE,
                CONSTRAINT `fk_inventory_count_batches_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`batch_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
];
