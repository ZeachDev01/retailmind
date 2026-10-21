<?php
if (str_starts_with($argv[1] ?? '', 'bread_') || str_starts_with($argv[1] ?? '', 'workspace_')) {
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
            public function now(): DateTimeImmutable { return new DateTimeImmutable('now'); }
        };
        (new NotificationService($pdo, $clock))->checkAndNotifyExpiringStock(30);
    } elseif (str_starts_with($argv[1], 'workspace_')) {
        // #262 Workspace-scoped coverage. User 2 holds super_admin plus the admin,
        // inventory_manager, and cashier secondary roles (see theme_fixture.php).
        if ($argv[1] === 'workspace_seed') {
            $pdo->exec('DELETE FROM notifications WHERE user_id = 2');
            $insert = $pdo->prepare("INSERT INTO notifications (notification_id,user_id,type,title,message,workspace,is_read,created_at) VALUES (?,2,?,?,'Disposable Workspace fixture.',?,?,?)");
            foreach ([
                [9300, 'system', 'Super admin notice', 'super_admin', 0, '2026-09-01 09:00:00'],
                [9310, 'adjustment', 'Admin adjustment notice', 'admin', 0, '2026-09-02 09:00:00'],
                [9311, 'system', 'Admin system notice', 'admin', 1, '2026-09-03 09:00:00'],
                [9320, 'system', 'Inventory notice one', 'inventory_manager', 0, '2026-09-04 09:00:00'],
                [9321, 'system', 'Inventory notice two', 'inventory_manager', 0, '2026-09-05 09:00:00'],
                [9330, 'adjustment', 'Cashier notice', 'cashier', 0, '2026-09-06 09:00:00'],
            ] as $row) $insert->execute($row);
        } elseif ($argv[1] === 'workspace_revoke_role') {
            $pdo->exec('DELETE FROM user_roles WHERE user_id = 2 AND role_id = 3');
        } elseif ($argv[1] === 'workspace_restore_role') {
            $pdo->exec('INSERT IGNORE INTO user_roles (user_id, role_id, is_primary) VALUES (2, 3, 0)');
        } elseif ($argv[1] === 'workspace_disable') {
            $pdo->exec("UPDATE users SET status = 'disabled' WHERE user_id = 2");
        } elseif ($argv[1] === 'workspace_enable') {
            $pdo->exec("UPDATE users SET status = 'active' WHERE user_id = 2");
        } else {
            throw new RuntimeException('Unknown workspace fixture action: ' . $argv[1]);
        }
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
$insert = $pdo->prepare("INSERT INTO notifications (notification_id,user_id,type,title,message,reference_type,reference_id,workspace,is_read,created_at) VALUES (?,?,?,?,?,?,?,'inventory_manager',FALSE,'2026-10-01 12:00:00')");
for ($id = 1; $id <= 27; $id++) {
    if ($id <= 13) {
        $product->execute([1000 + $id, 'NOTICE-' . $id, 'NOTICE-' . $id, 'Notice ' . $id]);
        $pdo->exec('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (' . (1000 + $id) . ',100)');
    }
    $insert->execute([$id, 3, $id <= 13 ? 'low_stock' : 'system', $id <= 13 ? 'Low Stock Alert: Outdated name' : 'Notice ' . $id, 'Review this Store update.', $id <= 13 ? 'product' : null, $id <= 13 ? 1000 + $id : null]);
}
$insert->execute([9000, 2, 'adjustment', 'Private notice', 'Another user only.', null, null]);
$pdo->exec("UPDATE notifications SET workspace='super_admin' WHERE notification_id=9000");

// Exercise retained surfaces with no purchasing schema, after upgrading old notices.
$insert->execute([9001, 1, 'replenishment', 'Retired purchasing approval', 'Obsolete request.', null, null]);
$migration = require __DIR__ . '/../../database/migrations/202610150002_retire_procurement_attention.php';
$migration['up']($pdo);

// Deleted-product alerts must not affect counts, category options, or pagination.
$insert->execute([9100, 3, 'low_stock', 'Low Stock Alert: Deleted test product', 'Current: 6, Threshold: 10', 'product', 999999]);
$insert->execute([9101, 2, 'low_stock', 'Low Stock Alert: Deleted private product', 'Current: 6, Threshold: 10', 'product', 999999]);
