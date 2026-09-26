<?php
// Legacy URL delegates to the same role-specific workflow, never a second importer.
require_once __DIR__ . '/../../../includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    require_capability(RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
}

if (role_capability_policy()->allows((string)current_role(), RoleCapabilityPolicy::PLATFORM_GOVERNANCE)) {
    require __DIR__ . '/../../../../frontend/components/system_administrator/backup_restore.php';
} else {
    require __DIR__ . '/../../../../frontend/components/administrator/database_backup.php';
}
