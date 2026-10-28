<?php
// Idempotent schema upgrades for operational features added after the base release.

function table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        foreach ($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['name']] = true;
        }
        return $columns;
    }
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

    // Ticket #88. An open Cashier Shift belongs to exactly one Cashier and
    // exactly one Register. MySQL has no filtered unique index, so each rule is
    // a generated column that is non-NULL only while the shift is open, carried
    // by a UNIQUE key; closed history is therefore never constrained. The
    // Register foreign key is added by migration 202609290002 rather than here,
    // because `registers` may not exist yet when this idempotent upgrade runs.
    $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_shifts (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_drawer_movements (
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
        prediction_id INT NULL,
        forecast_job_id BIGINT UNSIGNED NULL,
        product_id INT NOT NULL,
        original_suggested_qty INT NOT NULL DEFAULT 0,
        final_quantity INT NOT NULL DEFAULT 0,
        decision ENUM('accepted','modified','rejected') NOT NULL,
        override_reason TEXT NULL,
        decided_by INT NOT NULL,
        decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_receiving_groups (
        group_id INT AUTO_INCREMENT PRIMARY KEY,
        received_by INT NOT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        supplier VARCHAR(150) NULL,
        invoice_number VARCHAR(100) NULL,
        notes TEXT NULL,
        INDEX idx_stock_receiving_groups_received_by (received_by),
        INDEX idx_stock_receiving_groups_received_at (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensure_table_column($pdo, 'stock_receiving', 'group_id', 'INT NULL AFTER receiving_id');
    ensure_table_column($pdo, 'stock_receiving', 'received_packages', 'INT NOT NULL DEFAULT 0 AFTER received_qty');
    ensure_table_column($pdo, 'stock_receiving', 'units_per_package_used', 'INT NOT NULL DEFAULT 1 AFTER received_packages');

    ensure_table_column($pdo, 'product_batches', 'cost_price', 'DECIMAL(10,2) NULL AFTER expiration_date');
    ensure_table_column($pdo, 'sale_item_batches', 'unit_cost', 'DECIMAL(10,2) NULL AFTER quantity');
    ensure_table_column($pdo, 'sale_item_batches', 'cost_price', 'DECIMAL(10,2) NULL AFTER unit_cost');
    ensure_table_column($pdo, 'sale_item_batches', 'cost_status', "ENUM('recorded','estimated','missing') NOT NULL DEFAULT 'missing' AFTER cost_price");
    ensure_table_column($pdo, 'sale_item_batches', 'cost_source', 'VARCHAR(50) NULL AFTER cost_status');
    ensure_table_column($pdo, 'sale_item_batches', 'cost_provenance', 'VARCHAR(50) NULL AFTER cost_source');

    $pdo->exec("CREATE TABLE IF NOT EXISTS gcash_service_sessions (
        session_id INT AUTO_INCREMENT PRIMARY KEY,
        shift_id INT NOT NULL,
        cashier_id INT NOT NULL,
        opening_service_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        opening_wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('open','closed') NOT NULL DEFAULT 'open',
        expected_service_cash DECIMAL(12,2) NULL,
        expected_wallet_balance DECIMAL(12,2) NULL,
        closing_service_cash DECIMAL(12,2) NULL,
        closing_wallet_balance DECIMAL(12,2) NULL,
        cash_variance DECIMAL(12,2) NULL,
        wallet_variance DECIMAL(12,2) NULL,
        closing_notes TEXT NULL,
        closed_at TIMESTAMP NULL,
        closed_by INT NULL,
        unresolved_carryover_count INT NOT NULL DEFAULT 0,
        waiver_notes TEXT NULL,
        waived_by INT NULL,
        waived_at TIMESTAMP NULL,
        reviewed_by INT NULL,
        reviewed_at TIMESTAMP NULL,
        review_notes TEXT NULL,
        review_draft_notes TEXT NULL,
        review_shortage DECIMAL(12,2) NULL,
        review_history TEXT NULL,
        cash_check_explanation TEXT NULL,
        wallet_check_explanation TEXT NULL,
        is_open INT GENERATED ALWAYS AS (IF(status = 'open', 1, NULL)) STORED,
        UNIQUE KEY uq_gcash_open_session (is_open),
        INDEX idx_gcash_sessions_shift (shift_id),
        INDEX idx_gcash_sessions_cashier (cashier_id),
        INDEX idx_gcash_sessions_closed_by (closed_by),
        INDEX idx_gcash_sessions_waived_by (waived_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensure_table_column($pdo, 'gcash_service_sessions', 'waiver_notes', 'TEXT NULL AFTER unresolved_carryover_count');
    ensure_table_column($pdo, 'gcash_service_sessions', 'waived_by', 'INT NULL AFTER waiver_notes');
    ensure_table_column($pdo, 'gcash_service_sessions', 'waived_at', 'TIMESTAMP NULL AFTER waived_by');
    ensure_table_column($pdo, 'gcash_service_sessions', 'reviewed_by', 'INT NULL AFTER waived_at');
    ensure_table_column($pdo, 'gcash_service_sessions', 'reviewed_at', 'TIMESTAMP NULL AFTER reviewed_by');
    ensure_table_column($pdo, 'gcash_service_sessions', 'review_notes', 'TEXT NULL AFTER reviewed_at');
    ensure_table_column($pdo, 'gcash_service_sessions', 'review_draft_notes', 'TEXT NULL AFTER review_notes');
    ensure_table_column($pdo, 'gcash_service_sessions', 'review_shortage', 'DECIMAL(12,2) NULL AFTER review_draft_notes');
    ensure_table_column($pdo, 'gcash_service_sessions', 'review_history', 'TEXT NULL AFTER review_shortage');
    ensure_table_column($pdo, 'gcash_service_sessions', 'cash_check_explanation', 'TEXT NULL AFTER review_history');
    ensure_table_column($pdo, 'gcash_service_sessions', 'wallet_check_explanation', 'TEXT NULL AFTER cash_check_explanation');

    $pdo->exec("CREATE TABLE IF NOT EXISTS gcash_funding_movements (
        movement_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        movement_type ENUM('replenishment','withdrawal','product_drawer_transfer','owner_capital') NOT NULL,
        fund_type ENUM('cash','wallet') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        direction ENUM('in','out') NOT NULL,
        evidence_reference VARCHAR(120) NULL,
        reason VARCHAR(255) NOT NULL,
        authorized_by INT NOT NULL,
        recorded_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_gcash_funding_session (session_id),
        INDEX idx_gcash_funding_auth (authorized_by),
        INDEX idx_gcash_funding_rec (recorded_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS gcash_adjustments (
        adjustment_id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id BIGINT UNSIGNED NOT NULL,
        session_id INT NOT NULL,
        service_cash_delta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        wallet_delta DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        reason VARCHAR(255) NOT NULL,
        authorized_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_gcash_adj_tx (transaction_id),
        INDEX idx_gcash_adj_session (session_id),
        INDEX idx_gcash_adj_auth (authorized_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $gcashTableExists = false;
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $gcashTableExists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='gcash_transactions'")->fetchColumn();
        } else {
            $gcashTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'gcash_transactions'")->fetchColumn();
        }
    } catch (Throwable $e) {}
    if ($gcashTableExists) {
        ensure_table_column($pdo, 'gcash_transactions', 'session_id', 'INT NULL AFTER transaction_id');
        ensure_table_column($pdo, 'gcash_transactions', 'shift_id', 'INT NULL AFTER session_id');
        ensure_table_column($pdo, 'gcash_transactions', 'principal_amount', 'DECIMAL(12,2) NULL AFTER amount');
        ensure_table_column($pdo, 'gcash_transactions', 'fee_amount', 'DECIMAL(12,2) NULL AFTER principal_amount');
        ensure_table_column($pdo, 'gcash_transactions', 'fee_method', "ENUM('cash','wallet') NULL AFTER fee_amount");
        ensure_table_column($pdo, 'gcash_transactions', 'service_cash_delta', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER fee_method');
        ensure_table_column($pdo, 'gcash_transactions', 'wallet_delta', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER service_cash_delta');
        ensure_table_column($pdo, 'gcash_transactions', 'gross_fee', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER wallet_delta');
        ensure_table_column($pdo, 'gcash_transactions', 'confirmed_wallet', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER cashier_id');
        ensure_table_column($pdo, 'gcash_transactions', 'confirmed_cash', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER confirmed_wallet');
        ensure_table_column($pdo, 'gcash_transactions', 'notes', 'TEXT NULL AFTER confirmed_cash');
        ensure_table_column($pdo, 'gcash_transactions', 'completed_at', 'DATETIME NULL AFTER reviewed_at');
        if ($driver !== 'sqlite') {
            $pdo->exec("ALTER TABLE gcash_transactions
                MODIFY COLUMN reference_number VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
                MODIFY COLUMN verification_status ENUM('Pending','Verified','Rejected','Completed','Needs checking') NOT NULL DEFAULT 'Pending'");
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO schema_migrations (migration_key, description) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE description = VALUES(description)"
    );
    $stmt->execute(['2026_07_operational_updates', 'Suppliers, purchase orders, shifts, held sales, forecast decisions, units, and inventory insights']);

    $ensured = true;
}
