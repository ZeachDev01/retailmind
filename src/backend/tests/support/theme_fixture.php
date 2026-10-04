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
if ($argv[1] === 'state') {
    $pdo->exec("USE `{$database}`");
    echo json_encode(['users'=>$pdo->query('SELECT user_id,theme_preference,must_change_password FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC),
        'shift'=>$pdo->query('SELECT status,locked_at FROM cashier_shifts WHERE shift_id=1')->fetch(PDO::FETCH_ASSOC),
        'sales'=>(int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn(),
        'stock'=>(int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn()]); exit;
}
if ($argv[1] === 'gates') {
    $pdo->exec("USE `{$database}`");
    $pdo->exec('UPDATE users SET must_change_password=1 WHERE user_id=1');
    $pdo->exec('UPDATE cashier_shifts SET locked_at=CURRENT_TIMESTAMP WHERE shift_id=1'); exit;
}
$pdo->exec("CREATE DATABASE `{$database}`");
$pdo->exec("USE `{$database}`");
preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../../sql/schema.sql'), $tables);
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
$pdo->exec("INSERT INTO products (product_id,sku,product_name,unit_price,branch_id) VALUES (1,'THEME-ITEM','Theme Test Item',25,1)");
$pdo->exec('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (1,10)');
$pdo->exec("UPDATE products SET barcode='THEME-BARCODE' WHERE product_id=1");
$pdo->exec("INSERT INTO suppliers (supplier_id,supplier_name,created_by) VALUES (1,'Theme Test Supplier',3)");
$pdo->exec('INSERT INTO supplier_products (supplier_id,product_id) VALUES (1,1)');
$pdo->prepare("INSERT INTO emergency_access_sessions (actor_user_id,reason,activated_at,expires_at,duration_minutes,status) VALUES (2,'Disposable appearance coverage',?,?,30,'active')")
    ->execute([date('Y-m-d H:i:s'),date('Y-m-d H:i:s',time()+1800)]);
App\Store\StoreWriteGate::begin($pdo); $pdo->commit();
$pdo->exec("INSERT INTO sales (sale_id,cashier_id,shift_id,total_amount,payment_method) VALUES (1,4,1,25,'cash')");
$pdo->exec('INSERT INTO sale_items (sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES (1,1,1,1,25,25)');
$pdo->beginTransaction(); (new App\Services\ReceiptDetailsService($pdo))->preserveSale(1); $pdo->commit();
echo json_encode(['database'=>$database,'password'=>$password]);
