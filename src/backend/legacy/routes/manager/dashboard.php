<?php
// manager/dashboard.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Services/DashboardService.php';
require_role(['admin', 'inventory_manager']);

$inventoryService = new InventoryService($pdo);
$dashboardService = new DashboardService($pdo);

$inventorySummary = $inventoryService->getInventorySummary();
$managerMetrics = $dashboardService->getManagerMetrics();
$lowStock = array_slice(array_values(array_filter(
    $inventoryService->getLowStockProducts(),
    fn($product) => (int)$product['quantity_on_hand'] > 0
)), 0, 8);
$outOfStock = $pdo->query(
    "SELECT p.sku, p.product_name, i.quantity_on_hand, p.reorder_level
     FROM products p
     JOIN inventory i ON i.product_id = p.product_id
     WHERE p.status = 'active' AND i.quantity_on_hand <= 0
     ORDER BY p.product_name
     LIMIT 8"
)->fetchAll();
$fastMoving = $dashboardService->getFastMovingProducts();
$slowMoving = $dashboardService->getSlowMovingProducts();
$expiringBatches = array_slice($inventoryService->getExpiringSoonBatches(), 0, 8);
$pendingRequests = $dashboardService->getPendingReplenishmentRequests();
$latestForecasts = $dashboardService->getLatestForecasts();
$maxFastMoving = max(1, ...array_map(fn($row) => (int)$row['units_sold'], $fastMoving ?: [['units_sold' => 0]]));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../../../../frontend/components/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Inventory Manager Dashboard</h1>
            <span class="badge-role">Manager: <?= htmlspecialchars($_SESSION['full_name']) ?></span>
        </div>

        <div class="quick-actions">
            <a class="quick-action" href="<?= htmlspecialchars(app_url('manager/products.php')) ?>">
                <strong>Manage Stock</strong>
                <span>Add inventory and update reorder points</span>
            </a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('manager/replenishment_requests.php')) ?>">
                <strong>Replenishment Requests</strong>
                <span>Approve, reject, or receive requests</span>
            </a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('report/predictions.php')) ?>">
                <strong>View Forecasts</strong>
                <span>Review demand and reorder suggestions</span>
            </a>
        </div>

        <div class="card-grid">
            <div class="stat-card <?= $managerMetrics['low_stock_count'] > 0 ? 'warning' : 'success' ?>">
                <div class="value"><?= (int)$managerMetrics['low_stock_count'] ?></div>
                <div class="label">Low-Stock Products</div>
                <div class="hint">At or below reorder level</div>
            </div>
            <div class="stat-card <?= $managerMetrics['out_of_stock_count'] > 0 ? 'warning' : 'success' ?>">
                <div class="value"><?= (int)$managerMetrics['out_of_stock_count'] ?></div>
                <div class="label">Out-of-Stock Products</div>
                <div class="hint">Cannot be sold now</div>
            </div>
            <div class="stat-card <?= $managerMetrics['expiring_count'] > 0 ? 'warning' : 'success' ?>">
                <div class="value"><?= (int)$managerMetrics['expiring_count'] ?></div>
                <div class="label">Expiring Products</div>
                <div class="hint">Batches expiring in 30 days</div>
            </div>
            <div class="stat-card <?= $managerMetrics['pending_replenishment'] > 0 ? 'warning' : 'success' ?>">
                <div class="value"><?= (int)$managerMetrics['pending_replenishment'] ?></div>
                <div class="label">Pending Replenishment</div>
                <div class="hint">Requests waiting for action</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= number_format((int)$managerMetrics['forecasted_demand']) ?></div>
                <div class="label">Forecasted Demand</div>
                <div class="hint">Latest 30-day prediction total</div>
            </div>
            <div class="stat-card">
                <div class="value">&#8369;<?= number_format((float)$managerMetrics['inventory_value'], 2) ?></div>
                <div class="label">Inventory Value</div>
                <div class="hint"><?= number_format((int)$inventorySummary['current_stock']) ?> units on hand</div>
            </div>
        </div>

        <div class="dashboard-layout equal">
            <div class="dashboard-section">
                <h3>Fast-Moving Products</h3>
                <div class="chart-list">
                    <?php foreach ($fastMoving as $product): ?>
                    <?php $width = min(100, round(((int)$product['units_sold'] / $maxFastMoving) * 100)); ?>
                    <div class="chart-row">
                        <div class="chart-label" title="<?= htmlspecialchars($product['product_name']) ?>"><?= htmlspecialchars($product['product_name']) ?></div>
                        <div class="chart-track"><div class="chart-fill" style="width:<?= $width ?>%;"></div></div>
                        <div class="chart-value"><?= (int)$product['units_sold'] ?> sold</div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$fastMoving): ?>
                    <p class="section-description">No sales in the last 30 days.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dashboard-section">
                <h3>Slow-Moving Products</h3>
                <div class="indicator-list">
                    <?php foreach ($slowMoving as $product): ?>
                    <div class="indicator-item">
                        <div>
                            <strong><?= htmlspecialchars($product['product_name']) ?></strong>
                            <span><?= htmlspecialchars($product['sku']) ?> - <?= (int)$product['quantity_on_hand'] ?> on hand</span>
                        </div>
                        <span class="indicator-value"><?= (int)$product['units_sold'] ?> sold</span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$slowMoving): ?>
                    <div class="indicator-item"><strong>No slow-moving products found</strong><span class="decision-pill ok">Clear</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="dashboard-layout equal">
            <div class="dashboard-section">
                <h3>Low Stock and Out of Stock</h3>
                <div class="table-wrap">
                    <table>
                        <tr><th>SKU</th><th>Product</th><th>On Hand</th><th>Reorder</th><th>Status</th></tr>
                        <?php foreach (array_merge($outOfStock, $lowStock) as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['sku']) ?></td>
                            <td><?= htmlspecialchars($p['product_name']) ?></td>
                            <td><?= (int)$p['quantity_on_hand'] ?></td>
                            <td><?= (int)$p['reorder_level'] ?></td>
                            <td><?= (int)$p['quantity_on_hand'] <= 0 ? '<span class="decision-pill action">Restock now</span>' : '<span class="decision-pill info">Watch</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$lowStock && !$outOfStock): ?>
                        <tr><td colspan="5" style="text-align:center;color:var(--muted);">No low-stock or out-of-stock products right now.</td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="dashboard-section">
                <h3>Expiring Products</h3>
                <div class="table-wrap">
                    <table>
                        <tr><th>Product</th><th>Batch</th><th>Remaining</th><th>Expires</th></tr>
                        <?php foreach ($expiringBatches as $batch): ?>
                        <tr>
                            <td><?= htmlspecialchars($batch['sku'] . ' - ' . $batch['product_name']) ?></td>
                            <td><?= htmlspecialchars($batch['batch_number'] ?? '-') ?></td>
                            <td><?= (int)$batch['remaining_quantity'] ?></td>
                            <td><?= htmlspecialchars($batch['expiration_date']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$expiringBatches): ?>
                        <tr><td colspan="4" style="text-align:center;color:var(--muted);">No batches expiring in the next 30 days.</td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <div class="dashboard-layout equal">
            <div class="dashboard-section">
                <h3>Pending Replenishment Requests</h3>
                <div class="indicator-list">
                    <?php foreach ($pendingRequests as $request): ?>
                    <div class="indicator-item">
                        <div>
                            <strong><?= htmlspecialchars($request['product_name']) ?></strong>
                            <span><?= htmlspecialchars($request['sku']) ?> - requested <?= htmlspecialchars(date('M d', strtotime($request['request_date']))) ?></span>
                        </div>
                        <span class="indicator-value"><?= (int)$request['request_qty'] ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$pendingRequests): ?>
                    <div class="indicator-item"><strong>No pending requests</strong><span class="decision-pill ok">Clear</span></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dashboard-section">
                <h3>Forecasted Demand</h3>
                <div class="indicator-list">
                    <?php foreach ($latestForecasts as $forecast): ?>
                    <div class="indicator-item">
                        <div>
                            <strong><?= htmlspecialchars($forecast['product_name']) ?></strong>
                            <span><?= htmlspecialchars($forecast['sku']) ?> - confidence <?= $forecast['confidence_score'] !== null ? (int)round($forecast['confidence_score'] * 100) . '%' : '-' ?></span>
                        </div>
                        <span class="<?= $forecast['reorder_suggested'] ? 'decision-pill action' : 'decision-pill ok' ?>">
                            <?= $forecast['reorder_suggested'] ? 'Order ' . (int)$forecast['suggested_reorder_qty'] : (int)$forecast['predicted_demand_next_30_days'] . ' demand' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$latestForecasts): ?>
                    <div class="indicator-item"><strong>No forecasts generated yet</strong><span class="decision-pill info">Run model</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
