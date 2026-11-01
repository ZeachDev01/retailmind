<?php
// Shared by first-visit guidance and Help; links follow the active Workspace.
$isAdmin = current_role() === 'admin';
$guidanceTitle = $isAdmin ? 'Administrator Workspace' : 'Inventory Manager Workspace';

$adminWorkflows = [
    'store_calendar.php' => [
        'title' => 'Store setup & calendar',
        'path' => 'administrator/store_calendar.php',
        'link_label' => 'Review the Store calendar',
        'summary' => 'Set trading days and planned closures.',
        'guidance' => 'Record trading days and holiday closures so operational planning reflects your schedule. Operational setup prepares Store trading, separate from customer sales history: completing setup does not qualify or activate forecasting models. Genuine customer sales history accumulates through daily sales transactions over time. Stock receiving does not establish customer demand history; any historical sales import stays within the existing technical workflow. Models qualify over time as genuine sales history accumulates.',
    ],
    'user_manager.php' => [
        'title' => 'Staff management',
        'path' => 'user_manager/user_manager.php',
        'link_label' => 'Manage staff',
        'summary' => 'Maintain operational staff accounts and access.',
        'guidance' => 'Maintain operational staff accounts (cashiers, inventory managers, administrators), update user status, reset passwords, and manage role assignments. Staff access follows active Workspace permissions.',
    ],
    'report_generation.php' => [
        'title' => 'Store reports',
        'path' => 'report/report_generation.php',
        'link_label' => 'Review Store Reports',
        'summary' => 'Check Store performance and reporting.',
        'guidance' => 'Review Store performance across sales, gross profit, cashier shifts, inventory, and refunds. Filter trading periods and export CSV summaries for operational analysis.',
    ],
    'forecast_exceptions.php' => [
        'title' => 'Forecasting oversight',
        'path' => 'report/forecast_exceptions.php',
        'link_label' => 'Review forecast exceptions',
        'summary' => 'Check readiness and available oversight actions.',
        'guidance' => 'Review products with forecasting exceptions (low confidence, WAPE above alert threshold, or insufficient sales days). Model qualification and observation happen over time as genuine customer sales history accumulates. Permitted actions: inspect exceptions, review replenishment recommendations, and view analytics if permitted. Nonfunctional approval actions are not advertised.',
    ],
    'dashboard.php' => [
        'title' => 'Store operations & exceptions',
        'path' => 'administrator/dashboard.php',
        'link_label' => 'Review Store operations',
        'summary' => 'Find exceptions and work needing attention.',
        'guidance' => 'Oversee Store performance, staff and operational exceptions. Monitor daily sales and gross profit trends, and review the Needs Attention queue for cashier shift variances, unresolved GCash exchanges, and pending stock issues before closing trading periods.',
    ],
];

$inventoryGuidanceLinks = [
    ['inventory_management/replenishment_queue.php', 'Review replenishment needs', 'Suggestions support your buying decisions; they do not place orders or change stock.'],
    ['inventory_management/products.php', 'Set up Products', 'Use Add Product for details, counted opening stock, package sizes and stock rules.'],
    ['inventory_management/inventory_overview.php', 'Monitor stock', 'Check availability, low stock and expiry risk.'],
    ['report/stock_receiving.php', 'Record Stock Receiving', 'Record the quantities that actually arrived at the Store.'],
    ['inventory_management/inventory_counts.php', 'Check inventory counts', 'Count stock and resolve differences through Inventory Control.'],
    ['inventory_management/stock_issues.php', 'Review Stock Issues', 'Review reports and decide the permitted stock corrections.'],
];

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$activeWorkflow = ($isAdmin && isset($adminWorkflows[$currentScript])) ? $adminWorkflows[$currentScript] : null;
?>
<h2 id="workspace-guidance-title">
    <?= htmlspecialchars($guidanceTitle) ?><?= ($activeWorkflow && !$guidanceIsHelp) ? ' &middot; ' . htmlspecialchars($activeWorkflow['title']) : '' ?>
</h2>

<?php if ($isAdmin): ?>
    <?php if ($guidanceIsHelp): ?>
        <p>Oversee Store performance, staff and operational exceptions. Start with Store operations to see what needs attention.</p>
        <ul class="workspace-guidance-links">
            <?php foreach ($adminWorkflows as $wf): ?>
            <li>
                <a href="<?= htmlspecialchars(app_url('components/' . $wf['path'])) ?>"><?= htmlspecialchars($wf['link_label']) ?></a>
                <strong class="workspace-workflow-heading"><?= htmlspecialchars($wf['title']) ?></strong>
                <p><?= htmlspecialchars($wf['guidance']) ?></p>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($activeWorkflow): ?>
        <p><?= htmlspecialchars($activeWorkflow['guidance']) ?></p>
        <p><a href="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>">Explore Workspace guidance</a></p>
    <?php else: ?>
        <p>Oversee Store performance, staff and operational exceptions. Start with Store operations to see what needs attention.</p>
        <p><a href="<?= htmlspecialchars(app_url('components/administrator/dashboard.php')) ?>">Review Store operations</a> &middot; <a href="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>">Explore Workspace guidance</a></p>
    <?php endif; ?>
<?php else: ?>
    <p>Review stock and replenishment needs, then record goods that arrive. Start with Replenishment Recommendations for your daily buying review.</p>
    <?php if ($guidanceIsHelp): ?>
    <ul class="workspace-guidance-links">
        <?php foreach ($inventoryGuidanceLinks as [$path, $label, $description]): ?>
        <li><a href="<?= htmlspecialchars(app_url('components/' . $path)) ?>"><?= htmlspecialchars($label) ?></a><p><?= htmlspecialchars($description) ?></p></li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p><a href="<?= htmlspecialchars(app_url('components/' . $inventoryGuidanceLinks[0][0])) ?>"><?= htmlspecialchars($inventoryGuidanceLinks[0][1]) ?></a> &middot; <a href="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>">Explore Workspace guidance</a></p>
    <?php endif; ?>
<?php endif; ?>
<p class="workspace-guidance-note">Guidance is optional. It does not confirm Store setup or change operational records. Reopen it anytime from Help in your account menu.</p>
