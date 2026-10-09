-- Upgrade an existing RetailMind installation to Random Forest v2.
-- Run once in phpMyAdmin after making a database backup.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS failed_login_attempts INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS session_version INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL;

ALTER TABLE forecast_runs MODIFY COLUMN evaluation_result LONGTEXT NULL;
ALTER TABLE stock_predictions MODIFY COLUMN evaluation_result LONGTEXT NULL;

ALTER TABLE stock_predictions
    ADD COLUMN IF NOT EXISTS lower_bound_7_days INT NULL,
    ADD COLUMN IF NOT EXISTS upper_bound_7_days INT NULL,
    ADD COLUMN IF NOT EXISTS lower_bound_30_days INT NULL,
    ADD COLUMN IF NOT EXISTS upper_bound_30_days INT NULL,
    ADD COLUMN IF NOT EXISTS lead_time_lower_bound INT NULL,
    ADD COLUMN IF NOT EXISTS lead_time_upper_bound INT NULL,
    ADD COLUMN IF NOT EXISTS forecast_explanation TEXT NULL;

ALTER TABLE barcode_pairings
    ADD COLUMN IF NOT EXISTS access_token_hash VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS joined_ip VARCHAR(45) NULL;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_identity (username, ip_address, attempted_at),
    INDEX idx_login_attempts_time (attempted_at)
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    reset_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_tokens_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS store_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS email_delivery_log (
    email_log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('sent','failed','queued') NOT NULL,
    provider VARCHAR(50) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_delivery_log_created_at (created_at),
    INDEX idx_email_delivery_log_status (status)
);

CREATE TABLE IF NOT EXISTS backup_history (
    backup_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    backup_type ENUM('manual','scheduled','restore') NOT NULL DEFAULT 'manual',
    file_size BIGINT NULL,
    status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
    performed_by INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (performed_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS ml_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS model_training_runs (
    training_run_id INT AUTO_INCREMENT PRIMARY KEY,
    model_name VARCHAR(100) NOT NULL,
    model_version VARCHAR(30) NOT NULL,
    trigger_type ENUM('manual','scheduled','automatic','cli') NOT NULL DEFAULT 'cli',
    status ENUM('running','completed','failed','skipped') NOT NULL DEFAULT 'running',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    duration_seconds DECIMAL(10,2) NULL,
    sales_records_used INT DEFAULT 0,
    eligible_products INT DEFAULT 0,
    metrics_json LONGTEXT NULL,
    error_message TEXT NULL,
    host_name VARCHAR(150) NULL,
    INDEX idx_model_training_runs_started_at (started_at),
    INDEX idx_model_training_runs_status (status)
);

CREATE TABLE IF NOT EXISTS forecast_evaluations (
    evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
    training_run_id INT NULL,
    product_id INT NOT NULL,
    evaluation_records INT NOT NULL DEFAULT 0,
    actual_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    predicted_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    mae DECIMAL(14,4) NULL,
    rmse DECIMAL(14,4) NULL,
    wape DECIMAL(10,4) NULL,
    smape DECIMAL(10,4) NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_forecast_evaluations_product (product_id, generated_at),
    FOREIGN KEY (training_run_id) REFERENCES model_training_runs(training_run_id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE
);

INSERT INTO store_settings (setting_key, setting_value) VALUES
('store_name', 'Shalom Store'), ('store_address', 'Tangub City'),
('store_phone', ''), ('store_email', ''), ('business_identifier', ''),
('receipt_footer', 'Thank you for shopping with us.'), ('currency_symbol', '₱'),
('timezone', 'Asia/Manila')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO ml_settings (setting_key, setting_value) VALUES
('minimum_history_days', '30'), ('preferred_history_days', '90'),
('minimum_nonzero_sales_days', '5'), ('history_window_days', '365'),
('forecast_period_days', '30'), ('n_estimators', '300'), ('max_depth', '18'),
('min_samples_split', '4'), ('min_samples_leaf', '2'),
('retrain_frequency_days', '7'), ('retrain_new_sales_records', '100'),
('accuracy_threshold_wape', '35'), ('prediction_interval_lower', '10'),
('prediction_interval_upper', '90'), ('holiday_dates', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
