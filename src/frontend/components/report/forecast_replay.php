<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\ReplenishmentQueueService;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$source = json_decode((string)$pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'")->fetchColumn(), true);
$result = json_decode((string)$pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='demo_replay_result'")->fetchColumn(), true);
if (!ReplenishmentQueueService::replayDatabaseAllowed($database)
    || empty($source['replay']) || !$result) {
    http_response_code(404);
    exit('A prepared, explicitly enabled train/test replay is required.');
}
$preview = (new ReplenishmentQueueService($pdo))->getReplayPreview((int)$result['preview_job']);
$products = $preview;
$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT) ?: (int)($products[0]['product_id'] ?? 0);
if (!in_array($productId, array_map('intval', array_column($products, 'product_id')), true)) {
    http_response_code(400);
    exit('Choose an active replay product.');
}
$jobs = array_map('intval', $result['jobs']);
$placeholders = implode(',', array_fill(0, count($jobs), '?'));
$comparison = $pdo->prepare("SELECT df.target_date,df.forecast_origin,df.predicted_units,
    COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON s.sale_id=si.sale_id
        WHERE si.product_id=df.product_id AND DATE(s.sale_date)=df.target_date AND s.payment_reference LIKE 'SIMULATED-UCI-%'),0) AS actual_units
    FROM demand_forecasts df JOIN ml_jobs j ON j.job_id=df.job_id
    WHERE df.job_id IN ($placeholders) AND df.product_id=? AND df.model_version=?
      AND j.trigger_type='forecast_observation' AND j.status='succeeded'
    ORDER BY df.target_date DESC LIMIT 28");
$comparison->execute([...$jobs, $productId, $result['model_version']]);
$daily = $comparison->fetchAll(PDO::FETCH_ASSOC);
$canReceive = has_capability(RoleCapabilityPolicy::MUTATE_INVENTORY);
?>
<!DOCTYPE html>
<html lang="en"><head><?php retailmind_theme_head(); ?>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Train/test replay - RetailMind</title>
<link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
</head><body><div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<main class="main-content">
    <div class="topbar"><div><h1>Train/test replay</h1><p class="page-subtitle">Simulated recent sales. Real public quantities, shifted dates, illustrative stock.</p></div>
        <a class="btn btn-secondary" href="../system_administrator/ml_settings.php">ML Operation</a></div>
    <p class="tag-warning" role="status">Experimental preview only. Training validation: <?= htmlspecialchars($result['validation_outcome']) ?>. No Store model or purchasing guidance was approved.</p>
    <section class="dashboard-section" aria-labelledby="replay-method">
        <h2 id="replay-method">What was trained and tested</h2>
        <p>Training: <?= htmlspecialchars($source['date_window'][0]) ?> to <?= htmlspecialchars($result['training_end']) ?>
            (<?= (int)$source['total_records'] ?> daily observations).
            Test: <?= htmlspecialchars($result['test_start']) ?> to <?= htmlspecialchars($result['test_end']) ?>
            (<?= (int)$result['test_rows'] ?> observations, 20 products).</p>
        <p>Model <code><?= htmlspecialchars($result['model_version']) ?></code> was frozen before testing. Each 28-day prediction block uses only sales available before its origin. Earlier test blocks can supply later lag inputs; test sales never refit this model.</p>
        <p>Dates shifted <?= (int)$source['replay']['shift_days'] ?> days from the original UCI history. Calendar features use the simulated dates, so these scores describe this shifted experiment.</p>
        <p><?= htmlspecialchars($source['replay']['stock_scenario']) ?></p>
        <p><?= htmlspecialchars($source['replay']['money']) ?></p>
        <p><a class="btn btn-secondary" href="../inventory_management/inventory_overview.php">Current stock</a>
            <a class="btn btn-secondary" href="../invoice/sales.php">Simulated sales history</a>
            <a class="btn btn-secondary" href="../inventory_management/replenishment_queue.php">Normal buying guidance</a></p>
    </section>
    <section class="dashboard-section" aria-labelledby="replay-metrics"><h2 id="replay-metrics">How close were the held-out predictions?</h2>
        <p>Errors pool 60 product/origin totals per horizon. MAE and RMSE are units per horizon total; WAPE is error, not accuracy. Test scores do not override training-validation gates.</p>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Horizon</th><th>MAE</th><th>RMSE</th><th>Model WAPE</th><th>Baseline WAPE</th></tr></thead><tbody>
        <?php foreach ($result['metrics'] as $horizon => $metrics): ?><tr>
            <td><?= (int)$horizon ?> days</td><td><?= number_format((float)$metrics['mae'], 2) ?></td><td><?= number_format((float)$metrics['rmse'], 2) ?></td>
            <td><?= $metrics['wape'] === null ? 'Unknown' : number_format((float)$metrics['wape'] * 100, 2) . '%' ?></td>
            <td><?= $metrics['baseline_wape'] === null ? 'Unknown' : number_format((float)$metrics['baseline_wape'] * 100, 2) . '%' ?></td>
        </tr><?php endforeach; ?></tbody></table></div>
    </section>
    <section class="dashboard-section" aria-labelledby="replay-daily"><h2 id="replay-daily">Latest 28 test days: predicted vs simulated sales</h2>
        <form method="get"><label for="replay-product">Product</label><select name="product_id" id="replay-product">
            <?php foreach ($products as $product): ?><option value="<?= (int)$product['product_id'] ?>" <?= (int)$product['product_id'] === $productId ? 'selected' : '' ?>><?= htmlspecialchars($product['sku'] . ' — ' . $product['product_name']) ?></option><?php endforeach; ?>
        </select><button class="btn btn-secondary" type="submit">Compare product</button></form>
        <p>Actual units below come from the seeded simulated sales, including confirmed zero-sales days in this complete replay. Difference is predicted minus actual.</p>
        <div class="table-wrap"><table class="data-table" id="replay-comparison"><thead><tr><th>Sale date</th><th>Forecast origin</th><th>Predicted</th><th>Simulated actual</th><th>Difference</th></tr></thead><tbody>
        <?php foreach ($daily as $row): $difference = (int)$row['predicted_units'] - (int)$row['actual_units']; ?><tr>
            <td><?= htmlspecialchars($row['target_date']) ?></td><td><?= htmlspecialchars($row['forecast_origin']) ?></td>
            <td><?= (int)$row['predicted_units'] ?></td><td><?= (int)$row['actual_units'] ?></td><td><?= $difference > 0 ? '+' : '' ?><?= $difference ?></td>
        </tr><?php endforeach; ?></tbody></table></div>
    </section>
    <section class="dashboard-section" aria-labelledby="replay-stock"><h2 id="replay-stock">Current stock and experimental buying preview</h2>
        <p>Future preview origin: <?= htmlspecialchars($result['test_end']) ?>. These future dates have no actual sales yet. Quantities reuse the normal lead-time, buffer, minimum-order and package rules, but remain unapproved experiments.</p>
        <div class="table-wrap"><table class="data-table" id="replay-stock-table"><thead><tr><th>Product</th><th>On hand</th><th>Next 7 days</th><th>Next 14 days</th><th>Next 28 days</th><th>Preview buy quantity</th><th>Exposure</th></tr></thead><tbody>
        <?php foreach ($preview as $item): ?><tr data-product-id="<?= (int)$item['product_id'] ?>">
            <td><?= htmlspecialchars($item['sku'] . ' — ' . $item['product_name']) ?></td><td><?= (int)$item['current_stock'] ?></td>
            <td><?= (int)$item['forecast_7_day'] ?></td><td><?= (int)$item['forecast_14_day'] ?></td><td><?= (int)$item['forecast_28_day'] ?></td>
            <td><?= (int)$item['recommended_quantity'] ?></td><td><?= htmlspecialchars($item['urgency_label']) ?></td>
        </tr><?php endforeach; ?></tbody></table></div>
        <?php if ($canReceive): ?><p><a class="btn btn-secondary" href="stock_receiving.php">Record goods bought in the Demo</a></p><?php endif; ?>
    </section>
</main></div></body></html>
