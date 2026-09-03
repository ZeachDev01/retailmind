<?php
require_once __DIR__ . '/../../backend/includes/auth.php';
require_role(['admin', 'inventory_manager']);

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
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Demand Forecasts</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../modules/sidebar.php'; ?><main class="main-content"><div class="topbar"><div><h1>Random Forest Demand Forecasts</h1><p class="page-subtitle">Forecast ranges, product-level accuracy, and transparent reorder calculations.</p></div><form method="post"><?= csrf_field() ?><button class="btn" name="retrain" value="1">Retrain model</button></form></div>
<?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem"><a class="btn btn-small" href="<?= htmlspecialchars(app_url('report/forecast_analytics.php')) ?>">Forecast analytics</a><a class="btn btn-small" href="<?= htmlspecialchars(app_url('report/data_readiness.php')) ?>">Data readiness</a><?php if (in_array(current_role(), ['admin','super_admin'], true)): ?><a class="btn btn-small" href="<?= htmlspecialchars(app_url('modules/system_administrator/ml_settings.php')) ?>">ML settings</a><?php endif; ?></div>
<div class="card-grid"><div class="stat-card"><div class="value">RF v2</div><div class="label">Model version</div></div><div class="stat-card"><div class="value"><?= number_format($treeCount) ?></div><div class="label">Decision trees</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($modelMetrics, 'wape')) ?><?= isset($modelMetrics['wape']) ? '%' : '' ?></div><div class="label">WAPE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($modelMetrics, 'smape')) ?><?= isset($modelMetrics['smape']) ? '%' : '' ?></div><div class="label">SMAPE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($modelMetrics, 'mean_absolute_error')) ?></div><div class="label">MAE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars((string)($modelMetrics['training_date'] ?? '-')) ?></div><div class="label">Last trained</div></div></div>
<div class="dashboard-section"><div class="table-wrap"><table><tr><th>Product</th><th>Status / data</th><th>7-day demand</th><th>30-day demand</th><th>Lead-time demand</th><th>Accuracy</th><th>Reorder calculation</th><th>Suggested</th><th>Supplier</th></tr>
<?php foreach ($forecastRows as $row): $show=!empty($row['can_show_forecast']); $lead=(int)($row['prediction_supplier_lead_time_days'] ?? $row['supplier_lead_time_days']); ?>
<tr><td><strong><?= htmlspecialchars($row['product_name']) ?></strong><br><span class="muted"><?= htmlspecialchars($row['sku']) ?></span></td><td><?= htmlspecialchars($row['forecast_status']) ?><br><span class="range"><?= (int)$row['calendar_history_days'] ?> calendar / <?= (int)$row['nonzero_sales_days'] ?> selling / <?= (int)$row['zero_sales_days'] ?> zero-sales days</span><br><span class="range"><?= htmlspecialchars($row['forecast_reason']) ?></span></td>
<td><?php if($show): ?><?= (int)$row['predicted_demand_next_7_days'] ?><div class="range"><?= (int)$row['lower_bound_7_days'] ?>–<?= (int)$row['upper_bound_7_days'] ?></div><?php else: ?>-<?php endif; ?></td>
<td><?php if($show): ?><?= (int)$row['predicted_demand_next_30_days'] ?><div class="range"><?= (int)$row['lower_bound_30_days'] ?>–<?= (int)$row['upper_bound_30_days'] ?></div><?php else: ?>-<?php endif; ?></td>
<td><?php if($show): ?><?= (int)$row['forecasted_demand_during_lead_time'] ?> / <?= $lead ?>d<div class="range"><?= (int)$row['lead_time_lower_bound'] ?>–<?= (int)$row['lead_time_upper_bound'] ?></div><?php else: ?>-<?php endif; ?></td>
<td><?php if($show): ?>Confidence <?= round((float)$row['confidence_score']*100) ?>%<br><span class="range">WAPE <?= $row['product_wape'] !== null ? number_format((float)$row['product_wape'],1).'%' : '-' ?><br>MAE <?= $row['product_mae'] !== null ? number_format((float)$row['product_mae'],1) : '-' ?></span><?php else: ?>-<?php endif; ?></td>
<td><?php if($show): $d=(int)$row['forecasted_demand_during_lead_time'];$ss=(int)$row['safety_stock_used'];$cs=(int)$row['current_stock_used'];$inc=(int)$row['incoming_stock_used']; ?><div class="formula"><?= $d ?> + <?= $ss ?> − <?= $cs ?> − <?= $inc ?> = <?= $d+$ss-$cs-$inc ?></div><div class="range">Demand + safety − stock − incoming<br>MOQ <?= (int)$row['minimum_order_quantity_used'] ?>; package <?= (int)$row['units_per_package_used'] ?></div><?php else: ?>-<?php endif; ?></td>
<td><?= $show ? (int)$row['suggested_reorder_qty'] : '-' ?></td><td><?= htmlspecialchars($row['preferred_supplier'] ?: '-') ?></td></tr>
<?php endforeach; ?><?php if (!$forecastRows): ?><tr><td colspan="9" style="text-align:center">No forecast data. Import the v2 migration and train the model.</td></tr><?php endif; ?></table></div></div>
</main></div></body></html>
