<?php
// Direct stock adjustments must honor assigned Inventory Manager access,
// including accounts whose primary role is still another workspace.

$source = file_get_contents(__DIR__ . '/../app/Services/ProductService.php');
$failures = [];

if (!preg_match('/public function adjustStock\(array \$data, int \$userId\): void\s*\{.*?\n    \}/s', (string)$source, $match)) {
    $failures[] = 'ProductService::adjustStock was not found.';
} else {
    $adjustStock = $match[0];
    if (!str_contains($adjustStock, 'LEFT JOIN user_roles')) {
        $failures[] = 'adjustStock must check assigned roles, not only users.role_id.';
    }
    if (!str_contains($adjustStock, "assigned_role.role_name = 'inventory_manager'")) {
        $failures[] = 'adjustStock must allow an assigned Inventory Manager role.';
    }
    if (str_contains($adjustStock, 'SELECT r.role_name FROM users u JOIN roles r')) {
        $failures[] = 'adjustStock still uses the legacy primary-role-only query.';
    }
}

if ($failures) {
    fwrite(STDERR, "Product stock adjustment role contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Product stock adjustment role contract: passed\n";
