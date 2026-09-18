<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

use App\Attention\SystemClock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;

$displayName = trim((string)($_SESSION['full_name'] ?? 'Administrator'));
$requestedRange = filter_input(INPUT_GET, 'range', FILTER_VALIDATE_INT);
$selectedRange = in_array($requestedRange, [7, 30, 90], true) ? $requestedRange : 30;
$workspace = new StoreOperationsDashboardWorkspace(
    $pdo,
    new SystemClock(),
    new RoleCapabilityPolicy()
);
$dashboard = $workspace->load('admin', $selectedRange);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'Not available';
    }
    try {
        return (new DateTimeImmutable($value))->format('m-d-Y g:i A');
    } catch (Throwable) {
        return 'Unavailable';
    }
};
$formatMetric = static fn(float|int $value, bool $currency = false): string => ($currency ? '₱' : '') . number_format((float)$value, $currency ? 2 : 0);
$formatChange = static function (?float $change): string {
    if ($change === null) {
        return 'No prior baseline';
    }
    return ($change > 0 ? '+' : '') . number_format($change, 1) . '%';
};
$headlineOrder = [
    'sales_cash_exceptions' => ['Sales & cash exceptions', 'bi-exclamation-diamond'],
    'pending_approvals' => ['Pending approvals', 'bi-check2-square'],
    'inventory_risks' => ['Escalated inventory risks', 'bi-box-seam'],
    'fiscal_period' => ['Current Fiscal Period', 'bi-calendar-check'],
];
$comparisonOrder = [
    'sales' => ['Sales', true],
    'transactions' => ['Transactions', false],
    'unusual_discounts' => ['Unusual discounts', false],
    'reversals' => ['Reversals', false],
];
$rangeUrl = app_url('components/administrator/dashboard.php') . '?range=' . $selectedRange;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Operations</title>
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/style.css')) ?>">
</head>
<body class="admin-dashboard-page administrator-workspace">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content" data-dashboard-refresh data-stale-after="<?= $escape($dashboard['freshness']['stale_after'] ?? '') ?>">
            <header class="page-heading control-center-heading">
                <div>
                    <p class="page-kicker">Administrator workspace</p>
                    <h1>Store Operations</h1>
                    <p class="page-subtitle">Welcome, <?= $escape($displayName) ?>. Resolve Store exceptions, approvals, staff oversight, and operational risks.</p>
                    <p class="dashboard-freshness"><i class="bi bi-clock" aria-hidden="true"></i> Last updated <?= $escape($formatDate($dashboard['freshness']['generated_at'] ?? null)) ?></p>
                </div>
                <div class="page-heading-actions">
                    <form method="get" action="<?= $escape(app_url('components/administrator/dashboard.php')) ?>" data-dashboard-action>
                        <label class="sr-only" for="dashboardRange">Comparison range</label>
                        <select class="u-select-filter" id="dashboardRange" name="range" onchange="this.form.submit()">
                            <?php foreach ([7, 30, 90] as $range): ?>
                                <option value="<?= $range ?>" <?= $range === $selectedRange ? 'selected' : '' ?>><?= $range ?> days</option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <a class="btn btn-quiet btn-icon" href="<?= $escape($rangeUrl) ?>" data-manual-refresh data-dashboard-action><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh</a>
                </div>
            </header>

            <p class="dashboard-stale" role="status" aria-live="polite" hidden><i class="bi bi-hourglass-split" aria-hidden="true"></i> This view is stale. Automatic refresh is waiting for the current action to finish.</p>

            <section class="dashboard-section governance-queue store-attention-queue" aria-labelledby="store-attention-title">
                <div class="section-header">
                    <div><p class="section-eyebrow">Act first</p><h2 id="store-attention-title">Store attention queue</h2><p class="section-description">Actionable reversals, cash variances, unusual discounts, approvals, and inventory escalations.</p></div>
                </div>
                <?php if ($dashboard['state'] === 'error'): ?>
                    <div class="dashboard-state dashboard-state-error" role="alert"><i class="bi bi-cloud-slash" aria-hidden="true"></i><div><strong>Store status unavailable</strong><p><?= $escape($dashboard['message']) ?></p></div><a class="btn btn-quiet" href="<?= $escape($rangeUrl) ?>">Try again</a></div>
                <?php elseif ($dashboard['exceptions'] === []): ?>
                    <div class="dashboard-state dashboard-state-empty" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>No action required</strong><p><?= $escape($dashboard['message']) ?></p></div></div>
                <?php else: ?>
                    <div class="attention-list" aria-live="polite">
                        <?php foreach ($dashboard['exceptions'] as $item): ?>
                            <article class="attention-item attention-<?= $escape($item['severity']) ?>">
                                <span class="attention-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
                                <div class="attention-copy"><span class="severity-label"><?= $escape(ucfirst($item['severity'])) ?></span><strong><?= $escape($item['title']) ?></strong><span><?= $escape($item['detail']) ?> · <?= $escape($formatDate($item['detected_at'])) ?></span></div>
                                <a class="btn btn-small" href="<?= $escape(app_url($item['destination'])) ?>" data-dashboard-action>Review</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($dashboard['headline'] !== []): ?>
                <section aria-labelledby="store-status-title">
                    <div class="section-header control-center-section-header"><div><h2 id="store-status-title">Store status</h2><p class="section-description">Unresolved controls and the active accounting window.</p></div></div>
                    <div class="card-grid store-operations-headlines">
                        <?php foreach ($headlineOrder as $key => [$label, $icon]): $item = $dashboard['headline'][$key]; ?>
                            <article class="stat-card with-icon status-<?= $escape($item['status']) ?>">
                                <span class="stat-icon"><i class="bi <?= $escape($icon) ?>" aria-hidden="true"></i></span>
                                <div class="value"><?= $escape($item['value']) ?></div><div class="label"><?= $escape($label) ?></div><div class="hint"><?= $escape($item['detail']) ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="dashboard-section period-comparison" aria-labelledby="comparison-title">
                    <div class="section-header"><div><h2 id="comparison-title"><?= $selectedRange ?>-day performance</h2><p class="section-description">Compared with the Previous equivalent period.</p></div></div>
                    <div class="comparison-grid">
                        <?php foreach ($comparisonOrder as $key => [$label, $currency]): $item = $dashboard['comparison'][$key]; ?>
                            <article class="comparison-card"><span><?= $escape($label) ?></span><strong><?= $escape($formatMetric($item['current'], $currency)) ?></strong><small>Previous <?= $escape($formatMetric($item['previous'], $currency)) ?> · <?= $escape($formatChange($item['change_percent'])) ?></small></article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="operations-overview-grid">
                    <section class="dashboard-section" aria-labelledby="staff-shifts-title">
                        <div class="section-header"><div><h2 id="staff-shifts-title">Staff &amp; cashier shifts</h2><p class="section-description">Delegated staff and cash-control oversight.</p></div></div>
                        <div class="indicator-list">
                            <div class="indicator-item"><span><strong>Active Cashiers</strong><span>Available operational staff</span></span><b class="indicator-value"><?= (int)$dashboard['staff']['active_cashiers'] ?></b></div>
                            <div class="indicator-item"><span><strong>Active Inventory Managers</strong><span>Routine inventory operators</span></span><b class="indicator-value"><?= (int)$dashboard['staff']['active_inventory_managers'] ?></b></div>
                            <div class="indicator-item"><span><strong>Disabled Accounts</strong><span>Retained staff identities</span></span><b class="indicator-value"><?= (int)$dashboard['staff']['disabled_staff'] ?></b></div>
                            <div class="indicator-item"><span><strong>Open cashier shifts</strong><span>Currently active shifts</span></span><b class="indicator-value"><?= (int)$dashboard['shifts']['open'] ?></b></div>
                            <div class="indicator-item"><span><strong>Unreviewed variances</strong><span>Closed shifts awaiting review</span></span><b class="indicator-value"><?= (int)$dashboard['shifts']['unreviewed_variances'] ?></b></div>
                        </div>
                    </section>

                    <section class="dashboard-section" aria-labelledby="forecast-title">
                        <div class="section-header"><div><h2 id="forecast-title">Demand Forecast performance</h2><p class="section-description">Operational quality and exceptions; model controls are not available here.</p></div></div>
                        <div class="metric-pair"><article><span>Evaluated products</span><strong><?= (int)$dashboard['forecast']['evaluated_products'] ?></strong></article><article><span>Forecast accuracy</span><strong><?= $dashboard['forecast']['accuracy_percent'] === null ? 'Not available' : $escape(number_format((float)$dashboard['forecast']['accuracy_percent'], 1) . '%') ?></strong></article></div>
                        <div class="indicator-item"><span><strong>Operational forecast exceptions</strong><span>Low-confidence or replenishment-signaled products</span></span><b class="indicator-value"><?= (int)$dashboard['forecast']['operational_exceptions'] ?></b></div>
                    </section>

                    <section class="dashboard-section inventory-escalation-summary" aria-labelledby="inventory-title">
                        <div class="section-header"><div><h2 id="inventory-title">Inventory escalations</h2><p class="section-description">Summary and escalation only; routine inventory execution remains with the Inventory Manager.</p></div></div>
                        <div class="metric-pair"><article><span>Escalated</span><strong><?= (int)$dashboard['inventory']['escalated'] ?></strong></article><article><span>Out of stock</span><strong><?= (int)$dashboard['inventory']['out_of_stock'] ?></strong></article><article><span>Low stock</span><strong><?= (int)$dashboard['inventory']['low_stock'] ?></strong></article></div>
                    </section>
                </div>
            <?php endif; ?>

            <section class="dashboard-section control-center-actions" aria-labelledby="store-tools-title">
                <div class="section-header"><div><h2 id="store-tools-title">Store governance</h2><p class="section-description">Administrator actions for Store oversight and delegated decisions.</p></div></div>
                <div class="quick-actions">
                    <?php foreach ($dashboard['actions'] as $action): ?>
                        <a class="quick-action" href="<?= $escape(app_url($action['destination'])) ?>" data-dashboard-action><i class="bi <?= $escape($action['icon']) ?>" aria-hidden="true"></i><strong><?= $escape($action['label']) ?></strong><span><?= $escape($action['description']) ?></span></a>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
    <script>
    (() => {
        const dashboard = document.querySelector('[data-dashboard-refresh]');
        const staleNotice = document.querySelector('.dashboard-stale');
        if (!dashboard || !staleNotice) return;

        let actionInProgress = false;
        const interactiveFields = 'input, select, textarea, [contenteditable="true"]';
        document.addEventListener('focusin', event => { if (event.target.matches?.(interactiveFields)) actionInProgress = true; });
        document.addEventListener('focusout', event => { if (event.target.matches?.(interactiveFields)) actionInProgress = false; });
        document.addEventListener('submit', () => { actionInProgress = true; });
        document.querySelectorAll('[data-dashboard-action]').forEach(element => element.addEventListener('click', () => { actionInProgress = true; }));

        const refreshWhenSafe = () => {
            if (actionInProgress || document.hidden || window.__retailMindActionInProgress === true) {
                staleNotice.hidden = false;
                window.setTimeout(refreshWhenSafe, 30000);
                return;
            }
            window.location.assign('<?= $escape($rangeUrl) ?>');
        };
        const staleAt = Date.parse((dashboard.dataset.staleAfter || '').replace(' ', 'T'));
        window.setInterval(() => { if (Number.isFinite(staleAt) && Date.now() >= staleAt) staleNotice.hidden = false; }, 30000);
        window.setTimeout(refreshWhenSafe, 300000);
    })();
    </script>
</body>
</html>
