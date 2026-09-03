<?php
require_once __DIR__ . '/../../backend/includes/auth.php';
require_role(['admin', 'inventory_manager']);
$rows = get_forecasting_readiness($pdo);
$counts = [];
foreach ($rows as $row) { $counts[$row['forecast_status']] = ($counts[$row['forecast_status']] ?? 0) + 1; }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forecast Data Readiness</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../modules/sidebar.php'; ?><main class="main-content"><div class="topbar"><div><h1>Forecast Data Readiness</h1><p class="page-subtitle">Review complete calendar history, zero-sales dates, eligibility, and data-quality warnings.</p></div><a class="btn" href="<?= htmlspecialchars(app_url('report/predictions.php')) ?>">Forecasts</a></div>
<div class="card-grid"><div class="stat-card"><div class="value"><?= count($rows) ?></div><div class="label">Active products</div></div><div class="stat-card"><div class="value"><?= (int)($counts['Forecast Generated'] ?? 0) ?></div><div class="label">Forecast generated</div></div><div class="stat-card"><div class="value"><?= (int)($counts['Ready for Forecasting'] ?? 0) ?></div><div class="label">Ready to train</div></div><div class="stat-card"><div class="value"><?= (int)($counts['Insufficient Data'] ?? 0) ?></div><div class="label">Insufficient data</div></div></div>
<div class="dashboard-section"><div class="table-wrap"><table><tr><th>SKU</th><th>Product</th><th>Category</th><th>First sale</th><th>Latest sale</th><th>Calendar days</th><th>Days with sales</th><th>Zero-sales days</th><th>Records</th><th>Status</th><th>Reason</th></tr>
<?php foreach ($rows as $row): ?><tr><td><?= htmlspecialchars($row['sku']) ?></td><td><?= htmlspecialchars($row['product_name']) ?></td><td><?= htmlspecialchars($row['category']) ?></td><td><?= htmlspecialchars((string)($row['first_sale_date'] ?? '-')) ?></td><td><?= htmlspecialchars((string)($row['last_sale_at'] ?? '-')) ?></td><td><?= (int)$row['calendar_history_days'] ?></td><td><?= (int)$row['nonzero_sales_days'] ?></td><td><?= (int)$row['zero_sales_days'] ?></td><td><?= (int)$row['sales_records'] ?></td><td><?= htmlspecialchars($row['forecast_status']) ?></td><td><?= htmlspecialchars($row['forecast_reason']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="11" class="muted u-text-center">No readiness data. Run <code>backend/sql/upgrade_random_forest_v2.sql</code> first.</td></tr><?php endif; ?></table></div></div>
</main></div></body></html>
