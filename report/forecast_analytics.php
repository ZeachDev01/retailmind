<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'inventory_manager']);

$metrics = get_ml_model_metrics();
$featureImportance = $metrics['feature_importance'] ?? [];
$productMetrics = $metrics['product_metrics'] ?? [];
$productsById = [];
foreach ($pdo->query('SELECT product_id, product_name FROM products')->fetchAll(PDO::FETCH_ASSOC) as $product) {
    $productsById[(int)$product['product_id']] = $product['product_name'];
}

$actualVsForecast = [];
try {
    $actualVsForecast = $pdo->query(
        "SELECT p.product_name, sp.forecast_value, sp.actual_demand, sp.generated_at
         FROM stock_predictions sp JOIN products p ON p.product_id = sp.product_id
         WHERE sp.actual_demand IS NOT NULL
         ORDER BY sp.generated_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$trainingTrend = [];
try {
    $runs = $pdo->query("SELECT started_at, metrics_json FROM model_training_runs WHERE status = 'completed' ORDER BY started_at ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($runs as $run) {
        $runMetrics = json_decode((string)$run['metrics_json'], true) ?: [];
        $trainingTrend[] = ['date' => $run['started_at'], 'wape' => $runMetrics['wape'] ?? null, 'mae' => $runMetrics['mean_absolute_error'] ?? null];
    }
} catch (PDOException $e) {}

usort($productMetrics, static fn(array $a, array $b): int => ($a['wape'] ?? PHP_FLOAT_MAX) <=> ($b['wape'] ?? PHP_FLOAT_MAX));
$best = array_slice($productMetrics, 0, 5);
$worst = array_slice(array_reverse($productMetrics), 0, 5);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forecast Analytics</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"><style>.chart-card{min-height:310px}.chart-card canvas{width:100%;height:240px}.analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1rem;margin-bottom:1rem}</style></head>
<body><div class="app-shell"><?php include __DIR__ . '/../modules/sidebar.php'; ?><main class="main-content"><div class="topbar"><div><h1>Forecast Analytics</h1><p class="page-subtitle">Backtesting, accuracy trends, feature importance, and product-level performance.</p></div><div style="display:flex;gap:.5rem;flex-wrap:wrap"><a class="btn" href="<?= htmlspecialchars(app_url('report/forecast_exceptions.php')) ?>">Forecast exceptions</a><a class="btn" href="<?= htmlspecialchars(app_url('report/data_readiness.php')) ?>">Data readiness</a></div></div>
<div class="card-grid"><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'mean_absolute_error')) ?></div><div class="label">Random Forest MAE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'root_mean_squared_error')) ?></div><div class="label">RMSE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'wape')) ?><?= isset($metrics['wape']) ? '%' : '' ?></div><div class="label">Random Forest WAPE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'baseline_wape')) ?><?= isset($metrics['baseline_wape']) ? '%' : '' ?></div><div class="label">7-day baseline WAPE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'model_improvement_vs_baseline_pct')) ?><?= isset($metrics['model_improvement_vs_baseline_pct']) ? '%' : '' ?></div><div class="label">Improvement vs baseline</div></div><div class="stat-card"><div class="value"><?= !empty($metrics['model_beats_baseline']) ? 'Yes' : (array_key_exists('model_beats_baseline', $metrics) ? 'No' : '-') ?></div><div class="label">Model beats baseline</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'eligible_products', 0)) ?></div><div class="label">Eligible products</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'zero_sales_records', 0)) ?></div><div class="label">Zero-sales dates used</div></div></div>
<div class="analytics-grid"><section class="dashboard-section chart-card"><h3>Feature importance</h3><canvas id="featureChart"></canvas></section><section class="dashboard-section chart-card"><h3>Accuracy over retraining runs</h3><canvas id="trendChart"></canvas></section><section class="dashboard-section chart-card"><h3>Completed forecasts: actual vs predicted</h3><canvas id="actualChart"></canvas></section></div>
<div class="analytics-grid"><section class="dashboard-section"><h3>Best-performing products</h3><div class="table-wrap"><table><tr><th>Product</th><th>WAPE</th><th>MAE</th><th>Actual</th><th>Predicted</th></tr><?php foreach ($best as $row): ?><tr><td><?= htmlspecialchars($productsById[(int)$row['product_id']] ?? ('Product #' . $row['product_id'])) ?></td><td><?= $row['wape'] !== null ? number_format((float)$row['wape'], 2) . '%' : '-' ?></td><td><?= $row['mae'] !== null ? number_format((float)$row['mae'], 2) : '-' ?></td><td><?= number_format((float)$row['actual_total'], 0) ?></td><td><?= number_format((float)$row['predicted_total'], 0) ?></td></tr><?php endforeach; ?><?php if (!$best): ?><tr><td colspan="5">No product evaluation data yet.</td></tr><?php endif; ?></table></div></section>
<section class="dashboard-section"><h3>Products needing attention</h3><div class="table-wrap"><table><tr><th>Product</th><th>WAPE</th><th>SMAPE</th><th>Records</th></tr><?php foreach ($worst as $row): ?><tr><td><?= htmlspecialchars($productsById[(int)$row['product_id']] ?? ('Product #' . $row['product_id'])) ?></td><td><?= $row['wape'] !== null ? number_format((float)$row['wape'], 2) . '%' : '-' ?></td><td><?= $row['smape'] !== null ? number_format((float)$row['smape'], 2) . '%' : '-' ?></td><td><?= (int)$row['evaluation_records'] ?></td></tr><?php endforeach; ?><?php if (!$worst): ?><tr><td colspan="4">No product evaluation data yet.</td></tr><?php endif; ?></table></div></section></div>
</main></div>
<script>
const featureData = <?= json_encode(['labels' => array_keys($featureImportance), 'values' => array_values($featureImportance)], JSON_HEX_TAG) ?>;
const trendData = <?= json_encode($trainingTrend, JSON_HEX_TAG) ?>;
const actualData = <?= json_encode(array_reverse($actualVsForecast), JSON_HEX_TAG) ?>;
function drawBars(canvasId, labels, series, legends) {
  const canvas = document.getElementById(canvasId), dpr = window.devicePixelRatio || 1, width = canvas.clientWidth || 600, height = 240;
  canvas.width = width*dpr; canvas.height = height*dpr; const ctx=canvas.getContext('2d'); ctx.scale(dpr,dpr); ctx.clearRect(0,0,width,height);
  const pad={l:48,r:12,t:18,b:55}, cw=width-pad.l-pad.r, ch=height-pad.t-pad.b; const all=series.flatMap(s=>s.values).filter(v=>Number.isFinite(Number(v))).map(Number); const max=Math.max(1,...all)*1.1;
  ctx.strokeStyle='#94a3b8'; ctx.beginPath(); ctx.moveTo(pad.l,pad.t); ctx.lineTo(pad.l,height-pad.b); ctx.lineTo(width-pad.r,height-pad.b); ctx.stroke();
  const groups=Math.max(1,labels.length), groupW=cw/groups, barW=Math.max(3,groupW/(series.length+1));
  series.forEach((s,si)=>{ctx.fillStyle=s.fill || (si===0?'#2563eb':'#16a34a'); s.values.forEach((value,i)=>{value=Number(value)||0; const h=value/max*ch; const x=pad.l+i*groupW+(si+.4)*barW; ctx.fillRect(x,height-pad.b-h,barW*.8,h);});});
  ctx.fillStyle='#64748b'; ctx.font='11px sans-serif'; labels.forEach((label,i)=>{ctx.save();ctx.translate(pad.l+i*groupW+groupW/2,height-pad.b+8);ctx.rotate(-.45);ctx.fillText(String(label).slice(0,18),0,0);ctx.restore();});
  ctx.fillText(max.toFixed(1),4,pad.t+5); if(legends){legends.forEach((x,i)=>ctx.fillText(x, pad.l+i*110, 12));}
}
function drawLine(canvasId, labels, values) {
 const canvas=document.getElementById(canvasId),dpr=window.devicePixelRatio||1,w=canvas.clientWidth||600,h=240;canvas.width=w*dpr;canvas.height=h*dpr;const ctx=canvas.getContext('2d');ctx.scale(dpr,dpr);const p={l:45,r:15,t:15,b:45},cw=w-p.l-p.r,ch=h-p.t-p.b;const nums=values.map(Number).filter(Number.isFinite),max=Math.max(1,...nums)*1.1;
 ctx.strokeStyle='#94a3b8';ctx.beginPath();ctx.moveTo(p.l,p.t);ctx.lineTo(p.l,h-p.b);ctx.lineTo(w-p.r,h-p.b);ctx.stroke();ctx.strokeStyle='#2563eb';ctx.lineWidth=2;ctx.beginPath();values.forEach((v,i)=>{const x=p.l+(labels.length<=1?cw/2:i*cw/(labels.length-1)),y=h-p.b-(Number(v)||0)/max*ch;i?ctx.lineTo(x,y):ctx.moveTo(x,y);});ctx.stroke();ctx.fillStyle='#64748b';ctx.font='11px sans-serif';labels.forEach((l,i)=>{if(i%Math.max(1,Math.ceil(labels.length/6))===0)ctx.fillText(String(l).slice(5,10),p.l+(labels.length<=1?0:i*cw/(labels.length-1))-12,h-18);});ctx.fillText(max.toFixed(1),3,p.t+5);
}
drawBars('featureChart', featureData.labels, [{values:featureData.values,fill:'#2563eb'}]);
drawLine('trendChart', trendData.map(x=>x.date), trendData.map(x=>x.wape));
drawBars('actualChart', actualData.map(x=>x.product_name), [{values:actualData.map(x=>x.forecast_value),fill:'#2563eb'},{values:actualData.map(x=>x.actual_demand),fill:'#16a34a'}], ['Forecast','Actual']);
</script></body></html>
