<?php
// cashier/dashboard.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/DashboardService.php';
// Issue #86: the active Cashier workspace is authoritative for the POS
// dashboard. Multi-role Staff must switch to Cashier before selling.
require_role(['cashier']);
require_once __DIR__ . '/../../../backend/app/Views/sale_receipt_support.php';

$dashboardService = new DashboardService($pdo);
$cashierId = (int)$_SESSION['user_id'];
$cashierMetrics = $dashboardService->getCashierMetrics($cashierId);
$recentTransactions = $dashboardService->getCashierRecentTransactions($cashierId);
$lowStockWarnings = $dashboardService->getCashierLowStockWarnings($cashierId);
?>
<!DOCTYPE html>
<html lang="en">

<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Overview</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/gcash.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/gcash.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/sale-receipt.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/sale-receipt.css')) ?>">
</head>

<body class="cashier-page cashier-dashboard-page">
    <a class="skip-link" href="#cashier-overview">Skip to main content</a>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content" id="cashier-overview" data-api="<?= htmlspecialchars(app_url('components/cashier/gcash_api.php?action=overview')) ?>" data-history="<?= htmlspecialchars(app_url('components/cashier/gcash.php?tab=history')) ?>">
            <h1 class="sr-only">Dashboard Overview</h1>
            <div class="gcash-tabs-nav-wrap">
                <nav class="gcash-tablist" role="tablist" aria-label="Cashier overview">
                    <button type="button" role="tab" id="tab-cashier" class="gcash-tab active" aria-selected="true" aria-controls="panel-cashier" tabindex="0">
                        <i class="bi bi-grid-1x2" aria-hidden="true"></i>Cashier
                    </button>
                    <button type="button" role="tab" id="tab-gcash" class="gcash-tab" aria-selected="false" aria-controls="panel-gcash" tabindex="-1">
                        <i class="bi bi-wallet2" aria-hidden="true"></i>GCash
                    </button>
                </nav>
            </div>
            <div role="tabpanel" id="panel-cashier" aria-labelledby="tab-cashier" tabindex="0">
            <section class="card-grid cashier-stats" aria-label="Today's sales summary">
                <article class="stat-card with-icon stat-card-sales">
                    <div class="stat-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></div>
                    <div class="stat-card-top">
                        <span class="label">Gross Sales</span>
                        <span class="stat-tag">Today</span>
                    </div>
                    <div class="value">&#8369;<?= number_format((float)$cashierMetrics['today_sales'], 2) ?></div>
                </article>
                <article class="stat-card with-icon stat-card-txns">
                    <div class="stat-icon"><i class="bi bi-receipt-cutoff" aria-hidden="true"></i></div>
                    <div class="stat-card-top">
                        <span class="label">Transactions</span>
                        <span class="stat-tag">Today</span>
                    </div>
                    <div class="value"><?= (int)$cashierMetrics['transactions'] ?></div>
                </article>
                <article class="stat-card with-icon success stat-card-avg">
                    <div class="stat-icon"><i class="bi bi-graph-up" aria-hidden="true"></i></div>
                    <div class="stat-card-top">
                        <span class="label">Avg. Sale</span>
                        <span class="stat-tag">Today</span>
                    </div>
                    <div class="value">&#8369;<?= number_format((float)$cashierMetrics['average_transaction'], 2) ?></div>
                </article>
                <article class="stat-card with-icon <?= $lowStockWarnings ? 'warning' : 'success' ?> stat-card-alerts">
                    <div class="stat-icon"><i class="bi bi-<?= $lowStockWarnings ? 'exclamation-triangle' : 'check2-circle' ?>" aria-hidden="true"></i></div>
                    <div class="stat-card-top">
                        <span class="label">Low Stock</span>
                        <span class="stat-tag <?= $lowStockWarnings ? 'tag-warning' : 'tag-success' ?>"><?= $lowStockWarnings ? count($lowStockWarnings) . ' alerts' : 'Healthy' ?></span>
                    </div>
                    <div class="value"><?= count($lowStockWarnings) ?></div>
                </article>
            </section>

            <div class="cashier-dashboard-grid">
                <section class="dashboard-panel" aria-labelledby="recent-transactions-title">
                    <div class="dashboard-panel-header">
                        <div class="panel-header-title-group">
                            <h3>Hourly Sales</h3>
                            <span class="panel-tag"><i class="bi bi-clock" aria-hidden="true"></i> Today · PHT</span>
                        </div>
                    </div>
                        <div class="cashier-sales-chart">
                            <canvas id="cashier-sales-chart" role="img" aria-label="Today's gross sales in pesos by hour, Philippine time.">
                                <?php foreach ($cashierMetrics['hourly_sales'] as $hour => $total): ?>
                                    <?= sprintf('%02d:00', $hour) ?>: PHP <?= number_format($total, 2) ?>;
                                <?php endforeach; ?>
                            </canvas>
                        </div>
                    <div class="dashboard-panel-header cashier-transactions-header">
                        <div class="panel-header-title-group">
                            <h2 id="recent-transactions-title">Recent Transactions</h2>
                            <span class="panel-tag"><?= count($recentTransactions) ?> latest</span>
                        </div>
                        <a class="btn btn-small btn-secondary cashier-view-all-btn" href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=transactions')) ?>">View all <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <div class="table-wrap cashier-transactions-wrap" role="region" aria-labelledby="recent-transactions-title" tabindex="0">
                        <table class="cashier-recent-table" data-no-smart-table role="table" aria-labelledby="recent-transactions-title">
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th scope="col" role="columnheader">Receipt</th>
                                    <th scope="col" role="columnheader">Total</th>
                                    <th scope="col" role="columnheader">Items</th>
                                    <th scope="col" role="columnheader">Payment</th>
                                    <th scope="col" role="columnheader">Time</th>
                                    <th scope="col" role="columnheader" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                <?php foreach ($recentTransactions as $sale): ?>
                                    <tr role="row">
                                        <td role="cell" data-label="Receipt"><strong class="receipt-id-tag">#<?= (int)$sale['sale_id'] ?></strong></td>
                                        <td role="cell" data-label="Total"><strong>&#8369;<?= number_format((float)$sale['total_amount'], 2) ?></strong></td>
                                        <td role="cell" data-label="Items"><?= (int)$sale['item_count'] ?></td>
                                        <td role="cell" data-label="Payment"><span class="transaction-payment payment-<?= strtolower(htmlspecialchars($sale['payment_method'])) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></span></td>
                                        <td role="cell" data-label="Time"><?= htmlspecialchars(format_display_datetime($sale['sale_date'])) ?></td>
                                        <td role="cell" data-label="Action">
                                            <a class="btn btn-primary btn-sm receipt-link" href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=transactions&sale_id=' . (int)$sale['sale_id'])) ?>" data-sale-id="<?= (int)$sale['sale_id'] ?>" role="link" onclick="event.preventDefault(); window.viewReceipt(<?= (int)$sale['sale_id'] ?>, this);" aria-haspopup="dialog" aria-label="View receipt #<?= (int)$sale['sale_id'] ?>">
                                                <i class="bi bi-eye" aria-hidden="true"></i> <span>Details</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$recentTransactions): ?>
                                    <tr role="row">
                                        <td role="cell" class="transaction-empty" colspan="6"><i class="bi bi-receipt" aria-hidden="true"></i><strong>No transactions recorded.</strong><span>Your recent sales will appear here.</span></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <aside class="dashboard-panel" aria-labelledby="stock-alerts-title">
                    <div class="dashboard-panel-header">
                        <div class="panel-header-title-group">
                            <h2 id="stock-alerts-title">Stock Alerts</h2>
                            <span class="panel-tag <?= $lowStockWarnings ? 'tag-warning' : 'tag-success' ?>"><?= $lowStockWarnings ? count($lowStockWarnings) . ' low' : 'Healthy' ?></span>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <div class="stock-alert-list">
                            <?php foreach ($lowStockWarnings as $product): ?>
                                <div class="stock-alert-item">
                                    <div class="stock-alert-info">
                                        <strong><?= htmlspecialchars($product['product_name']) ?></strong>
                                        <span class="stock-alert-meta"><?= htmlspecialchars($product['sku']) ?> · Reorder: <?= (int)$product['reorder_level'] ?></span>
                                    </div>
                                    <span class="stock-level-pill warning"><?= (int)$product['quantity_on_hand'] ?> left</span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$lowStockWarnings): ?>
                                <div class="stock-alert-item clear">
                                    <div class="stock-alert-clear-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
                                    <div class="stock-alert-info">
                                        <strong>No stock warnings</strong>
                                        <span class="stock-alert-meta">All inventory levels healthy</span>
                                    </div>
                                    <span class="stock-level-pill">Clear</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </aside>
            </div>
            </div>
        <div role="tabpanel" id="panel-gcash" aria-labelledby="tab-gcash" tabindex="0" class="gcash-tabpanel" hidden>
            <header class="gcash-overview-heading">
                <div class="gcash-overview-brand-group">
                    <div class="gcash-overview-brand-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="5" y="2" width="14" height="20" rx="3"></rect>
                            <line x1="12" y1="18" x2="12.01" y2="18"></line>
                        </svg>
                    </div>
                    <h1 class="gcash-overview-title">GCash Services</h1>
                </div>
                <div class="gcash-overview-quick-actions">
                    <a href="<?= htmlspecialchars(app_url('components/cashier/gcash.php?type=cash-in')) ?>" class="btn btn-primary" id="gcash-overview-action-cash-in">
                        <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>New Cash-In
                    </a>
                    <a href="<?= htmlspecialchars(app_url('components/cashier/gcash.php?type=cash-out')) ?>" class="btn btn-secondary" id="gcash-overview-action-cash-out">
                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>New Cash-Out
                    </a>
                </div>
            </header>
            <p id="gcash-overview-status" role="status">Loading GCash overview…</p>
            <div class="gcash-overview-ledger">
            <section class="pos-panel gcash-overview-section gcash-overview-balances gcash-workspace-balances" aria-labelledby="gcash-overview-balances-title">
                <div class="pos-panel-header">
                    <div class="pos-panel-title">
                        <div class="pos-panel-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
                        <div>
                            <h2 id="gcash-overview-balances-title">Expected balances</h2>
                        </div>
                    </div>
                </div>
                <div class="gcash-overview-grid gcash-balances-grid" aria-live="polite">
                    <div class="gcash-card gcash-card-balance gcash-balance-card gcash-balance-card-cash">
                        <div class="gcash-balance-header">
                            <span class="gcash-balance-icon-wrap gcash-icon-cash" aria-hidden="true">
                                <svg class="gcash-balance-svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="2" y="6" width="20" height="12" rx="2.5"></rect>
                                    <circle cx="12" cy="12" r="2.5"></circle>
                                    <path d="M6 12h.01M18 12h.01"></path>
                                </svg>
                            </span>
                            <span class="gcash-card-label">Physical Service Cash</span>
                        </div>
                        <strong class="gcash-card-value" id="gcash-overview-expected-cash">—</strong>
                    </div>
                    <div class="gcash-card gcash-card-balance gcash-balance-card gcash-balance-card-wallet">
                        <div class="gcash-balance-header">
                            <span class="gcash-balance-icon-wrap gcash-icon-wallet" aria-hidden="true">
                                <svg class="gcash-balance-svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="5" y="2" width="14" height="20" rx="3"></rect>
                                    <line x1="12" y1="18" x2="12.01" y2="18"></line>
                                </svg>
                            </span>
                            <span class="gcash-card-label">GCash App Wallet</span>
                        </div>
                        <strong class="gcash-card-value" id="gcash-overview-expected-wallet">—</strong>
                    </div>
                </div>
                <details class="gcash-balance-details">
                    <summary>Balance details</summary>
                    <p class="gcash-card-sub" id="gcash-overview-cash-checked">Last actual check: Not checked yet</p>
                </details>
            </section>

            <section class="pos-panel gcash-overview-section gcash-overview-shift" aria-labelledby="gcash-overview-metrics-title">
                <div class="pos-panel-header">
                    <div class="pos-panel-title">
                        <div class="pos-panel-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
                        <div>
                            <h2 id="gcash-overview-metrics-title">Current shift</h2>
                        </div>
                    </div>
                </div>
                <div class="gcash-overview-grid gcash-metrics-grid">
                    <div class="gcash-card gcash-shift-metric-card gcash-metric-ci">
                        <div class="gcash-shift-metric-header">
                            <span class="gcash-shift-metric-icon" aria-hidden="true"><i class="bi bi-box-arrow-in-down"></i></span>
                            <span class="gcash-card-label">Cash-In principal</span>
                        </div>
                        <div class="gcash-card-value" id="gcash-metric-cash-in-principal">—</div>
                    </div>
                    <div class="gcash-card gcash-shift-metric-card gcash-metric-co">
                        <div class="gcash-shift-metric-header">
                            <span class="gcash-shift-metric-icon" aria-hidden="true"><i class="bi bi-box-arrow-up-right"></i></span>
                            <span class="gcash-card-label">Cash-Out principal</span>
                        </div>
                        <div class="gcash-card-value" id="gcash-metric-cash-out-principal">—</div>
                    </div>
                    <div class="gcash-card gcash-shift-metric-card gcash-metric-fees-cash">
                        <div class="gcash-shift-metric-header">
                            <span class="gcash-shift-metric-icon" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
                            <span class="gcash-card-label">Gross fees · Cash</span>
                        </div>
                        <div class="gcash-card-value" id="gcash-metric-fees-cash">—</div>
                    </div>
                    <div class="gcash-card gcash-shift-metric-card gcash-metric-fees-wallet">
                        <div class="gcash-shift-metric-header">
                            <span class="gcash-shift-metric-icon" aria-hidden="true"><i class="bi bi-phone"></i></span>
                            <span class="gcash-card-label">Gross fees · Wallet</span>
                        </div>
                        <div class="gcash-card-value" id="gcash-metric-fees-wallet">—</div>
                    </div>
                </div>
            </section>

            <section class="pos-panel gcash-overview-section gcash-overview-review" aria-labelledby="gcash-overview-nc-title">
                <div class="pos-panel-header">
                    <div class="pos-panel-title">
                        <div class="pos-panel-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></div>
                        <div>
                            <h2 id="gcash-overview-nc-title">Needs checking</h2>
                        </div>
                    </div>
                    <div class="gcash-overview-review-actions">
                        <span id="gcash-overview-nc-count" class="gcash-status gcash-status-needs-checking">—</span>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/gcash.php?tab=history')) ?>">View history <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </div>
                <div id="gcash-overview-nc-list" class="gcash-nc-list"></div>
            </section>
            <template id="gcash-overview-empty-template">
                <div class="gcash-empty-state">
                    <svg class="gcash-empty-illustration" width="220" height="170" viewBox="0 0 220 170" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <defs>
                            <linearGradient id="rm-gcash-card-grad-t" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="var(--card-bg, #ffffff)" />
                                <stop offset="100%" stop-color="var(--theme-surface-soft, #f1f5f9)" />
                            </linearGradient>
                            <linearGradient id="rm-gcash-brand-grad-t" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#007dfe" />
                                <stop offset="100%" stop-color="#0056b3" />
                            </linearGradient>
                            <linearGradient id="rm-gcash-badge-grad-t" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#10b981" />
                                <stop offset="100%" stop-color="#059669" />
                            </linearGradient>
                            <filter id="rm-gcash-empty-shadow-t" x="-20%" y="-15%" width="140%" height="140%" filterUnits="userSpaceOnUse">
                                <feDropShadow dx="0" dy="6" stdDeviation="10" flood-color="rgba(15, 23, 42, 0.12)" />
                            </filter>
                        </defs>
                        <ellipse cx="110" cy="148" rx="76" ry="12" fill="var(--theme-surface-soft, rgba(0, 125, 254, 0.08))" />
                        <circle cx="110" cy="80" r="54" fill="var(--primary, #007dfe)" opacity="0.06" />
                        <g filter="url(#rm-gcash-empty-shadow-t)">
                            <rect x="74" y="20" width="72" height="114" rx="14" fill="url(#rm-gcash-card-grad-t)" stroke="var(--border, #cbd5e1)" stroke-width="1.5" />
                        </g>
                        <rect x="98" y="26" width="24" height="3" rx="1.5" fill="var(--muted, #94a3b8)" opacity="0.6" />
                        <rect x="80" y="34" width="60" height="22" rx="6" fill="url(#rm-gcash-brand-grad-t)" opacity="0.95" />
                        <rect x="86" y="41" width="12" height="8" rx="2" fill="#ffffff" opacity="0.95" />
                        <rect x="103" y="41" width="31" height="3" rx="1.5" fill="#ffffff" opacity="0.85" />
                        <rect x="103" y="46" width="20" height="2.5" rx="1.25" fill="#ffffff" opacity="0.6" />
                        <rect x="82" y="66" width="36" height="4" rx="2" fill="var(--muted, #94a3b8)" opacity="0.4" />
                        <rect x="124" y="66" width="16" height="4" rx="2" fill="#10b981" opacity="0.8" />
                        <rect x="82" y="76" width="44" height="4" rx="2" fill="var(--muted, #94a3b8)" opacity="0.3" />
                        <rect x="130" y="76" width="10" height="4" rx="2" fill="#007dfe" opacity="0.6" />
                        <line x1="82" y1="88" x2="138" y2="88" stroke="var(--border, #cbd5e1)" stroke-width="1.5" stroke-dasharray="3 3" />
                        <rect x="82" y="96" width="24" height="5" rx="2" fill="#007dfe" opacity="0.85" />
                        <rect x="116" y="96" width="22" height="5" rx="2" fill="#007dfe" opacity="0.9" />
                        <circle cx="110" cy="123" r="3.5" fill="var(--muted, #94a3b8)" opacity="0.4" />
                        <g transform="translate(130, 88)">
                            <circle cx="18" cy="18" r="22" fill="url(#rm-gcash-badge-grad-t)" filter="url(#rm-gcash-empty-shadow-t)" />
                            <circle cx="18" cy="18" r="20" stroke="rgba(255,255,255,0.3)" stroke-width="1.5" fill="none" />
                            <path d="M12 18 L16 22 L24 14" stroke="#ffffff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                        </g>
                        <path d="M50 46 L52 40 L58 38 L52 36 L50 30 L48 36 L42 38 L48 40 Z" fill="#007dfe" opacity="0.75" />
                        <path d="M170 34 L171.5 29 L176.5 27.5 L171.5 26 L170 21 L168.5 26 L163.5 27.5 L168.5 29 Z" fill="#f59e0b" opacity="0.85" />
                        <circle cx="46" cy="82" r="3" fill="#10b981" opacity="0.75" />
                        <circle cx="174" cy="74" r="2.5" fill="#007dfe" opacity="0.6" />
                    </svg>
                    <h3>All clear — No transactions need checking</h3>
                    <p class="gcash-empty-desc">All transactions are verified and in balance. Discrepancies will appear here for review.</p>
                    <div class="gcash-empty-actions">
                        <a href="<?= htmlspecialchars(app_url('components/cashier/gcash.php?tab=history')) ?>" class="btn btn-secondary">
                            <i class="bi bi-clock-history" aria-hidden="true"></i> View History
                        </a>
                        <a href="<?= htmlspecialchars(app_url('components/cashier/gcash.php')) ?>" class="btn btn-primary">
                            <i class="bi bi-arrows-angle-expand" aria-hidden="true"></i> Open Workspace
                        </a>
                    </div>
                </div>
            </template>
            </div>
        </div>

        </main>
    </div>
        <script src="<?= htmlspecialchars(app_url('assets/vendor/chartjs/chart.umd.min.js') . '?v=' . filemtime(__DIR__ . '/../../assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
        <script>
            if (typeof Chart !== 'undefined') {
                const hourlySales = <?= json_encode($cashierMetrics['hourly_sales']) ?>;
                const currency = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'});
                const textColor = getComputedStyle(document.body).getPropertyValue('--text').trim() || '#334155';
                const primaryColor = getComputedStyle(document.body).getPropertyValue('--primary').trim() || '#2563eb';
                const canvas = document.getElementById('cashier-sales-chart');
                let barBg = primaryColor;
                try {
                    const ctx = canvas.getContext('2d');
                    const grad = ctx.createLinearGradient(0, 0, 0, 240);
                    grad.addColorStop(0, primaryColor);
                    grad.addColorStop(1, 'rgba(37, 99, 235, 0.45)');
                    barBg = grad;
                } catch (_) {}
                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: hourlySales.map((total, hour) => String(hour).padStart(2, '0') + ':00'),
                        datasets: [{
                            label: 'Gross sales (PHP)',
                            data: hourlySales,
                            backgroundColor: barBg,
                            hoverBackgroundColor: primaryColor,
                            borderRadius: 8,
                            maxBarThickness: 48
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
                        plugins: {
                            legend: {display: false},
                            tooltip: {callbacks: {label: context => currency.format(context.parsed.y)}}
                        },
                        scales: {
                            x: {ticks: {color: textColor, maxTicksLimit: 8}, grid: {display: false}, title: {display: true, text: 'Hour (Philippine time)', color: textColor}},
                            y: {beginAtZero: true, ticks: {color: textColor, callback: value => currency.format(value)}}
                        }
                    }
                });
            }
        </script>
<script src="<?= htmlspecialchars(app_url('assets/js/cashier-overview.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/cashier-overview.js')) ?>"></script>
<?php
$selected_sale = $selected_sale ?? null;
$autoPrintSale = $autoPrintSale ?? false;
$selected_items = $selected_items ?? [];
$canStartSale = $canStartSale ?? true;
require __DIR__ . '/../../../backend/app/Views/sale_receipt_dialog.php';
?>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt-dialog.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt-dialog.js')) ?>" defer></script>
</body>

</html>
