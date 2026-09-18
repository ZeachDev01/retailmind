<?php

namespace App\Authorization;

final class RoleWorkspaceRouter
{
    public static function pathFor(?string $role): string
    {
        return match ($role) {
            'super_admin' => 'components/super_administrator/dashboard.php',
            'admin' => 'components/administrator/dashboard.php',
            'inventory_manager' => 'components/inventory_management/dashboard.php',
            'cashier' => 'components/cashier/pos.php',
            default => '?login=1',
        };
    }
}
