CREATE TABLE ml_jobs (
    job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trigger_type VARCHAR(50) NOT NULL COMMENT 'e.g. forecast_generation, model_retraining',
    status VARCHAR(20) NOT NULL DEFAULT 'queued' COMMENT 'queued, running, succeeded, failed',
    requester_id INT NULL COMMENT 'User ID who requested it, NULL if scheduled',
    queued_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    lease_expires_at DATETIME NULL,
    attempts INT NOT NULL DEFAULT 0,
    operator_message VARCHAR(255) NULL COMMENT 'Safe user-facing message',
    diagnostic_reference TEXT NULL COMMENT 'Technical failure details',
    INDEX (status, queued_at),
    FOREIGN KEY (requester_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
