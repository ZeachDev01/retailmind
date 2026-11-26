<?php
// Historical receipts with fewer good units than delivered units.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';
$database = $argv[1] ?? '';
$token = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)
    || !preg_match('/^[a-z0-9]{4,12}$/', $token)) {
    throw new RuntimeException('Disposable receiving fixture required.');
}
$_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = $database;
$pdo = App\Core\Database::connection();
$pdo->prepare('UPDATE stock_receiving SET received_qty=18, accepted_qty=14, damaged_qty=4,
    pack_size_used=6, received_packs=3, accepted_packs=2, accepted_loose=2, damaged_loose=4
    WHERE invoice_number=?')->execute(['HISTORICAL-' . $token]);
$pdo->prepare('UPDATE stock_receiving sr JOIN products p ON p.product_id=sr.product_id
    JOIN stock_receiving_groups g ON g.group_id=sr.group_id
    SET sr.received_qty=48, sr.accepted_qty=47, sr.damaged_qty=1,
        sr.received_packages=2, sr.units_per_package_used=24
    WHERE g.invoice_number=? AND p.sku=?')->execute([$token . '-21', 'SCN' . $token . 'C']);
