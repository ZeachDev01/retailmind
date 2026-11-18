<?php

use App\Database\Schema;

return [
    'key' => '202611180002_receiving_cost_basis',
    'description' => 'Preserve the entered purchase amount and its receiving cost conversion',
    'up' => static function (PDO $pdo): void {
        $columns = [
            'entered_cost' => 'DECIMAL(10,2) NULL',
            'cost_basis' => 'VARCHAR(20) NULL',
            'cost_units_used' => 'INT NULL',
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
