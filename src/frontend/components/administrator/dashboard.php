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
$formatMetric = static fn(float|int $value, bool $currency = false): string => $currency
    ? ($value < 0 ? '-₱' : '₱') . number_format(abs((float)$value), 2)
    : number_format((float)$value, 0);
$formatCurrency = static fn(float|int $value): string => $formatMetric($value, true);
$formatChange = static function (?float $change): string {
    if ($change === null) {
        return 'No prior baseline';
    }
    return ($change > 0 ? '+' : '') . number_format($change, 1) . '%';
};
$netSales = $dashboard['net_sales'] ?? null;
$hasNetSales = $netSales !== null && is_array($netSales);
$netSalesPoints = $hasNetSales ? ($netSales['points'] ?? []) : [];
$netSalesTotal = $hasNetSales ? ($netSales['total'] ?? 0.0) : null;
$netSalesHasActivity = $hasNetSales && ($netSales['has_activity'] ?? false);
$netSalesAsOf = $hasNetSales ? ($netSales['as_of'] ?? null) : null;
$headlineOrder = [
    'sales_cash_exceptions' => ['Sales & cash exceptions', 'bi-exclamation-diamond'],
    'inventory_risks' => ['Pending Stock Issues', 'bi-box-seam'],
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
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Operations</title>
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/dashboard.css')) ?>">
</head>
<body class="admin-dashboard-page administrator-workspace">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content" data-dashboard-refresh data-stale-after="<?= $escape($dashboard['freshness']['stale_after'] ?? '') ?>">
            <header class="page-heading control-center-heading">
                <div>
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
                    <div><h2 id="store-attention-title">Store attention queue</h2><p class="section-description">Reversals, cash variances, unusual discounts, and read-only Stock Issue oversight.</p></div>
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

            <section class="dashboard-section net-sales-section" aria-labelledby="net-sales-title">
                <div class="section-header control-center-section-header">
                    <div>
                        <div class="net-sales-title-wrap">
                            <h2 id="net-sales-title">Today's Net Sales</h2>
                            <span class="period-badge ongoing">Ongoing</span>
                        </div>
                        <p class="section-description">Philippine time (UTC+08:00) · Completed sales after discounts minus issued refunds<?php if ($netSalesAsOf): ?> · As of <?= $escape($formatDate($netSalesAsOf)) ?><?php endif; ?></p>
                    </div>
                    <div class="net-sales-summary-stat">
                        <span class="net-sales-summary-label">Today's Net Sales</span>
                        <?php if ($hasNetSales): ?>
                            <strong class="net-sales-summary-value <?= $netSalesTotal < 0 ? 'is-negative' : '' ?>"><?= $escape($formatCurrency($netSalesTotal)) ?></strong>
                        <?php else: ?>
                            <strong class="net-sales-summary-value is-unavailable">Unavailable</strong>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$hasNetSales): ?>
                    <div class="dashboard-state dashboard-state-error" role="alert">
                        <i class="bi bi-cloud-slash" aria-hidden="true"></i>
                        <div>
                            <strong>Today's Net Sales unavailable</strong>
                            <p>Net sales metrics could not be loaded. Refresh to try again.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if (!$netSalesHasActivity): ?>
                        <p class="section-description net-sales-empty-note"><i class="bi bi-info-circle" aria-hidden="true"></i> No sales or refunds recorded today.</p>
                    <?php endif; ?>

                    <div class="net-sales-chart-wrapper">
                        <canvas id="net-sales-hourly-chart" role="img" aria-label="Today's hourly Net Sales in Philippine pesos"></canvas>
                    </div>

                    <div class="table-wrap u-table-flat net-sales-table-wrap" role="region" aria-label="Today's hourly Net Sales breakdown" tabindex="0">
                        <table class="net-sales-table" aria-label="Exact hourly Net Sales breakdown for Today">
                            <thead>
                                <tr>
                                    <th scope="col">Hour</th>
                                    <th scope="col" class="net-sales-breakdown-col">Sales</th>
                                    <th scope="col" class="net-sales-breakdown-col">Refunds</th>
                                    <th scope="col">Net Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($netSalesPoints as $point): ?>
                                    <tr>
                                        <td data-label="Hour"><strong><?= $escape($point['label']) ?></strong></td>
                                        <td data-label="Sales" class="net-sales-breakdown-col"><?= $escape($formatCurrency($point['sales'])) ?></td>
                                        <td data-label="Refunds" class="net-sales-breakdown-col"><?= $escape($formatCurrency($point['refunds'])) ?></td>
                                        <td data-label="Net Sales" class="<?= $point['net_sales'] < 0 ? 'text-negative' : '' ?>">
                                            <?= $escape($formatCurrency($point['net_sales'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                        <div class="metric-pair">
                            <article><span>Evaluated products</span><strong><?= (int)$dashboard['forecast']['evaluated_products'] ?></strong></article>
                            <article><span>7-Day MAE</span><strong><?= isset($dashboard['forecast']['evaluations'][7]['mae']) ? $escape(number_format($dashboard['forecast']['evaluations'][7]['mae'], 2)) : 'N/A' ?></strong></article>
                            <article><span>14-Day MAE</span><strong><?= isset($dashboard['forecast']['evaluations'][14]['mae']) ? $escape(number_format($dashboard['forecast']['evaluations'][14]['mae'], 2)) : 'N/A' ?></strong></article>
                            <article><span>28-Day MAE</span><strong><?= isset($dashboard['forecast']['evaluations'][28]['mae']) ? $escape(number_format($dashboard['forecast']['evaluations'][28]['mae'], 2)) : 'N/A' ?></strong></article>
                        </div>
                        <div class="indicator-item"><span><strong>Operational forecast exceptions</strong><span>Products with Low or Insufficient Forecast Readiness</span></span><b class="indicator-value"><?= (int)$dashboard['forecast']['operational_exceptions'] ?></b></div>
                    </section>

                    <section class="dashboard-section inventory-escalation-summary" aria-labelledby="inventory-title">
                        <div class="section-header"><div><h2 id="inventory-title">Inventory oversight</h2><p class="section-description">Read-only stock and Stock Issue summary; routine inventory execution remains with the Inventory Manager.</p></div></div>
                        <div class="metric-pair"><article><span>Pending Stock Issues</span><strong><?= (int)$dashboard['inventory']['escalated'] ?></strong></article><article><span>Out of stock</span><strong><?= (int)$dashboard['inventory']['out_of_stock'] ?></strong></article><article><span>Low stock</span><strong><?= (int)$dashboard['inventory']['low_stock'] ?></strong></article></div>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
    (() => {
        <?php if ($hasNetSales && !empty($netSalesPoints)): ?>
        if (typeof Chart !== 'undefined') {
            const canvas = document.getElementById('net-sales-hourly-chart');
            if (canvas) {
                const points = <?= json_encode($netSalesPoints, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const labels = points.map(p => p.label);
                const data = points.map(p => p.net_sales);
                const textColor = getComputedStyle(document.body).getPropertyValue('--text').trim() || '#334155';
                const primaryColor = getComputedStyle(document.body).getPropertyValue('--primary').trim() || '#2563eb';
                const negativeColor = '#ef4444';
                const formatPeso = val => (val < 0 ? '-₱' : '₱') + Math.abs(val).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});

                const chart = new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Net Sales',
                            data: data,
                            backgroundColor: data.map(v => v < 0 ? negativeColor : primaryColor),
                            borderRadius: 4,
                            maxBarThickness: 36
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: ctx => formatPeso(ctx.parsed.y)
                                }
                            }
                        },
                        scales: {
                            x: {
                                ticks: { color: textColor, maxTicksLimit: 12 },
                                grid: { display: false },
                                title: { display: true, text: 'Hour (Philippine time)', color: textColor }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    color: textColor,
                                    callback: value => formatPeso(value)
                                },
                                grid: {
                                    color: ctx => ctx.tick.value === 0 ? '#94a3b8' : 'rgba(148, 163, 184, 0.15)'
                                }
                            }
                        }
                    }
                });
                window.netSalesChart = chart;
            }
        }
        <?php endif; ?>

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
            RetailMindUI.navigate('<?= $escape($rangeUrl) ?>', {fetch: {headers: {'X-RetailMind-Background': '1'}}});
        };
        const staleAt = Date.parse((dashboard.dataset.staleAfter || '').replace(' ', 'T'));
        window.setInterval(() => { if (Number.isFinite(staleAt) && Date.now() >= staleAt) staleNotice.hidden = false; }, 30000);
        window.setTimeout(refreshWhenSafe, 300000);
    })();
    </script>
</body>
</html>
