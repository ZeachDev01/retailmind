<?php

use App\Database\Schema;

return [
    'key' => '202610100002_forecasting_schedule_defaults',
    'description' => 'Add default forecasting schedules',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("INSERT IGNORE INTO ml_settings (setting_key, setting_value) VALUES ('schedule_daily_forecast_time', '23:00')");
        $pdo->exec("INSERT IGNORE INTO ml_settings (setting_key, setting_value) VALUES ('schedule_weekly_retrain_day', 'Sunday')");
        $pdo->exec("INSERT IGNORE INTO ml_settings (setting_key, setting_value) VALUES ('schedule_weekly_retrain_time', '01:00')");
    }
];
