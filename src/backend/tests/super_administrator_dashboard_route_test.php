<?php
// Thin route contract for dashboard wiring, presentation states, and refresh behavior.
$root = dirname(__DIR__, 3);
$route = file_get_contents($root . '/src/frontend/components/super_administrator/dashboard.php') ?: '';
$styles = file_get_contents($root . '/src/frontend/assets/css/dashboard.css') ?: '';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    "require_role(['super_admin'])",
    'DashboardWorkspace',
    'SystemHealthService',
    'Platform attention queue',
    'Critical attention',
    'Latest successful backup',
    'Platform/service health',
    'ML health',
    'Privileged-account anomalies',
    'Emergency Access',
    'Recovery Account',
    'Store continuity',
    'data-dashboard-refresh',
    'data-stale-after',
    'aria-live="polite"',
    'aria-labelledby=',
] as $needle) {
    $assert(str_contains($route, $needle), "Dashboard route is missing {$needle}");
}
foreach (['Recent Sales', 'Total Revenue', 'Inventory Value', 'Adjust Stock', 'Receive Stock'] as $forbidden) {
    $assert(!str_contains($route, $forbidden), "Dashboard route must not expose {$forbidden}");
}
$assert(str_contains($route, '300000'), 'Dashboard must schedule a five-minute refresh');
$assert(str_contains($route, 'actionInProgress'), 'Automatic refresh must defer while an action is in progress');
$assert(str_contains($route, 'dashboard-stale'), 'Dashboard must expose a stale state when refresh is deferred');
foreach (['.control-center-headlines', '.governance-queue', '.continuity-grid', '@media (max-width: 640px)'] as $needle) {
    $assert(str_contains($styles, $needle), "Dashboard styles are missing responsive contract {$needle}");
}

if ($failures) {
    fwrite(STDERR, "Super Administrator dashboard route tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Super Administrator dashboard route tests: passed\n";
