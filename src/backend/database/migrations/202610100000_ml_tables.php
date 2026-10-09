<?php

return [
    'key' => '202610100000_ml_tables',
    'description' => 'Create ML jobs, evaluation, and demand forecasts tables',
    'up' => static function (PDO $pdo): void {
        $sqlFiles = [
            __DIR__ . '/../../sql/migrations/004_ml_jobs.sql',
            __DIR__ . '/../../sql/migrations/005_ml_evaluation.sql',
            __DIR__ . '/../../sql/migrations/006_demand_forecasts.sql'
        ];
        
        foreach ($sqlFiles as $file) {
            if (is_file($file)) {
                $sql = file_get_contents($file);
                $sql = str_ireplace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql);
                $pdo->exec($sql);
            }
        }
        
        // Also create ml_settings if not exists, since it's used
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ml_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
];
