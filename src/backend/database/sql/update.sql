-- RetailMind single-run UPDATE, reconciled through 2026-10-08.
-- Select the existing RetailMind database in phpMyAdmin, then import this file.
-- Back up first; stop Store activity during import. DDL commits independently.
-- Existing business records and passwords are retained. No DROP TABLE or TRUNCATE.
-- Re-running repairs schema even when schema_migrations incorrectly says applied.
-- Requires the existing base RetailMind tables; use schema.sql for a fresh install.
-- Includes SQL dependencies only; PHP/Composer, Python, .env and private storage stay separate.
-- No CREATE ROUTINE permission or DELIMITER support required. Stop on the first SQL error.
SET @rm_old_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;
SET @rm_old_time_zone = @@SESSION.time_zone;
SET SESSION time_zone = '+08:00';
CREATE TABLE IF NOT EXISTS schema_migrations (migration_key VARCHAR(100) PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 202608040001_auth_security: Authentication security tables and mandatory password-change support
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
            INDEX idx_password_reset_tokens_expiry (expires_at)
        );
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='failed_login_attempts'), 'ALTER TABLE `users` ADD COLUMN `failed_login_attempts` INT NOT NULL DEFAULT 0', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='locked_until'), 'ALTER TABLE `users` ADD COLUMN `locked_until` DATETIME NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='last_login_at'), 'ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='session_version'), 'ALTER TABLE `users` ADD COLUMN `session_version` INT NOT NULL DEFAULT 1', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='password_changed_at'), 'ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='must_change_password'), 'ALTER TABLE `users` ADD COLUMN `must_change_password` BOOLEAN NOT NULL DEFAULT TRUE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='password_reset_tokens' AND column_name='user_id' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `password_reset_tokens` ADD CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
UPDATE users SET must_change_password = 1 WHERE username = 'superadmin' AND password_changed_at IS NULL;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202608040001_auth_security','Authentication security tables and mandatory password-change support') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202608040002_operational_updates: Operational workflow tables and columns
CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(100) PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS suppliers (
        supplier_id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_name VARCHAR(150) NOT NULL UNIQUE,
        contact_person VARCHAR(120) NULL,
        email VARCHAR(150) NULL,
        phone VARCHAR(60) NULL,
        address TEXT NULL,
        standard_lead_time_days INT NOT NULL DEFAULT 7,
        minimum_order_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_suppliers_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS supplier_products (
        supplier_product_id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        product_id INT NOT NULL,
        supplier_sku VARCHAR(100) NULL,
        last_unit_cost DECIMAL(12,2) NULL,
        minimum_order_quantity INT NOT NULL DEFAULT 1,
        lead_time_days INT NOT NULL DEFAULT 7,
        is_preferred BOOLEAN NOT NULL DEFAULT FALSE,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_supplier_product (supplier_id, product_id),
        INDEX idx_supplier_products_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS purchase_orders (
        purchase_order_id INT AUTO_INCREMENT PRIMARY KEY,
        po_number VARCHAR(60) NOT NULL UNIQUE,
        supplier_id INT NULL,
        status ENUM('draft','approved','sent','partially_received','fully_received','cancelled') NOT NULL DEFAULT 'draft',
        expected_delivery_date DATE NULL,
        notes TEXT NULL,
        created_by INT NOT NULL,
        approved_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        approved_at TIMESTAMP NULL,
        sent_at TIMESTAMP NULL,
        cancelled_at TIMESTAMP NULL,
        INDEX idx_purchase_orders_status (status),
        INDEX idx_purchase_orders_supplier (supplier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS purchase_order_items (
        purchase_order_item_id INT AUTO_INCREMENT PRIMARY KEY,
        purchase_order_id INT NOT NULL,
        replenishment_request_id INT NULL,
        product_id INT NOT NULL,
        ordered_qty INT NOT NULL,
        received_qty INT NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        notes TEXT NULL,
        UNIQUE KEY uq_po_request (purchase_order_id, replenishment_request_id),
        INDEX idx_po_items_product (product_id),
        INDEX idx_po_items_request (replenishment_request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cashier_shifts (
        shift_id INT AUTO_INCREMENT PRIMARY KEY,
        cashier_id INT NOT NULL,
        register_id INT NULL,
        opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        opening_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('open','closed') NOT NULL DEFAULT 'open',
        closed_at TIMESTAMP NULL,
        expected_cash DECIMAL(12,2) NULL,
        actual_cash DECIMAL(12,2) NULL,
        cash_variance DECIMAL(12,2) NULL,
        variance_threshold DECIMAL(12,2) NULL,
        variance_review_required TINYINT(1) NOT NULL DEFAULT 0,
        payment_totals TEXT NULL,
        closing_notes TEXT NULL,
        closed_by INT NULL,
        intervention_reason TEXT NULL,
        reviewed_by INT NULL,
        reviewed_at TIMESTAMP NULL,
        open_cashier_id INT GENERATED ALWAYS AS (IF(status = 'open', cashier_id, NULL)) STORED,
        open_register_id INT GENERATED ALWAYS AS (IF(status = 'open', register_id, NULL)) STORED,
        UNIQUE KEY uq_cashier_shifts_open_cashier (open_cashier_id),
        UNIQUE KEY uq_cashier_shifts_open_register (open_register_id),
        INDEX idx_cashier_shifts_cashier_status (cashier_id, status),
        INDEX idx_cashier_shifts_register (register_id),
        INDEX idx_cashier_shifts_closed_by (closed_by),
        INDEX idx_cashier_shifts_opened_at (opened_at),
        INDEX idx_cashier_shifts_variance_review (variance_review_required, status, closed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cash_drawer_movements (
        drawer_movement_id INT AUTO_INCREMENT PRIMARY KEY,
        shift_id INT NOT NULL,
        movement_type ENUM('pay_in','pay_out','cash_in','cash_out','safe_drop') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        note VARCHAR(255) NULL,
        cashier_id INT NOT NULL,
        recorded_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_drawer_movements_shift (shift_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS held_sales (
        held_sale_id INT AUTO_INCREMENT PRIMARY KEY,
        cashier_id INT NOT NULL,
        shift_id INT NULL,
        reference_no VARCHAR(50) NOT NULL UNIQUE,
        customer_label VARCHAR(120) NULL,
        cart_json LONGTEXT NOT NULL,
        item_count INT NOT NULL DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('held','resumed','cancelled','expired') NOT NULL DEFAULT 'held',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        resolved_at DATETIME NULL,
        INDEX idx_held_sales_cashier_status (cashier_id, status),
        INDEX idx_held_sales_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS promotions (
        promotion_id INT AUTO_INCREMENT PRIMARY KEY,
        promotion_name VARCHAR(150) NOT NULL,
        discount_type ENUM('percentage','fixed') NOT NULL,
        discount_value DECIMAL(12,2) NOT NULL,
        scope ENUM('all','product','category') NOT NULL DEFAULT 'all',
        product_id INT NULL,
        category_id INT NULL,
        minimum_quantity INT NOT NULL DEFAULT 1,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_promotions_active_window (status, starts_at, ends_at),
        INDEX idx_promotions_product (product_id),
        INDEX idx_promotions_category (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS forecast_decisions (
        forecast_decision_id INT AUTO_INCREMENT PRIMARY KEY,
        prediction_id INT NOT NULL,
        product_id INT NOT NULL,
        original_suggested_qty INT NOT NULL DEFAULT 0,
        final_quantity INT NOT NULL DEFAULT 0,
        decision ENUM('accepted','modified','rejected') NOT NULL,
        override_reason TEXT NULL,
        decided_by INT NOT NULL,
        decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        replenishment_request_id INT NULL,
        INDEX idx_forecast_decisions_prediction (prediction_id),
        INDEX idx_forecast_decisions_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cycle_count_schedules (
        schedule_id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        abc_class ENUM('A','B','C') NOT NULL DEFAULT 'C',
        frequency_days INT NOT NULL DEFAULT 90,
        next_count_date DATE NOT NULL,
        priority_score DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('scheduled','completed','skipped') NOT NULL DEFAULT 'scheduled',
        assigned_to INT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        INDEX idx_cycle_product_status (product_id, status),
        INDEX idx_cycle_count_next_date (next_count_date, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='shift_id'), 'ALTER TABLE `sales` ADD COLUMN `shift_id` INT NULL AFTER cashier_id', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='gross_amount'), 'ALTER TABLE `sales` ADD COLUMN `gross_amount` DECIMAL(12,2) NULL AFTER total_amount', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='discount_type'), 'ALTER TABLE `sales` ADD COLUMN `discount_type` ENUM(''none'',''percentage'',''fixed'') NOT NULL DEFAULT ''none'' AFTER gross_amount', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='discount_value'), 'ALTER TABLE `sales` ADD COLUMN `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_type', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='discount_amount'), 'ALTER TABLE `sales` ADD COLUMN `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_value', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='discount_reason'), 'ALTER TABLE `sales` ADD COLUMN `discount_reason` VARCHAR(255) NULL AFTER discount_amount', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='discount_authorized_by'), 'ALTER TABLE `sales` ADD COLUMN `discount_authorized_by` INT NULL AFTER discount_reason', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='promotion_id'), 'ALTER TABLE `sales` ADD COLUMN `promotion_id` INT NULL AFTER discount_authorized_by', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='promotion_name'), 'ALTER TABLE `sales` ADD COLUMN `promotion_name` VARCHAR(150) NULL AFTER promotion_id', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='base_unit'), 'ALTER TABLE `products` ADD COLUMN `base_unit` VARCHAR(40) NOT NULL DEFAULT ''piece'' AFTER units_per_package', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='receiving_unit'), 'ALTER TABLE `products` ADD COLUMN `receiving_unit` VARCHAR(40) NOT NULL DEFAULT ''package'' AFTER base_unit', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='case_barcode'), 'ALTER TABLE `products` ADD COLUMN `case_barcode` VARCHAR(80) NULL AFTER barcode', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='parent_product_id'), 'ALTER TABLE `products` ADD COLUMN `parent_product_id` INT NULL AFTER case_barcode', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='variant_label'), 'ALTER TABLE `products` ADD COLUMN `variant_label` VARCHAR(100) NULL AFTER parent_product_id', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_receiving' AND column_name='purchase_order_id'), 'ALTER TABLE `stock_receiving` ADD COLUMN `purchase_order_id` INT NULL AFTER replenishment_request_id', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_receiving' AND column_name='purchase_order_item_id'), 'ALTER TABLE `stock_receiving` ADD COLUMN `purchase_order_item_id` INT NULL AFTER purchase_order_id', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_receiving' AND column_name='received_packages'), 'ALTER TABLE `stock_receiving` ADD COLUMN `received_packages` INT NOT NULL DEFAULT 0 AFTER received_qty', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_receiving' AND column_name='units_per_package_used'), 'ALTER TABLE `stock_receiving` ADD COLUMN `units_per_package_used` INT NOT NULL DEFAULT 1 AFTER received_packages', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='replenishment_requests' AND column_name='forecast_prediction_id'), 'ALTER TABLE `replenishment_requests` ADD COLUMN `forecast_prediction_id` INT NULL AFTER source', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='replenishment_requests' AND column_name='original_suggested_qty'), 'ALTER TABLE `replenishment_requests` ADD COLUMN `original_suggested_qty` INT NULL AFTER forecast_prediction_id', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='replenishment_requests' AND column_name='override_reason'), 'ALTER TABLE `replenishment_requests` ADD COLUMN `override_reason` TEXT NULL AFTER original_suggested_qty', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key, description) VALUES ('2026_07_operational_updates', 'Suppliers, purchase orders, shifts, held sales, forecast decisions, units, and inventory insights')
         ON DUPLICATE KEY UPDATE description = VALUES(description);
INSERT INTO schema_migrations (migration_key,description) VALUES ('202608040002_operational_updates','Operational workflow tables and columns') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202608040003_integrity_constraints: Foreign-key protections for upgraded operational databases
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='suppliers' AND column_name='created_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `suppliers` ADD CONSTRAINT `fk_suppliers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='supplier_products' AND column_name='supplier_id' AND referenced_table_name='suppliers' AND referenced_column_name='supplier_id'), 'ALTER TABLE `supplier_products` ADD CONSTRAINT `fk_supplier_products_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='supplier_products' AND column_name='product_id' AND referenced_table_name='products' AND referenced_column_name='product_id'), 'ALTER TABLE `supplier_products` ADD CONSTRAINT `fk_supplier_products_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='purchase_orders' AND column_name='supplier_id' AND referenced_table_name='suppliers' AND referenced_column_name='supplier_id'), 'ALTER TABLE `purchase_orders` ADD CONSTRAINT `fk_purchase_orders_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='purchase_orders' AND column_name='created_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `purchase_orders` ADD CONSTRAINT `fk_purchase_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='purchase_orders' AND column_name='approved_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `purchase_orders` ADD CONSTRAINT `fk_purchase_orders_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='purchase_order_items' AND column_name='purchase_order_id' AND referenced_table_name='purchase_orders' AND referenced_column_name='purchase_order_id'), 'ALTER TABLE `purchase_order_items` ADD CONSTRAINT `fk_po_items_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`purchase_order_id`) ON DELETE CASCADE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='purchase_order_items' AND column_name='replenishment_request_id' AND referenced_table_name='replenishment_requests' AND referenced_column_name='request_id'), 'ALTER TABLE `purchase_order_items` ADD CONSTRAINT `fk_po_items_request` FOREIGN KEY (`replenishment_request_id`) REFERENCES `replenishment_requests` (`request_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='purchase_order_items' AND column_name='product_id' AND referenced_table_name='products' AND referenced_column_name='product_id'), 'ALTER TABLE `purchase_order_items` ADD CONSTRAINT `fk_po_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='cashier_id' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cashier_shifts` ADD CONSTRAINT `fk_cashier_shifts_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='reviewed_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cashier_shifts` ADD CONSTRAINT `fk_cashier_shifts_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_drawer_movements' AND column_name='shift_id' AND referenced_table_name='cashier_shifts' AND referenced_column_name='shift_id'), 'ALTER TABLE `cash_drawer_movements` ADD CONSTRAINT `fk_drawer_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE CASCADE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_drawer_movements' AND column_name='recorded_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cash_drawer_movements` ADD CONSTRAINT `fk_drawer_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='held_sales' AND column_name='cashier_id' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `held_sales` ADD CONSTRAINT `fk_held_sales_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='held_sales' AND column_name='shift_id' AND referenced_table_name='cashier_shifts' AND referenced_column_name='shift_id'), 'ALTER TABLE `held_sales` ADD CONSTRAINT `fk_held_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='promotions' AND column_name='product_id' AND referenced_table_name='products' AND referenced_column_name='product_id'), 'ALTER TABLE `promotions` ADD CONSTRAINT `fk_promotions_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='promotions' AND column_name='category_id' AND referenced_table_name='categories' AND referenced_column_name='category_id'), 'ALTER TABLE `promotions` ADD CONSTRAINT `fk_promotions_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='promotions' AND column_name='created_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `promotions` ADD CONSTRAINT `fk_promotions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='forecast_decisions' AND column_name='prediction_id' AND referenced_table_name='stock_predictions' AND referenced_column_name='prediction_id'), 'ALTER TABLE `forecast_decisions` ADD CONSTRAINT `fk_forecast_decisions_prediction` FOREIGN KEY (`prediction_id`) REFERENCES `stock_predictions` (`prediction_id`) ON DELETE CASCADE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='forecast_decisions' AND column_name='product_id' AND referenced_table_name='products' AND referenced_column_name='product_id'), 'ALTER TABLE `forecast_decisions` ADD CONSTRAINT `fk_forecast_decisions_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='forecast_decisions' AND column_name='decided_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `forecast_decisions` ADD CONSTRAINT `fk_forecast_decisions_user` FOREIGN KEY (`decided_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='forecast_decisions' AND column_name='replenishment_request_id' AND referenced_table_name='replenishment_requests' AND referenced_column_name='request_id'), 'ALTER TABLE `forecast_decisions` ADD CONSTRAINT `fk_forecast_decisions_request` FOREIGN KEY (`replenishment_request_id`) REFERENCES `replenishment_requests` (`request_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cycle_count_schedules' AND column_name='product_id' AND referenced_table_name='products' AND referenced_column_name='product_id'), 'ALTER TABLE `cycle_count_schedules` ADD CONSTRAINT `fk_cycle_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cycle_count_schedules' AND column_name='assigned_to' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cycle_count_schedules` ADD CONSTRAINT `fk_cycle_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cycle_count_schedules' AND column_name='created_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cycle_count_schedules` ADD CONSTRAINT `fk_cycle_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202608040003_integrity_constraints','Foreign-key protections for upgraded operational databases') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609130001_branch_management: Branches, branch-scoped users and privilege assignments
CREATE TABLE IF NOT EXISTS branches (
            branch_id INT AUTO_INCREMENT PRIMARY KEY,
            branch_name VARCHAR(100) NOT NULL UNIQUE,
            branch_code VARCHAR(30) NOT NULL UNIQUE,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_branches_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS privileges (
            privilege_id INT AUTO_INCREMENT PRIMARY KEY,
            privilege_key VARCHAR(80) NOT NULL UNIQUE,
            privilege_name VARCHAR(120) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS role_privileges (
            role_id INT NOT NULL,
            privilege_id INT NOT NULL,
            PRIMARY KEY (role_id, privilege_id),
            FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
            FOREIGN KEY (privilege_id) REFERENCES privileges(privilege_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS user_privileges (
            user_id INT NOT NULL,
            privilege_id INT NOT NULL,
            allowed BOOLEAN NOT NULL DEFAULT TRUE,
            PRIMARY KEY (user_id, privilege_id),
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (privilege_id) REFERENCES privileges(privilege_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='branch_id'), 'ALTER TABLE `users` ADD COLUMN `branch_id` INT NULL AFTER role_id', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='branch_id'), 'ALTER TABLE `products` ADD COLUMN `branch_id` INT NULL AFTER created_by', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='users' AND index_name='idx_users_branch_id'), 'ALTER TABLE `users` ADD INDEX `idx_users_branch_id` (branch_id)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='products' AND index_name='idx_products_branch_id'), 'ALTER TABLE `products` ADD INDEX `idx_products_branch_id` (branch_id)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='users' AND column_name='branch_id' AND referenced_table_name='branches' AND referenced_column_name='branch_id'), 'ALTER TABLE `users` ADD CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='products' AND column_name='branch_id' AND referenced_table_name='branches' AND referenced_column_name='branch_id'), 'ALTER TABLE `products` ADD CONSTRAINT `fk_products_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT IGNORE INTO roles (role_name) VALUES ('seller');
INSERT IGNORE INTO privileges (privilege_key, privilege_name) VALUES
            ('manage_users', 'Manage user accounts'),
            ('manage_roles', 'Assign user roles'),
            ('manage_privileges', 'Manage user privileges'),
            ('manage_branches', 'Create and manage branches'),
            ('manage_inventory', 'Manage branch inventory'),
            ('view_inventory', 'View branch inventory');
INSERT IGNORE INTO role_privileges (role_id, privilege_id)
            SELECT r.role_id, p.privilege_id FROM roles r CROSS JOIN privileges p
            WHERE r.role_name IN ('super_admin', 'admin');
INSERT IGNORE INTO role_privileges (role_id, privilege_id)
            SELECT r.role_id, p.privilege_id FROM roles r CROSS JOIN privileges p
            WHERE r.role_name = 'inventory_manager' AND p.privilege_key IN ('manage_inventory', 'view_inventory');
INSERT IGNORE INTO role_privileges (role_id, privilege_id)
            SELECT r.role_id, p.privilege_id FROM roles r CROSS JOIN privileges p
            WHERE r.role_name IN ('seller', 'cashier') AND p.privilege_key = 'view_inventory';
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609130001_branch_management','Branches, branch-scoped users and privilege assignments') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609130002_role_access_update: Make administrator inventory access read-only
DELETE rp FROM role_privileges rp
             JOIN roles r ON r.role_id = rp.role_id
             JOIN privileges p ON p.privilege_id = rp.privilege_id
             WHERE r.role_name IN ('admin', 'super_admin') AND p.privilege_key = 'manage_inventory';
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609130002_role_access_update','Make administrator inventory access read-only') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609130003_superadmin_inventory_read_only: Make super administrator inventory access read-only
DELETE rp FROM role_privileges rp
             JOIN roles r ON r.role_id = rp.role_id
             JOIN privileges p ON p.privilege_id = rp.privilege_id
             WHERE r.role_name = 'super_admin' AND p.privilege_key = 'manage_inventory';
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609130003_superadmin_inventory_read_only','Make super administrator inventory access read-only') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609130004_remove_seller_role: Replace the duplicate seller role with cashier
UPDATE users u JOIN roles old ON old.role_id=u.role_id JOIN roles cashier ON cashier.role_name='cashier' SET u.role_id=cashier.role_id WHERE old.role_name='seller';
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='user_roles'), 'INSERT IGNORE INTO user_roles (user_id,role_id,is_primary) SELECT ur.user_id,c.role_id,ur.is_primary FROM user_roles ur JOIN roles s ON s.role_id=ur.role_id AND s.role_name=''seller'' JOIN roles c ON c.role_name=''cashier''', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='user_roles'), 'DELETE ur FROM user_roles ur JOIN roles s ON s.role_id=ur.role_id WHERE s.role_name=''seller''', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
DELETE rp FROM role_privileges rp JOIN roles r ON r.role_id=rp.role_id WHERE r.role_name='seller';
DELETE r FROM roles r WHERE r.role_name='seller' AND EXISTS (SELECT 1 FROM (SELECT role_name FROM roles) available WHERE available.role_name='cashier');
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609130004_remove_seller_role','Replace the duplicate seller role with cashier') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609140001_branch_scoped_product_identifiers: Allow product identifiers to be reused across branches
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='products' AND index_name='uq_products_branch_sku'), 'ALTER TABLE `products` ADD UNIQUE KEY `uq_products_branch_sku` (`branch_id`, `sku`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='products' AND index_name='sku'), 'ALTER TABLE products DROP INDEX `sku`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='products' AND index_name='uq_products_branch_barcode'), 'ALTER TABLE `products` ADD UNIQUE KEY `uq_products_branch_barcode` (`branch_id`, `barcode`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='products' AND index_name='barcode'), 'ALTER TABLE products DROP INDEX `barcode`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='products' AND index_name='uq_products_branch_case_barcode'), 'ALTER TABLE `products` ADD UNIQUE KEY `uq_products_branch_case_barcode` (`branch_id`, `case_barcode`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='products' AND index_name='case_barcode'), 'ALTER TABLE products DROP INDEX `case_barcode`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609140001_branch_scoped_product_identifiers','Allow product identifiers to be reused across branches') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609170001_profile_images: Optional generated profile image filename for user accounts
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='profile_image'), 'ALTER TABLE `users` ADD COLUMN `profile_image` VARCHAR(80) NULL AFTER email', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609170001_profile_images','Optional generated profile image filename for user accounts') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609180001_singleton_store_scope: Resolve the singleton Store compatibility identity
INSERT INTO branches (branch_name,branch_code,status) SELECT 'RetailMind Store','RETAILMIND-STORE','active' WHERE NOT EXISTS (SELECT 1 FROM branches);
SET @rm_sql = IF((SELECT COUNT(*) FROM branches b WHERE EXISTS (SELECT 1 FROM users u WHERE u.branch_id=b.branch_id) OR EXISTS (SELECT 1 FROM products p WHERE p.branch_id=b.branch_id)) > 1 OR EXISTS (SELECT 1 FROM branches b WHERE b.status<>'active' AND (EXISTS (SELECT 1 FROM users u WHERE u.branch_id=b.branch_id) OR EXISTS (SELECT 1 FROM products p WHERE p.branch_id=b.branch_id))) OR ((SELECT COUNT(*) FROM branches WHERE status='active')<>1 AND NOT EXISTS (SELECT 1 FROM branches b WHERE EXISTS (SELECT 1 FROM users u WHERE u.branch_id=b.branch_id) OR EXISTS (SELECT 1 FROM products p WHERE p.branch_id=b.branch_id))), 'SELECT * FROM `__retailmind_error_store_consolidation_required`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180001_singleton_store_scope','Resolve the singleton Store compatibility identity') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609180002_audit_record_categories: Categorize Protected Audit Records for authority-scoped visibility
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='activity_log' AND column_name='category'), 'ALTER TABLE `activity_log` ADD COLUMN `category` ENUM(''store_operation'',''security'',''recovery'',''platform_setting'',''recovery_account'') NULL AFTER `action`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
UPDATE activity_log a SET category=CASE
          WHEN LOWER(TRIM(COALESCE(module,''))) IN ('users','user access') THEN CASE WHEN COALESCE((SELECT r.role_name FROM roles r WHERE r.role_id=JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.new_value),a.new_value,'{}'),'$.role_id')) LIMIT 1),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.new_value),a.new_value,'{}'),'$.role')),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.new_value),a.new_value,'{}'),'$.target_role')),'') IN ('super_admin','admin') OR COALESCE((SELECT r.role_name FROM roles r WHERE r.role_id=JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.previous_value),a.previous_value,'{}'),'$.role_id')) LIMIT 1),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.previous_value),a.previous_value,'{}'),'$.role')),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.previous_value),a.previous_value,'{}'),'$.target_role')),'') IN ('super_admin','admin') THEN 'security' WHEN COALESCE((SELECT r.role_name FROM roles r WHERE r.role_id=JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.new_value),a.new_value,'{}'),'$.role_id')) LIMIT 1),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.new_value),a.new_value,'{}'),'$.role')),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.new_value),a.new_value,'{}'),'$.target_role')),'') IN ('cashier','inventory_manager') OR COALESCE((SELECT r.role_name FROM roles r WHERE r.role_id=JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.previous_value),a.previous_value,'{}'),'$.role_id')) LIMIT 1),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.previous_value),a.previous_value,'{}'),'$.role')),JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(a.previous_value),a.previous_value,'{}'),'$.target_role')),'') IN ('cashier','inventory_manager') THEN 'store_operation' ELSE 'security' END
          WHEN LOWER(CONCAT(COALESCE(module,''),' ',action)) LIKE '%recovery account%' THEN 'recovery_account'
          WHEN LOWER(CONCAT(COALESCE(module,''),' ',action)) LIKE '%backup & restore%' OR LOWER(CONCAT(COALESCE(module,''),' ',action)) REGEXP '(^|[^[:alnum:]_])(database )?(backup|restore)([^[:alnum:]_]|$)' THEN 'recovery'
          WHEN LOWER(CONCAT(COALESCE(module,''),' ',action)) LIKE '%platform setting%' OR LOWER(CONCAT(COALESCE(module,''),' ',action)) LIKE '%ml setting%' OR LOWER(CONCAT(COALESCE(module,''),' ',action)) LIKE '%demand forecasting%' THEN 'platform_setting'
          WHEN LOWER(CONCAT(COALESCE(module,''),' ',action)) LIKE '%authentication%' OR LOWER(CONCAT(COALESCE(module,''),' ',action)) REGEXP '(^|[^[:alnum:]_])(login|logout|password changed|password change)([^[:alnum:]_]|$)' THEN 'security'
          ELSE 'store_operation' END WHERE category IS NULL;
ALTER TABLE activity_log MODIFY category ENUM('store_operation','security','recovery','platform_setting','recovery_account') NOT NULL;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='activity_log' AND index_name='idx_activity_log_category_created'), 'ALTER TABLE `activity_log` ADD INDEX `idx_activity_log_category_created` (`category`, `created_at`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180002_audit_record_categories','Categorize Protected Audit Records for authority-scoped visibility') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609180003_emergency_access: Durable reason-bound Emergency Access sessions and audit correlation
CREATE TABLE IF NOT EXISTS platform_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_platform_settings_updated_by
                FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES ('emergency_access_duration_minutes', '15');
CREATE TABLE IF NOT EXISTS emergency_access_sessions (
            session_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT NOT NULL,
            reason VARCHAR(500) NOT NULL,
            activated_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            duration_minutes SMALLINT UNSIGNED NOT NULL,
            status ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
            revoked_at DATETIME NULL,
            revoked_by INT NULL,
            INDEX idx_emergency_actor_status (actor_user_id, status, expires_at),
            CONSTRAINT fk_emergency_actor FOREIGN KEY (actor_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
            CONSTRAINT fk_emergency_revoker FOREIGN KEY (revoked_by) REFERENCES users(user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='activity_log' AND column_name='metadata'), 'ALTER TABLE `activity_log` ADD COLUMN `metadata` JSON NULL AFTER `new_value`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180003_emergency_access','Durable reason-bound Emergency Access sessions and audit correlation') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609180004_recovery_account: Sealed Recovery Account identity and offline lifecycle state
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='is_recovery_account'), 'ALTER TABLE `users` ADD COLUMN `is_recovery_account` BOOLEAN NOT NULL DEFAULT FALSE AFTER `branch_id`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
CREATE TABLE IF NOT EXISTS recovery_accounts (
            account_key VARCHAR(20) PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            activation_secret_hash VARCHAR(255) NOT NULL,
            activated_at DATETIME NULL,
            sealed_at DATETIME NULL,
            credentials_rotated_at DATETIME NULL,
            last_used_at DATETIME NULL,
            CONSTRAINT fk_recovery_account_user
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180004_recovery_account','Sealed Recovery Account identity and offline lifecycle state') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609180005_attention_foundation: Shared live attention settings, active state, and notification keys
CREATE TABLE IF NOT EXISTS attention_settings (
            setting_scope ENUM('platform','store') NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value VARCHAR(100) NOT NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (setting_scope, setting_key),
            CONSTRAINT fk_attention_setting_actor
                FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS attention_states (
            user_id INT NOT NULL,
            attention_key VARCHAR(120) NOT NULL,
            fingerprint CHAR(64) NOT NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            first_detected_at DATETIME NOT NULL,
            last_detected_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            PRIMARY KEY (user_id, attention_key),
            INDEX idx_attention_states_active (user_id, is_active, last_detected_at),
            CONSTRAINT fk_attention_state_user
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notifications' AND column_name='attention_key'), 'ALTER TABLE `notifications` ADD COLUMN `attention_key` VARCHAR(120) NULL AFTER `reference_type`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notifications' AND column_name='attention_severity'), 'ALTER TABLE `notifications` ADD COLUMN `attention_severity` VARCHAR(20) NULL AFTER `attention_key`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notifications' AND column_name='attention_count'), 'ALTER TABLE `notifications` ADD COLUMN `attention_count` INT NULL AFTER `attention_severity`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notifications' AND column_name='attention_destination'), 'ALTER TABLE `notifications` ADD COLUMN `attention_destination` VARCHAR(255) NULL AFTER `attention_count`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='notifications' AND index_name='idx_notifications_attention'), 'ALTER TABLE `notifications` ADD INDEX `idx_notifications_attention` (`user_id`, `attention_key`, `created_at`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609180005_attention_foundation','Shared live attention settings, active state, and notification keys') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609230001_cashier_stock_issues: Link cashier stock-issue reports to shifts and stock movements (ticket #29)
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_adjustments' AND column_name='shift_id'), 'ALTER TABLE `inventory_adjustments` ADD COLUMN `shift_id` INT NULL AFTER `reported_by`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_adjustments' AND column_name='review_notes'), 'ALTER TABLE `inventory_adjustments` ADD COLUMN `review_notes` TEXT NULL AFTER `reason`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_movements' AND column_name='adjustment_id'), 'ALTER TABLE `stock_movements` ADD COLUMN `adjustment_id` INT NULL AFTER `moved_by`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='inventory_adjustments' AND index_name='idx_inventory_adjustments_shift'), 'ALTER TABLE `inventory_adjustments` ADD INDEX `idx_inventory_adjustments_shift` (`shift_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='inventory_adjustments' AND index_name='idx_inventory_adjustments_status'), 'ALTER TABLE `inventory_adjustments` ADD INDEX `idx_inventory_adjustments_status` (`status`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='stock_movements' AND index_name='idx_stock_movements_adjustment'), 'ALTER TABLE `stock_movements` ADD INDEX `idx_stock_movements_adjustment` (`adjustment_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='inventory_adjustments' AND column_name='shift_id' AND referenced_table_name='cashier_shifts' AND referenced_column_name='shift_id'), 'ALTER TABLE `inventory_adjustments` ADD CONSTRAINT `fk_inventory_adjustments_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='stock_movements' AND column_name='adjustment_id' AND referenced_table_name='inventory_adjustments' AND referenced_column_name='adjustment_id'), 'ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609230001_cashier_stock_issues','Link cashier stock-issue reports to shifts and stock movements (ticket #29)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609230002_stock_issue_corrections: Stock-issue correction lifecycle: returned/cancelled states and append-only revision trail (ticket #30)
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_adjustments' AND column_name='status' AND (column_type NOT LIKE '%''returned''%' OR column_type NOT LIKE '%''cancelled''%')), 'ALTER TABLE `inventory_adjustments`
                  MODIFY COLUMN `status` ENUM(''pending'',''approved'',''rejected'',''returned'',''cancelled'') NOT NULL DEFAULT ''pending''', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
CREATE TABLE IF NOT EXISTS `inventory_adjustment_revisions` (
                `revision_id` INT NOT NULL AUTO_INCREMENT,
                `adjustment_id` INT NOT NULL,
                `actor_id` INT NULL,
                `action` VARCHAR(50) NOT NULL,
                `old_status` VARCHAR(20) NULL,
                `new_status` VARCHAR(20) NULL,
                `old_values` TEXT NULL,
                `new_values` TEXT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`revision_id`),
                KEY `idx_adjustment_revisions_adjustment` (`adjustment_id`),
                CONSTRAINT `fk_adjustment_revisions_adjustment` FOREIGN KEY (`adjustment_id`)
                    REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='inventory_adjustment_revisions' AND index_name='idx_adjustment_revisions_adjustment'), 'ALTER TABLE `inventory_adjustment_revisions` ADD INDEX `idx_adjustment_revisions_adjustment` (`adjustment_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609230002_stock_issue_corrections','Stock-issue correction lifecycle: returned/cancelled states and append-only revision trail (ticket #30)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609240001_stock_issue_oversight_correction_link: Link correction inventory counts to approved stock-issue reports for oversight history (ticket #31)
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_counts' AND column_name='related_adjustment_id'), 'ALTER TABLE `inventory_counts` ADD COLUMN `related_adjustment_id` INT NULL AFTER `status`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='inventory_counts' AND index_name='idx_inventory_counts_related_adjustment'), 'ALTER TABLE `inventory_counts` ADD INDEX `idx_inventory_counts_related_adjustment` (`related_adjustment_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='inventory_counts' AND column_name='related_adjustment_id' AND referenced_table_name='inventory_adjustments' AND referenced_column_name='adjustment_id'), 'ALTER TABLE `inventory_counts` ADD CONSTRAINT `fk_inventory_counts_related_adjustment` FOREIGN KEY (`related_adjustment_id`) REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609240001_stock_issue_oversight_correction_link','Link correction inventory counts to approved stock-issue reports for oversight history (ticket #31)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609240002_dormancy_policy_settings: Seed Dormancy Policy disable-days and warn-days Platform Settings (ticket #60)
CREATE TABLE IF NOT EXISTS platform_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_platform_settings_updated_by
                FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES ('dormancy_disable_days', '45');
INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES ('dormancy_warn_days', '30');
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609240002_dormancy_policy_settings','Seed Dormancy Policy disable-days and warn-days Platform Settings (ticket #60)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609240003_user_disabled_at: Record when a user account transitions to Disabled (ticket #61)
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='disabled_at'), 'ALTER TABLE `users` ADD COLUMN `disabled_at` DATETIME NULL AFTER `is_recovery_account`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609240003_user_disabled_at','Record when a user account transitions to Disabled (ticket #61)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609250001_user_multi_roles: Allow users to hold multiple role templates
CREATE TABLE IF NOT EXISTS user_roles (
                    user_id INT NOT NULL,
                    role_id INT NOT NULL,
                    is_primary TINYINT(1) NOT NULL DEFAULT 0,
                    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, role_id),
                    KEY idx_user_roles_role (role_id),
                    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO user_roles (user_id, role_id, is_primary)
             SELECT user_id, role_id, 1 FROM users WHERE role_id IS NOT NULL;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609250001_user_multi_roles','Allow users to hold multiple role templates') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609260001_shared_database_backups: Shared encrypted Database Backup coordination for Administrator and Super Administrator
CREATE TABLE IF NOT EXISTS store_write_gate (
                    gate_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    paused_at DATETIME NULL,
                    paused_by INT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO store_write_gate (gate_key) VALUES ('store_writes');
CREATE TABLE IF NOT EXISTS backup_operations (
                    operation_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    active_slot TINYINT NULL DEFAULT 1,
                    state ENUM('capturing','completed','failed','abandoned') NOT NULL DEFAULT 'capturing',
                    requested_by INT NULL,
                    requested_by_role VARCHAR(32) NULL,
                    artifact_token VARCHAR(64) NULL,
                    artifact_path VARCHAR(255) NULL,
                    filename VARCHAR(255) NULL,
                    file_size BIGINT NULL,
                    envelope_version VARCHAR(16) NULL,
                    cipher VARCHAR(64) NULL,
                    snapshot_at DATETIME NULL,
                    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    heartbeat_at DATETIME NULL,
                    finished_at DATETIME NULL,
                    detail TEXT NULL,
                    UNIQUE KEY uq_backup_operations_active (active_slot),
                    KEY idx_backup_operations_started (started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='backup_history' AND column_name='snapshot_at'), 'ALTER TABLE `backup_history` ADD COLUMN `snapshot_at` DATETIME NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='backup_history' AND column_name='envelope_version'), 'ALTER TABLE `backup_history` ADD COLUMN `envelope_version` VARCHAR(16) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='backup_history' AND column_name='cipher'), 'ALTER TABLE `backup_history` ADD COLUMN `cipher` VARCHAR(64) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='backup_history' AND column_name='requested_by_role'), 'ALTER TABLE `backup_history` ADD COLUMN `requested_by_role` VARCHAR(32) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
CREATE TABLE IF NOT EXISTS restore_preserved_activity LIKE activity_log;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609260001_shared_database_backups','Shared encrypted Database Backup coordination for Administrator and Super Administrator') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609290001_store_registers: Named physical Registers maintained by Administrators
CREATE TABLE IF NOT EXISTS registers (
                register_id INT NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                status ENUM('active','disabled') NOT NULL DEFAULT 'active',
                paper_width_mm ENUM('80','58') NOT NULL DEFAULT '80',
                disabled_at DATETIME NULL,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (register_id),
                UNIQUE KEY register_name (name),
                KEY idx_registers_status (status),
                KEY fk_registers_created_by (created_by),
                CONSTRAINT fk_registers_created_by
                    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290001_store_registers','Named physical Registers maintained by Administrators') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609290002_cashier_shift_registers: Bind each open Cashier Shift to an exclusively owned Register (ticket #88)
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='register_id'), 'ALTER TABLE `cashier_shifts` ADD COLUMN `register_id` INT NULL AFTER `cashier_id`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND index_name='idx_cashier_shifts_register'), 'ALTER TABLE `cashier_shifts` ADD INDEX `idx_cashier_shifts_register` (`register_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='open_cashier_id'), 'ALTER TABLE `cashier_shifts` ADD COLUMN `open_cashier_id` INT GENERATED ALWAYS AS (IF(status = ''open'', cashier_id, NULL)) STORED', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='open_register_id'), 'ALTER TABLE `cashier_shifts` ADD COLUMN `open_register_id` INT GENERATED ALWAYS AS (IF(status = ''open'', register_id, NULL)) STORED', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND index_name='uq_cashier_shifts_open_cashier'), 'ALTER TABLE `cashier_shifts` ADD UNIQUE KEY `uq_cashier_shifts_open_cashier` (`open_cashier_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND index_name='uq_cashier_shifts_open_register'), 'ALTER TABLE `cashier_shifts` ADD UNIQUE KEY `uq_cashier_shifts_open_register` (`open_register_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='register_id' AND referenced_table_name='registers' AND referenced_column_name='register_id'), 'ALTER TABLE `cashier_shifts` ADD CONSTRAINT `fk_cashier_shifts_register` FOREIGN KEY (`register_id`) REFERENCES `registers` (`register_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290002_cashier_shift_registers','Bind each open Cashier Shift to an exclusively owned Register (ticket #88)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609290003_sale_shift_attribution: Attribute each new sale to the Cashier and Cashier Shift that authorized it (ticket #89)
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sales' AND column_name='shift_id'), 'ALTER TABLE `sales` ADD COLUMN `shift_id` INT NULL AFTER `cashier_id`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='sales' AND index_name='idx_sales_shift'), 'ALTER TABLE `sales` ADD INDEX `idx_sales_shift` (`shift_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='sales' AND column_name='shift_id' AND referenced_table_name='cashier_shifts' AND referenced_column_name='shift_id'), 'ALTER TABLE `sales` ADD CONSTRAINT `fk_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290003_sale_shift_attribution','Attribute each new sale to the Cashier and Cashier Shift that authorized it (ticket #89)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609290004_cashier_shift_register_lock: Let a Cashier lock an open Cashier Shift and resume it with their own password (ticket #90)
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='locked_at'), 'ALTER TABLE `cashier_shifts` ADD COLUMN `locked_at` TIMESTAMP NULL DEFAULT NULL AFTER `reviewed_at`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290004_cashier_shift_register_lock','Let a Cashier lock an open Cashier Shift and resume it with their own password (ticket #90)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609290005_held_sale_shift_ownership: Keep held sales inside the Cashier Shift that authorized them (ticket #91)
ALTER TABLE `held_sales` MODIFY COLUMN `status` enum('held','resumed','cancelled','discarded','completed','expired') NOT NULL DEFAULT 'held';
UPDATE `held_sales` SET `status` = 'discarded' WHERE `status` = 'cancelled';
ALTER TABLE `held_sales` MODIFY COLUMN `status` enum('held','resumed','discarded','completed','expired') NOT NULL DEFAULT 'held';
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='held_sales' AND column_name='discard_reason'), 'ALTER TABLE `held_sales` ADD COLUMN `discard_reason` VARCHAR(50) NULL AFTER `resolved_at`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='held_sales' AND column_name='discard_note'), 'ALTER TABLE `held_sales` ADD COLUMN `discard_note` VARCHAR(255) NULL AFTER `discard_reason`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='held_sales' AND column_name='discarded_by'), 'ALTER TABLE `held_sales` ADD COLUMN `discarded_by` INT NULL AFTER `discard_note`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='held_sales' AND column_name='sale_id'), 'ALTER TABLE `held_sales` ADD COLUMN `sale_id` INT NULL AFTER `resolved_at`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='held_sales' AND column_name='sale_id' AND referenced_table_name='sales' AND referenced_column_name='sale_id'), 'ALTER TABLE `held_sales` ADD CONSTRAINT `fk_held_sales_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='held_sales' AND column_name='discarded_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `held_sales` ADD CONSTRAINT `fk_held_sales_discarded_by` FOREIGN KEY (`discarded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_fk = (SELECT rc.CONSTRAINT_NAME FROM information_schema.referential_constraints rc JOIN information_schema.key_column_usage k ON k.constraint_schema=rc.constraint_schema AND k.table_name=rc.table_name AND k.constraint_name=rc.constraint_name WHERE rc.constraint_schema=DATABASE() AND rc.table_name='held_sales' AND k.column_name='shift_id' AND rc.delete_rule='SET NULL' LIMIT 1);
SET @rm_sql = IF(@rm_fk IS NULL, 'DO 0', CONCAT('ALTER TABLE `held_sales` DROP FOREIGN KEY `', REPLACE(@rm_fk, '`', '``'), '`'));
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='held_sales' AND column_name='shift_id' AND referenced_table_name='cashier_shifts' AND referenced_column_name='shift_id'), 'ALTER TABLE `held_sales` ADD CONSTRAINT `fk_held_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='held_sales' AND index_name='idx_held_sales_shift_status'), 'ALTER TABLE `held_sales` ADD INDEX `idx_held_sales_shift_status` (`shift_id`, `status`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='held_sales' AND index_name='shift_id'), 'ALTER TABLE held_sales DROP INDEX `shift_id`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290005_held_sale_shift_ownership','Keep held sales inside the Cashier Shift that authorized them (ticket #91)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609290006_append_only_cash_refunds: Record append-only Cash Refunds against completed sales (ticket #92)
CREATE TABLE IF NOT EXISTS cash_refunds (
                refund_id INT NOT NULL AUTO_INCREMENT,
                sale_id INT NOT NULL,
                shift_id INT NOT NULL,
                cashier_id INT NOT NULL,
                refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                payment_method ENUM('cash','card','ewallet') NOT NULL DEFAULT 'cash',
                reason VARCHAR(50) NOT NULL,
                note VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (refund_id),
                KEY idx_cash_refunds_sale (sale_id),
                KEY idx_cash_refunds_shift (shift_id),
                KEY idx_cash_refunds_cashier (cashier_id),
                CONSTRAINT fk_cash_refunds_sale
                    FOREIGN KEY (sale_id) REFERENCES sales(sale_id) ON DELETE RESTRICT,
                CONSTRAINT fk_cash_refunds_shift
                    FOREIGN KEY (shift_id) REFERENCES cashier_shifts(shift_id) ON DELETE RESTRICT,
                CONSTRAINT fk_cash_refunds_cashier
                    FOREIGN KEY (cashier_id) REFERENCES users(user_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cash_refund_items (
                refund_item_id INT NOT NULL AUTO_INCREMENT,
                refund_id INT NOT NULL,
                sale_item_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                disposition ENUM('restockable','damaged') NOT NULL DEFAULT 'restockable',
                PRIMARY KEY (refund_item_id),
                UNIQUE KEY uq_cash_refund_items_refund_line (refund_id, sale_item_id),
                KEY idx_cash_refund_items_sale_item (sale_item_id),
                KEY idx_cash_refund_items_product (product_id),
                CONSTRAINT fk_cash_refund_items_refund
                    FOREIGN KEY (refund_id) REFERENCES cash_refunds(refund_id) ON DELETE RESTRICT,
                CONSTRAINT fk_cash_refund_items_sale_item
                    FOREIGN KEY (sale_item_id) REFERENCES sale_items(sale_item_id) ON DELETE RESTRICT,
                CONSTRAINT fk_cash_refund_items_product
                    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cash_refunds' AND index_name='idx_cash_refunds_sale'), 'ALTER TABLE `cash_refunds` ADD INDEX `idx_cash_refunds_sale` (`sale_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cash_refunds' AND index_name='idx_cash_refunds_shift'), 'ALTER TABLE `cash_refunds` ADD INDEX `idx_cash_refunds_shift` (`shift_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cash_refunds' AND index_name='idx_cash_refunds_cashier'), 'ALTER TABLE `cash_refunds` ADD INDEX `idx_cash_refunds_cashier` (`cashier_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cash_refund_items' AND index_name='idx_cash_refund_items_sale_item'), 'ALTER TABLE `cash_refund_items` ADD INDEX `idx_cash_refund_items_sale_item` (`sale_item_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cash_refund_items' AND index_name='idx_cash_refund_items_product'), 'ALTER TABLE `cash_refund_items` ADD INDEX `idx_cash_refund_items_product` (`product_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cash_refund_items' AND index_name='uq_cash_refund_items_refund_line'), 'ALTER TABLE `cash_refund_items` ADD UNIQUE KEY `uq_cash_refund_items_refund_line` (`refund_id`, `sale_item_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_refunds' AND column_name='sale_id' AND referenced_table_name='sales' AND referenced_column_name='sale_id'), 'ALTER TABLE `cash_refunds` ADD CONSTRAINT `fk_cash_refunds_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_refunds' AND column_name='shift_id' AND referenced_table_name='cashier_shifts' AND referenced_column_name='shift_id'), 'ALTER TABLE `cash_refunds` ADD CONSTRAINT `fk_cash_refunds_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_refunds' AND column_name='cashier_id' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cash_refunds` ADD CONSTRAINT `fk_cash_refunds_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_refund_items' AND column_name='refund_id' AND referenced_table_name='cash_refunds' AND referenced_column_name='refund_id'), 'ALTER TABLE `cash_refund_items` ADD CONSTRAINT `fk_cash_refund_items_refund` FOREIGN KEY (`refund_id`) REFERENCES `cash_refunds` (`refund_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_refund_items' AND column_name='sale_item_id' AND referenced_table_name='sale_items' AND referenced_column_name='sale_item_id'), 'ALTER TABLE `cash_refund_items` ADD CONSTRAINT `fk_cash_refund_items_sale_item` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`sale_item_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_refund_items' AND column_name='product_id' AND referenced_table_name='products' AND referenced_column_name='product_id'), 'ALTER TABLE `cash_refund_items` ADD CONSTRAINT `fk_cash_refund_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609290006_append_only_cash_refunds','Record append-only Cash Refunds against completed sales (ticket #92)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609300001_accountable_drawer_movements: Record accountable Cashier drawer movements (ticket #94)
ALTER TABLE cash_drawer_movements MODIFY COLUMN movement_type
            ENUM('pay_in','pay_out','cash_in','cash_out','safe_drop') NOT NULL;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_drawer_movements' AND column_name='note'), 'ALTER TABLE `cash_drawer_movements` ADD COLUMN `note` VARCHAR(255) NULL AFTER `reason`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_drawer_movements' AND column_name='cashier_id'), 'ALTER TABLE `cash_drawer_movements` ADD COLUMN `cashier_id` INT NULL AFTER `note`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
UPDATE cash_drawer_movements cdm
            JOIN cashier_shifts cs ON cs.shift_id = cdm.shift_id
            SET cdm.cashier_id = cs.cashier_id WHERE cdm.cashier_id IS NULL;
ALTER TABLE cash_drawer_movements MODIFY COLUMN cashier_id INT NOT NULL;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cash_drawer_movements' AND index_name='idx_drawer_movements_cashier'), 'ALTER TABLE `cash_drawer_movements` ADD INDEX `idx_drawer_movements_cashier` (`cashier_id`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_drawer_movements' AND column_name='cashier_id' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cash_drawer_movements` ADD CONSTRAINT `fk_drawer_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300001_accountable_drawer_movements','Record accountable Cashier drawer movements (ticket #94)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609300001_sale_receipt_details: Preserve customer-facing Sale Receipt details at checkout
CREATE TABLE IF NOT EXISTS sale_receipt_details (
            sale_id INT NOT NULL PRIMARY KEY,
            details_json LONGTEXT NOT NULL,
            CONSTRAINT fk_sale_receipt_details_sale FOREIGN KEY (sale_id)
                REFERENCES sales (sale_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300001_sale_receipt_details','Preserve customer-facing Sale Receipt details at checkout') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609300002_cashier_shift_reconciliation: Preserve Cashier Shift reconciliation and review status (ticket #95)
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='variance_threshold'), 'ALTER TABLE `cashier_shifts` ADD COLUMN `variance_threshold` DECIMAL(12,2) NULL AFTER `cash_variance`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='variance_review_required'), 'ALTER TABLE `cashier_shifts` ADD COLUMN `variance_review_required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `variance_threshold`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='payment_totals'), 'ALTER TABLE `cashier_shifts` ADD COLUMN `payment_totals` TEXT NULL AFTER `variance_review_required`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND index_name='idx_cashier_shifts_variance_review'), 'ALTER TABLE `cashier_shifts` ADD INDEX `idx_cashier_shifts_variance_review` (`variance_review_required`, `status`, `closed_at`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300002_cashier_shift_reconciliation','Preserve Cashier Shift reconciliation and review status (ticket #95)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609300003_administrator_shift_intervention: Preserve the closing actor and intervention reason for Cashier Shifts (ticket #96)
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='closed_by'), 'ALTER TABLE `cashier_shifts` ADD COLUMN `closed_by` INT NULL AFTER `closing_notes`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='intervention_reason'), 'ALTER TABLE `cashier_shifts` ADD COLUMN `intervention_reason` TEXT NULL AFTER `closed_by`', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cashier_shifts' AND index_name='idx_cashier_shifts_closed_by'), 'ALTER TABLE `cashier_shifts` ADD INDEX `idx_cashier_shifts_closed_by` (`closed_by`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cashier_shifts' AND column_name='closed_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cashier_shifts` ADD CONSTRAINT `fk_cashier_shifts_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300003_administrator_shift_intervention','Preserve the closing actor and intervention reason for Cashier Shifts (ticket #96)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609300003_refund_receipt_details: Preserve customer-facing Refund Receipt details atomically
CREATE TABLE IF NOT EXISTS refund_receipt_details (
            refund_id INT NOT NULL PRIMARY KEY,
            details_json LONGTEXT NOT NULL,
            CONSTRAINT fk_refund_receipt_details_refund FOREIGN KEY (refund_id)
                REFERENCES cash_refunds (refund_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300003_refund_receipt_details','Preserve customer-facing Refund Receipt details atomically') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609300004_legacy_register_columns: Upgrade existing Register columns to the current Cashier schema
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='registers' AND column_name='name'), 'ALTER TABLE registers ADD COLUMN name VARCHAR(100) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='registers' AND column_name='register_name'), 'UPDATE registers SET name=register_name WHERE name IS NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
ALTER TABLE registers MODIFY COLUMN name VARCHAR(100) NOT NULL;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='registers' AND column_name='status'), 'ALTER TABLE registers ADD COLUMN status ENUM(''active'',''disabled'') NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='registers' AND column_name='is_enabled'), 'UPDATE registers SET status=IF(is_enabled=1,''active'',''disabled'') WHERE status IS NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
ALTER TABLE registers MODIFY COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active';
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='registers' AND column_name='disabled_at'), 'ALTER TABLE `registers` ADD COLUMN `disabled_at` DATETIME NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='registers' AND column_name='register_code'), 'ALTER TABLE registers MODIFY COLUMN register_code VARCHAR(30) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='registers' AND column_name='register_name'), 'ALTER TABLE registers MODIFY COLUMN register_name VARCHAR(100) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='registers' AND index_name='register_name') AND NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='registers' AND index_name='register_name_current'), 'ALTER TABLE registers ADD UNIQUE KEY register_name_current (`name`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='registers' AND index_name='idx_registers_status'), 'ALTER TABLE `registers` ADD INDEX `idx_registers_status` (`status`)', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300004_legacy_register_columns','Upgrade existing Register columns to the current Cashier schema') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202609300005_register_paper_width: Configure thermal receipt paper width per Register
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='registers' AND column_name='paper_width_mm'), 'ALTER TABLE `registers` ADD COLUMN `paper_width_mm` ENUM(''80'',''58'') NOT NULL DEFAULT ''80''', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202609300005_register_paper_width','Configure thermal receipt paper width per Register') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202610010001_checkout_attempts: Persist Cashier checkout identities with committed sale outcomes
CREATE TABLE IF NOT EXISTS checkout_attempts (
            cashier_id INT NOT NULL,
            attempt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            sale_id INT NOT NULL,
            result_json LONGTEXT NOT NULL,
            PRIMARY KEY (cashier_id, attempt_id),
            UNIQUE KEY uq_checkout_attempt_sale (sale_id),
            CONSTRAINT fk_checkout_attempt_cashier FOREIGN KEY (cashier_id) REFERENCES users (user_id) ON DELETE RESTRICT,
            CONSTRAINT fk_checkout_attempt_sale FOREIGN KEY (sale_id) REFERENCES sales (sale_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610010001_checkout_attempts','Persist Cashier checkout identities with committed sale outcomes') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202610010003_safe_refund_exceptions: Preserve external settlement and specific Cash Refund exception attribution (#113)
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_refunds' AND column_name='original_cashier_id'), 'ALTER TABLE `cash_refunds` ADD COLUMN `original_cashier_id` INT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_refunds' AND column_name='approved_by'), 'ALTER TABLE `cash_refunds` ADD COLUMN `approved_by` INT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_refunds' AND column_name='exception_reason'), 'ALTER TABLE `cash_refunds` ADD COLUMN `exception_reason` VARCHAR(255) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_refunds' AND column_name='payment_reference'), 'ALTER TABLE `cash_refunds` ADD COLUMN `payment_reference` VARCHAR(100) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_refunds' AND column_name='original_cashier_id' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cash_refunds` ADD CONSTRAINT `fk_cash_refunds_original_cashier_id` FOREIGN KEY (`original_cashier_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage WHERE constraint_schema=DATABASE() AND table_name='cash_refunds' AND column_name='approved_by' AND referenced_table_name='users' AND referenced_column_name='user_id'), 'ALTER TABLE `cash_refunds` ADD CONSTRAINT `fk_cash_refunds_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
CREATE TABLE IF NOT EXISTS cash_refund_exception_access (
                access_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                token_hash CHAR(64) NOT NULL,
                sale_id INT NOT NULL, cashier_id INT NOT NULL, shift_id INT NOT NULL,
                approved_by INT NOT NULL, exception_reason VARCHAR(255) NOT NULL,
                expires_at BIGINT NOT NULL, used_refund_id INT NULL,
                UNIQUE KEY uq_refund_exception_token (token_hash),
                FOREIGN KEY (sale_id) REFERENCES sales(sale_id) ON DELETE RESTRICT,
                FOREIGN KEY (cashier_id) REFERENCES users(user_id) ON DELETE RESTRICT,
                FOREIGN KEY (shift_id) REFERENCES cashier_shifts(shift_id) ON DELETE RESTRICT,
                FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE RESTRICT,
                FOREIGN KEY (used_refund_id) REFERENCES cash_refunds(refund_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610010003_safe_refund_exceptions','Preserve external settlement and specific Cash Refund exception attribution (#113)') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- 202610040001_user_theme: Personal display theme for every Staff account
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='theme_preference'), 'ALTER TABLE `users` ADD COLUMN `theme_preference` ENUM(''light'',''dark'',''system'') NOT NULL DEFAULT ''system''', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
INSERT INTO schema_migrations (migration_key,description) VALUES ('202610040001_user_theme','Personal display theme for every Staff account') ON DUPLICATE KEY UPDATE description=VALUES(description);

-- Runtime SQL dependencies: includes/pairing.php, includes/functions.php, demandForcasting/train_model.py.
CREATE TABLE IF NOT EXISTS `barcode_pairings` (
  `pairing_id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(12) NOT NULL,
  `created_by` int(11) NOT NULL,
  `status` enum('pending','connected','expired') NOT NULL DEFAULT 'pending',
  `device_label` varchar(120) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `connected_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `access_token_hash` varchar(255) DEFAULT NULL,
  `joined_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`pairing_id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_barcode_pairings_code` (`code`),
  KEY `idx_barcode_pairings_expires_at` (`expires_at`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `barcode_pairings_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `barcode_scans` (
  `scan_id` int(11) NOT NULL AUTO_INCREMENT,
  `pairing_id` int(11) NOT NULL,
  `barcode` varchar(255) NOT NULL,
  `payload` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`scan_id`),
  KEY `idx_barcode_scans_pairing_scan` (`pairing_id`,`scan_id`),
  CONSTRAINT `barcode_scans_ibfk_1` FOREIGN KEY (`pairing_id`) REFERENCES `barcode_pairings` (`pairing_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `email_delivery_log` (
  `email_log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `recipient` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('sent','failed','queued') NOT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`email_log_id`),
  KEY `idx_email_delivery_log_created_at` (`created_at`),
  KEY `idx_email_delivery_log_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `store_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `store_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `ml_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `ml_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `model_training_runs` (
  `training_run_id` int(11) NOT NULL AUTO_INCREMENT,
  `model_name` varchar(100) NOT NULL,
  `model_version` varchar(30) NOT NULL,
  `trigger_type` enum('manual','scheduled','automatic','cli') NOT NULL DEFAULT 'cli',
  `status` enum('running','completed','failed','skipped') NOT NULL DEFAULT 'running',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` decimal(10,2) DEFAULT NULL,
  `sales_records_used` int(11) DEFAULT 0,
  `eligible_products` int(11) DEFAULT 0,
  `metrics_json` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `host_name` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`training_run_id`),
  KEY `idx_model_training_runs_started_at` (`started_at`),
  KEY `idx_model_training_runs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `forecast_evaluations` (
  `evaluation_id` int(11) NOT NULL AUTO_INCREMENT,
  `training_run_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `evaluation_records` int(11) NOT NULL DEFAULT 0,
  `actual_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `predicted_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `mae` decimal(14,4) DEFAULT NULL,
  `rmse` decimal(14,4) DEFAULT NULL,
  `wape` decimal(10,4) DEFAULT NULL,
  `smape` decimal(10,4) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`evaluation_id`),
  KEY `idx_forecast_evaluations_product` (`product_id`,`generated_at`),
  KEY `training_run_id` (`training_run_id`),
  CONSTRAINT `forecast_evaluations_ibfk_1` FOREIGN KEY (`training_run_id`) REFERENCES `model_training_runs` (`training_run_id`) ON DELETE SET NULL,
  CONSTRAINT `forecast_evaluations_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='barcode_pairings' AND column_name='access_token_hash'), 'ALTER TABLE `barcode_pairings` ADD COLUMN `access_token_hash` VARCHAR(255) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='barcode_pairings' AND column_name='joined_ip'), 'ALTER TABLE `barcode_pairings` ADD COLUMN `joined_ip` VARCHAR(45) NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_predictions' AND column_name='lower_bound_7_days'), 'ALTER TABLE `stock_predictions` ADD COLUMN `lower_bound_7_days` INT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_predictions' AND column_name='upper_bound_7_days'), 'ALTER TABLE `stock_predictions` ADD COLUMN `upper_bound_7_days` INT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_predictions' AND column_name='lower_bound_30_days'), 'ALTER TABLE `stock_predictions` ADD COLUMN `lower_bound_30_days` INT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_predictions' AND column_name='upper_bound_30_days'), 'ALTER TABLE `stock_predictions` ADD COLUMN `upper_bound_30_days` INT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_predictions' AND column_name='lead_time_lower_bound'), 'ALTER TABLE `stock_predictions` ADD COLUMN `lead_time_lower_bound` INT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_predictions' AND column_name='lead_time_upper_bound'), 'ALTER TABLE `stock_predictions` ADD COLUMN `lead_time_upper_bound` INT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
SET @rm_sql = IF(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_predictions' AND column_name='forecast_explanation'), 'ALTER TABLE `stock_predictions` ADD COLUMN `forecast_explanation` TEXT NULL', 'DO 0');
PREPARE rm_stmt FROM @rm_sql;
EXECUTE rm_stmt;
DEALLOCATE PREPARE rm_stmt;
-- Forecast defaults; retain any configured values.
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('minimum_history_days','30');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('preferred_history_days','90');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('minimum_nonzero_sales_days','5');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('history_window_days','365');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('forecast_period_days','30');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('n_estimators','300');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('max_depth','18');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('min_samples_split','4');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('min_samples_leaf','2');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('retrain_frequency_days','7');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('retrain_new_sales_records','100');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('accuracy_threshold_wape','35.0');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('prediction_interval_lower','10');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('prediction_interval_upper','90');
INSERT IGNORE INTO ml_settings (setting_key,setting_value) VALUES ('holiday_dates','');

SET SESSION time_zone = @rm_old_time_zone;
SET SESSION sql_mode = @rm_old_sql_mode;
SELECT 'RetailMind SQL update completed through 202610040001_user_theme' AS result;
