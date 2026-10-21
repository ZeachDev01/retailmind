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
    'Store status',
    'Sales & cash exceptions',
    'Current Fiscal Period',
    'Compared with the previous',
    'Staff &amp; cashier shifts',
    'name="range"',
    'data-dashboard-refresh',
    'data-stale-after',
    'data-dashboard-action',
    'aria-live="polite"',
    'aria-labelledby=',
    "Today's Net Sales",
    "This Week's Net Sales",
    "This Month's Net Sales",
    'id="net-sales-title"',
    'id="net-sales-hourly-chart"',
    'class="net-sales-table"',
    'net-sales-summaries-grid',
    'period-controls',
    'data-period="today"',
    'data-period="week"',
    'data-period="month"',
    'name="period"',
    'period-badge ongoing',
    'chart.umd.min.js',
] as $needle) {
    $assert(str_contains($route, $needle), "Administrator dashboard route is missing {$needle}");
}
foreach (['Recent Sales', 'System Health', 'Backup & Restore', 'ML Operation', 'Platform Settings', 'Protected Audit Records', 'Adjust Stock', 'Receive Stock', 'Store governance', 'Inventory oversight', 'Demand Forecast performance'] as $forbidden) {
    $assert(!str_contains($route, $forbidden), "Administrator dashboard route must not expose {$forbidden}");
}
$assert(str_contains($route, '<h2 id="staff-shifts-title"><i class="bi bi-people" aria-hidden="true"></i> Staff &amp; cashier shifts</h2>'), 'Staff heading must include hidden people icon');
$assert(str_contains($route, '300000'), 'Dashboard must schedule a five-minute refresh');
$assert(str_contains($route, 'actionInProgress'), 'Automatic refresh must defer during an action');
$assert(str_contains($route, 'dashboard-stale'), 'Dashboard must visibly mark deferred stale data');
$assert(str_contains($route, 'range='), 'Manual and automatic refresh must preserve the selected range');
$assert(str_contains($route, 'period='), 'Manual and automatic refresh must preserve the selected period');
foreach (['.store-operations-headlines', '.comparison-grid', '.staff-shifts-section', '.net-sales-section', '.net-sales-summaries-grid', '.net-sales-summary-card', '.period-controls', '.period-btn', '.net-sales-chart-wrapper', '.net-sales-table', '@media (max-width: 640px)'] as $needle) {
    $assert(str_contains($styles, $needle), "Dashboard styles are missing responsive contract {$needle}");
}

if ($failures) {
    fwrite(STDERR, "Administrator dashboard route tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Administrator dashboard route tests: passed\n";
