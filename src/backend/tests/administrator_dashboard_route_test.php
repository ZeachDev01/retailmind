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
    "require_role(['admin'])", 'StoreOperationsDashboardWorkspace',
    'Needs attention', 'Store status', 'Cash variances', 'Current Fiscal Period',
    'data-dashboard-refresh', 'data-stale-after', 'data-dashboard-action', 'aria-live="polite"',
    'name="period"', 'name="range" value="30"', 'method="get"',
    'Gross Sales', 'Net Sales', 'Gross Profit', 'Gross Profit excludes operating expenses',
    'id="net-sales-hourly-chart"', 'id="gross-profit-chart"', 'id="financial-history-chart"',
    'View detailed breakdown', 'View 30-day breakdown', 'Last 30 Days', 'No comparable percentage',
    'chart.umd.min.js',
] as $needle) {
    $assert(str_contains($route, $needle), "Administrator dashboard route is missing {$needle}");
}
foreach (['Recent Sales', 'System Health', 'Backup & Restore', 'ML Operation', 'Platform Settings', 'Protected Audit Records', 'Adjust Stock', 'Receive Stock', 'Store governance', 'Inventory oversight', 'Demand Forecast performance'] as $forbidden) {
    $assert(!str_contains($route, $forbidden), "Administrator dashboard route must not expose {$forbidden}");
}
$assert(str_contains($route, '300000'), 'Dashboard must schedule a five-minute refresh');
$assert(str_contains($route, 'actionInProgress'), 'Automatic refresh must defer during an action');
$assert(str_contains($route, 'dashboard-stale'), 'Dashboard must visibly mark deferred stale data');
$assert(str_contains($route, 'range='), 'Manual and automatic refresh must preserve the selected range');
$assert(str_contains($route, 'period='), 'Manual and automatic refresh must preserve the selected period');
foreach (['.store-operations-headlines', '.comparison-grid', '.net-sales-section', '.net-sales-summaries-grid', '.net-sales-summary-card', '.period-controls', '.period-btn', '.net-sales-chart-wrapper', '.net-sales-table', '@media (max-width: 640px)'] as $needle) {
    $assert(str_contains($styles, $needle), "Dashboard styles are missing responsive contract {$needle}");
}

if ($failures) {
    fwrite(STDERR, "Administrator dashboard route tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Administrator dashboard route tests: passed\n";
