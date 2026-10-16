<?php

use App\Database\Schema;

return [
    'key' => '202608040003_integrity_constraints',
    'description' => 'Foreign-key protections for upgraded operational databases',
    'up' => static function (\PDO $pdo): void {
        $constraints = [
            ['cashier_shifts', 'fk_cashier_shifts_cashier', 'cashier_id', 'users', 'user_id', 'RESTRICT'],
            ['cashier_shifts', 'fk_cashier_shifts_reviewer', 'reviewed_by', 'users', 'user_id', 'SET NULL'],
            ['cash_drawer_movements', 'fk_drawer_shift', 'shift_id', 'cashier_shifts', 'shift_id', 'CASCADE'],
            ['cash_drawer_movements', 'fk_drawer_recorded_by', 'recorded_by', 'users', 'user_id', 'RESTRICT'],
            ['held_sales', 'fk_held_sales_cashier', 'cashier_id', 'users', 'user_id', 'RESTRICT'],
            ['held_sales', 'fk_held_sales_shift', 'shift_id', 'cashier_shifts', 'shift_id', 'SET NULL'],
            ['promotions', 'fk_promotions_product', 'product_id', 'products', 'product_id', 'CASCADE'],
            ['promotions', 'fk_promotions_category', 'category_id', 'categories', 'category_id', 'CASCADE'],
            ['promotions', 'fk_promotions_created_by', 'created_by', 'users', 'user_id', 'RESTRICT'],
            ['forecast_decisions', 'fk_forecast_decisions_prediction', 'prediction_id', 'stock_predictions', 'prediction_id', 'CASCADE'],
            ['forecast_decisions', 'fk_forecast_decisions_product', 'product_id', 'products', 'product_id', 'RESTRICT'],
            ['forecast_decisions', 'fk_forecast_decisions_user', 'decided_by', 'users', 'user_id', 'RESTRICT'],
            ['cycle_count_schedules', 'fk_cycle_product', 'product_id', 'products', 'product_id', 'CASCADE'],
            ['cycle_count_schedules', 'fk_cycle_assigned', 'assigned_to', 'users', 'user_id', 'SET NULL'],
            ['cycle_count_schedules', 'fk_cycle_created_by', 'created_by', 'users', 'user_id', 'RESTRICT'],
        ];

        foreach ($constraints as [$table, $name, $column, $parent, $parentColumn, $onDelete]) {
            Schema::addForeignKeyIfMissing($pdo, $table, $name, $column, $parent, $parentColumn, $onDelete);
        }
    },
];
