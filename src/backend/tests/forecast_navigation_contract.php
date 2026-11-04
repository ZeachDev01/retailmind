<?php
// Contract for Demand Forecast navigation and capability isolation (Ticket #371, Spec #359).
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Attention\AttentionRuleService;
use App\Attention\AttentionSettingsService;
use App\Support\Clock;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "  FAIL: {$message}\n";
    } else {
        echo "  ok - {$message}\n";
    }
};

echo "Starting Ticket #371 Forecasting Navigation Contract...\n\n";

$policy = new RoleCapabilityPolicy();

// =========================================================================
// 1. Role Capability Boundaries & Capability Isolation
// =========================================================================
echo "--- 1. Role Capability Boundaries & Capability Isolation ---\n";
// Shared report access without widening unrelated permissions
$assert($policy->allows('admin', RoleCapabilityPolicy::VIEW_STORE_REPORTS), 'Administrator keeps shared report access');
$assert($policy->allows('inventory_manager', RoleCapabilityPolicy::VIEW_STORE_REPORTS), 'Inventory Manager keeps shared report access');

// Direct denied requests
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::GENERATE_FORECASTS), 'Inventory Manager is denied Forecast Generation');
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::RETRAIN_MODELS), 'Inventory Manager is denied Model Retraining');
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::CONFIGURE_MODELS), 'Inventory Manager is denied Model Configuration');
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::APPROVE_MODELS), 'Inventory Manager is denied Model Approval');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::RETRAIN_MODELS), 'Administrator is denied Model Retraining');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::CONFIGURE_MODELS), 'Administrator is denied Model Configuration');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::IMPORT_FORECAST_HISTORY), 'Administrator is denied Forecast History Import');
$assert(!$policy->allows('cashier', RoleCapabilityPolicy::VIEW_FORECASTS), 'Cashier is denied Forecast viewing');

// Permitted requests
$assert($policy->allows('admin', RoleCapabilityPolicy::VIEW_FORECASTS), 'Administrator is allowed Forecast viewing');
$assert($policy->allows('admin', RoleCapabilityPolicy::GENERATE_FORECASTS), 'Administrator is allowed Forecast Generation');
$assert($policy->allows('admin', RoleCapabilityPolicy::APPROVE_MODELS), 'Administrator is allowed Model Approval (ADR-0022)');
$assert($policy->allows('admin', RoleCapabilityPolicy::REPLACE_MODELS), 'Administrator is allowed Model Replacement (ADR-0022)');
$assert($policy->allows('admin', RoleCapabilityPolicy::ROLL_BACK_MODELS), 'Administrator is allowed Model Rollback (ADR-0022)');

$assert($policy->allows('super_admin', RoleCapabilityPolicy::CONFIGURE_MODELS), 'Super Administrator has Model Configuration');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::RETRAIN_MODELS), 'Super Administrator has Model Retraining');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::IMPORT_FORECAST_HISTORY), 'Super Administrator has Forecast History Import');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::VIEW_FORECASTS), 'Super Administrator has Forecast viewing');
$assert($policy->allows('inventory_manager', RoleCapabilityPolicy::VIEW_FORECASTS), 'Inventory Manager has Forecast viewing');

// =========================================================================
// Helper: Render sidebar DOMXPath for a given role and request URI
// =========================================================================
function render_sidebar_dom(string $role, string $requestUri = ''): DOMXPath
{
    $_SESSION = ['user_id' => 1, 'full_name' => 'Test User', 'role' => $role, 'roles' => [$role]];
    $_SERVER['REQUEST_URI'] = $requestUri !== '' ? $requestUri : app_url('components/administrator/dashboard.php');
    ob_start();
    require __DIR__ . '/../../frontend/components/sidebar.php';
    $html = (string)ob_get_clean();

    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    return new DOMXPath($document);
}

// =========================================================================
// 2. Super Administrator Navigation Contract
// =========================================================================
echo "\n--- 2. Super Administrator Navigation Contract ---\n";
$superXpath = render_sidebar_dom('super_admin', app_url('components/super_administrator/dashboard.php'));

$superForecastingBtn = $superXpath->query('//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")]//button[@title="Forecasting"]');
$assert($superForecastingBtn->length === 1, 'Super Administrator has dedicated Forecasting navigation group');

$superForecastingLinks = $superXpath->query('//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")][button[@title="Forecasting"]]//a');
$assert($superForecastingLinks->length === 6, 'Super Administrator Forecasting group has exactly 6 items');

$expectedSuperOrder = [
    ['title' => 'Import Sales History', 'path' => app_url('components/system_administrator/forecast_import.php')],
    ['title' => 'Data Readiness', 'path' => app_url('components/report/data_readiness.php')],
    ['title' => 'Model Training', 'path' => app_url('components/system_administrator/ml_settings.php')],
    ['title' => 'Forecast Analytics', 'path' => app_url('components/report/forecast_analytics.php')],
    ['title' => 'Demand Forecast', 'path' => app_url('components/report/predictions.php')],
    ['title' => 'Forecast Exceptions', 'path' => app_url('components/report/forecast_exceptions.php')],
];

for ($i = 0; $i < count($expectedSuperOrder); $i++) {
    $item = $superForecastingLinks->item($i);
    $exp = $expectedSuperOrder[$i];
    $itemTitle = $item ? $item->getAttribute('title') : '';
    $itemHref = $item ? $item->getAttribute('href') : '';
    $assert(
        $itemTitle === $exp['title'] && $itemHref === $exp['path'],
        "Super Administrator Forecasting item #" . ($i + 1) . " must be '{$exp['title']}' linking to {$exp['path']}"
    );
}

// Ensure duplicate forecasting entries are removed from Platform Administration
$platformAdminForecasting = $superXpath->query(
    '//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")][button[@title="Platform Administration"]]//a['
    . 'contains(@href, "ml_settings.php") or contains(@href, "forecast_analytics.php") or contains(@href, "forecast_import.php")'
    . ' or contains(@href, "predictions.php") or contains(@href, "data_readiness.php") or contains(@href, "forecast_exceptions.php")]'
);
$assert($platformAdminForecasting->length === 0, 'Platform Administration has no duplicate forecasting links');

// Ensure System Health remains in Platform Administration general tooling
$platformHealth = $superXpath->query(
    '//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")][button[@title="Platform Administration"]]//a[@title="System Health"]'
);
$assert($platformHealth->length === 1 && $platformHealth->item(0)->getAttribute('href') === app_url('components/system_administrator/system_health.php'),
    'System Health remains general platform tooling in Platform Administration');

// Ensure replay/demo/prototype entries are not in normal production menu
$superReplay = $superXpath->query('//nav[@aria-label="Main navigation"]//a[contains(@href, "forecast_replay.php") or contains(@title, "Replay") or contains(@title, "Demo")]');
$assert($superReplay->length === 0, 'Super Administrator normal navigation excludes replay/demo/prototype entries');

// =========================================================================
// 3. Administrator Navigation Contract
// =========================================================================
echo "\n--- 3. Administrator Navigation Contract ---\n";
$adminXpath = render_sidebar_dom('admin', app_url('components/administrator/dashboard.php'));

$adminForecastingBtn = $adminXpath->query('//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")]//button[@title="Forecasting"]');
$assert($adminForecastingBtn->length === 1, 'Administrator has dedicated Forecasting navigation group');

$adminForecastingLinks = $adminXpath->query('//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")][button[@title="Forecasting"]]//a');
$assert($adminForecastingLinks->length === 4, 'Administrator Forecasting group has exactly 4 items');

$expectedAdminOrder = [
    ['title' => 'Data Readiness', 'path' => app_url('components/report/data_readiness.php')],
    ['title' => 'Model Review & Approval', 'path' => app_url('components/administrator/model_review.php')],
    ['title' => 'Demand Forecast', 'path' => app_url('components/report/predictions.php')],
    ['title' => 'Forecast Exceptions', 'path' => app_url('components/report/forecast_exceptions.php')],
];

for ($i = 0; $i < count($expectedAdminOrder); $i++) {
    $item = $adminForecastingLinks->item($i);
    $exp = $expectedAdminOrder[$i];
    $itemTitle = $item ? $item->getAttribute('title') : '';
    $itemHref = $item ? $item->getAttribute('href') : '';
    $assert(
        $itemTitle === $exp['title'] && $itemHref === $exp['path'],
        "Administrator Forecasting item #" . ($i + 1) . " must be '{$exp['title']}' linking to {$exp['path']}"
    );
}

// Store Calendar remains in Store Administration
$storeCalendar = $adminXpath->query('//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")][button[@title="Store Administration"]]//a[@title="Store Calendar"]');
$assert($storeCalendar->length === 1 && $storeCalendar->item(0)->getAttribute('href') === app_url('components/administrator/store_calendar.php'),
    'Store Calendar remains in Store Administration');

// Promotions remains in Store Operations
$promotionsLink = $adminXpath->query('//nav[@aria-label="Main navigation"]//a[@title="Promotions"]');
$assert($promotionsLink->length === 1 && $promotionsLink->item(0)->getAttribute('href') === app_url('components/inventory_management/promotions.php'),
    'Promotions remains in Store Operations');

// Ensure duplicate forecasting entries are removed from Store Reports
$storeReportsForecasting = $adminXpath->query(
    '//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")][button[@title="Store Reports"]]//a['
    . 'contains(@href, "predictions.php") or contains(@href, "data_readiness.php") or contains(@href, "forecast_exceptions.php") or contains(@href, "model_review.php")]'
);
$assert($storeReportsForecasting->length === 0, 'Store Reports has no duplicate forecasting links');

// Ensure technical ML settings / analytics / import are NOT visible to Administrator
$adminTechnicalLinks = $adminXpath->query('//nav[@aria-label="Main navigation"]//a[contains(@href, "ml_settings.php") or contains(@href, "forecast_analytics.php") or contains(@href, "forecast_import.php")]');
$assert($adminTechnicalLinks->length === 0, 'Administrator navigation excludes technical ML settings, analytics, and data import');

// Ensure replay/demo/prototype entries are not in normal production menu
$adminReplay = $adminXpath->query('//nav[@aria-label="Main navigation"]//a[contains(@href, "forecast_replay.php") or contains(@title, "Replay") or contains(@title, "Demo")]');
$assert($adminReplay->length === 0, 'Administrator normal navigation excludes replay/demo/prototype entries');

// =========================================================================
// 4. Inventory Manager Navigation Contract
// =========================================================================
echo "\n--- 4. Inventory Manager Navigation Contract ---\n";
$imXpath = render_sidebar_dom('inventory_manager', app_url('components/inventory_management/inventory_overview.php'));

// Inventory Manager retains authorized inventory links
$imInventoryOverview = $imXpath->query('//nav[@aria-label="Main navigation"]//a[@title="Stock Overview"]');
$imProducts = $imXpath->query('//nav[@aria-label="Main navigation"]//a[@title="Products"]');
$imReplenishment = $imXpath->query('//nav[@aria-label="Main navigation"]//a[@title="Replenishment Recommendations"]');
$imStockReceiving = $imXpath->query('//nav[@aria-label="Main navigation"]//a[@title="Stock Receiving"]');
$imReceivingHistory = $imXpath->query('//nav[@aria-label="Main navigation"]//a[@title="Receiving History"]');
$imCounts = $imXpath->query('//nav[@aria-label="Main navigation"]//a[@title="Inventory Counts"]');
$imIssues = $imXpath->query('//nav[@aria-label="Main navigation"]//a[@title="Stock Issue Reviews"]');
$imPullouts = $imXpath->query('//nav[@aria-label="Main navigation"]//a[@title="Supplier Pullouts"]');

$assert($imInventoryOverview->length === 1, 'Inventory Manager retains Stock Overview');
$assert($imProducts->length === 1, 'Inventory Manager retains Products');
$assert($imReplenishment->length === 1, 'Inventory Manager retains Replenishment Recommendations');
$assert($imStockReceiving->length === 1, 'Inventory Manager retains Stock Receiving');
$assert($imReceivingHistory->length === 1, 'Inventory Manager retains Receiving History');
$assert($imCounts->length === 1, 'Inventory Manager retains Inventory Counts');
$assert($imIssues->length === 1, 'Inventory Manager retains Stock Issue Reviews');
$assert($imPullouts->length === 1, 'Inventory Manager retains Supplier Pullouts');

// Inventory Manager retains authorized Demand Forecast link under Reports
$imDemandForecast = $imXpath->query('//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")][button[@title="Reports"]]//a[@title="Demand Forecast"]');
$assert($imDemandForecast->length === 1 && $imDemandForecast->item(0)->getAttribute('href') === app_url('components/report/predictions.php'),
    'Inventory Manager retains authorized Demand Forecast link under Reports');

// Inventory Manager does NOT have administrative/technical forecasting links
$imAdminForecasting = $imXpath->query('//nav[@aria-label="Main navigation"]//a[contains(@href, "model_review.php") or contains(@href, "ml_settings.php") or contains(@href, "forecast_analytics.php") or contains(@href, "forecast_import.php")]');
$assert($imAdminForecasting->length === 0, 'Inventory Manager navigation excludes model review, ML settings, analytics, and import');

// Inventory Manager does NOT have dedicated Forecasting group (it is for Admin & Super Admin)
$imForecastingGroup = $imXpath->query('//nav[@aria-label="Main navigation"]//button[@title="Forecasting"]');
$assert($imForecastingGroup->length === 0, 'Inventory Manager does not have dedicated Forecasting group');

// =========================================================================
// 5. Cashier Navigation Contract
// =========================================================================
echo "\n--- 5. Cashier Navigation Contract ---\n";
$cashierXpath = render_sidebar_dom('cashier', app_url('components/cashier/pos.php'));
$cashierForecastingLinks = $cashierXpath->query('//nav[@aria-label="Main navigation"]//a[contains(@href, "predictions.php") or contains(@href, "forecast") or contains(@href, "model_review.php") or contains(@href, "data_readiness.php")]');
$assert($cashierForecastingLinks->length === 0, 'Cashier navigation has zero forecasting links');

// =========================================================================
// 6. Active State, Expanded Dropdown, Keyboard & Mobile Accessibility
// =========================================================================
echo "\n--- 6. Active State, Expanded Dropdown, Keyboard & Mobile Accessibility ---\n";
// When viewing a forecasting page as Administrator, the dropdown must be open and link active
$activeAdminXpath = render_sidebar_dom('admin', app_url('components/report/data_readiness.php'));
$activeDropdown = $activeAdminXpath->query('//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")][button[@title="Forecasting"]]')->item(0);
$assert($activeDropdown !== null && str_contains($activeDropdown->getAttribute('class'), 'open'),
    'Forecasting dropdown has "open" class when child page is active');

$activeBtn = $activeAdminXpath->query('.//button[@title="Forecasting"]', $activeDropdown)->item(0);
$assert($activeBtn !== null && $activeBtn->getAttribute('aria-expanded') === 'true',
    'Forecasting dropdown button has aria-expanded="true" when active');
$assert($activeBtn !== null && $activeBtn->getAttribute('type') === 'button',
    'Dropdown toggle button has explicit type="button" for keyboard accessibility');

$activeLink = $activeAdminXpath->query('.//a[@title="Data Readiness"]', $activeDropdown)->item(0);
$assert($activeLink !== null && str_contains($activeLink->getAttribute('class'), 'active'),
    'Active link has "active" CSS class');
$assert($activeLink !== null && $activeLink->getAttribute('aria-current') === 'page',
    'Active link has aria-current="page"');

// When viewing a non-forecasting page, Forecasting dropdown must NOT be open
$inactiveAdminXpath = render_sidebar_dom('admin', app_url('components/administrator/dashboard.php'));
$inactiveDropdown = $inactiveAdminXpath->query('//nav[@aria-label="Main navigation"]//div[contains(@class,"dropdown")][button[@title="Forecasting"]]')->item(0);
$assert($inactiveDropdown !== null && !str_contains($inactiveDropdown->getAttribute('class'), 'open'),
    'Forecasting dropdown does not have "open" class when child page is inactive');

$inactiveBtn = $inactiveAdminXpath->query('.//button[@title="Forecasting"]', $inactiveDropdown)->item(0);
$assert($inactiveBtn !== null && $inactiveBtn->getAttribute('aria-expanded') === 'false',
    'Forecasting dropdown button has aria-expanded="false" when inactive');

// Mobile drawer topbar and menu toggle
$mobileBrand = $adminXpath->query('//div[contains(@class,"admin-mobile-topbar")]//button[@id="menuToggle"]');
$assert($mobileBrand->length === 1 && $mobileBrand->item(0)->getAttribute('aria-controls') === 'appSidebar',
    'Mobile navigation includes accessible menuToggle controlling appSidebar');

$mobileLogo = $adminXpath->query('//div[contains(@class,"admin-mobile-topbar")]//a[contains(@class,"admin-mobile-logo")]');
$assert($mobileLogo->length === 1 && $mobileLogo->item(0)->getAttribute('aria-label') === 'RetailMind home',
    'Mobile navigation includes accessible brand home link');

// =========================================================================
// 7. Alert Links & Contextual Navigation
// =========================================================================
echo "\n--- 7. Alert Links & Contextual Navigation ---\n";
// AttentionRuleService candidate-review alert destination is model_review.php
$attentionFile = file_get_contents(__DIR__ . '/../app/Attention/AttentionRuleService.php');
$assert(str_contains($attentionFile, "'key' => 'store.forecasting.candidate-review'") &&
    str_contains($attentionFile, "'destination' => 'components/administrator/model_review.php'"),
    'Attention rule store.forecasting.candidate-review links to permitted model_review.php');

// StoreOperationsDashboardWorkspace candidate review exception links to model_review.php
$dashboardFile = file_get_contents(__DIR__ . '/../app/Dashboard/StoreOperationsDashboardWorkspace.php');
$assert(str_contains($dashboardFile, "'Candidate model awaiting review'") &&
    str_contains($dashboardFile, "'components/administrator/model_review.php'"),
    'Dashboard candidate review exception alert links to model_review.php');

// Forecasting workflow exposes Forecast Data Import from Model Training.
$operation = file_get_contents(__DIR__ . '/../../frontend/components/system_administrator/ml_settings.php');
$forecastUi = file_get_contents(__DIR__ . '/../../frontend/components/report/forecast_ui.php');
$assert(str_contains($operation, "forecast_workflow('train')") && str_contains($forecastUi, "['forecast_import.php', 'Import history'"), 'Model Training exposes Forecast Data Import');

// Store Calendar contains contextual link to Demand Forecast
$calendarFile = file_get_contents(__DIR__ . '/../../frontend/components/administrator/store_calendar.php');
$assert(str_contains($calendarFile, 'components/report/predictions.php'), 'Store Calendar contains contextual link to predictions.php');

// Promotions contains contextual link to Demand Forecast
$promotionsFile = file_get_contents(__DIR__ . '/../../frontend/components/inventory_management/promotions.php');
$assert(str_contains($promotionsFile, 'components/report/predictions.php'), 'Promotions contains contextual link to predictions.php');

// Data Readiness contains a contextual link to Demand Forecast.
$readinessFile = file_get_contents(__DIR__ . '/../../frontend/components/report/data_readiness.php');
$assert(str_contains($readinessFile, 'components/report/predictions.php'), 'Data Readiness links to predictions.php');

// Model Evaluation Summary exposes contextual link to model_review.php for APPROVE_MODELS
$evalSummaryFile = file_get_contents(__DIR__ . '/../../frontend/components/report/model_evaluation_summary.php');
$assert(str_contains($evalSummaryFile, 'components/administrator/model_review.php'), 'Model Evaluation Summary links to model_review.php');

// Forecasting pages keep workflow actions in the page body; redundant text-only
// navigation buttons do not belong in their top bars.
echo "\n--- 8. Forecasting Topbar Navigation ---\n";
$forecastTopbarFiles = [
    __DIR__ . '/../../frontend/components/system_administrator/forecast_import.php',
    __DIR__ . '/../../frontend/components/report/data_readiness.php',
    __DIR__ . '/../../frontend/components/report/forecast_analytics.php',
    __DIR__ . '/../../frontend/components/report/forecast_replay.php',
];
$forecastTopbarLabels = [
    'forecast_import.php' => ['Model Training'],
    'data_readiness.php' => ['Model Training', 'Model Review &amp; Approval', 'Demand Forecast'],
    'forecast_analytics.php' => ['Model Training', 'View Forecasts'],
    'forecast_replay.php' => ['Model Training'],
];
foreach ($forecastTopbarFiles as $forecastPage) {
    $forecastMarkup = file_get_contents($forecastPage);
    $pageName = basename($forecastPage);
    preg_match(
        '/<(?:div|header)\b[^>]*(?:class="topbar"|class="page-heading")[^>]*>.*?(?=<\?php forecast_workflow|<p class="tag-warning"|<\/main>)/is',
        $forecastMarkup,
        $topbarMatch
    );
    $topbarMarkup = $topbarMatch[0] ?? '';
    foreach ($forecastTopbarLabels[$pageName] as $label) {
        $hasTopbarLabel = str_contains($topbarMarkup, $label);
        $assert(!$hasTopbarLabel, "{$pageName} has no redundant '{$label}' topbar button");
    }
}

// =========================================================================
// Completion
// =========================================================================
if ($failures) {
    fwrite(STDERR, "\nForecast navigation contract failed (" . count($failures) . " errors):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\n=========================================================================\n";
echo "Forecast navigation contract: passed cleanly!\n";
echo "=========================================================================\n";
