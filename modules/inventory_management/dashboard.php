<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/InventoryService.php';
require_once __DIR__ . '/../../app/Services/DashboardService.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$inventoryService = new InventoryService($pdo);
$dashboardService = new DashboardService($pdo);
$rangeDays = in_array((int)($_GET['range'] ?? 30), [7, 30, 90], true) ? (int)($_GET['range'] ?? 30) : 30;
$inventorySummary = $inventoryService->getInventorySummary();
$managerMetrics = $dashboardService->getManagerMetrics();
$lowStock = array_slice(array_values(array_filter($inventoryService->getLowStockProducts(), static fn(array $product): bool => (int)$product['quantity_on_hand'] > 0)), 0, 8);
$outOfStock = $pdo->query(
    "SELECT p.sku, p.product_name, i.quantity_on_hand, p.reorder_level
     FROM products p JOIN inventory i ON i.product_id = p.product_id
     WHERE p.status = 'active' AND i.quantity_on_hand <= 0
     ORDER BY p.product_name LIMIT 8"
)->fetchAll();
$fastMoving = $dashboardService->getFastMovingProducts(6, $rangeDays);
$slowMoving = $dashboardService->getSlowMovingProducts(6, $rangeDays);
$expiringBatches = array_slice($inventoryService->getExpiringSoonBatches(), 0, 8);
$pendingRequests = $dashboardService->getPendingReplenishmentRequests(6);
$latestForecasts = $dashboardService->getLatestForecasts(6);
$salesTrend = $dashboardService->getSalesTrend($rangeDays);
$inventoryByCategory = $dashboardService->getInventoryValueByCategory(8);
$forecastVsActual = $dashboardService->getForecastVsActual(8);
$maxFastMoving = max(1, ...array_map(static fn(array $row): int => (int)$row['units_sold'], $fastMoving ?: [['units_sold' => 0]]));
$needsAttention = (int)$managerMetrics['low_stock_count'] + (int)$managerMetrics['out_of_stock_count'] + (int)$managerMetrics['expiring_count'] + (int)$managerMetrics['pending_replenishment'];
$lastUpdated = date('M d, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Manager Dashboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Inventory Manager Dashboard</h1>
                <p class="page-subtitle">Monitor stock health, sales movement, forecasting, and replenishment priorities.</p>
                <p class="section-description">Last updated <?= htmlspecialchars($lastUpdated) ?></p>
            </div>
            <div class="page-heading-actions">
                <form method="GET" style="display:flex;gap:.5rem;align-items:center">
                    <label for="dashboard-range" class="sr-only">Dashboard range</label>
                    <select name="range" id="dashboard-range" onchange="this.form.submit()" style="min-height:42px;border:1px solid var(--border);border-radius:10px;padding:.55rem .75rem;background:#fff">
                        <option value="7" <?= $rangeDays === 7 ? 'selected' : '' ?>>Last 7 days</option>
                        <option value="30" <?= $rangeDays === 30 ? 'selected' : '' ?>>Last 30 days</option>
                        <option value="90" <?= $rangeDays === 90 ? 'selected' : '' ?>>Last 90 days</option>
                    </select>
                </form>
                <a class="btn btn-quiet btn-icon" href="?range=<?= $rangeDays ?>"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
                <span class="badge-role">Manager: <?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>
        </header>

        <div class="quick-actions">
            <a class="quick-action" href="<?= htmlspecialchars(app_url('modules/inventory_management/products.php')) ?>"><strong><i class="bi bi-box-seam"></i> Products &amp; Stock</strong><span>Search products, print labels, and update planning settings</span></a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('modules/inventory_management/replenishment_requests.php')) ?>"><strong><i class="bi bi-truck"></i> Replenishment Requests</strong><span>Approve, reject, and convert requests into purchase orders</span></a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('report/predictions.php')) ?>"><strong><i class="bi bi-graph-up-arrow"></i> Demand Forecasts</strong><span>Review predictions, confidence, and reorder suggestions</span></a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('report/stock_receiving.php')) ?>"><strong><i class="bi bi-box-arrow-in-down"></i> Stock Receiving</strong><span>Receive approved deliveries with batch and supplier records</span></a>
        </div>

        <div class="card-grid">
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('modules/inventory_management/products.php?stock=low')) ?>"><article class="stat-card with-icon <?= $managerMetrics['low_stock_count'] > 0 ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-exclamation-triangle"></i></span><div class="value"><?= (int)$managerMetrics['low_stock_count'] ?></div><div class="label">Low-Stock Products</div><div class="hint">At or below reorder level</div><div class="stat-meta"><span>Open filtered list</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('modules/inventory_management/products.php?stock=out')) ?>"><article class="stat-card with-icon <?= $managerMetrics['out_of_stock_count'] > 0 ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-x-octagon"></i></span><div class="value"><?= (int)$managerMetrics['out_of_stock_count'] ?></div><div class="label">Out-of-Stock Products</div><div class="hint">Unavailable for checkout</div><div class="stat-meta"><span>Restock now</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('modules/inventory_management/products.php?stock=expiring')) ?>"><article class="stat-card with-icon <?= $managerMetrics['expiring_count'] > 0 ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-calendar2-x"></i></span><div class="value"><?= (int)$managerMetrics['expiring_count'] ?></div><div class="label">Expiring Products</div><div class="hint">Batches expiring within 30 days</div><div class="stat-meta"><span>Review batches</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('modules/inventory_management/replenishment_requests.php')) ?>"><article class="stat-card with-icon <?= $managerMetrics['pending_replenishment'] > 0 ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-truck"></i></span><div class="value"><?= (int)$managerMetrics['pending_replenishment'] ?></div><div class="label">Pending Replenishment</div><div class="hint">Requests waiting for action</div><div class="stat-meta"><span>Review requests</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('report/predictions.php')) ?>"><article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-graph-up-arrow"></i></span><div class="value"><?= number_format((int)$managerMetrics['forecasted_demand']) ?></div><div class="label">Forecasted Demand</div><div class="hint">Latest 30-day prediction total</div><div class="stat-meta"><span>View forecasts</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('modules/inventory_management/inventory_overview.php')) ?>"><article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-cash-stack"></i></span><div class="value">₱<?= number_format((float)$managerMetrics['inventory_value'], 2) ?></div><div class="label">Inventory Value</div><div class="hint"><?= number_format((int)$inventorySummary['current_stock']) ?> units on hand</div><div class="stat-meta"><span>Open valuation</span><i class="bi bi-arrow-right"></i></div></article></a>
        </div>

        <section class="dashboard-section" style="margin-bottom:1.5rem">
            <div class="section-header"><div><h3>Needs Attention</h3><p class="section-description"><?= $needsAttention ?> operational item(s) may require action.</p></div><span class="decision-pill <?= $needsAttention ? 'action' : 'ok' ?>"><?= $needsAttention ? 'Action required' : 'All clear' ?></span></div>
            <div class="attention-list">
                <?php if ($managerMetrics['out_of_stock_count'] > 0): ?><div class="attention-item"><span class="attention-icon"><i class="bi bi-x-octagon"></i></span><span class="attention-copy"><strong><?= (int)$managerMetrics['out_of_stock_count'] ?> products cannot be sold</strong><span>Create or review replenishment requests immediately.</span></span><a class="btn btn-small" href="<?= htmlspecialchars(app_url('modules/inventory_management/products.php?stock=out')) ?>">Review</a></div><?php endif; ?>
                <?php if ($managerMetrics['low_stock_count'] > 0): ?><div class="attention-item"><span class="attention-icon"><i class="bi bi-exclamation-triangle"></i></span><span class="attention-copy"><strong><?= (int)$managerMetrics['low_stock_count'] ?> products are below threshold</strong><span>Check forecasts and supplier lead times before stock runs out.</span></span><a class="btn btn-small" href="<?= htmlspecialchars(app_url('modules/inventory_management/products.php?stock=low')) ?>">Review</a></div><?php endif; ?>
                <?php if ($managerMetrics['expiring_count'] > 0): ?><div class="attention-item"><span class="attention-icon"><i class="bi bi-calendar2-x"></i></span><span class="attention-copy"><strong><?= (int)$managerMetrics['expiring_count'] ?> batches expire soon</strong><span>Review pricing, promotions, or controlled disposal decisions.</span></span><a class="btn btn-small" href="<?= htmlspecialchars(app_url('modules/inventory_management/products.php?stock=expiring')) ?>">Review</a></div><?php endif; ?>
                <?php if ($managerMetrics['pending_replenishment'] > 0): ?><div class="attention-item"><span class="attention-icon"><i class="bi bi-truck"></i></span><span class="attention-copy"><strong><?= (int)$managerMetrics['pending_replenishment'] ?> replenishment requests are pending</strong><span>Approve, reject, or convert them into purchase orders.</span></span><a class="btn btn-small" href="<?= htmlspecialchars(app_url('modules/inventory_management/replenishment_requests.php')) ?>">Review</a></div><?php endif; ?>
                <?php if (!$needsAttention): ?><div class="empty-state" style="min-height:120px"><div><i class="bi bi-check-circle"></i><strong>No urgent inventory issues</strong><span>Stock, expiration, and replenishment indicators are within normal limits.</span></div></div><?php endif; ?>
            </div>
        </section>

        <div class="dashboard-layout equal">
            <section class="dashboard-section"><div class="section-header"><div><h3>Sales Trend</h3><p class="section-description">Daily recorded sales for the selected period.</p></div></div><canvas id="salesTrendChart" height="125" aria-label="Sales trend chart"></canvas></section>
            <section class="dashboard-section"><div class="section-header"><div><h3>Inventory Value by Category</h3><p class="section-description">Cost value distribution across product categories.</p></div></div><canvas id="categoryValueChart" height="125" aria-label="Inventory value by category chart"></canvas></section>
        </div>

        <div class="dashboard-layout equal">
            <section class="dashboard-section"><div class="section-header"><div><h3>Forecast versus Actual Demand</h3><p class="section-description">Latest 30-day prediction compared with actual units sold.</p></div><a href="<?= htmlspecialchars(app_url('report/forecast_analytics.php')) ?>">Analytics</a></div><canvas id="forecastChart" height="145" aria-label="Forecast versus actual demand chart"></canvas></section>
            <section class="dashboard-section"><div class="section-header"><div><h3>Fast-Moving Products</h3><p class="section-description">Top-selling products during the selected period.</p></div></div><div class="chart-list"><?php foreach ($fastMoving as $product): $width = min(100, round(((int)$product['units_sold'] / $maxFastMoving) * 100)); ?><div class="chart-row"><div class="chart-label" title="<?= htmlspecialchars($product['product_name']) ?>"><?= htmlspecialchars($product['product_name']) ?></div><div class="chart-track"><div class="chart-fill" style="width:<?= $width ?>%"></div></div><div class="chart-value"><?= (int)$product['units_sold'] ?> sold</div></div><?php endforeach; ?><?php if (!$fastMoving): ?><div class="empty-state" style="min-height:150px"><div><i class="bi bi-bar-chart"></i><strong>No sales in this period</strong><span>Sales activity will appear after checkout transactions.</span></div></div><?php endif; ?></div></section>
        </div>

        <div class="dashboard-layout equal">
            <section class="dashboard-section"><div class="section-header"><div><h3>Low and Out of Stock</h3><p class="section-description">Products requiring monitoring or immediate replenishment.</p></div><a href="<?= htmlspecialchars(app_url('modules/inventory_management/products.php?stock=low')) ?>">View all</a></div><div class="table-wrap"><table><thead><tr><th>SKU</th><th>Product</th><th>On Hand</th><th>Reorder</th><th>Action</th></tr></thead><tbody><?php foreach (array_merge($outOfStock, $lowStock) as $product): ?><tr><td><?= htmlspecialchars($product['sku']) ?></td><td><?= htmlspecialchars($product['product_name']) ?></td><td><?= (int)$product['quantity_on_hand'] ?></td><td><?= (int)$product['reorder_level'] ?></td><td><a class="btn btn-small" href="<?= htmlspecialchars(app_url('modules/inventory_management/replenishment_requests.php')) ?>"><?= (int)$product['quantity_on_hand'] <= 0 ? 'Restock now' : 'Review' ?></a></td></tr><?php endforeach; ?><?php if (!$lowStock && !$outOfStock): ?><tr><td colspan="5"><div class="empty-state" style="min-height:140px"><div><i class="bi bi-check-circle"></i><strong>Stock levels are healthy</strong></div></div></td></tr><?php endif; ?></tbody></table></div></section>
            <section class="dashboard-section"><div class="section-header"><div><h3>Expiring Products</h3><p class="section-description">Remaining batch quantities approaching expiration.</p></div><a href="<?= htmlspecialchars(app_url('modules/inventory_management/products.php?stock=expiring')) ?>">View all</a></div><div class="table-wrap"><table><thead><tr><th>Product</th><th>Batch</th><th>Remaining</th><th>Expires</th></tr></thead><tbody><?php foreach ($expiringBatches as $batch): ?><tr><td><?= htmlspecialchars($batch['sku'] . ' — ' . $batch['product_name']) ?></td><td><?= htmlspecialchars($batch['batch_number'] ?? '—') ?></td><td><?= (int)$batch['remaining_quantity'] ?></td><td><?= htmlspecialchars(date('M d, Y', strtotime($batch['expiration_date']))) ?></td></tr><?php endforeach; ?><?php if (!$expiringBatches): ?><tr><td colspan="4"><div class="empty-state" style="min-height:140px"><div><i class="bi bi-calendar-check"></i><strong>No batches expiring soon</strong></div></div></td></tr><?php endif; ?></tbody></table></div></section>
        </div>

        <div class="dashboard-layout equal">
            <section class="dashboard-section"><div class="section-header"><div><h3>Pending Replenishment</h3><p class="section-description">Oldest requests waiting for action.</p></div><a href="<?= htmlspecialchars(app_url('modules/inventory_management/replenishment_requests.php')) ?>">Manage requests</a></div><div class="indicator-list"><?php foreach ($pendingRequests as $request): ?><div class="indicator-item"><div><strong><?= htmlspecialchars($request['product_name']) ?></strong><span><?= htmlspecialchars($request['sku']) ?> · requested <?= htmlspecialchars(date('M d', strtotime($request['request_date']))) ?></span></div><span class="indicator-value"><?= (int)$request['request_qty'] ?></span></div><?php endforeach; ?><?php if (!$pendingRequests): ?><div class="empty-state" style="min-height:140px"><div><i class="bi bi-truck"></i><strong>No pending requests</strong></div></div><?php endif; ?></div></section>
            <section class="dashboard-section"><div class="section-header"><div><h3>Slow-Moving Products</h3><p class="section-description">Products with stock but limited sales during the selected period.</p></div></div><div class="indicator-list"><?php foreach ($slowMoving as $product): ?><div class="indicator-item"><div><strong><?= htmlspecialchars($product['product_name']) ?></strong><span><?= htmlspecialchars($product['sku']) ?> · <?= (int)$product['quantity_on_hand'] ?> on hand</span></div><span class="indicator-value"><?= (int)$product['units_sold'] ?> sold</span></div><?php endforeach; ?><?php if (!$slowMoving): ?><div class="empty-state" style="min-height:140px"><div><i class="bi bi-check-circle"></i><strong>No slow-moving products found</strong></div></div><?php endif; ?></div></section>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
if (window.Chart) {
const chartText = '#64748b';
Chart.defaults.color = chartText;
Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
new Chart(document.getElementById('salesTrendChart'), {type:'line',data:{labels:<?= json_encode(array_map(static fn(array $row): string => date('M d', strtotime($row['sale_day'])), $salesTrend)) ?>,datasets:[{label:'Sales (₱)',data:<?= json_encode(array_map(static fn(array $row): float => (float)$row['total_sales'], $salesTrend)) ?>,borderWidth:2,tension:.32,fill:true}]},options:{responsive:true,interaction:{mode:'index',intersect:false},plugins:{tooltip:{callbacks:{label:context=>'₱'+Number(context.raw).toLocaleString(undefined,{minimumFractionDigits:2})}},legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:value=>'₱'+Number(value).toLocaleString()}}}}});
new Chart(document.getElementById('categoryValueChart'), {type:'doughnut',data:{labels:<?= json_encode(array_column($inventoryByCategory, 'category_name')) ?>,datasets:[{data:<?= json_encode(array_map(static fn(array $row): float => (float)$row['inventory_value'], $inventoryByCategory)) ?>}]},options:{responsive:true,plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:context=>`${context.label}: ₱${Number(context.raw).toLocaleString(undefined,{minimumFractionDigits:2})}`}}}}});
new Chart(document.getElementById('forecastChart'), {type:'bar',data:{labels:<?= json_encode(array_map(static fn(array $row): string => $row['product_name'], $forecastVsActual)) ?>,datasets:[{label:'Forecast',data:<?= json_encode(array_map(static fn(array $row): int => (int)$row['forecast_demand'], $forecastVsActual)) ?>},{label:'Actual',data:<?= json_encode(array_map(static fn(array $row): int => (int)$row['actual_units'], $forecastVsActual)) ?>}]},options:{responsive:true,interaction:{mode:'index',intersect:false},scales:{y:{beginAtZero:true}},plugins:{legend:{position:'bottom'}}}});
}
</script>
</body>
</html>
