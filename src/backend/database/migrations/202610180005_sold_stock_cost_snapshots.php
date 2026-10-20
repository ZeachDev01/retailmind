<?php

use App\Database\Schema;

return [
    'key' => '202610180005_sold_stock_cost_snapshots',
    'description' => 'Gross Profit: preserve sold-stock costs during checkout (ticket #228)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('product_batches', $tables, true)) {
                $pbCols = $pdo->query("PRAGMA table_info(product_batches)")->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array('cost_price', $pbCols, true)) {
                    $pdo->exec("ALTER TABLE product_batches ADD COLUMN cost_price NUMERIC NULL");
                }
            }

            if (in_array('sale_item_batches', $tables, true)) {
                $sibCols = $pdo->query("PRAGMA table_info(sale_item_batches)")->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array('unit_cost', $sibCols, true)) {
                    $pdo->exec("ALTER TABLE sale_item_batches ADD COLUMN unit_cost NUMERIC NULL");
                }
                if (!in_array('cost_price', $sibCols, true)) {
                    $pdo->exec("ALTER TABLE sale_item_batches ADD COLUMN cost_price NUMERIC NULL");
                }
                if (!in_array('cost_status', $sibCols, true)) {
                    $pdo->exec("ALTER TABLE sale_item_batches ADD COLUMN cost_status TEXT NOT NULL DEFAULT 'missing'");
                }
                if (!in_array('cost_source', $sibCols, true)) {
                    $pdo->exec("ALTER TABLE sale_item_batches ADD COLUMN cost_source TEXT NULL");
                }
                if (!in_array('cost_provenance', $sibCols, true)) {
                    $pdo->exec("ALTER TABLE sale_item_batches ADD COLUMN cost_provenance TEXT NULL");
                }
            }
            return;
        }

        if (Schema::tableExists($pdo, 'product_batches')) {
            Schema::addColumnIfMissing($pdo, 'product_batches', 'cost_price', 'DECIMAL(10,2) NULL AFTER `expiration_date`');
        }

        if (Schema::tableExists($pdo, 'sale_item_batches')) {
            Schema::addColumnIfMissing($pdo, 'sale_item_batches', 'unit_cost', 'DECIMAL(10,2) NULL AFTER `quantity`');
            Schema::addColumnIfMissing($pdo, 'sale_item_batches', 'cost_price', 'DECIMAL(10,2) NULL AFTER `unit_cost`');
            Schema::addColumnIfMissing($pdo, 'sale_item_batches', 'cost_status', "ENUM('recorded','estimated','missing') NOT NULL DEFAULT 'missing' AFTER `cost_price`");
            Schema::addColumnIfMissing($pdo, 'sale_item_batches', 'cost_source', 'VARCHAR(50) NULL AFTER `cost_status`');
            Schema::addColumnIfMissing($pdo, 'sale_item_batches', 'cost_provenance', 'VARCHAR(50) NULL AFTER `cost_source`');
        }
    },
];
