<?php
require __DIR__ . '/theme_fixture.php';
if ($argv[1] !== 'create') exit;
$pdo->exec('DELETE FROM notifications');
$insert = $pdo->prepare("INSERT INTO notifications (notification_id,user_id,type,title,message,is_read,created_at) VALUES (?,?,?,?,?,FALSE,'2026-10-01 12:00:00')");
for ($id = 1; $id <= 27; $id++) {
    $insert->execute([$id, 1, $id <= 13 ? 'low_stock' : 'system', 'Notice ' . $id, 'Review this Store update.']);
}
$insert->execute([9000, 2, 'adjustment', 'Private notice', 'Another user only.']);

// Exercise retained surfaces with no purchasing schema, after upgrading old notices.
$insert->execute([9001, 1, 'replenishment', 'Retired purchasing approval', 'Obsolete request.']);
$migration = require __DIR__ . '/../../database/migrations/202610150002_retire_procurement_attention.php';
$migration['up']($pdo);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['purchase_order_items', 'purchase_orders', 'replenishment_requests', 'supplier_products', 'suppliers'] as $table) {
    $pdo->exec("DROP TABLE `{$table}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
