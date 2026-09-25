<?php
// Session-level contract for selecting one of a user's assigned workspaces.
$_SESSION = [
    'role' => 'admin',
    'roles' => ['admin', 'inventory_manager', 'cashier'],
];

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function current_roles(): array
{
    return $_SESSION['roles'] ?? [];
}

$source = file_get_contents(__DIR__ . '/../includes/auth.php');
if (!preg_match('/function current_workspace_roles\(\?string \$primaryRole = null\): array\s*\{.*?\n\}/s', $source, $workspaceRolesMatch)
    || !preg_match('/function switch_current_workspace\(string \$role\): bool\s*\{.*?\n\}/s', $source, $match)
) {
    fwrite(STDERR, "Workspace switching contract failed: switch_current_workspace was not found.\n");
    exit(1);
}
eval($workspaceRolesMatch[0]);
eval($match[0]);

$failures = [];
if (!switch_current_workspace('inventory_manager') || current_role() !== 'inventory_manager') {
    $failures[] = 'an assigned Inventory workspace must be selectable';
}
if (!switch_current_workspace('cashier') || current_role() !== 'cashier') {
    $failures[] = 'an assigned Cashier workspace must be selectable';
}
if (switch_current_workspace('super_admin') || current_role() !== 'cashier') {
    $failures[] = 'an unassigned workspace must be rejected without changing the active workspace';
}

$_SESSION = ['role' => 'super_admin', 'roles' => ['super_admin']];
foreach (['admin', 'inventory_manager', 'cashier'] as $workspace) {
    if (!switch_current_workspace($workspace) || current_role() !== $workspace) {
        $failures[] = "Super Administrator must be able to enter the {$workspace} workspace";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Workspace switching contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Workspace switching contract: passed\n";
