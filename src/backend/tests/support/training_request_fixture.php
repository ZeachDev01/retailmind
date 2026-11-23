<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Disposable database required.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$service = new App\Services\MlJobService($pdo);
$action = $argv[1];
if (($argv[4] ?? '') === 'barrier') { echo "ready\n"; flush(); if (trim(fgets(STDIN)) !== 'go') throw new RuntimeException('Barrier failed.'); }
if ($action === 'ready') {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['sales', 'sale_items', 'stock_movements', 'store_calendar', 'store_weekly_schedule', 'scheduled_demand_drivers', 'forecast_sales_imports', 'ml_jobs', 'model_training_runs'] as $table) $pdo->exec("DELETE FROM `$table`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO products (product_id,sku,product_name,base_unit,cost_price,unit_price,status) VALUES (2,'NOT-READY','Not ready','piece',1,2,'active') ON DUPLICATE KEY UPDATE status='active'");
    $pdo->exec("INSERT INTO platform_settings (setting_key,setting_value) VALUES ('ml_max_wape_7d','0.20'),('ml_max_wape_14d','0.25'),('ml_max_wape_28d','0.30') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $insert = $pdo->prepare('INSERT INTO forecast_sales_imports (product_id,sale_date,quantity,imported_by) VALUES (1,?,5,1)');
    $end = new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Manila'));
    for ($day = 139; $day >= 0; $day--) $insert->execute([$end->modify("-$day days")->format('Y-m-d')]);
} elseif ($action === 'enqueue') {
    try { echo json_encode(['id' => $service->enqueue('model_retraining', 1, $argv[3] ?? 'admin')]); }
    catch (RuntimeException $e) { echo json_encode(['error' => $e->getMessage()]); }
} elseif ($action === 'system_enqueue') {
    try { echo json_encode(['id' => $service->enqueueSystem('model_retraining')]); }
    catch (RuntimeException $e) { echo json_encode(['error' => $e->getMessage()]); }
} elseif ($action === 'not_ready') {
    $pdo->exec('DELETE FROM forecast_sales_imports');
} elseif ($action === 'clear_jobs') {
    $pdo->exec('DELETE FROM ml_jobs');
} elseif ($action === 'fail_audit') {
    $pdo->exec("CREATE TRIGGER fail_training_request_audit BEFORE INSERT ON activity_log FOR EACH ROW BEGIN IF NEW.action='Enqueued ML Job' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture audit unavailable'; END IF; END");
} elseif ($action === 'allow_audit') {
    $pdo->exec('DROP TRIGGER fail_training_request_audit');
} elseif ($action === 'orphan_succeeded') {
    $pdo->exec("INSERT INTO ml_jobs (trigger_type,status,queued_at,completed_at) VALUES ('model_retraining','succeeded',NOW(),NOW())");
    echo json_encode((int)$pdo->lastInsertId());
} elseif ($action === 'fail_latest') {
    $pdo->exec("UPDATE ml_jobs SET status='failed',operator_message='Traceback: password=DO-NOT-EXPOSE',diagnostic_reference='ERR-TEST-502' ORDER BY job_id DESC LIMIT 1");
} elseif ($action === 'history') {
    echo json_encode($service->getRecentTrainingJobs());
} elseif ($action === 'state') {
    echo json_encode(['jobs' => $pdo->query('SELECT * FROM ml_jobs ORDER BY job_id')->fetchAll(PDO::FETCH_ASSOC),
        'audit' => $pdo->query("SELECT user_id,new_value,record_id FROM activity_log WHERE action='Enqueued ML Job' ORDER BY log_id")->fetchAll(PDO::FETCH_ASSOC)]);
}


