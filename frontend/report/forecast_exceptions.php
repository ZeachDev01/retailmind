<?php
require_once __DIR__ . '/../../backend/includes/auth.php';
require_once __DIR__ . '/../../backend/includes/functions.php';
require_role(['admin', 'super_admin', 'inventory_manager']);

$metrics = get_ml_model_metrics();
$settings = get_ml_settings($pdo);
$wapeThreshold = (float)($settings['accuracy_threshold_wape'] ?? 35);
$staleDays = max(1, (int)($settings['retrain_frequency_days'] ?? 7));
$productMetricMap = [];
foreach (($metrics['product_metrics'] ?? []) as $metric) $productMetricMap[(int)$metric['product_id']] = $metric;

$predictions = $pdo->query("SELECT sp.*,p.sku,p.product_name,i.quantity_on_hand,
        COALESCE(s.units_30,0) units_30, COALESCE(s.nonzero_days,0) nonzero_days, s.first_sale_date
    FROM stock_predictions sp
    JOIN products p ON p.product_id=sp.product_id
    JOIN inventory i ON i.product_id=sp.product_id
    LEFT JOIN (
      SELECT si.product_id,
        SUM(CASE WHEN sa.sale_date >= DATE_SUB(NOW(),INTERVAL 30 DAY) THEN si.quantity ELSE 0 END) units_30,
        COUNT(DISTINCT CASE WHEN sa.sale_date >= DATE_SUB(NOW(),INTERVAL 90 DAY) THEN DATE(sa.sale_date) END) nonzero_days,
        MIN(sa.sale_date) first_sale_date
      FROM sale_items si JOIN sales sa ON sa.sale_id=si.sale_id GROUP BY si.product_id
    ) s ON s.product_id=sp.product_id
    WHERE sp.prediction_id=(SELECT sp2.prediction_id FROM stock_predictions sp2 WHERE sp2.product_id=sp.product_id ORDER BY sp2.generated_at DESC,sp2.prediction_id DESC LIMIT 1)
    ORDER BY sp.generated_at DESC,p.product_name")->fetchAll(PDO::FETCH_ASSOC);

$exceptions=[];
foreach($predictions as $row){
    $reasons=[];
    $confidence=(float)($row['confidence_score'] ?? 0);
    $pm=$productMetricMap[(int)$row['product_id']] ?? [];
    $wape=isset($pm['wape']) ? (float)$pm['wape'] : null;
    if($confidence<.50) $reasons[]='Low confidence';
    if($wape!==null && $wape>$wapeThreshold) $reasons[]='WAPE above threshold';
    if((int)$row['nonzero_days'] < (int)($settings['minimum_nonzero_sales_days'] ?? 5)) $reasons[]='Insufficient nonzero sales days';
    if((int)$row['predicted_demand_next_30_days'] > max(10,(int)$row['units_30']*2)) $reasons[]='Forecast spike versus recent demand';
    if((int)$row['units_30']===0 && (int)$row['predicted_demand_next_30_days']>0) $reasons[]='Forecast despite zero recent sales';
    if(strtotime((string)$row['generated_at']) < strtotime('-'.$staleDays.' days')) $reasons[]='Prediction is stale';
    if(!$reasons) continue;
    $row['exception_reasons']=$reasons;
    $row['product_wape']=$wape;
    $exceptions[]=$row;
}

$decisions=$pdo->query("SELECT fd.*,p.sku,p.product_name,u.full_name decided_by_name
    FROM forecast_decisions fd JOIN products p ON p.product_id=fd.product_id LEFT JOIN users u ON u.user_id=fd.decided_by
    ORDER BY fd.decided_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forecast Exceptions</title><link rel="stylesheet" href="<?=htmlspecialchars(app_url('assets/css/style.css'))?>"><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>"></head><body><div class="app-shell"><?php include __DIR__.'/../modules/sidebar.php';?><main class="main-content"><div class="topbar"><div><h1>Forecast Exceptions</h1><p class="page-subtitle">Products requiring human review before AI recommendations become replenishment requests.</p></div><div class="u-flex-wrap"><a class="btn" href="<?=htmlspecialchars(app_url('report/forecast_analytics.php'))?>">Analytics</a><a class="btn" href="<?=htmlspecialchars(app_url('modules/inventory_management/replenishment_requests.php'))?>">Review recommendations</a></div></div>
<div class="card-grid"><div class="stat-card"><div class="value"><?=count($exceptions)?></div><div class="label">Products needing review</div></div><div class="stat-card"><div class="value"><?=htmlspecialchars(number_format($wapeThreshold,0))?>%</div><div class="label">WAPE alert threshold</div></div><div class="stat-card"><div class="value"><?=htmlspecialchars(format_ml_metric($metrics,'wape'))?><?=isset($metrics['wape'])?'%':''?></div><div class="label">Current model WAPE</div></div><div class="stat-card"><div class="value"><?=!empty($metrics['model_beats_baseline'])?'Yes':(array_key_exists('model_beats_baseline',$metrics)?'No':'-')?></div><div class="label">Beats 7-day baseline</div></div></div>
<section class="dashboard-section"><h3>Exception queue</h3><div class="table-wrap"><table><tr><th>Product</th><th>Forecast 30d</th><th>Recent sales 30d</th><th>Confidence</th><th>WAPE</th><th>Generated</th><th>Reasons</th></tr><?php foreach($exceptions as $row):?><tr><td><?=htmlspecialchars($row['sku'].' - '.$row['product_name'])?></td><td><?= (int)$row['predicted_demand_next_30_days'] ?></td><td><?= (int)$row['units_30'] ?></td><td><?=number_format((float)$row['confidence_score']*100,0)?>%</td><td><?= $row['product_wape']===null?'-':number_format((float)$row['product_wape'],2).'%' ?></td><td><?=htmlspecialchars($row['generated_at'])?></td><td><?php foreach($row['exception_reasons'] as $reason):?><span class="reason"><?=htmlspecialchars($reason)?></span><?php endforeach;?></td></tr><?php endforeach;?><?php if(!$exceptions):?><tr><td colspan="7">No current forecast exceptions.</td></tr><?php endif;?></table></div></section>
<section class="dashboard-section"><h3>Recent human decisions</h3><div class="table-wrap"><table><tr><th>Date</th><th>Product</th><th>Decision</th><th>Suggested</th><th>Final</th><th>Reason</th><th>By</th></tr><?php foreach($decisions as $row):?><tr><td><?=htmlspecialchars($row['decided_at'])?></td><td><?=htmlspecialchars($row['sku'].' - '.$row['product_name'])?></td><td><?=htmlspecialchars(ucfirst($row['decision']))?></td><td><?= (int)$row['original_suggested_qty'] ?></td><td><?= (int)$row['final_quantity'] ?></td><td><?=htmlspecialchars($row['override_reason'] ?: '-')?></td><td><?=htmlspecialchars($row['decided_by_name'] ?? 'Unknown')?></td></tr><?php endforeach;?><?php if(!$decisions):?><tr><td colspan="7">No forecast decisions recorded yet.</td></tr><?php endif;?></table></div></section>
</main></div></body></html>
