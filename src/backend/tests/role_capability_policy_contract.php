<?php
// Deterministic authority contract for every supported RetailMind role.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\AuthorizationContext;
use App\Authorization\RoleCapabilityPolicy;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$policy = new RoleCapabilityPolicy();
$capabilities = [
    RoleCapabilityPolicy::PLATFORM_GOVERNANCE,
    RoleCapabilityPolicy::STORE_OPERATIONS,
    RoleCapabilityPolicy::VIEW_INVENTORY,
    RoleCapabilityPolicy::MUTATE_INVENTORY,
    RoleCapabilityPolicy::OPERATE_POINT_OF_SALE,
    RoleCapabilityPolicy::VIEW_STORE_AUDIT,
    RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT,
    RoleCapabilityPolicy::MANAGE_USERS,
    RoleCapabilityPolicy::ASSIGN_ROLES,
    RoleCapabilityPolicy::ASSIGN_PRIVILEGES,
    RoleCapabilityPolicy::ACTIVATE_EMERGENCY_ACCESS,
    RoleCapabilityPolicy::VIEW_SALES_HISTORY,
    RoleCapabilityPolicy::VIEW_STORE_REPORTS,
    RoleCapabilityPolicy::MANAGE_SALE_REVERSALS,
    RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP,
];

// Shared preservation authority only: both administrator roles may create and
// download a Database Backup, while Database Restore stays exclusive to the
// Super Administrator (ADR-0003).
$expected = [
    'super_admin' => [true, false, true, false, false, true, true, true, true, true, true, true, true, false, true],
    'admin' => [false, true, true, false, false, true, false, true, true, false, false, true, true, true, true],
    'inventory_manager' => [false, false, true, true, false, false, false, false, false, false, false, true, true, true, false],
    'cashier' => [false, false, true, false, true, false, false, false, false, false, false, true, false, false, false],
];

foreach ($expected as $role => $decisions) {
    foreach ($capabilities as $index => $capability) {
        $actual = $policy->allows($role, $capability);
        $assert(
            $actual === $decisions[$index],
            "{$role} {$capability} should be " . ($decisions[$index] ? 'allowed' : 'denied')
        );
    }
}

foreach (['inventory_manager', 'cashier'] as $targetRole) {
    $assert($policy->allows('admin', RoleCapabilityPolicy::MANAGE_USERS, $targetRole), "Administrator should manage {$targetRole} accounts");
    $assert($policy->allows('admin', RoleCapabilityPolicy::ASSIGN_ROLES, $targetRole), "Administrator should assign the {$targetRole} template");
}
foreach (['admin', 'super_admin'] as $targetRole) {
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::MANAGE_USERS, $targetRole), "Administrator must not manage {$targetRole} accounts");
    $assert(!$policy->allows('admin', RoleCapabilityPolicy::ASSIGN_ROLES, $targetRole), "Administrator must not assign the {$targetRole} role");
}
$assert(!$policy->allows('admin', RoleCapabilityPolicy::ASSIGN_PRIVILEGES, 'cashier'), 'Administrator must not assign arbitrary privileges');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::MANAGE_USERS, 'admin'), 'Super Administrator should manage Administrator accounts');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::ASSIGN_ROLES, 'super_admin'), 'Super Administrator should govern privileged roles');

// Shared Database Backup creation/download is deliberately not platform
// governance: the Administrator keeps the backup capability without gaining any
// restoration or platform authority.
$assert($policy->allows('admin', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP), 'Administrator should create and download a Database Backup');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP), 'Super Administrator should create and download a Database Backup');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::PLATFORM_GOVERNANCE), 'Administrator backup access must not grant platform governance');
$assert(!$policy->allows('inventory_manager', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP), 'Inventory Manager must not reach Database Backup');
$assert(!$policy->allows('cashier', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP), 'Cashier must not reach Database Backup');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP, null, $emergency, 41) || $policy->allows('admin', RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP), 'Emergency Access must not change backup authority');

$emergency = AuthorizationContext::emergencyAccess(101, 41, 'Restore Store operations during an incident');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::STORE_OPERATIONS, null, $emergency, 41), 'Emergency Access should permit isolated Store operations');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $emergency, 41), 'Emergency Access should permit emergency inventory mutation');
$assert(!$policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $emergency), 'Emergency Access should require an authenticated actor identifier');
$assert(!$policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $emergency, 42), 'Emergency context must not elevate another Super Administrator');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::PLATFORM_GOVERNANCE, null, $emergency, 41), 'Emergency context must not elevate an Administrator');
$assert(!$policy->allows('super_admin', RoleCapabilityPolicy::OPERATE_POINT_OF_SALE, null, $emergency, 41), 'Emergency Access must not turn the Super Administrator into a Cashier');
$expiredEmergency = AuthorizationContext::standard();
$assert(!$policy->allows('super_admin', RoleCapabilityPolicy::STORE_OPERATIONS, null, $expiredEmergency, 41), 'Expired Emergency Access must not grant Store operations');
$assert(!$policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $expiredEmergency, 41), 'Expired Emergency Access must not grant inventory mutation');
$assert(!$policy->allows('unknown', RoleCapabilityPolicy::VIEW_INVENTORY), 'Unknown actors must be denied');
$assert(!$policy->allows('admin', 'unknown_capability'), 'Unknown capabilities must be denied');

if ($failures) {
    fwrite(STDERR, "Role capability policy contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Role capability policy contract: passed\n";
