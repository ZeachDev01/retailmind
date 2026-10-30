<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);
$rows = get_forecasting_readiness($pdo);
$counts = [];
foreach ($rows as $row) {
    $counts[$row['forecast_status']] = ($counts[$row['forecast_status']] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Forecast Data Readiness</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <h1>Data Readiness</h1>
            <a class="btn" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">View demand forecasts</a>
        </header>

        <div class="card-grid">
            <div class="stat-card">
                <div class="value"><?= count($rows) ?></div>
                <div class="label">Active products</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= (int)($counts['Forecast Generated'] ?? 0) ?></div>
                <div class="label">Forecast generated</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= (int)($counts['Ready for Forecasting'] ?? 0) ?></div>
                <div class="label">Ready to forecast</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= (int)($counts['Insufficient Data'] ?? 0) ?></div>
                <div class="label">Needs more sales history</div>
            </div>
        </div>

        <section class="dashboard-section">
            <div class="section-header">
                <h2>Product readiness</h2>
                <p class="u-text-muted">Missing history is unknown, not zero sales; the Super Administrator imports historical sales through ML Operation.</p>
            </div>
            <div class="table-wrap" role="region" aria-label="Product readiness" tabindex="0">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Forecast status &amp; reason</th>
                            <th scope="col">Sales history</th>
                            <th scope="col">Days with / without sales</th>
                            <th scope="col">Recorded sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($row['product_name']) ?></strong><br>
                                    <span class="u-text-muted"><?= htmlspecialchars($row['sku']) ?> &middot; <?= htmlspecialchars($row['category']) ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($row['forecast_status']) ?></strong><br>
                                    <span class="u-text-muted"><?= htmlspecialchars($row['forecast_reason']) ?></span>
                                </td>
                                <td>
                                    <?= (int)$row['calendar_history_days'] ?> days<br>
                                    <span class="u-text-muted">First sale: <?= htmlspecialchars((string)($row['first_sale_date'] ?? 'No sales yet')) ?><br>Latest sale: <?= htmlspecialchars((string)($row['last_sale_at'] ?? 'No sales yet')) ?></span>
                                </td>
                                <td>
                                    <?= (int)$row['nonzero_sales_days'] ?> with sales<br>
                                    <span class="u-text-muted"><?= (int)$row['zero_sales_days'] ?> without sales</span>
                                </td>
                                <td><?= (int)$row['sales_records'] ?> records</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="5" class="u-text-muted u-text-center">No product readiness records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
