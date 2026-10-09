CREATE TABLE `demand_forecasts` (
    `forecast_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `job_id` BIGINT UNSIGNED NOT NULL,
    `product_id` INT NOT NULL,
    `forecast_origin` DATE NOT NULL,
    `target_date` DATE NOT NULL,
    `horizon_day` INT NOT NULL COMMENT '1 to 28',
    `predicted_units` INT NOT NULL,
    `model_version` VARCHAR(50) NOT NULL,
    `readiness_level` VARCHAR(20) NOT NULL COMMENT 'Insufficient, Low, Medium, High',
    `limitation_marker` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_demand_forecasts_product_origin` (`product_id`, `forecast_origin`),
    INDEX `idx_demand_forecasts_job` (`job_id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE CASCADE,
    FOREIGN KEY (`job_id`) REFERENCES `ml_jobs`(`job_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
