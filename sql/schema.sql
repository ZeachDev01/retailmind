-- ============================================================
-- Inventory Management System - Database Schema
-- Roles: super_admin, admin, inventory_manager, cashier
-- ============================================================

-- Import this file after selecting your assigned database in phpMyAdmin.
-- Shared hosts usually do not allow CREATE DATABASE or USE statements for
-- arbitrary database names, so this schema creates tables in the active DB.

-- ---------- Users & Roles ----------
CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE   -- super_admin | admin | inventory_manager | cashier
);

INSERT INTO roles (role_name) VALUES
('super_admin'), ('admin'), ('inventory_manager'), ('cashier');

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    status ENUM('active','disabled') DEFAULT 'active',
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    session_version INT NOT NULL DEFAULT 1,
    password_changed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
);

INSERT INTO users (full_name, username, email, password_hash, role_id, status)
VALUES (
    'Super Admin',
    'superadmin',
    'superadmin@inventory.local',
    '$2y$12$0tQ7Weeiqjbq4m1abSwUGeq//mTiOHJkMyKeTZCZVtJ8jHufT1EFC',
    (SELECT role_id FROM roles WHERE role_name = 'super_admin'),
    'active'
);

-- ---------- Products & Inventory ----------
CREATE TABLE categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL
);

CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    barcode VARCHAR(50) NOT NULL UNIQUE,
    product_name VARCHAR(150) NOT NULL,
    brand VARCHAR(100),
    category_id INT,
    unit_price DECIMAL(10,2) NOT NULL,
    cost_price DECIMAL(10,2) NOT NULL,
    quantity_purchased INT NOT NULL DEFAULT 0,
    quantity_sold INT NOT NULL DEFAULT 0,
    reorder_level INT DEFAULT 10,
    supplier VARCHAR(150),
    preferred_supplier VARCHAR(150),
    supplier_lead_time_days INT NOT NULL DEFAULT 7,
    safety_stock INT NOT NULL DEFAULT 0,
    minimum_order_quantity INT NOT NULL DEFAULT 1,
    units_per_package INT NOT NULL DEFAULT 1,
    expiration_date DATE NULL,
    product_image VARCHAR(255),
    status ENUM('active','inactive') DEFAULT 'active',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CHECK (unit_price >= 0),
    CHECK (cost_price >= 0),
    INDEX idx_products_product_name (product_name),
    INDEX idx_products_status (status),
    FOREIGN KEY (category_id) REFERENCES categories(category_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id)
);

CREATE TABLE inventory (
    inventory_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity_on_hand INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_product UNIQUE (product_id),
    CHECK (quantity_on_hand >= 0),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

CREATE TABLE stock_movements (
    movement_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    change_qty INT NOT NULL,              -- positive = stock in, negative = stock out
    reason ENUM('purchase','sale','adjustment','return') NOT NULL,
    moved_by INT,
    moved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_movements_product_id (product_id),
    INDEX idx_stock_movements_moved_at (moved_at),
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (moved_by) REFERENCES users(user_id)
);

CREATE TABLE purchase_history (
    purchase_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    supplier VARCHAR(150),
    quantity_purchased INT NOT NULL,
    cost_price DECIMAL(10,2) NOT NULL,
    purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_cost DECIMAL(10,2) NOT NULL,
    recorded_by INT,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (recorded_by) REFERENCES users(user_id)
);

CREATE TABLE product_batches (
    batch_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    receiving_id INT NULL,
    batch_number VARCHAR(100) NULL,
    quantity INT NOT NULL,
    remaining_quantity INT NOT NULL,
    expiration_date DATE NULL,
    date_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    supplier VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_batches_product_expiry (product_id, expiration_date),
    INDEX idx_product_batches_remaining_expiry (remaining_quantity, expiration_date),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- ---------- Sales (Cashier) ----------
CREATE TABLE sales (
    sale_id INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash','card','ewallet') DEFAULT 'cash',
    cash_received DECIMAL(10,2) NULL,
    change_due DECIMAL(10,2) NULL,
    payment_reference VARCHAR(120) NULL,
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sales_sale_date (sale_date),
    FOREIGN KEY (cashier_id) REFERENCES users(user_id)
);

CREATE TABLE sale_items (
    sale_item_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    INDEX idx_sale_items_product_id (product_id),
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

CREATE TABLE sale_item_batches (
    sale_item_batch_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_item_id INT NOT NULL,
    batch_id INT NOT NULL,
    quantity INT NOT NULL,
    INDEX idx_sale_item_batches_sale_item (sale_item_id),
    INDEX idx_sale_item_batches_batch (batch_id),
    FOREIGN KEY (sale_item_id) REFERENCES sale_items(sale_item_id),
    FOREIGN KEY (batch_id) REFERENCES product_batches(batch_id)
);

CREATE TABLE sale_reversals (
    reversal_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    reversal_type ENUM('cancel','return','refund','exchange') NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reason TEXT NOT NULL,
    settlement_method ENUM('none','cash','card','ewallet','exchange') NOT NULL DEFAULT 'none',
    refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    exchange_details TEXT NULL,
    requested_by INT NOT NULL,
    approved_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id),
    FOREIGN KEY (requested_by) REFERENCES users(user_id),
    FOREIGN KEY (approved_by) REFERENCES users(user_id)
);

CREATE TABLE sale_reversal_items (
    reversal_item_id INT AUTO_INCREMENT PRIMARY KEY,
    reversal_id INT NOT NULL,
    sale_item_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (reversal_id) REFERENCES sale_reversals(reversal_id),
    FOREIGN KEY (sale_item_id) REFERENCES sale_items(sale_item_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- ---------- Machine Learning: predictions storage ----------
-- Populated by the Python ML service (demand forecast / reorder suggestions)
CREATE TABLE forecast_runs (
    forecast_run_id INT AUTO_INCREMENT PRIMARY KEY,
    model_name VARCHAR(100) NOT NULL,
    model_version VARCHAR(20) NOT NULL,
    forecast_period_days INT NOT NULL DEFAULT 30,
    evaluation_result LONGTEXT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_forecast_runs_generated_at (generated_at)
);

CREATE TABLE stock_predictions (
    prediction_id INT AUTO_INCREMENT PRIMARY KEY,
    forecast_run_id INT NOT NULL,
    product_id INT NOT NULL,
    forecast_period_days INT NOT NULL DEFAULT 30,
    forecast_value INT,
    predicted_demand_next_7_days INT,
    predicted_demand_next_30_days INT,
    forecasted_demand_during_lead_time INT,
    actual_demand INT NULL,
    evaluation_result LONGTEXT NULL,
    supplier_lead_time_days INT,
    safety_stock_used INT,
    current_stock_used INT,
    incoming_stock_used INT,
    minimum_order_quantity_used INT,
    units_per_package_used INT,
    reorder_suggested BOOLEAN DEFAULT FALSE,
    suggested_reorder_qty INT,
    confidence_score DECIMAL(5,2),         -- interval-based 0.00 - 1.00
    lower_bound_7_days INT NULL,
    upper_bound_7_days INT NULL,
    lower_bound_30_days INT NULL,
    upper_bound_30_days INT NULL,
    lead_time_lower_bound INT NULL,
    lead_time_upper_bound INT NULL,
    forecast_explanation TEXT NULL,
    model_version VARCHAR(20),
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_stock_predictions_run_product_period UNIQUE (forecast_run_id, product_id, forecast_period_days),
    INDEX idx_stock_predictions_forecast_run_id (forecast_run_id),
    INDEX idx_stock_predictions_product_id (product_id),
    FOREIGN KEY (forecast_run_id) REFERENCES forecast_runs(forecast_run_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- Activity log (useful for admin oversight)
CREATE TABLE activity_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    module VARCHAR(100) NULL,
    record_id INT NULL,
    previous_value TEXT NULL,
    new_value TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- NOTE:
-- Some shared MySQL/MariaDB hosts do not grant the TRIGGER privilege, which
-- makes phpMyAdmin imports fail with "#1142 - TRIGGER command denied".
-- The activity_log table is still created and used by the application; the
-- database-level immutability triggers were removed so this schema can import
-- with standard database-user permissions.

-- ---------- Fiscal Periods (Admin) ----------
CREATE TABLE fiscal_periods (
    period_id INT AUTO_INCREMENT PRIMARY KEY,
    period_name VARCHAR(100) NOT NULL UNIQUE,  -- e.g. "Q1 2024", "January 2024"
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('open','closed','locked') DEFAULT 'open',  -- open = can record, closed = no changes, locked = read-only
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL,
    closed_by INT NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    FOREIGN KEY (closed_by) REFERENCES users(user_id)
);

-- Lock records by period (prevents changes after closure)
CREATE TABLE fiscal_period_locks (
    lock_id INT AUTO_INCREMENT PRIMARY KEY,
    period_id INT NOT NULL,
    table_name VARCHAR(50),  -- e.g., 'sales', 'inventory', 'purchase_history'
    record_id INT,
    locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (period_id) REFERENCES fiscal_periods(period_id)
);

-- ---------- Replenishment Requests (Inventory Manager) ----------
CREATE TABLE replenishment_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    request_qty INT NOT NULL,
    requested_by INT NOT NULL,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','approved','partially_received','rejected','received') DEFAULT 'pending',
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    source ENUM('manual','ml_forecast') DEFAULT 'manual',  -- Track if from ML prediction
    notes TEXT,
    CHECK (request_qty > 0),
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (requested_by) REFERENCES users(user_id),
    FOREIGN KEY (approved_by) REFERENCES users(user_id)
);

-- ---------- Stock Receiving (Cashier) ----------
CREATE TABLE stock_receiving (
    receiving_id INT AUTO_INCREMENT PRIMARY KEY,
    replenishment_request_id INT NULL,  -- NULL if ad-hoc receiving
    product_id INT NOT NULL,
    received_qty INT NOT NULL,
    accepted_qty INT NOT NULL DEFAULT 0,
    damaged_qty INT NOT NULL DEFAULT 0,
    cost_price DECIMAL(10,2) NULL,
    total_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    received_by INT NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    supplier VARCHAR(150),
    po_number VARCHAR(100),  -- Purchase order reference
    invoice_number VARCHAR(100),
    batch_number VARCHAR(100),
    expiration_date DATE NULL,
    discrepancy_type ENUM('none','short','over','damaged','documentation','other') NOT NULL DEFAULT 'none',
    discrepancy_qty INT NOT NULL DEFAULT 0,
    discrepancy_notes TEXT NULL,
    notes TEXT,
    FOREIGN KEY (replenishment_request_id) REFERENCES replenishment_requests(request_id),
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (received_by) REFERENCES users(user_id)
);

ALTER TABLE product_batches
    ADD FOREIGN KEY (receiving_id) REFERENCES stock_receiving(receiving_id);

-- ---------- Inventory Adjustments (Damaged/Missing/Expired) ----------
CREATE TABLE inventory_adjustments (
    adjustment_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    adjustment_qty INT NOT NULL,  -- negative for damage/loss/expiration
    adjustment_type ENUM('damaged','missing','expired','other') NOT NULL,
    reported_by INT NOT NULL,
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    reason TEXT,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (reported_by) REFERENCES users(user_id),
    FOREIGN KEY (approved_by) REFERENCES users(user_id)
);

-- ---------- Physical Inventory Counts & Reconciliation ----------
CREATE TABLE inventory_counts (
    count_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    system_quantity INT NOT NULL,
    physical_quantity INT NOT NULL,
    difference_qty INT NOT NULL,
    discrepancy_reason TEXT NOT NULL,
    counted_by INT NOT NULL,
    approved_by INT NULL,
    counted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    INDEX idx_inventory_counts_status (status),
    INDEX idx_inventory_counts_product_counted_at (product_id, counted_at),
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (counted_by) REFERENCES users(user_id),
    FOREIGN KEY (approved_by) REFERENCES users(user_id)
);

-- ---------- Notifications ----------
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('low_stock','replenishment','adjustment','system','fiscal_period','expiring_stock','expired_stock') NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    reference_id INT NULL,  -- ID of related product/request/etc
    reference_type VARCHAR(50) NULL,  -- 'product', 'replenishment', 'adjustment'
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_id (user_id),
    INDEX idx_notifications_is_read (is_read),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- User notification preferences (email/in-app)
CREATE TABLE notification_preferences (
    pref_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    notify_low_stock BOOLEAN DEFAULT TRUE,
    notify_replenishment BOOLEAN DEFAULT TRUE,
    notify_adjustment BOOLEAN DEFAULT FALSE,
    notify_email BOOLEAN DEFAULT FALSE,
    notify_inapp BOOLEAN DEFAULT TRUE,
    low_stock_threshold INT DEFAULT 10,  -- Override product reorder_level
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- CSV Import logs (track bulk import operations)
CREATE TABLE csv_import_logs (
    import_id INT AUTO_INCREMENT PRIMARY KEY,
    imported_by INT NOT NULL,
    import_type ENUM('products','inventory') NOT NULL,
    filename VARCHAR(255),
    total_rows INT,
    successful_rows INT,
    failed_rows INT,
    import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('completed','failed') DEFAULT 'completed',
    error_details TEXT NULL,
    FOREIGN KEY (imported_by) REFERENCES users(user_id)
);

-- ---------- Barcode Scanner Pairing ----------
CREATE TABLE barcode_pairings (
    pairing_id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(12) NOT NULL UNIQUE,
    created_by INT NOT NULL,
    status ENUM('pending','connected','expired') NOT NULL DEFAULT 'pending',
    device_label VARCHAR(120) NULL,
    expires_at DATETIME NOT NULL,
    connected_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    access_token_hash VARCHAR(255) NULL,
    joined_ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_barcode_pairings_code (code),
    INDEX idx_barcode_pairings_expires_at (expires_at),
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE barcode_scans (
    scan_id INT AUTO_INCREMENT PRIMARY KEY,
    pairing_id INT NOT NULL,
    barcode VARCHAR(255) NOT NULL,
    payload TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_barcode_scans_pairing_scan (pairing_id, scan_id),
    FOREIGN KEY (pairing_id) REFERENCES barcode_pairings(pairing_id) ON DELETE CASCADE
);


-- ---------- Security, configuration, and ML operations ----------
CREATE TABLE login_attempts (
    attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_identity (username, ip_address, attempted_at),
    INDEX idx_login_attempts_time (attempted_at)
);

CREATE TABLE password_reset_tokens (
    reset_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_tokens_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE store_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT INTO store_settings (setting_key, setting_value) VALUES
('store_name', 'Shalom Store'),
('store_address', 'Tangub City'),
('store_phone', ''),
('store_email', ''),
('business_identifier', ''),
('receipt_footer', 'Thank you for shopping with us.'),
('currency_symbol', '₱'),
('timezone', 'Asia/Manila')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

CREATE TABLE email_delivery_log (
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

CREATE TABLE backup_history (
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

CREATE TABLE ml_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT INTO ml_settings (setting_key, setting_value) VALUES
('minimum_history_days', '30'),
('preferred_history_days', '90'),
('minimum_nonzero_sales_days', '5'),
('history_window_days', '365'),
('forecast_period_days', '30'),
('n_estimators', '300'),
('max_depth', '18'),
('min_samples_split', '4'),
('min_samples_leaf', '2'),
('retrain_frequency_days', '7'),
('retrain_new_sales_records', '100'),
('accuracy_threshold_wape', '35'),
('prediction_interval_lower', '10'),
('prediction_interval_upper', '90'),
('holiday_dates', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

CREATE TABLE model_training_runs (
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

CREATE TABLE forecast_evaluations (
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
