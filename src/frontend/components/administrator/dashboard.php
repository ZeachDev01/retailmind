<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

use App\Attention\SystemClock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;

$displayName = trim((string)($_SESSION['full_name'] ?? 'Administrator'));
$selectedRange = 30;
$requestedPeriod = filter_input(INPUT_GET, 'period', FILTER_DEFAULT);
$selectedPeriod = in_array($requestedPeriod, ['today', 'week', 'month'], true) ? $requestedPeriod : 'today';
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
$netSalesPeriods = $hasNetSales ? ($netSales['periods'] ?? [
    'today' => $netSales,
    'week' => $netSales['week'] ?? $netSales,
    'month' => $netSales['month'] ?? $netSales,
]) : [];
$activePeriodData = $netSalesPeriods[$selectedPeriod] ?? ($netSalesPeriods['today'] ?? $netSales ?? []);
$activePeriodPoints = $activePeriodData['points'] ?? [];
$grossProfit = $dashboard['gross_profit'] ?? null;
$activeProfitData = $grossProfit['periods'][$selectedPeriod] ?? [];
$profitStatus = $activeProfitData['status'] ?? 'unavailable';
$profitTotal = $activeProfitData['total_gross_profit'] ?? null;
$costTotal = $activeProfitData['total_net_cost'] ?? null;
$profitMargin = $activeProfitData['total_margin'] ?? null;
$profitRange = $activeProfitData['date_range'] ?? [];
$periodFrom = $profitRange['from'] ?? $activePeriodData['period_start'] ?? null;
$periodTo = $profitRange['to'] ?? $activePeriodData['period_end'] ?? null;
$profitReportUrl = app_url('components/report/report_generation.php') . '?' . http_build_query([
    'report' => 'gross_profit', 'from' => $periodFrom, 'to' => $periodTo, 'period' => $selectedPeriod,
]);
$formatFinancial = static fn(mixed $value): string => $value === null ? 'Unavailable' : $formatCurrency($value);
$formatProfit = static fn(mixed $value, string $status): string => in_array($status, ['incomplete', 'unavailable'], true)
    ? ucfirst($status) : $formatFinancial($value);
$history = $dashboard['financial_history'] ?? null;
$headlineOrder = [
    'sales_cash_exceptions' => ['Cash variances', 'bi-cash-coin', ['View cash variances' => 'components/administrator/shift_report.php']],
    'inventory_risks' => ['Pending Stock Issues', 'bi-box-seam', ['View Stock Issues' => 'components/administrator/stock_issues.php']],
    'fiscal_period' => ['Current Fiscal Period', 'bi-calendar-check', ['View Fiscal Periods' => 'components/system_administrator/fiscal_periods.php']],
];
$currentUrl = app_url('components/administrator/dashboard.php') . '?range=' . $selectedRange . '&period=' . $selectedPeriod;
$rangeUrl = $currentUrl;
$gcash = $dashboard['gcash_attention'] ?? ['visible' => false, 'status' => 'ready', 'unresolved_count' => 0, 'differences_count' => 0];
$hasExceptions = !empty($dashboard['exceptions']);
$hasGcash = !empty($gcash['visible']);
$attentionCount = count($dashboard['exceptions'] ?? []) + ($hasGcash ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Operations</title>
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" as="script" crossorigin>
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/dashboard.css')) ?>">
</head>
<body class="admin-dashboard-page administrator-workspace">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content" data-dashboard-refresh data-stale-after="<?= $escape($dashboard['freshness']['stale_after'] ?? '') ?>">
            <header class="page-heading control-center-heading">
                <div class="control-center-heading-copy">
                    <h1>Store Operations</h1>
                    <?php if (!empty($dashboard['freshness']['generated_at'])): ?><p class="dashboard-freshness"><i class="bi bi-clock" aria-hidden="true"></i> Updated <?= $escape($formatDate($dashboard['freshness']['generated_at'])) ?> Philippine time</p><?php endif; ?>
                </div>
                <div class="page-heading-actions">
                    <a class="btn btn-quiet btn-icon" id="dashboardRefreshBtn" title="Last updated <?= $escape($formatDate($dashboard['freshness']['generated_at'] ?? null)) ?>" href="<?= $escape($rangeUrl) ?>" data-manual-refresh data-dashboard-action><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh</a>
                </div>
            </header>

            <p class="dashboard-stale" role="status" aria-live="polite" hidden><i class="bi bi-hourglass-split" aria-hidden="true"></i> Refresh paused until you finish.</p>

            <?php
            $isInitialSetup = !($activePeriodData['has_activity'] ?? false)
                && (int)($dashboard['comparison']['transactions']['current'] ?? 0) === 0
                && empty($dashboard['exceptions']);
            ?>
            <?php if ($isInitialSetup): ?>
                <section class="dashboard-section store-onboarding-section" aria-labelledby="store-onboarding-title">
                    <div class="section-header">
                        <div>
                            <h2 id="store-onboarding-title">Store Operations Onboarding</h2>
                            <p class="section-description">Quick activation steps for Shalom Store administration.</p>
                        </div>
                    </div>
                    <div class="quick-actions">
                        <a class="quick-action" href="<?= $escape(app_url('components/system_administrator/fiscal_periods.php')) ?>" data-dashboard-action>
                            <strong>1. Fiscal Period</strong>
                            <span>Confirm active period</span>
                        </a>
                        <a class="quick-action" href="<?= $escape(app_url('components/administrator/store_settings.php')) ?>" data-dashboard-action>
                            <strong>2. Store Settings</strong>
                            <span>Review thresholds and limits</span>
                        </a>
                        <a class="quick-action" href="<?= $escape(app_url('components/administrator/shift_report.php')) ?>" data-dashboard-action>
                            <strong>3. Cashier Shifts</strong>
                            <span>Monitor shifts and variances</span>
                        </a>
                        <a class="quick-action" href="<?= $escape(app_url('components/administrator/stock_issues.php')) ?>" data-dashboard-action>
                            <strong>4. Stock Issues</strong>
                            <span>Review escalated stock risks</span>
                        </a>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-section governance-queue store-attention-queue" id="store-attention-section" aria-labelledby="store-attention-title">
                <div class="section-header">
                    <div class="section-title-row">
                        <h2 id="store-attention-title">Needs attention</h2>
                        <span class="decision-pill <?= $attentionCount > 0 ? 'action' : 'ok' ?>"><?= $attentionCount > 0 ? $attentionCount . ' pending' : 'All clear' ?></span>
                    </div>
                </div>
                <?php if ($dashboard['state'] === 'error'): ?>
                    <div class="dashboard-state dashboard-state-error" role="alert"><i class="bi bi-cloud-slash" aria-hidden="true"></i><div><strong>Store status unavailable</strong><p><?= $escape($dashboard['message']) ?></p></div><a class="btn btn-quiet" href="<?= $escape($rangeUrl) ?>">Try again</a></div>
                <?php elseif (!$hasExceptions && !$hasGcash): ?>
                    <div class="dashboard-state dashboard-state-empty" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>No action required</strong><p>All cashier shifts, GCash exchanges, and stock records are in balance.</p></div></div>
                <?php else: ?>
                    <div class="attention-list" aria-live="polite">
                        <?php if ($hasGcash): ?>
                            <article class="attention-item attention-warning attention-gcash" id="gcash-attention-row">
                                <span class="attention-icon"><i class="bi bi-phone" aria-hidden="true"></i></span>
                                <div class="attention-copy">
                                    <span class="severity-label">Warning</span>
                                    <strong>GCash Needs Review</strong>
                                    <?php if ($gcash['status'] === 'unavailable'): ?>
                                        <span>GCash review status unavailable. Could not load Store-wide counts.</span>
                                    <?php elseif ($gcash['status'] === 'partial_failure'): ?>
                                        <span>
                                            <?= $gcash['unresolved_count'] !== null ? (int)$gcash['unresolved_count'] . ' unresolved exchange' . ((int)$gcash['unresolved_count'] === 1 ? '' : 's') : 'Unresolved exchanges unavailable' ?> ·
                                            <?= $gcash['differences_count'] !== null ? (int)$gcash['differences_count'] . ' unreviewed closing difference' . ((int)$gcash['differences_count'] === 1 ? '' : 's') : 'Closing differences unavailable' ?>
                                        </span>
                                    <?php else: ?>
                                        <span><?= (int)$gcash['unresolved_count'] ?> unresolved exchange<?= (int)$gcash['unresolved_count'] === 1 ? '' : 's' ?> · <?= (int)$gcash['differences_count'] ?> unreviewed closing difference<?= (int)$gcash['differences_count'] === 1 ? '' : 's' ?> (Store-wide)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="attention-actions">
                                    <?php if ($gcash['status'] === 'unavailable'): ?>
                                        <a class="btn btn-small" href="<?= $escape(app_url('components/administrator/gcash_overview.php')) ?>" data-dashboard-action aria-label="Open GCash Overview">Open GCash Overview</a>
                                    <?php else: ?>
                                        <?php if (($gcash['unresolved_count'] ?? 0) > 0 || $gcash['unresolved_count'] === null): ?>
                                            <a class="btn btn-small" href="<?= $escape(app_url($gcash['unresolved_destination'])) ?>" data-dashboard-action aria-label="Review unresolved exchanges">Exchanges (<?= $gcash['unresolved_count'] !== null ? (int)$gcash['unresolved_count'] : '?' ?>)</a>
                                        <?php endif; ?>
                                        <?php if (($gcash['differences_count'] ?? 0) > 0 || $gcash['differences_count'] === null): ?>
                                            <a class="btn btn-small" href="<?= $escape(app_url($gcash['differences_destination'])) ?>" data-dashboard-action aria-label="Review closing differences">Differences (<?= $gcash['differences_count'] !== null ? (int)$gcash['differences_count'] : '?' ?>)</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endif; ?>
                        <?php foreach ($dashboard['exceptions'] as $item): ?>
                            <article class="attention-item attention-<?= $escape($item['severity']) ?>">
                                <span class="attention-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
                                <div class="attention-copy"><span class="severity-label"><?= $escape(ucfirst($item['severity'])) ?></span><strong><?= $escape($item['title']) ?></strong><span><?= $escape($item['detail']) ?> · <?= $escape($formatDate($item['detected_at'])) ?></span></div>
                                <a class="btn btn-small" href="<?= $escape(app_url($item['destination'])) ?>" data-dashboard-action aria-label="Review <?= $escape($item['title']) ?>">Review</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($dashboard['headline'] !== []): ?>
                <section class="store-status-section" id="store-status-section" aria-labelledby="store-status-title">
                    <div class="section-header control-center-section-header">
                        <div class="section-title-row">
                            <h2 id="store-status-title">Store status</h2>
                            <span class="priority-label">Quick Controls</span>
                        </div>
                    </div>
                    <div class="card-grid store-operations-headlines">
                        <?php foreach ($headlineOrder as $key => [$label, $icon, $destinations]): $item = $dashboard['headline'][$key]; ?>
                            <article class="stat-card with-icon status-<?= $escape($item['status']) ?>">
                                <span class="stat-icon"><i class="bi <?= $escape($icon) ?>" aria-hidden="true"></i></span>
                                <div class="label"><?= $escape($label) ?></div>
                                <div class="value"><?= $escape($item['value']) ?></div>
                                <div class="store-status-links"><?php foreach ($destinations as $linkLabel => $destination): ?><a href="<?= $escape(app_url($destination)) ?>" data-dashboard-action><?= $escape($linkLabel) ?></a><?php endforeach; ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-section net-sales-section <?= !$hasNetSales || !($activePeriodData['has_activity'] ?? false) ? 'is-empty' : '' ?>" id="sales-profit-section" aria-labelledby="financial-title">
                <div class="section-header">
                    <div class="section-title-row">
                        <h2 id="financial-title">Sales and Profit</h2>
                    </div>
                    <form method="get" action="<?= $escape(app_url('components/administrator/dashboard.php')) ?>" class="period-controls" aria-label="Sales and profit period">
                        <input type="hidden" name="range" value="30">
                        <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'] as $period => $label): ?>
                            <button type="submit" name="period" value="<?= $period ?>" class="btn btn-quiet period-btn <?= $selectedPeriod === $period ? 'is-selected active' : '' ?>" aria-pressed="<?= $selectedPeriod === $period ? 'true' : 'false' ?>"><?= $label ?></button>
                        <?php endforeach; ?>
                    </form>
                </div>
                <div class="net-sales-summaries-grid" role="region" aria-label="Sales and profit totals">
                    <article class="stat-card net-sales-summary-card card-gross-sales">
                        <div class="label">Gross Sales</div>
                        <div class="value" id="gross-sales-card-value"><?= $escape($formatFinancial($activePeriodData['total_gross_sales'] ?? null)) ?></div>
                    </article>
                    <article class="stat-card net-sales-summary-card card-net-sales">
                        <div class="label">Net Sales</div>
                        <div class="value <?= ($activePeriodData['total'] ?? 0) < 0 ? 'is-negative' : '' ?>" id="net-sales-card-value"><?= $escape($formatFinancial($activePeriodData['total'] ?? null)) ?></div>
                    </article>
                    <article class="stat-card net-sales-summary-card card-gross-profit <?= $profitStatus === 'recorded' && $profitTotal > 0 ? 'is-positive-complete' : '' ?>">
                        <div class="label-wrap"><span class="label">Gross Profit</span><span class="profit-status-badge status-<?= $escape($profitStatus) ?>"><?= $escape(ucfirst($profitStatus)) ?></span></div>
                        <div class="value <?= $profitTotal !== null && $profitTotal < 0 ? 'is-negative' : '' ?>" id="gross-profit-card-value"><?= $escape($formatProfit($profitTotal, $profitStatus)) ?></div>
                        <?php if ($profitStatus === 'recorded' && $profitMargin !== null && ($activePeriodData['total'] ?? 0) > 0): ?><div class="profit-margin">Margin: <?= number_format($profitMargin, 1) ?>%</div><?php endif; ?>
                    </article>
                </div>
                <div class="profit-guidance-panel">
                    <div class="profit-guidance-row">
                        <p class="profit-cost-pill"><i class="bi bi-tag" aria-hidden="true"></i> Product Cost: <strong><?= $escape($formatProfit($costTotal, $profitStatus)) ?></strong></p>
                        <div class="profit-guidance-actions">
                            <a class="btn btn-quiet profit-report-link" id="viewProfitReportBtn" href="<?= $escape($profitReportUrl) ?>" data-dashboard-action><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> View profit report</a>
                        </div>
                    </div>
                    <?php if ($profitStatus === 'incomplete'): ?><p role="status" class="profit-incomplete-explanation" id="profit-incomplete-explanation"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> <span><?= (int)($activeProfitData['affected_lines_count'] ?? 0) ?> affected sold line<?= ($activeProfitData['affected_lines_count'] ?? 0) === 1 ? '' : 's' ?> missing cost. Complete product cost and Gross Profit totals are unavailable.</span></p><?php elseif ($profitStatus === 'estimated'): ?><p class="profit-status-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Costs estimated · Margin withheld. Estimated intervals are identified in the breakdown.</p><?php elseif ($profitStatus === 'unavailable'): ?><p role="status" class="profit-status-note"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Product costs and Gross Profit could not be loaded. Refresh to try again.</p><?php endif; ?>
                    <details class="profit-return-help"><summary>How profit is calculated</summary><div class="return-help-drawer"><p><strong>Gross Sales:</strong> Sales before discounts and refunds.</p><p><strong>Net Sales:</strong> Gross Sales minus discounts and cash refunds.</p><p><strong>Gross Profit:</strong> Net Sales minus product cost, adjusted for returns. Gross Profit excludes operating expenses (Rent, wages, electricity).</p><p><strong>Restockable returns:</strong> Reverse Net Sales and Product Cost.</p><p><strong>Unsaleable returns:</strong> Reverse Net Sales; product cost remains an inventory loss.</p></div></details>
                </div>
                <p class="chart-unavailable" hidden role="status">Charts unavailable. Exact values remain in the detailed breakdowns.</p>
                <div class="dashboard-charts-grid">
                    <div class="chart-group"><h3 id="net-sales-title">Sales Trend</h3>
                    <?php if (!$hasNetSales): ?><p role="status">Sales unavailable. Refresh to try again.</p><?php elseif (!($activePeriodData['has_activity'] ?? false)): ?><p>No sales or refunds recorded in this period.</p><?php endif; ?>
                    <div class="net-sales-chart-wrapper"><canvas id="net-sales-hourly-chart" role="img" aria-label="Gross Sales and Net Sales in Philippine pesos. Exact values in View detailed breakdown."></canvas></div>
                    </div>
                    <div class="chart-group"><h3>Gross Profit Trend</h3>
                    <div class="net-sales-chart-wrapper"><canvas id="gross-profit-chart" role="img" aria-label="Gross Profit in Philippine pesos. Exact values and cost status in View detailed breakdown."></canvas></div></div>
                </div>
                <?php $periodLabels = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month']; ?>
                <details id="financial-breakdown" class="financial-breakdown"><summary>View detailed breakdown</summary><div class="breakdown-meta"><div class="breakdown-meta-row"><span class="period-badge ongoing"><?= $escape($periodLabels[$selectedPeriod] ?? 'Period') ?></span><p class="section-description" id="financial-period-dates"><?= $escape($periodFrom ?? 'Unavailable') ?> – <?= $escape($periodTo ?? 'Unavailable') ?> · Ongoing through <?= $escape($formatDate($dashboard['net_sales']['as_of'] ?? null)) ?> Philippine time</p></div><div class="breakdown-deductions"><span>Discounts: <strong><?= $escape($formatFinancial($activePeriodData['total_discounts'] ?? null)) ?></strong></span><span>Refunds: <strong><?= $escape($formatFinancial($activePeriodData['refunds_total'] ?? null)) ?></strong></span></div></div>
                    <div class="table-wrap u-table-flat net-sales-table-wrap" role="region" aria-label="Sales and profit breakdown" tabindex="0"><table class="net-sales-table"><caption><?= $selectedPeriod === 'today' ? 'Hourly' : 'Daily' ?> amounts in Philippine pesos · Philippine time</caption><thead><tr><th scope="col"><?= $selectedPeriod === 'today' ? 'Hour' : 'Date' ?></th><th scope="col">Gross Sales</th><th scope="col">Discounts</th><th scope="col">Refunds</th><th scope="col">Net Sales</th><th scope="col">Product Cost</th><th scope="col">Gross Profit</th><th scope="col">Cost status</th></tr></thead><tbody>
                    <?php foreach ($activePeriodPoints as $index => $point): $profitPoint = $activeProfitData['points'][$index] ?? []; $pointStatus = $profitPoint['status'] ?? 'unavailable'; $pointCost = $profitPoint['net_cost'] ?? null; $pointProfit = $profitPoint['gross_profit'] ?? null; $pointMuted = in_array($pointStatus, ['incomplete', 'unavailable'], true); $isIdle = (($point['gross_sales'] ?? 0) == 0 && ($point['discounts'] ?? 0) == 0 && ($point['refunds'] ?? 0) == 0 && ($point['net_sales'] ?? 0) == 0); ?>
                        <tr class="<?= $isIdle ? 'is-idle' : '' ?>"><th scope="row"><?= $escape($selectedPeriod === 'today' ? $point['label'] : ($point['date'] ?? $point['label'])) ?></th><?php foreach (['gross_sales', 'discounts', 'refunds', 'net_sales'] as $key): $val = $point[$key] ?? null; ?><td class="<?= $val === null ? 'is-muted' : ($val < 0 ? 'is-negative' : ($val == 0 ? 'is-zero' : ($key === 'net_sales' ? 'is-strong' : ''))) ?>"><?= $escape($formatFinancial($val)) ?></td><?php endforeach; ?><td class="<?= $pointMuted || $pointCost === null ? 'is-muted' : ($pointCost < 0 ? 'is-negative' : ($pointCost == 0 ? 'is-zero' : '')) ?>"><?= $escape($formatProfit($pointCost, $pointStatus)) ?></td><td class="<?= $pointMuted || $pointProfit === null ? 'is-muted' : ($pointProfit < 0 ? 'is-negative' : ($pointProfit > 0 ? 'is-positive' : ($pointProfit == 0 ? 'is-zero' : 'is-strong'))) ?>"><?= $escape($formatProfit($pointProfit, $pointStatus)) ?></td><td><span class="profit-status-badge status-<?= $escape($pointStatus) ?>"><?= $escape(ucfirst($pointStatus)) ?></span></td></tr>
                    <?php endforeach; ?>
                    <?php if ($activePeriodPoints === []): ?><tr><td colspan="8" class="u-empty-cell">No breakdown records available for this period.</td></tr><?php endif; ?>
                    </tbody><?php if ($activePeriodPoints !== []): ?><tfoot><tr><th scope="row">Total</th><td><?= $escape($formatFinancial($activePeriodData['total_gross_sales'] ?? null)) ?></td><td><?= $escape($formatFinancial($activePeriodData['total_discounts'] ?? null)) ?></td><td><?= $escape($formatFinancial($activePeriodData['refunds_total'] ?? null)) ?></td><td class="<?= ($activePeriodData['total'] ?? 0) < 0 ? 'is-negative' : 'is-strong' ?>"><?= $escape($formatFinancial($activePeriodData['total'] ?? null)) ?></td><td><?= $escape($formatProfit($costTotal, $profitStatus)) ?></td><td class="<?= $profitTotal !== null && $profitTotal < 0 ? 'is-negative' : ($profitStatus === 'recorded' && $profitTotal > 0 ? 'is-positive' : '') ?>"><?= $escape($formatProfit($profitTotal, $profitStatus)) ?></td><td><span class="profit-status-badge status-<?= $escape($profitStatus) ?>"><?= $escape(ucfirst($profitStatus)) ?></span></td></tr></tfoot><?php endif; ?></table></div>
                </details>
            </section>

            <section class="dashboard-section period-comparison" id="financial-history" aria-labelledby="comparison-title">
                <div class="section-header">
                    <div class="section-title-row">
                        <h2 id="comparison-title">Last 30 Days</h2>
                        <span class="priority-label">Rolling 30-Day Window</span>
                    </div>
                </div>
                <div class="comparison-grid financial-history-totals">
                    <?php foreach (['net_sales' => 'Net Sales', 'gross_profit' => 'Gross Profit'] as $key => $label): $metric = $history[$key] ?? []; $status = $metric['status'] ?? ($history === null ? 'unavailable' : 'recorded'); $previousStatus = $metric['previous_status'] ?? ($history === null ? 'unavailable' : 'recorded'); ?>
                        <article class="comparison-card"><span><?= $label ?><?= $status === 'estimated' ? ' · Estimated' : '' ?></span><strong><?= $escape($formatProfit($metric['current'] ?? null, $status)) ?></strong><small>Previous <?= $escape($formatProfit($metric['previous'] ?? null, $previousStatus)) ?><?= $previousStatus === 'estimated' ? ' (estimated)' : '' ?> · <?= $escape(!isset($metric['change_percent']) ? 'No comparable percentage' : $formatChange($metric['change_percent'])) ?><?= $status === 'estimated' || $previousStatus === 'estimated' ? ' · Estimated comparison' : '' ?></small></article>
                    <?php endforeach; ?>
                </div>
                <div class="net-sales-chart-wrapper"><canvas id="financial-history-chart" role="img" aria-label="Daily Net Sales and Gross Profit over the last 30 days in Philippine pesos. Exact values in View 30-day breakdown."></canvas></div>
                <details id="financial-history-breakdown" class="financial-breakdown"><summary>View 30-day breakdown</summary><div class="breakdown-meta"><p class="section-description"><?= $escape($history['period_start'] ?? 'Unavailable') ?> – <?= $escape($history['period_end'] ?? 'Unavailable') ?> · Ongoing through <?= $escape($formatDate($history['as_of'] ?? null)) ?> Philippine time</p><p class="section-description">Previous 30 days: <?= $escape($history['previous_start'] ?? 'Unavailable') ?> – <?= $escape($history['previous_end'] ?? 'Unavailable') ?> (today in progress).</p></div><div class="table-wrap u-table-flat net-sales-table-wrap" role="region" aria-label="Last 30 days exact values" tabindex="0"><table class="net-sales-table"><caption>Daily amounts in Philippine pesos · Philippine time</caption><thead><tr><th scope="col">Date</th><th scope="col">Net Sales</th><th scope="col">Product Cost</th><th scope="col">Gross Profit</th><th scope="col">Cost status</th></tr></thead><tbody>
                    <?php foreach ($history['points'] ?? [] as $point): $pointStatus = $point['status'] ?? 'unavailable'; $pointSales = $point['net_sales'] ?? null; $pointCost = $point['net_cost'] ?? null; $pointProfit = $point['gross_profit'] ?? null; $pointMuted = in_array($pointStatus, ['incomplete', 'unavailable'], true); ?><tr><th scope="row"><?= $escape($point['date']) ?></th><td class="<?= $pointSales === null ? 'is-muted' : ($pointSales < 0 ? 'is-negative' : 'is-strong') ?>"><?= $escape($formatFinancial($pointSales)) ?></td><td class="<?= $pointMuted || $pointCost === null ? 'is-muted' : ($pointCost < 0 ? 'is-negative' : '') ?>"><?= $escape($formatProfit($pointCost, $pointStatus)) ?></td><td class="<?= $pointMuted || $pointProfit === null ? 'is-muted' : ($pointProfit < 0 ? 'is-negative' : ($pointProfit > 0 ? 'is-positive' : 'is-strong')) ?>"><?= $escape($formatProfit($pointProfit, $pointStatus)) ?></td><td><span class="profit-status-badge status-<?= $escape($pointStatus) ?>"><?= $escape(ucfirst($pointStatus)) ?></span></td></tr><?php endforeach; ?>
                    <?php if (empty($history['points'])): ?><tr><td colspan="5" class="u-empty-cell">No 30-day breakdown records available.</td></tr><?php endif; ?>
                </tbody></table></div></details>
            </section>

        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
    (() => {
        const salesPoints = <?= json_encode($activePeriodPoints, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const profitPoints = <?= json_encode($activeProfitData['points'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const historyPoints = <?= json_encode($history['points'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const formatPeso = value => value === null ? 'Unavailable' : (value < 0 ? '-₱' : '₱') + Math.abs(value).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const styles = getComputedStyle(document.body);
        const textColor = styles.getPropertyValue('--text').trim() || '#334155';
        const colors = {gross: '#64748b', net: styles.getPropertyValue('--primary').trim() || '#2563eb', profit: styles.getPropertyValue('--theme-success-text').trim() || '#166534'};
        const showChartFallback = () => {
            document.querySelectorAll('.chart-unavailable').forEach(note => note.hidden = false);
            document.querySelectorAll('.net-sales-chart-wrapper').forEach(wrapper => wrapper.hidden = true);
        };
        if (typeof Chart === 'undefined') {
            showChartFallback();
        } else {
            const isMobile = window.innerWidth < 640;
            const series = (label, values, color) => ({label, data: values, backgroundColor: color, borderColor: color, borderWidth: 2, pointRadius: isMobile ? 2 : 3, pointHitRadius: isMobile ? 24 : 18, tension: 0, normalized: true});
            const graph = (id, type, points, datasets, axisTitle) => new Chart(document.getElementById(id), {
                type, data: {labels: points.map(point => point.label || point.date), datasets},
                options: {
                    responsive: true, maintainAspectRatio: false, spanGaps: false,
                    animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : {duration: 250}, resizeDelay: 50,
                    interaction: {mode: 'index', intersect: false},
                    plugins: {legend: {labels: {color: textColor}}, tooltip: {callbacks: {
                        label: context => context.dataset.label + ': ' + formatPeso(context.raw),
                        afterBody: items => {const status = points[items[0]?.dataIndex]?.status; return status ? 'Cost status: ' + status : '';}
                    }}},
                    scales: {x: {ticks: {color: textColor, maxTicksLimit: isMobile ? 6 : 12, maxRotation: 0}, grid: {display: false}, title: {display: true, text: axisTitle, color: textColor}}, y: {beginAtZero: true, ticks: {color: textColor, callback: formatPeso}}}
                }
            });
            try {
                graph('net-sales-hourly-chart', 'bar', salesPoints, [series('Gross Sales', salesPoints.map(point => point.gross_sales ?? null), colors.gross), series('Net Sales', salesPoints.map(point => point.net_sales ?? null), colors.net)], <?= json_encode($selectedPeriod === 'today' ? 'Hour (Philippine time)' : 'Date (Philippine time)') ?>);
                graph('gross-profit-chart', 'line', profitPoints, [series('Gross Profit', profitPoints.map(point => point.gross_profit ?? null), colors.profit)], <?= json_encode($selectedPeriod === 'today' ? 'Hour (Philippine time)' : 'Date (Philippine time)') ?>);
                graph('financial-history-chart', 'line', historyPoints, [series('Net Sales', historyPoints.map(point => point.net_sales ?? null), colors.net), series('Gross Profit', historyPoints.map(point => point.gross_profit ?? null), colors.profit)], 'Date (Philippine time)');
            } catch {
                showChartFallback();
            }
        }

        const dashboard = document.querySelector('[data-dashboard-refresh]');
        const staleNotice = document.querySelector('.dashboard-stale');
        if (!dashboard || !staleNotice) return;

        let actionInProgress = false;
        const interactiveFields = 'input, select, textarea, [contenteditable="true"]';
        document.addEventListener('focusin', event => { if (event.target.matches?.(interactiveFields)) actionInProgress = true; });
        document.addEventListener('focusout', event => { if (event.target.matches?.(interactiveFields)) actionInProgress = false; });
        document.addEventListener('submit', () => { actionInProgress = true; });
        document.addEventListener('click', event => { if (event.target.closest?.('[data-dashboard-action]')) actionInProgress = true; }, {passive: true});

        const refreshWhenSafe = () => {
            if (actionInProgress || document.hidden || window.__retailMindActionInProgress === true) {
                staleNotice.hidden = false;
                window.setTimeout(refreshWhenSafe, 30000);
                return;
            }
            const currentLoc = new URL(window.location.href);
            RetailMindUI.navigate(currentLoc.pathname + currentLoc.search, {fetch: {headers: {'X-RetailMind-Background': '1'}}});
        };
        const staleAt = Date.parse((dashboard.dataset.staleAfter || '').replace(' ', 'T'));
        window.setInterval(() => { if (Number.isFinite(staleAt) && Date.now() >= staleAt) staleNotice.hidden = false; }, 30000);
        window.setTimeout(refreshWhenSafe, 300000);
    })();
    </script>
</body>
</html>
