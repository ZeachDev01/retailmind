<?php

use App\Database\Schema;

return [
    'key' => '202610100001_scheduled_demand_drivers',
    'description' => 'Record and track price and promotion schedules over time',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `scheduled_demand_drivers` (
                `driver_id` INT AUTO_INCREMENT PRIMARY KEY,
                `product_id` INT NOT NULL,
                `driver_type` VARCHAR(50) NOT NULL,
                `effective_date` DATE NOT NULL,
                `scheduled_at` TIMESTAMP NOT NULL,
                `details_json` JSON NOT NULL,
                CONSTRAINT `fk_scheduled_demand_drivers_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
                INDEX `idx_scheduled_demand_drivers_history` (`product_id`, `effective_date`, `scheduled_at` DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
];
