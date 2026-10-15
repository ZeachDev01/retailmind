<?php
declare(strict_types=1);

// Contract test for Issue #185: Retire procurement execution and alternate stock writers.
// Verifies:
// 1. Obsolete procurement execution files are removed.
// 2. Navigation, dashboard, and attention surfaces contain zero references to retired routes.
// 3. Router resolution returns 404 for retired URLs.
// 4. Retained direct-receiving and inventory operational files remain intact.

$root = dirname(__DIR__, 3);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$retiredFiles = [
    'src/frontend/components/inventory_management/suppliers.php',
    'src/frontend/components/inventory_management/replenishment_requests.php',
    'src/frontend/components/invoice/purchase_orders.php',
    'src/frontend/components/inventory_management/legacy_reorder_planner.php',
    'src/frontend/components/inventory_management/reorder_planner.php',
    'src/backend/legacy/routes/manager/replenishment_requests.php',
];

foreach ($retiredFiles as $file) {
    $fullPath = $root . '/' . $file;
    $assert(!file_exists($fullPath), "Retired file must not exist: {$file}");
}

// Router 404 verification
$frontendRoot = realpath($root . '/src/frontend');
$resolveRoute = static function (string $path) use ($frontendRoot): int {
    $path = rawurldecode(parse_url($path, PHP_URL_PATH));
    $file = realpath($frontendRoot . ($path === '/' ? '/index.php' : $path));
    if (!$file || !str_starts_with($file, $frontendRoot . DIRECTORY_SEPARATOR) || !str_ends_with($file, '.php')) {
        return 404;
    }
    return 200;
};

$retiredUrls = [
    '/components/inventory_management/suppliers.php',
    '/components/inventory_management/replenishment_requests.php',
    '/components/invoice/purchase_orders.php',
    '/components/inventory_management/legacy_reorder_planner.php',
    '/components/inventory_management/reorder_planner.php',
];

foreach ($retiredUrls as $url) {
    $status = $resolveRoute($url);
    $assert($status === 404, "Retired URL {$url} must resolve to 404, got {$status}");
}

// Retained surfaces must exist
$retainedFiles = [
    'src/frontend/components/report/stock_receiving.php',
    'src/frontend/components/report/receiving_history.php',
    'src/frontend/components/inventory_management/inventory_overview.php',
    'src/frontend/components/inventory_management/replenishment_queue.php',
    'src/frontend/components/inventory_management/products.php',
    'src/frontend/components/inventory_management/inventory_counts.php',
    'src/frontend/components/administrator/stock_issues.php',
    'src/frontend/components/invoice/sales.php',
    'src/frontend/components/cashier/pos.php',
];

foreach ($retainedFiles as $file) {
    $fullPath = $root . '/' . $file;
    $assert(file_exists($fullPath), "Retained file must exist: {$file}");
}

// Zero references in active UI / nav files
$surfaces = [
    'src/frontend/components/sidebar.php',
    'src/frontend/components/inventory_management/replenishment_queue.php',
    'src/frontend/components/report/forecast_exceptions.php',
    'src/frontend/components/report/report_generation.php',
];

$prohibitedPatterns = [
    'suppliers.php',
    'replenishment_requests.php',
    'purchase_orders.php',
    'legacy_reorder_planner.php',
    'reorder_planner.php',
    'Legacy Purchasing',
];

foreach ($surfaces as $surface) {
    $content = file_get_contents($root . '/' . $surface);
    $assert($content !== false, "Surface must be readable: {$surface}");
    foreach ($prohibitedPatterns as $pattern) {
        $assert(!str_contains($content, $pattern), "{$surface} must not contain retired pattern: '{$pattern}'");
    }
}

// Attention rules and dashboard routing
$attentionRuleFile = $root . '/src/backend/app/Attention/AttentionRuleService.php';
$attentionRuleContent = file_get_contents($attentionRuleFile);
$assert(str_contains($attentionRuleContent, 'components/administrator/stock_issues.php'), "AttentionRuleService must route overdue approvals to stock_issues.php");

$dashboardWorkspaceFile = $root . '/src/backend/app/Dashboard/StoreOperationsDashboardWorkspace.php';
$dashboardWorkspaceContent = file_get_contents($dashboardWorkspaceFile);
$assert(str_contains($dashboardWorkspaceContent, 'components/administrator/stock_issues.php'), "StoreOperationsDashboardWorkspace must route Store Approvals to stock_issues.php");
$assert(!str_contains($dashboardWorkspaceContent, 'replenishment_requests'), "StoreOperationsDashboardWorkspace must not query replenishment_requests");

$dashboardServiceFile = $root . '/src/backend/app/Services/DashboardService.php';
$dashboardServiceContent = file_get_contents($dashboardServiceFile);
$assert(!str_contains($dashboardServiceContent, 'pending_replenishment'), "DashboardService must not compute pending_replenishment");
$assert(!str_contains($dashboardServiceContent, 'getPendingReplenishmentRequests'), "DashboardService must not have getPendingReplenishmentRequests");

if ($failures !== []) {
    fwrite(STDERR, "Procurement retirement contract failed with " . count($failures) . " errors:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Procurement retirement contract: passed\n";
