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
];

$expected = [
    'super_admin' => [true, false, true, false, false, true, true, true, true, true, true],
    'admin' => [false, true, true, false, false, true, false, true, true, false, false],
    'inventory_manager' => [false, false, true, true, false, false, false, false, false, false, false],
    'cashier' => [false, false, true, false, true, false, false, false, false, false, false],
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

$emergency = AuthorizationContext::emergencyAccess('Restore Store operations during an incident');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::STORE_OPERATIONS, null, $emergency), 'Emergency Access should permit isolated Store operations');
$assert($policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $emergency), 'Emergency Access should permit emergency inventory mutation');
$assert(!$policy->allows('admin', RoleCapabilityPolicy::PLATFORM_GOVERNANCE, null, $emergency), 'Emergency context must not elevate an Administrator');
$assert(!$policy->allows('super_admin', RoleCapabilityPolicy::OPERATE_POINT_OF_SALE, null, $emergency), 'Emergency Access must not turn the Super Administrator into a Cashier');
$expiredEmergency = AuthorizationContext::expiredEmergencyAccess('Expired incident intervention');
$assert(!$policy->allows('super_admin', RoleCapabilityPolicy::STORE_OPERATIONS, null, $expiredEmergency), 'Expired Emergency Access must not grant Store operations');
$assert(!$policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $expiredEmergency), 'Expired Emergency Access must not grant inventory mutation');
$assert(!$policy->allows('unknown', RoleCapabilityPolicy::VIEW_INVENTORY), 'Unknown actors must be denied');
$assert(!$policy->allows('admin', 'unknown_capability'), 'Unknown capabilities must be denied');

if ($failures) {
    fwrite(STDERR, "Role capability policy contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Role capability policy contract: passed\n";
