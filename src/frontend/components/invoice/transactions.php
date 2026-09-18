<?php
// components/invoice/transactions.php - Inventory Transaction History
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::VIEW_STORE_REPORTS);
$storeId = store_scope_id($pdo);

// Get transaction history
$transactionStatement = $pdo->prepare(
    "SELECT sm.movement_id, sm.change_qty, sm.reason, sm.moved_at, p.sku, p.barcode, p.product_name, u.full_name AS moved_by_name, i.quantity_on_hand
     FROM stock_movements sm
     JOIN products p ON sm.product_id = p.product_id
     JOIN inventory i ON p.product_id = i.product_id
     LEFT JOIN users u ON sm.moved_by = u.user_id
     WHERE p.branch_id = ?
     ORDER BY sm.moved_at DESC
     LIMIT 100"
);
$transactionStatement->execute([$storeId]);
$transactions = $transactionStatement->fetchAll();

$role = current_role();
$back_url = $role === 'inventory_manager'
    ? app_url('components/inventory_management/inventory_overview.php')
    : app_url('components/administrator/dashboard.php');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css')) ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
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
                                            <td><?= htmlspecialchars(format_display_datetime($t['moved_at'])) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($t['sku'] ?? $t['barcode']) ?></strong>
                                                <br><small class="u-text-muted"><?= htmlspecialchars($t['product_name']) ?></small>
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
                                        <td class="u-empty-cell-padded" colspan="6">No transactions recorded yet.</td>
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
