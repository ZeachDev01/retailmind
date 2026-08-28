<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$message = '';
$error = '';

function planner_round_quantity(int $quantity, int $minimumOrder, int $packageSize): int
{
    $quantity = max(0, $quantity);
    if ($quantity === 0) {
        return 0;
    }
    $quantity = max($quantity, max(1, $minimumOrder));
    $packageSize = max(1, $packageSize);
    return (int)(ceil($quantity / $packageSize) * $packageSize);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $productIds = array_values(array_unique(array_filter(array_map('intval', $_POST['product_ids'] ?? []))));
    if (!$productIds) {
        $error = 'Select at least one recommended product.';
    } else {
        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT p.product_id, p.product_name, p.reorder_level, p.safety_stock,
                        p.minimum_order_quantity, p.units_per_package,
                        COALESCE(i.quantity_on_hand, 0) quantity_on_hand,
                        sp.prediction_id, sp.suggested_reorder_qty
                 FROM products p
                 LEFT JOIN inventory i ON i.product_id = p.product_id
                 LEFT JOIN stock_predictions sp ON sp.prediction_id = (
                    SELECT MAX(sp2.prediction_id) FROM stock_predictions sp2 WHERE sp2.product_id = p.product_id
                 )
                 WHERE p.product_id IN ({$placeholders}) AND p.status = 'active'
                 FOR UPDATE"
            );
            $stmt->execute($productIds);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $existingStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM replenishment_requests
                 WHERE product_id = ? AND status IN ('pending','approved','partially_received')"
            );
            $insert = $pdo->prepare(
                "INSERT INTO replenishment_requests
                    (product_id, request_qty, requested_by, status, source, forecast_prediction_id, original_suggested_qty, notes)
                 VALUES (?, ?, ?, 'pending', ?, ?, ?, ?)"
            );

            $created = 0;
            foreach ($products as $product) {
                $existingStmt->execute([(int)$product['product_id']]);
                if ((int)$existingStmt->fetchColumn() > 0) {
                    continue;
                }
                $baseQuantity = (int)($product['suggested_reorder_qty'] ?? 0);
                if ($baseQuantity <= 0 && (int)$product['quantity_on_hand'] <= (int)$product['reorder_level']) {
                    $baseQuantity = (int)$product['reorder_level'] + (int)$product['safety_stock'] - (int)$product['quantity_on_hand'];
                }
                $quantity = planner_round_quantity(
                    $baseQuantity,
                    (int)$product['minimum_order_quantity'],
                    (int)$product['units_per_package']
                );
                if ($quantity <= 0) {
                    continue;
                }
                $predictionId = $product['prediction_id'] !== null ? (int)$product['prediction_id'] : null;
                $source = $predictionId ? 'ml_forecast' : 'manual';
                $insert->execute([
                    (int)$product['product_id'],
                    $quantity,
                    (int)$_SESSION['user_id'],
                    $source,
                    $predictionId,
                    $predictionId ? $baseQuantity : null,
                    'Created from supplier-based reorder planning.',
                ]);
                $created++;
            }
            $pdo->commit();
            log_activity($pdo, (int)$_SESSION['user_id'], 'Created planned replenishment requests', 'Reorder Planning', null, null, ['created_count' => $created]);
            $message = $created > 0
                ? "Created {$created} replenishment request(s). They are ready for approval."
                : 'No new requests were created because selected products already have open requests or no longer require replenishment.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$rows = $pdo->query(
    "SELECT p.product_id, p.sku, p.product_name, p.reorder_level, p.safety_stock,
            p.minimum_order_quantity, p.units_per_package, p.preferred_supplier,
            COALESCE(i.quantity_on_hand, 0) quantity_on_hand,
            sp.prediction_id, sp.suggested_reorder_qty, sp.forecasted_demand_during_lead_time,
            sp.incoming_stock_used, sp.confidence_score, sp.generated_at,
            s.supplier_id, s.supplier_name,
            COALESCE(s.standard_lead_time_days, p.supplier_lead_time_days) lead_time_days,
            COALESCE(s.minimum_order_value, 0) supplier_minimum_value,
            COALESCE(spt.last_unit_cost, p.cost_price) planned_unit_cost,
            EXISTS(
                SELECT 1 FROM replenishment_requests rr
                WHERE rr.product_id = p.product_id AND rr.status IN ('pending','approved','partially_received')
            ) has_open_request
     FROM products p
     LEFT JOIN inventory i ON i.product_id = p.product_id
     LEFT JOIN stock_predictions sp ON sp.prediction_id = (
        SELECT MAX(sp2.prediction_id) FROM stock_predictions sp2 WHERE sp2.product_id = p.product_id
     )
     LEFT JOIN supplier_products spt ON spt.product_id = p.product_id AND spt.is_preferred = 1
     LEFT JOIN suppliers s ON s.supplier_id = spt.supplier_id AND s.status = 'active'
     WHERE p.status = 'active'
       AND (COALESCE(sp.suggested_reorder_qty, 0) > 0 OR COALESCE(i.quantity_on_hand, 0) <= p.reorder_level)
     ORDER BY COALESCE(s.supplier_name, p.preferred_supplier, 'Unassigned'), p.product_name"
)->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
$totalValue = 0.0;
$totalUnits = 0;
foreach ($rows as &$row) {
    $base = (int)($row['suggested_reorder_qty'] ?? 0);
    if ($base <= 0) {
        $base = max(0, (int)$row['reorder_level'] + (int)$row['safety_stock'] - (int)$row['quantity_on_hand']);
    }
    $row['planned_qty'] = planner_round_quantity($base, (int)$row['minimum_order_quantity'], (int)$row['units_per_package']);
    $row['planned_value'] = $row['planned_qty'] * (float)$row['planned_unit_cost'];
    $supplierName = trim((string)($row['supplier_name'] ?? '')) ?: (trim((string)$row['preferred_supplier']) ?: 'Unassigned supplier');
    $groups[$supplierName][] = $row;
    if (!$row['has_open_request']) {
        $totalUnits += $row['planned_qty'];
        $totalValue += $row['planned_value'];
    }
}
unset($row);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reorder Planning</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<style>
.supplier-plan{margin-bottom:1.25rem}.supplier-plan__head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1rem}.planning-meta{display:flex;gap:.5rem;flex-wrap:wrap}.planning-note{font-size:.85rem;color:var(--muted)}@media(max-width:700px){.supplier-plan__head{display:block}}
</style>
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content">
<header class="page-heading">
    <div><h1>Supplier-Based Reorder Planning</h1><p class="page-subtitle">Group forecast and low-stock recommendations by preferred supplier, MOQ, and package size.</p></div>
    <div class="page-heading-actions"><a class="btn btn-quiet" href="<?= htmlspecialchars(app_url('modules/inventory_management/suppliers.php')) ?>">Supplier terms</a><a class="btn" href="<?= htmlspecialchars(app_url('modules/inventory_management/purchase_orders.php')) ?>">Purchase orders</a></div>
</header>
<?php if ($message): ?><div class="message success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card-grid">
    <article class="stat-card"><div class="value"><?= count($rows) ?></div><div class="label">Products to review</div><div class="hint">Forecasted or below reorder level</div></article>
    <article class="stat-card"><div class="value"><?= number_format($totalUnits) ?></div><div class="label">Planned units</div><div class="hint">Excludes products with open requests</div></article>
    <article class="stat-card"><div class="value">₱<?= number_format($totalValue, 2) ?></div><div class="label">Estimated value</div><div class="hint">Based on supplier or product cost</div></article>
</div>

<form method="POST">
<?= csrf_field() ?>
<?php foreach ($groups as $supplierName => $items):
    $supplierValue = array_sum(array_column($items, 'planned_value'));
    $minimumValue = max(array_map(static fn(array $item): float => (float)$item['supplier_minimum_value'], $items));
?>
<section class="dashboard-section supplier-plan">
    <div class="supplier-plan__head">
        <div><h3><?= htmlspecialchars($supplierName) ?></h3><p class="section-description"><?= count($items) ?> product(s) · Estimated ₱<?= number_format($supplierValue, 2) ?><?php if ($minimumValue > 0): ?> · Supplier minimum ₱<?= number_format($minimumValue, 2) ?><?php endif; ?></p></div>
        <div class="planning-meta"><span class="decision-pill <?= $minimumValue > 0 && $supplierValue < $minimumValue ? 'action' : 'ok' ?>"><?= $minimumValue > 0 && $supplierValue < $minimumValue ? 'Below supplier minimum' : 'Order value ready' ?></span></div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>Select</th><th>Product</th><th>Stock</th><th>Forecast / Reorder</th><th>MOQ / Pack</th><th>Planned Qty</th><th>Estimated Cost</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><input type="checkbox" name="product_ids[]" value="<?= (int)$item['product_id'] ?>" <?= $item['has_open_request'] || $item['planned_qty'] <= 0 ? 'disabled' : 'checked' ?>></td>
                <td><strong><?= htmlspecialchars($item['product_name']) ?></strong><br><small><?= htmlspecialchars($item['sku']) ?> · Lead <?= (int)$item['lead_time_days'] ?> day(s)</small></td>
                <td><?= (int)$item['quantity_on_hand'] ?><br><small>Reorder <?= (int)$item['reorder_level'] ?> · Safety <?= (int)$item['safety_stock'] ?></small></td>
                <td><?= $item['suggested_reorder_qty'] !== null ? (int)$item['suggested_reorder_qty'] : 'Low-stock rule' ?><br><small><?= $item['generated_at'] ? 'Forecast ' . htmlspecialchars(date('M d', strtotime($item['generated_at']))) : 'No recent forecast' ?></small></td>
                <td><?= (int)$item['minimum_order_quantity'] ?> / <?= (int)$item['units_per_package'] ?></td>
                <td><strong><?= (int)$item['planned_qty'] ?></strong></td>
                <td>₱<?= number_format((float)$item['planned_value'], 2) ?></td>
                <td><?= $item['has_open_request'] ? '<span class="tag-warning">Open request</span>' : '<span class="tag-success">Ready</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endforeach; ?>
<?php if (!$rows): ?><section class="dashboard-section"><div class="empty-state"><div><i class="bi bi-check-circle"></i><strong>No replenishment recommendations</strong><span>Current stock and forecast suggestions do not require new requests.</span></div></div></section><?php else: ?><div style="display:flex;justify-content:flex-end;gap:.75rem;position:sticky;bottom:1rem"><button class="btn" type="submit">Create selected replenishment requests</button></div><?php endif; ?>
</form>
</main>
</div>
</body>
</html>
