<?php

return [
    'key' => '202609130003_superadmin_inventory_read_only',
    'description' => 'Make super administrator inventory access read-only',
    'up' => static function (PDO $pdo): void {
        $stmt = $pdo->prepare(
            "DELETE rp FROM role_privileges rp
             JOIN roles r ON r.role_id = rp.role_id
             JOIN privileges p ON p.privilege_id = rp.privilege_id
             WHERE r.role_name = 'super_admin' AND p.privilege_key = 'manage_inventory'"
        );
        $stmt->execute();
    },
];