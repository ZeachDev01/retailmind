<?php
// cashier/dashboard.php
require_once __DIR__ . '/../../backend/includes/auth.php';
require_once __DIR__ . '/../../backend/includes/functions.php';
require_once __DIR__ . '/../../backend/app/Services/DashboardService.php';
require_role(['admin', 'cashier']);

$dashboardService = new DashboardService($pdo);
$cashierId = (int)$_SESSION['user_id'];
$cashierMetrics = $dashboardService->getCashierMetrics($cashierId);
$recentTransactions = $dashboardService->getCashierRecentTransactions($cashierId);
$lowStockWarnings = $dashboardService->getCashierLowStockWarnings($cashierId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Dashboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/cashier.css')) ?>">
</head>
<body class="cashier-page">
<div class="app-shell">
    <?php include __DIR__ . '/../modules/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar cashier-topbar">
            <div class="cashier-heading">
                <h1>My Sales</h1>
                <p><?= htmlspecialchars(date('l, F j, Y')) ?> performance and recent activity.</p>
            </div>
            <div class="cashier-meta">
                <span class="cashier-chip online">Register ready</span>
                <span class="cashier-chip"><i class="bi bi-person" aria-hidden="true"></i><?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>
        </header>

        <section class="cashier-hero" aria-labelledby="cashier-hero-title">
            <div>
                <h2 id="cashier-hero-title">Ready for the next customer?</h2>
                <p>Open the checkout screen to scan products, adjust quantities, accept payment, and print the receipt.</p>
            </div>
            <a class="btn" href="<?= htmlspecialchars(app_url('cashier/pos.php')) ?>">
                <i class="bi bi-cart-check" aria-hidden="true"></i>Open checkout
            </a>
        </section>

        <nav class="cashier-quick-actions" aria-label="Cashier quick actions">
            <a class="cashier-action-card" href="<?= htmlspecialchars(app_url('cashier/pos.php')) ?>">
                <span class="action-icon"><i class="bi bi-upc-scan" aria-hidden="true"></i></span>
                <span><strong>New sale</strong><span>Scan items and collect payment</span></span>
            </a>
            <a class="cashier-action-card" href="<?= htmlspecialchars(app_url('modules/invoice/sales_history.php')) ?>">
                <span class="action-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                <span><strong>Sales history</strong><span>Find receipts and transactions</span></span>
            </a>
            <a class="cashier-action-card" href="<?= htmlspecialchars(app_url('modules/invoice/reversals.php')) ?>">
                <span class="action-icon"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></span>
                <span><strong>Returns and reversals</strong><span>Review cancellation requests</span></span>
            </a>
        </nav>

        <section class="card-grid cashier-stats" aria-label="Today's sales summary">
            <article class="stat-card with-icon">
                <div class="stat-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></div>
                <div class="value">&#8369;<?= number_format((float)$cashierMetrics['today_sales'], 2) ?></div>
                <div class="label">Today's sales</div>
                <div class="hint">Completed sales under your account</div>
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
                        <h3 id="recent-transactions-title">Recent transactions</h3>
                        <p class="section-description">Your latest completed checkouts.</p>
                    </div>
                    <a class="btn btn-small btn-secondary" href="<?= htmlspecialchars(app_url('modules/invoice/sales_history.php')) ?>">View all</a>
                </div>
                <div class="table-wrap" style="border:0;border-radius:0;">
                    <table class="cashier-recent-table">
                        <thead>
                            <tr><th>Receipt</th><th>Total</th><th>Items</th><th>Payment</th><th>Time</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentTransactions as $sale): ?>
                            <tr>
                                <td><strong>#<?= (int)$sale['sale_id'] ?></strong></td>
                                <td>&#8369;<?= number_format((float)$sale['total_amount'], 2) ?></td>
                                <td><?= (int)$sale['item_count'] ?></td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></td>
                                <td><?= htmlspecialchars(date('g:i A', strtotime($sale['sale_date']))) ?></td>
                                <td>
                                    <a class="receipt-link" href="<?= htmlspecialchars(app_url('modules/invoice/receipt.php?sale_id=' . (int)$sale['sale_id'])) ?>">
                                        View receipt <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$recentTransactions): ?>
                            <tr><td colspan="6" style="padding:2rem;text-align:center;color:var(--muted);">No transactions recorded today.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="dashboard-panel" aria-labelledby="stock-alerts-title">
                <div class="dashboard-panel-header">
                    <div>
                        <h3 id="stock-alerts-title">Stock alerts</h3>
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
</body>
</html>
