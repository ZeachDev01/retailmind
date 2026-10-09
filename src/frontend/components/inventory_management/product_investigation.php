<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\DemandForecastService;
use App\Store\ScheduledDemandDriverService;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
if ($productId <= 0) {
    header('Location: ' . app_url('components/report/predictions.php'));
    exit;
}

$stmt = $pdo->prepare("SELECT sku, barcode, product_name, category_id, unit_price FROM products WHERE product_id = ?");
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
        $errorState = 'insufficient_history'; // or no forecast
    }
}

$driverService = new ScheduledDemandDriverService();
$drivers = $driverService->getDriversForPeriod($pdo, $productId, date('Y-m-d'), date('Y-m-d', strtotime('+28 days')), date('Y-m-d H:i:s'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Product Investigation</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/inventory.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <a href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>" class="btn btn-quiet"><i class="bi bi-arrow-left"></i> Back to Overview</a>
                <h1>Product Investigation: <?= htmlspecialchars($product['product_name'] ?? 'Unknown Product') ?></h1>
                <p class="page-subtitle">Demand Forecast, Scheduled Drivers, and Readiness.</p>
            </div>
        </div>

        <?php if (isset($errorState)): ?>
            <div class="empty-state">
                <i class="bi bi-exclamation-triangle empty-icon"></i>
                <h2><?= htmlspecialchars(str_replace('_', ' ', ucfirst($errorState))) ?></h2>
                <p>Unable to provide forecast data.</p>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Forecast Totals</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>7 Days</th>
                            <th>14 Days</th>
                            <th>28 Days</th>
                            <th>Readiness</th>
                            <th>Limitation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= htmlspecialchars((string)$forecast['aggregates']['forecast_7_day']) ?></td>
                            <td><?= htmlspecialchars((string)$forecast['aggregates']['forecast_14_day']) ?></td>
                            <td><?= htmlspecialchars((string)$forecast['aggregates']['forecast_28_day']) ?></td>
                            <td><?= htmlspecialchars((string)$forecast['aggregates']['readiness_level']) ?></td>
                            <td><?= htmlspecialchars((string)($forecast['aggregates']['limitation_marker'] ?? 'None')) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>Scheduled Demand Drivers</h2>
                <?php if (empty($drivers)): ?>
                    <p>No scheduled price/promotion influences.</p>
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
                <h2>Daily Forecast</h2>
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
                                <td><?= htmlspecialchars((string)$d['horizon_day']) ?></td>
                                <td><?= htmlspecialchars((string)$d['target_date']) ?></td>
                                <td><?= htmlspecialchars((string)$d['predicted_units']) ?></td>
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
