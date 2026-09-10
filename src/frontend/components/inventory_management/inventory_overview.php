<?php
// components/inventory_management/inventory_overview.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/InventoryService.php';
require_once __DIR__ . '/../../../backend/app/Services/ProductService.php';
require_role(['admin', 'inventory_manager']);

$inventoryService = new InventoryService($pdo);
$productService = new ProductService($pdo);
$products = $productService->getProductsForManagement();
$low_stock = get_low_stock_products($pdo);
$expiring_batches = $inventoryService->getExpiringSoonBatches();
$expired_batches = $inventoryService->getExpiredBatches();
$fefo_recommendations = array_slice($inventoryService->getFefoRecommendations(), 0, 10);
$total_products = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$total_units = $pdo->query("SELECT COALESCE(SUM(quantity_on_hand),0) FROM inventory")->fetchColumn();
$out_of_stock = $pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity_on_hand = 0")->fetchColumn();
$out_of_stock_products = $pdo->query(
    "SELECT p.sku, p.product_name, i.quantity_on_hand, p.reorder_level
     FROM products p
     JOIN inventory i ON i.product_id = p.product_id
     WHERE i.quantity_on_hand = 0
     ORDER BY p.product_name"
)->fetchAll();
$pending_counts = $pdo->query("SELECT COUNT(*) FROM inventory_counts WHERE status = 'pending'")->fetchColumn();

$recent_movements = $pdo->query(
    "SELECT sm.movement_id, sm.change_qty, sm.reason, sm.moved_at, p.sku, p.product_name, u.full_name AS moved_by_name
     FROM stock_movements sm
     JOIN products p ON sm.product_id = p.product_id
     LEFT JOIN users u ON sm.moved_by = u.user_id
     ORDER BY sm.moved_at DESC
     LIMIT 10"
)->fetchAll();
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
