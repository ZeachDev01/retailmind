<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Invalid disposable database name.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("USE `{$database}`");
if ($argv[1] === 'receiving_code_overlap') {
    $pdo->exec("UPDATE products SET case_barcode=sku WHERE product_id=1");
}
if ($argv[1] === 'receiving_code_pack_overlap') {
    $pdo->exec("UPDATE products SET pack_barcode=sku,case_barcode='THEME-CASE' WHERE product_id=1");
}
if ($argv[1] === 'receiving_code_unit_overlap') {
    $pdo->exec("UPDATE products SET barcode=sku,case_barcode='THEME-CASE',pack_barcode='THEME-PACK' WHERE product_id=1");
}
if ($argv[1] === 'receiving_code_legacy') {
    $pdo->exec("UPDATE products SET barcode='THEME-BARCODE',case_barcode='THEME-BARCODE' WHERE product_id=1");
}
if ($argv[1] === 'receiving_code_multiple') {
    $pdo->exec("UPDATE products SET case_barcode='UI-BARCODE-0' WHERE product_id=1");
}
if ($argv[1] === 'receiving_code_reset') {
    $pdo->exec("UPDATE products SET barcode='THEME-BARCODE',status='active',case_barcode='THEME-CASE',pack_barcode='THEME-PACK' WHERE product_id=1");
}
if ($argv[1] === 'receiving_code_handoff_fixtures') {
    $pdo->exec("INSERT INTO users (user_id, first_name, display_name, username, password_hash, role_id, must_change_password)
        SELECT 5, 'Second', 'Second Inventory Manager', 'theme_second_manager', password_hash, role_id, 0 FROM users WHERE user_id=3");
    $pdo->exec('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (5,3,1)');
    $pdo->exec("INSERT INTO supplier_pullouts (product_id, batch_id, quantity, pulled_loose, reason, supplier, pulled_by)
        SELECT 1, batch_id, 1, 1, 'Fixture pullout', 'Original supplier', 3 FROM product_batches WHERE product_id=1 LIMIT 1");
}
if ($argv[1] === 'receiving_code_race') {
    require_once __DIR__ . '/../../app/Services/ProductService.php';
    $field = $argv[3];
    $categoryId = (int)$pdo->query('SELECT MIN(category_id) FROM categories')->fetchColumn();
    while (microtime(true) < (float)$argv[4]) usleep(1000);
    try {
        echo json_encode(['id' => (new ProductService($pdo))->createProduct([
            'product_name' => 'Concurrent code ' . $field, 'category_id' => $categoryId, 'base_unit' => 'piece',
            'cost_price' => '3', 'selling_price' => '9', $field => 'ISSUE-482-SAME-CODE',
        ], 3)]);
    } catch (Throwable $exception) {
        echo json_encode(['error' => $exception->getMessage()]);
    }
}
