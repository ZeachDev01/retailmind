<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::VIEW_STORE_REPORTS);
$storeId = store_scope_id($pdo);

$metrics = get_ml_model_metrics();
$featureImportance = $metrics['feature_importance'] ?? [];
$productMetrics = $metrics['product_metrics'] ?? [];
$productsById = [];
$productStatement = $pdo->prepare('SELECT product_id, product_name FROM products WHERE branch_id = ?');
$productStatement->execute([$storeId]);
foreach ($productStatement->fetchAll(PDO::FETCH_ASSOC) as $product) {
    $productsById[(int)$product['product_id']] = $product['product_name'];
}
$productMetrics = array_values(array_filter(
    $productMetrics,
    static fn(array $metric): bool => isset($productsById[(int)$metric['product_id']])
));

$actualVsForecast = [];
try {
    $actualStatement = $pdo->prepare(
        "SELECT p.product_name, sp.forecast_value, sp.actual_demand, sp.generated_at
         FROM stock_predictions sp JOIN products p ON p.product_id = sp.product_id
         WHERE sp.actual_demand IS NOT NULL AND p.branch_id = ?
         ORDER BY sp.generated_at DESC LIMIT 20"
    );
    $actualStatement->execute([$storeId]);
    $actualVsForecast = $actualStatement->fetchAll(PDO::FETCH_ASSOC);
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
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forecast Analytics</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content"><div class="topbar"><div><h1>Forecast Analytics</h1><p class="page-subtitle">Backtesting, accuracy trends, feature importance, and product-level performance.</p></div><div class="u-flex-wrap"><a class="btn" href="<?= htmlspecialchars(app_url('components/report/forecast_exceptions.php')) ?>">Forecast exceptions</a><a class="btn" href="<?= htmlspecialchars(app_url('components/report/data_readiness.php')) ?>">Data readiness</a></div></div>
<div class="card-grid"><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'mean_absolute_error')) ?></div><div class="label">Random Forest MAE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'root_mean_squared_error')) ?></div><div class="label">RMSE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'wape')) ?><?= isset($metrics['wape']) ? '%' : '' ?></div><div class="label">Random Forest WAPE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'baseline_wape')) ?><?= isset($metrics['baseline_wape']) ? '%' : '' ?></div><div class="label">7-day baseline WAPE</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'model_improvement_vs_baseline_pct')) ?><?= isset($metrics['model_improvement_vs_baseline_pct']) ? '%' : '' ?></div><div class="label">Improvement vs baseline</div></div><div class="stat-card"><div class="value"><?= !empty($metrics['model_beats_baseline']) ? 'Yes' : (array_key_exists('model_beats_baseline', $metrics) ? 'No' : '-') ?></div><div class="label">Model beats baseline</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'eligible_products', 0)) ?></div><div class="label">Eligible products</div></div><div class="stat-card"><div class="value"><?= htmlspecialchars(format_ml_metric($metrics, 'zero_sales_records', 0)) ?></div><div class="label">Zero-sales dates used</div></div></div>
<section class="forecast-analytics-tabs" aria-labelledby="analytics-view-title">
<div class="analytics-tab-list" role="tablist" aria-label="Forecast analytics views">
<button class="analytics-tab is-active" id="analytics-tab-features" type="button" role="tab" aria-selected="true" aria-controls="analytics-panel-features" data-analytics-tab="features"><i class="bi bi-sliders" aria-hidden="true"></i>Feature Importance</button>
<button class="analytics-tab" id="analytics-tab-trend" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-trend" data-analytics-tab="trend" tabindex="-1"><i class="bi bi-graph-up" aria-hidden="true"></i>Accuracy Trend</button>
<button class="analytics-tab" id="analytics-tab-actual" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-actual" data-analytics-tab="actual" tabindex="-1"><i class="bi bi-bar-chart" aria-hidden="true"></i>Actual vs Predicted</button>
<button class="analytics-tab" id="analytics-tab-best" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-best" data-analytics-tab="best" tabindex="-1"><i class="bi bi-trophy" aria-hidden="true"></i>Best Performers</button>
<button class="analytics-tab" id="analytics-tab-attention" type="button" role="tab" aria-selected="false" aria-controls="analytics-panel-attention" data-analytics-tab="attention" tabindex="-1"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Needs Attention</button>
</div>

<div class="analytics-tab-panel chart-card" id="analytics-panel-features" role="tabpanel" aria-labelledby="analytics-tab-features" data-analytics-panel="features"><h2 id="analytics-view-title">Feature importance</h2><canvas id="featureChart"></canvas></div>
<div class="analytics-tab-panel chart-card" id="analytics-panel-trend" role="tabpanel" aria-labelledby="analytics-tab-trend" data-analytics-panel="trend" hidden><h2>Accuracy over retraining runs</h2><canvas id="trendChart"></canvas></div>
<div class="analytics-tab-panel chart-card" id="analytics-panel-actual" role="tabpanel" aria-labelledby="analytics-tab-actual" data-analytics-panel="actual" hidden><h2>Completed forecasts: actual vs predicted</h2><canvas id="actualChart"></canvas></div>
<div class="analytics-tab-panel" id="analytics-panel-best" role="tabpanel" aria-labelledby="analytics-tab-best" data-analytics-panel="best" hidden><h2>Best-performing products</h2><div class="table-wrap"><table><tr><th>Product</th><th>WAPE</th><th>MAE</th><th>Actual</th><th>Predicted</th></tr><?php foreach ($best as $row): ?><tr><td><?= htmlspecialchars($productsById[(int)$row['product_id']] ?? ('Product #' . $row['product_id'])) ?></td><td><?= $row['wape'] !== null ? number_format((float)$row['wape'], 2) . '%' : '-' ?></td><td><?= $row['mae'] !== null ? number_format((float)$row['mae'], 2) : '-' ?></td><td><?= number_format((float)$row['actual_total'], 0) ?></td><td><?= number_format((float)$row['predicted_total'], 0) ?></td></tr><?php endforeach; ?><?php if (!$best): ?><tr><td colspan="5">No product evaluation data yet.</td></tr><?php endif; ?></table></div></div>
<div class="analytics-tab-panel" id="analytics-panel-attention" role="tabpanel" aria-labelledby="analytics-tab-attention" data-analytics-panel="attention" hidden><h2>Products needing attention</h2><div class="table-wrap"><table><tr><th>Product</th><th>WAPE</th><th>SMAPE</th><th>Records</th></tr><?php foreach ($worst as $row): ?><tr><td><?= htmlspecialchars($productsById[(int)$row['product_id']] ?? ('Product #' . $row['product_id'])) ?></td><td><?= $row['wape'] !== null ? number_format((float)$row['wape'], 2) . '%' : '-' ?></td><td><?= $row['smape'] !== null ? number_format((float)$row['smape'], 2) . '%' : '-' ?></td><td><?= (int)$row['evaluation_records'] ?></td></tr><?php endforeach; ?><?php if (!$worst): ?><tr><td colspan="4">No product evaluation data yet.</td></tr><?php endif; ?></table></div></div>
</section>
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
function drawAnalyticsPanel(panel) {
  if (panel === 'features') drawBars('featureChart', featureData.labels, [{values:featureData.values,fill:'#2563eb'}]);
  if (panel === 'trend') drawLine('trendChart', trendData.map(x=>x.date), trendData.map(x=>x.wape));
  if (panel === 'actual') drawBars('actualChart', actualData.map(x=>x.product_name), [{values:actualData.map(x=>x.forecast_value),fill:'#2563eb'},{values:actualData.map(x=>x.actual_demand),fill:'#16a34a'}], ['Forecast','Actual']);
}
const analyticsTabs = Array.from(document.querySelectorAll('[data-analytics-tab]'));
const analyticsPanels = Array.from(document.querySelectorAll('[data-analytics-panel]'));
function activateAnalyticsTab(tab) {
  const target = tab.dataset.analyticsTab;
  analyticsTabs.forEach(item => { const active=item===tab; item.classList.toggle('is-active',active); item.setAttribute('aria-selected',String(active)); item.tabIndex=active?0:-1; });
  analyticsPanels.forEach(panel => { panel.hidden=panel.dataset.analyticsPanel!==target; });
  drawAnalyticsPanel(target);
}
analyticsTabs.forEach((tab,index) => {
  tab.addEventListener('click',()=>activateAnalyticsTab(tab));
  tab.addEventListener('keydown',event=>{ if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return; event.preventDefault(); let next=index; if(event.key==='ArrowRight')next=(index+1)%analyticsTabs.length; if(event.key==='ArrowLeft')next=(index-1+analyticsTabs.length)%analyticsTabs.length; if(event.key==='Home')next=0; if(event.key==='End')next=analyticsTabs.length-1; analyticsTabs[next].focus(); activateAnalyticsTab(analyticsTabs[next]); });
});
drawAnalyticsPanel('features');
</script></body></html>
