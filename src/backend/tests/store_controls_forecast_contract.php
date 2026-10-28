<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Services/RegisterService.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Attention/AttentionSettingsService.php';
require_once __DIR__ . '/../app/Forecasting/DemandForecastService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\RegisterService;
use App\Services\CashierShiftService;
use App\Attention\AttentionSettingsService;
use App\Attention\Clock;
use App\Forecasting\DemandForecastService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// 1. Role Capabilities for Store Controls & Forecasting
$policy = new RoleCapabilityPolicy();
$assert($policy->allows('admin', RoleCapabilityPolicy::MANAGE_REGISTERS), 'Admin can manage registers');
$assert($policy->allows('admin', RoleCapabilityPolicy::VIEW_FORECASTS), 'Admin can view forecasts');
$assert($policy->allows('admin', RoleCapabilityPolicy::GENERATE_FORECASTS), 'Admin can generate forecasts');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::RETRAIN_MODELS), 'Admin cannot retrain models');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::CONFIGURE_MODELS), 'Admin cannot configure models');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::APPROVE_MODELS), 'Admin cannot approve models');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::ROLL_BACK_MODELS), 'Admin cannot roll back models');

$assert($policy->allows('inventory_manager', RoleCapabilityPolicy::VIEW_FORECASTS), 'Inventory Manager can view forecasts');
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::GENERATE_FORECASTS), 'Inventory Manager cannot generate forecasts');
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::MANAGE_REGISTERS), 'Inventory Manager cannot manage registers');

$assert(!$policy->allows('cashier', RoleCapabilityPolicy::VIEW_FORECASTS), 'Cashier cannot view forecasts');
$assert(!$policy->allows('cashier', RoleCapabilityPolicy::GENERATE_FORECASTS), 'Cashier cannot generate forecasts');

$assert($policy->allows('super_admin', RoleCapabilityPolicy::CONFIGURE_MODELS), 'Super Admin can configure models');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::APPROVE_MODELS), 'Super Admin can approve models');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::ROLL_BACK_MODELS), 'Super Admin can roll back models');

// 2. Register Disabling Semantics
// In-memory SQLite test to verify register disabling blocks new shifts while preserving existing open shifts
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("
    CREATE TABLE registers (
        register_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        paper_width_mm TEXT NOT NULL DEFAULT '80',
        status TEXT NOT NULL DEFAULT 'active',
        created_by INTEGER NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NULL,
        disabled_at TEXT NULL
    );
    CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        register_id INTEGER NOT NULL,
        opening_cash REAL NOT NULL,
        status TEXT NOT NULL DEFAULT 'open',
        opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT NULL,
        expected_cash REAL NULL,
        actual_cash REAL NULL,
        cash_variance REAL NULL,
        variance_threshold REAL NULL,
        variance_review_required INTEGER NOT NULL DEFAULT 0,
        payment_totals TEXT NULL,
        closing_notes TEXT NULL,
        closed_by INTEGER NULL,
        intervention_reason TEXT NULL,
        reviewed_by INTEGER NULL,
        reviewed_at TEXT NULL,
        locked_at TEXT NULL
    );
    CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'cashier',
        full_name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active'
    );
    CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT NOT NULL,
        category TEXT NOT NULL DEFAULT 'store_operation',
        module TEXT,
        record_id INTEGER,
        previous_value TEXT,
        new_value TEXT,
        metadata TEXT,
        ip_address TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE attention_settings (
        setting_id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT NOT NULL,
        setting_key TEXT NOT NULL,
        setting_value REAL NOT NULL,
        updated_by INTEGER NULL,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE platform_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );
    CREATE TABLE ml_jobs (
        job_id INTEGER PRIMARY KEY AUTOINCREMENT,
        trigger_type TEXT NOT NULL,
        status TEXT NOT NULL,
        operator_message TEXT NULL,
        queued_at TEXT NOT NULL,
        completed_at TEXT NULL
    );
    CREATE TABLE demand_forecasts (
        forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        forecast_origin TEXT NOT NULL,
        target_date TEXT NOT NULL,
        horizon_day INTEGER NOT NULL,
        predicted_units INTEGER NOT NULL,
        model_version TEXT NOT NULL,
        readiness_level TEXT NOT NULL,
        limitation_marker TEXT NULL,
        confidence_score REAL NULL
    );
    CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT NOT NULL,
        product_name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active'
    );
    CREATE TABLE inventory (
        inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        quantity_on_hand INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE promotions (
        promotion_id INTEGER PRIMARY KEY AUTOINCREMENT,
        promotion_name TEXT NOT NULL,
        discount_type TEXT NOT NULL,
        discount_value REAL NOT NULL,
        scope TEXT NOT NULL DEFAULT 'all',
        product_id INTEGER NULL,
        category_id INTEGER NULL,
        minimum_quantity INTEGER NOT NULL DEFAULT 1,
        starts_at TEXT NOT NULL,
        ends_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        created_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

$registerService = new RegisterService($pdo, $policy);
$shiftService = new CashierShiftService($pdo);

// Seed users
$pdo->exec("INSERT INTO users (user_id, username, full_name, role) VALUES (1, 'admin_user', 'Admin User', 'admin'), (10, 'cashier1', 'Cashier One', 'cashier'), (20, 'cashier2', 'Cashier Two', 'cashier')");

// Create register 1 and 2
$regId1 = $registerService->create(1, 'admin', 'Front Till', '80');
$regId2 = $registerService->create(1, 'admin', 'Back Till', '80');

// Open a shift on register 1
$shiftId1 = $shiftService->openShift(10, 'cashier', $regId1, 1000.0);
$assert($shiftId1 > 0, 'Shift opened on register 1');

// Disable register 1 while shift is open
$disabledReg1 = $registerService->setStatus(1, 'admin', $regId1, RegisterService::STATUS_DISABLED);
$assert($disabledReg1['status'] === RegisterService::STATUS_DISABLED, 'Register 1 status is disabled');

// Existing open shift on disabled register 1 is NOT shut down; it remains open
$activeShift = $shiftService->getOpenShift(10);
$assert($activeShift !== null && $activeShift['status'] === 'open', 'Existing open shift on disabled register remains open');

// New shift on disabled register 1 is BLOCKED
try {
    $shiftService->openShift(20, 'cashier', $regId1, 500.0);
    $failures[] = 'Opening new shift on disabled register should have been blocked';
} catch (DomainException $e) {
    $assert(str_contains($e->getMessage(), 'disabled and cannot start a new Cashier Shift'), 'Blocked message is exact');
}

// 3. Promotion Copy and Eligibility Semantics
$promotionsFile = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/promotions.php');
$assert(!str_contains($promotionsFile, 'The POS applies the highest eligible discount'), 'Retired promise of highest eligible discount is removed');
$assert(!str_contains($promotionsFile, 'automatic discounts'), 'Retired promise of automatic discounts is removed');
$assert(str_contains($promotionsFile, 'explicit Cashier selection') || str_contains($promotionsFile, 'Cashier selection'), 'Explicit Cashier selection described');
$assert(str_contains($promotionsFile, 'eligible') && str_contains($promotionsFile, 'Enabled'), 'Distinguishes enabled configuration from schedule eligibility');

// 4. Register Disabling Copy
$registersFile = file_get_contents(__DIR__ . '/../../frontend/components/administrator/registers.php');
$assert(str_contains($registersFile, 'blocks new Shifts') || str_contains($registersFile, 'blocking new Shifts'), 'Register disabling copy explains blocking new shifts');
$assert(str_contains($registersFile, 'existing') || str_contains($registersFile, 'open Shift'), 'Register disabling copy explains preserving existing shifts');

// 5. Store Calendar Copy
$calendarFile = file_get_contents(__DIR__ . '/../../frontend/components/administrator/store_calendar.php');
$assert(str_contains($calendarFile, 'forecast context') || str_contains($calendarFile, 'context for forecasting') || str_contains($calendarFile, 'Store and forecast context') || str_contains($calendarFile, 'operational and forecasting context'), 'Calendar explains store/forecast context');
$assert(!str_contains($calendarFile, 'gating checkout') && (str_contains($calendarFile, 'does not gate checkout') || str_contains($calendarFile, 'not checkout')), 'Calendar explains not gating checkout or shifts');
$assert(str_contains($calendarFile, 'Holiday') && str_contains($calendarFile, 'Open'), 'Explains Holiday forecasting meaning and using Open when trading');

// 6. Store Settings: Retained Effects vs Ineffective Controls
$settingsFile = file_get_contents(__DIR__ . '/../../frontend/components/administrator/store_settings.php');
$assert(!str_contains($settingsFile, 'inventory_escalation_count'), 'Ineffective inventory_escalation_count control is removed');
$assert(!str_contains($settingsFile, 'inventory_unresolved_hours'), 'Ineffective inventory_unresolved_hours control is removed');
$assert(str_contains($settingsFile, 'cash_variance_amount'), 'Retained cash_variance_amount setting present');
$assert(str_contains($settingsFile, 'inventory_overdue_hours'), 'Retained inventory_overdue_hours setting present');
$assert(str_contains($settingsFile, 'inventory_high_value_amount'), 'Retained inventory_high_value_amount setting present');
$assert(str_contains($settingsFile, 'fiscal_period_days_to_close'), 'Retained fiscal_period_days_to_close setting present');

// 7. Operational Forecasts: Statuses (Ready, Updating, Old, Unavailable), Origin/Age, Distinct Counts, Unavailable Confidence
$predictionsFile = file_get_contents(__DIR__ . '/../../frontend/components/report/predictions.php');
$assert(str_contains($predictionsFile, 'Ready') || str_contains($predictionsFile, 'ready'), 'Ready state present in predictions');
$assert(str_contains($predictionsFile, 'Updating') || str_contains($predictionsFile, 'updating'), 'Updating state present in predictions');
$assert(str_contains($predictionsFile, 'Old') || str_contains($predictionsFile, 'old'), 'Old/stale state present in predictions');
$assert(str_contains($predictionsFile, 'Unavailable') || str_contains($predictionsFile, 'unavailable'), 'Unavailable state present in predictions');
$assert(!str_contains($predictionsFile, 'href="forecast_analytics.php"') && !str_contains($predictionsFile, "href='forecast_analytics.php'"), 'Predictions does not link ordinary users to technical model analytics');

// 8. Forecast Analytics restricted to Super Admin technical governance
$analyticsFile = file_get_contents(__DIR__ . '/../../frontend/components/report/forecast_analytics.php');
$assert(str_contains($analyticsFile, 'super_admin') || str_contains($analyticsFile, 'CONFIGURE_MODELS'), 'Forecast analytics restricted to technical governance / super_admin');

if (!empty($failures)) {
    echo "FAIL: Store controls and operational forecasts contract failed:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}

echo "PASS: Store controls and operational forecasts contract passed.\n";
