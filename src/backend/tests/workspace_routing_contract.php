<?php
// Thin route contract for canonical role workspaces.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\RoleWorkspaceRouter;

$expected = [
    'super_admin' => 'components/super_administrator/dashboard.php',
    'admin' => 'components/administrator/dashboard.php',
    'inventory_manager' => 'components/inventory_management/inventory_overview.php',
    'cashier' => 'components/cashier/pos.php',
];
$failures = [];
foreach ($expected as $role => $path) {
    if (RoleWorkspaceRouter::pathFor($role) !== $path) {
        $failures[] = "{$role} must route to {$path}";
    }
}
if (RoleWorkspaceRouter::pathFor('unknown') !== '?login=1' || RoleWorkspaceRouter::pathFor(null) !== '?login=1') {
    $failures[] = 'unknown and absent roles must route to login';
}
if (count(array_unique($expected)) !== count($expected)) {
    $failures[] = 'role workspaces must remain distinct';
}

if ($failures) {
    fwrite(STDERR, "Workspace routing contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Workspace routing contract: passed\n";
