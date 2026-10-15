<?php

use App\Database\Schema;

return [
    'key' => '202610150003_retire_procurement_storage',
    'description' => 'Retire development procurement storage; preserve receiving and operational records',
    'up' => static function (PDO $pdo): void {
        // Constraint names vary between fresh imports and older development databases.
        foreach ([
            'stock_receiving' => ['replenishment_request_id', 'purchase_order_id', 'purchase_order_item_id', 'po_number'],
            'forecast_decisions' => ['replenishment_request_id'],
            'notification_preferences' => ['notify_replenishment'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (!Schema::columnExists($pdo, $table, $column)) continue;
                $keys = $pdo->prepare('SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL');
                $keys->execute([$table, $column]);
                foreach ($keys->fetchAll(PDO::FETCH_COLUMN) as $key) {
                    $key = str_replace('`', '``', $key);
                    $pdo->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$key}`");
                }
                $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }
        }
        // Children first; foreign-key checks remain enabled throughout cleanup.
        foreach (['purchase_order_items', 'purchase_orders', 'replenishment_requests', 'supplier_products', 'suppliers'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
    },
];
