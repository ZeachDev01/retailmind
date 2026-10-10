<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::VIEW_STORE_REPORTS);

$prototypeVariant = strtoupper(trim((string)($_GET['variant'] ?? '')));
$prototypeEnabled = strtolower((string)env('APP_ENV', 'production')) !== 'production'
    && in_array($prototypeVariant, ['A', 'B', 'C'], true);

if ($prototypeEnabled) {
    $forecastRows = get_forecasting_readiness($pdo);
    $modelMetrics = get_ml_model_metrics();
    require __DIR__ . '/forecast_dashboard_prototype.php';
    exit;
}

$message = '';
$messageClass = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retrain'])) {
    csrf_verify();
    $before = get_ml_model_metrics();
    $response = call_ml_api(ML_API_RETRAIN_ENDPOINT, 'POST', [], max(180, ML_API_TIMEOUT));
    $success = !empty($response['success']);
    $message = $success ? 'Random Forest model retrained successfully.' : (string)($response['message'] ?? 'Retraining failed.');
    $messageClass = $success ? 'tag-success' : 'tag-warning';
    log_activity($pdo, (int)$_SESSION['user_id'], 'Forecast retraining', 'Demand Forecasting', null, $before, $response);
}

$forecastRows = get_forecasting_readiness($pdo);
$modelMetrics = get_ml_model_metrics();
$treeCount = (int)($modelMetrics['hyperparameters']['n_estimators'] ?? $modelMetrics['settings']['n_estimators'] ?? 300);
?>
<!DOCTYPE html><html lang="en"><head><?php retailmind_theme_head(); ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Demand Forecasts</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content"><div class="topbar"><div><h1>Demand Forecasting</h1><p class="page-subtitle">See how many units customers may buy and how much stock to reorder.</p></div><form method="post"><?= csrf_field() ?><button type="submit" class="btn" name="retrain" value="1">Update forecasts</button></form></div>
<?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="u-flex-wrap-loose"><a class="btn btn-small" href="<?= htmlspecialchars(app_url('components/report/forecast_analytics.php')) ?>">Forecast analytics</a><a class="btn btn-small" href="<?= htmlspecialchars(app_url('components/report/data_readiness.php')) ?>">Check sales data</a><?php if (in_array(current_role(), ['admin','super_admin'], true)): ?><a class="btn btn-small" href="<?= htmlspecialchars(app_url('components/system_administrator/ml_settings.php')) ?>">ML settings</a><?php endif; ?></div>
<details class="dashboard-section"><summary>Model details and accuracy metrics</summary><p class="muted">WAPE and SMAPE measure percentage error; MAE measures average error in units. Lower error is better.</p><div class="card-grid"><div class="stat-card"><div class="value">RF v2</div><div class="label">Model version</div></div><div class="stat-card"><div class="value"><?= number_format($treeCount) ?></div><div class="label">Decision trees</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($modelMetrics, 'wape')) ?><?= isset($modelMetrics['wape']) ? '%' : '' ?></div><div class="label">WAPE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($modelMetrics, 'smape')) ?><?= isset($modelMetrics['smape']) ? '%' : '' ?></div><div class="label">SMAPE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($modelMetrics, 'mean_absolute_error')) ?></div><div class="label">MAE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars((string)($modelMetrics['training_date'] ?? '-')) ?></div><div class="label">Last updated</div></div></div></details>
<div class="dashboard-section"><h2>What should I order?</h2><p>Read the expected sales for the next 7 or 30 days, then check <strong>Units to reorder</strong>. The suggestion accounts for demand while waiting for delivery, safety stock, current stock, incoming orders, and supplier order sizes.</p><p class="muted">Forecasts are estimates. Ranges show possible demand. A dash means no forecast is available; check Data Readiness for the reason. Updating forecasts retrains the model using recorded data and may take a few minutes.</p><div class="table-wrap"><table><tr><th>Product</th><th>Forecast status</th><th>Expected sales: 7 days</th><th>Expected sales: 30 days</th><th>Sales while awaiting delivery</th><th>Forecast reliability</th><th>Why this order size?</th><th>Units to reorder</th><th>Supplier</th></tr>
<?php foreach ($forecastRows as $row): $show=!empty($row['can_show_forecast']); $lead=(int)($row['prediction_supplier_lead_time_days'] ?? $row['supplier_lead_time_days']); ?>
<tr><td><strong><?= htmlspecialchars($row['product_name']) ?></strong><br><span class="muted"><?= htmlspecialchars($row['sku']) ?></span></td><td><?= htmlspecialchars($row['forecast_status']) ?><br><span class="range"><?= (int)$row['calendar_history_days'] ?> history days / <?= (int)$row['nonzero_sales_days'] ?> days with sales / <?= (int)$row['zero_sales_days'] ?> days without sales</span><br><span class="range"><?= htmlspecialchars($row['forecast_reason']) ?></span></td>
<td><?php if($show): ?><?= (int)$row['predicted_demand_next_7_days'] ?><div class="range"><?= (int)$row['lower_bound_7_days'] ?>–<?= (int)$row['upper_bound_7_days'] ?></div><?php else: ?>-<?php endif; ?></td>
<td><?php if($show): ?><?= (int)$row['predicted_demand_next_30_days'] ?><div class="range"><?= (int)$row['lower_bound_30_days'] ?>–<?= (int)$row['upper_bound_30_days'] ?></div><?php else: ?>-<?php endif; ?></td>
<td><?php if($show): ?><?= (int)$row['forecasted_demand_during_lead_time'] ?> / <?= $lead ?>d<div class="range"><?= (int)$row['lead_time_lower_bound'] ?>–<?= (int)$row['lead_time_upper_bound'] ?></div><?php else: ?>-<?php endif; ?></td>
<td><?php if($show): ?>Confidence <?= round((float)$row['confidence_score']*100) ?>%<br><span class="range">WAPE <?= $row['product_wape'] !== null ? number_format((float)$row['product_wape'],1).'%' : '-' ?><br>MAE <?= $row['product_mae'] !== null ? number_format((float)$row['product_mae'],1) : '-' ?></span><?php else: ?>-<?php endif; ?></td>
<td><?php if($show): $d=(int)$row['forecasted_demand_during_lead_time'];$ss=(int)$row['safety_stock_used'];$cs=(int)$row['current_stock_used'];$inc=(int)$row['incoming_stock_used']; ?><details><summary>View stock calculation</summary><div class="formula"><?= $d ?> + <?= $ss ?> − <?= $cs ?> − <?= $inc ?> = <?= $d+$ss-$cs-$inc ?></div><div class="range">Demand + safety − stock − incoming<br>Minimum order <?= (int)$row['minimum_order_quantity_used'] ?>; units per package <?= (int)$row['units_per_package_used'] ?></div></details><?php else: ?>-<?php endif; ?></td>
<td><?= $show ? (int)$row['suggested_reorder_qty'] : '-' ?></td><td><?= htmlspecialchars($row['preferred_supplier'] ?: '-') ?></td></tr>
<?php endforeach; ?><?php if (!$forecastRows): ?><tr><td class="u-text-center" colspan="9">No forecasts are available yet. Check Data Readiness, then update forecasts when products are ready. If you already have sales data, ask your administrator to check the forecasting setup.</td></tr><?php endif; ?></table></div></div>
</main></div></body></html>
