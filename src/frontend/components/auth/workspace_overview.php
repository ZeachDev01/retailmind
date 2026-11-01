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

$inventoryWorkflows = [
    'replenishment_queue.php' => [
        'title' => 'Replenishment recommendations',
        'path' => 'inventory_management/replenishment_queue.php',
        'link_label' => 'Review replenishment needs',
        'summary' => 'Start daily buying review: buying quantities, basis and reasons. Forecast details are optional supporting information.',
        'guidance' => 'Start your daily buying review here. Suggestions show recommended buying quantities, basis, and reasons to focus on products needing attention. Without an approved forecast, suggestions use stock rules; demand forecasts and forecast details remain optional supporting information. Staff decide what to buy based on Store knowledge and supplier availability; reviewing recommendations creates no purchase or inventory change. Record actual arrivals in Stock Receiving.',
    ],
    'products.php' => [
        'title' => 'Product setup',
        'path' => 'inventory_management/products.php',
        'link_label' => 'Set up Products',
        'summary' => 'Use Add Product for details, counted opening stock, package sizes and stock rules.',
        'guidance' => 'Use the existing Add Product form to define product details, physically counted opening stock, package unit sizes, and replenishment stock rules (reorder level, safety stock, MOQ). Enter values based on Store knowledge rather than relying on defaults. There is one product setup procedure; no per-product onboarding confirmations are required. Confirm setup in the checklist once initial catalog and opening stock are ready.',
    ],
    'inventory_overview.php' => [
        'title' => 'Stock monitoring',
        'path' => 'inventory_management/inventory_overview.php',
        'link_label' => 'Monitor stock',
        'summary' => 'Check availability, low stock and expiry risk across the Store catalog.',
        'guidance' => 'Monitor inventory levels, stock availability, out-of-stock items, low stock warnings, and expiry risk across the Store catalog. Use metric cards to filter products requiring attention and jump directly to stock receiving or replenishment.',
    ],
    'stock_receiving.php' => [
        'title' => 'Stock Receiving',
        'path' => 'report/stock_receiving.php',
        'link_label' => 'Record Stock Receiving',
        'summary' => 'Record the actual goods and quantities that arrived at the Store.',
        'guidance' => 'Record goods and quantities that physically arrived at the Store. While replenishment suggestions guide purchasing decisions based on Store knowledge and availability, Stock Receiving records actual receipts and commits stock to inventory. Stock receiving does not establish customer demand history for forecasting.',
    ],
    'inventory_counts.php' => [
        'title' => 'Inventory counts',
        'path' => 'inventory_management/inventory_counts.php',
        'link_label' => 'Check inventory counts',
        'summary' => 'Count stock and resolve differences through Inventory Control.',
        'guidance' => 'Conduct periodic physical inventory counts to verify on-hand quantities against recorded stock. Record counted items and resolve discrepancies through Inventory Control count adjustments.',
    ],
    'stock_issues.php' => [
        'title' => 'Stock Issue review',
        'path' => 'inventory_management/stock_issues.php',
        'link_label' => 'Review Stock Issues',
        'summary' => 'Review damage, loss or shrinkage reports and decide permitted stock corrections.',
        'guidance' => 'Review reported stock issues including damage, expired goods, loss, and shrinkage. Evaluate reports and apply permitted stock corrections to keep inventory records accurate.',
    ],
];

$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$currentScript = basename($scriptPath);
$workflows = $isAdmin ? $adminWorkflows : $inventoryWorkflows;
$activeWorkflow = null;
if (isset($workflows[$currentScript])) {
    $activeWorkflow = $workflows[$currentScript];
} else {
    foreach ($workflows as $wf) {
        if (str_ends_with($scriptPath, $wf['path'])) {
            $activeWorkflow = $wf;
            break;
        }
    }
}
?>
<h2 id="workspace-guidance-title">
    <?= htmlspecialchars($guidanceTitle) ?><?= ($activeWorkflow && !$guidanceIsHelp) ? ' &middot; ' . htmlspecialchars($activeWorkflow['title']) : '' ?>
</h2>

<?php
$introText = $isAdmin
    ? 'Oversee Store performance, staff and operational exceptions. Start with Store operations to see what needs attention.'
    : 'Review stock and replenishment needs, then record goods that arrive. Start with Replenishment Recommendations for your daily buying review.';
$primaryActionUrl = $isAdmin
    ? app_url('components/administrator/dashboard.php')
    : app_url('components/inventory_management/replenishment_queue.php');
$primaryActionLabel = $isAdmin
    ? 'Review Store operations'
    : 'Review replenishment needs';
?>
<?php if ($guidanceIsHelp): ?>
    <p><?= htmlspecialchars($introText) ?></p>
    <ul class="workspace-guidance-links">
        <?php foreach ($workflows as $wf): ?>
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
    <p><?= htmlspecialchars($introText) ?></p>
    <p><a href="<?= htmlspecialchars($primaryActionUrl) ?>"><?= htmlspecialchars($primaryActionLabel) ?></a> &middot; <a href="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>">Explore Workspace guidance</a></p>
<?php endif; ?>
<p class="workspace-guidance-note">Guidance is optional. It does not confirm Store setup or change operational records. Reopen it anytime from Help in your account menu.</p>
