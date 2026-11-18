<?php

use App\Database\Schema;

return [
    'key' => '202611180004_product_declared_size',
    'description' => 'Store the size printed on the Individual Item separately from operational units',
    'up' => static function (PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('declared_size', $columns, true)) $pdo->exec('ALTER TABLE products ADD COLUMN declared_size VARCHAR(100) DEFAULT NULL');
        } else {
            Schema::addColumnIfMissing($pdo, 'products', 'declared_size', 'VARCHAR(100) DEFAULT NULL');
        }
    },
];
