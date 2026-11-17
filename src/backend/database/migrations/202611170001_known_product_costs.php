<?php

use App\Database\Schema;

return [
    'key' => '202611170001_known_product_costs',
    'description' => 'Distinguish future intentional zero costs from missing and legacy costs',
    'up' => static function (PDO $pdo): void {
        foreach (['products' => 'INTEGER NOT NULL DEFAULT 0', 'product_batches' => 'INTEGER NULL DEFAULT NULL'] as $table => $definition) {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $columns = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array('cost_known', $columns, true)) $pdo->exec("ALTER TABLE {$table} ADD COLUMN cost_known {$definition}");
            } else {
                Schema::addColumnIfMissing($pdo, $table, 'cost_known', str_replace('INTEGER', 'TINYINT(1)', $definition));
            }
        }
    },
];
