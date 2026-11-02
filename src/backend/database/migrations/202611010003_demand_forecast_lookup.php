<?php

use App\Database\Schema;

return [
    'key' => '202611010003_demand_forecast_lookup',
    'description' => 'Index forecast job and product lookups',
    'up' => static function (PDO $pdo): void {
        Schema::addIndexIfMissing($pdo, 'ml_jobs', 'idx_ml_jobs_type_status_completed', '`trigger_type`, `status`, `completed_at`, `job_id`');
        Schema::addIndexIfMissing($pdo, 'demand_forecasts', 'idx_demand_forecasts_job_product_horizon', '`job_id`, `product_id`, `horizon_day`');
    },
];
