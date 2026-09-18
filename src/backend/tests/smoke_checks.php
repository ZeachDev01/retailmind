<?php
// Lightweight release checks that do not require a live database.
$root = dirname(__DIR__, 3);
$checks = [
    'Inactive products blocked at checkout' => [
        'file' => 'src/backend/app/Services/SalesWorkflowService.php',
        'needles' => ["p.status = 'active'", "AND p.status = 'active' FOR UPDATE"],
    ],
    'Cashier shift required' => [
        'file' => 'src/backend/app/Services/SalesWorkflowService.php',
        'needles' => ['resolveOpenShift', 'Open a cashier shift before processing sales'],
    ],
    'Discount authorization recorded' => [
        'file' => 'src/backend/app/Services/SalesWorkflowService.php',
        'needles' => ['discount_authorized_by', 'authorizeSupervisor'],
    ],
    'Replenishment segregation of duties' => [
        'file' => 'src/frontend/components/inventory_management/replenishment_requests.php',
        'needles' => ["requested_by <> ?", "status = 'pending'"],
    ],
    'Accepted stock drives request completion' => [
        'file' => 'src/backend/app/Services/ReceivingService.php',
        'needles' => ['SUM(accepted_qty)', 'LEAST(ordered_qty, received_qty + ?)'],
    ],
    'Server-held sales enabled' => [
        'file' => 'src/frontend/components/barcodeScanner/apiScanner/held_sales.php',
        'needles' => ['INSERT INTO held_sales', "status='held'"],
    ],
    'Server product search enabled' => [
        'file' => 'src/frontend/components/barcodeScanner/apiScanner/products.php',
        'needles' => ["p.status='active'", 'case_barcode'],
    ],
    'Random Forest baseline comparison' => [
        'file' => 'src/backend/legacy/demandForcasting/train_model.py',
        'needles' => ['baseline_predictions', 'model_beats_baseline'],
    ],
    'Versioned migration runner present' => [
        'file' => 'src/backend/scripts/migrate.php',
        'needles' => ['MigrationRunner', 'runPending', 'database/migrations'],
    ],
    'Runtime authentication does not migrate schema' => [
        'file' => 'src/backend/includes/auth.php',
        'needles' => ['must_change_password', 'components/auth/change_password.php'],
        'forbidden' => ['ALTER TABLE', 'CREATE TABLE', 'ensure_operational_updates_schema'],
    ],
    'Mandatory password change page present' => [
        'file' => 'src/frontend/components/auth/change_password.php',
        'needles' => ['current_password', 'must_change_password = 0', 'password_policy_error'],
    ],
    'Dedicated system health page present' => [
        'file' => 'src/frontend/components/system_administrator/system_health.php',
        'needles' => ['SystemHealthService', 'Environment checks', 'Recommended maintenance commands', 'system-health-groups', 'Refresh checks'],
        'forbidden' => ['embed=1', 'data-embedded-close', 'system-health-embedded'],
    ],
    'System health uses direct navigation' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ['components/system_administrator/system_health.php'],
        'forbidden' => ['data-system-health-open', 'systemHealthOverlay', 'systemHealthFrame'],
    ],
    'Dedicated administrator workspaces are canonical' => [
        'file' => 'src/backend/app/Authorization/RoleWorkspaceRouter.php',
        'needles' => ["'super_admin' => 'components/super_administrator/dashboard.php'", "'admin' => 'components/administrator/dashboard.php'", "'inventory_manager' => 'components/inventory_management/dashboard.php'", "'cashier' => 'components/cashier/pos.php'"],
    ],
    'Landing routes through the canonical workspace map' => [
        'file' => 'src/frontend/index.php',
        'needles' => ['RoleWorkspaceRouter::pathFor'],
        'forbidden' => ["case 'super_admin':", "case 'admin':"],
    ],
    'Super Administrator workspace is exactly guarded' => [
        'file' => 'src/frontend/components/super_administrator/dashboard.php',
        'needles' => ["require_role(['super_admin'])", 'Platform Control Center', "include __DIR__ . '/../sidebar.php'", 'system_health.php', 'backup_restore.php', 'ml_settings.php', 'system_settings.php'],
        'forbidden' => ['inventory_counts.php', 'csv_import.php', 'stock_receiving.php', 'inventory_adjustments.php'],
    ],
    'Administrator workspace is exactly guarded' => [
        'file' => 'src/frontend/components/administrator/dashboard.php',
        'needles' => ["require_role(['admin'])", 'Store Operations', "include __DIR__ . '/../sidebar.php'", 'fiscal_periods.php'],
        'forbidden' => ['system_health.php', 'backup_restore.php', 'ml_settings.php', 'system_settings.php'],
    ],
    'Shared administrator dashboard is retired' => [
        'file' => 'src/frontend/components/dashboard.php',
        'needles' => ['redirect_by_role();'],
        'forbidden' => ['DashboardService', 'getAdminMetrics'],
    ],
    'Administrator navigation is role-specific' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ['$superAdministratorSections', '$administratorSections', "'super_admin' => \$superAdministratorSections", "'admin' => \$administratorSections"],
    ],
    'User Management is a single-Store workflow' => [
        'file' => 'src/frontend/components/user_manager/user_manager.php',
        'needles' => ['UserLifecycleService', 'store_scope_id($pdo)', "\$action === 'revoke_sessions'"],
        'forbidden' => ['create_branch', 'toggle_branch', 'branchesPanel', 'openBranchModal', 'data-management-tab="branches"'],
    ],
    'Manage Users summary icons present' => [
        'file' => 'src/frontend/components/user_manager/user_manager.php',
        'needles' => ['stat-card with-icon', 'bi-people-fill', 'bi-person-check-fill', 'bi-person-x-fill'],
    ],
    'Super Administrator account is protected' => [
        'file' => 'src/backend/app/Services/UserLifecycleService.php',
        'needles' => ["\$targetRole === 'super_admin'", 'The Super Administrator account is protected.', 'requireManageable'],
    ],
    'Staff role selectors use fixed templates without branch assignment' => [
        'file' => 'src/frontend/components/user_manager/modals/add_user_modal.php',
        'needles' => ["!in_array(\$r['role_name'], ['super_admin', 'seller'], true)"],
        'forbidden' => ['name="branch_id"', 'Assigned Branch'],
    ],
    'Staff management has no branch or arbitrary privilege controls' => [
        'file' => 'src/frontend/components/user_manager/modals/manage_user_modal.php',
        'needles' => ['data-user-drawer-tab="security"', 'Revoke Sessions'],
        'forbidden' => ['drawerBranch', 'privilege_ids[]', 'manage_privileges', 'Delete User'],
    ],
    'Manage Users modal form remains scrollable' => [
        'file' => 'src/frontend/assets/css/modals.css',
        'needles' => ['max-height: calc(100dvh - 2rem);', '.user-modal > .user-form {', 'overflow-y: auto;', '-webkit-overflow-scrolling: touch;'],
    ],
    'Staff profile images use secure shared storage' => [
        'file' => 'src/backend/app/Services/ProfileImageStorage.php',
        'needles' => ['finfo_file', 'getimagesize', 'is_uploaded_file', 'move_uploaded_file', 'random_bytes', 'MAX_BYTES'],
        'forbidden' => ["\$file['type']", "\$file['name']"],
    ],
    'Staff profile image upload remains optional' => [
        'file' => 'src/frontend/components/user_manager/modals/add_user_modal.php',
        'needles' => ['enctype="multipart/form-data"', 'name="profile_image"', 'accept="image/jpeg,image/png,image/gif,image/webp"', 'Optional'],
        'forbidden' => ['name="profile_image" required'],
    ],
    'Staff profile images are rendered through shared fallback logic' => [
        'file' => 'src/frontend/components/user_manager/user_manager.php',
        'needles' => ['profile_avatar_html', 'profile_image', 'remove_profile_image'],
        'forbidden' => ['storage/profile-images/'],
    ],
    'Preferences live under the profile menu' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ['components/auth/preferences.php'],
        'forbidden' => ["\$notificationItems[] = ['path' => 'components/notification/notification_preferences.php'"],
    ],
    'Account preferences preserve notification controls' => [
        'file' => 'src/frontend/components/auth/preferences.php',
        'needles' => ['Preferences', 'preferences.css', 'notify_low_stock', 'notify_replenishment', 'notify_adjustment', 'notify_email', 'notify_inapp', 'low_stock_threshold', 'csrf_field()'],
    ],
    'Administrator profile pictures are self-service only' => [
        'file' => 'src/frontend/components/auth/user_info.php',
        'needles' => ['replace_profile_image', 'remove_profile_image', 'is_system_admin()', "(int)\$_SESSION['user_id']", 'ProfileImageStorage::MAX_FILE_SIZE', 'image/jpeg,image/png,image/gif,image/webp'],
        'forbidden' => ["\$_POST['user_id']", "\$_GET['user_id']", "\$_REQUEST['user_id']"],
    ],
    'Admin User Info uses a dedicated responsive layout' => [
        'file' => 'src/frontend/components/auth/user_info.php',
        'needles' => ['user-info.css', 'class="user-info-page"', 'user-info-layout', 'profile-picture-frame', 'profile-details-form'],
    ],
    'Admin User Info keeps profile media contained' => [
        'file' => 'src/frontend/assets/css/user-info.css',
        'needles' => ['.user-info-page .profile-avatar', '.profile-picture-frame', 'overflow: hidden;', 'aspect-ratio: 1;', '.profile-picture-preview', 'object-fit: cover;', '@media (max-width: 720px)'],
    ],
    'Shared shell loads cache-safe avatar styling' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ['assets/css/avatars.css', 'filemtime', 'data-avatar-styles'],
    ],
    'Shared avatar media remains contained across pages' => [
        'file' => 'src/frontend/assets/css/avatars.css',
        'needles' => ['.profile-avatar {', 'position: relative;', 'display: inline-grid;', 'overflow: hidden;', '.profile-avatar-image {', 'position: absolute;', 'inset: 0;', 'width: 100%;', 'height: 100%;', 'object-fit: cover;'],
    ],
    'Profile pictures are delivered without exposing storage paths' => [
        'file' => 'src/frontend/components/auth/profile_image.php',
        'needles' => ['is_logged_in()', 'profile_image_storage()', 'X-Content-Type-Options: nosniff', 'Cache-Control: private'],
        'forbidden' => ["\$_GET['path']", "\$_GET['filename']"],
    ],
    'Default profile picture uses the replacement asset' => [
        'file' => 'src/backend/includes/auth.php',
        'needles' => ["assets/img/new-default-profile.svg.png"],
        'forbidden' => ["assets/img/default-profile.svg"],
    ],
    'Former notification preferences route redirects' => [
        'file' => 'src/frontend/components/notification/notification_preferences.php',
        'needles' => ["app_url('components/auth/preferences.php')", '302', '307'],
        'forbidden' => ['preferences-form', 'notify_low_stock'],
    ],
    'Dedicated audit logs DataTable present' => [
        'file' => 'src/frontend/components/system_administrator/audit_logs.php',
        'needles' => ['id="auditLogsTable"', 'data-no-smart-table', "new DataTable('#auditLogsTable'", 'dataTables.columnControl.min.js', 'dataTables.dateTime.min.js', "columnControl: ['order'", 'topStart: null', "bottom: [", "'info'", 'pageLength:', 'paging:', 'Export CSV'],
        'forbidden' => ['embed=1', 'data-embedded-close', 'Filter activity', 'Apply filters', 'audit-datatable-filters'],
    ],
    'Audit Logs summary icons present' => [
        'file' => 'src/frontend/components/system_administrator/audit_logs.php',
        'needles' => ['audit-log-stats', 'bi-card-checklist', 'bi-person-check-fill', 'bi-boxes'],
    ],
    'Receipt Management uses a server-side DataTable' => [
        'file' => 'src/frontend/components/invoice/receipt.php',
        'needles' => ['id="receiptsTable"', 'data-no-smart-table', "new DataTable('#receiptsTable'", 'serverSide: true', 'processing: true', 'pageLength: 25', "stateDuration: -1", "sessionStorage", 'receipt-table-status'],
        'forbidden' => ['function performSearch()', 'fetchAll();\n}'],
    ],
    'Receipt Management exposes focused filters and existing actions' => [
        'file' => 'src/frontend/components/invoice/receipt.php',
        'needles' => ['id="receiptDateFrom"', 'id="receiptDateTo"', 'id="receiptReversalStatus"', 'id="receiptCashier"', 'id="retryReceiptTable"', 'viewReceipt(', 'Print Receipt', 'components/invoice/reversals.php?sale_id=', 'No receipts have been created yet.', 'No receipts match the current search and filters.', 'Unable to load receipts.'],
    ],
    'Receipt Management server contract is isolated and safe' => [
        'file' => 'src/backend/app/Services/ReceiptTableService.php',
        'needles' => ['class ReceiptTableService', 'StoreScope', 'recordsTotal', 'recordsFiltered', "'s.sale_date'", "'s.total_amount'", "'item_count'", 'LIMIT :limit OFFSET :offset'],
        'forbidden' => ['ORDER BY {$request', 'ORDER BY ' . "\$_GET", "scope['branch_id']", 'scope_branch_id'],
    ],
    'Sales and receipt routes use capability and Store scope' => [
        'file' => 'src/frontend/components/invoice/receipt.php',
        'needles' => ['VIEW_SALES_HISTORY', 'store_scope_id($pdo)', 'ReceiptTableService($pdo)'],
        'forbidden' => ["require_role(['admin', 'super_admin', 'inventory_manager', 'cashier'])", "'branch_id' => null"],
    ],
    'Report generation uses capability and Store scope' => [
        'file' => 'src/frontend/components/report/report_generation.php',
        'needles' => ['VIEW_STORE_REPORTS', "'store_id' => store_scope_id(\$pdo)", '{$alias}.branch_id = ?'],
        'forbidden' => ["require_role(['admin', 'inventory_manager'])"],
    ],
    'Forecast training keeps Store Product and Day grain' => [
        'file' => 'src/backend/legacy/demandForcasting/train_model.py',
        'needles' => ['resolve_store_id', 'p.branch_id = %s', 'GROUP BY si.product_id, DATE(s.sale_date)'],
        'forbidden' => ['branch_id AS', 'groupby("branch_id"', "groupby(['branch_id'"],
    ],
    'Audit Logs search control is inset' => [
        'file' => 'src/frontend/assets/css/audit-logs.css',
        'needles' => ['.dt-container > .dt-layout-row:first-child', 'padding: 0.65rem 1rem 0.15rem;'],
    ],
    'Audit logs use direct navigation' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ['components/system_administrator/audit_logs.php'],
        'forbidden' => ['data-audit-log-open', 'auditLogOverlay', 'auditLogFrame'],
    ],
    'Dedicated fiscal periods page present' => [
        'file' => 'src/frontend/components/system_administrator/fiscal_periods.php',
        'needles' => ['Fiscal Periods', 'fiscal-period-form-grid', 'fiscal-period-card-grid', "\$action === 'create'", "\$action === 'close'", "\$action === 'lock'"],
        'forbidden' => ['embed=1', 'data-embedded-close', 'fiscal-periods-embedded'],
    ],
    'Fiscal periods use direct sidebar navigation' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ['components/system_administrator/fiscal_periods.php'],
        'forbidden' => ['data-fiscal-periods-open', 'fiscalPeriodsOverlay', 'fiscalPeriodsFrame'],
    ],
    'Fiscal periods use direct dashboard navigation' => [
        'file' => 'src/frontend/components/administrator/dashboard.php',
        'needles' => ['components/system_administrator/fiscal_periods.php'],
        'forbidden' => ['components/modals/fiscal_periods.php', 'data-fiscal-periods-open'],
    ],
    'Supplier-based reorder planning present' => [
        'file' => 'src/frontend/components/inventory_management/reorder_planner.php',
        'needles' => ['Supplier-Based Reorder Planning', 'minimum_order_quantity', 'Create selected replenishment requests'],
    ],
    'Release cleanup covers runtime files' => [
        'file' => 'src/backend/scripts/build_release.sh',
        'needles' => ['src/backend/storage/sessions/*', 'src/backend/storage/imports/*', 'src/backend/legacy/demandForcasting/models/*'],
    ],
];
$failures = [];
foreach ($checks as $label => $check) {
    $path = $root . '/' . $check['file'];
    $text = is_readable($path) ? file_get_contents($path) : '';
    foreach ($check['needles'] as $needle) {
        if ($text === false || strpos($text, $needle) === false) {
            $failures[] = "$label: missing {$needle}";
        }
    }
    foreach ($check['forbidden'] ?? [] as $needle) {
        if ($text !== false && strpos($text, $needle) !== false) {
            $failures[] = "$label: forbidden {$needle}";
        }
    }
}
if ($failures) {
    fwrite(STDERR, "Smoke checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'Smoke checks passed: ' . count($checks) . "\n";
