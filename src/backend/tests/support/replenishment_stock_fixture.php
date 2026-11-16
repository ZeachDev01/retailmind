<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (in_array($argv[1] ?? '', ['create', 'cleanup'], true)) {
    require __DIR__ . '/theme_fixture.php';
    exit;
}
require_once __DIR__ . '/../../bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Disposable database required.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
switch ($argv[1]) {
    case 'stock_forms':
        $pdo->exec("UPDATE products SET base_unit='piece', pack_size=12, reorder_level=30,
                safety_stock=5, minimum_order_quantity=1, units_per_package=24 WHERE product_id=1;
            UPDATE inventory SET quantity_on_hand=42 WHERE product_id=1;
            INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,base_unit,pack_size,reorder_level)
                VALUES (2,'BOTTLES','BOTTLES','Bottles without packs',25,3,'bottle',NULL,10),
                    (3,'EXPIRED-ONLY','EXPIRED-ONLY','Expired-only packs',25,3,'piece',12,10);
            INSERT INTO inventory (product_id,quantity_on_hand) VALUES (2,8),(3,13);
            INSERT INTO product_batches (product_id,quantity,remaining_quantity,sealed_quantity,loose_quantity,expiration_date)
                VALUES (1,29,29,1,17,NULL),(1,13,13,1,1,'2000-01-01'),
                    (2,8,8,0,8,CURRENT_DATE),(3,13,13,1,1,'2000-01-01')");
        break;
    case 'state':
        echo json_encode([
            'inventory' => $pdo->query('SELECT * FROM inventory ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC),
            'batches' => $pdo->query('SELECT * FROM product_batches ORDER BY batch_id')->fetchAll(PDO::FETCH_ASSOC),
        ]);
        break;
    default: throw new RuntimeException('Unknown fixture action.');
}
