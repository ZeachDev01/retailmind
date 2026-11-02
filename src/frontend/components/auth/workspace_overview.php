<?php
// Shared by first-visit guidance and Help; links follow the active Workspace.
$isAdmin = current_role() === 'admin';
$guidanceTitle = $isAdmin ? 'Administrator Workspace' : 'Inventory Manager Workspace';

$adminWorkflows = [
    'store_calendar.php' => [
        'title' => 'Store setup & calendar',
        'icon' => 'calendar',
        'path' => 'administrator/store_calendar.php',
        'link_label' => 'Review the Store calendar',
        'summary' => 'Set trading days and planned closures.',
        'guidance' => 'Record trading days and holiday closures so operational planning reflects your schedule. Operational setup prepares Store trading, separate from customer sales history: completing setup does not qualify or activate forecasting models. Genuine customer sales history accumulates through daily sales transactions over time. Stock receiving does not establish customer demand history; any historical sales import stays within the existing technical workflow. Models qualify over time as genuine sales history accumulates.',
    ],
    'user_manager.php' => [
        'title' => 'Staff management',
        'icon' => 'conference_call',
        'path' => 'user_manager/user_manager.php',
        'link_label' => 'Manage staff',
        'summary' => 'Maintain operational staff accounts and access.',
        'guidance' => 'Maintain operational staff accounts (cashiers, inventory managers, administrators), update user status, reset passwords, and manage role assignments. Staff access follows active Workspace permissions.',
    ],
    'report_generation.php' => [
        'title' => 'Store reports',
        'icon' => 'statistics',
        'path' => 'report/report_generation.php',
        'link_label' => 'Review Store Reports',
        'summary' => 'Check Store performance and reporting.',
        'guidance' => 'Review Store performance across sales, gross profit, cashier shifts, inventory, and refunds. Filter trading periods and export CSV summaries for operational analysis.',
    ],
    'forecast_exceptions.php' => [
        'title' => 'Forecasting oversight',
        'icon' => 'disclaimer',
        'path' => 'report/forecast_exceptions.php',
        'link_label' => 'Review forecast exceptions',
        'summary' => 'Check readiness and available oversight actions.',
        'guidance' => 'Review products with forecasting exceptions (low confidence, WAPE above alert threshold, or insufficient sales days). Model qualification and observation happen over time as genuine customer sales history accumulates. Permitted actions: inspect exceptions, review replenishment recommendations, and view analytics if permitted. Nonfunctional approval actions are not advertised.',
    ],
    'dashboard.php' => [
        'title' => 'Store operations & exceptions',
        'icon' => 'shop',
        'path' => 'administrator/dashboard.php',
        'link_label' => 'Review Store operations',
        'summary' => 'Find exceptions and work needing attention.',
        'guidance' => 'Oversee Store performance, staff and operational exceptions. Monitor daily sales and gross profit trends, and review the Needs Attention queue for cashier shift variances, unresolved GCash exchanges, and pending stock issues before closing trading periods.',
    ],
];

$inventoryWorkflows = [
    'replenishment_queue.php' => [
        'title' => 'Replenishment recommendations',
        'icon' => 'workflow',
        'path' => 'inventory_management/replenishment_queue.php',
        'link_label' => 'Review replenishment needs',
        'summary' => 'Start daily buying review: buying quantities, basis and reasons. Forecast details are optional supporting information.',
        'guidance' => 'Start your daily buying review here. Suggestions show recommended buying quantities, basis, and reasons to focus on products needing attention. Without an approved forecast, suggestions use stock rules; demand forecasts and forecast details remain optional supporting information. Staff decide what to buy based on Store knowledge and supplier availability; reviewing recommendations creates no purchase or inventory change. Record actual arrivals in Stock Receiving.',
    ],
    'products.php' => [
        'title' => 'Product setup',
        'icon' => 'package',
        'path' => 'inventory_management/products.php',
        'link_label' => 'Set up Products',
        'summary' => 'Use Add Product for details, counted opening stock, package sizes and stock rules.',
        'guidance' => 'Use the existing Add Product form to define product details, physically counted opening stock, package unit sizes, and replenishment stock rules (reorder level, safety stock, MOQ). Enter values based on Store knowledge rather than relying on defaults. There is one product setup procedure; no per-product onboarding confirmations are required. Confirm setup in the checklist once initial catalog and opening stock are ready.',
    ],
    'inventory_overview.php' => [
        'title' => 'Stock monitoring',
        'icon' => 'deployment',
        'path' => 'inventory_management/inventory_overview.php',
        'link_label' => 'Monitor stock',
        'summary' => 'Check availability, low stock and expiry risk across the Store catalog.',
        'guidance' => 'Monitor inventory levels, stock availability, out-of-stock items, low stock warnings, and expiry risk across the Store catalog. Use metric cards to filter products requiring attention and jump directly to stock receiving or replenishment.',
    ],
    'stock_receiving.php' => [
        'title' => 'Stock Receiving',
        'icon' => 'shipped',
        'path' => 'report/stock_receiving.php',
        'link_label' => 'Record Stock Receiving',
        'summary' => 'Record the actual goods and quantities that arrived at the Store.',
        'guidance' => 'Record goods and quantities that physically arrived at the Store. While replenishment suggestions guide purchasing decisions based on Store knowledge and availability, Stock Receiving records actual receipts and commits stock to inventory. Stock receiving does not establish customer demand history for forecasting.',
    ],
    'inventory_counts.php' => [
        'title' => 'Inventory counts',
        'icon' => 'survey',
        'path' => 'inventory_management/inventory_counts.php',
        'link_label' => 'Check inventory counts',
        'summary' => 'Count stock and resolve differences through Inventory Control.',
        'guidance' => 'Conduct periodic physical inventory counts to verify on-hand quantities against recorded stock. Record counted items and resolve discrepancies through Inventory Control count adjustments.',
    ],
    'stock_issues.php' => [
        'title' => 'Stock Issue review',
        'icon' => 'high_priority',
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
$walkthroughProgress = isset($pdo, $_SESSION['user_id'])
    ? \App\Guidance\WorkspaceWalkthroughService::getProgress($pdo, (int)$_SESSION['user_id'], current_role())
    : ['status' => null, 'active_topic' => null, 'active_step' => 0, 'completed_topics' => [], 'invitation_status' => null];
$activeTopicId = $walkthroughProgress['active_topic'] ?? null;
$activeTopicDef = $activeTopicId ? \App\Guidance\WorkspaceWalkthroughService::findTopic(current_role(), $activeTopicId) : null;
$completedTopics = $walkthroughProgress['completed_topics'] ?? [];
$allWalkthroughPaths = \App\Guidance\WorkspaceWalkthroughService::paths(current_role());
$topicIcons = [
    'store_settings' => 'shop',
    'registers' => 'self_service_kiosk',
    'fiscal_periods' => 'calendar',
    'user_manager' => 'conference_call',
    'store_calendar' => 'calendar',
    'dashboard' => 'shop',
    'report_generation' => 'statistics',
    'replenishment_queue' => 'workflow',
    'products' => 'package',
    'stock_receiving' => 'shipped',
];
$getOrientedTopics = $allWalkthroughPaths['get_oriented']['topics'] ?? [];
$dailyOversightTopics = $allWalkthroughPaths['daily_oversight']['topics'] ?? [];
$imTopics = $allWalkthroughPaths['first_useful_tasks']['topics'] ?? [];
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
    <?php if ($activeTopicDef && !empty($activeTopicDef['steps'])): ?>
        <section class="guidance-continue-card" aria-label="Continue guidance">
            <div>
                <h3>Continue where you left off</h3>
                <p>Resume <?= htmlspecialchars($activeTopicDef['title']) ?> walkthrough (Step <?= ($walkthroughProgress['active_step'] ?? 0) + 1 ?> of <?= count($activeTopicDef['steps']) ?>)</p>
            </div>
            <a href="<?= htmlspecialchars(app_url($activeTopicDef['path'] . '?guidance=' . $activeTopicDef['id'])) ?>" class="btn btn-primary">Resume <?= htmlspecialchars($activeTopicDef['title']) ?> (Step <?= ($walkthroughProgress['active_step'] ?? 0) + 1 ?> of <?= count($activeTopicDef['steps']) ?>)</a>
        </section>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
        <section class="guidance-walkthroughs" aria-labelledby="get-oriented-title">
            <div class="guidance-walkthroughs-header">
                <h3 id="get-oriented-title">Get oriented</h3>
                <p>Follow working walkthroughs on existing pages. Complete steps at your pace without altering operational records.</p>
            </div>
            <div class="walkthrough-topics-list">
                <?php
                foreach ($getOrientedTopics as $tId => $tDef):
                    $isDone = in_array($tId, $walkthroughProgress['completed_topics'] ?? [], true);
                    $isInProgress = (($walkthroughProgress['active_topic'] ?? null) === $tId);
                    $hasSteps = !empty($tDef['steps']);
                    $icon = $topicIcons[$tId] ?? 'shop';
                ?>
                <div class="walkthrough-topic-card<?= $hasSteps ? '' : ' walkthrough-topic-upcoming' ?>">
                    <div class="walkthrough-topic-main">
                        <span class="walkthrough-topic-icon" aria-hidden="true"><?= sidebar_icon($icon) ?></span>
                        <div>
                            <h4 class="walkthrough-topic-title"><?= htmlspecialchars($tDef['title']) ?></h4>
                            <p class="walkthrough-topic-desc"><?= htmlspecialchars($tDef['summary']) ?></p>
                        </div>
                    </div>
                    <div class="walkthrough-topic-actions">
                        <?php if ($hasSteps): ?>
                            <?php if ($isDone): ?>
                                <span class="guidance-completion-badge"><span class="guidance-checkmark" aria-hidden="true">✓</span> Guidance finished</span>
                                <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tId . '&replay=1')) ?>" class="btn btn-secondary">Replay</a>
                            <?php elseif ($isInProgress): ?>
                                <span class="guidance-progress-badge">In progress (Step <?= ($walkthroughProgress['active_step'] ?? 0) + 1 ?> of <?= count($tDef['steps']) ?>)</span>
                                <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tId)) ?>" class="btn btn-primary">Continue</a>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tId)) ?>" class="btn btn-primary">Start walkthrough</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars(app_url($tDef['path'])) ?>" class="btn btn-secondary">Go to <?= htmlspecialchars($tDef['title']) ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="guidance-walkthroughs-daily" aria-labelledby="daily-oversight-title">
            <div class="guidance-walkthroughs-header">
                <h3 id="daily-oversight-title">Daily oversight</h3>
                <p>Learn recurring review workflows for your Workspace.</p>
            </div>
            <div class="walkthrough-topics-list">
                <?php
                foreach ($dailyOversightTopics as $tKey => $tDef):
                    $isDone = in_array($tKey, $completedTopics, true);
                    $isInProgress = (($walkthroughProgress['active_topic'] ?? null) === $tKey);
                    $stepTotal = count($tDef['steps']);
                    $icon = $topicIcons[$tKey] ?? 'shop';
                ?>
                <div class="walkthrough-topic-card" id="topic-card-<?= htmlspecialchars($tKey) ?>">
                    <div class="walkthrough-topic-main">
                        <span class="walkthrough-topic-icon" aria-hidden="true"><?= sidebar_icon($icon) ?></span>
                        <div>
                            <h4 class="walkthrough-topic-title"><?= htmlspecialchars($tDef['title']) ?></h4>
                            <p class="walkthrough-topic-desc"><?= htmlspecialchars($tDef['summary']) ?></p>
                        </div>
                    </div>
                    <div class="walkthrough-topic-actions">
                        <?php if ($isDone): ?>
                            <span class="guidance-completion-badge"><span class="guidance-checkmark" aria-hidden="true">✓</span> Guidance finished</span>
                            <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tKey . '&replay=1')) ?>" class="btn btn-secondary">Replay</a>
                        <?php elseif ($isInProgress): ?>
                            <span class="guidance-progress-badge">In progress (Step <?= ($walkthroughProgress['active_step'] ?? 0) + 1 ?> of <?= $stepTotal ?>)</span>
                            <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tKey)) ?>" class="btn btn-primary">Continue</a>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tKey)) ?>" class="btn btn-primary">Start walkthrough</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="guidance-walkthroughs" aria-labelledby="first-useful-tasks-title">
            <div class="guidance-walkthroughs-header">
                <h3 id="first-useful-tasks-title">First useful tasks</h3>
                <p>Follow working walkthroughs on existing pages. Complete steps at your pace without altering operational records.</p>
            </div>
            <div class="walkthrough-topics-list">
                <?php
                foreach ($imTopics as $tId => $tDef):
                    $isDone = in_array($tId, $completedTopics, true);
                    $isInProgress = ($walkthroughProgress['active_topic'] === $tId);
                    $stepTotal = count($tDef['steps']);
                    $iconName = $topicIcons[$tId] ?? 'workflow';
                ?>
                <div class="walkthrough-topic-card" id="topic-card-<?= htmlspecialchars($tId) ?>">
                    <div class="walkthrough-topic-main">
                        <span class="walkthrough-topic-icon" aria-hidden="true"><?= sidebar_icon($iconName) ?></span>
                        <div>
                            <h4 class="walkthrough-topic-title"><?= htmlspecialchars($tDef['title']) ?></h4>
                            <p class="walkthrough-topic-desc"><?= htmlspecialchars($tDef['summary']) ?></p>
                        </div>
                    </div>
                    <div class="walkthrough-topic-actions">
                        <?php if ($isDone): ?>
                            <span class="guidance-completion-badge"><span class="guidance-checkmark" aria-hidden="true">✓</span> Guidance finished</span>
                            <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tId . '&replay=1')) ?>" class="btn btn-secondary">Replay</a>
                        <?php elseif ($isInProgress): ?>
                            <span class="guidance-progress-badge">In progress (Step <?= $walkthroughProgress['active_step'] + 1 ?> of <?= $stepTotal ?>)</span>
                            <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tId)) ?>" class="btn btn-primary">Continue</a>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tId)) ?>" class="btn btn-primary">Start walkthrough</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php elseif ($activeWorkflow): ?>
    <p><?= htmlspecialchars($activeWorkflow['guidance']) ?></p>
    <p>
        <?php foreach ($allWalkthroughPaths as $pDef): ?>
            <?php foreach ($pDef['topics'] as $tId => $tDef): ?>
                <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tId)) ?>"><?= htmlspecialchars($pDef['title'] . ': ' . $tDef['title']) ?></a> &middot;
            <?php endforeach; ?>
        <?php endforeach; ?>
        <a href="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>">Explore Workspace guidance</a>
    </p>
<?php else: ?>
    <p><?= htmlspecialchars($introText) ?></p>
    <p>
        <a href="<?= htmlspecialchars($primaryActionUrl) ?>"><?= htmlspecialchars($primaryActionLabel) ?></a> &middot;
        <?php foreach ($allWalkthroughPaths as $pDef): ?>
            <?php foreach ($pDef['topics'] as $tId => $tDef): ?>
                <a href="<?= htmlspecialchars(app_url($tDef['path'] . '?guidance=' . $tId)) ?>"><?= htmlspecialchars($pDef['title'] . ': ' . $tDef['title']) ?></a> &middot;
            <?php endforeach; ?>
        <?php endforeach; ?>
        <a href="<?= htmlspecialchars(app_url('components/auth/help.php')) ?>">Explore Workspace guidance</a>
    </p>
<?php endif; ?>
<?php if (!$guidanceIsHelp): ?>
<p class="workspace-guidance-note">Guidance is optional. It does not confirm Store setup or change operational records. Reopen it anytime from Help in your account menu.</p>
<?php endif; ?>
