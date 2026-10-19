<?php
if (str_starts_with($argv[1] ?? '', 'bread_')) {
    require_once __DIR__ . '/../../bootstrap/app.php';
    require_once __DIR__ . '/../../app/Services/NotificationService.php';
    require_once __DIR__ . '/../../app/Services/SupplierPulloutService.php';
    set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error); exit(1); });
    $database = $argv[2] ?? '';
    if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Disposable database required.');
    $config = require __DIR__ . '/../../config/database.php';
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone = '+08:00'");
    if ($argv[1] === 'bread_create') {
        $pdo->exec("INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id,warning_lead_time_days) VALUES (901,'REMINDER-BREAD','REMINDER-BREAD','Reminder Bread',25,3,1,2)");
        $pdo->exec('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (901,10)');
        $pdo->exec("INSERT INTO product_batches (batch_id,product_id,batch_number,quantity,remaining_quantity,expiration_date) VALUES (901,901,'COLLECT-10',10,10,'2000-10-25')");
        $clock = new class implements \App\Attention\Clock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2000-10-26T00:00:00+08:00'); }
        };
        (new NotificationService($pdo, $clock))->checkAndNotifyExpiringStock(30);
    } else {
        (new SupplierPulloutService($pdo, null, 'inventory_manager'))->recordPullout([
            'product_id' => 901, 'batch_id' => 901, 'quantity' => $argv[1] === 'bread_partial' ? 8 : 2, 'reason' => 'Supplier collection',
        ], 3);
    }
    exit;
}
require __DIR__ . '/theme_fixture.php';
if ($argv[1] !== 'create') exit;
$pdo->exec('DELETE FROM notifications');
$product = $pdo->prepare('INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,branch_id) VALUES (?,?,?,?,1,1,1)');
$insert = $pdo->prepare("INSERT INTO notifications (notification_id,user_id,type,title,message,reference_type,reference_id,is_read,created_at) VALUES (?,?,?,?,?,?,?,FALSE,'2026-10-01 12:00:00')");
for ($id = 1; $id <= 27; $id++) {
    if ($id <= 13) {
        $product->execute([1000 + $id, 'NOTICE-' . $id, 'NOTICE-' . $id, 'Notice ' . $id]);
        $pdo->exec('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (' . (1000 + $id) . ',100)');
    }
    $insert->execute([$id, 1, $id <= 13 ? 'low_stock' : 'system', $id <= 13 ? 'Low Stock Alert: Outdated name' : 'Notice ' . $id, 'Review this Store update.', $id <= 13 ? 'product' : null, $id <= 13 ? 1000 + $id : null]);
}
$insert->execute([9000, 2, 'adjustment', 'Private notice', 'Another user only.', null, null]);

// Exercise retained surfaces with no purchasing schema, after upgrading old notices.
$insert->execute([9001, 1, 'replenishment', 'Retired purchasing approval', 'Obsolete request.', null, null]);
$migration = require __DIR__ . '/../../database/migrations/202610150002_retire_procurement_attention.php';
$migration['up']($pdo);

// Deleted-product alerts must not affect counts, category options, or pagination.
$insert->execute([9100, 1, 'low_stock', 'Low Stock Alert: Deleted test product', 'Current: 6, Threshold: 10', 'product', 999999]);
$insert->execute([9101, 2, 'low_stock', 'Low Stock Alert: Deleted private product', 'Current: 6, Threshold: 10', 'product', 999999]);
