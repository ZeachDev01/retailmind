<?php

use App\Database\Schema;

return [
    'key' => '202610250001_retire_unused_storage',
    'description' => 'Remove retired tables, unused columns, and Emergency Access setting',
    'up' => static function (PDO $pdo): void {
        // Capabilities live in RoleCapabilityPolicy; restore evidence lives in RecoveryStore.
        foreach (['user_privileges', 'role_privileges', 'privileges', 'restore_preserved_activity', 'emergency_access_sessions'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        foreach ([
            'csv_import_logs' => ['error_details'],
            'forecast_evaluations' => ['evaluation_records', 'actual_total', 'predicted_total'],
            'model_training_runs' => ['host_name'],
            'sale_reversals' => ['exchange_details'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::columnExists($pdo, $table, $column)) {
                    $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
                }
            }
        }
        $pdo->exec("DELETE FROM platform_settings WHERE setting_key = 'emergency_access_duration_minutes'");
    },
];
