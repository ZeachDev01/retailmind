<?php
if (in_array($argv[1] ?? '', ['disable_product', 'enable_product'], true)) {
    require_once __DIR__ . '/../../bootstrap/app.php';
    $database = $argv[2] ?? '';
    if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Disposable database required.');
    $config = require __DIR__ . '/../../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->prepare('UPDATE products SET status = ? WHERE product_id = 1')->execute([$argv[1] === 'disable_product' ? 'inactive' : 'active']);
    exit;
}
require __DIR__ . '/theme_fixture.php';
if ($argv[1] !== 'create') return;
$pdo->exec("INSERT INTO fiscal_periods (period_name,start_date,end_date,status,created_by) VALUES ('Inventory recovery test','2026-01-01','2099-12-31','open',1)");
$pdo->exec("INSERT INTO users (username,password_hash,full_name,email,role_id,status,branch_id) SELECT 'theme_inventory_other', password_hash, 'Other Inventory Manager', 'other-inventory@example.test', role_id, status, branch_id FROM users WHERE user_id = 3");

$pdo->exec("INSERT INTO categories (category_name) VALUES ('Recovery test category')");
