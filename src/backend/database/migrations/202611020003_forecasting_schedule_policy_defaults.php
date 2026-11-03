<?php

return [
    'key' => '202611020003_forecasting_schedule_policy_defaults',
    'description' => 'Correct conflicting 23:00 forecasting schedule default to accepted 01:00 policy',
    'up' => static function (PDO $pdo): void {
        try {
            $pdo->exec("UPDATE ml_settings SET setting_value = '01:00' WHERE setting_key = 'schedule_daily_forecast_time' AND setting_value = '23:00' AND updated_by IS NULL");
        } catch (\Throwable) {}

        try {
            $pdo->exec("INSERT IGNORE INTO ml_settings (setting_key, setting_value) VALUES ('schedule_daily_forecast_time', '01:00')");
        } catch (\Throwable) {}
    }
];
