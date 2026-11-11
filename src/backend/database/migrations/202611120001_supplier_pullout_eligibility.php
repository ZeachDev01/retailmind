<?php

use App\Database\Schema;

return [
    'key' => '202611120001_supplier_pullout_eligibility',
    'description' => 'Explicit Product eligibility for Supplier Pullouts, default off',
    'up' => static function (PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = $pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('supplier_pullout_eligible', $columns, true)) {
                $pdo->exec('ALTER TABLE products ADD COLUMN supplier_pullout_eligible INTEGER NOT NULL DEFAULT 0');
            }
        } else {
            Schema::addColumnIfMissing($pdo, 'products', 'supplier_pullout_eligible', 'TINYINT(1) NOT NULL DEFAULT 0');
        }
        // Legacy expiry alerts must not imply collection for Products that were never opted in.
        $hasNotifications = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'notifications'")->fetchColumn()
            : Schema::tableExists($pdo, 'notifications');
        if ($hasNotifications) {
            $alerts = $pdo->query("SELECT n.notification_id, n.type, n.workspace, p.product_name
                FROM notifications n JOIN product_batches pb ON pb.batch_id = n.reference_id
                JOIN products p ON p.product_id = pb.product_id
                WHERE p.supplier_pullout_eligible = 0
                  AND (n.reference_type = 'product_batch' OR n.reference_type LIKE 'product_batch_expiry_%')
                  AND n.type IN ('expiring_stock', 'expired_stock', 'system')")->fetchAll(PDO::FETCH_ASSOC);
            $update = $pdo->prepare('UPDATE notifications SET title = ?, attention_destination = ? WHERE notification_id = ?');
            foreach ($alerts as $alert) {
                $update->execute([($alert['type'] === 'expired_stock' ? 'Expired Stock Alert: ' : 'Expiry Alert: ') . $alert['product_name'],
                    'components/' . ($alert['workspace'] === 'admin' ? 'administrator' : 'inventory_management') . '/stock_issues.php', $alert['notification_id']]);
            }
        }
    },
];
