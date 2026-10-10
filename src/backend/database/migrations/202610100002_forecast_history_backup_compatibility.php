<?php

return [
    'key' => '202610100002_forecast_history_backup_compatibility',
    'description' => 'Keep imported forecast history compatible with SQL database backups',
    'up' => static function (PDO $pdo): void {
        $pdo->exec('DROP VIEW IF EXISTS forecast_daily_sales');
    },
];
