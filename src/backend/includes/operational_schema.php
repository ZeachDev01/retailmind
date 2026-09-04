<?php
// Idempotent schema upgrades for operational features added after the base release.

function table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }
    return $columns;
}

function ensure_table_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = table_columns($pdo, $table);
    if (!isset($columns[$column])) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function ensure_operational_updates_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(100) PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_products (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_shifts (
        shift_id INT AUTO_INCREMENT PRIMARY KEY,
        cashier_id INT NOT NULL,
        opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        opening_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('open','closed') NOT NULL DEFAULT 'open',
        closed_at TIMESTAMP NULL,
        expected_cash DECIMAL(12,2) NULL,
        actual_cash DECIMAL(12,2) NULL,
        cash_variance DECIMAL(12,2) NULL,
        closing_notes TEXT NULL,
        reviewed_by INT NULL,
        reviewed_at TIMESTAMP NULL,
        INDEX idx_cashier_shifts_cashier_status (cashier_id, status),
        INDEX idx_cashier_shifts_opened_at (opened_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_drawer_movements (
        drawer_movement_id INT AUTO_INCREMENT PRIMARY KEY,
        shift_id INT NOT NULL,
        movement_type ENUM('pay_in','pay_out') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        recorded_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_drawer_movements_shift (shift_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS held_sales (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS promotions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS forecast_decisions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cycle_count_schedules (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensure_table_column($pdo, 'sales', 'shift_id', 'INT NULL AFTER cashier_id');
    ensure_table_column($pdo, 'sales', 'gross_amount', 'DECIMAL(12,2) NULL AFTER total_amount');
    ensure_table_column($pdo, 'sales', 'discount_type', "ENUM('none','percentage','fixed') NOT NULL DEFAULT 'none' AFTER gross_amount");
    ensure_table_column($pdo, 'sales', 'discount_value', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_type');
    ensure_table_column($pdo, 'sales', 'discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_value');
    ensure_table_column($pdo, 'sales', 'discount_reason', 'VARCHAR(255) NULL AFTER discount_amount');
    ensure_table_column($pdo, 'sales', 'discount_authorized_by', 'INT NULL AFTER discount_reason');
    ensure_table_column($pdo, 'sales', 'promotion_id', 'INT NULL AFTER discount_authorized_by');
    ensure_table_column($pdo, 'sales', 'promotion_name', 'VARCHAR(150) NULL AFTER promotion_id');

    ensure_table_column($pdo, 'products', 'base_unit', "VARCHAR(40) NOT NULL DEFAULT 'piece' AFTER units_per_package");
    ensure_table_column($pdo, 'products', 'receiving_unit', "VARCHAR(40) NOT NULL DEFAULT 'package' AFTER base_unit");
    ensure_table_column($pdo, 'products', 'case_barcode', 'VARCHAR(80) NULL AFTER barcode');
    ensure_table_column($pdo, 'products', 'parent_product_id', 'INT NULL AFTER case_barcode');
    ensure_table_column($pdo, 'products', 'variant_label', 'VARCHAR(100) NULL AFTER parent_product_id');

    ensure_table_column($pdo, 'stock_receiving', 'purchase_order_id', 'INT NULL AFTER replenishment_request_id');
    ensure_table_column($pdo, 'stock_receiving', 'purchase_order_item_id', 'INT NULL AFTER purchase_order_id');
    ensure_table_column($pdo, 'stock_receiving', 'received_packages', 'INT NOT NULL DEFAULT 0 AFTER received_qty');
    ensure_table_column($pdo, 'stock_receiving', 'units_per_package_used', 'INT NOT NULL DEFAULT 1 AFTER received_packages');

    ensure_table_column($pdo, 'replenishment_requests', 'forecast_prediction_id', 'INT NULL AFTER source');
    ensure_table_column($pdo, 'replenishment_requests', 'original_suggested_qty', 'INT NULL AFTER forecast_prediction_id');
    ensure_table_column($pdo, 'replenishment_requests', 'override_reason', 'TEXT NULL AFTER original_suggested_qty');

    $stmt = $pdo->prepare(
        "INSERT INTO schema_migrations (migration_key, description) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE description = VALUES(description)"
    );
    $stmt->execute(['2026_07_operational_updates', 'Suppliers, purchase orders, shifts, held sales, forecast decisions, units, and inventory insights']);

    $ensured = true;
}
