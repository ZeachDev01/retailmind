CREATE TABLE `ml_model_evaluations` (
    `evaluation_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `model_version` VARCHAR(50) NOT NULL,
    `horizon_days` INT NOT NULL COMMENT '7, 14, or 28',
    `mae` DECIMAL(10,4) NULL,
    `rmse` DECIMAL(10,4) NULL,
    `wape` DECIMAL(10,4) NULL,
    `baseline_mae` DECIMAL(10,4) NULL,
    `baseline_rmse` DECIMAL(10,4) NULL,
    `baseline_wape` DECIMAL(10,4) NULL,
    `missing_metric_reason` VARCHAR(255) NULL,
    `data_window_start` DATE NOT NULL,
    `data_window_end` DATE NOT NULL,
    `candidate_gate_outcome` VARCHAR(50) NOT NULL COMMENT 'passed, failed, incomplete',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ml_model_evaluations_model_version` (`model_version`),
    INDEX `idx_ml_model_evaluations_horizon` (`horizon_days`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Platform Settings for thresholds
-- We need to ensure platform settings exist or use a new table for it.
-- Based on the spec: "Record experiment thresholds and quality rules; only Super Administrator can configure approved error/quality thresholds as Platform Settings."
-- Let's check if there is a platform_settings table.
