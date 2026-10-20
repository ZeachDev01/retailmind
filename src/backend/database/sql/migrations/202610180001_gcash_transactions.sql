-- Select the existing application database in phpMyAdmin before importing.
-- SQL equivalent of the PHP migration; existing records are preserved.
CREATE TABLE IF NOT EXISTS gcash_transactions (
    transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_type ENUM('Cash-In','Cash-Out','Payment') NOT NULL,
    reference_number VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    cashier_id INT NOT NULL,
    verification_status ENUM('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
    verified_by INT NULL,
    verification_note VARCHAR(500) NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gcash_reference (reference_number),
    KEY idx_gcash_cashier_history (cashier_id, transaction_id),
    CONSTRAINT fk_gcash_cashier FOREIGN KEY (cashier_id) REFERENCES users(user_id),
    CONSTRAINT fk_gcash_reviewer FOREIGN KEY (verified_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(100) PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO schema_migrations (migration_key, description)
SELECT '202610180001_gcash_transactions',
       'GCash reference records with explicit verification and unique references'
WHERE NOT EXISTS (
    SELECT 1 FROM schema_migrations WHERE migration_key = '202610180001_gcash_transactions'
);
