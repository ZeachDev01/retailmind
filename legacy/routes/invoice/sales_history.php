<?php
// invoice/sales_history.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'super_admin', 'inventory_manager', 'cashier']);

$is_cashier = current_role() === 'cashier';
$summarySql = $is_cashier
    ? "SELECT COUNT(DISTINCT s.sale_id) AS sale_count,
              COALESCE(SUM(si.quantity), 0) AS quantity_sold,
              COALESCE(SUM(si.subtotal), 0) AS total_amount
       FROM sales s
       JOIN sale_items si ON s.sale_id = si.sale_id
       WHERE s.cashier_id = ?"
    : "SELECT COUNT(DISTINCT s.sale_id) AS sale_count,
              COALESCE(SUM(si.quantity), 0) AS quantity_sold,
              COALESCE(SUM(si.subtotal), 0) AS total_amount
       FROM sales s
       JOIN sale_items si ON s.sale_id = si.sale_id";

$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($is_cashier ? [$_SESSION['user_id']] : []);
$summary = $summaryStmt->fetch();

$historySql = $is_cashier
    ? "SELECT s.sale_id, s.sale_date, si.quantity, si.unit_price, si.subtotal,
              p.sku, p.product_name
       FROM sales s
       JOIN sale_items si ON s.sale_id = si.sale_id
       JOIN products p ON si.product_id = p.product_id
       WHERE s.cashier_id = ?
       ORDER BY s.sale_date DESC, s.sale_id DESC, si.sale_item_id DESC
       LIMIT 200"
    : "SELECT s.sale_id, s.sale_date, si.quantity, si.unit_price, si.subtotal,
              p.sku, p.product_name
       FROM sales s
       JOIN sale_items si ON s.sale_id = si.sale_id
       JOIN products p ON si.product_id = p.product_id
       ORDER BY s.sale_date DESC, s.sale_id DESC, si.sale_item_id DESC
       LIMIT 200";

$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($is_cashier ? [$_SESSION['user_id']] : []);
$sales_history = $historyStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales History</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../../../modules/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>Sales History</h1>
            <span class="badge-role"><?= htmlspecialchars(ucfirst((string)current_role())) ?></span>
        </div>

        <div class="card-grid">
            <div class="stat-card"><div class="value"><?= (int)$summary['sale_count'] ?></div><div class="label">Sales</div></div>
            <div class="stat-card"><div class="value"><?= (int)$summary['quantity_sold'] ?></div><div class="label">Quantity Sold</div></div>
            <div class="stat-card"><div class="value">&#8369;<?= number_format((float)$summary['total_amount'], 2) ?></div><div class="label">Total Amount</div></div>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
            <h3 style="margin:0;">Latest Sales</h3>
            <a class="btn" href="<?= htmlspecialchars(app_url('invoice/receipt.php')) ?>">Latest Receipt</a>
        </div>

        <div style="overflow-x:auto;">
            <table>
                <tr>
                    <th>Sale ID</th>
                    <th>Product</th>
                    <th>Quantity Sold</th>
                    <th>Selling Price</th>
                    <th>Total Amount</th>
                    <th>Date</th>
                </tr>
                <?php foreach ($sales_history as $sale): ?>
                <tr>
                    <td><a href="<?= htmlspecialchars(app_url('invoice/receipt.php?sale_id=' . $sale['sale_id'])) ?>">#<?= (int)$sale['sale_id'] ?></a></td>
                    <td><?= htmlspecialchars($sale['sku'] . ' - ' . $sale['product_name']) ?></td>
                    <td><?= (int)$sale['quantity'] ?></td>
                    <td>&#8369;<?= number_format((float)$sale['unit_price'], 2) ?></td>
                    <td>&#8369;<?= number_format((float)$sale['subtotal'], 2) ?></td>
                    <td><?= htmlspecialchars($sale['sale_date']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$sales_history): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--muted);">No sales history recorded yet.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
</body>
</html>
