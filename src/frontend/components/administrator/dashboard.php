<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

use App\Attention\SystemClock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;

$displayName = trim((string)($_SESSION['full_name'] ?? 'Administrator'));
$requestedRange = filter_input(INPUT_GET, 'range', FILTER_VALIDATE_INT);
$selectedRange = in_array($requestedRange, [7, 30, 90], true) ? $requestedRange : 30;
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
$activePeriodTitle = match($selectedPeriod) {
    'week' => "This Week's Net Sales",
    'month' => "This Month's Net Sales",
    default => "Today's Net Sales",
};
$activePeriodTotal = $hasNetSales ? ($activePeriodData['total'] ?? 0.0) : null;
$activePeriodPoints = $hasNetSales ? ($activePeriodData['points'] ?? []) : [];
$activePeriodHasActivity = $hasNetSales && ($activePeriodData['has_activity'] ?? false);
$activePeriodIntervalLabel = $activePeriodData['interval_label'] ?? ($selectedPeriod === 'today' ? 'Hour' : 'Day');
$netSalesTotal = $activePeriodTotal;
$netSalesPoints = $activePeriodPoints;
$netSalesHasActivity = $activePeriodHasActivity;
$netSalesAsOf = $hasNetSales ? ($netSales['as_of'] ?? null) : null;

$grossProfit = $dashboard['gross_profit'] ?? null;
$hasGrossProfit = $grossProfit !== null && is_array($grossProfit);
$grossProfitPeriods = $hasGrossProfit ? ($grossProfit['periods'] ?? []) : [];
$activeProfitData = $grossProfitPeriods[$selectedPeriod] ?? ($grossProfitPeriods['today'] ?? []);

$activeProfitTitle = match($selectedPeriod) {
    'week' => "This Week's Gross Profit",
    'month' => "This Month's Gross Profit",
    default => "Today's Gross Profit",
};
$activeCostTitle = match($selectedPeriod) {
    'week' => "This Week's Product Cost",
    'month' => "This Month's Product Cost",
    default => "Today's Product Cost",
};

$profitStatus = (string)($activeProfitData['status'] ?? 'recorded');
$profitTotal = $activeProfitData['total_gross_profit'] ?? null;
$costTotal = $activeProfitData['total_net_cost'] ?? null;
$profitMargin = $activeProfitData['total_margin'] ?? null;
$affectedLinesCount = (int)($activeProfitData['affected_lines_count'] ?? 0);
$profitRange = $activeProfitData['date_range'] ?? [];
$activeProfitFrom = (string)($profitRange['from'] ?? date('Y-m-d'));
$activeProfitTo = (string)($profitRange['to'] ?? date('Y-m-d'));

$profitReportUrl = app_url('components/report/report_generation.php') . '?report=gross_profit&from=' . urlencode($activeProfitFrom) . '&to=' . urlencode($activeProfitTo) . '&period=' . urlencode($selectedPeriod);
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
$currentUrl = app_url('components/administrator/dashboard.php') . '?range=' . $selectedRange . '&period=' . $selectedPeriod;
$rangeUrl = $currentUrl;
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
                    <p class="dashboard-freshness"><i class="bi bi-clock" aria-hidden="true"></i> Last updated <?= $escape($formatDate($dashboard['freshness']['generated_at'] ?? null)) ?></p>
                </div>
                <div class="page-heading-actions">
                    <a class="btn btn-quiet btn-icon" id="dashboardRefreshBtn" href="<?= $escape($rangeUrl) ?>" data-manual-refresh data-dashboard-action><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh</a>
                </div>
            </header>

            <p class="dashboard-stale" role="status" aria-live="polite" hidden><i class="bi bi-hourglass-split" aria-hidden="true"></i> Updates paused while you work. This dashboard will refresh when your action is finished.</p>

            <section class="dashboard-section governance-queue store-attention-queue" aria-labelledby="store-attention-title">
                <div class="section-header">
                    <div><h2 id="store-attention-title">Store attention queue</h2></div>
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
                                <a class="btn btn-small" href="<?= $escape(app_url($item['destination'])) ?>" data-dashboard-action aria-label="Review <?= $escape($item['title']) ?>">Review</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($dashboard['headline'] !== []): ?>
                <section aria-labelledby="store-status-title">
                    <div class="section-header control-center-section-header"><div><h2 id="store-status-title">Store status</h2></div></div>
                    <div class="card-grid store-operations-headlines">
                        <?php foreach ($headlineOrder as $key => [$label, $icon]): $item = $dashboard['headline'][$key]; ?>
                            <article class="stat-card with-icon status-<?= $escape($item['status']) ?>">
                                <span class="stat-icon"><i class="bi <?= $escape($icon) ?>" aria-hidden="true"></i></span>
                                <div class="value"><?= $escape($item['value']) ?></div><div class="label"><?= $escape($label) ?></div><div class="hint"><?= $escape($item['detail']) ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-section net-sales-section" aria-labelledby="net-sales-title">
                <div class="section-header control-center-section-header">
                    <div>
                        <div class="net-sales-title-wrap">
                            <h2 id="net-sales-title"><?= $escape($activePeriodTitle) ?></h2>
                            <span class="period-badge ongoing">Ongoing</span>
                        </div>
                    </div>
                    <div class="net-sales-header-controls">
                        <div class="period-controls btn-group" role="group" aria-label="Net Sales period">
                            <button type="button" class="btn btn-quiet period-btn <?= $selectedPeriod === 'today' ? 'active is-selected' : '' ?>" data-period="today" aria-pressed="<?= $selectedPeriod === 'today' ? 'true' : 'false' ?>">Today</button>
                            <button type="button" class="btn btn-quiet period-btn <?= $selectedPeriod === 'week' ? 'active is-selected' : '' ?>" data-period="week" aria-pressed="<?= $selectedPeriod === 'week' ? 'true' : 'false' ?>">This Week</button>
                            <button type="button" class="btn btn-quiet period-btn <?= $selectedPeriod === 'month' ? 'active is-selected' : '' ?>" data-period="month" aria-pressed="<?= $selectedPeriod === 'month' ? 'true' : 'false' ?>">This Month</button>
                        </div>
                        <?php if (!$hasGrossProfit): ?>
                        <div class="net-sales-summary-stat">
                            <span class="net-sales-summary-label"><?= $escape($activePeriodTitle) ?></span>
                            <?php if ($hasNetSales && $activePeriodTotal !== null): ?>
                                <strong class="net-sales-summary-value <?= $activePeriodTotal < 0 ? 'is-negative' : '' ?>"><?= $escape($formatCurrency($activePeriodTotal)) ?></strong>
                            <?php else: ?>
                                <strong class="net-sales-summary-value is-unavailable">Unavailable</strong>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($hasGrossProfit): ?>
                    <div class="net-sales-summaries-grid profit-cards-grid layout-a-cards" role="region" aria-label="Sales and Profit summaries">
                        <!-- Card 1: Net Sales -->
                        <article class="stat-card net-sales-summary-card layout-a-card card-net-sales is-selected" data-card="net-sales" data-summary-period="<?= $escape($selectedPeriod) ?>">
                            <div class="label-wrap">
                                <span class="label" id="net-sales-card-label"><?= $escape($activePeriodTitle) ?></span>
                                <span class="period-badge ongoing">Ongoing</span>
                            </div>
                            <?php if ($hasNetSales && $activePeriodTotal !== null): ?>
                                <div class="value <?= $activePeriodTotal < 0 ? 'is-negative' : '' ?>" id="net-sales-card-value"><?= $escape($formatCurrency($activePeriodTotal)) ?></div>
                            <?php else: ?>
                                <div class="value is-unavailable" id="net-sales-card-value">Unavailable</div>
                            <?php endif; ?>
                            <div class="hint" id="net-sales-card-hint">Sales after discounts and refunds</div>
                        </article>

                        <!-- Card 2: Gross Profit (beside Net Sales) -->
                        <?php
                        $isPositiveComplete = ($profitStatus === 'recorded' && $profitTotal !== null && $profitTotal > 0);
                        $isProfitNegative = ($profitTotal !== null && $profitTotal < 0);
                        $isProfitIncomplete = ($profitStatus === 'incomplete');
                        $isProfitEstimated = ($profitStatus === 'estimated');
                        ?>
                        <article class="stat-card net-sales-summary-card layout-a-card card-gross-profit <?= $isPositiveComplete ? 'is-positive-complete' : '' ?> <?= $isProfitNegative ? 'is-negative' : '' ?>" data-card="gross-profit">
                            <div class="label-wrap">
                                <span class="label" id="gross-profit-card-label"><?= $escape($activeProfitTitle) ?></span>
                                <span class="profit-status-badge status-<?= $escape($profitStatus) ?>" id="gross-profit-status-badge"><?= $escape(ucfirst($profitStatus)) ?></span>
                            </div>
                            <?php if ($isProfitIncomplete): ?>
                                <div class="value is-incomplete" id="gross-profit-card-value">Incomplete</div>
                            <?php elseif ($hasGrossProfit && $profitTotal !== null): ?>
                                <div class="value <?= $isProfitNegative ? 'is-negative' : '' ?>" id="gross-profit-card-value"><?= $escape($formatCurrency($profitTotal)) ?></div>
                            <?php else: ?>
                                <div class="value is-unavailable" id="gross-profit-card-value">Unavailable</div>
                            <?php endif; ?>
                            <div class="profit-meta" id="gross-profit-card-meta">
                                <?php if ($isProfitIncomplete): ?>
                                    <span class="profit-affected-lines" id="profit-affected-lines"><?= $affectedLinesCount ?> affected sold line<?= $affectedLinesCount === 1 ? '' : 's' ?> missing cost</span>
                                <?php elseif ($isProfitEstimated): ?>
                                    <span class="hint" id="gross-profit-card-hint">Costs estimated &middot; Margin withheld</span>
                                <?php elseif ($profitStatus === 'recorded' && $profitMargin !== null && $activePeriodTotal > 0): ?>
                                    <strong class="profit-margin" id="profit-margin-value">Margin: <?= number_format($profitMargin, 1) ?>%</strong>
                                <?php else: ?>
                                    <span class="hint" id="gross-profit-card-hint"><?= ($activePeriodTotal !== null && $activePeriodTotal <= 0) ? 'Margin withheld for nonpositive sales' : 'Costs recorded' ?></span>
                                <?php endif; ?>
                            </div>
                        </article>

                        <!-- Card 3: Cost of Products Sold -->
                        <?php
                        $isCostIncomplete = ($profitStatus === 'incomplete');
                        $isCostNegative = ($costTotal !== null && $costTotal < 0);
                        ?>
                        <article class="stat-card net-sales-summary-card layout-a-card card-cost-of-sales" data-card="cost-of-sales">
                            <div class="label-wrap">
                                <span class="label" id="cost-card-label"><?= $escape($activeCostTitle) ?></span>
                                <span class="profit-status-badge status-<?= $escape($profitStatus) ?>" id="cost-status-badge"><?= $escape(ucfirst($profitStatus)) ?></span>
                            </div>
                            <?php if ($isCostIncomplete): ?>
                                <div class="value is-incomplete" id="cost-card-value">Incomplete</div>
                            <?php elseif ($hasGrossProfit && $costTotal !== null): ?>
                                <div class="value <?= $isCostNegative ? 'is-negative' : '' ?>" id="cost-card-value"><?= $escape($formatCurrency($costTotal)) ?></div>
                            <?php else: ?>
                                <div class="value is-unavailable" id="cost-card-value">Unavailable</div>
                            <?php endif; ?>
                            <div class="hint" id="cost-card-hint">
                                <?= $isCostIncomplete ? 'Some sold items have no recorded cost' : ($isProfitEstimated ? 'Estimated from catalog product costs' : 'Cost of products sold') ?>
                            </div>
                        </article>
                    </div>

                    <div class="profit-guidance-panel" id="profit-guidance-panel">
                        <div class="profit-guidance-row">
                            <div class="profit-formula-bar" aria-label="Gross profit calculation">
                                <span class="formula-term">Net Sales</span>
                                <span class="formula-operator">&minus;</span>
                                <span class="formula-term">Product Cost</span>
                                <span class="formula-operator">=</span>
                                <span class="formula-term">Gross Profit</span>
                            </div>
                            <a class="btn btn-quiet btn-icon profit-report-link" id="viewProfitReportBtn" href="<?= $escape($profitReportUrl) ?>" data-dashboard-action>
                                <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> View profit report
                            </a>
                        </div>
                        <p class="profit-exclusion-note">
                            <i class="bi bi-info-circle" aria-hidden="true"></i> Rent, wages, electricity, and other operating expenses are excluded from this calculation.
                        </p>
                        <div class="profit-incomplete-explanation" id="profit-incomplete-explanation" <?= $isProfitIncomplete ? '' : 'hidden' ?>>
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                            <span id="profit-incomplete-explanation-text">Some sold items have no recorded cost. Product cost and gross profit totals are unavailable until those costs are recorded.</span>
                        </div>
                        <details class="profit-return-help" id="profit-return-help">
                            <summary><i class="bi bi-question-circle" aria-hidden="true"></i> How returns affect profit</summary>
                            <div class="return-help-drawer">
                                <p><strong>Restockable returns:</strong> Returned items returned to saleable inventory reverse both Net Sales and Product Cost, restoring the gross profit margin.</p>
                                <p><strong>Damaged returns:</strong> Damaged, disposed, or unsaleable items reverse Net Sales, but their stock cost is not reversed and remains an inventory loss because the items cannot be resold.</p>
                            </div>
                        </details>
                    </div>
                <?php else: ?>
                    <div class="net-sales-summaries-grid" role="region" aria-label="Net Sales period summaries">
                        <?php 
                        $summaryPeriodDefs = [
                            'today' => "Today's Net Sales",
                            'week' => "This Week's Net Sales",
                            'month' => "This Month's Net Sales",
                        ];
                        foreach ($summaryPeriodDefs as $pKey => $pLabel):
                            $pData = $netSalesPeriods[$pKey] ?? null;
                            $pTotal = $pData['total'] ?? null;
                            $isNegative = $pTotal !== null && $pTotal < 0;
                            $isSelected = $pKey === $selectedPeriod;
                        ?>
                            <article class="stat-card net-sales-summary-card <?= $isSelected ? 'is-selected' : '' ?>" data-summary-period="<?= $pKey ?>">
                                <div class="label-wrap">
                                    <span class="label"><?= $escape($pLabel) ?></span>
                                    <span class="period-badge ongoing">Ongoing</span>
                                </div>
                                <?php if ($hasNetSales && $pTotal !== null): ?>
                                    <div class="value <?= $isNegative ? 'is-negative' : '' ?>"><?= $escape($formatCurrency($pTotal)) ?></div>
                                <?php else: ?>
                                    <div class="value is-unavailable">Unavailable</div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$hasNetSales): ?>
                    <div class="dashboard-state dashboard-state-error" role="alert">
                        <i class="bi bi-cloud-slash" aria-hidden="true"></i>
                        <div>
                            <strong><?= $escape($activePeriodTitle) ?> unavailable</strong>
                            <p>Net sales metrics could not be loaded. Refresh to try again.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php 
                    $emptyNoteText = match($selectedPeriod) {
                        'week' => 'No sales or refunds recorded this week.',
                        'month' => 'No sales or refunds recorded this month.',
                        default => 'No sales or refunds recorded today.',
                    };
                    ?>
                    <p class="section-description net-sales-empty-note" id="net-sales-empty-note" <?= $netSalesHasActivity ? 'hidden' : '' ?>><i class="bi bi-info-circle" aria-hidden="true"></i> <?= $escape($emptyNoteText) ?></p>

                    <div class="net-sales-chart-wrapper">
                        <canvas id="net-sales-hourly-chart" role="img" aria-label="<?= $escape($activePeriodTitle) ?> in Philippine pesos"></canvas>
                    </div>

                    <div class="table-wrap u-table-flat net-sales-table-wrap" role="region" aria-label="<?= $escape($activePeriodTitle) ?> breakdown" tabindex="0">
                        <table class="net-sales-table" aria-label="Exact breakdown for <?= $escape($activePeriodTitle) ?>">
                            <thead>
                                <tr>
                                    <th scope="col" id="net-sales-interval-col"><?= $escape($activePeriodIntervalLabel) ?></th>
                                    <th scope="col" class="net-sales-breakdown-col">Sales</th>
                                    <th scope="col" class="net-sales-breakdown-col">Refunds</th>
                                    <th scope="col">Net Sales</th>
                                </tr>
                            </thead>
                            <tbody id="net-sales-table-body">
                                <?php foreach ($netSalesPoints as $point): ?>
                                    <tr>
                                        <td data-label="<?= $escape($activePeriodIntervalLabel) ?>"><strong><?= $escape($point['label']) ?></strong></td>
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
                <section class="dashboard-section period-comparison" aria-labelledby="comparison-title">
                    <div class="section-header"><div><h2 id="comparison-title"><?= $selectedRange ?>-day performance</h2><p class="section-description">Compared with the previous <?= $selectedRange ?> days.</p></div>
                    <form method="get" action="<?= $escape(app_url('components/administrator/dashboard.php')) ?>" data-dashboard-action>
                        <input type="hidden" name="period" id="rangeFormPeriod" value="<?= $escape($selectedPeriod) ?>">
                        <label class="comparison-range-label" for="dashboardRange">Comparison range</label>
                        <select class="u-select-filter" id="dashboardRange" name="range" onchange="this.form.submit()">
                            <?php foreach ([7, 30, 90] as $range): ?>
                                <option value="<?= $range ?>" <?= $range === $selectedRange ? 'selected' : '' ?>><?= $range ?> days</option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    </div>
                    <div class="comparison-grid">
                        <?php foreach ($comparisonOrder as $key => [$label, $currency]): $item = $dashboard['comparison'][$key]; ?>
                            <article class="comparison-card"><span><?= $escape($label) ?></span><strong><?= $escape($formatMetric($item['current'], $currency)) ?></strong><small>Previous <?= $escape($formatMetric($item['previous'], $currency)) ?> · <?= $escape($formatChange($item['change_percent'])) ?></small></article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="dashboard-section staff-shifts-section" aria-labelledby="staff-shifts-title">
                    <div class="section-header">
                        <div>
                            <h2 id="staff-shifts-title"><i class="bi bi-people" aria-hidden="true"></i> Staff &amp; cashier shifts</h2>
                        </div>
                    </div>
                    <div class="indicator-list">
                        <div class="indicator-item"><span class="indicator-icon"><i class="bi bi-person-check" aria-hidden="true"></i></span><span class="indicator-copy"><strong>Active Cashiers</strong></span><b class="indicator-value"><?= (int)$dashboard['staff']['active_cashiers'] ?></b></div>
                        <div class="indicator-item"><span class="indicator-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span><span class="indicator-copy"><strong>Active Inventory Managers</strong></span><b class="indicator-value"><?= (int)$dashboard['staff']['active_inventory_managers'] ?></b></div>
                        <div class="indicator-item"><span class="indicator-icon"><i class="bi bi-person-x" aria-hidden="true"></i></span><span class="indicator-copy"><strong>Disabled Accounts</strong></span><b class="indicator-value"><?= (int)$dashboard['staff']['disabled_staff'] ?></b></div>
                        <div class="indicator-item"><span class="indicator-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><span class="indicator-copy"><strong>Open cashier shifts</strong></span><b class="indicator-value"><?= (int)$dashboard['shifts']['open'] ?></b></div>
                        <div class="indicator-item"><span class="indicator-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span><span class="indicator-copy"><strong>Cash variances awaiting review</strong></span><b class="indicator-value"><?= (int)$dashboard['shifts']['unreviewed_variances'] ?></b></div>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
    (() => {
        <?php if ($hasNetSales && !empty($netSalesPeriods)): ?>
        if (typeof Chart !== 'undefined') {
            const canvas = document.getElementById('net-sales-hourly-chart');
            const netSalesData = <?= json_encode($netSalesPeriods, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const grossProfitData = <?= json_encode($grossProfitPeriods, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            let currentPeriod = <?= json_encode($selectedPeriod) ?>;

            if (canvas && netSalesData[currentPeriod]) {
                const textColor = getComputedStyle(document.body).getPropertyValue('--text').trim() || '#334155';
                const primaryColor = getComputedStyle(document.body).getPropertyValue('--primary').trim() || '#2563eb';
                const negativeColor = '#ef4444';
                const formatPeso = val => (val < 0 ? '-₱' : '₱') + Math.abs(val).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});

                const getChartConfig = (period) => {
                    const pData = netSalesData[period] || netSalesData['today'] || {};
                    const points = pData.points || [];
                    const labels = points.map(p => p.label);
                    const data = points.map(p => p.net_sales);
                    const axisTitle = period === 'today' ? 'Hour (Philippine time)' : (period === 'week' ? 'Day of Week' : 'Day of Month');

                    return {
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
                                    ticks: { color: textColor, maxTicksLimit: period === 'month' ? 15 : 12 },
                                    grid: { display: false },
                                    title: { display: true, text: axisTitle, color: textColor }
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
                    };
                };

                const chart = new Chart(canvas, getChartConfig(currentPeriod));
                window.netSalesChart = chart;

                window.switchNetSalesPeriod = function(period) {
                    if (!netSalesData[period]) return;
                    currentPeriod = period;
                    const pData = netSalesData[period];

                    document.querySelectorAll('.period-btn').forEach(btn => {
                        const active = btn.dataset.period === period;
                        btn.classList.toggle('active', active);
                        btn.classList.toggle('is-selected', active);
                        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                    });

                    document.querySelectorAll('.net-sales-summary-card').forEach(card => {
                        card.classList.toggle('is-selected', card.dataset.summaryPeriod === period);
                    });

                    const titles = {
                        today: "Today's Net Sales",
                        week: "This Week's Net Sales",
                        month: "This Month's Net Sales"
                    };
                    const titleText = titles[period] || titles.today;
                    const titleEl = document.getElementById('net-sales-title');
                    if (titleEl) titleEl.innerText = titleText;
                    const summaryLabel = document.querySelector('.net-sales-summary-stat .net-sales-summary-label');
                    if (summaryLabel) summaryLabel.innerText = titleText;
                    const summaryVal = document.querySelector('.net-sales-summary-stat .net-sales-summary-value');
                    if (summaryVal && pData.total !== undefined) {
                        summaryVal.innerText = formatPeso(pData.total);
                        summaryVal.classList.toggle('is-negative', pData.total < 0);
                    }

                    if (grossProfitData && grossProfitData[period]) {
                        const gp = grossProfitData[period];
                        const status = gp.status || 'recorded';
                        const statusCap = status.charAt(0).toUpperCase() + status.slice(1);
                        const isIncomplete = status === 'incomplete';
                        const isEstimated = status === 'estimated';
                        const gpTotal = gp.total_gross_profit;
                        const costTotal = gp.total_net_cost;
                        const margin = gp.total_margin;
                        const netSalesTotal = pData.total;
                        const isPositiveComplete = status === 'recorded' && gpTotal !== null && gpTotal > 0;
                        const isProfitNegative = gpTotal !== null && gpTotal < 0;
                        const isCostNegative = costTotal !== null && costTotal < 0;
                        const affectedCount = gp.affected_lines_count || 0;

                        const netSalesCard = document.querySelector('.card-net-sales');
                        if (netSalesCard) {
                            netSalesCard.dataset.summaryPeriod = period;
                            netSalesCard.classList.add('is-selected');
                        }
                        const netSalesLabel = document.getElementById('net-sales-card-label');
                        if (netSalesLabel) netSalesLabel.innerText = titleText;
                        const netSalesVal = document.getElementById('net-sales-card-value');
                        if (netSalesVal && netSalesTotal !== undefined) {
                            netSalesVal.innerText = formatPeso(netSalesTotal);
                            netSalesVal.className = 'value' + (netSalesTotal < 0 ? ' is-negative' : '');
                        }

                        const profitTitles = {
                            today: "Today's Gross Profit",
                            week: "This Week's Gross Profit",
                            month: "This Month's Gross Profit"
                        };
                        const profitCard = document.querySelector('.card-gross-profit');
                        if (profitCard) {
                            profitCard.classList.toggle('is-positive-complete', isPositiveComplete);
                            profitCard.classList.toggle('is-negative', !isIncomplete && isProfitNegative);
                        }
                        const profitLabel = document.getElementById('gross-profit-card-label');
                        if (profitLabel) profitLabel.innerText = profitTitles[period] || profitTitles.today;
                        const profitBadge = document.getElementById('gross-profit-status-badge');
                        if (profitBadge) {
                            profitBadge.className = 'profit-status-badge status-' + status;
                            profitBadge.innerText = statusCap;
                        }
                        const profitVal = document.getElementById('gross-profit-card-value');
                        if (profitVal) {
                            if (isIncomplete) {
                                profitVal.className = 'value is-incomplete';
                                profitVal.innerText = 'Incomplete';
                            } else if (gpTotal !== null && gpTotal !== undefined) {
                                profitVal.className = 'value' + (isProfitNegative ? ' is-negative' : '');
                                profitVal.innerText = formatPeso(gpTotal);
                            } else {
                                profitVal.className = 'value is-unavailable';
                                profitVal.innerText = 'Unavailable';
                            }
                        }
                        const profitMeta = document.getElementById('gross-profit-card-meta');
                        if (profitMeta) {
                            if (isIncomplete) {
                                profitMeta.innerHTML = `<span class="profit-affected-lines" id="profit-affected-lines">${affectedCount} affected sold line${affectedCount === 1 ? '' : 's'} missing cost</span>`;
                            } else if (isEstimated) {
                                profitMeta.innerHTML = `<span class="hint" id="gross-profit-card-hint">Costs estimated &middot; Margin withheld</span>`;
                            } else if (status === 'recorded' && margin !== null && margin !== undefined && netSalesTotal > 0) {
                                profitMeta.innerHTML = `<strong class="profit-margin" id="profit-margin-value">Margin: ${Number(margin).toFixed(1)}%</strong>`;
                            } else {
                                profitMeta.innerHTML = `<span class="hint" id="gross-profit-card-hint">${netSalesTotal <= 0 ? 'Margin withheld for nonpositive sales' : 'Costs recorded'}</span>`;
                            }
                        }

                        const costTitles = {
                            today: "Today's Product Cost",
                            week: "This Week's Product Cost",
                            month: "This Month's Product Cost"
                        };
                        const costCard = document.querySelector('.card-cost-of-sales');
                        if (costCard) {
                            costCard.classList.toggle('is-negative', !isIncomplete && isCostNegative);
                        }
                        const costLabel = document.getElementById('cost-card-label');
                        if (costLabel) costLabel.innerText = costTitles[period] || costTitles.today;
                        const costBadge = document.getElementById('cost-status-badge');
                        if (costBadge) {
                            costBadge.className = 'profit-status-badge status-' + status;
                            costBadge.innerText = statusCap;
                        }
                        const costVal = document.getElementById('cost-card-value');
                        if (costVal) {
                            if (isIncomplete) {
                                costVal.className = 'value is-incomplete';
                                costVal.innerText = 'Incomplete';
                            } else if (costTotal !== null && costTotal !== undefined) {
                                costVal.className = 'value' + (isCostNegative ? ' is-negative' : '');
                                costVal.innerText = formatPeso(costTotal);
                            } else {
                                costVal.className = 'value is-unavailable';
                                costVal.innerText = 'Unavailable';
                            }
                        }
                        const costHint = document.getElementById('cost-card-hint');
                        if (costHint) {
                            costHint.innerText = isIncomplete
                                ? 'Some sold items have no recorded cost'
                                : (isEstimated ? 'Estimated from catalog product costs' : 'Cost of products sold');
                        }

                        const explanationEl = document.getElementById('profit-incomplete-explanation');
                        if (explanationEl) {
                            explanationEl.hidden = !isIncomplete;
                        }

                        const reportBtn = document.getElementById('viewProfitReportBtn');
                        if (reportBtn && gp.date_range && gp.date_range.from && gp.date_range.to) {
                            const repUrl = new URL(reportBtn.getAttribute('href'), window.location.href);
                            repUrl.searchParams.set('from', gp.date_range.from);
                            repUrl.searchParams.set('to', gp.date_range.to);
                            reportBtn.href = repUrl.pathname + repUrl.search;
                        }
                    }

                    const emptyNote = document.getElementById('net-sales-empty-note');
                    if (emptyNote) {
                        const emptyTexts = {
                            today: 'No sales or refunds recorded today.',
                            week: 'No sales or refunds recorded this week.',
                            month: 'No sales or refunds recorded this month.'
                        };
                        emptyNote.innerHTML = '<i class="bi bi-info-circle" aria-hidden="true"></i> ' + (emptyTexts[period] || emptyTexts.today);
                        emptyNote.hidden = pData.has_activity;
                    }

                    if (window.netSalesChart) {
                        const newConfig = getChartConfig(period);
                        window.netSalesChart.data = newConfig.data;
                        window.netSalesChart.options = newConfig.options;
                        window.netSalesChart.update();
                    }

                    const intervalCol = document.getElementById('net-sales-interval-col');
                    const intervalLabel = period === 'today' ? 'Hour' : 'Day';
                    if (intervalCol) intervalCol.innerText = intervalLabel;
                    const tbody = document.getElementById('net-sales-table-body');
                    if (tbody && pData.points) {
                        tbody.innerHTML = pData.points.map(pt => `
                            <tr>
                                <td data-label="${intervalLabel}"><strong>${pt.label}</strong></td>
                                <td data-label="Sales" class="net-sales-breakdown-col">${formatPeso(pt.sales)}</td>
                                <td data-label="Refunds" class="net-sales-breakdown-col">${formatPeso(pt.refunds)}</td>
                                <td data-label="Net Sales" class="${pt.net_sales < 0 ? 'text-negative' : ''}">
                                    ${formatPeso(pt.net_sales)}
                                </td>
                            </tr>
                        `).join('');
                    }

                    const url = new URL(window.location.href);
                    url.searchParams.set('period', period);
                    const currentRange = document.getElementById('dashboardRange')?.value || '<?= (int)$selectedRange ?>';
                    url.searchParams.set('range', currentRange);
                    window.history.replaceState(null, '', url.toString());

                    const rangeFormPeriod = document.getElementById('rangeFormPeriod');
                    if (rangeFormPeriod) rangeFormPeriod.value = period;

                    const refreshBtn = document.getElementById('dashboardRefreshBtn');
                    if (refreshBtn) refreshBtn.href = url.toString();
                };

                document.querySelectorAll('.period-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        window.switchNetSalesPeriod(btn.dataset.period);
                    });
                });
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
