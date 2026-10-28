<?php
// components/inventory_management/inventory_overview.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/InventoryService.php';
require_once __DIR__ . '/../../../backend/app/Services/ProductService.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$inventoryService = new InventoryService($pdo);
$productService = new ProductService($pdo);

if (isset($_GET['action']) && $_GET['action'] === 'get_batches') {
    $productId = (int)($_GET['product_id'] ?? 0);
    $batches = $inventoryService->getProductBatches($productId);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($batches);
    exit;
}

$products = $inventoryService->getProductsWithBatchSummaries();
$low_stock = $inventoryService->getLowStockProducts();
$expiring_batches = $inventoryService->getExpiringSoonBatches(30);
$expired_batches = $inventoryService->getExpiredBatches();
$fefo_recommendations = $inventoryService->getFefoRecommendations();
$lowStockProductIds = array_fill_keys(array_map('intval', array_column($low_stock, 'product_id')), true);
$expiringProductIds = array_fill_keys(array_map('intval', array_column($expiring_batches, 'product_id')), true);
$expiredProductIds = array_fill_keys(array_map('intval', array_column($expired_batches, 'product_id')), true);
$total_products = count($products);
$total_units = 0;
$out_of_stock = 0;
$out_of_stock_products = [];
foreach ($products as $p) {
    $qty = (int)($p['quantity_on_hand'] ?? 0);
    $total_units += $qty;
    if ($qty <= 0) {
        $out_of_stock++;
        $out_of_stock_products[] = [
            'sku' => $p['sku'] ?? '',
            'product_name' => $p['product_name'] ?? '',
            'quantity_on_hand' => $qty,
            'reorder_level' => (int)($p['reorder_level'] ?? 0),
        ];
    }
}
[$scopeSql, $scopeParams] = store_product_scope('p');
$recentStmt = $pdo->prepare(
    "SELECT sm.movement_id, sm.change_qty, sm.reason, sm.moved_at, p.sku, p.product_name, u.full_name AS moved_by_name
     FROM stock_movements sm
     JOIN products p ON sm.product_id = p.product_id
     LEFT JOIN users u ON sm.moved_by = u.user_id
    WHERE 1 = 1{$scopeSql}
     ORDER BY sm.moved_at DESC
     LIMIT 10"
);
$recentStmt->execute($scopeParams);
$recent_movements = $recentStmt->fetchAll();

$categoryStats = [];
$statusCounts = [
    'available' => 0,
    'low' => 0,
    'out' => 0,
    'expiring' => 0,
    'expired' => 0,
];

$missing_expiration_count = 0;

foreach ($products as $p) {
    $cat = trim((string)($p['category_name'] ?? 'Uncategorized'));
    if ($cat === '') {
        $cat = 'Uncategorized';
    }
    $qty = (int)($p['quantity_on_hand'] ?? 0);
    if (!isset($categoryStats[$cat])) {
        $categoryStats[$cat] = 0;
    }
    $categoryStats[$cat] += $qty;

    if (!empty($p['has_missing_expiration'])) {
        $missing_expiration_count++;
    }

    $pid = (int)($p['product_id'] ?? 0);
    $threshold = max((int)($p['reorder_level'] ?? 0), (int)($p['safety_stock'] ?? 0));
    $isExp = ($p['expired_quantity'] ?? 0) > 0 || isset($expiredProductIds[$pid]);
    $isExpiring = !$isExp && isset($expiringProductIds[$pid]);
    if ($isExp) {
        $statusCounts['expired']++;
    } elseif ($isExpiring) {
        $statusCounts['expiring']++;
    } elseif ($qty <= 0) {
        $statusCounts['out']++;
    } elseif ($qty <= $threshold || isset($lowStockProductIds[$pid])) {
        $statusCounts['low']++;
    } else {
        $statusCounts['available']++;
    }
}

arsort($categoryStats);
$productCategories = array_keys($categoryStats);
sort($productCategories, SORT_NATURAL | SORT_FLAG_CASE);
$topCategories = array_slice($categoryStats, 0, 8, true);
$categoryLabels = array_keys($topCategories);
$categoryUnits = array_values($topCategories);
$forecastProducts = get_stored_predictions($pdo);
usort($forecastProducts, static fn(array $a, array $b): int =>
    (float)$b['predicted_demand_next_30_days'] <=> (float)$a['predicted_demand_next_30_days']
);
$topForecastProducts = array_slice($forecastProducts, 0, 10);

$chartDataJson = json_encode([
    'forecast' => [
        'labels' => array_column($topForecastProducts, 'product_name'),
        'stock' => array_map('floatval', array_column($topForecastProducts, 'current_stock')),
        'demand7' => array_map('floatval', array_column($topForecastProducts, 'predicted_demand_next_7_days')),
        'demand30' => array_map('floatval', array_column($topForecastProducts, 'predicted_demand_next_30_days')),
    ],
    'status' => [
        'labels' => ['Available', 'Low Stock', 'Out of Stock', 'Expiring Soon', 'Expired'],
        'values' => [
            $statusCounts['available'],
            $statusCounts['low'],
            $statusCounts['out'],
            $statusCounts['expiring'],
            $statusCounts['expired'],
        ],
        'keys' => ['in-stock', 'low', 'out', 'expiring', 'expired'],
    ],
    'categories' => [
        'labels' => $categoryLabels,
        'values' => $categoryUnits,
    ],
    'totals' => [
        'products' => $total_products,
        'units' => $total_units,
    ],
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock Overview</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/inventory.css')) ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
</head>
<body class="inventory-overview-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content overview-main">
        <header class="page-heading"><div>
            <div class="overview-tabs" id="overview-product-tabs" role="tablist" aria-label="Inventory sections">
                <button type="button" class="overview-tab is-active" id="overview-summary-tab" role="tab" aria-selected="true" aria-controls="overview-metrics" data-overview-page-tab="summary">Stock Overview</button>
                <button type="button" class="overview-tab" id="overview-products-tab" role="tab" aria-selected="false" aria-controls="overview-product-view" data-overview-page-tab="products" tabindex="-1">Products</button>
            </div>
        </div></header>
        <section class="overview-metrics" id="overview-metrics" aria-label="Inventory summary">
            <button type="button" class="overview-metric" data-overview-view="all" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format((int)$total_products) ?></strong><span>Total products</span></span>
            </button>
            <button type="button" class="overview-metric" data-overview-view="in-stock" aria-controls="overview-product-view">
                <span class="overview-metric-icon overview-metric-icon--green" aria-hidden="true"><i class="bi bi-boxes"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format((int)$total_units) ?></strong><span>Units on hand</span></span>
            </button>
            <button type="button" class="overview-metric overview-metric--danger" data-overview-view="out" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-x-octagon"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format((int)$out_of_stock) ?></strong><span>Out of stock</span></span>
            </button>
            <button type="button" class="overview-metric overview-metric--warning" data-overview-view="low" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format(count($low_stock)) ?></strong><span>Low stock</span></span>
            </button>
            <button type="button" class="overview-metric overview-metric--warning" data-overview-view="expiring" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format(count($expiring_batches)) ?></strong><span>Expiring soon</span></span>
            </button>
            <button type="button" class="overview-metric overview-metric--danger" data-overview-view="expired" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-calendar-x"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format(count($expired_batches)) ?></strong><span>Expired batches</span></span>
            </button>
            <button type="button" class="overview-metric overview-metric--warning" data-overview-view="missing-exp" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-calendar-minus"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format((int)$missing_expiration_count) ?></strong><span>Missing Expiration</span></span>
            </button>
        </section>


        <section class="overview-chart-card overview-forecast-card" id="overview-forecast" aria-labelledby="overview-forecast-heading">
            <div class="overview-chart-header">
                <div>
                    <span class="overview-chart-icon overview-chart-icon--blue" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span>
                    <div>
                        <h3 id="overview-forecast-heading">Demand Forecast vs Current Stock</h3>
                    </div>
                </div>
            </div>
            <?php if ($topForecastProducts): ?>
            <div class="overview-chart-body">
                <canvas id="overviewForecastChart" aria-label="Current stock and predicted demand for the next 7 and 30 days by product, in units" role="img"></canvas>
            </div>
            <details>
                <summary>View forecast values</summary>
                <p>Top 10 products by predicted 30-day demand. Forecasts are estimates; 7-day demand is included in the 30-day total.</p>
                <div class="overview-table-shell"><table class="overview-table">
                    <thead><tr><th>Product</th><th>Current stock</th><th>7-day demand</th><th>30-day demand</th></tr></thead>
                    <tbody><?php foreach ($topForecastProducts as $forecast): ?>
                    <tr><td><?= htmlspecialchars($forecast['product_name']) ?></td><td><?= number_format((float)$forecast['current_stock']) ?></td><td><?= number_format((float)$forecast['predicted_demand_next_7_days']) ?></td><td><?= number_format((float)$forecast['predicted_demand_next_30_days']) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </details>
            <?php else: ?>
            <p class="overview-empty">No forecasts available. Check Demand Forecasting.</p>
            <?php endif; ?>
        </section>

        <section class="overview-charts-grid" id="overview-charts" aria-label="Inventory visual analytics">
            <div class="overview-chart-card">
                <div class="overview-chart-header">
                    <div>
                        <span class="overview-chart-icon" aria-hidden="true"><i class="bi bi-pie-chart-fill"></i></span>
                        <div>
                            <h3>Stock Health Distribution</h3>
                        </div>
                    </div>
                </div>
                <div class="overview-chart-body">
                    <canvas id="overviewStatusChart" aria-label="Stock health distribution chart" role="img"></canvas>
                </div>
            </div>

            <div class="overview-chart-card">
                <div class="overview-chart-header">
                    <div>
                        <span class="overview-chart-icon overview-chart-icon--blue" aria-hidden="true"><i class="bi bi-bar-chart-fill"></i></span>
                        <div>
                            <h3>Stock Volume by Category</h3>
                        </div>
                    </div>
                </div>
                <div class="overview-chart-body">
                    <canvas id="overviewCategoryChart" aria-label="Stock volume by category chart" role="img"></canvas>
                </div>
            </div>
        </section>

        <section class="overview-product-view" id="overview-product-view" aria-label="Filtered products" hidden>
            <div class="overview-product-view-header">
                <button type="button" class="btn btn-quiet" id="overview-back">View stock movements</button>
                <div class="overview-view-filter-group">
                    <div class="overview-filter-field overview-search-field">
                        <input type="search" id="overview-product-search" class="form-control" placeholder="Search products" aria-label="Search products" aria-controls="overview-product-table-body">
                    </div>
                    <div class="overview-filter-field">
                        <select id="overview-category-filter" class="form-select" aria-label="Filter by category">
                            <option value="">All Categories</option>
                            <?php foreach ($productCategories as $category): ?>
                            <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="overview-filter-field">
                        <select id="overview-filter-select" class="form-select form-select-sm" aria-label="Filter by stock status">
                            <option value="all">All Products</option>
                            <option value="in-stock">🟢 In Stock</option>
                            <option value="out">🔴 Out of Stock</option>
                            <option value="low">🟡 Low Stock</option>
                            <option value="expiring">Expiring Soon</option>
                            <option value="expired">Expired</option>
                            <option value="missing-exp">Missing Expiration</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="overview-product-table-shell">
                <table class="overview-product-table">
                    <thead>
                        <tr>
                            <th class="overview-select-cell"><input type="checkbox" id="overview-select-all" aria-label="Select all visible products"></th>
                            <th aria-sort="none"><button type="button" data-sort-column="1">Product <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th aria-sort="none"><button type="button" data-sort-column="2">Status <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th aria-sort="none"><button type="button" data-sort-column="3">SKU / Barcode <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th aria-sort="none"><button type="button" data-sort-column="4">Category <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th aria-sort="none"><button type="button" data-sort-column="5" data-sort-type="number">Cost Price <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th aria-sort="none"><button type="button" data-sort-column="6" data-sort-type="number">Selling Price <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th aria-sort="none"><button type="button" data-sort-column="7" data-sort-type="number">Price minus cost per item <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th aria-sort="none"><button type="button" data-sort-column="8" data-sort-type="number">On Hand <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th aria-sort="none"><button type="button" data-sort-column="9" data-sort-type="number">Expired Qty <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th class="overview-col-undated" style="display:none;">Qty Without Date</th>
                            <th aria-sort="none"><button type="button" data-sort-column="11" data-sort-type="date">Next Expiration <i class="bi bi-arrow-down-up" aria-hidden="true"></i></button></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="overview-product-table-body">
                    <?php foreach ($products as $product): ?>
                    <?php
                        $productId = (int)$product['product_id'];
                        $quantity = (int)($product['quantity_on_hand'] ?? 0);
                        $threshold = max((int)($product['reorder_level'] ?? 0), (int)($product['safety_stock'] ?? 0));
                        $stockState = $quantity <= 0 ? 'out' : ($quantity <= $threshold ? 'low' : 'available');
                        $isLowStock = isset($lowStockProductIds[$productId]);
                        $expiredQty = (int)($product['expired_quantity'] ?? 0);
                        $undatedQty = (int)($product['undated_quantity'] ?? 0);
                        $isExpired = $expiredQty > 0 || isset($expiredProductIds[$productId]);
                        $isExpiring = !$isExpired && isset($expiringProductIds[$productId]);
                        $hasMissingExp = !empty($product['has_missing_expiration']);
                        $hasUnbatched = !empty($product['has_unbatched_stock']);
                        $unbatchedQty = (int)($product['unbatched_quantity'] ?? 0);
                        $nextExp = $product['next_expiration'] ? htmlspecialchars($product['next_expiration']) : '—';
                        $statusClass = $isExpired ? 'expired' : ($isExpiring ? 'expiring' : $stockState);
                        $statusLabel = $isExpired ? 'Expired' : ($isExpiring ? 'Expiring soon' : ($stockState === 'out' ? 'Out of stock' : ($stockState === 'low' ? 'Low stock' : 'Available')));
                        $productCategory = trim((string)($product['category_name'] ?? ''));
                        $productCategory = $productCategory === '' ? 'Uncategorized' : $productCategory;
                    ?>
                    <tr data-overview-product-row data-category="<?= htmlspecialchars($productCategory) ?>" data-search="<?= htmlspecialchars(implode(' ', [$product['product_name'], $product['brand'] ?? '', $product['sku'] ?? '', $product['barcode'] ?? ''])) ?>" data-stock-state="<?= $stockState ?>" data-low="<?= $isLowStock ? '1' : '0' ?>" data-expiring="<?= $isExpiring ? '1' : '0' ?>" data-expired="<?= $isExpired ? '1' : '0' ?>" data-missing-exp="<?= $hasMissingExp ? '1' : '0' ?>" data-unbatched="<?= $hasUnbatched ? '1' : '0' ?>">
                        <td class="overview-select-cell"><input type="checkbox" class="overview-row-select" aria-label="Select <?= htmlspecialchars($product['product_name']) ?>"></td>
                        <td data-sort-value="<?= htmlspecialchars($product['product_name']) ?>"><div class="overview-product-name"><span class="overview-product-thumb"><i class="bi bi-box-seam" aria-hidden="true"></i></span><span><strong><?= htmlspecialchars($product['product_name']) ?></strong><small><?= htmlspecialchars($product['brand'] ?: 'No brand') ?></small></span></div></td>
                        <td><span class="overview-stock-status overview-stock-status--<?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                        <td data-sort-value="<?= htmlspecialchars(($product['sku'] ?? '') . ' ' . ($product['barcode'] ?? '')) ?>"><strong><?= htmlspecialchars($product['sku'] ?? '-') ?></strong><small><?= htmlspecialchars($product['barcode'] ?: 'Not assigned') ?></small></td>
                        <td><?= htmlspecialchars($productCategory) ?></td>
                        <td data-sort-value="<?= (float)($product['cost_price'] ?? 0) ?>">₱<?= number_format((float)($product['cost_price'] ?? 0), 2) ?></td>
                        <td data-sort-value="<?= (float)($product['unit_price'] ?? 0) ?>">₱<?= number_format((float)($product['unit_price'] ?? 0), 2) ?></td>
                        <td data-sort-value="<?= (float)($product['cost_price'] ?? 0) - (float)($product['unit_price'] ?? 0) ?>">₱<?= number_format((float)($product['cost_price'] ?? 0) - (float)($product['unit_price'] ?? 0), 2) ?></td>
                        <td data-sort-value="<?= $quantity ?>">
                            <strong><?= number_format($quantity) ?></strong>
                            <?php if ($expiredQty > 0): ?>
                                <small class="text-muted d-block">Saleable: <?= number_format(max(0, $quantity - $expiredQty)) ?></small>
                            <?php endif; ?>
                            <small>Reorder at <?= number_format((int)($product['reorder_level'] ?? 0)) ?></small>
                            <?php if ($hasUnbatched): ?>
                                <span class="overview-stock-flag overview-stock-flag--unbatched" title="Reconciliation exception: Recorded stock exceeds batch balances"><i class="bi bi-exclamation-diamond-fill" aria-hidden="true"></i> Unbatched: <?= number_format($unbatchedQty) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-sort-value="<?= $expiredQty ?>">
                            <?php if ($expiredQty > 0): ?>
                                <strong class="text-danger"><?= number_format($expiredQty) ?></strong>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="overview-col-undated" style="display:none;">
                            <?php if ($undatedQty > 0): ?>
                                <strong class="text-warning"><?= number_format($undatedQty) ?></strong>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td data-sort-value="<?= htmlspecialchars($product['next_expiration'] ?? '') ?>"><span class="overview-next-exp"><?= $nextExp ?></span></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-quiet btn-view-batches" data-product-id="<?= $productId ?>" data-product-name="<?= htmlspecialchars($product['product_name']) ?>" data-unbatched="<?= $hasUnbatched ? '1' : '0' ?>" data-unbatched-qty="<?= $unbatchedQty ?>" title="View Batches">
                                <i class="bi bi-boxes" aria-hidden="true"></i> View Batches
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="overview-product-empty" id="overview-product-empty" hidden><i class="bi bi-inbox" aria-hidden="true"></i><strong>No matching products</strong><span>Clear your search or change the category and stock filters.</span></div>
            </div>
            <nav class="overview-product-pagination" id="overview-product-pagination" aria-label="Product pagination">
                <span id="overview-page-summary" role="status">Showing 0 products</span>
                <div>
                    <button type="button" class="btn btn-quiet" id="overview-page-previous"><i class="bi bi-chevron-left" aria-hidden="true"></i>Previous</button>
                    <span id="overview-page-status" aria-live="polite">Page 1 of 1</span>
                    <button type="button" class="btn btn-quiet" id="overview-page-next">Next<i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </div>
            </nav>
        </section>

        <div id="overview-default-view" class="overview-tab-card" hidden>
        <div class="overview-tabs" role="tablist" aria-label="Inventory overview sections">
            <button type="button" class="overview-tab is-active" id="overview-tab-movements" role="tab" aria-selected="true" aria-controls="overview-panel-movements" data-overview-tab="movements">Recent Stock Movements</button>
            <button type="button" class="overview-tab" id="overview-tab-fefo" role="tab" aria-selected="false" aria-controls="overview-panel-fefo" data-overview-tab="fefo" tabindex="-1">FEFO Pick Recommendations</button>
        </div>
        <div class="overview-tab-panels">
        <section class="overview-section overview-tab-panel is-active" id="overview-panel-movements" role="tabpanel" aria-labelledby="overview-tab-movements" data-overview-tab-panel="movements">
            <header class="overview-section-header">
                <div><span class="overview-section-icon" aria-hidden="true"><i class="bi bi-arrow-left-right"></i></span><div><h2 id="movement-heading">Recent Stock Movements</h2></div></div>
            </header>
            <div class="overview-table-shell">
                <table class="overview-table">
                    <thead><tr><th>Product</th><th>Change</th><th>Reason</th><th>Recorded by</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_movements as $movement): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($movement['product_name']) ?></strong><small><?= htmlspecialchars($movement['sku']) ?></small></td>
                        <td><span class="movement-change <?= (int)$movement['change_qty'] >= 0 ? 'movement-change--in' : 'movement-change--out' ?>"><?= (int)$movement['change_qty'] > 0 ? '+' : '' ?><?= (int)$movement['change_qty'] ?></span></td>
                        <td><?= htmlspecialchars($movement['reason']) ?></td>
                        <td><?= htmlspecialchars($movement['moved_by_name'] ?? 'System') ?></td>
                        <td><?= htmlspecialchars(format_display_datetime($movement['moved_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent_movements): ?>
                    <tr><td class="overview-empty" colspan="5"><i class="bi bi-arrow-left-right" aria-hidden="true"></i><strong>No stock movements yet</strong><span>Receiving and adjustment activity will appear here.</span></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <nav class="overview-tab-pagination" id="movement-pagination" aria-label="Stock movement pagination" hidden>
                <span id="movement-page-summary">Showing 0 movements</span>
                <div>
                    <button type="button" class="btn btn-quiet" id="movement-page-previous"><i class="bi bi-chevron-left" aria-hidden="true"></i>Previous</button>
                    <span id="movement-page-status" aria-live="polite">Page 1 of 1</span>
                    <button type="button" class="btn btn-quiet" id="movement-page-next">Next<i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </div>
            </nav>
        </section>

        <section class="overview-section overview-tab-panel" id="overview-panel-fefo" role="tabpanel" aria-labelledby="overview-tab-fefo" data-overview-tab-panel="fefo" hidden>
            <header class="overview-section-header">
                <div><span class="overview-section-icon overview-section-icon--amber" aria-hidden="true"><i class="bi bi-calendar2-week"></i></span><div><h2 id="fefo-heading">FEFO Pick Recommendations</h2></div></div>
                <a href="<?= htmlspecialchars(app_url('components/report/stock_receiving.php')) ?>">Open stock receiving <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </header>
            <div class="overview-table-shell">
                <table class="overview-table">
                    <thead><tr><th>Product</th><th>Batch</th><th>Remaining</th><th>Expiration</th><th>Supplier</th></tr></thead>
                    <tbody>
                    <?php foreach ($fefo_recommendations as $batch): ?>
                    <?php
                        $todayManila = \App\Services\PhilippineTime::today();
                        $expDate = !empty($batch['expiration_date']) ? trim((string)$batch['expiration_date']) : null;
                        $daysRemaining = null;
                        if ($expDate !== null && $expDate !== '') {
                            $daysRemaining = (int)round((strtotime($expDate) - strtotime($todayManila)) / 86400);
                        }
                        $urgencyThreshold = (isset($batch['warning_lead_time_days']) && $batch['warning_lead_time_days'] !== null && $batch['warning_lead_time_days'] !== '')
                            ? (int)$batch['warning_lead_time_days']
                            : 7;
                        $isUrgent = ($daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= $urgencyThreshold);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($batch['product_name']) ?></strong><small><?= htmlspecialchars($batch['sku']) ?></small></td>
                        <td><?= htmlspecialchars($batch['batch_number'] ?? '-') ?></td>
                        <td><?= number_format((int)$batch['remaining_quantity']) ?></td>
                        <td><span class="expiry-status <?= $isUrgent ? 'expiry-status--urgent' : '' ?>"><?= htmlspecialchars($batch['expiration_date'] ?? 'No expiration date') ?></span><?php if ($daysRemaining !== null): ?><small><?= $daysRemaining < 0 ? abs($daysRemaining) . ' days overdue' : $daysRemaining . ' days remaining' ?></small><?php endif; ?></td>
                        <td><?= htmlspecialchars($batch['supplier'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$fefo_recommendations): ?>
                    <tr><td class="overview-empty" colspan="5"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>No FEFO recommendations</strong></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <nav class="overview-tab-pagination" id="fefo-pagination" aria-label="FEFO recommendation pagination" hidden>
                <span id="fefo-page-summary">Showing 0 recommendations</span>
                <div>
                    <button type="button" class="btn btn-quiet" id="fefo-page-previous"><i class="bi bi-chevron-left" aria-hidden="true"></i>Previous</button>
                    <span id="fefo-page-status" aria-live="polite">Page 1 of 1</span>
                    <button type="button" class="btn btn-quiet" id="fefo-page-next">Next<i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </div>
            </nav>
        </section>
        </div>
        </div>

    </main>
</div>
<?php include __DIR__ . '/../modals/product_overview/lowOfStack.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/outOfStack.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/expringSoon.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/expiredStock.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/product.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/viewBatches.php'; ?>
<script>
window.addEventListener('pagehide', () => document.documentElement.classList.remove('overview-fefo-active'));

const inventoryChartData = <?= $chartDataJson ?>;

function getChartThemeColors() {
    const isDark = document.documentElement.dataset.theme === 'dark';
    return {
        textColor: isDark ? '#cbd5e1' : '#475569',
        mutedColor: isDark ? '#94a3b8' : '#64748b',
        gridColor: isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)',
        borderBg: isDark ? '#1e293b' : '#ffffff',
    };
}

let statusChartInstance = null;
let categoryChartInstance = null;
let forecastChartInstance = null;
let overviewNeedsRefresh = false;
function refreshInventoryOnReturn() {
    if (!overviewNeedsRefresh || document.visibilityState !== 'visible') return;
    overviewNeedsRefresh = false;
    if (window.RetailMindNavigation) {
        window.RetailMindNavigation.navigate(location.href, {history: 'none'});
    } else {
        location.reload();
    }
}
window.addEventListener('blur', () => { overviewNeedsRefresh = true; });
window.addEventListener('focus', refreshInventoryOnReturn);
document.addEventListener('visibilitychange', () => {
    if (document.hidden) overviewNeedsRefresh = true;
    else refreshInventoryOnReturn();
});
window.addEventListener('pageshow', event => {
    if (event.persisted) {
        overviewNeedsRefresh = true;
        refreshInventoryOnReturn();
    }
});

function initInventoryCharts() {
    if (typeof Chart === 'undefined') {
        const fallbacks = document.querySelectorAll('.overview-chart-body');
        fallbacks.forEach(function (body) {
            if (!body.querySelector('.overview-chart-fallback')) {
                body.innerHTML = '<div class="overview-chart-fallback"><i class="bi bi-bar-chart"></i> Visual chart available when online.</div>';
            }
        });
        return;
    }

    const colors = getChartThemeColors();
    if (forecastChartInstance) forecastChartInstance.destroy();
    forecastChartInstance = null;
    const forecastCtx = document.getElementById('overviewForecastChart');
    if (forecastCtx) {
        forecastChartInstance = new Chart(forecastCtx, {
            type: 'bar',
            data: {
                labels: inventoryChartData.forecast.labels,
                datasets: [
                    { label: 'Current stock', data: inventoryChartData.forecast.stock, backgroundColor: '#16a34a' },
                    { label: '7-day demand', data: inventoryChartData.forecast.demand7, backgroundColor: '#60a5fa' },
                    { label: '30-day demand', data: inventoryChartData.forecast.demand30, backgroundColor: '#7c3aed' }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: colors.textColor } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: colors.mutedColor } },
                    y: { beginAtZero: true, title: { display: true, text: 'Units', color: colors.mutedColor }, grid: { color: colors.gridColor }, ticks: { color: colors.mutedColor, precision: 0 } }
                }
            }
        });
    }
    const statusCtx = document.getElementById('overviewStatusChart');
    const categoryCtx = document.getElementById('overviewCategoryChart');

    if (statusChartInstance) {
        statusChartInstance.destroy();
        statusChartInstance = null;
    }
    if (categoryChartInstance) {
        categoryChartInstance.destroy();
        categoryChartInstance = null;
    }

    if (statusCtx) {
        statusChartInstance = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: inventoryChartData.status.labels,
                datasets: [{
                    data: inventoryChartData.status.values,
                    backgroundColor: [
                        '#10b981',
                        '#f59e0b',
                        '#ef4444',
                        '#f97316',
                        '#991b1b',
                    ],
                    borderWidth: 2,
                    borderColor: colors.borderBg,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: colors.textColor,
                            font: { size: 11, family: 'system-ui, -apple-system, sans-serif' },
                            padding: 10,
                            boxWidth: 12,
                            boxHeight: 12,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const val = context.raw || 0;
                                const pct = total > 0 ? Math.round((val / total) * 100) : 0;
                                return ' ' + context.label + ': ' + val + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                onClick: function (event, elements) {
                    if (!elements || !elements.length) return;
                    const index = elements[0].index;
                    const targetKey = inventoryChartData.status.keys[index];
                    if (targetKey) {
                        const metricBtn = document.querySelector('.overview-metric[data-overview-view="' + targetKey + '"]');
                        if (metricBtn) metricBtn.click();
                    }
                }
            }
        });
    }

    if (categoryCtx) {
        categoryChartInstance = new Chart(categoryCtx, {
            type: 'bar',
            data: {
                labels: inventoryChartData.categories.labels,
                datasets: [{
                    label: 'Units in Stock',
                    data: inventoryChartData.categories.values,
                    backgroundColor: 'rgba(37, 99, 235, 0.85)',
                    hoverBackgroundColor: '#2563eb',
                    borderRadius: 4,
                    borderSkipped: false,
                    maxBarThickness: 32
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.parsed.y + ' units in stock';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: colors.mutedColor,
                            font: { size: 10.5 },
                            maxRotation: 25,
                            minRotation: 0
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: colors.gridColor
                        },
                        ticks: {
                            color: colors.mutedColor,
                            font: { size: 10.5 },
                            precision: 0
                        }
                    }
                }
            }
        });
    }
}

window.addEventListener('retailmind:themechange', function () {
    if (forecastChartInstance) {
        const colors = getChartThemeColors();
        forecastChartInstance.options.plugins.legend.labels.color = colors.textColor;
        forecastChartInstance.options.scales.x.ticks.color = colors.mutedColor;
        forecastChartInstance.options.scales.y.ticks.color = colors.mutedColor;
        forecastChartInstance.options.scales.y.title.color = colors.mutedColor;
        forecastChartInstance.options.scales.y.grid.color = colors.gridColor;
        forecastChartInstance.update();
    }
    if (!statusChartInstance && !categoryChartInstance) return;
    const colors = getChartThemeColors();
    if (statusChartInstance) {
        statusChartInstance.options.plugins.legend.labels.color = colors.textColor;
        statusChartInstance.data.datasets[0].borderColor = colors.borderBg;
        statusChartInstance.update();
    }
    if (categoryChartInstance) {
        categoryChartInstance.options.scales.x.ticks.color = colors.mutedColor;
        categoryChartInstance.options.scales.y.ticks.color = colors.mutedColor;
        categoryChartInstance.options.scales.y.grid.color = colors.gridColor;
        categoryChartInstance.update();
    }
});

if (document.readyState !== 'loading') {
    initInventoryCharts();
}

document.addEventListener('DOMContentLoaded', function () {
    initInventoryCharts();
    const overviewTabs = Array.from(document.querySelectorAll('[data-overview-tab]'));
    const overviewTabPanels = Array.from(document.querySelectorAll('[data-overview-tab-panel]'));

    function selectOverviewTab(tab, focusTab) {
        const selectedTab = tab.dataset.overviewTab;
        document.documentElement.classList.toggle('overview-fefo-active', selectedTab === 'fefo');
        overviewTabs.forEach(function (button) {
            const isActive = button === tab;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.tabIndex = isActive ? 0 : -1;
        });
        overviewTabPanels.forEach(function (panel) {
            const isActive = panel.dataset.overviewTabPanel === selectedTab;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
        if (focusTab) tab.focus();
    }

    overviewTabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            selectOverviewTab(tab, false);
        });
        tab.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
            event.preventDefault();
            const direction = event.key === 'ArrowRight' ? 1 : -1;
            const nextIndex = (index + direction + overviewTabs.length) % overviewTabs.length;
            selectOverviewTab(overviewTabs[nextIndex], true);
        });
    });

    const movementRows = Array.from(document.querySelectorAll('#overview-panel-movements tbody tr')).filter(function (row) {
        return !row.querySelector('.overview-empty');
    });
    const movementPagination = document.getElementById('movement-pagination');
    const movementPageSummary = document.getElementById('movement-page-summary');
    const movementPageStatus = document.getElementById('movement-page-status');
    const movementPreviousButton = document.getElementById('movement-page-previous');
    const movementNextButton = document.getElementById('movement-page-next');
    const movementPageSize = 5;
    let movementPage = 1;

    function renderMovementPage() {
        const pageCount = Math.max(1, Math.ceil(movementRows.length / movementPageSize));
        const firstRow = (movementPage - 1) * movementPageSize;
        movementRows.forEach(function (row, index) {
            row.hidden = index < firstRow || index >= firstRow + movementPageSize;
        });
        movementPagination.hidden = movementRows.length <= movementPageSize;
        movementPageSummary.textContent = 'Showing ' + (firstRow + 1) + '-' + Math.min(firstRow + movementPageSize, movementRows.length) + ' of ' + movementRows.length;
        movementPageStatus.textContent = 'Page ' + movementPage + ' of ' + pageCount;
        movementPreviousButton.disabled = movementPage === 1;
        movementNextButton.disabled = movementPage === pageCount;
    }

    movementPreviousButton.addEventListener('click', function () {
        if (movementPage <= 1) return;
        movementPage -= 1;
        renderMovementPage();
    });

    movementNextButton.addEventListener('click', function () {
        const pageCount = Math.max(1, Math.ceil(movementRows.length / movementPageSize));
        if (movementPage >= pageCount) return;
        movementPage += 1;
        renderMovementPage();
    });

    renderMovementPage();

    const fefoRows = Array.from(document.querySelectorAll('#overview-panel-fefo tbody tr')).filter(function (row) {
        return !row.querySelector('.overview-empty');
    });
    const fefoPagination = document.getElementById('fefo-pagination');
    const fefoPageSummary = document.getElementById('fefo-page-summary');
    const fefoPageStatus = document.getElementById('fefo-page-status');
    const fefoPreviousButton = document.getElementById('fefo-page-previous');
    const fefoNextButton = document.getElementById('fefo-page-next');
    const fefoPageSize = 5;
    let fefoPage = 1;

    function renderFefoPage() {
        const pageCount = Math.max(1, Math.ceil(fefoRows.length / fefoPageSize));
        const firstRow = (fefoPage - 1) * fefoPageSize;
        fefoRows.forEach(function (row, index) {
            row.hidden = index < firstRow || index >= firstRow + fefoPageSize;
        });
        fefoPagination.hidden = fefoRows.length <= fefoPageSize;
        fefoPageSummary.textContent = 'Showing ' + (firstRow + 1) + '-' + Math.min(firstRow + fefoPageSize, fefoRows.length) + ' of ' + fefoRows.length;
        fefoPageStatus.textContent = 'Page ' + fefoPage + ' of ' + pageCount;
        fefoPreviousButton.disabled = fefoPage === 1;
        fefoNextButton.disabled = fefoPage === pageCount;
    }

    fefoPreviousButton.addEventListener('click', function () {
        if (fefoPage <= 1) return;
        fefoPage -= 1;
        renderFefoPage();
    });

    fefoNextButton.addEventListener('click', function () {
        const pageCount = Math.max(1, Math.ceil(fefoRows.length / fefoPageSize));
        if (fefoPage >= pageCount) return;
        fefoPage += 1;
        renderFefoPage();
    });

    renderFefoPage();

    if (!window.RetailMindUI) return;

    const pageSize = 10;
    const defaultView = document.getElementById('overview-default-view');
    const productView = document.getElementById('overview-product-view');
    const productEmpty = document.getElementById('overview-product-empty');
    const productRows = Array.from(document.querySelectorAll('[data-overview-product-row]'));
    const productSearch = document.getElementById('overview-product-search');
    const categoryFilter = document.getElementById('overview-category-filter');
    const selectAll = document.getElementById('overview-select-all');
    const productPagination = document.getElementById('overview-product-pagination');
    const pageSummary = document.getElementById('overview-page-summary');
    const pageStatus = document.getElementById('overview-page-status');
    const previousPageButton = document.getElementById('overview-page-previous');
    const nextPageButton = document.getElementById('overview-page-next');
    const productPageSize = 10;
    let activeProductView = 'all';
    let productPage = 1;
    function rowMatchesView(row, view) {
        if (view === 'all') return true;
        if (view === 'in-stock') return row.dataset.stockState !== 'out';
        if (view === 'out') return row.dataset.stockState === 'out';
        if (view === 'low') return row.dataset.low === '1';
        if (view === 'expiring') return row.dataset.expiring === '1';
        if (view === 'expired') return row.dataset.expired === '1';
        if (view === 'missing-exp') return row.dataset.missingExp === '1';
        return true;
    }

    function rowMatchesFilters(row) {
        return rowMatchesView(row, activeProductView)
            && (!categoryFilter.value || row.dataset.category === categoryFilter.value)
            && row.dataset.search.toLowerCase().includes(productSearch.value.trim().toLowerCase());
    }

    function showProductView(view) {
        activeProductView = view;
        productPage = 1;
        renderProductPage();
        const selectedMetric = document.querySelector('[data-overview-view="' + view + '"]');
        const productsTab = document.getElementById('overview-products-tab');
        productsTab.textContent = selectedMetric
            ? selectedMetric.querySelector('.overview-metric-copy span').textContent.trim()
            : 'Products';
        document.getElementById('overview-filter-select').value = view;
        document.querySelectorAll('[data-overview-view]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.overviewView === view);
        });
        document.getElementById('overview-metrics').hidden = true;
        document.getElementById('overview-forecast').hidden = true;
        document.getElementById('overview-charts').hidden = true;
        defaultView.hidden = true;
        productView.hidden = false;
        setOverviewTab('products');
    }

    function setOverviewTab(view) {
        document.querySelectorAll('#overview-product-tabs [data-overview-page-tab]').forEach(function (tab) {
            const active = tab.dataset.overviewPageTab === view;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });
    }

    function showOverviewSummary() {
        productView.hidden = true;
        defaultView.hidden = true;
        document.getElementById('overview-metrics').hidden = false;
        document.getElementById('overview-forecast').hidden = false;
        document.getElementById('overview-charts').hidden = false;
        document.querySelectorAll('[data-overview-view]').forEach(function (button) {
            button.classList.remove('is-active');
        });
        setOverviewTab('summary');
    }

    document.getElementById('overview-summary-tab').addEventListener('click', showOverviewSummary);
    document.getElementById('overview-products-tab').addEventListener('click', function () {
        showProductView(activeProductView);
    });

    document.getElementById('overview-back').addEventListener('click', function () {
        productView.hidden = true;
        defaultView.hidden = false;
        document.getElementById('overview-metrics').hidden = false;
        document.getElementById('overview-forecast').hidden = false;
        document.getElementById('overview-charts').hidden = false;
        document.querySelectorAll('[data-overview-view]').forEach(function (button) {
            button.classList.remove('is-active');
        });
        setOverviewTab('summary');
        document.querySelector('.overview-tab.is-active').focus({preventScroll: true});
    });

    function renderProductPage() {
        const showUndated = activeProductView === 'missing-exp';
        document.querySelectorAll('.overview-col-undated').forEach(function (el) {
            el.style.display = showUndated ? '' : 'none';
        });

        const matchingRows = productRows.filter(rowMatchesFilters);
        const pageCount = Math.max(1, Math.ceil(matchingRows.length / productPageSize));
        productPage = Math.min(productPage, pageCount);
        const firstRow = (productPage - 1) * productPageSize;
        const pageRows = new Set(matchingRows.slice(firstRow, firstRow + productPageSize));
        productRows.forEach(function (row) {
            row.hidden = !pageRows.has(row);
            row.querySelector('.overview-row-select').checked = false;
        });
        productEmpty.hidden = matchingRows.length !== 0;
        selectAll.checked = false;
        pageSummary.textContent = matchingRows.length ? 'Showing ' + (firstRow + 1) + '-' + Math.min(firstRow + productPageSize, matchingRows.length) + ' of ' + matchingRows.length : 'Showing 0 products';
        pageStatus.textContent = 'Page ' + productPage + ' of ' + pageCount;
        previousPageButton.disabled = productPage === 1;
        nextPageButton.disabled = productPage === pageCount;
        productPagination.hidden = matchingRows.length === 0;
    }

    renderProductPage();
    let sortColumn = null;
    let sortDirection = 1;
    const sortButtons = Array.from(document.querySelectorAll('.overview-product-table [data-sort-column]'));
    const productCollator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
    sortButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const column = Number(button.dataset.sortColumn);
            sortDirection = sortColumn === column ? -sortDirection : 1;
            sortColumn = column;
            productRows.sort(function (a, b) {
                const left = a.cells[column].dataset.sortValue ?? a.cells[column].textContent.trim();
                const right = b.cells[column].dataset.sortValue ?? b.cells[column].textContent.trim();
                if (button.dataset.sortType === 'date' && (!left || !right)) {
                    return left === right ? 0 : (left ? -1 : 1);
                }
                return sortDirection * (button.dataset.sortType === 'number' ? Number(left) - Number(right) : productCollator.compare(left, right));
            });
            document.getElementById('overview-product-table-body').append(...productRows);
            sortButtons.forEach(function (sortButton) {
                const active = sortButton === button;
                sortButton.closest('th').setAttribute('aria-sort', active ? (sortDirection === 1 ? 'ascending' : 'descending') : 'none');
                sortButton.querySelector('i').className = active ? (sortDirection === 1 ? 'bi bi-arrow-up' : 'bi bi-arrow-down') : 'bi bi-arrow-down-up';
            });
            productPage = 1;
            renderProductPage();
        });
    });
    document.querySelectorAll('[data-overview-view]').forEach(function (button) {
        button.addEventListener('click', function () {
            showProductView(button.dataset.overviewView);
        });
    });

    productSearch.addEventListener('input', function () {
        productPage = 1;
        renderProductPage();
    });
    categoryFilter.addEventListener('change', function () {
        productPage = 1;
        renderProductPage();
    });
    document.getElementById('overview-filter-select').addEventListener('change', function () {
        showProductView(this.value);
    });

    selectAll.addEventListener('change', function () {
        productRows.filter(function (row) { return !row.hidden; }).forEach(function (row) {
            row.querySelector('.overview-row-select').checked = selectAll.checked;
        });
    });

    previousPageButton.addEventListener('click', function () {
        if (productPage <= 1) return;
        productPage -= 1;
        renderProductPage();
        productView.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    nextPageButton.addEventListener('click', function () {
        const matchingCount = productRows.filter(rowMatchesFilters).length;
        const pageCount = Math.max(1, Math.ceil(matchingCount / productPageSize));
        if (productPage >= pageCount) return;
        productPage += 1;
        renderProductPage();
        productView.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    document.querySelectorAll('.product-overview-modal .table-wrap').forEach(function (tableWrap) {
        const rows = Array.from(tableWrap.querySelectorAll('tbody tr')).filter(function (row) {
            return !row.querySelector('.u-empty-cell');
        });
        if (rows.length <= pageSize) return;

        const pageCount = Math.ceil(rows.length / pageSize);
        let currentPage = 1;
        const pagination = document.createElement('nav');
        pagination.className = 'overview-pagination';
        pagination.setAttribute('aria-label', 'Product list pagination');

        const previousButton = document.createElement('button');
        previousButton.type = 'button';
        previousButton.className = 'btn btn-quiet';
        previousButton.textContent = 'Previous';
        previousButton.addEventListener('click', function () {
            if (currentPage > 1) {
                currentPage -= 1;
                renderPage();
            }
        });

        const pageStatus = document.createElement('span');
        pageStatus.className = 'overview-pagination-status';
        pageStatus.setAttribute('aria-live', 'polite');

        const nextButton = document.createElement('button');
        nextButton.type = 'button';
        nextButton.className = 'btn btn-quiet';
        nextButton.textContent = 'Next';
        nextButton.addEventListener('click', function () {
            if (currentPage < pageCount) {
                currentPage += 1;
                renderPage();
            }
        });

        pagination.append(previousButton, pageStatus, nextButton);
        tableWrap.appendChild(pagination);

        function renderPage() {
            const firstRow = (currentPage - 1) * pageSize;
            rows.forEach(function (row, index) {
                row.hidden = index < firstRow || index >= firstRow + pageSize;
            });
            pageStatus.textContent = 'Page ' + currentPage + ' of ' + pageCount;
            previousButton.disabled = currentPage === 1;
            nextButton.disabled = currentPage === pageCount;
        }

        renderPage();
    });

    document.querySelectorAll('[data-modal-target]').forEach(function (trigger) {
        const modal = document.getElementById(trigger.dataset.modalTarget);
        if (!modal) return;
        trigger.addEventListener('click', function () {
            RetailMindUI.openOverlay(modal);
        });
    });

    function escapeBatchHtml(str) {
        if (str === null || str === undefined) return '';
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    document.querySelectorAll('.btn-view-batches').forEach(function (button) {
        button.addEventListener('click', function () {
            const productId = this.dataset.productId;
            const productName = this.dataset.productName || 'Product';
            const hasUnbatched = this.dataset.unbatched === '1';
            const modal = document.getElementById('view-batches-modal');
            if (!modal) return;

            document.getElementById('view-batches-title').textContent = 'Batches: ' + productName;
            const reconAlert = document.getElementById('view-batches-reconciliation-alert');
            if (reconAlert) {
                reconAlert.style.display = hasUnbatched ? 'block' : 'none';
            }

            const tbody = document.getElementById('view-batches-table-body');
            tbody.innerHTML = '<tr><td class="u-empty-cell" colspan="7"><i class="bi bi-arrow-repeat"></i> Loading batches...</td></tr>';
            RetailMindUI.openOverlay(modal);

            fetch('inventory_overview.php?action=get_batches&product_id=' + encodeURIComponent(productId))
                .then(function (res) { return res.json(); })
                .then(function (batches) {
                    tbody.innerHTML = '';
                    if (!batches || batches.length === 0) {
                        tbody.innerHTML = '<tr><td class="u-empty-cell" colspan="7">No batches recorded for this product.</td></tr>';
                        return;
                    }
                    batches.forEach(function (b) {
                        const tr = document.createElement('tr');
                        const expLabel = b.expiration_date ? escapeBatchHtml(b.expiration_date) : '<span class="text-muted">Not recorded</span>';
                        const daysLabel = b.days_remaining !== null ? (b.days_remaining >= 0 ? (b.days_remaining + ' days') : (Math.abs(b.days_remaining) + ' days ago')) : '—';
                        let actionHtml = '—';
                        if (b.return_to_supplier_url) {
                            actionHtml = '<a href="' + escapeBatchHtml(b.return_to_supplier_url) + '" class="btn btn-sm btn-outline-danger btn-return-supplier"><i class="bi bi-arrow-return-left"></i> Return to Supplier</a>';
                        }

                        let sourceBadge = '<span class="badge">' + escapeBatchHtml(b.source) + '</span>';
                        if (b.pullout_id) {
                            sourceBadge += ' <small class="text-muted d-block">Pullout #' + escapeBatchHtml(String(b.pullout_id)) + '</small>';
                        }

                        tr.innerHTML = '<td><strong>' + escapeBatchHtml(b.batch_number) + '</strong></td>' +
                            '<td>' + sourceBadge + '</td>' +
                            '<td><strong>' + Number(b.remaining_quantity).toLocaleString() + '</strong></td>' +
                            '<td>' + expLabel + '</td>' +
                            '<td>' + daysLabel + '</td>' +
                            '<td><span class="overview-stock-status overview-stock-status--' + (b.status === 'Expired' ? 'expired' : (b.status === 'Active' ? 'available' : 'out')) + '">' + escapeBatchHtml(b.status) + '</span></td>' +
                            '<td>' + actionHtml + '</td>';
                        tbody.appendChild(tr);
                    });
                })
                .catch(function () {
                    tbody.innerHTML = '<tr><td class="u-empty-cell text-danger" colspan="7">Failed to load batches. Please try again.</td></tr>';
                });
        });
    });
});
</script>
</body>
</html>
