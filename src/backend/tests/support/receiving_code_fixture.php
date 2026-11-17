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
