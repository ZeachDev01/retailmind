<?php

use App\Database\Schema;

return [
    'key' => '202609240001_stock_issue_oversight_correction_link',
    'description' => 'Link correction inventory counts to approved stock-issue reports for oversight history (ticket #31)',
    'up' => static function (PDO $pdo): void {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $columns = [];
            try {
                foreach ($pdo->query('PRAGMA table_info(inventory_counts)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $columns[strtolower((string)$column['name'])] = true;
                }
            } catch (Throwable $e) {
                $columns = [];
            }
            if (!isset($columns['related_adjustment_id'])) {
                $pdo->exec('ALTER TABLE inventory_counts ADD COLUMN related_adjustment_id INTEGER NULL');
            }
            return;
        }

        Schema::addColumnIfMissing(
            $pdo,
            'inventory_counts',
            'related_adjustment_id',
            'INT NULL AFTER `status`'
        );
        Schema::addIndexIfMissing($pdo, 'inventory_counts', 'idx_inventory_counts_related_adjustment', '`related_adjustment_id`');
        Schema::addForeignKeyIfMissing(
            $pdo,
            'inventory_counts',
            'fk_inventory_counts_related_adjustment',
            'related_adjustment_id',
            'inventory_adjustments',
            'adjustment_id',
            'SET NULL'
        );
    },
];
