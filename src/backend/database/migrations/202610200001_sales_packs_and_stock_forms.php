<?php

use App\Database\Schema;

return [
    'key' => '202610200001_sales_packs_and_stock_forms',
    'description' => 'Support Sales Pack configuration and batch-level sealed and loose stock forms (issue #246)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $productCols = $pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('pack_size', $productCols, true)) {
                $pdo->exec("ALTER TABLE products ADD COLUMN pack_size INTEGER NULL");
            }
            if (!in_array('pack_barcode', $productCols, true)) {
                $pdo->exec("ALTER TABLE products ADD COLUMN pack_barcode TEXT NULL");
            }
            if (!in_array('pack_price', $productCols, true)) {
                $pdo->exec("ALTER TABLE products ADD COLUMN pack_price REAL NULL");
            }

            $batchCols = $pdo->query("PRAGMA table_info(product_batches)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('sealed_quantity', $batchCols, true)) {
                $pdo->exec("ALTER TABLE product_batches ADD COLUMN sealed_quantity INTEGER NOT NULL DEFAULT 0");
            }
            if (!in_array('loose_quantity', $batchCols, true)) {
                $pdo->exec("ALTER TABLE product_batches ADD COLUMN loose_quantity INTEGER NOT NULL DEFAULT 0");
            }

            $pdo->exec("UPDATE product_batches SET loose_quantity = remaining_quantity, sealed_quantity = 0 WHERE loose_quantity = 0 AND sealed_quantity = 0 AND remaining_quantity > 0");
            return;
        }

        Schema::addColumnIfMissing(
            $pdo,
            'products',
            'pack_size',
            'INT NULL DEFAULT NULL AFTER `case_barcode`'
        );
        Schema::addColumnIfMissing(
            $pdo,
            'products',
            'pack_barcode',
            'VARCHAR(80) NULL DEFAULT NULL AFTER `pack_size`'
        );
        Schema::addColumnIfMissing(
            $pdo,
            'products',
            'pack_price',
            'DECIMAL(10,2) NULL DEFAULT NULL AFTER `pack_barcode`'
        );
        Schema::addUniqueKeyIfMissing(
            $pdo,
            'products',
            'uq_products_branch_pack_barcode',
            '`branch_id`, `pack_barcode`'
        );

        Schema::addColumnIfMissing(
            $pdo,
            'product_batches',
            'sealed_quantity',
            'INT NOT NULL DEFAULT 0 AFTER `remaining_quantity`'
        );
        Schema::addColumnIfMissing(
            $pdo,
            'product_batches',
            'loose_quantity',
            'INT NOT NULL DEFAULT 0 AFTER `sealed_quantity`'
        );

        $pdo->exec("UPDATE product_batches SET loose_quantity = remaining_quantity, sealed_quantity = 0 WHERE loose_quantity = 0 AND sealed_quantity = 0 AND remaining_quantity > 0");
    },
];
