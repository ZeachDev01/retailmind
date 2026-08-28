<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$message = '';
$error = '';

$sql = "SELECT p.product_id, p.sku, p.product_name, p.unit_price, p.cost_price, p.reorder_level,
               i.quantity_on_hand,
               COALESCE(s.units_30,0) AS units_30,
               COALESCE(s.units_90,0) AS units_90,
               s.last_sale_date,
               COALESCE(b.expiring_90,0) AS expiring_90,
               COALESCE(b.expired_qty,0) AS expired_qty
        FROM products p
        JOIN inventory i ON i.product_id=p.product_id
        LEFT JOIN (
            SELECT si.product_id,
                   SUM(CASE WHEN sa.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN si.quantity ELSE 0 END) units_30,
                   SUM(CASE WHEN sa.sale_date >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN si.quantity ELSE 0 END) units_90,
                   MAX(sa.sale_date) last_sale_date
            FROM sale_items si JOIN sales sa ON sa.sale_id=si.sale_id
            WHERE sa.sale_date >= DATE_SUB(NOW(), INTERVAL 365 DAY)
            GROUP BY si.product_id
        ) s ON s.product_id=p.product_id
        LEFT JOIN (
            SELECT product_id,
                   SUM(CASE WHEN expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN remaining_quantity ELSE 0 END) expiring_90,
                   SUM(CASE WHEN expiration_date < CURDATE() THEN remaining_quantity ELSE 0 END) expired_qty
            FROM product_batches WHERE remaining_quantity > 0 GROUP BY product_id
        ) b ON b.product_id=p.product_id
        WHERE p.status='active'
        ORDER BY p.product_name";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totalVelocityValue = 0.0;
foreach ($rows as &$row) {
    $row['velocity_value'] = (float)$row['units_90'] * max((float)$row['unit_price'], 0.01);
    $totalVelocityValue += $row['velocity_value'];
}
unset($row);
usort($rows, static fn(array $a, array $b): int => $b['velocity_value'] <=> $a['velocity_value']);
$cumulative = 0.0;
foreach ($rows as &$row) {
    $cumulative += $row['velocity_value'];
    $ratio = $totalVelocityValue > 0 ? $cumulative / $totalVelocityValue : 1;
    $row['abc_class'] = $ratio <= .70 ? 'A' : ($ratio <= .90 ? 'B' : 'C');
    $row['frequency_days'] = $row['abc_class'] === 'A' ? 7 : ($row['abc_class'] === 'B' ? 30 : 90);
    $avgDaily = (float)$row['units_30'] / 30;
    $row['days_of_stock'] = $avgDaily > 0 ? (float)$row['quantity_on_hand'] / $avgDaily : null;
    if ((int)$row['quantity_on_hand'] <= 0) {
        $row['stock_status'] = 'Out of stock';
    } elseif ($row['days_of_stock'] === null) {
        $row['stock_status'] = 'No recent demand';
    } elseif ($row['days_of_stock'] <= 3) {
        $row['stock_status'] = 'Critical';
    } elseif ($row['days_of_stock'] <= 7) {
        $row['stock_status'] = 'Low';
    } elseif ($row['days_of_stock'] <= 30) {
        $row['stock_status'] = 'Adequate';
    } else {
        $row['stock_status'] = 'Excess';
    }
    $priority = 0;
    if (in_array($row['stock_status'], ['Critical','Out of stock'], true)) $priority += 50;
    if ((int)$row['expired_qty'] > 0) $priority += 35;
    if ((int)$row['expiring_90'] > 0) $priority += 20;
    if ((int)$row['units_90'] === 0 && (int)$row['quantity_on_hand'] > 0) $priority += 25;
    if ($row['abc_class'] === 'A') $priority += 25; elseif ($row['abc_class'] === 'B') $priority += 10;
    $row['priority_score'] = $priority;
}
unset($row);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (($_POST['action'] ?? '') === 'schedule_all') {
            $find = $pdo->prepare("SELECT schedule_id FROM cycle_count_schedules WHERE product_id=? AND status='scheduled' ORDER BY schedule_id DESC LIMIT 1");
            $insert = $pdo->prepare("INSERT INTO cycle_count_schedules
                (product_id,abc_class,frequency_days,next_count_date,priority_score,status,created_by)
                VALUES (?,?,?,?,?,'scheduled',?)");
            $update = $pdo->prepare("UPDATE cycle_count_schedules SET abc_class=?,frequency_days=?,next_count_date=?,priority_score=?,created_by=? WHERE schedule_id=?");
            foreach ($rows as $row) {
                $days = (int)$row['frequency_days'];
                $next = date('Y-m-d', strtotime('+' . $days . ' days'));
                $find->execute([(int)$row['product_id']]);
                $scheduleId = $find->fetchColumn();
                if ($scheduleId) {
                    $update->execute([$row['abc_class'],$days,$next,(float)$row['priority_score'],(int)$_SESSION['user_id'],(int)$scheduleId]);
                } else {
                    $insert->execute([(int)$row['product_id'],$row['abc_class'],$days,$next,(float)$row['priority_score'],(int)$_SESSION['user_id']]);
                }
            }
            log_activity($pdo, (int)$_SESSION['user_id'], 'Generated ABC cycle count schedule', 'Inventory', null, null, ['products' => count($rows)]);
            $message = 'ABC cycle-count schedule generated for active products.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$dueSchedules = $pdo->query("SELECT c.*,p.sku,p.product_name,u.full_name assigned_name
    FROM cycle_count_schedules c JOIN products p ON p.product_id=c.product_id
    LEFT JOIN users u ON u.user_id=c.assigned_to
    WHERE c.status='scheduled' ORDER BY c.next_count_date,c.priority_score DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);

$summary = ['critical'=>0,'dead'=>0,'excess'=>0,'expiry'=>0];
foreach ($rows as $row) {
    if (in_array($row['stock_status'], ['Critical','Out of stock'], true)) $summary['critical']++;
    if ((int)$row['units_90'] === 0 && (int)$row['quantity_on_hand'] > 0) $summary['dead']++;
    if ($row['stock_status'] === 'Excess') $summary['excess']++;
    if ((int)$row['expiring_90'] > 0 || (int)$row['expired_qty'] > 0) $summary['expiry']++;
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Inventory Insights</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"><style>
.insight-toolbar{display:flex;gap:.65rem;align-items:center;flex-wrap:wrap}.status{font-weight:700}.table-wrap{overflow:auto}.risk{max-width:270px}.pill{display:inline-block;padding:.2rem .5rem;border-radius:999px;background:var(--surface-2,#e2e8f0);font-size:.8rem}
</style></head><body><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
<div class="topbar"><div><h1>Inventory Insights</h1><p class="page-subtitle">Days of stock, dead stock, excess inventory, expiry risk, and ABC cycle counting.</p></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="schedule_all"><button class="btn">Generate cycle schedule</button></form></div>
<?php if ($message): ?><div class="message success"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card-grid"><div class="stat-card"><div class="value"><?= $summary['critical'] ?></div><div class="label">Critical / out of stock</div></div><div class="stat-card"><div class="value"><?= $summary['dead'] ?></div><div class="label">Dead stock (90 days)</div></div><div class="stat-card"><div class="value"><?= $summary['excess'] ?></div><div class="label">Excess stock</div></div><div class="stat-card"><div class="value"><?= $summary['expiry'] ?></div><div class="label">Expiry-risk products</div></div></div>
<section class="dashboard-section"><h3>Product risk analysis</h3><div class="table-wrap"><table><tr><th>Class</th><th>Product</th><th>On hand</th><th>30-day sales</th><th>Days of stock</th><th>Status</th><th>Expiry</th><th>Suggested action</th></tr>
<?php foreach ($rows as $row):
$actions=[];
if ((int)$row['expired_qty']>0) $actions[]='Block and dispose/return expired stock';
if ((int)$row['expiring_90']>0) $actions[]='Prioritize FEFO or markdown';
if ((int)$row['units_90']===0 && (int)$row['quantity_on_hand']>0) $actions[]='Review price; stop reordering';
if (in_array($row['stock_status'],['Critical','Out of stock'],true)) $actions[]='Create replenishment request';
if ($row['stock_status']==='Excess') $actions[]='Reduce reorder quantity';
if (!$actions) $actions[]='No immediate action'; ?>
<tr><td><span class="pill"><?= $row['abc_class'] ?></span></td><td><?= htmlspecialchars($row['sku'].' - '.$row['product_name']) ?></td><td><?= (int)$row['quantity_on_hand'] ?></td><td><?= (int)$row['units_30'] ?></td><td><?= $row['days_of_stock']===null ? 'No demand' : number_format((float)$row['days_of_stock'],1) ?></td><td class="status"><?= htmlspecialchars($row['stock_status']) ?></td><td><?= (int)$row['expired_qty'] ?> expired / <?= (int)$row['expiring_90'] ?> near</td><td class="risk"><?= htmlspecialchars(implode('; ',$actions)) ?></td></tr>
<?php endforeach; ?><?php if(!$rows):?><tr><td colspan="8">No active products found.</td></tr><?php endif;?></table></div></section>
<section class="dashboard-section"><h3>Scheduled cycle counts</h3><div class="table-wrap"><table><tr><th>Due</th><th>Class</th><th>Product</th><th>Frequency</th><th>Priority</th><th>Assigned</th></tr><?php foreach($dueSchedules as $item):?><tr><td><?= htmlspecialchars($item['next_count_date']) ?></td><td><?= htmlspecialchars($item['abc_class']) ?></td><td><?= htmlspecialchars($item['sku'].' - '.$item['product_name']) ?></td><td>Every <?= (int)$item['frequency_days'] ?> days</td><td><?= number_format((float)$item['priority_score'],0) ?></td><td><?= htmlspecialchars($item['assigned_name'] ?? 'Unassigned') ?></td></tr><?php endforeach;?><?php if(!$dueSchedules):?><tr><td colspan="6">No cycle counts scheduled yet.</td></tr><?php endif;?></table></div><div style="margin-top:1rem"><a class="btn" href="<?= htmlspecialchars(app_url('modules/inventory_management/inventory_counts.php')) ?>">Open inventory counts</a></div></section>
</main></div></body></html>
