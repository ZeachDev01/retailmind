<?php

use App\Database\Schema;

return [
    'key' => '202611020001_store_weekly_schedule',
    'description' => 'Configurable weekly store schedule revisions with effective dates',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `store_weekly_schedule` (
                `schedule_id` INT AUTO_INCREMENT PRIMARY KEY,
                `effective_date` DATE NOT NULL,
                `monday_open` TINYINT(1) NOT NULL DEFAULT 1,
                `tuesday_open` TINYINT(1) NOT NULL DEFAULT 1,
                `wednesday_open` TINYINT(1) NOT NULL DEFAULT 1,
                `thursday_open` TINYINT(1) NOT NULL DEFAULT 1,
                `friday_open` TINYINT(1) NOT NULL DEFAULT 1,
                `saturday_open` TINYINT(1) NOT NULL DEFAULT 0,
                `sunday_open` TINYINT(1) NOT NULL DEFAULT 0,
                `recorded_by` INT DEFAULT NULL,
                `source` VARCHAR(50) NOT NULL DEFAULT 'manual',
                `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_store_weekly_schedule_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
                INDEX `idx_store_weekly_schedule_history` (`effective_date`, `recorded_at` DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        Schema::addColumnIfMissing($pdo, 'store_calendar', 'source', "VARCHAR(50) NOT NULL DEFAULT 'manual' AFTER `recorded_by`");
    },
];
