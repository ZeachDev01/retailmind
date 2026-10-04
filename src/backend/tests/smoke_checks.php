<?php
// Lightweight release checks that do not require a live database.
$root = dirname(__DIR__, 3);
$checks = [
    'Inactive products blocked at checkout' => [
        'file' => 'src/backend/app/Services/SalesWorkflowService.php',
        // The row lock is dialect-aware (see rowLock()), so the rule is asserted
        // by the locked read itself rather than a hard-coded clause.
        'needles' => ["p.status = 'active'", '$this->rowLock()'],
    ],
    'Sale requires the Cashier workspace and an open Cashier Shift' => [
        'file' => 'src/backend/app/Services/SalesWorkflowService.php',
        'needles' => [
            'resolveSaleAttribution',
            'requireCashierWorkspace',
            'Only the Cashier workspace can sell.',
            'Open a Cashier Shift before processing sales.',
            'OPERATE_POINT_OF_SALE',
            'cs.cashier_id = ? AND cs.status = \'open\'',
        ],
        // Whether the Register can be supplied by the client is proved by
        // behaviour in sale_shift_attribution_contract.php, not by a needle.
    ],
    'Sale attribution ships in an upgrade migration' => [
        'file' => 'src/backend/database/migrations/202609290003_sale_shift_attribution.php',
        'needles' => [
            "'shift_id', 'INT NULL AFTER `cashier_id`'",
            'idx_sales_shift',
            'fk_sales_shift',
            'Schema::addForeignKeyIfMissing',
            "'RESTRICT'",
        ],
    ],
    'Fresh schema ships the same sale attribution relationship' => [
        'file' => 'src/backend/sql/schema.sql',
        'needles' => [
            'KEY `idx_sales_shift`',
            'CONSTRAINT `fk_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE RESTRICT',
        ],
    ],
    'Unlinked sales are presented as Legacy / Unassigned' => [
        'file' => 'src/backend/app/Services/ReceiptDetailsService.php',
        'needles' => ['Legacy / Unassigned', 'LEFT JOIN cashier_shifts cs ON cs.shift_id = s.shift_id', 'r.name AS register_name'],
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
        // Ticket #91 moved the rules out of the endpoint and into
        // HeldSaleService, so the parking INSERT and the unresolved statuses now
        // live there. The endpoint's own wiring is checked further down.
        'file' => 'src/backend/app/Services/HeldSaleService.php',
        'needles' => ['INSERT INTO held_sales', "status IN ({", 'openForCashier('],
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
        'needles' => ["'super_admin' => 'components/super_administrator/dashboard.php'", "'admin' => 'components/administrator/dashboard.php'", "'inventory_manager' => 'components/inventory_management/inventory_overview.php'", "'cashier' => 'components/cashier/pos.php'"],
    ],
    'Landing routes through the canonical workspace map' => [
        'file' => 'src/frontend/index.php',
        'needles' => ['RoleWorkspaceRouter::pathFor'],
        'forbidden' => ["case 'super_admin':", "case 'admin':"],
    ],
    'Super Administrator workspace is exactly guarded' => [
        'file' => 'src/frontend/components/super_administrator/dashboard.php',
        'needles' => ["require_role(['super_admin'])", 'Platform Control Center', "include __DIR__ . '/../sidebar.php'", 'DashboardWorkspace', 'RoleCapabilityPolicy'],
        'forbidden' => ['inventory_counts.php', 'csv_import.php', 'stock_receiving.php', 'inventory_adjustments.php'],
    ],
    'Super Administrator control center uses policy-filtered destinations' => [
        'file' => 'src/backend/app/Dashboard/DashboardWorkspace.php',
        'needles' => ['RoleCapabilityPolicy::PLATFORM_GOVERNANCE', 'RoleCapabilityPolicy::MANAGE_USERS', 'RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT', 'permittedActions'],
        'forbidden' => ['inventory_counts.php', 'stock_receiving.php', 'inventory_adjustments.php'],
    ],
    'Administrator workspace is exactly guarded' => [
        'file' => 'src/frontend/components/administrator/dashboard.php',
        'needles' => ["require_role(['admin'])", 'Store Operations', "include __DIR__ . '/../sidebar.php'", 'StoreOperationsDashboardWorkspace'],
        'forbidden' => ['system_health.php', 'backup_restore.php', 'ml_settings.php', 'system_settings.php'],
    ],
    'Shared administrator dashboard is retired' => [
        'file' => 'src/frontend/components/dashboard.php',
        'needles' => ['redirect_by_role();'],
        'forbidden' => ['DashboardService', 'getAdminMetrics'],
    ],
    'Authentication does not expose compatibility Store identity' => [
        'file' => 'src/backend/includes/auth.php',
        'needles' => ['function store_product_scope(string $alias = \'p\'): array'],
        'forbidden' => ['branch_name', "\$_SESSION['branch_id']", 'current_branch_id', 'require_assigned_branch', 'selected_inventory_branch_id', 'function branch_scope(', 'is_system_admin', 'has_privilege', 'require_privilege', 'require_inventory_management'],
    ],
    'Capability policy has no legacy privilege bypass' => [
        'file' => 'src/backend/app/Authorization/RoleCapabilityPolicy.php',
        'needles' => [],
        'forbidden' => ['allowsLegacyPrivilege', 'manage_branches', 'manage_inventory'],
    ],
    'Store Staff is a dedicated workspace page' => [
        'file' => 'src/frontend/components/user_manager/user_manager.php',
        'needles' => ['<body class="manage-users-page">'],
        'forbidden' => ["\$_GET['embed']", 'isEmbedded', 'manage-users-embedded'],
    ],
    'Navigation does not embed Store Staff' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ["'path' => 'components/user_manager/user_manager.php'"],
        'forbidden' => ['userManagementOverlay', 'userManagementFrame', 'data-user-management-open', '?embed=1', 'isset($isEmbedded)'],
    ],
    'Branch-facing Store Staff styles are retired' => [
        'file' => 'src/frontend/assets/css/modals.css',
        'needles' => [],
        'forbidden' => ['.branch-modal', 'manage-users-branches', 'users-col-branch', 'branches-col-', 'manage-users-embedded', 'manage-users-tab'],
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
    'Manage Account Status tab shows dormancy visibility' => [
        'file' => 'src/frontend/components/user_manager/modals/manage_user_modal.php',
        'needles' => ['data-user-drawer-panel="status"', 'DormancyStatusTab::HELP_LINE', 'drawerLastLogin', 'drawerAutoDisableDate', 'drawerPolicyDisabledLine'],
        'forbidden' => ['value="dormant"', 'value="inactive"'],
    ],
    'Manage Account Status tab wires per-account dormancy data' => [
        'file' => 'src/frontend/components/user_manager/user_manager.php',
        'needles' => ['DormancyStatusTab', 'data-last-login', 'data-auto-disable-date', 'data-policy-disabled'],
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
        'needles' => ['enctype="multipart/form-data"', 'name="profile_image"', 'ProfileImageStorage::ACCEPT', 'Optional'],
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
    'Ordinary account profile pictures are self-service only' => [
        'file' => 'src/frontend/components/auth/user_info.php',
        'needles' => ['replace_profile_image', 'remove_profile_image', "['cashier', 'inventory_manager', 'admin', 'super_admin']", "(int)\$_SESSION['user_id']", 'ProfileImageStorage::MAX_FILE_SIZE', 'ProfileImageStorage::ACCEPT', 'Save picture', 'Optional.'],
        'forbidden' => ['is_system_admin', "\$_REQUEST['user_id']"],
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
    'Sales Transactions uses a server-side DataTable' => [
        'file' => 'src/frontend/components/invoice/sales.php',
        'needles' => ['id="receiptsTable"', 'data-no-smart-table', "new DataTable('#receiptsTable'", 'serverSide: true', 'processing: true', 'pageLength: 25', "stateDuration: -1", "sessionStorage", 'receipt-table-status'],
        'forbidden' => ['function performSearch()', 'fetchAll();\n}'],
    ],
    'Sales workspace exposes transaction filters and reversal actions' => [
        'file' => 'src/frontend/components/invoice/sales.php',
        'needles' => ['sales-tabs', 'Sales Transactions', 'Legacy Reversals', 'id="receiptDateFrom"', 'id="receiptDateTo"', 'id="receiptReversalStatus"', 'id="receiptCashier"', 'id="retryReceiptTable"', 'viewReceipt(', 'Print Receipt', 'tab=reversals&sale_id=', 'SaleReversalService', 'No receipts have been created yet.', 'No receipts match the current search and filters.', 'Unable to load receipts.'],
    ],
    'Sales transaction server contract is isolated and safe' => [
        'file' => 'src/backend/app/Services/ReceiptTableService.php',
        'needles' => ['class ReceiptTableService', 'StoreScope', 'recordsTotal', 'recordsFiltered', "'s.sale_date'", "'s.total_amount'", "'item_count'", 'LIMIT :limit OFFSET :offset'],
        'forbidden' => ['ORDER BY {$request', 'ORDER BY ' . "\$_GET", "scope['branch_id']", 'scope_branch_id'],
    ],
    'Sales and receipt routes use capability and Store scope' => [
        'file' => 'src/frontend/components/invoice/sales.php',
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
        'file' => 'src/backend/app/Dashboard/StoreOperationsDashboardWorkspace.php',
        'needles' => ['components/system_administrator/fiscal_periods.php'],
        'forbidden' => ['components/modals/fiscal_periods.php', 'data-fiscal-periods-open'],
    ],
    'Supplier-based reorder planning present' => [
        'file' => 'src/frontend/components/inventory_management/reorder_planner.php',
        'needles' => ['Supplier-Based Reorder Planning', 'minimum_order_quantity', 'Create selected replenishment requests'],
    ],
    'Shared attention evaluation feeds scheduled notifications' => [
        'file' => 'src/backend/scripts/run_notifications.php',
        'needles' => ['DatabaseAttentionSignalSource', 'AttentionRuleService', 'AttentionNotificationService', 'refreshResult', 'notify_email', 'newItems'],
    ],
    'Daily job runs the Dormancy Policy runner' => [
        'file' => 'src/backend/scripts/run_notifications.php',
        'needles' => ['App\Authorization\DormancyRunner', '->run()'],
        'forbidden' => ['DormancyPolicyService', 'disable_days', 'warn_days', 'dormancy_warn', 'last_login_at'],
    ],
    'Live Store attention includes explicit inventory escalations' => [
        'file' => 'src/backend/app/Attention/DatabaseAttentionSignalSource.php',
        'needles' => ["'inventory_escalated_count'", "ia.status = 'pending'", 'item_count'],
    ],
    'Platform Settings govern attention safety limits' => [
        'file' => 'src/backend/legacy/routes/admin/system_settings.php',
        'needles' => ['save_attention', 'updatePlatform', 'store_cash_variance_amount_max', 'Platform attention thresholds'],
    ],
    'Store Settings expose Administrator-owned attention thresholds' => [
        'file' => 'src/frontend/components/administrator/store_settings.php',
        'needles' => ["require_role(['admin'])", 'updateStore', 'thresholdsFor', 'Platform-limited value'],
        'forbidden' => ["require_role(['super_admin'])"],
    ],
    'Release cleanup covers runtime files' => [
        'file' => 'src/backend/scripts/build_release.sh',
        'needles' => ['src/backend/storage/sessions/*', 'src/backend/storage/imports/*', 'src/backend/legacy/demandForcasting/models/*'],
    ],
    'Cashier navigation offers one direct Report Stock Issue destination' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ["'path' => 'components/cashier/stock_issues.php'", "'label' => 'Report Stock Issue'", "'path' => 'components/inventory_management/stock_issues.php'"],
        'forbidden' => ["'label' => 'Warehouse'", 'components/report/stock_receiving.php', 'components/report/inventory_adjustments.php'],
    ],
    'Stock receiving stays closed to cashiers server-side' => [
        'file' => 'src/frontend/components/report/stock_receiving.php',
        'needles' => ["require_role(['admin', 'super_admin', 'inventory_manager'])"],
        'forbidden' => ["'cashier'"],
    ],
    'Legacy damage report redirects cashiers to the shift-gated flow' => [
        'file' => 'src/frontend/components/report/inventory_adjustments.php',
        'needles' => ["require_role(['admin'])", 'components/cashier/stock_issues.php'],
        'forbidden' => ["require_role(['admin', 'cashier'])"],
    ],
    'Cashier stock-issue submission is shift-gated with lookup selection' => [
        'file' => 'src/frontend/components/cashier/stock_issues.php',
        'needles' => ["require_role(['cashier'])", 'getOpenShift', 'active cashier shift', 'apiScanner/products.php', 'submitReport', 'getReportsByCashier'],
        'forbidden' => ['-- Select Product --'],
    ],
    'Manager review queue guards approvals and requires rejection reasons' => [
        'file' => 'src/frontend/components/inventory_management/stock_issues.php',
        'needles' => ["require_role(['inventory_manager'])", 'MUTATE_INVENTORY', 'approveReport', 'rejectReport', 'getPendingReports', 'required to reject'],
    ],
    'Stock-issue approvals deduct atomically with linked movements' => [
        'file' => 'src/backend/app/Services/StockIssueService.php',
        'needles' => ['getOpenShift', 'quantity_on_hand >= ?', 'adjustment_id', 'Insufficient available stock', 'A rejection reason is required', "'inventory_manager'"],
    ],
    'Stock-issue schema links reports to shifts and movements' => [
        'file' => 'src/backend/sql/schema.sql',
        'needles' => ['fk_inventory_adjustments_shift', 'fk_stock_movements_adjustment', 'review_notes', 'related_adjustment_id', 'fk_inventory_counts_related_adjustment'],
    ],
    'Administrator stock-issue oversight is read-only' => [
        'file' => 'src/frontend/components/administrator/stock_issues.php',
        'needles' => ["require_role(['admin'])", 'getOversightReports', 'getReportDetail', 'Stock Issue Oversight', 'date_from', 'date_to', 'Missing/Lost', 'Read-only oversight', 'correction_counts'],
        'forbidden' => ['approveReport', 'rejectReport', 'returnReport', 'editReport', 'cancelReport', 'resubmitReport', 'submitReport'],
    ],
    'Administrator navigation offers stock-issue oversight' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ["'path' => 'components/administrator/stock_issues.php'", "'label' => 'Stock Issues'"],
    ],
    'Legacy damage report page hands administrators to read-only oversight' => [
        'file' => 'src/frontend/components/report/inventory_adjustments.php',
        'needles' => ['components/administrator/stock_issues.php', 'components/cashier/stock_issues.php'],
        'forbidden' => ['INSERT INTO inventory_adjustments'],
    ],
    'Correction counts link back to approved stock-issue reports' => [
        'file' => 'src/backend/app/Services/InventoryCountService.php',
        'needles' => ['related_adjustment_id', 'Only approved stock issue reports can be corrected', "assertRole(\$userId, 'inventory_manager')"],
    ],
    'Inventory counts page pre-fills linked corrections' => [
        'file' => 'src/frontend/components/inventory_management/inventory_counts.php',
        'needles' => ['related_adjustment_id', "?correct=", 'Correcting approved stock issue report'],
    ],
    'Manager queue offers the separate correction path for approved reports' => [
        'file' => 'src/frontend/components/inventory_management/stock_issues.php',
        'needles' => ['Correct via inventory count', 'inventory_counts.php?correct='],
    ],
    // Register administration (ticket #87).
    'Register administration stays with the Administrator workspace' => [
        'file' => 'src/frontend/components/administrator/registers.php',
        'needles' => [
            'require_capability(RoleCapabilityPolicy::MANAGE_REGISTERS)',
            'RegisterService',
            'csrf_verify',
            'Create Register',
            'Rename',
            'Disable',
            'Delete',
        ],
        // Every class the page uses must exist in the stylesheet it loads.
        'forbidden' => ['visually-hidden', 'form-group-actions', 'register-actions'],
    ],
    'Register table stays scrollable on small screens' => [
        'file' => 'src/frontend/components/administrator/registers.php',
        'needles' => ['data-table-shell', 'data-table-scroll'],
    ],
    'Register rename inputs stay labelled for screen readers' => [
        'file' => 'src/frontend/components/administrator/registers.php',
        'needles' => ['aria-label="Rename'],
    ],
    'Register service keeps identity stable and guards referenced deletes' => [
        'file' => 'src/backend/app/Services/RegisterService.php',
        'needles' => [
            'MANAGE_REGISTERS',
            'Register name is required.',
            'Another Register already uses that name.',
            'This Register is still used by operational records',
            "'Register created'",
            "'Register renamed'",
            "'Register disabled'",
            "'Register enabled'",
            "'Register deleted'",
        ],
    ],
    'Register capability is a Store operation, not platform governance' => [
        'file' => 'src/backend/app/Authorization/RoleCapabilityPolicy.php',
        'needles' => ['MANAGE_REGISTERS'],
    ],
    'Administrator navigation offers Register administration' => [
        'file' => 'src/frontend/components/sidebar.php',
        'needles' => ["'path' => 'components/administrator/registers.php'", "'label' => 'Registers'"],
    ],
    'Register structure ships in an upgrade migration' => [
        'file' => 'src/backend/database/migrations/202609290001_store_registers.php',
        'needles' => [
            'CREATE TABLE registers',
            'UNIQUE KEY register_name',
            'disabled_at',
            "Schema::tableExists(\$pdo, 'registers')",
        ],
    ],
    'Fresh schema ships the same Register structure' => [
        'file' => 'src/backend/sql/schema.sql',
        'needles' => ['CREATE TABLE `registers`', 'UNIQUE KEY `register_name`', 'fk_registers_created_by'],
    ],
    // Exclusive Cashier Shift opening (ticket #88).
    'Cashier Shift opening is owned by the Cashier workspace' => [
        'file' => 'src/frontend/components/cashier/shifts.php',
        'needles' => [
            'use App\Services\CashierShiftService;',
            '$service->openShift($actorId, $actorRole, $registerId',
            'name="register_id"',
            'name="opening_float"',
            '$service->availableRegisters()',
            'A Cashier Shift is opened and owned by the Cashier who sells',
        ],
        // A shift is never opened on somebody's behalf, and the duplicate
        // page-level audit write is gone: the service records the opening.
        'forbidden' => ['openShift($targetCashierId', "'Opened cashier shift'"],
    ],
    'Cashier Shift service binds a shift to one exclusively owned Register' => [
        'file' => 'src/backend/app/Services/CashierShiftService.php',
        'needles' => [
            'OPERATE_POINT_OF_SALE',
            'Choose a Register to open this Cashier Shift on.',
            'That Register already has an open Cashier Shift.',
            'You already have an open Cashier Shift.',
            'That Register is disabled and cannot start a new Cashier Shift.',
            'uq_cashier_shifts_open_register',
            'uq_cashier_shifts_open_cashier',
            "'Cashier Shift opened'",
            'AuditRecordCategory::STORE_OPERATION',
        ],
    ],
    'Cashier Shift structure ships in an upgrade migration' => [
        'file' => 'src/backend/database/migrations/202609290002_cashier_shift_registers.php',
        'needles' => [
            "'register_id', 'INT NULL AFTER `cashier_id`'",
            'uq_cashier_shifts_open_cashier',
            'uq_cashier_shifts_open_register',
            'fk_cashier_shifts_register',
            'Schema::addUniqueKeyIfMissing',
        ],
        // MigrationRunner::available() loads with `require`, not
        // `require_once`, so anything declared at file scope would fatal on a
        // second load in the same process. Migrations declare nothing.
        'forbidden' => ["\nfunction "],
    ],
    'Fresh schema ships the same exclusive Cashier Shift constraints' => [
        'file' => 'src/backend/sql/schema.sql',
        'needles' => [
            'UNIQUE KEY `uq_cashier_shifts_open_cashier`',
            'UNIQUE KEY `uq_cashier_shifts_open_register`',
            'CONSTRAINT `fk_cashier_shifts_register`',
        ],
    ],
    'A break is recorded on the Cashier Shift, never in the session' => [
        'file' => 'src/backend/app/Services/CashierShiftService.php',
        'needles' => [
            'lockRegister(',
            'unlockRegister(',
            'requireUnlockedRegister(',
            'password_verify(',
            'SET locked_at = ',
            "'Cashier Shift locked'",
            "'Cashier Shift unlocked'",
            "'Register unlock refused'",
            'AuditRecordCategory::SECURITY',
        ],
        // The lock belongs to the shift, so the session is never consulted for
        // it: a session flag would let a Register reopen itself when a cookie
        // expired or a logout happened.
        'forbidden' => ['$_SESSION'],
    ],
    'A locked Register ships in an upgrade migration' => [
        'file' => 'src/backend/database/migrations/202609290004_cashier_shift_register_lock.php',
        'needles' => [
            "'cashier_shifts', 'locked_at', 'TIMESTAMP NULL DEFAULT NULL",
            'Schema::addColumnIfMissing',
        ],
        'forbidden' => ["\nfunction "],
    ],
    'Fresh schema records the lock on the Cashier Shift' => [
        'file' => 'src/backend/sql/schema.sql',
        'needles' => ['`locked_at` timestamp NULL DEFAULT NULL'],
    ],
    'Checkout refuses a locked Register from the row that authorizes it' => [
        'file' => 'src/backend/app/Services/SalesWorkflowService.php',
        'needles' => [
            'SELECT cs.shift_id, cs.locked_at',
            'CashierShiftService::LOCKED_MESSAGE',
        ],
    ],
    'The point of sale locks, resumes, and withholds itself while locked' => [
        'file' => 'src/frontend/components/cashier/pos.php',
        'needles' => [
            'lockRegister(',
            'unlockRegister(',
            '$posRegisterLocked',
            'name="pos_unlock_password"',
            'name="action" value="lock_register"',
            'name="action" value="unlock_register"',
        ],
        // Resumption reuses the Cashier's account password. A PIN field would be
        // a second, weaker credential to talk somebody into at the till.
        'forbidden' => ['name="pos_pin"', 'name="pin"'],
    ],
    'A locked Register pauses the drawer, not just the sale screen' => [
        'file' => 'src/backend/app/Services/CashierShiftService.php',
        'needles' => [
            'requireUnlockedRegister(',
            'SET locked_at = ',
            'password_verify(',
        ],
    ],
    'The Cashier Shift page explains the pause and defers to the lock screen' => [
        'file' => 'src/frontend/components/cashier/shifts.php',
        'needles' => ['isRegisterLocked(', 'CashierShiftService::LOCKED_MESSAGE'],
    ],
    'Logging out leaves the Cashier Shift open' => [
        'file' => 'src/frontend/components/auth/logout.php',
        'needles' => ['logout_user()'],
        'forbidden' => ['cashier_shifts'],
    ],
    // Held sales belong to the Cashier Shift that authorized them (ticket #91).
    'A held sale is parked under the Cashier and shift that own it' => [
        'file' => 'src/backend/app/Services/HeldSaleService.php',
        'needles' => [
            'requireHoldingShift',
            'requireOwnUnresolved',
            'requireCashierWorkspace',
            'lockOpenShift($cashierId, true)',
            "const UNRESOLVED_STATUSES = ['held', 'resumed']",
            'Held sale not found or already resolved.',
        ],
        // The shift is derived from the authenticated Cashier, so no request can
        // park a cart on somebody else's drawer. Proved by behaviour in
        // held_sale_shift_contract.php; asserted here so a reintroduction is loud.
        'forbidden' => ["\$_SESSION", "\$_POST['shift_id']", "\$_GET['shift_id']"],
    ],
    'A held sale is discarded with a reason, and only for a reason' => [
        'file' => 'src/backend/app/Services/HeldSaleService.php',
        'needles' => [
            'const DISCARD_REASONS',
            "'other'",
            'Add a note explaining why this held sale was discarded.',
            "'discarded'",
            "'discard_reason'",
            "'discard_note'",
            "'Held sale discarded'",
            'AuditRecordCategory::STORE_OPERATION',
        ],
        // A discard has no cash effect at all, so it must not reach the drawer.
        'forbidden' => ['cash_drawer_movements'],
    ],
    'A Cashier Shift cannot close over an unresolved held sale' => [
        'file' => 'src/backend/app/Services/CashierShiftService.php',
        'needles' => [
            'unresolvedHeldSales',
            'requireNoUnresolvedHeldSales',
            'Complete or discard',
        ],
    ],
    'A resumed held sale is completed by the checkout that pays for it' => [
        'file' => 'src/backend/app/Services/SalesWorkflowService.php',
        'needles' => [
            'resolveResumedHeldSale',
            'assertCompletable',
            'markCompleted',
        ],
    ],
    'Only a held sale on no open Cashier Shift expires on its own' => [
        'file' => 'src/backend/app/Services/HeldSaleService.php',
        'needles' => ['sweepExpiredOrphans', "cs.status = 'open'"],
        // Expiry is the one path around both the discard reason and the closure
        // invariant, so it must never touch a cart an open shift is waiting on.
        'forbidden' => ['DROP TABLE', 'DELETE FROM held_sales'],
    ],
    'Held sale ownership ships in an upgrade migration' => [
        'file' => 'src/backend/database/migrations/202609290005_held_sale_shift_ownership.php',
        'needles' => [
            'Schema::addColumnIfMissing',
            "'discard_reason'",
            "'discarded_by'",
            'idx_held_sales_shift_status',
            'fk_held_sales_shift',
            'DROP FOREIGN KEY',
            'referential_constraints',
            "'RESTRICT'",
        ],
        'forbidden' => ["\nfunction "],
    ],
    'Fresh schema ships the same held sale ownership structure' => [
        'file' => 'src/backend/sql/schema.sql',
        'needles' => [
            "enum('held','resumed','discarded','completed','expired')",
            'KEY `idx_held_sales_shift_status` (`shift_id`,`status`)',
            'CONSTRAINT `fk_held_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`shift_id`) ON DELETE RESTRICT',
            'CONSTRAINT `fk_held_sales_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE SET NULL',
        ],
    ],
    'The point of sale discards a held sale with a reason, not a bare cancel' => [
        'file' => 'src/frontend/components/cashier/pos.php',
        'needles' => [
            'HeldSaleService::DISCARD_REASONS',
            'id="discard-modal"',
            'id="discard-reason"',
            'discard_reason: reason',
            'confirmDiscardHeldSale()',
            'id="held-sale-id-input"',
            'resumedHeldSaleId',
        ],
        // There is no way to drop a parked cart without saying why, and no free
        // text standing in for the fixed set of reasons.
        'forbidden' => ["apiHeldSale('cancel'", 'removeHeldSale('],
    ],
    'The closing form names the held sales that are in the way' => [
        'file' => 'src/frontend/components/cashier/shifts.php',
        'needles' => ['unresolvedHeldSales', 'before closing.'],
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
