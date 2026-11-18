<?php

use App\Database\Schema;

return [
    'key' => '202611180005_product_image_source',
    'description' => 'Keep attribution for saved Open Food Facts Product photos',
    'up' => static function (PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('product_image_source', $columns, true)) $pdo->exec('ALTER TABLE products ADD COLUMN product_image_source VARCHAR(255) DEFAULT NULL');
        } else {
            Schema::addColumnIfMissing($pdo, 'products', 'product_image_source', 'VARCHAR(255) DEFAULT NULL');
        }
    },
];
