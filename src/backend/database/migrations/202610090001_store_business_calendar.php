<?php

use App\Database\Schema;

return [
    'key' => '202610090001_store_business_calendar',
    'description' => 'Record Store open days, holidays, and exceptional closures',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `store_calendar` (
                `calendar_id` INT AUTO_INCREMENT PRIMARY KEY,
                
                `calendar_date` DATE NOT NULL,
                `status` ENUM('open', 'closed', 'holiday', 'exceptional-closure') NOT NULL,
                `recorded_by` INT DEFAULT NULL,
                `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                
                CONSTRAINT `fk_store_calendar_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
                INDEX `idx_store_calendar_history` (`calendar_date`, `recorded_at` DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
];

