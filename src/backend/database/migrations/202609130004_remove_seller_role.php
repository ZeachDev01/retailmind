<?php

use App\Database\Schema;

return [
    'key' => '202609130004_remove_seller_role',
    'description' => 'Replace the duplicate seller role with cashier',
    'up' => static function (PDO $pdo): void {
        $sellerRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = 'seller' LIMIT 1")->fetchColumn();
        $cashierRoleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = 'cashier' LIMIT 1")->fetchColumn();

        if ($sellerRoleId <= 0 || $cashierRoleId <= 0) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE users SET role_id = ? WHERE role_id = ?');
            $stmt->execute([$cashierRoleId, $sellerRoleId]);

            $stmt = $pdo->prepare('DELETE FROM role_privileges WHERE role_id = ?');
            $stmt->execute([$sellerRoleId]);

            $stmt = $pdo->prepare('DELETE FROM roles WHERE role_id = ?');
            $stmt->execute([$sellerRoleId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    },
];
