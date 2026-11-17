<?php

use App\Database\Schema;

return [
    'key' => '202611180001_receiving_allocations',
    'description' => 'Preserve receiving allocation modes, cost provenance and current-cost choices',
    'up' => static function (PDO $pdo): void {
        $columns = [
            'quantity_mode' => 'VARCHAR(20) NULL',
            'cost_source' => 'VARCHAR(20) NULL',
            'current_product_cost' => 'DECIMAL(10,2) NULL',
        ];
        foreach ($columns as $column => $definition) {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $existing = $pdo->query('PRAGMA table_info(stock_receiving)')->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array($column, $existing, true)) $pdo->exec("ALTER TABLE stock_receiving ADD COLUMN {$column} {$definition}");
            } else {
                Schema::addColumnIfMissing($pdo, 'stock_receiving', $column, $definition);
            }
        }
    },
];
