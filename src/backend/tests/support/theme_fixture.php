<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
set_exception_handler(static function(Throwable $exception): void { fwrite(STDERR, (string)$exception); exit(1); });
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Invalid disposable database name.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($argv[1] === 'cleanup') { $pdo->exec("DROP DATABASE IF EXISTS `{$database}`"); exit; }
if (in_array($argv[1], ['reset_ready', 'reset_state', 'reset_failure', 'reset_allow', 'reset_fiscal_block', 'reset_fiscal_open', 'reset_forecast_lock'], true)) {
    $pdo->exec("USE `{$database}`");
    if ($argv[1] === 'reset_ready') {
        $pdo->exec("UPDATE cashier_shifts SET status = 'closed', closed_at = NOW(), closed_by = 1");
        $products = App\Database\ProductSeeder::loadProducts(__DIR__ . '/../../database/seeds/product_seed.csv');
        App\Database\ProductSeeder::run($pdo, $products);
        (new App\Database\DailySalesTrendSeeder($pdo))->run();
        $pdo->exec("INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id) VALUES (90000,'DELETE-TEST','DELETE-TEST','Unused deletion test',1,1,1)");
        $pdo->exec('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (90000,0)');
        $pdo->exec('INSERT INTO supplier_products (supplier_id,product_id) VALUES (1,90000)');
        $pdo->exec("INSERT INTO promotions (promotion_name,discount_type,discount_value,scope,product_id,starts_at,ends_at,created_by) VALUES ('Product reset test','fixed',1,'product',1,NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY),1),('General preserved test','fixed',1,'all',NULL,NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY),1)");
        $pdo->exec("INSERT INTO forecast_sales_imports (product_id,sale_date,quantity,imported_by) VALUES (1,'2026-01-01',5,1)");
        $pdo->exec("INSERT INTO model_training_runs (model_name,model_version,status) VALUES ('Random Forest','rf-v2','completed')");
        $pdo->exec("INSERT INTO forecast_runs (model_name,model_version,forecast_period_days) VALUES ('Random Forest','rf-v2',30)");
        $runId = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO stock_predictions (forecast_run_id,product_id,forecast_period_days,forecast_value) VALUES ({$runId},1,30,10)");
        $pdo->exec("INSERT INTO activity_log (user_id,action,category,module) VALUES (1,'Preserve reset audit sentinel','security','Authentication')");
        $pdo->exec("INSERT INTO fiscal_periods (period_name,start_date,end_date,status,created_by) VALUES ('Reset Test Period','2026-01-01','2026-12-31','open',1)");
    } elseif ($argv[1] === 'reset_failure') {
        $pdo->exec('CREATE TABLE reset_failure_probe (id INT PRIMARY KEY, sale_id INT NOT NULL, FOREIGN KEY (sale_id) REFERENCES sales(sale_id)) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO reset_failure_probe SELECT 1, MIN(sale_id) FROM sales');
    } elseif ($argv[1] === 'reset_allow') {
        $pdo->exec('DROP TABLE reset_failure_probe');
    } elseif ($argv[1] === 'reset_fiscal_block') {
        $pdo->exec("UPDATE fiscal_periods SET status = 'closed'");
    } elseif ($argv[1] === 'reset_fiscal_open') {
        $pdo->exec("UPDATE fiscal_periods SET status = 'open'");
    } elseif ($argv[1] === 'reset_forecast_lock') {
        fclose(App\Backup\RecoveryStore::exclusive());
        if ((int)$pdo->query("SELECT GET_LOCK('retailmind_forecast_pipeline', 0)")->fetchColumn() !== 1) throw new RuntimeException('Could not acquire test forecast lock.');
        echo "LOCKED\n";
        fflush(STDOUT);
        fgets(STDIN);
        $pdo->query("SELECT RELEASE_LOCK('retailmind_forecast_pipeline')");
    } else {
        $state = [];
        foreach (App\Services\OperationalDataResetService::TABLES as $table) {
            $state[$table] = App\Database\Schema::tableExists($pdo, $table) ? (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() : 0;
        }
        $state['promotions'] = (int)$pdo->query('SELECT COUNT(*) FROM promotions WHERE product_id IS NOT NULL')->fetchColumn();
        $state['general_promotions'] = (int)$pdo->query('SELECT COUNT(*) FROM promotions WHERE product_id IS NULL')->fetchColumn();
        $state['inventory_units'] = (int)$pdo->query('SELECT SUM(quantity_on_hand) FROM inventory')->fetchColumn();
        foreach (['products', 'users', 'categories', 'suppliers', 'roles', 'registers', 'fiscal_periods'] as $table) {
            $state[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }
        $state['preserved_audit'] = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Preserve reset audit sentinel'")->fetchColumn();
        $state['reset_audits'] = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Operational data reset' AND category = 'recovery'")->fetchColumn();
        echo json_encode($state);
    }
    exit;
}
if ($argv[1] === 'reports') {
    $pdo->exec("USE `{$database}`");
    $product = $pdo->prepare('INSERT IGNORE INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id) VALUES (?,?,?,?,?,?,1)');
    for ($id = 100; $id < 165; $id++) {
        $product->execute([$id, 'REPORT-' . $id, 'REPORT-' . $id, 'Report pagination item ' . $id, 25, 3]);
        $pdo->exec("INSERT IGNORE INTO inventory (product_id,quantity_on_hand) VALUES ({$id},10)");
    }
    $pdo->exec('INSERT IGNORE INTO purchase_order_items (purchase_order_item_id,purchase_order_id,product_id,ordered_qty,unit_cost) VALUES (100,1,1,5,3)');
    $pdo->exec("INSERT IGNORE INTO forecast_runs (forecast_run_id,model_name,model_version,forecast_period_days) VALUES (100,'Random Forest','theme-test',7)");
    $pdo->exec('INSERT IGNORE INTO stock_predictions (prediction_id,forecast_run_id,product_id,forecast_period_days,forecast_value,actual_demand) VALUES (100,100,1,7,8,6)');
    $pdo->exec("INSERT IGNORE INTO model_training_runs (training_run_id,model_name,model_version,status,metrics_json) VALUES (100,'Random Forest','theme-test','completed','{\"wape\":12,\"mean_absolute_error\":2}'),(101,'Random Forest','theme-test','completed','{\"wape\":10,\"mean_absolute_error\":1}')");
    exit;
}
if ($argv[1] === 'responsive') {
    $pdo->exec("USE `{$database}`");
    for ($index = 0; $index < 6; $index++) {
        $pdo->exec("INSERT INTO notifications (user_id,type,title,message) VALUES (4,'system','Inventory review needed','Review the available stock and contact your Administrator if an item needs attention.')");
    }
    exit;
}
if ($argv[1] === 'inventory_state') {
    $pdo->exec("USE `{$database}`");
    $state = [];
    foreach (['products', 'inventory', 'suppliers', 'supplier_products', 'replenishment_requests', 'purchase_orders', 'purchase_order_items', 'stock_movements', 'inventory_counts', 'inventory_adjustments', 'stock_predictions', 'forecast_runs', 'forecast_decisions', 'stock_receiving', 'promotions'] as $table) {
        $state[$table] = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($state); exit;
}
if ($argv[1] === 'governance_state') {
    $pdo->exec("USE `{$database}`");
    $state = [];
    foreach (['registers', 'attention_settings', 'platform_settings', 'store_settings', 'ml_settings', 'user_roles', 'fiscal_periods'] as $table) {
        $state[$table] = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    }
    $state['users'] = $pdo->query('SELECT user_id,username,full_name,password_hash,role_id,status,must_change_password FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($state); exit;
}
if ($argv[1] === 'state') {
    $pdo->exec("USE `{$database}`");
    echo json_encode(['users'=>$pdo->query('SELECT user_id,theme_preference,must_change_password FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC),
        'shift'=>$pdo->query('SELECT status,locked_at FROM cashier_shifts WHERE shift_id=1')->fetch(PDO::FETCH_ASSOC),
        'sales'=>(int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn(),
        'stock'=>(int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn()]); exit;
}
if ($argv[1] === 'cashier_state') {
    $pdo->exec("USE `{$database}`");
    $state = [];
    foreach (['cashier_shifts', 'cash_drawer_movements', 'held_sales', 'sales', 'sale_items', 'sale_receipt_details', 'cash_refunds', 'cash_refund_items', 'refund_receipt_details', 'inventory', 'inventory_adjustments', 'stock_movements'] as $table) {
        $state[$table] = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($state); exit;
}
if ($argv[1] === 'receipts') {
    $pdo->exec("USE `{$database}`");
    require_once __DIR__ . '/../../app/Services/CashRefundService.php';
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO sales (sale_id,cashier_id,shift_id,total_amount,payment_method) VALUES (2,4,1,25,'cash'),(3,4,1,25,'cash'),(4,1,NULL,25,'cash')");
    $pdo->exec('INSERT INTO sale_items (sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES (2,2,1,1,25,25),(3,3,1,1,25,25),(4,4,1,1,25,25)');
    (new App\Receipts\ReceiptDetailsService($pdo))->preserveSale(2);
    $pdo->exec("INSERT INTO cash_refunds (refund_id,sale_id,shift_id,cashier_id,refund_amount,reason) VALUES (1,2,1,4,25,'customer_return'),(2,4,1,1,25,'customer_return')");
    $pdo->exec('INSERT INTO cash_refund_items (refund_id,sale_item_id,product_id,quantity,unit_price,subtotal) VALUES (1,2,1,1,25,25),(2,4,1,1,25,25)');
    (new App\Receipts\RefundReceiptService($pdo))->preserve(1);
    (new App\Receipts\RefundReceiptService($pdo))->preserve(2);
    $pdo->commit();
    $pdo->exec("UPDATE products SET product_name='Edited live product' WHERE product_id=1");
    $pdo->exec("UPDATE registers SET name='Edited live Register' WHERE register_id=1");
    $pdo->exec("INSERT INTO store_settings (setting_key,setting_value) VALUES ('store_name','Edited live Store'),('receipt_footer','Edited live footer') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    exit;
}
if (in_array($argv[1], ['paper58', 'paper80'], true)) {
    $pdo->exec("USE `{$database}`");
    $pdo->exec("UPDATE registers SET paper_width_mm='" . ($argv[1] === 'paper58' ? '58' : '80') . "' WHERE register_id=1");
    exit;
}
if ($argv[1] === 'gates') {
    $pdo->exec("USE `{$database}`");
    $pdo->exec('UPDATE users SET must_change_password=1 WHERE user_id=1');
    $pdo->exec('UPDATE cashier_shifts SET locked_at=CURRENT_TIMESTAMP WHERE shift_id=1'); exit;
}
$pdo->exec("CREATE DATABASE `{$database}`");
$pdo->exec("USE `{$database}`");
preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../../database/sql/schema.sql'), $tables);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables[0] as $ddl) $pdo->exec($ddl);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$rolesMigration = require __DIR__ . '/../../database/migrations/202609250001_user_multi_roles.php';
$rolesMigration['up']($pdo);
$backupMigration = require __DIR__ . '/../../database/migrations/202609260001_shared_database_backups.php';
$forecastImportMigration = require __DIR__ . '/../../database/migrations/202610100001_forecast_history_import.php';
$forecastImportMigration['up']($pdo);
$backupMigration['up']($pdo);
$fresh = $pdo->query("SHOW COLUMNS FROM users LIKE 'theme_preference'")->fetch(PDO::FETCH_ASSOC);
$pdo->exec("INSERT INTO branches (branch_id,branch_name,branch_code) VALUES (1,'Theme Test Store','THEME')");
$password = 'ThemeTest@2026';
$roles = ['admin','super_admin','inventory_manager','cashier'];
foreach ($roles as $index => $role) {
    $id = $index + 1;
    $pdo->prepare('INSERT INTO roles (role_id,role_name) VALUES (?,?)')->execute([$id,$role]);
    $pdo->prepare('INSERT INTO users (user_id,full_name,username,password_hash,role_id,branch_id,must_change_password) VALUES (?,?,?,?,?,1,0)')
        ->execute([$id,'Theme Test ' . $role,'theme_' . $role,password_hash($password,PASSWORD_DEFAULT),$id]);
    if ($pdo->query('SELECT theme_preference FROM users WHERE user_id=' . $id)->fetchColumn() !== 'system') {
        throw new RuntimeException('Fresh accounts must default to System.');
    }
    $pdo->prepare('INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (?,?,1)')->execute([$id,$id]);
}
$pdo->exec('ALTER TABLE users DROP COLUMN theme_preference');
$migration = require __DIR__ . '/../../database/migrations/202610040001_user_theme.php';
$migration['up']($pdo); $migration['up']($pdo);
$upgraded = $pdo->query("SHOW COLUMNS FROM users LIKE 'theme_preference'")->fetch(PDO::FETCH_ASSOC);
if ($fresh !== $upgraded) throw new RuntimeException('Fresh/upgrade appearance storage differs.');
if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE theme_preference='system'")->fetchColumn() !== count($roles)) {
    throw new RuntimeException('Existing accounts must default to System after upgrade.');
}
$pdo->exec("INSERT INTO registers (register_id,name) VALUES (1,'Theme Register')");
$pdo->exec('INSERT INTO cashier_shifts (shift_id,cashier_id,register_id,opening_cash) VALUES (1,4,1,100)');
$pdo->prepare('INSERT INTO held_sales (cashier_id,shift_id,reference_no,customer_label,cart_json,item_count,total_amount) VALUES (4,1,?,?,?,?,?)')
    ->execute(['THEME-HELD', 'Unfinished theme customer', json_encode([1 => ['qty' => 1, 'name' => 'Theme Test Item', 'price' => 25]]), 1, 25]);
$pdo->exec("INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id) VALUES (1,'THEME-ITEM','THEME-BARCODE','Theme Test Item',25,3,1)");
$pdo->exec('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (1,10)');
$pdo->exec("INSERT INTO suppliers (supplier_id,supplier_name,created_by) VALUES (1,'Theme Test Supplier',3)");
$pdo->exec('INSERT INTO supplier_products (supplier_id,product_id) VALUES (1,1)');
$pdo->exec("INSERT INTO inventory_adjustments (product_id,adjustment_qty,adjustment_type,reported_by,shift_id,reason) VALUES (1,1,'damaged',4,1,'Disposable theme Stock Issue')");
$pdo->exec("INSERT INTO purchase_orders (po_number,supplier_id,status,created_by) VALUES ('THEME-PO',1,'draft',3)");
$pdo->exec("INSERT INTO replenishment_requests (product_id,request_qty,requested_by,status,approved_by) VALUES (1,5,3,'approved',1)");
$pdo->prepare("INSERT INTO emergency_access_sessions (actor_user_id,reason,activated_at,expires_at,duration_minutes,status) VALUES (2,'Disposable appearance coverage',?,?,30,'active')")
    ->execute([date('Y-m-d H:i:s'),date('Y-m-d H:i:s',time()+1800)]);
App\Store\StoreWriteGate::begin($pdo); $pdo->commit();
$pdo->exec("INSERT INTO sales (sale_id,cashier_id,shift_id,total_amount,payment_method) VALUES (1,4,1,25,'cash')");
$pdo->exec('INSERT INTO sale_items (sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES (1,1,1,1,25,25)');
$pdo->beginTransaction(); (new App\Receipts\ReceiptDetailsService($pdo))->preserveSale(1); $pdo->commit();
echo json_encode(['database'=>$database,'password'=>$password]);
