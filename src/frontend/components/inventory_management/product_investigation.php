<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;
use App\Store\ScheduledDemandDriverService;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$skuParam = trim($_GET['sku'] ?? '');

if ($productId <= 0 && $skuParam !== '') {
    $stmt = $pdo->prepare("SELECT product_id FROM products WHERE sku = ? LIMIT 1");
    $stmt->execute([$skuParam]);
    $productId = (int)$stmt->fetchColumn();
}

if ($productId <= 0) {
    header('Location: ' . app_url('components/report/predictions.php'));
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.product_id, p.sku, p.barcode, p.product_name, p.category_id, p.unit_price, COALESCE(i.quantity_on_hand, 0) as current_stock
    FROM products p
    LEFT JOIN inventory i ON p.product_id = i.product_id
    WHERE p.product_id = ?
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $errorState = 'missing_product';
}

$service = new DemandForecastService($pdo);
$latestJobId = $service->getLatestSuccessfulJobId();
$forecast = null;

if (!$latestJobId) {
    $errorState = 'failed_latest_run';
} else {
    $forecast = $service->getProductForecast($latestJobId, $productId);
    if (empty($forecast['aggregates']) || empty($forecast['daily'])) {
        $errorState = 'insufficient_history';
    }
}

// Drivers known at the displayed forecast origin cutoff
$forecastOrigin = $forecast['aggregates']['forecast_origin'] ?? date('Y-m-d');
$originCutoff = $forecastOrigin . ' 23:59:59';
$driverService = new ScheduledDemandDriverService();
$drivers = $driverService->getDriversForPeriod(
    $pdo, 
    $productId, 
    $forecastOrigin, 
    date('Y-m-d', strtotime('+28 days', strtotime($forecastOrigin))), 
    $originCutoff
);

$isDemoProduct = isset($product['sku']) && str_starts_with($product['sku'], 'DEMO-');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Product Investigation: <?= htmlspecialchars($product['product_name'] ?? 'Product') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/inventory.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/reports.css')) ?>">
    <style>
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .meta-card {
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 6px;
            padding: 1rem;
        }
        .meta-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted, #64748b);
            margin-bottom: 0.25rem;
        }
        .meta-value {
            font-size: 1.1rem;
            font-weight: 600;
        }
        .demo-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 0.5rem;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <a href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>" class="btn btn-quiet"><i class="bi bi-arrow-left"></i> Back to Demand Forecast</a>
                <h1>
                    <?= htmlspecialchars($product['product_name'] ?? 'Unknown Product') ?>
                    <?php if ($isDemoProduct): ?><span class="demo-badge">Demo Product (S001)</span><?php endif; ?>
                </h1>
                <p class="page-subtitle">Product SKU: <code><?= htmlspecialchars($product['sku'] ?? 'N/A') ?></code> &middot; Current Stock: <?= (int)($product['current_stock'] ?? 0) ?></p>
            </div>
        </div>

        <?php if (isset($errorState)): ?>
            <div class="empty-state">
                <i class="bi bi-exclamation-triangle empty-icon"></i>
                <h2><?= htmlspecialchars(str_replace('_', ' ', ucfirst($errorState))) ?></h2>
                <p>Unable to provide forecast data for this product at this time.</p>
            </div>
        <?php else: ?>
            <div class="meta-grid">
                <div class="meta-card">
                    <div class="meta-label">Forecast Origin</div>
                    <div class="meta-value"><?= htmlspecialchars((string)$forecast['aggregates']['forecast_origin']) ?></div>
                </div>
                <div class="meta-card">
                    <div class="meta-label">Model Version</div>
                    <div class="meta-value"><code><?= htmlspecialchars((string)$forecast['aggregates']['model_version']) ?></code></div>
                </div>
                <div class="meta-card">
                    <div class="meta-label">Readiness Level</div>
                    <div class="meta-value"><?= htmlspecialchars((string)$forecast['aggregates']['readiness_level']) ?></div>
                </div>
                <div class="meta-card">
                    <div class="meta-label">Evidence / Limitation</div>
                    <div class="meta-value" style="font-size:0.95rem; font-weight:normal;"><?= htmlspecialchars((string)($forecast['aggregates']['limitation_marker'] ?? 'Full evidence available')) ?></div>
                </div>
            </div>

            <div class="card" style="margin-bottom: 1.5rem;">
                <h2>Forecast Horizon Totals</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>7-Day Demand</th>
                            <th>14-Day Demand</th>
                            <th>28-Day Demand</th>
                            <th>Readiness Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)$forecast['aggregates']['forecast_7_day']) ?></strong></td>
                            <td><strong><?= htmlspecialchars((string)$forecast['aggregates']['forecast_14_day']) ?></strong></td>
                            <td><strong><?= htmlspecialchars((string)$forecast['aggregates']['forecast_28_day']) ?></strong></td>
                            <td><?= htmlspecialchars((string)$forecast['aggregates']['readiness_level']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="margin-bottom: 1.5rem;">
                <h2>Scheduled Demand Drivers (as of Origin Cutoff)</h2>
                <?php if (empty($drivers)): ?>
                    <p style="color:var(--text-muted, #64748b); font-style:italic;">No scheduled price changes or promotions were known prior to the forecast origin.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Effective Date</th>
                                <th>Driver Type</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($drivers as $d): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$d['effective_date']) ?></td>
                                    <td><?= htmlspecialchars((string)$d['driver_type']) ?></td>
                                    <td><?= htmlspecialchars((string)$d['details_json']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Daily Forecast (28-Day Breakdown)</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Horizon Day</th>
                            <th>Target Date</th>
                            <th>Predicted Units</th>
                            <th>Readiness Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($forecast['daily'] as $d): ?>
                            <tr>
                                <td>Day <?= htmlspecialchars((string)$d['horizon_day']) ?></td>
                                <td><?= htmlspecialchars((string)$d['target_date']) ?></td>
                                <td><strong><?= htmlspecialchars((string)$d['predicted_units']) ?></strong></td>
                                <td><?= htmlspecialchars((string)$d['readiness_level']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
