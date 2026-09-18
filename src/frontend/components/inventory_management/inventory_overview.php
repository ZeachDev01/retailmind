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
$pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_counts ic JOIN products p ON p.product_id = ic.product_id WHERE ic.status = 'pending'{$scopeSql}");
$pendingStmt->execute($scopeParams);
$pending_counts = $pendingStmt->fetchColumn();

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
</head>
<body class="inventory-overview-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Inventory Overview</h1>
            <span class="badge-role">Inventory Manager: <?= htmlspecialchars($_SESSION['full_name']) ?></span>
        </div>

        <div class="card-grid">
            <div class="stat-card with-icon" role="button" tabindex="0" data-modal-target="product-overview-modal" aria-controls="product-overview-modal" aria-haspopup="dialog">
                <div class="stat-icon" aria-hidden="true"><i class="bi bi-box-seam-fill"></i></div>
                <div class="value"><?= $total_products ?></div>
                <div class="label">Total Products</div>
            </div>
            <div class="stat-card with-icon">
                <div class="stat-icon" aria-hidden="true"><i class="bi bi-boxes"></i></div>
                <div class="value"><?= $total_units ?></div>
                <div class="label">Units in Stock</div>
            </div>
            <div class="stat-card with-icon" role="button" tabindex="0" data-modal-target="out-of-stock-modal" aria-controls="out-of-stock-modal" aria-haspopup="dialog">
                <div class="stat-icon" aria-hidden="true"><i class="bi bi-x-circle-fill"></i></div>
                <div class="value"><?= $out_of_stock ?></div>
                <div class="label">Out of Stock</div>
            </div>
            <div class="stat-card with-icon" role="button" tabindex="0" data-modal-target="low-stock-modal" aria-controls="low-stock-modal" aria-haspopup="dialog">
                <div class="stat-icon" aria-hidden="true"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div class="value"><?= count($low_stock) ?></div>
                <div class="label">Low Stock Items</div>
            </div>
            <div class="stat-card with-icon" role="button" tabindex="0" data-modal-target="expiring-soon-modal" aria-controls="expiring-soon-modal" aria-haspopup="dialog">
                <div class="stat-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></div>
                <div class="value"><?= count($expiring_batches) ?></div>
                <div class="label">Expiring Soon</div>
            </div>
            <div class="stat-card with-icon" role="button" tabindex="0" data-modal-target="expired-stock-modal" aria-controls="expired-stock-modal" aria-haspopup="dialog">
                <div class="stat-icon" aria-hidden="true"><i class="bi bi-calendar-x-fill"></i></div>
                <div class="value"><?= count($expired_batches) ?></div>
                <div class="label">Expired Batches</div>
            </div>
            <div class="stat-card with-icon">
                <div class="stat-icon" aria-hidden="true"><i class="bi bi-clipboard-check-fill"></i></div>
                <div class="value"><?= (int)$pending_counts ?></div>
                <div class="label">Pending Counts</div>
            </div>
        </div>

        <div class="u-grid-two-spaced">
            <div>
                <h3>Recent Stock Movements</h3>
                <table>
                    <tr><th>Product</th><th>Change</th><th>Reason</th><th>By</th></tr>
                    <?php foreach ($recent_movements as $movement): ?>
                    <tr>
                        <td><?= htmlspecialchars($movement['sku'] . ' - ' . $movement['product_name']) ?></td>
                        <td><?= $movement['change_qty'] ?></td>
                        <td><?= htmlspecialchars($movement['reason']) ?></td>
                        <td><?= htmlspecialchars($movement['moved_by_name'] ?? 'System') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent_movements): ?>
                    <tr><td class="u-empty-cell" colspan="4">No stock movements recorded yet.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="u-mt-15">
            <h3>FEFO Pick Recommendations</h3>
            <table>
                <tr><th>Product</th><th>Batch</th><th>Remaining</th><th>Expiration</th><th>Supplier</th></tr>
                <?php foreach ($fefo_recommendations as $batch): ?>
                <tr>
                    <td><?= htmlspecialchars($batch['sku'] . ' - ' . $batch['product_name']) ?></td>
                    <td><?= htmlspecialchars($batch['batch_number'] ?? '-') ?></td>
                    <td><?= (int)$batch['remaining_quantity'] ?></td>
                    <td><?= htmlspecialchars($batch['expiration_date'] ?? 'No expiration date') ?></td>
                    <td><?= htmlspecialchars($batch['supplier'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$fefo_recommendations): ?>
                <tr><td class="u-empty-cell" colspan="5">No batch stock available for FEFO recommendations.</td></tr>
                <?php endif; ?>
            </table>
        </div>

        <div style="margin-top:1.5rem;display:flex;gap:1rem;flex-wrap:wrap;">
            <a class="btn" href="<?= htmlspecialchars(app_url('components/inventory_management/products.php')) ?>">Manage Products</a>
            <a class="btn" href="<?= htmlspecialchars(app_url('components/inventory_management/inventory_counts.php')) ?>">Inventory Counts</a>
            <a class="btn" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">View ML Predictions</a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../modals/product_overview/lowOfStack.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/outOfStack.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/expringSoon.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/expiredStock.php'; ?>
<?php include __DIR__ . '/../modals/product_overview/product.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.RetailMindUI) return;

    const pageSize = 10;

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
        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
            }
        });
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
