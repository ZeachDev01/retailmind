<?php
declare(strict_types=1);

/**
 * Ticket #453: Administrator Forecast Governance & Financial Reporting Acceptance Test.
 *
 * Verifies:
 * 1. Forecast governance: Model evaluation comparison (RMSE, MAE, WAPE vs baseline & incumbent),
 *    safe promotion and safe rollback guardrails.
 * 2. Store Shift reporting: Register sales, cash over/short tallies, payment methods breakdown,
 *    cashier performance summary across selected date ranges, non-jumping pagination & complete CSV export.
 * 3. Financial & Sales reporting: Cost of goods sold (COGS), gross profit, and margins computed
 *    without drift, with table pagination and non-jumping layout.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Forecasting/CandidateEvaluationService.php';
require_once __DIR__ . '/../app/Forecasting/StoreModelGovernanceService.php';
require_once __DIR__ . '/../app/Services/StoreShiftReportService.php';
require_once __DIR__ . '/../app/Services/GrossProfitService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\CandidateEvaluationService;
use App\Forecasting\StoreModelGovernanceService;
use App\Services\StoreShiftReportService;
use App\Services\GrossProfitService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "  [FAIL] {$message}\n";
    } else {
        echo "  [PASS] {$message}\n";
    }
};

echo "Testing Ticket #453: Administrator Forecast Governance & Financial Reporting...\n\n";

// =========================================================================
// SECTION 1: Forecasting Governance (RMSE, MAE, Baseline & Incumbent Comparison, Promotion & Rollback)
// =========================================================================
echo "--- Section 1: Forecasting Governance Interface & Model Comparison ---\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
    CREATE TABLE platform_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_by INT,
        updated_at DATETIME
    );
    CREATE TABLE ml_model_evaluations (
        evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
        model_version VARCHAR(100),
        horizon_days INT,
        mae DOUBLE,
        rmse DOUBLE,
        wape DOUBLE,
        baseline_mae DOUBLE,
        baseline_rmse DOUBLE,
        baseline_wape DOUBLE,
        candidate_gate_outcome VARCHAR(50),
        missing_metric_reason VARCHAR(255),
        data_window_start DATE,
        data_window_end DATE,
        created_at DATETIME
    );
    CREATE TABLE model_training_runs (
        training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
        model_version VARCHAR(100),
        status VARCHAR(50),
        metrics_json TEXT,
        completed_at DATETIME
    );
    CREATE TABLE demand_forecasts (
        forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INT,
        product_id INT,
        forecast_origin DATE,
        target_date DATE,
        horizon_day INT,
        predicted_units INT,
        model_version VARCHAR(100),
        readiness_level VARCHAR(50),
        limitation_marker VARCHAR(255)
    );
    CREATE TABLE roles (
        role_id INTEGER PRIMARY KEY,
        role_name VARCHAR(50)
    );
    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name VARCHAR(255),
        display_name VARCHAR(255),
        username VARCHAR(100),
        email VARCHAR(255),
        profile_image VARCHAR(255),
        status VARCHAR(50) DEFAULT 'active',
        session_version INT DEFAULT 0,
        is_recovery_account INT DEFAULT 0,
        last_login_at DATETIME,
        password_changed_at DATETIME,
        must_change_password INT DEFAULT 0,
        created_at DATETIME,
        role_id INT
    );
    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku VARCHAR(50),
        product_name VARCHAR(255),
        category_id INT,
        unit_price DECIMAL(10,2),
        cost_price DECIMAL(10,2),
        supplier_lead_time_days INT DEFAULT 7,
        minimum_order_quantity INT DEFAULT 1,
        status VARCHAR(20) DEFAULT 'active'
    );
    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_date DATETIME,
        status VARCHAR(20) DEFAULT 'completed'
    );
    CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INT,
        product_id INT,
        quantity INT,
        unit_price DECIMAL(10,2),
        total_price DECIMAL(10,2)
    );
    CREATE TABLE forecast_sales_imports (
        product_id INT,
        sale_date DATE,
        quantity INT,
        PRIMARY KEY (product_id, sale_date)
    );
    CREATE TABLE store_calendar (
        calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
        calendar_date DATE,
        status VARCHAR(50),
        notes TEXT,
        recorded_by INT,
        recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE stock_movements (
        movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INT,
        change_qty INT,
        reason VARCHAR(255),
        moved_at DATETIME
    );
    CREATE TABLE ml_jobs (
        job_id INTEGER PRIMARY KEY AUTOINCREMENT,
        trigger_type VARCHAR(50),
        status VARCHAR(50),
        requester_id INT,
        evidence_cutoff DATE,
        model_version VARCHAR(100),
        attempts INT DEFAULT 0,
        queued_at DATETIME,
        started_at DATETIME,
        completed_at DATETIME,
        lease_expires_at DATETIME,
        operator_message TEXT,
        diagnostic_reference VARCHAR(100)
    );
    CREATE TABLE protected_audit_records (
        record_id INTEGER PRIMARY KEY AUTOINCREMENT,
        record_uuid VARCHAR(64),
        event_time DATETIME,
        event_type VARCHAR(100),
        actor_user_id INT,
        actor_role VARCHAR(50),
        ip_address VARCHAR(45),
        details_json TEXT,
        previous_record_hash VARCHAR(64),
        record_hash VARCHAR(64)
    );
    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INT,
        action VARCHAR(255),
        category VARCHAR(100),
        module VARCHAR(100),
        record_id INT,
        previous_value TEXT,
        new_value TEXT,
        metadata TEXT,
        ip_address VARCHAR(50),
        created_at DATETIME
    );
");

require_once __DIR__ . '/support/governance_fixture.php';

$pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (2, 'admin')");
$pdo->exec("INSERT INTO users (user_id, full_name, username, email, role_id, status) VALUES (2, 'Local Administrator', 'admin', 'admin@store.local', 2, 'active')");
$pdo->exec("INSERT INTO products (product_id, sku, product_name, status) VALUES (1, 'BEV-COLA', 'Store Cola 500ml', 'active')");

// Configure governance thresholds and model versions
$govService = new StoreModelGovernanceService($pdo);
$govService->setThresholds(['ml_max_wape_7d' => 0.40, 'ml_max_wape_14d' => 0.45, 'ml_max_wape_28d' => 0.50], 2);

governance_fixture($pdo, $govService, ['model-v0', 'model-v1', 'model-v2']);

$pdo->exec("
    INSERT OR REPLACE INTO platform_settings (setting_key, setting_value) VALUES
        ('ml_approved_model_version', 'model-v1'),
        ('ml_previous_approved_model_version', 'model-v0');

    -- Incumbent evaluations (V1)
    INSERT INTO ml_model_evaluations (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, data_window_start, data_window_end)
    VALUES
        ('model-v1', 7, 2.5, 3.2, 0.25, 3.0, 4.0, 0.30, 'passed', '2026-08-01', '2026-08-28'),
        ('model-v1', 14, 3.0, 3.8, 0.30, 3.8, 4.8, 0.38, 'passed', '2026-08-01', '2026-08-28'),
        ('model-v1', 28, 3.5, 4.5, 0.35, 4.5, 5.8, 0.45, 'passed', '2026-08-01', '2026-08-28');

    -- Candidate evaluations (V2): better MAE, RMSE, and WAPE
    INSERT INTO ml_model_evaluations (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, data_window_start, data_window_end)
    VALUES
        ('model-v2', 7, 1.8, 2.4, 0.18, 3.0, 4.0, 0.30, 'passed', '2026-09-01', '2026-09-28'),
        ('model-v2', 14, 2.2, 2.9, 0.22, 3.8, 4.8, 0.38, 'passed', '2026-09-01', '2026-09-28'),
        ('model-v2', 28, 2.6, 3.4, 0.26, 4.5, 5.8, 0.45, 'passed', '2026-09-01', '2026-09-28');
");

// 28 observation days
$pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_at) VALUES (1, 1000, 'receive', '2026-09-01 07:00:00')");
for ($i = 1; $i <= 28; $i++) {
    $tDate = sprintf('2026-10-%02d', $i);
    $pdo->prepare("INSERT INTO store_calendar (calendar_date, status, recorded_at) VALUES (?, 'open', '2026-09-30 00:00:00')")->execute([$tDate]);
    $pdo->prepare("INSERT INTO demand_forecasts (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version) VALUES (15, 1, '2026-09-30', ?, ?, 10, ?)")->execute([$tDate, $i, 'model-v2']);
    $pdo->prepare("INSERT INTO sales (sale_id, sale_date, status) VALUES (?, ? || ' 10:00:00', 'completed')")->execute([5000 + $i, $tDate]);
    $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, 1, 8, 100.0, 800.0)")->execute([5000 + $i]);
}

$summary = $govService->getApprovalSummary('model-v2', '2026-10-01');

$assert(!empty($summary['has_candidate']), 'Candidate model is recognized');
$assert($summary['candidate_version'] === 'model-v2', 'Candidate version is model-v2');
$assert($summary['approved_version'] === 'model-v1', 'Approved version is model-v1');

// Check that horizons contain both RMSE and MAE for candidate and baseline
$h28 = $summary['candidate_quality']['horizons'][28] ?? [];
$assert(isset($h28['mae']) && (float)$h28['mae'] === 2.6, 'Candidate 28-day MAE is 2.6');
$assert(isset($h28['rmse']) && (float)$h28['rmse'] === 3.4, 'Candidate 28-day RMSE is 3.4');
$assert(isset($h28['baseline_mae']) && (float)$h28['baseline_mae'] === 4.5, 'Baseline 28-day MAE is 4.5');
$assert(isset($h28['baseline_rmse']) && (float)$h28['baseline_rmse'] === 5.8, 'Baseline 28-day RMSE is 5.8');

// Check incumbent comparison contains RMSE and MAE
$inc28 = $summary['incumbent_comparison']['horizons'][28] ?? [];
$assert($summary['incumbent_comparison']['available'] === true, 'Incumbent comparison is available against model-v1');
$assert(isset($inc28['mae']) && (float)$inc28['mae'] === 3.5, 'Incumbent 28-day MAE is 3.5');
$assert(isset($inc28['rmse']) && (float)$inc28['rmse'] === 4.5, 'Incumbent 28-day RMSE is 4.5');

// Test Model Review HTML output contains RMSE columns and metrics
ob_start();
$_SESSION['user_id'] = 2;
$_SESSION['role'] = 'admin';
$_SESSION['active_role'] = 'admin';
$_SESSION['session_version'] = 0;
$_SESSION['full_name'] = 'Test Local Admin';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['pdo'] = $pdo;

include __DIR__ . '/../../frontend/components/administrator/model_review.php';
$reviewHtml = ob_get_clean();

$assert(str_contains($reviewHtml, 'Candidate RMSE'), 'Model review table renders Candidate RMSE column header');
$assert(str_contains($reviewHtml, 'Baseline RMSE'), 'Model review table renders Baseline RMSE column header');
$assert(str_contains($reviewHtml, '3.40 units') || str_contains($reviewHtml, '3.4 units'), 'Candidate 28-day RMSE rendered in review');
$assert(str_contains($reviewHtml, '5.80 units') || str_contains($reviewHtml, '5.8 units'), 'Baseline 28-day RMSE rendered in review');
$assert(str_contains($reviewHtml, 'Compare with approved model'), 'Approved model comparison section rendered');

// Test Safe Model Promotion
$govService->activateStoreModel('model-v2', '2026-10-28', 2, 'Approved better RMSE/MAE model', 'admin', '127.0.0.1');
$statusAfterPromo = $govService->getActivationStatus();
$newApproved = $statusAfterPromo['approved_model_version'];
$newPrevious = $statusAfterPromo['previous_model_version'];
$assert($newApproved === 'model-v2', 'Safe promotion sets approved model to candidate model-v2');
$assert($newPrevious === 'model-v1', 'Safe promotion preserves model-v1 as previous model for rollback');

// Test Safe Model Rollback
$rollbackResult = $govService->rollbackStoreModel(2, 'Rolling back due to verification test', 'admin', '127.0.0.1');
$statusAfterRollback = $govService->getActivationStatus();
$assert($statusAfterRollback['approved_model_version'] === 'model-v1', 'Safe rollback restores previous model-v1');
$assert($rollbackResult['restored_model_version'] === 'model-v1', 'Rollback reports restored model-v1');

// =========================================================================
// SECTION 2: Shift Reports (Register Sales, Over/Short Tallies, Payment Methods, Cashier Performance, Pagination & CSV)
// =========================================================================
echo "\n--- Section 2: Shift Reports & Performance Summaries ---\n";

$shiftPdo = new PDO('sqlite::memory:');
$shiftPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$shiftPdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name VARCHAR(50));
    INSERT INTO roles VALUES (2, 'admin'), (3, 'cashier');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        display_name TEXT,
        full_name TEXT,
        username TEXT,
        email TEXT,
        profile_image TEXT,
        status TEXT DEFAULT 'active',
        session_version INT DEFAULT 0,
        is_recovery_account INT DEFAULT 0,
        last_login_at DATETIME,
        password_changed_at DATETIME,
        must_change_password INT DEFAULT 0,
        created_at DATETIME,
        role_id INT
    );
    INSERT INTO users (user_id, display_name, full_name, username, role_id) VALUES
        (1, 'Maria Santos', 'Maria Santos', 'maria', 3),
        (2, 'Juan Dela Cruz', 'Juan Dela Cruz', 'juan', 3),
        (3, 'Admin User', 'Admin User', 'admin', 2);

    CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT);
    INSERT INTO registers VALUES (10,'Register 1 - Front'),(11,'Register 2 - Express');

    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY,
        cashier_id INTEGER,
        register_id INTEGER,
        opened_at TEXT,
        closed_at TEXT,
        status TEXT,
        opening_cash REAL,
        expected_cash REAL,
        actual_cash REAL,
        cash_variance REAL,
        variance_review_required INTEGER,
        closing_notes TEXT,
        closed_by INTEGER,
        intervention_reason TEXT
    );
    INSERT INTO cashier_shifts VALUES
        (101, 1, 10, '2026-10-10 08:00:00', '2026-10-10 16:00:00', 'closed', 1000.00, 5500.00, 5450.00, -50.00, 1, 'Minor shortage', 3, NULL),
        (102, 2, 11, '2026-10-10 09:00:00', '2026-10-10 17:00:00', 'closed', 1000.00, 4200.00, 4220.00, 20.00, 0, 'Slight overage', 3, NULL),
        (103, 1, 10, '2026-10-11 08:00:00', '2026-10-11 16:00:00', 'closed', 1000.00, 6000.00, 6000.00, 0.00, 0, 'Exact balanced', 3, NULL),
        (104, 2, 11, '2026-10-11 09:00:00', NULL, 'open', 1000.00, NULL, NULL, NULL, 0, NULL, NULL, NULL);

    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY,
        cashier_id INTEGER,
        shift_id INTEGER,
        sale_date TEXT,
        total_amount REAL,
        payment_method TEXT,
        discount_amount REAL
    );
    INSERT INTO sales VALUES
        (1, 1, 101, '2026-10-10 10:00:00', 3000.00, 'cash', 0),
        (2, 1, 101, '2026-10-10 11:00:00', 1000.00, 'card', 0),
        (3, 1, 101, '2026-10-10 12:00:00', 500.00, 'ewallet', 0),
        (4, 2, 102, '2026-10-10 10:30:00', 2000.00, 'cash', 0),
        (5, 2, 102, '2026-10-10 11:30:00', 1200.00, 'ewallet', 0),
        (6, 1, 103, '2026-10-11 10:00:00', 4000.00, 'cash', 0),
        (7, 1, 103, '2026-10-11 14:00:00', 1000.00, 'card', 0),
        (8, 2, 104, '2026-10-11 11:00:00', 1500.00, 'cash', 0);

    CREATE TABLE cash_refunds (shift_id INTEGER, payment_method TEXT, refund_amount REAL);
    INSERT INTO cash_refunds VALUES (101, 'cash', 100.00);

    CREATE TABLE cash_drawer_movements (shift_id INTEGER, movement_type TEXT, amount REAL);
    INSERT INTO cash_drawer_movements VALUES
        (101, 'cash_in', 100.00),
        (101, 'safe_drop', 500.00);

    CREATE TABLE sale_reversals (sale_id INTEGER, requested_by INTEGER, status TEXT, settlement_method TEXT, refund_amount REAL, approved_at TEXT);
");

$shiftReportService = new StoreShiftReportService($shiftPdo);
$reportData = $shiftReportService->report('admin', ['from' => '2026-10-10', 'to' => '2026-10-11']);

$assert(count($reportData['rows']) === 4, 'Report contains 4 shifts across the 2-day date range');

// Over/short tallies calculation test
$overShort = StoreShiftReportService::summarizeOverShort($reportData['rows']);
$assert($overShort['total_over'] === 20.00, 'Total cash overage correctly summarized as PHP 20.00');
$assert($overShort['total_short'] === 50.00, 'Total cash shortage correctly summarized as PHP 50.00');
$assert($overShort['net_variance'] === -30.00, 'Net cash variance correctly calculated as -PHP 30.00');
$assert($overShort['balanced_count'] === 1, '1 shift was exactly balanced');
$assert($overShort['closed_shifts_count'] === 3, '3 closed shifts analyzed');

// Cashier performance summary test
$cashierPerf = StoreShiftReportService::summarizeCashierPerformance($reportData['rows']);
$assert(count($cashierPerf) === 2, 'Cashier performance summarizes both active cashiers');
$mariaPerf = array_values(array_filter($cashierPerf, static fn($c) => $c['cashier_name'] === 'Maria Santos'))[0] ?? [];
$assert($mariaPerf['shifts_count'] === 2, 'Maria Santos worked 2 shifts');
$assert($mariaPerf['net_variance'] === -50.00, 'Maria Santos net variance was -PHP 50.00');

// Register performance summary test
$registerPerf = StoreShiftReportService::summarizeRegisterPerformance($reportData['rows']);
$assert(count($registerPerf) === 2, 'Register performance summarizes both active registers');

// Test Shift Report rendering with Tallies & Cashier Performance
ob_start();
$_SESSION['user_id'] = 3;
$_SESSION['role'] = 'admin';
$_SESSION['active_role'] = 'admin';
$_SESSION['session_version'] = 0;
$_SESSION['full_name'] = 'Admin User';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['from' => '2026-10-10', 'to' => '2026-10-11'];
$pdo = $shiftPdo;

include __DIR__ . '/../../frontend/components/administrator/shift_report.php';
$shiftHtml = ob_get_clean();

$assert(str_contains($shiftHtml, 'Cash Over / Short Tallies'), 'Shift report renders Cash Over/Short tallies header');
$assert(str_contains($shiftHtml, '50.00'), 'Over/short tallies capture PHP 50.00 shortage');
$assert(str_contains($shiftHtml, '20.00'), 'Over/short tallies capture PHP 20.00 overage');
$assert(str_contains($shiftHtml, 'Cashier Performance Summary'), 'Shift report renders Cashier Performance section');
$assert(str_contains($shiftHtml, 'Maria Santos'), 'Cashier performance lists Maria Santos');
$assert(str_contains($shiftHtml, 'Juan Dela Cruz'), 'Cashier performance lists Juan Dela Cruz');
$assert(str_contains($shiftHtml, 'Register 1 - Front'), 'Register sales performance rendered');

// Test Pagination in Shift Report
$_GET = ['from' => '2026-10-10', 'to' => '2026-10-11', 'page' => '1', 'per_page' => '2'];
ob_start();
include __DIR__ . '/../../frontend/components/administrator/shift_report.php';
$pagedShiftHtml = ob_get_clean();

$assert(str_contains($pagedShiftHtml, 'Showing 1 to 2 of 4') || str_contains($pagedShiftHtml, 'Page 1 of 2'), 'Shift report table renders pagination controls');
$assert(str_contains($pagedShiftHtml, 'data-no-jumping="true"'), 'Shift table container uses stable layout container');

// Test CSV Export exports all matching rows, ignoring pagination slicing
$csvStream = fopen('php://temp', 'w+');
StoreShiftReportService::csv($csvStream, $reportData);
rewind($csvStream);
$csvLines = [];
while (($row = fgetcsv($csvStream)) !== false) {
    $csvLines[] = $row;
}
$assert(count($csvLines) === 6, 'Shift CSV exports headers, all 4 rows and 1 total row (6 rows total)');

// =========================================================================
// SECTION 3: Financial & Gross Profit Reporting (COGS, Margins, Pagination)
// =========================================================================
echo "\n--- Section 3: Financial & Gross Profit Reporting ---\n";

$gpPdo = new PDO('sqlite::memory:');
$gpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$gpPdo->exec("
    CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name VARCHAR(50));
    INSERT INTO roles VALUES (2, 'admin');

    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        display_name TEXT,
        full_name TEXT,
        username TEXT,
        email TEXT,
        profile_image TEXT,
        status TEXT DEFAULT 'active',
        session_version INT DEFAULT 0,
        is_recovery_account INT DEFAULT 0,
        last_login_at DATETIME,
        password_changed_at DATETIME,
        must_change_password INT DEFAULT 0,
        created_at DATETIME,
        role_id INT
    );
    INSERT INTO users (user_id, display_name, full_name, username, role_id) VALUES
        (3, 'Admin User', 'Admin User', 'admin', 2);

    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY,
        barcode TEXT,
        sku TEXT,
        product_name TEXT,
        category_id INTEGER,
        cost_price REAL,
        sale_price REAL
    );
    CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT);
    INSERT INTO categories VALUES (1, 'Beverages');
    INSERT INTO products VALUES (1, '111', 'BEV-1', 'Orange Juice', 1, 15.00, 25.00);

    CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY,
        cashier_id INTEGER,
        shift_id INTEGER,
        sale_date TEXT,
        total_amount REAL,
        gross_amount REAL,
        discount_amount REAL,
        payment_method TEXT
    );
    CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY,
        sale_id INTEGER,
        product_id INTEGER,
        quantity INTEGER,
        unit_price REAL,
        subtotal REAL
    );
    CREATE TABLE sale_item_cost_allocations (
        allocation_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_item_id INTEGER,
        batch_id INTEGER,
        quantity INTEGER,
        unit_cost REAL,
        cost_source TEXT,
        cost_status TEXT,
        created_at TEXT
    );
    CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY,
        sale_id INTEGER,
        cashier_id INTEGER,
        created_at TEXT,
        total_amount REAL,
        payment_method TEXT,
        reason TEXT
    );
    CREATE TABLE cash_refund_items (
        refund_item_id INTEGER PRIMARY KEY,
        refund_id INTEGER,
        sale_item_id INTEGER,
        product_id INTEGER,
        quantity INTEGER,
        unit_price REAL,
        subtotal REAL,
        disposition TEXT
    );

    CREATE TABLE sale_item_batches (
        sale_item_batch_id INTEGER PRIMARY KEY,
        sale_item_id INTEGER,
        batch_id INTEGER,
        quantity INTEGER,
        unit_cost REAL,
        cost_status TEXT
    );
    CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY,
        product_id INTEGER,
        remaining_quantity INTEGER,
        cost_price REAL,
        expiration_date TEXT,
        date_received TEXT
    );
    CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY,
        sale_id INTEGER,
        reversal_type TEXT,
        status TEXT,
        reason TEXT,
        created_at TEXT
    );

    -- 10 sales of Orange Juice at 25.00 (Cost 15.00 each)
    INSERT INTO sales VALUES (1, 1, 1, '2026-10-15 12:00:00', 250.00, 250.00, 0.00, 'cash');
    INSERT INTO sale_items VALUES (1, 1, 1, 10, 25.00, 250.00);
    INSERT INTO sale_item_batches VALUES (1, 1, 1, 10, 15.00, 'recorded');
    INSERT INTO sale_item_cost_allocations VALUES (1, 1, 1, 10, 15.00, 'batch', 'recorded', '2026-10-15 12:00:00');
");

$gpService = new GrossProfitService($gpPdo);
$calc = $gpService->calculate(['from' => '2026-10-15', 'to' => '2026-10-15']);

$assert($calc['summary']['total_net_sales'] === 250.00, 'Net sales computed as 250.00');
$assert($calc['summary']['total_net_cost'] === 150.00, 'COGS computed as 150.00 (10 units * 15.00)');
$assert($calc['summary']['total_gross_profit'] === 100.00, 'Gross profit computed as 100.00 (250 - 150)');
$assert($calc['summary']['total_margin'] === 40.0, 'Gross margin computed as 40.0% (100 / 250)');

// Test Gross Profit Report HTML rendering & non-jumping container
ob_start();
$_SESSION['user_id'] = 3;
$_SESSION['role'] = 'admin';
$_SESSION['active_role'] = 'admin';
$_SESSION['session_version'] = 0;
$_SESSION['full_name'] = 'Admin User';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['report' => 'gross_profit', 'from' => '2026-10-15', 'to' => '2026-10-15', 'page' => '1', 'per_page' => '10'];
$pdo = $gpPdo;

include __DIR__ . '/../../frontend/components/report/report_generation.php';
$gpHtml = ob_get_clean();

$assert(str_contains($gpHtml, 'Gross Profit Report'), 'Gross profit report title rendered');
$assert(str_contains($gpHtml, 'Product Cost') && str_contains($gpHtml, '150.00'), 'COGS rendered in summary card or table');
$assert(str_contains($gpHtml, 'Gross Profit') && str_contains($gpHtml, '100.00'), 'Gross profit rendered in summary');
$assert(str_contains($gpHtml, 'gp-table-wrap') || str_contains($gpHtml, 'table-wrap'), 'Non-jumping table container present');

if (!empty($failures)) {
    echo "\nFailed assertions (" . count($failures) . "):\n";
    foreach ($failures as $f) {
        echo " - " . $f . "\n";
    }
    exit(1);
}

echo "\nAll Ticket #453 Administrator Forecast Governance & Financial Reporting tests passed!\n";
