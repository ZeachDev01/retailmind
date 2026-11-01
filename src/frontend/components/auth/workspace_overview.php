<?php
// Shared by first-visit guidance and Help; links follow the active Workspace.
$guidanceTitle = current_role() === 'admin' ? 'Administrator Workspace' : 'Inventory Manager Workspace';
$guidanceLinks = current_role() === 'admin' ? [
    ['administrator/dashboard.php', 'Review Store operations', 'Find exceptions and work needing attention.'],
    ['administrator/store_calendar.php', 'Review the Store calendar', 'Set trading days and planned closures.'],
    ['user_manager/user_manager.php', 'Manage staff', 'Maintain operational staff accounts and access.'],
    ['report/report_generation.php', 'Review Store Reports', 'Check Store performance and reporting.'],
    ['report/forecast_exceptions.php', 'Review forecast exceptions', 'Check readiness and available oversight actions.'],
] : [
    ['inventory_management/replenishment_queue.php', 'Review replenishment needs', 'Suggestions support your buying decisions; they do not place orders or change stock.'],
    ['inventory_management/products.php', 'Set up Products', 'Use Add Product for details, counted opening stock, package sizes and stock rules.'],
    ['inventory_management/inventory_overview.php', 'Monitor stock', 'Check availability, low stock and expiry risk.'],
    ['report/stock_receiving.php', 'Record Stock Receiving', 'Record the quantities that actually arrived at the Store.'],
    ['inventory_management/inventory_counts.php', 'Check inventory counts', 'Count stock and resolve differences through Inventory Control.'],
    ['inventory_management/stock_issues.php', 'Review Stock Issues', 'Review reports and decide the permitted stock corrections.'],
];
?>
<h2 id="workspace-guidance-title"><?= htmlspecialchars($guidanceTitle) ?></h2>
<p><?= current_role() === 'admin'
    ? 'Oversee Store performance, staff and operational exceptions. Start with Store operations to see what needs attention.'
    : 'Review stock and replenishment needs, then record goods that arrive. Start with Replenishment Recommendations for your daily buying review.' ?></p>
<?php if ($guidanceIsHelp): ?>
<ul class="workspace-guidance-links">
    <?php foreach ($guidanceLinks as [$path, $label, $description]): ?>
    <li><a href="<?= htmlspecialchars(app_url('components/' . $path)) ?>"><?= htmlspecialchars($label) ?></a><p><?= htmlspecialchars($description) ?></p></li>
    <?php endforeach; ?>
</ul>
<?php else: ?>
<p><a href="<?= htmlspecialchars(app_url('components/' . $guidanceLinks[0][0])) ?>"><?= htmlspecialchars($guidanceLinks[0][1]) ?></a> &middot; <a href="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>">Explore Workspace guidance</a></p>
<?php endif; ?>
<p class="workspace-guidance-note">Guidance is optional. It does not confirm Store setup or change operational records. Reopen it anytime from Help in your account menu.</p>
