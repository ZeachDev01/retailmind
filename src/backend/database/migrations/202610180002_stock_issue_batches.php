<?php

return [
    'key' => '202610180002_stock_issue_batches',
    'description' => 'Track affected batches deducted during stock issue approval',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS stock_issue_batches (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    adjustment_id INTEGER NOT NULL,
                    batch_id INTEGER NOT NULL,
                    quantity INTEGER NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (adjustment_id) REFERENCES inventory_adjustments (adjustment_id) ON DELETE CASCADE,
                    FOREIGN KEY (batch_id) REFERENCES product_batches (batch_id)
                )'
            );
            $pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_stock_issue_batches_adjustment ON stock_issue_batches (adjustment_id)'
            );
            $pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_stock_issue_batches_batch ON stock_issue_batches (batch_id)'
            );
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `stock_issue_batches` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `adjustment_id` int(11) NOT NULL,
                `batch_id` int(11) NOT NULL,
                `quantity` int(11) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_stock_issue_batches_adjustment` (`adjustment_id`),
                KEY `idx_stock_issue_batches_batch` (`batch_id`),
                CONSTRAINT `fk_stock_issue_batches_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE CASCADE,
                CONSTRAINT `fk_stock_issue_batches_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`batch_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
];
