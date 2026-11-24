<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Disposable database required.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($argv[1] === 'unapprove') { $pdo->exec("DELETE FROM platform_settings WHERE setting_key='ml_approved_model_version'"); exit; }
if ($argv[1] === 'approve_unrelated') { $pdo->exec("INSERT INTO platform_settings (setting_key,setting_value,updated_at) VALUES ('ml_approved_model_version','unrelated_approved','2023-01-01 00:00:00')"); exit; }
if ($argv[1] === 'setup') {
    $evaluation = ['mae'=>9, 'rmse'=>10, 'wape'=>0.9, 'baseline_mae'=>2, 'baseline_rmse'=>3, 'baseline_wape'=>0.2, 'candidate_gate_outcome'=>'failed', 'missing_metric_reason'=>null];
    $metrics = ['outcome'=>'failed', 'metadata'=>['job_id'=>12, 'dataset_kind'=>'demo', 'evidence_label'=>'External Demo: M5 original daily sales; not Store accuracy', 'data_window_start'=>'2014-03-01', 'data_window_end'=>'2016-05-22', 'held_out_daily'=>[['product_id'=>1, 'forecast_origin'=>'2016-04-24', 'target_date'=>'2016-04-25', 'actual_units'=>17, 'predicted_units'=>28, 'baseline_units'=>16]]], 'evaluations'=>[7=>$evaluation,14=>$evaluation,28=>$evaluation]];
    $insert = $pdo->prepare("INSERT INTO model_training_runs (model_name,model_version,status,metrics_json) VALUES ('Random Forest',?,'completed',?)");
    $insert->execute(['candidate_store', '{}']);
    $store = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO ml_jobs (trigger_type,status,queued_at,completed_at) VALUES ('forecast_generation','succeeded',NOW(),NOW())");
    $job = (int)$pdo->lastInsertId();
    $forecast = $pdo->prepare("INSERT INTO demand_forecasts (job_id,product_id,forecast_origin,target_date,horizon_day,predicted_units,model_version,readiness_level) VALUES (?,1,CURDATE()-INTERVAL (? + 1) DAY,CURDATE()-INTERVAL ? DAY,1,3,'candidate_store','High')");
    for ($day = 0; $day < 25; $day++) $forecast->execute([$job, $day, $day]);
    $metrics['metadata']['held_out_daily'][] = ['product_id'=>1,'forecast_origin'=>'2016-04-24','target_date'=>'2016-04-26','actual_units'=>0,'predicted_units'=>2,'baseline_units'=>0];
    $metrics['metadata']['held_out_daily'][] = ['product_id'=>1,'forecast_origin'=>'2016-05-01','target_date'=>'2016-05-02','actual_units'=>4,'predicted_units'=>3,'baseline_units'=>2];
    $metrics['metadata']['held_out_daily'][] = ['product_id'=>2,'forecast_origin'=>'2016-04-24','target_date'=>'2016-04-25','actual_units'=>5,'predicted_units'=>5,'baseline_units'=>4];
    for ($day = 1; $day <= 25; $day++) {
        $metrics['metadata']['held_out_daily'][] = ['product_id'=>3,'forecast_origin'=>'2016-03-31','target_date'=>sprintf('2016-04-%02d', $day),'actual_units'=>$day,'predicted_units'=>$day+1,'baseline_units'=>$day-1];
    }
    // Distinct origins for the same target date must survive pagination and search.
    $metrics['metadata']['held_out_daily'][] = ['product_id'=>3,'forecast_origin'=>'2016-03-30','target_date'=>'2016-04-01','actual_units'=>1,'predicted_units'=>4,'baseline_units'=>0];
    $metrics['metadata']['feature_importance'] = ['lag_7'=>0.6,'lag_1'=>0.4];
    $insert->execute(['candidate_failed', json_encode($metrics)]);
    $failed = (int)$pdo->lastInsertId();
    $insert->execute(['candidate_missing', '{}']);
    $missing = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO platform_settings (setting_key,setting_value,updated_at) VALUES ('ml_approved_model_version','unrelated_approved','2023-01-01 00:00:00') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $pdo->exec("INSERT INTO ml_model_evaluations (model_version,horizon_days,mae,rmse,wape,baseline_mae,baseline_rmse,baseline_wape,candidate_gate_outcome,data_window_start,data_window_end) VALUES ('unrelated_approved',7,1234,1234,0.01,2,3,0.2,'passed','2016-04-25','2016-05-22')");
    echo json_encode(['failed'=>$failed,'missing'=>$missing,'store'=>$store]);
    exit;
}
$state=[];
foreach (['platform_settings','ml_jobs','model_training_runs','demand_forecasts','ml_model_evaluations','sales','inventory'] as $table) $state[$table]=$pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($state, JSON_THROW_ON_ERROR);
