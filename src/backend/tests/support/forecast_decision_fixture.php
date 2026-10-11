<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Disposable database required.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($argv[1] === 'cleanup') { $pdo->exec("DROP DATABASE IF EXISTS `{$database}`"); exit; }
if ($argv[1] === 'create') {
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `{$database}`; SET FOREIGN_KEY_CHECKS=0");
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../../sql/schema.sql'), $tables);
    foreach ($tables[0] as $ddl) $pdo->exec($ddl);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['202609250001_user_multi_roles', '202609260001_shared_database_backups'] as $key) {
        $migration = require __DIR__ . '/../../database/migrations/' . $key . '.php';
        $migration['up']($pdo);
    }
    $pdo->exec("INSERT INTO branches (branch_id,branch_name,branch_code) VALUES (1,'Decision Test Store','DECISION');
        INSERT INTO roles (role_id,role_name) VALUES (3,'inventory_manager')");
    $password = 'DecisionTest@2026';
    $pdo->prepare('INSERT INTO users (user_id,full_name,username,password_hash,role_id,branch_id,must_change_password) VALUES (3,?,?,?,?,1,0)')
        ->execute(['Decision Test Manager', 'decision_manager', password_hash($password, PASSWORD_DEFAULT), 3]);
    $pdo->exec("INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (3,3,1);
        INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id) VALUES (1,'DECISION-ITEM','DECISION-ITEM','Decision Test Item',25,3,1);
        INSERT INTO inventory (product_id,quantity_on_hand) VALUES (1,0)");
    echo json_encode(['password' => $password]);
    exit;
}
$pdo->exec("USE `{$database}`");
switch ($argv[1]) {
    case 'seed':
        $migration = require __DIR__ . '/../../database/migrations/202610100000_ml_tables.php';
        $migration['up']($pdo);
        // Match the legacy model metadata collation on MySQL 8's newer default.
        $pdo->exec('ALTER TABLE demand_forecasts MODIFY model_version VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $pdo->exec('ALTER TABLE forecast_decisions DROP FOREIGN KEY fk_forecast_decisions_forecast_job, DROP COLUMN forecast_job_id, MODIFY prediction_id INT NOT NULL;
            ALTER TABLE replenishment_requests DROP FOREIGN KEY fk_replenishment_requests_forecast_job, DROP COLUMN forecast_job_id');
        $pdo->exec("UPDATE inventory SET quantity_on_hand=0;
            INSERT INTO ml_jobs (job_id,trigger_type,status,queued_at,completed_at) VALUES
                (10,'forecast_generation','succeeded',NOW(),NOW());
            INSERT INTO demand_forecasts (job_id,product_id,forecast_origin,target_date,horizon_day,predicted_units,model_version,readiness_level)
                VALUES (10,1,CURDATE(),CURDATE()+INTERVAL 7 DAY,7,14,'decision-test','High');
            INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id) VALUES (2,'UNRELATED','UNRELATED','Unrelated legacy product',25,3,1);
            INSERT INTO forecast_runs (forecast_run_id,model_name,model_version,forecast_period_days) VALUES (100,'Random Forest','legacy-test',7);
            INSERT INTO stock_predictions (prediction_id,forecast_run_id,product_id,forecast_period_days,forecast_value,actual_demand)
                VALUES (100,100,2,7,8,6);
            INSERT INTO forecast_decisions (prediction_id,product_id,original_suggested_qty,final_quantity,decision,decided_by)
                VALUES (100,2,8,8,'accepted',3);
            INSERT INTO replenishment_requests (product_id,request_qty,requested_by,source,forecast_prediction_id,original_suggested_qty)
                VALUES (2,8,3,'ml_forecast',100,8)");
        break;
    case 'upgrade':
        $migration = require __DIR__ . '/../../database/migrations/202610120001_forecast_decision_source.php';
        $migration['up']($pdo);
        $migration['up']($pdo);
        break;
    case 'collision':
        $pdo->exec('INSERT INTO stock_predictions (prediction_id,forecast_run_id,product_id,forecast_period_days,forecast_value,actual_demand) VALUES (1,100,2,14,8,6)');
        break;
    case 'next_run':
        $pdo->exec("INSERT INTO ml_jobs (job_id,trigger_type,status,queued_at,completed_at) VALUES (11,'forecast_generation','succeeded',NOW(),NOW());
            INSERT INTO demand_forecasts (job_id,product_id,forecast_origin,target_date,horizon_day,predicted_units,model_version,readiness_level)
                VALUES (11,1,CURDATE(),CURDATE()+INTERVAL 7 DAY,7,21,'newer-run','High')");
        break;
    case 'fail':
        $pdo->exec("CREATE TRIGGER fail_forecast_decision BEFORE INSERT ON forecast_decisions FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected decision persistence failure'");
        break;
    case 'state':
        echo json_encode(['requests' => $pdo->query('SELECT * FROM replenishment_requests ORDER BY request_id')->fetchAll(PDO::FETCH_ASSOC),
            'decisions' => $pdo->query('SELECT * FROM forecast_decisions ORDER BY forecast_decision_id')->fetchAll(PDO::FETCH_ASSOC)]);
        break;
    default: throw new RuntimeException('Unknown fixture action.');
}
