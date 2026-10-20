<?php
// Thin route contract for dashboard wiring, presentation states, and refresh behavior.
$root = dirname(__DIR__, 3);
$route = str_replace("\r\n", "\n", file_get_contents($root . '/src/frontend/components/super_administrator/dashboard.php') ?: '');
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
    'Recovery Account',
    'data-dashboard-refresh',
    'data-stale-after',
    'aria-live="polite"',
    'aria-labelledby=',
] as $needle) {
    $assert(str_contains($route, $needle), "Dashboard route is missing {$needle}");
}
$assert(str_contains($route, 'Platform attention queue') || str_contains($route, 'Issue queue'), 'Dashboard route is missing Platform attention queue');
$assert(str_contains($route, 'Critical attention') || str_contains($route, 'Critical'), 'Dashboard route is missing Critical attention');
$assert(str_contains($route, 'Latest successful backup') || str_contains($route, 'Latest backup'), 'Dashboard route is missing Latest backup');
$assert(str_contains($route, 'Platform/service health') || str_contains($route, 'Platform health'), 'Dashboard route is missing Platform health');
$assert(str_contains($route, 'ML health') || str_contains($route, 'Demand Forecast'), 'Dashboard route is missing ML health');
$assert(str_contains($route, 'Recovery access'), 'Dashboard route is missing Recovery access readiness');
$assert(str_contains($route, 'Store continuity') || (str_contains($route, 'continuity') && str_contains($route, 'store')), 'Dashboard route is missing Store continuity');
foreach (['Recent Sales', 'Total Revenue', 'Inventory Value', 'Adjust Stock', 'Receive Stock'] as $forbidden) {
    $assert(!str_contains($route, $forbidden), "Dashboard route must not expose {$forbidden}");
}
$assert(str_contains($route, '300000'), 'Dashboard must schedule a five-minute refresh');
$assert(str_contains($route, 'actionInProgress'), 'Automatic refresh must defer while an action is in progress');
$assert(str_contains($route, 'dashboard-stale'), 'Dashboard must expose a stale state when refresh is deferred');
$assert(str_contains($route, '<a class="triage-item') && str_contains($route, 'data-dashboard-action>'), 'Issues must link directly to their controls');
$assert(!str_contains($route, 'data-resolution-') && !str_contains($route, 'data-triage-item'), 'Issue navigation must not require the removed selection panel');
$assert(!str_contains($route, 'control-shortcuts'), 'Dashboard must not show the removed Platform controls section');
$assert(str_contains($route, "<?php if (\$dashboard['state'] !== 'error'): ?>\n            <section class=\"risk-horizon\""), 'Unavailable data must not show zero-risk summary counts');
foreach (['.control-center-headlines', '.governance-queue', '.continuity-grid', '@media (max-width: 640px)'] as $needle) {
    $assert(str_contains($styles, $needle), "Dashboard styles are missing responsive contract {$needle}");
}

if ($failures) {
    fwrite(STDERR, "Super Administrator dashboard route tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Super Administrator dashboard route tests: passed\n";
