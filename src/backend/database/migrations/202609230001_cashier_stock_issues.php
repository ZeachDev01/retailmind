<?php

use App\Database\Schema;

return [
    'key' => '202609230001_cashier_stock_issues',
    'description' => 'Link cashier stock-issue reports to shifts and stock movements (ticket #29)',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'inventory_adjustments', 'shift_id', 'INT NULL AFTER `reported_by`');
        Schema::addColumnIfMissing($pdo, 'inventory_adjustments', 'review_notes', 'TEXT NULL AFTER `reason`');
        Schema::addColumnIfMissing($pdo, 'stock_movements', 'adjustment_id', 'INT NULL AFTER `moved_by`');

        Schema::addIndexIfMissing($pdo, 'inventory_adjustments', 'idx_inventory_adjustments_shift', '`shift_id`');
        Schema::addIndexIfMissing($pdo, 'inventory_adjustments', 'idx_inventory_adjustments_status', '`status`');
        Schema::addIndexIfMissing($pdo, 'stock_movements', 'idx_stock_movements_adjustment', '`adjustment_id`');

        Schema::addForeignKeyIfMissing(
            $pdo,
            'inventory_adjustments',
            'fk_inventory_adjustments_shift',
            'shift_id',
            'cashier_shifts',
            'shift_id',
            'SET NULL'
        );
        Schema::addForeignKeyIfMissing(
            $pdo,
            'stock_movements',
            'fk_stock_movements_adjustment',
            'adjustment_id',
            'inventory_adjustments',
            'adjustment_id',
            'SET NULL'
        );
    },
];
