<?php
// manager/transactions.php - Inventory Transaction History
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'inventory_manager']);

// Get transaction history
$transactions = $pdo->query(
    "SELECT sm.movement_id, sm.change_qty, sm.reason, sm.moved_at, p.sku, p.barcode, p.product_name, u.full_name AS moved_by_name, i.quantity_on_hand
     FROM stock_movements sm
     JOIN products p ON sm.product_id = p.product_id
     JOIN inventory i ON p.product_id = i.product_id
     LEFT JOIN users u ON sm.moved_by = u.user_id
     ORDER BY sm.moved_at DESC
     LIMIT 100"
)->fetchAll();

$role = current_role();
$back_url = $role === 'inventory_manager' ? app_url('manager/dashboard.php') : app_url('admin/dashboard.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transaction History</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<style>
    .transactions-container { max-width: 1200px; margin: 0 auto; padding: 2rem 1rem; }
    .transaction-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 1.5rem; }
    .trans-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
    .trans-header h1 { margin: 0; }
    .trans-table { width: 100%; border-collapse: collapse; }
    .trans-table th, .trans-table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border); }
    .trans-table th { background: var(--table-header-bg, rgba(51, 65, 85, 0.5)); font-weight: 600; }
    .trans-table tr:hover { background: rgba(59, 130, 246, 0.05); }
    .badge-reason { display: inline-block; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
    .badge-purchase { background: #d1fae5; color: #047857; }
    .badge-adjustment { background: #fef3c7; color: #b45309; }
    .badge-return { background: #fecaca; color: #991b1b; }
    .qty-positive { color: #10b981; font-weight: 600; }
    .qty-negative { color: #f87171; font-weight: 600; }
    .overflow-x-auto { overflow-x: auto; }
</style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="transactions-container">
            <div class="trans-header">
                <h1>📋 Transaction History</h1>
                <a href="<?= htmlspecialchars($back_url) ?>" class="btn" style="background:#6b7280;">← Back</a>
            </div>

            <div class="transaction-card">
                <div class="overflow-x-auto">
                    <table class="trans-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Product</th>
                                <th>Change</th>
                                <th>Current Stock</th>
                                <th>Reason</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions): ?>
                                <?php foreach ($transactions as $t): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($t['moved_at']))) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($t['sku'] ?? $t['barcode']) ?></strong>
                                            <br><small style="color:var(--muted);"><?= htmlspecialchars($t['product_name']) ?></small>
                                        </td>
                                        <td>
                                            <span class="<?= $t['change_qty'] >= 0 ? 'qty-positive' : 'qty-negative' ?>">
                                                <?= $t['change_qty'] >= 0 ? '+' . $t['change_qty'] : $t['change_qty'] ?>
                                            </span>
                                        </td>
                                        <td><?= (int)$t['quantity_on_hand'] ?></td>
                                        <td>
                                            <span class="badge-reason badge-<?= htmlspecialchars($t['reason']) ?>">
                                                <?= htmlspecialchars(ucfirst($t['reason'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($t['moved_by_name'] ?? 'System') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:2rem;color:var(--muted);">No transactions recorded yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
