<?php

use App\Database\Schema;

return [
    'key' => '202610180004_supplier_pullouts',
    'description' => 'Record batch-specific Supplier Pullouts under Inventory Control (ticket #224)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS supplier_pullouts (
                    pullout_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    product_id INTEGER NOT NULL,
                    batch_id INTEGER NOT NULL,
                    quantity INTEGER NOT NULL,
                    reason TEXT NOT NULL,
                    supplier TEXT NULL,
                    pulled_by INTEGER NOT NULL,
                    pulled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    notes TEXT NULL,
                    FOREIGN KEY (product_id) REFERENCES products (product_id),
                    FOREIGN KEY (batch_id) REFERENCES product_batches (batch_id),
                    FOREIGN KEY (pulled_by) REFERENCES users (user_id)
                )'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_supplier_pullouts_product ON supplier_pullouts (product_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_supplier_pullouts_batch ON supplier_pullouts (batch_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_supplier_pullouts_pulled_by ON supplier_pullouts (pulled_by)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_supplier_pullouts_pulled_at ON supplier_pullouts (pulled_at)');

            $smCols = $pdo->query("PRAGMA table_info(stock_movements)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('pullout_id', $smCols, true)) {
                $pdo->exec('ALTER TABLE stock_movements ADD COLUMN pullout_id INTEGER NULL');
            }

            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('stock_receiving', $tables, true)) {
                $srCols = $pdo->query("PRAGMA table_info(stock_receiving)")->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array('pullout_id', $srCols, true)) {
                    $pdo->exec('ALTER TABLE stock_receiving ADD COLUMN pullout_id INTEGER NULL');
                }
            }
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `supplier_pullouts` (
                `pullout_id` int(11) NOT NULL AUTO_INCREMENT,
                `product_id` int(11) NOT NULL,
                `batch_id` int(11) NOT NULL,
                `quantity` int(11) NOT NULL,
                `reason` varchar(255) NOT NULL,
                `supplier` varchar(150) DEFAULT NULL,
                `pulled_by` int(11) NOT NULL,
                `pulled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `notes` text DEFAULT NULL,
                PRIMARY KEY (`pullout_id`),
                KEY `idx_supplier_pullouts_product` (`product_id`),
                KEY `idx_supplier_pullouts_batch` (`batch_id`),
                KEY `idx_supplier_pullouts_pulled_by` (`pulled_by`),
                KEY `idx_supplier_pullouts_pulled_at` (`pulled_at`),
                CONSTRAINT `fk_supplier_pullouts_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
                CONSTRAINT `fk_supplier_pullouts_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`batch_id`),
                CONSTRAINT `fk_supplier_pullouts_user` FOREIGN KEY (`pulled_by`) REFERENCES `users` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "ALTER TABLE `stock_movements` MODIFY COLUMN `reason` ENUM('purchase','sale','adjustment','return','supplier_pullout') NOT NULL"
        );

        Schema::addColumnIfMissing($pdo, 'stock_movements', 'pullout_id', 'INT NULL AFTER `adjustment_id`');
        Schema::addIndexIfMissing($pdo, 'stock_movements', 'idx_stock_movements_pullout', '`pullout_id`');
        Schema::addForeignKeyIfMissing(
            $pdo,
            'stock_movements',
            'fk_stock_movements_pullout',
            'pullout_id',
            'supplier_pullouts',
            'pullout_id',
            'SET NULL'
        );

        if (Schema::tableExists($pdo, 'stock_receiving')) {
            Schema::addColumnIfMissing($pdo, 'stock_receiving', 'pullout_id', 'INT NULL AFTER `group_id`');
            Schema::addIndexIfMissing($pdo, 'stock_receiving', 'idx_stock_receiving_pullout_id', '`pullout_id`');
            Schema::addForeignKeyIfMissing(
                $pdo,
                'stock_receiving',
                'fk_stock_receiving_pullout',
                'pullout_id',
                'supplier_pullouts',
                'pullout_id',
                'SET NULL'
            );
        }
    },
];
