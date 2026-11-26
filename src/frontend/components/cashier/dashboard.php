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
        <main class="main-content" id="cashier-overview" data-api="<?= htmlspecialchars(app_url('components/cashier/gcash_api.php?action=overview')) ?>">
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
$selected_items = $selected_items ?? [];
$canStartSale = $canStartSale ?? true;
require __DIR__ . '/../../../backend/app/Views/sale_receipt_dialog.php';
?>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_url('assets/js/sale-receipt-dialog.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/sale-receipt-dialog.js')) ?>" defer></script>
</body>

</html>
