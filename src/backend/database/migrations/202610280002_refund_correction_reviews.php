<?php

use App\Database\Schema;

return [
    'key' => '202610280002_refund_correction_reviews',
    'description' => 'Add refund correction review workflow tables and columns (ticket #343)',
    'up' => static function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `refund_correction_reviews` (
                `review_id` INT AUTO_INCREMENT PRIMARY KEY,
                `refund_id` INT NOT NULL,
                `correction_type` VARCHAR(50) NOT NULL,
                `status` VARCHAR(50) NOT NULL,
                `cash_status` VARCHAR(50) NOT NULL,
                `stock_status` VARCHAR(50) NOT NULL,
                `explanation` TEXT NOT NULL,
                `proposed_correction` TEXT NULL,
                `reviewed_by` INT NOT NULL,
                `reviewed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `responsible_cashier_id` INT NULL,
                `responsible_inventory_manager_id` INT NULL,
                `cash_drawer_movement_id` INT NULL,
                `inventory_adjustment_id` INT NULL,
                `completed_at` TIMESTAMP NULL DEFAULT NULL,
                INDEX `idx_rcr_refund` (`refund_id`),
                INDEX `idx_rcr_status` (`status`),
                INDEX `idx_rcr_cashier` (`responsible_cashier_id`),
                INDEX `idx_rcr_inventory` (`responsible_inventory_manager_id`),
                CONSTRAINT `fk_rcr_refund` FOREIGN KEY (`refund_id`) REFERENCES `cash_refunds` (`refund_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_rcr_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_rcr_cashier` FOREIGN KEY (`responsible_cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_rcr_inventory` FOREIGN KEY (`responsible_inventory_manager_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    },
];
