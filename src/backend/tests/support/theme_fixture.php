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
