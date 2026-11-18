<?php

use App\Database\Schema;

return [
    'key' => '202611180003_product_sku_provenance',
    'description' => 'Record whether a Product SKU follows its Individual Item barcode',
    'up' => static function (PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('sku_follows_barcode', $columns, true)) {
                $pdo->exec('ALTER TABLE products ADD COLUMN sku_follows_barcode INTEGER NULL');
            }
            return;
        }
        Schema::addColumnIfMissing($pdo, 'products', 'sku_follows_barcode', 'TINYINT(1) NULL DEFAULT NULL');
    },
];
