<?php
// Contract for Demand Forecast navigation and capability isolation.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\RoleCapabilityPolicy;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$policy = new RoleCapabilityPolicy();

// 1. Existing shared report access without widening unrelated permissions
// The Administrator and Inventory Manager must still have VIEW_STORE_REPORTS.
$assert($policy->allows('admin', RoleCapabilityPolicy::VIEW_STORE_REPORTS), 'Administrator keeps shared report access');
$assert($policy->allows('inventory_manager', RoleCapabilityPolicy::VIEW_STORE_REPORTS), 'Inventory Manager keeps shared report access');

// 2. Direct denied requests
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::GENERATE_FORECASTS), 'Inventory Manager is denied Forecast Generation');
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::RETRAIN_MODELS), 'Inventory Manager is denied Model Retraining');
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::CONFIGURE_MODELS), 'Inventory Manager is denied Model Configuration');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::RETRAIN_MODELS), 'Administrator is denied Model Retraining');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::CONFIGURE_MODELS), 'Administrator is denied Model Configuration');
$assert(!$policy->allows('cashier', RoleCapabilityPolicy::VIEW_FORECASTS), 'Cashier is denied Forecast viewing');

// 3. Sidebar contains the links
$sidebar = file_get_contents(__DIR__ . '/../../frontend/components/sidebar.php');
$assert(str_contains($sidebar, 'components/report/predictions.php'), 'Sidebar contains predictions.php entry');
$assert(str_contains($sidebar, 'components/report/forecast_analytics.php'), 'Sidebar contains forecast analytics entry');
$assert(!str_contains($sidebar, 'forecast_import.php'), 'Forecast Data Import is reached through ML Operation, not role sidebars');
$operation = file_get_contents(__DIR__ . '/../../frontend/components/system_administrator/ml_settings.php');
$assert(str_contains($operation, 'components/system_administrator/forecast_import.php'), 'ML Operation exposes Forecast Data Import');

if ($failures) {
    fwrite(STDERR, "Forecast navigation contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Forecast navigation contract: passed\n";

