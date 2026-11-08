<?php

use App\Database\Schema;

return [
    'key' => '202611080002_store_staff_shifts',
    'description' => 'Create store_staff_shifts table for staff shift scheduling and availability',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `store_staff_shifts` (
                `shift_id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `shift_date` DATE NOT NULL,
                `start_time` TIME NOT NULL DEFAULT '08:00:00',
                `end_time` TIME NOT NULL DEFAULT '17:00:00',
                `notes` VARCHAR(255) NULL,
                `created_by` INT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_store_staff_shifts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
                CONSTRAINT `fk_store_staff_shifts_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
                INDEX `idx_staff_shifts_date_user` (`shift_date`, `user_id`),
                INDEX `idx_staff_shifts_user_date` (`user_id`, `shift_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
];
