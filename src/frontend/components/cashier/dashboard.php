<?php
// cashier/dashboard.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/DashboardService.php';
// Issue #86: the active Cashier workspace is authoritative for the POS
// dashboard. Multi-role Staff must switch to Cashier before selling.
require_role(['cashier']);

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
    <title>Cashier Dashboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/cashier.css')) ?>">
</head>

<body class="cashier-page cashier-dashboard-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <nav class="cashier-quick-actions" aria-label="Cashier quick actions">
                <a class="cashier-action-card" href="<?= htmlspecialchars(app_url('components/cashier/pos.php')) ?>">
                    <span class="action-icon"><i class="bi bi-upc-scan" aria-hidden="true"></i></span>
                    <span><strong>New sale</strong><span>Scan items and collect payment</span></span>
                </a>
                <a class="cashier-action-card" href="<?= htmlspecialchars(app_url('components/invoice/sales.php')) ?>">
                    <span class="action-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                    <span><strong>Receipt history</strong><span>Find receipts and reprints</span></span>
                </a>
                <a class="cashier-action-card" href="<?= htmlspecialchars(app_url('components/cashier/refunds.php')) ?>">
                    <span class="action-icon"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></span>
                    <span><strong>Cash refunds</strong><span>Process returns and refunds</span></span>
                </a>
            </nav>

            <section class="card-grid cashier-stats" aria-label="Today's sales summary">
                <article class="stat-card with-icon">
                    <div class="stat-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></div>
                    <div class="value">&#8369;<?= number_format((float)$cashierMetrics['today_sales'], 2) ?></div>
                    <div class="label">Today's gross sales</div>
                    <div class="hint">Before refunds, Philippine time</div>
                </article>
                <article class="stat-card with-icon">
                    <div class="stat-icon"><i class="bi bi-receipt-cutoff" aria-hidden="true"></i></div>
                    <div class="value"><?= (int)$cashierMetrics['transactions'] ?></div>
                    <div class="label">Transactions</div>
                    <div class="hint">Completed checkouts today</div>
                </article>
                <article class="stat-card with-icon success">
                    <div class="stat-icon"><i class="bi bi-graph-up" aria-hidden="true"></i></div>
                    <div class="value">&#8369;<?= number_format((float)$cashierMetrics['average_transaction'], 2) ?></div>
                    <div class="label">Average sale</div>
                    <div class="hint">Average value per checkout</div>
                </article>
                <article class="stat-card with-icon <?= $lowStockWarnings ? 'warning' : 'success' ?>">
                    <div class="stat-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
                    <div class="value"><?= count($lowStockWarnings) ?></div>
                    <div class="label">Low-stock alerts</div>
                    <div class="hint">Products reduced to warning level</div>
                </article>
            </section>

            <div class="cashier-dashboard-grid">
                <section class="dashboard-panel" aria-labelledby="recent-transactions-title">
                    <div class="dashboard-panel-header">
                        <div>
                            <h2 id="recent-transactions-title">Recent transactions</h2>
                            <p class="section-description">Your latest ten checkouts across all dates.</p>
                        </div>
                        <a class="btn btn-small btn-secondary" href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=transactions')) ?>">View all</a>
                    </div>
                    <div class="dashboard-panel-header">
                        <div>
                            <h3>Today's sales by hour</h3>
                            <p class="section-description">All your sales today, before refunds. Philippine time (UTC+08:00).</p>
                        </div>
                    </div>
                        <div class="cashier-sales-chart">
                            <canvas id="cashier-sales-chart" role="img" aria-label="Today's gross sales in pesos by hour, Philippine time.">
                                <?php foreach ($cashierMetrics['hourly_sales'] as $hour => $total): ?>
                                    <?= sprintf('%02d:00', $hour) ?>: PHP <?= number_format($total, 2) ?>;
                                <?php endforeach; ?>
                            </canvas>
                        </div>
                    <?php if (!$cashierMetrics['transactions']): ?>
                        <p class="section-description">No sales recorded today.</p>
                    <?php endif; ?>
                    <div class="table-wrap u-table-flat" role="region" aria-labelledby="recent-transactions-title" tabindex="0">
                        <table class="cashier-recent-table">
                            <thead>
                                <tr>
                                    <th>Receipt</th>
                                    <th>Total</th>
                                    <th>Items</th>
                                    <th>Payment</th>
                                    <th>Time</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentTransactions as $sale): ?>
                                    <tr>
                                        <td data-label="Receipt"><strong>#<?= (int)$sale['sale_id'] ?></strong></td>
                                        <td data-label="Total">&#8369;<?= number_format((float)$sale['total_amount'], 2) ?></td>
                                        <td data-label="Items"><?= (int)$sale['item_count'] ?></td>
                                        <td data-label="Payment"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></td>
                                        <td data-label="Time"><?= htmlspecialchars(format_display_datetime($sale['sale_date'])) ?></td>
                                        <td data-label="Action">
                                            <a class="receipt-link" href="<?= htmlspecialchars(app_url('components/invoice/sales.php?tab=transactions&sale_id=' . (int)$sale['sale_id'])) ?>">
                                                View receipt <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$recentTransactions): ?>
                                    <tr>
                                        <td class="u-empty-cell-padded" colspan="6">No transactions recorded.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <aside class="dashboard-panel" aria-labelledby="stock-alerts-title">
                    <div class="dashboard-panel-header">
                        <div>
                            <h2 id="stock-alerts-title">Stock alerts</h2>
                            <p class="section-description">Warnings triggered by products you sold.</p>
                        </div>
                    </div>
                    <div class="dashboard-panel-body">
                        <div class="stock-alert-list">
                            <?php foreach ($lowStockWarnings as $product): ?>
                                <div class="stock-alert-item">
                                    <div>
                                        <strong><?= htmlspecialchars($product['product_name']) ?></strong>
                                        <span><?= htmlspecialchars($product['sku']) ?> · Reorder at <?= (int)$product['reorder_level'] ?></span>
                                    </div>
                                    <span class="stock-level-pill"><?= (int)$product['quantity_on_hand'] ?> left</span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$lowStockWarnings): ?>
                                <div class="stock-alert-item clear">
                                    <div>
                                        <strong>No current warnings</strong>
                                        <span>Low-stock products will appear here after checkout.</span>
                                    </div>
                                    <span class="stock-level-pill">Clear</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </aside>
            </div>
        </main>
    </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
        <script>
            if (typeof Chart !== 'undefined') {
                const hourlySales = <?= json_encode($cashierMetrics['hourly_sales']) ?>;
                const currency = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'});
                const textColor = getComputedStyle(document.body).getPropertyValue('--text').trim() || '#334155';
                new Chart(document.getElementById('cashier-sales-chart'), {
                    type: 'bar',
                    data: {
                        labels: hourlySales.map((total, hour) => String(hour).padStart(2, '0') + ':00'),
                        datasets: [{
                            label: 'Gross sales (PHP)',
                            data: hourlySales,
                            backgroundColor: getComputedStyle(document.body).getPropertyValue('--primary').trim() || '#2563eb',
                            borderRadius: 6,
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
</body>

</html>
