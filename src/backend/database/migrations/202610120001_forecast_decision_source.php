<?php

use App\Database\Schema;

return [
    'key' => '202610120001_forecast_decision_source',
    'description' => 'Retain demand forecast job/product sources alongside legacy prediction history',
    'up' => static function (PDO $pdo): void {
        // Legacy prediction IDs stay intact; new decisions refer to ML jobs instead.
        $pdo->exec('ALTER TABLE forecast_decisions MODIFY prediction_id INT NULL');
        foreach (['forecast_decisions', 'replenishment_requests'] as $table) {
            Schema::addColumnIfMissing($pdo, $table, 'forecast_job_id', 'BIGINT UNSIGNED NULL');
            Schema::addForeignKeyIfMissing($pdo, $table, 'fk_' . $table . '_forecast_job',
                'forecast_job_id', 'ml_jobs', 'job_id', 'RESTRICT');
        }
    },
];
