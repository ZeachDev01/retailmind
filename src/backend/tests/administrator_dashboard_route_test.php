<?php
// Thin route contract for the Administrator Store-operations dashboard.
$root = dirname(__DIR__, 3);
$route = file_get_contents($root . '/src/frontend/components/administrator/dashboard.php') ?: '';
$styles = file_get_contents($root . '/src/frontend/assets/css/dashboard.css') ?: '';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    "require_role(['admin'])",
    'StoreOperationsDashboardWorkspace',
    'Store attention queue',
    'Sales & cash exceptions',
    'Pending approvals',
    'Escalated inventory risks',
    'Current Fiscal Period',
    'Previous equivalent period',
    'Staff &amp; cashier shifts',
    'Demand Forecast performance',
    'Inventory escalations',
    'name="range"',
    'data-dashboard-refresh',
    'data-stale-after',
    'data-dashboard-action',
    'aria-live="polite"',
    'aria-labelledby=',
] as $needle) {
    $assert(str_contains($route, $needle), "Administrator dashboard route is missing {$needle}");
}
foreach (['Recent Sales', 'System Health', 'Backup & Restore', 'ML Operation', 'Platform Settings', 'Protected Audit Records', 'Adjust Stock', 'Receive Stock'] as $forbidden) {
    $assert(!str_contains($route, $forbidden), "Administrator dashboard route must not expose {$forbidden}");
}
$assert(str_contains($route, '300000'), 'Dashboard must schedule a five-minute refresh');
$assert(str_contains($route, 'actionInProgress'), 'Automatic refresh must defer during an action');
$assert(str_contains($route, 'dashboard-stale'), 'Dashboard must visibly mark deferred stale data');
$assert(str_contains($route, 'range='), 'Manual and automatic refresh must preserve the selected range');
foreach (['.store-operations-headlines', '.comparison-grid', '.operations-overview-grid', '@media (max-width: 640px)'] as $needle) {
    $assert(str_contains($styles, $needle), "Dashboard styles are missing responsive contract {$needle}");
}

if ($failures) {
    fwrite(STDERR, "Administrator dashboard route tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Administrator dashboard route tests: passed\n";
