<?php
// Compatibility route: administrator roles now have separate canonical workspaces.
require_once __DIR__ . '/../../backend/includes/auth.php';
require_any_capability([
    \App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE,
    \App\Authorization\RoleCapabilityPolicy::STORE_OPERATIONS,
]);
redirect_by_role();
