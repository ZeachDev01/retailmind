<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../config/db.php';

echo "Starting clean slate reset...\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

$tablesToTruncate = [
    // Transactions & cashier/sales/refunds/GCash
    'cash_refund_items',
    'cash_refund_exception_access',
    'refund_receipt_details',
    'cash_refunds',
    'sale_reversal_items',
    'sale_reversals',
    'sale_item_batches',
    'sale_receipt_details',
    'sale_items',
    'sales',
    'held_sales',
    'checkout_attempts',
    'cash_drawer_movements',
    'cashier_shifts',
    'gcash_transactions',
    'gcash_funding_movements',
    'gcash_adjustments',
    'gcash_service_sessions',
    'barcode_scans',
    'barcode_pairings',

    // Products & Inventory
    'product_batches',
    'stock_issue_batches',
    'supplier_pullouts',
    'stock_movements',
    'stock_receiving',
    'stock_receiving_groups',
    'inventory_adjustment_revisions',
    'inventory_adjustments',
    'inventory_counts',
    'inventory_count_batches',
    'cycle_count_schedules',
    'purchase_history',
    'promotions',
    'scheduled_demand_drivers',
    'inventory',
    'products',
    'csv_import_logs',

    // Forecasting & ML runs
    'demand_forecasts',
    'forecast_decisions',
    'forecast_evaluations',
    'forecast_runs',
    'forecast_sales_imports',
    'ml_jobs',
    'ml_model_evaluations',
    'model_training_runs',
    'stock_predictions',

    // Logs & Notifications
    'activity_log',
    'attention_states',
    'notifications',
    'login_attempts',
    'password_reset_tokens',
    'backup_history',
    'backup_operations',
    'notification_preferences',
    'recovery_accounts',

    // Fiscal periods & calendar
    'fiscal_period_locks',
    'fiscal_periods',
    'store_calendar',
];

foreach ($tablesToTruncate as $table) {
    try {
        $pdo->exec("TRUNCATE TABLE `{$table}`");
        echo "Truncated {$table}\n";
    } catch (PDOException $e) {
        echo "Warning truncating {$table}: " . $e->getMessage() . "\n";
    }
}

// Re-seed open fiscal period for current testing
$pdo->prepare("INSERT INTO fiscal_periods (period_id, period_name, start_date, end_date, status, created_by, created_at) VALUES (1, 'FY2026', '2026-01-01', '2026-12-31', 'open', 1, NOW())")->execute();
echo "Created open fiscal period FY2026 (2026-01-01 to 2026-12-31)\n";

// Re-seed today's open store calendar
$pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_by, recorded_at) VALUES (CURDATE(), 'open', 1, NOW())")->execute();
echo "Initialized store calendar date as open\n";

// Reset store write gate
$pdo->exec("UPDATE store_write_gate SET paused_at = NULL, paused_by = NULL WHERE gate_key = 'store_writes'");
echo "Unpaused store write gate\n";

// Clean and seed users
$pdo->exec("DELETE FROM user_roles WHERE user_id <> 1");
$pdo->exec("DELETE FROM users WHERE user_id <> 1");
echo "Removed non-superadmin users\n";

$hash = password_hash('RetailMind@2026', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("
    INSERT INTO users (
        user_id, full_name, username, email, password_hash, role_id,
        status, failed_login_attempts, locked_until, session_version,
        must_change_password, branch_id, is_recovery_account, disabled_at, created_at
    ) VALUES (
        1, 'Super Admin', 'superadmin', 'superadmin@inventory.local', :hash, 1,
        'active', 0, NULL, 1,
        0, 1, 0, NULL, NOW()
    ) ON DUPLICATE KEY UPDATE
        full_name = 'Super Admin',
        username = 'superadmin',
        email = 'superadmin@inventory.local',
        password_hash = :hash_update,
        role_id = 1,
        status = 'active',
        failed_login_attempts = 0,
        locked_until = NULL,
        session_version = 1,
        must_change_password = 0,
        branch_id = 1,
        is_recovery_account = 0,
        disabled_at = NULL
");
$stmt->execute(['hash' => $hash, 'hash_update' => $hash]);

$pdo->exec("DELETE FROM user_roles WHERE user_id = 1");
$pdo->exec("
    INSERT INTO user_roles (user_id, role_id, is_primary, assigned_at) VALUES
    (1, 1, 1, NOW()),
    (1, 2, 0, NOW()),
    (1, 3, 0, NOW()),
    (1, 4, 0, NOW())
");
echo "Seeded superadmin with credentials 'superadmin' / 'RetailMind@2026' with full role access\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// Clean session files
$sessionFiles = glob(__DIR__ . '/../storage/sessions/sess_*');
if ($sessionFiles) {
    foreach ($sessionFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    echo "Purged " . count($sessionFiles) . " active session file(s)\n";
}

echo "Clean slate complete.\n";

