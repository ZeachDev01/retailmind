<?php
// components/inventory_management/inventory_overview.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/InventoryService.php';
require_once __DIR__ . '/../../../backend/app/Services/ProductService.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$inventoryService = new InventoryService($pdo);
$productService = new ProductService($pdo);
$products = $productService->getProductsForManagement();
$low_stock = $inventoryService->getLowStockProducts();
$expiring_batches = $inventoryService->getExpiringSoonBatches(30);
$expired_batches = $inventoryService->getExpiredBatches();
$fefo_recommendations = array_slice($inventoryService->getFefoRecommendations(), 0, 10);
$lowStockProductIds = array_fill_keys(array_map('intval', array_column($low_stock, 'product_id')), true);
$expiringProductIds = array_fill_keys(array_map('intval', array_column($expiring_batches, 'product_id')), true);
$expiredProductIds = array_fill_keys(array_map('intval', array_column($expired_batches, 'product_id')), true);
$summary = $inventoryService->getInventorySummary();
$total_products = $summary['total_products'];
$total_units = $summary['current_stock'];
[$scopeSql, $scopeParams] = store_product_scope('p');
$outOfStockStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory i JOIN products p ON p.product_id = i.product_id WHERE i.quantity_on_hand = 0{$scopeSql}");
$outOfStockStmt->execute($scopeParams);
$out_of_stock = $outOfStockStmt->fetchColumn();
$out_of_stock_products = $pdo->prepare(
    "SELECT p.sku, p.product_name, i.quantity_on_hand, p.reorder_level
     FROM products p
     JOIN inventory i ON i.product_id = p.product_id
    WHERE i.quantity_on_hand = 0{$scopeSql}
     ORDER BY p.product_name"
);
$out_of_stock_products->execute($scopeParams);
$out_of_stock_products = $out_of_stock_products->fetchAll();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory Overview</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css')) ?>">
</head>
<body class="inventory-overview-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content overview-main">
        <section class="overview-metrics" aria-label="Inventory summary">
            <button type="button" class="overview-metric" data-overview-view="all" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format((int)$total_products) ?></strong><span>Total products</span></span>
                <i class="bi bi-chevron-right overview-metric-arrow" aria-hidden="true"></i>
            </button>
            <button type="button" class="overview-metric" data-overview-view="in-stock" aria-controls="overview-product-view">
                <span class="overview-metric-icon overview-metric-icon--green" aria-hidden="true"><i class="bi bi-boxes"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format((int)$total_units) ?></strong><span>Units in stock</span></span>
                <i class="bi bi-chevron-right overview-metric-arrow" aria-hidden="true"></i>
            </button>
            <button type="button" class="overview-metric overview-metric--danger" data-overview-view="out" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-x-octagon"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format((int)$out_of_stock) ?></strong><span>Out of stock</span></span>
                <i class="bi bi-chevron-right overview-metric-arrow" aria-hidden="true"></i>
            </button>
            <button type="button" class="overview-metric overview-metric--warning" data-overview-view="low" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format(count($low_stock)) ?></strong><span>Low stock</span></span>
                <i class="bi bi-chevron-right overview-metric-arrow" aria-hidden="true"></i>
            </button>
            <button type="button" class="overview-metric overview-metric--warning" data-overview-view="expiring" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format(count($expiring_batches)) ?></strong><span>Expiring soon</span></span>
                <i class="bi bi-chevron-right overview-metric-arrow" aria-hidden="true"></i>
            </button>
            <button type="button" class="overview-metric overview-metric--danger" data-overview-view="expired" aria-controls="overview-product-view">
                <span class="overview-metric-icon" aria-hidden="true"><i class="bi bi-calendar-x"></i></span>
                <span class="overview-metric-copy"><strong><?= number_format(count($expired_batches)) ?></strong><span>Expired batches</span></span>
                <i class="bi bi-chevron-right overview-metric-arrow" aria-hidden="true"></i>
            </button>
        </section>

        <div id="overview-default-view" class="overview-tab-card">
        <div class="overview-tabs" role="tablist" aria-label="Inventory overview sections">
            <button type="button" class="overview-tab is-active" id="overview-tab-movements" role="tab" aria-selected="true" aria-controls="overview-panel-movements" data-overview-tab="movements">Recent Stock Movements</button>
            <button type="button" class="overview-tab" id="overview-tab-fefo" role="tab" aria-selected="false" aria-controls="overview-panel-fefo" data-overview-tab="fefo" tabindex="-1">FEFO Pick Recommendations</button>
        </div>
        <div class="overview-tab-panels">
        <section class="overview-section overview-tab-panel is-active" id="overview-panel-movements" role="tabpanel" aria-labelledby="overview-tab-movements" data-overview-tab-panel="movements">
            <header class="overview-section-header">
                <div><span class="overview-section-icon" aria-hidden="true"><i class="bi bi-arrow-left-right"></i></span><div><h2 id="movement-heading">Recent Stock Movements</h2><p>Latest inventory changes recorded across this store scope.</p></div></div>
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
                <div><span class="overview-section-icon overview-section-icon--amber" aria-hidden="true"><i class="bi bi-calendar2-week"></i></span><div><h2 id="fefo-heading">FEFO Pick Recommendations</h2><p>Prioritize batches with the nearest expiration dates.</p></div></div>
                <a href="<?= htmlspecialchars(app_url('components/report/stock_receiving.php')) ?>">Open stock receiving <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </header>
            <div class="overview-table-shell">
                <table class="overview-table">
                    <thead><tr><th>Product</th><th>Batch</th><th>Remaining</th><th>Expiration</th><th>Supplier</th></tr></thead>
                    <tbody>
                    <?php foreach ($fefo_recommendations as $batch): ?>
                    <?php
                        $expirationTimestamp = !empty($batch['expiration_date']) ? strtotime((string)$batch['expiration_date']) : false;
                        $daysRemaining = $expirationTimestamp === false ? null : (int)floor(($expirationTimestamp - strtotime('today')) / 86400);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($batch['product_name']) ?></strong><small><?= htmlspecialchars($batch['sku']) ?></small></td>
                        <td><?= htmlspecialchars($batch['batch_number'] ?? '-') ?></td>
                        <td><?= number_format((int)$batch['remaining_quantity']) ?></td>
                        <td><span class="expiry-status <?= $daysRemaining !== null && $daysRemaining <= 7 ? 'expiry-status--urgent' : '' ?>"><?= htmlspecialchars($batch['expiration_date'] ?? 'No expiration date') ?></span><?php if ($daysRemaining !== null): ?><small><?= $daysRemaining < 0 ? abs($daysRemaining) . ' days overdue' : $daysRemaining . ' days remaining' ?></small><?php endif; ?></td>
                        <td><?= htmlspecialchars($batch['supplier'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$fefo_recommendations): ?>
                    <tr><td class="overview-empty" colspan="5"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>No FEFO recommendations</strong><span>No batch stock currently requires picking priority.</span></td></tr>
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

        <section class="overview-product-view" id="overview-product-view" aria-label="Filtered products" hidden>
            <div class="overview-product-table-shell">
                <table class="overview-product-table">
                    <thead><tr><th class="overview-select-cell"><input type="checkbox" id="overview-select-all" aria-label="Select all visible products"></th><th>Product <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th><th>SKU / Barcode <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th><th>Category <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th><th>Selling Price <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th><th>Current Stock <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th><th>Status</th></tr></thead>
                    <tbody id="overview-product-table-body">
                    <?php foreach ($products as $product): ?>
                    <?php
                        $productId = (int)$product['product_id'];
                        $quantity = (int)($product['quantity_on_hand'] ?? 0);
                        $threshold = max((int)($product['reorder_level'] ?? 0), (int)($product['safety_stock'] ?? 0));
                        $stockState = $quantity <= 0 ? 'out' : ($quantity <= $threshold ? 'low' : 'available');
                        $isLowStock = isset($lowStockProductIds[$productId]);
                        $isExpired = isset($expiredProductIds[$productId]);
                        $isExpiring = !$isExpired && isset($expiringProductIds[$productId]);
                        $statusClass = $isExpired ? 'expired' : ($isExpiring ? 'expiring' : $stockState);
                        $statusLabel = $isExpired ? 'Expired' : ($isExpiring ? 'Expiring soon' : ($stockState === 'out' ? 'Out of stock' : ($stockState === 'low' ? 'Low stock' : 'Available')));
                    ?>
                    <tr data-overview-product-row data-stock-state="<?= $stockState ?>" data-low="<?= $isLowStock ? '1' : '0' ?>" data-expiring="<?= $isExpiring ? '1' : '0' ?>" data-expired="<?= $isExpired ? '1' : '0' ?>">
                        <td class="overview-select-cell"><input type="checkbox" class="overview-row-select" aria-label="Select <?= htmlspecialchars($product['product_name']) ?>"></td>
                        <td><div class="overview-product-name"><span class="overview-product-thumb"><i class="bi bi-box-seam" aria-hidden="true"></i></span><span><strong><?= htmlspecialchars($product['product_name']) ?></strong><small><?= htmlspecialchars($product['brand'] ?: 'No brand') ?></small></span></div></td>
                        <td><strong><?= htmlspecialchars($product['sku'] ?? '-') ?></strong><small><?= htmlspecialchars($product['barcode'] ?: 'Not assigned') ?></small></td>
                        <td><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></td>
                        <td>₱<?= number_format((float)($product['unit_price'] ?? 0), 2) ?></td>
                        <td><strong><?= number_format($quantity) ?></strong><small>Reorder at <?= number_format((int)($product['reorder_level'] ?? 0)) ?></small></td>
                        <td><span class="overview-stock-status overview-stock-status--<?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="overview-product-empty" id="overview-product-empty" hidden><i class="bi bi-inbox" aria-hidden="true"></i><strong>No matching products</strong><span>No products belong to this inventory view.</span></div>
            </div>
            <nav class="overview-product-pagination" id="overview-product-pagination" aria-label="Product pagination">
                <span id="overview-page-summary">Showing 0 products</span>
                <div>
                    <button type="button" class="btn btn-quiet" id="overview-page-previous"><i class="bi bi-chevron-left" aria-hidden="true"></i>Previous</button>
                    <span id="overview-page-status" aria-live="polite">Page 1 of 1</span>
                    <button type="button" class="btn btn-quiet" id="overview-page-next">Next<i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </div>
            </nav>
        </section>
    </main>
</div>
<?php include __DIR__ . '/../modals/product_overview/lowOfStack.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/outOfStack.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/expringSoon.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/expiredStock.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/product.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
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
        return true;
    }

    function showProductView(view) {
        activeProductView = view;
        productPage = 1;
        renderProductPage();
        document.querySelectorAll('[data-overview-view]').forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.overviewView === view);
        });
        defaultView.hidden = true;
        productView.hidden = false;
        productView.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function renderProductPage() {
        const matchingRows = productRows.filter(function (row) {
            return rowMatchesView(row, activeProductView);
        });
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

    document.querySelectorAll('[data-overview-view]').forEach(function (button) {
        button.addEventListener('click', function () {
            showProductView(button.dataset.overviewView);
        });
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
        const matchingCount = productRows.filter(function (row) { return rowMatchesView(row, activeProductView); }).length;
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
        const open = function () { RetailMindUI.openOverlay(modal); };
        const close = function () { RetailMindUI.closeOverlay(modal); };
        trigger.addEventListener('click', open);
        modal.querySelectorAll('[data-close-modal]').forEach(function (button) {
            button.addEventListener('click', close);
        });
        modal.addEventListener('click', function (event) {
            if (event.target === modal) close();
        });
    });
});
</script>
</body>
</html>
