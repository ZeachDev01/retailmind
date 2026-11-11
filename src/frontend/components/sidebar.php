<?php
// components/sidebar.php
// Include after auth.php has run. Uses the active workspace for navigation.
$role = current_role();
$notificationCount = 0;
try {
    if (isset($pdo, $_SESSION['user_id'])) {
        $notificationCount = get_notification_count($pdo, (int)$_SESSION['user_id']);
    }
} catch (Throwable $e) {
    $notificationCount = 0;
}
$sidebarUserName = trim((string)($_SESSION['display_name'] ?? 'RetailMind User'));
$sidebarProfileImage = isset($_SESSION['profile_image']) ? (string)$_SESSION['profile_image'] : null;
$sidebarRoleLabel = ucwords(str_replace('_', ' ', (string)$role));
$workspaceChoices = [
    'super_admin' => ['label' => 'Super Administrator', 'icon' => 'privacy'],
    'admin' => ['label' => 'Administrator', 'icon' => 'shop'],
    'inventory_manager' => ['label' => 'Inventory', 'icon' => 'deployment'],
    'cashier' => ['label' => 'Cashier', 'icon' => 'self_service_kiosk'],
];
$assignedWorkspaceRoles = array_values(array_filter(
    function_exists('current_workspace_roles') ? current_workspace_roles() : ($role === null ? [] : [$role]),
    static fn(string $assignedRole): bool => isset($workspaceChoices[$assignedRole])
));
$avatarStylesheetPath = __DIR__ . '/../assets/css/avatars.css';
$avatarStylesheetVersion = is_file($avatarStylesheetPath) ? (string)filemtime($avatarStylesheetPath) : '1';
$avatarStylesheetUrl = app_url('assets/css/avatars.css') . '?v=' . rawurlencode($avatarStylesheetVersion);
$sidebarBrandBaseUrl = app_url('assets/img/retailmind-web-assets%20dark%20mode/');
$sidebarBrandLogoUrl = $sidebarBrandBaseUrl . 'logos/light/logo-header.png';
$sidebarBrandIconUrl = $sidebarBrandBaseUrl . 'icons/light/icon-64.png';
$commandProductTarget = match ($role) {
    'super_admin' => app_url('components/inventory_management/inventory_overview.php'),
    'admin', 'inventory_manager' => app_url('components/inventory_management/products.php'),
    default => app_url('components/cashier/pos.php'),
};
$mobileHomeTarget = match ($role) {
    'super_admin' => 'components/super_administrator/dashboard.php',
    'admin' => 'components/administrator/dashboard.php',
    'inventory_manager' => 'components/inventory_management/inventory_overview.php',
    default => 'components/cashier/pos.php',
};
$flashMessages = [];
$loginSuccess = (string)($_SESSION['_flash_login_success'] ?? '');
unset($_SESSION['_flash_login_success']);
foreach (['success', 'error', 'warning', 'info'] as $flashType) {
    $flashKey = '_flash_' . $flashType;
    if (!empty($_SESSION[$flashKey])) {
        $flashMessages[$flashType] = (string)$_SESSION[$flashKey];
        unset($_SESSION[$flashKey]);
    }
}

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$currentPath = '/' . trim(str_replace('\\', '/', $currentPath), '/');
$GLOBALS['currentPath'] = $currentPath;

if (!function_exists("sidebar_path")) {
function sidebar_path(string $path): string
{
    $urlPath = parse_url(app_url($path), PHP_URL_PATH) ?: app_url($path);
    return '/' . trim(str_replace('\\', '/', $urlPath), '/');
}

function sidebar_is_active(string $path): bool
{
    global $currentPath;
    return rtrim($currentPath, '/') === rtrim(sidebar_path($path), '/');
}

function sidebar_active_attr(string $path, string $extraClass = ''): string
{
    $classes = trim(($extraClass ? $extraClass . ' ' : '') . (sidebar_is_active($path) ? 'active' : ''));
    return ($classes !== '' ? ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"' : '')
        . (sidebar_is_active($path) ? ' aria-current="page"' : '');
}

function sidebar_dropdown_class(array $paths): string
{
    foreach ($paths as $path) {
        if (sidebar_is_active($path)) {
            return ' open';
        }
    }

    return '';
}

function sidebar_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sidebar_icon(string $name, string $class = ''): string
{
    return '<img class="navigation-icon ' . sidebar_e($class) . '" src="'
        . sidebar_e(app_url('assets/img/navigation/' . $name . '.svg'))
        . '" alt="" aria-hidden="true" width="22" height="22" loading="lazy">';
}

function sidebar_item_paths(array $items): array
{
    $paths = [];
    foreach ($items as $item) {
        if (isset($item['path'])) {
            $paths[] = $item['path'];
        }
    }

    return $paths;
}

function sidebar_render_link(array $item, string $extraClass = ''): void
{
?>
    <a href="<?= sidebar_e(app_url($item['path'])) ?>" <?= sidebar_active_attr($item['path'], $extraClass) ?> title="<?= sidebar_e($item['label']) ?>"><?php if (!empty($item['image'])): ?><img class="sidebar-nav-icon" src="<?= sidebar_e(app_url($item['image'])) ?>" alt="" width="22" height="22" loading="lazy"><?php else: ?><?= sidebar_icon($item['icon']) ?><?php endif; ?><span><?= sidebar_e($item['label']) ?></span><?php if (!empty($item['badge'])): ?><span class="sidebar-badge"><?= sidebar_e((string)$item['badge']) ?></span><?php endif; ?></a>
<?php
}

function sidebar_render_dropdown(array $item): void
{
    $paths = sidebar_item_paths($item['items']);
    $openClass = sidebar_dropdown_class($paths);
?>
    <div class="dropdown<?= $openClass ?>">
        <button type="button" class="dropbtn" aria-expanded="<?= $openClass ? 'true' : 'false' ?>" title="<?= sidebar_e($item['label']) ?>"><?= sidebar_icon($item['icon']) ?><span><?= sidebar_e($item['label']) ?></span><?php if (!empty($item['badge'])): ?><span class="sidebar-badge"><?= sidebar_e((string)$item['badge']) ?></span><?php endif; ?><?= sidebar_icon('next', 'bi-chevron-right dropdown-chevron') ?></button>
        <div class="dropdown-content">
            <?php foreach ($item['items'] as $child): ?>
                <?php sidebar_render_link($child); ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php
}

function sidebar_render_item(array $item): void
{
    if (isset($item['items'])) {
        sidebar_render_dropdown($item);
        return;
    }

    sidebar_render_link($item);
}

function sidebar_render_section(array $section): void
{
?>
    <div class="sidebar-section">
        <?php if (!empty($section['title'])): ?>
            <div class="sidebar-section-title"><?= sidebar_e($section['title']) ?></div>
        <?php endif; ?>
        <?php foreach ($section['items'] as $item): ?>
            <?php sidebar_render_item($item); ?>
        <?php endforeach; ?>
    </div>
<?php
}

function sidebar_render_remote_access_pill(string $idSuffix = 'desktop', ?string $role = null, bool $isOnline = false, string $tunnelUrl = ''): string
{
    $hasUrl = $tunnelUrl !== '';
    $statusText = $isOnline ? ($hasUrl ? 'Online' : 'Connecting') : 'Offline';
    $pillLabel = $isOnline ? ($hasUrl ? 'Remote Online' : 'Connecting...') : 'Remote Offline';
    $descText = $isOnline
        ? ($hasUrl ? 'Public access is verified through the Store tunnel. RetailMind sign-in and Workspace permissions apply.' : 'Tunnel is active. Resolving secure public URL...')
        : 'Remote access is offline. Local Store PC checkout and operations run normally.';
    $apiUrl = app_url('components/administrator/remote_access_api.php');
    $canManage = in_array($role, ['admin', 'super_admin'], true);
    $settingsUrl = app_url('components/administrator/store_settings.php?section=remote-access');

    $html = '<div class="remote-pill-wrapper" data-remote-access-wrapper data-remote-api="' . sidebar_e($apiUrl) . '">';
    $html .= '<button type="button" class="remote-status-pill ' . ($isOnline ? 'is-online' : 'is-offline') . '" id="remoteStatusPill-' . sidebar_e($idSuffix) . '" data-remote-pill-trigger aria-expanded="false" aria-haspopup="true" aria-label="Remote Access: ' . $statusText . '" title="Remote Access: ' . $statusText . '">';
    $html .= '<span class="remote-status-dot ' . ($isOnline ? 'is-online' : 'is-offline') . '" aria-hidden="true"></span>';
    $html .= '<span class="remote-status-label">' . $pillLabel . '</span>';
    $html .= '<span class="remote-status-caret" aria-hidden="true">&#9662;</span>';
    $html .= '</button>';

    $html .= '<div class="remote-status-popover u-hidden" data-remote-popover role="dialog" aria-label="Remote Access Details">';
    $html .= '<div class="remote-popover-header">';
    $html .= '<div class="remote-popover-heading">';
    $html .= '<span class="remote-popover-icon" aria-hidden="true">' . sidebar_icon('deployment', 'bi-broadcast-pin') . '</span>';
    $html .= '<span class="remote-popover-title">Remote Access</span>';
    $html .= '</div>';
    $html .= '<span class="remote-badge ' . ($isOnline ? 'badge-online' : 'badge-offline') . '" data-remote-badge>' . $statusText . '</span>';
    $html .= '</div>';

    $html .= '<div class="remote-popover-body">';
    $html .= '<p class="remote-popover-desc" data-remote-desc>' . $descText . '</p>';

    $html .= '<div class="remote-popover-url-row ' . ($isOnline ? '' : 'u-hidden') . '" data-remote-url-row>';
    $html .= '<label class="u-sr-only" for="remoteUrlInput-' . sidebar_e($idSuffix) . '">Active Remote URL</label>';
    $html .= '<input type="text" id="remoteUrlInput-' . sidebar_e($idSuffix) . '" class="remote-popover-url-input" data-remote-url-input readonly value="' . sidebar_e($tunnelUrl) . '" placeholder="Resolving URL..." aria-label="Active Remote URL">';
    $html .= '<button type="button" class="remote-copy-btn" data-remote-copy-btn aria-label="Copy remote URL">';
    $html .= '<span class="remote-copy-icon" aria-hidden="true">&#128203;</span>';
    $html .= '<span class="remote-copy-text" data-remote-copy-text>Copy</span>';
    $html .= '</button>';
    $html .= '</div>';
    $html .= '<div class="remote-copy-feedback u-hidden" data-remote-feedback role="status" aria-live="polite">URL copied to clipboard!</div>';

    if ($canManage) {
        $html .= '<div class="remote-popover-footer">';
        $html .= '<a href="' . sidebar_e($settingsUrl) . '" class="remote-settings-link">Manage in Store Settings &rarr;</a>';
        $html .= '</div>';
    }

    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}
}

$superAdministratorSystemItems = [
    ['path' => 'components/user_manager/user_manager.php', 'icon' => 'privacy', 'label' => 'Users & Access'],
    ['path' => 'components/system_administrator/audit_logs.php', 'icon' => 'inspection', 'label' => 'Protected Audit Records'],
    ['path' => 'components/system_administrator/system_health.php', 'icon' => 'support', 'label' => 'System Health'],
    ['path' => 'components/administrator/reset_data.php', 'icon' => 'delete_database', 'label' => 'Reset Data'],
    ['path' => 'components/system_administrator/backup_restore.php', 'icon' => 'data_recovery', 'label' => 'Backup & Restore'],
    ['path' => 'components/system_administrator/system_settings.php', 'icon' => 'data_configuration', 'label' => 'Platform Settings'],
];

$superAdministratorForecastingItems = [
    ['path' => 'components/system_administrator/forecast_import.php', 'icon' => 'filing_cabinet', 'label' => 'Import Sales History'],
    ['path' => 'components/report/data_readiness.php', 'icon' => 'accept_database', 'label' => 'Data Readiness'],
    ['path' => 'components/system_administrator/ml_settings.php', 'icon' => 'process', 'label' => 'Model Training'],
    ['path' => 'components/report/forecast_analytics.php', 'icon' => 'combo_chart', 'label' => 'Forecast Analytics'],
    ['path' => 'components/report/predictions.php', 'icon' => 'positive_dynamic', 'label' => 'Demand Forecast'],
    ['path' => 'components/report/forecast_exceptions.php', 'icon' => 'disclaimer', 'label' => 'Forecast Exceptions'],
];

$administratorSystemItems = [
    ['path' => 'components/user_manager/user_manager.php', 'icon' => 'conference_call', 'label' => 'User Manager'],
    ['path' => 'components/administrator/registers.php', 'icon' => 'self_service_kiosk', 'label' => 'Checkout Stations'],
    ['path' => 'components/administrator/store_settings.php', 'icon' => 'settings', 'label' => 'Store Configuration'],
    ['path' => 'components/administrator/store_calendar.php', 'icon' => 'calendar', 'label' => 'Store Calendar'],
    ['path' => 'components/system_administrator/fiscal_periods.php', 'icon' => 'planner', 'label' => 'Fiscal Periods'],
    ['path' => 'components/administrator/database_backup.php', 'icon' => 'data_backup', 'label' => 'Database Backup'],
    ['path' => 'components/system_administrator/audit_logs.php', 'icon' => 'inspection', 'label' => 'Operational Audit'],
];

$managerInventoryItems = [];

$administratorForecastingItems = [
    ['path' => 'components/report/data_readiness.php', 'icon' => 'accept_database', 'label' => 'Data Readiness'],
    ['path' => 'components/administrator/model_review.php', 'icon' => 'combo_chart', 'label' => 'Model Review & Approval'],
    ['path' => 'components/report/predictions.php', 'icon' => 'positive_dynamic', 'label' => 'Demand Forecast'],
    ['path' => 'components/report/forecast_exceptions.php', 'icon' => 'disclaimer', 'label' => 'Forecast Exceptions'],
];

$administratorReportItems = [
    ['path' => 'components/administrator/shift_report.php', 'icon' => 'overtime', 'label' => 'Cashier Shifts'],
    ['path' => 'components/report/receiving_history.php', 'icon' => 'filing_cabinet', 'label' => 'Receiving History'],
    ['path' => 'components/invoice/sales.php', 'icon' => 'document', 'label' => 'Receipt History'],
    ['path' => 'components/report/report_generation.php', 'icon' => 'statistics', 'label' => 'Generate Report'],
];

$superAdministratorSections = [
    [
        'title' => 'Platform Governance',
        'items' => [
            ['path' => 'components/super_administrator/dashboard.php', 'icon' => 'services', 'label' => 'Control Center'],
            ['icon' => 'privacy', 'label' => 'Platform Administration', 'items' => $superAdministratorSystemItems],
            ['icon' => 'positive_dynamic', 'label' => 'Forecasting', 'items' => $superAdministratorForecastingItems],
        ],
    ],
];

$administratorSections = [
    [
        'title' => 'Store Operations',
        'items' => [
            ['path' => 'components/administrator/dashboard.php', 'icon' => 'shop', 'label' => 'Store Overview'],
            ['path' => 'components/administrator/gcash_overview.php', 'image' => 'assets/img/gcash-brandmark.svg', 'label' => 'GCash Overview'],
            ['icon' => 'business', 'label' => 'Store Management', 'items' => $administratorSystemItems],
            ['path' => 'components/inventory_management/promotions.php', 'icon' => 'advertising', 'label' => 'Promotions'],
            ['path' => 'components/administrator/stock_issues.php', 'icon' => 'high_priority', 'label' => 'Stock Issue Oversight'],
            ['icon' => 'positive_dynamic', 'label' => 'Forecasting', 'items' => $administratorForecastingItems],
            ['icon' => 'statistics', 'label' => 'Store Reports', 'items' => $administratorReportItems],
        ],
    ],
];

$roleSections = [
    'super_admin' => $superAdministratorSections,
    'admin' => $administratorSections,
    'inventory_manager' => [
        [
            'items' => [
                ['path' => 'components/inventory_management/inventory_overview.php', 'icon' => 'deployment', 'label' => 'Stock Overview'],
                ['path' => 'components/inventory_management/products.php', 'icon' => 'package', 'label' => 'Products'],
                ['path' => 'components/inventory_management/replenishment_queue.php', 'icon' => 'workflow', 'label' => 'Replenishment Recommendations'],
                ['path' => 'components/report/stock_receiving.php', 'icon' => 'shipped', 'label' => 'Stock Receiving'],
                ['path' => 'components/report/receiving_history.php', 'icon' => 'filing_cabinet', 'label' => 'Receiving History'],
                ['icon' => 'settings', 'label' => 'Inventory Control', 'items' => [
                    ['path' => 'components/inventory_management/inventory_counts.php', 'icon' => 'survey', 'label' => 'Inventory Counts'],
                    ['path' => 'components/inventory_management/stock_issues.php', 'icon' => 'high_priority', 'label' => 'Stock Issue Reviews'],
                    ['path' => 'components/inventory_management/supplier_pullouts.php', 'icon' => 'export', 'label' => 'Supplier Pullouts'],
                ]],
            ],
        ],
        [
            'items' => [
                ['icon' => 'statistics', 'label' => 'Reports', 'items' => [

                    ['path' => 'components/report/predictions.php', 'icon' => 'positive_dynamic', 'label' => 'Demand Forecast'],
                    ['path' => 'components/inventory_management/inventory_insights.php', 'icon' => 'idea', 'label' => 'Insights & Risk'],
                    ['path' => 'components/invoice/sales.php', 'icon' => 'document', 'label' => 'Receipt History'],
                    ['path' => 'components/report/report_generation.php', 'icon' => 'statistics', 'label' => 'Generate Report'],
                ]],
            ],
        ],
    ],
    'cashier' => [
        [
            'title' => 'Sales',
            'items' => [
                ['path' => 'components/cashier/pos.php', 'icon' => 'self_service_kiosk', 'label' => 'Point of Sale'],
                ['path' => 'components/cashier/gcash.php', 'image' => 'assets/img/gcash-brandmark.svg', 'label' => 'GCash'],
                ['path' => 'components/cashier/shifts.php', 'icon' => 'overtime', 'label' => 'Cashier Shift'],
                ['path' => 'components/cashier/refunds.php', 'icon' => 'money_transfer', 'label' => 'Cash Refunds'],
                ['path' => 'components/cashier/dashboard.php', 'icon' => 'sales_performance', 'label' => 'Dashboard Overview'],
            ],
        ],
        [
            'title' => 'Documents',
            'items' => [
                ['path' => 'components/cashier/history.php', 'icon' => 'timeline', 'label' => 'Transaction History'],
                ['path' => 'components/invoice/sales.php', 'icon' => 'document', 'label' => 'Receipt History'],
                ['path' => 'components/cashier/stock_issues.php', 'icon' => 'high_priority', 'label' => 'Report Stock Issue'],
            ],
        ],
    ],
];

if (in_array($role, ['admin', 'cashier', 'inventory_manager'], true)) {
    $roleSections[$role][0]['items'][] = ['path' => 'components/cashier/refund_corrections.php', 'icon' => 'money_transfer', 'label' => 'Refund Correction Reviews'];
}

$sections = $roleSections[$role] ?? [];
$GLOBALS['rm_navigation_flash'] = $flashMessages;
$GLOBALS['rm_navigation_shell'] = [
    'name' => $sidebarUserName,
    'avatar' => profile_avatar_html((int)($_SESSION['user_id'] ?? 0), $sidebarUserName, $sidebarProfileImage, 'sidebar-avatar'),
    'mobileAvatar' => profile_avatar_html((int)($_SESSION['user_id'] ?? 0), $sidebarUserName, $sidebarProfileImage),
    'notifications' => $notificationCount,
];
?>
<!--rm-shell-start-->
<link rel="stylesheet" href="<?= sidebar_e(app_url('assets/css/page-navigation.css') . '?v=' . filemtime(__DIR__ . '/../assets/css/page-navigation.css')) ?>">
<script src="<?= sidebar_e(app_url('assets/js/page-navigation.js') . '?v=' . filemtime(__DIR__ . '/../assets/js/page-navigation.js')) ?>" data-workspace="<?= sidebar_e((string)$role) ?>"></script>
<link rel="stylesheet" href="<?= sidebar_e($avatarStylesheetUrl) ?>" data-avatar-styles>
<script>
    (function() {
        var href = <?= json_encode(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var link = document.querySelector('link[href="' + href + '"]');
        if (document.head && !link) {
            link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            document.head.appendChild(link);
        }
        if (link) link.removeAttribute('data-rm-page-asset');
    })();
</script>
<div class="admin-mobile-topbar" aria-label="Mobile navigation">
    <div class="admin-mobile-brand">
        <button type="button" class="admin-mobile-menu" id="menuToggle" aria-label="Open menu" aria-expanded="false" aria-controls="appSidebar">
            <?= sidebar_icon('menu') ?>
        </button>
        <a class="admin-mobile-logo" href="<?= sidebar_e(app_url($mobileHomeTarget)) ?>" aria-label="RetailMind home">
            <img class="admin-mobile-logo-image" src="<?= sidebar_e($sidebarBrandIconUrl) ?>" data-brand-base="<?= sidebar_e($sidebarBrandBaseUrl) ?>" data-brand-variant="icons/STYLE/icon-64.png" alt="">
            <span class="admin-mobile-logo-copy"><strong>RetailMind</strong><small>Inventory &amp; Forecasting</small></span>
        </a>
    </div>
    <div class="admin-mobile-tools">
        <button type="button" class="admin-mobile-search" data-command-open aria-label="Search pages">
            <?= sidebar_icon('search', 'bi-search') ?>
        </button>
        <button type="button" class="admin-mobile-avatar" data-account-menu-open aria-label="Open account menu" aria-expanded="false" aria-controls="sidebarAccountMenu">
            <?= profile_avatar_html((int)($_SESSION['user_id'] ?? 0), $sidebarUserName, $sidebarProfileImage) ?>
        </button>
    </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="sidebar-tip" id="rmSidebarTip" role="tooltip" aria-hidden="true"></div>
<div class="sidebar" id="appSidebar">
    <button type="button" class="sidebar-brand" id="sidebarBrandToggle" aria-label="Collapse sidebar" aria-expanded="true" aria-controls="appSidebar">
        <span class="brand-icon" aria-hidden="true"><img src="<?= sidebar_e($sidebarBrandIconUrl) ?>" data-brand-base="<?= sidebar_e($sidebarBrandBaseUrl) ?>" data-brand-variant="icons/STYLE/icon-64.png" alt=""></span>
        <span class="sidebar-brand-copy">
            <span class="sidebar-brand-title">RetailMind</span>
            <span>Inventory &amp; Forecasting</span>
        </span>
    </button>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <?php foreach ($sections as $section): ?>
            <?php sidebar_render_section($section); ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <button type="button" class="sidebar-profile" id="sidebarProfile" data-account-menu-open aria-expanded="false" aria-controls="sidebarAccountMenu" aria-label="<?= sidebar_e($sidebarUserName . ', ' . $sidebarRoleLabel) ?>" title="<?= sidebar_e($sidebarUserName . ' · ' . $sidebarRoleLabel) ?>">
            <?= profile_avatar_html((int)($_SESSION['user_id'] ?? 0), $sidebarUserName, $sidebarProfileImage, 'sidebar-avatar') ?>
            <span class="sidebar-profile-copy">
                <strong><?= sidebar_e($sidebarUserName) ?></strong>
                <span><?= sidebar_e($sidebarRoleLabel) ?></span>
            </span>
        </button>
    </div>
</div>

<nav class="sidebar-account-menu" id="sidebarAccountMenu" aria-label="Account options" aria-hidden="true">
        <div class="sidebar-account-actions">
            <a href="<?= sidebar_e(app_url('components/auth/user_info.php')) ?>">
                <?= sidebar_icon('contacts', 'bi-person-circle') ?>
                <span>User</span>
                <?= sidebar_icon('next', 'bi-chevron-right') ?>
            </a>
            <details class="sidebar-workspace-switcher">
                <summary class="sidebar-account-workspace">
                    <?= sidebar_icon('grid', 'bi-grid') ?>
                    <span>Workspace</span>
                    <?= sidebar_icon('next', 'bi-chevron-right sidebar-workspace-chevron') ?>
                </summary>
                <div class="sidebar-workspace-options">
                    <?php foreach ($assignedWorkspaceRoles as $assignedRole): ?>
                        <?php $workspaceChoice = $workspaceChoices[$assignedRole]; ?>
                        <?php if ($assignedRole === $role): ?>
                            <div class="sidebar-workspace-option is-current" aria-current="page">
                                <?= sidebar_icon($workspaceChoice['icon']) ?>
                                <span><?= sidebar_e($workspaceChoice['label']) ?></span>
                                <small>Current</small>
                            </div>
                        <?php else: ?>
                            <form method="post" data-rm-backup-bypass="true" action="<?= sidebar_e(app_url('components/auth/workspace.php')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="workspace" value="<?= sidebar_e($assignedRole) ?>">
                                <button type="submit" class="sidebar-workspace-option">
                                    <?= sidebar_icon($workspaceChoice['icon']) ?>
                                    <span><?= sidebar_e($workspaceChoice['label']) ?></span>
                                    <?= sidebar_icon('right', 'bi-arrow-right') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </details>
            <details class="sidebar-preferences-menu">
                <summary class="sidebar-account-preferences">
                    <?= sidebar_icon('settings', 'bi-sliders') ?>
                    <span>Settings</span>
                    <?= sidebar_icon('next', 'bi-chevron-right sidebar-preferences-chevron') ?>
                </summary>
                <div class="sidebar-preferences-options">
                    <div class="sidebar-theme-target" data-sidebar-theme-target></div>
                    <button type="button" class="sidebar-preference-option" data-fullscreen-toggle aria-pressed="false">
                        <?= sidebar_icon('expand', 'bi-arrows-fullscreen') ?>
                        <span>Full Screen</span>
                    </button>
                    <?php if ($role !== 'cashier'): ?>
                        <a href="<?= sidebar_e(app_url('components/auth/preferences.php')) ?>">
                            <?= sidebar_icon('notification', 'bi-bell') ?>
                            <span>Notifications</span>
                            <?= sidebar_icon('next', 'bi-chevron-right') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </details>
            <?php if (in_array($role, ['admin', 'inventory_manager'], true)): ?>
            <a href="<?= sidebar_e(app_url('components/auth/help.php')) ?>">
                <?= sidebar_icon('disclaimer', 'bi-question-circle') ?><span>Help</span>
            </a>
            <?php endif; ?>
            <a href="<?= sidebar_e(app_url('components/auth/logout.php')) ?>" class="sidebar-account-logout">
                <?= sidebar_icon('external', 'bi-box-arrow-right') ?>
                <span>Logout</span>
                <?= sidebar_icon('next', 'bi-chevron-right') ?>
            </a>
        </div>
</nav>

<div class="global-topbar" aria-label="Global tools">
    <button type="button" class="global-search-trigger" data-command-open aria-label="Search products, reports, settings, and pages">
        <?= sidebar_icon('search') ?>
        <span>Search products, reports, settings, and pages…</span>
        <kbd>Ctrl&nbsp;K</kbd>
    </button>
    <a class="global-notification-button" href="<?= sidebar_e(app_url('components/notification/notifications.php')) ?>" aria-label="Notifications<?= $notificationCount > 0 ? ': ' . (int)$notificationCount . ' unread' : '' ?>">
        <?= sidebar_icon('notification') ?>
        <span>Notifications</span>
        <?php if ($notificationCount > 0): ?><strong><?= $notificationCount > 99 ? '99+' : (int)$notificationCount ?></strong><?php endif; ?>
    </a>
</div>

<div class="command-overlay" id="commandPalette" aria-hidden="true" data-products-api="<?= sidebar_e(app_url('components/barcodeScanner/apiScanner/products.php')) ?>" data-product-target="<?= sidebar_e($commandProductTarget) ?>" data-product-icon="<?= sidebar_e(app_url('assets/img/navigation/package.svg')) ?>">
    <section class="command-dialog" role="dialog" aria-modal="true" aria-label="Search RetailMind">
        <div class="command-search-row">
            <?= sidebar_icon('search', 'bi-search') ?>
            <input type="search" id="commandSearch" aria-label="Search RetailMind" placeholder="Search products, reports, settings, and pages…" autocomplete="off">
            <button type="button" class="command-close" data-command-close aria-label="Close search"><?= sidebar_icon('close', 'bi-x-lg') ?></button>
        </div>
        <div class="command-results" id="commandResults"></div>
        <div class="command-footer"><span>↑↓ Navigate</span><span>Enter Open</span><span>Esc Close</span></div>
    </section>
</div>

<div id="rm-flash-messages" hidden data-login-success="<?= sidebar_e($loginSuccess) ?>"<?php foreach ($flashMessages as $flashType => $flashMessage): ?> data-<?= sidebar_e($flashType) ?>="<?= sidebar_e($flashMessage) ?>" <?php endforeach; ?>></div>
<link rel="stylesheet" href="<?= sidebar_e(app_url('assets/vendor/sweetalert2/sweetalert2.min.css')) ?>">
<script src="<?= sidebar_e(app_url('assets/vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script>window.RM_DEBUG = <?= !empty($GLOBALS['app']['debug']) ? 'true' : 'false' ?>;</script>
<script src="<?= sidebar_e(app_url('assets/js/ui.js') . '?v=' . filemtime(__DIR__ . '/../assets/js/ui.js')) ?>"></script>
<script src="<?= sidebar_e(app_url('assets/js/sidebar-loader.js') . '?v=' . filemtime(__DIR__ . '/../assets/js/sidebar-loader.js')) ?>"></script>
<?php
require_once __DIR__ . '/../../backend/app/Services/CashierShiftService.php';
require_once __DIR__ . '/../../backend/app/Services/HeldSaleService.php';
try {
    $cartRecoveryShift = ($role === 'cashier' && isset($pdo)) ? (new \App\Services\CashierShiftService($pdo))->getOpenShift((int)$_SESSION['user_id']) : null;
} catch (Throwable) {
    $cartRecoveryShift = null;
}
$cartWorkspaceContext = [
    'cashier' => (int)$_SESSION['user_id'], 'shift' => (int)($cartRecoveryShift['shift_id'] ?? 0),
    'workspace' => $role, 'locked' => !empty($cartRecoveryShift['locked_at']),
    'endpoint' => app_url('components/barcodeScanner/apiScanner/held_sales.php'),
    'csrf' => csrf_token(), 'reasons' => \App\Services\HeldSaleService::DISCARD_REASONS,
];
$GLOBALS['rm_navigation_cart'] = $cartWorkspaceContext;
?>
<script src="<?= sidebar_e(app_url('assets/js/cart-workspace.js') . '?v=' . filemtime(__DIR__ . '/../assets/js/cart-workspace.js')) ?>"></script>
<script>
window.cartWorkspace = new CartWorkspace(<?= json_encode($cartWorkspaceContext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
cartWorkspace.bindNavigation();
</script>

<link rel="stylesheet" href="<?= sidebar_e(app_url('assets/css/backup.css')) ?>">
<script>
    // Shared Database Backup status (ticket #69): every signed-in session is
    // told when saving is briefly paused, including sessions that open or
    // reconnect while a snapshot is being captured.
    document.body.setAttribute('data-rm-authenticated', 'true');
    document.body.setAttribute('data-rm-backup-status', <?= json_encode(app_url('components/backup/backup_status.php'), JSON_UNESCAPED_SLASHES) ?>);
</script>
<script src="<?= sidebar_e(app_url('assets/js/backup_status.js')) ?>"></script>
<?php require __DIR__ . '/auth/session_activity_client.php'; ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var menuToggle = document.getElementById('menuToggle');
        var mobileBrandToggle = document.getElementById('mobileBrandToggle');
        var brandToggle = document.getElementById('sidebarBrandToggle');
        var sidebar = document.getElementById('appSidebar');
        var overlay = document.getElementById('sidebarOverlay');
        var collapseStorageKey = 'retailmind_sidebar_collapsed';

        try {
            if (window.innerWidth > 900 && localStorage.getItem(collapseStorageKey) === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (_) { /* Navigation remains usable when browser storage is blocked. */ }

        function isMobileDrawer() {
            return window.innerWidth <= 900;
        }

        function updateBrandState() {
            var isOpen = sidebar && sidebar.classList.contains('open');
            var isCollapsed = document.body.classList.contains('sidebar-collapsed');
            var expanded = isMobileDrawer() ? isOpen : !isCollapsed;
            if (sidebar) sidebar.inert = isMobileDrawer() && !isOpen;
            var main = document.querySelector('.main-content');
            if (main) main.inert = isMobileDrawer() && isOpen;

            if (brandToggle) {
                brandToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                brandToggle.setAttribute('aria-label', expanded ? 'Collapse sidebar' : 'Expand sidebar');
            }
            if (mobileBrandToggle) {
                mobileBrandToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                mobileBrandToggle.setAttribute('aria-label', isOpen ? 'Close sidebar' : 'Open sidebar');
            }
        }

        function setSidebarOpen(isOpen) {
            var restoreFocus = !isOpen && sidebar.contains(document.activeElement);
            sidebar.classList.toggle('open', isOpen);
            overlay.classList.toggle('open', isOpen);
            if (menuToggle) {
                menuToggle.classList.toggle('active', isOpen);
                menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                menuToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
                var menuIcon = menuToggle.querySelector('img.navigation-icon');
                if (menuIcon) {
                    menuIcon.src = new URL(isOpen ? 'close.svg' : 'menu.svg', menuIcon.src).href;
                }
            }
            if (window.RetailMindUI?.syncScrollLock) RetailMindUI.syncScrollLock();
            else document.body.classList.toggle('no-scroll', isOpen);
            updateBrandState();
            if (isOpen) sidebar.querySelector('.sidebar-nav a, .sidebar-nav button')?.focus();
            else if (restoreFocus) menuToggle?.focus();
        }

        function setSidebarCollapsed(isCollapsed) {
            document.body.classList.toggle('sidebar-collapsed', isCollapsed);
            try { localStorage.setItem(collapseStorageKey, isCollapsed ? '1' : '0'); }
            catch (_) { /* Navigation still works without browser storage. */ }
            updateBrandState();
            closeSidebarDropdowns();
            hideNavTip();
        }

        function toggleBrandSidebar() {
            if (!sidebar || !overlay) {
                return;
            }

            if (isMobileDrawer()) {
                setSidebarOpen(!sidebar.classList.contains('open'));
                return;
            }

            setSidebarCollapsed(!document.body.classList.contains('sidebar-collapsed'));
        }

        if (menuToggle && sidebar && overlay) {
            menuToggle.addEventListener('click', function() {
                setSidebarOpen(!sidebar.classList.contains('open'));
            });
        }
        if (sidebar && overlay) {
            sidebar.addEventListener('click', function(event) {
                if (isMobileDrawer() && event.target.closest('a[href]')) setSidebarOpen(false);
            });
            overlay.addEventListener('click', function() {
                setSidebarOpen(false);
            });
            window.addEventListener('resize', function() {
                if (window.innerWidth > 900) {
                    setSidebarOpen(false);
                }
                updateBrandState();
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && !event.defaultPrevented && sidebar.classList.contains('open')) {
                    setSidebarOpen(false);
                    menuToggle?.focus();
                }
            });
        }
        if (brandToggle) {
            brandToggle.addEventListener('click', toggleBrandSidebar);
        }
        if (mobileBrandToggle) {
            mobileBrandToggle.addEventListener('click', toggleBrandSidebar);
        }
        updateBrandState();

        var sidebarTip = document.getElementById('rmSidebarTip');
        var tipTarget = null;
        var tipSelector = '.sidebar a[href], .sidebar .dropbtn, .sidebar .sidebar-profile';

        function hideNavTip() {
            if (tipTarget && tipTarget.dataset.rmTipTitle) {
                tipTarget.setAttribute('title', tipTarget.dataset.rmTipTitle);
                delete tipTarget.dataset.rmTipTitle;
            }
            tipTarget = null;
            if (sidebarTip) {
                sidebarTip.classList.remove('visible');
                sidebarTip.setAttribute('aria-hidden', 'true');
            }
        }

        function showNavTip(target, replaceTitle) {
            if (!sidebarTip || !document.body.classList.contains('sidebar-collapsed') || isMobileDrawer()) {
                return;
            }
            if (target.closest('.dropdown-content')) {
                return;
            }
            var label = target.dataset.rmTipTitle || target.getAttribute('title');
            if (!label) {
                return;
            }
            hideNavTip();
            // Hover shows the card, so the native tooltip would duplicate it. Focus keeps title as the accessible name.
            if (replaceTitle) {
                target.dataset.rmTipTitle = label;
                target.removeAttribute('title');
            }
            tipTarget = target;

            sidebarTip.textContent = label;
            var rect = target.getBoundingClientRect();
            var railRight = sidebar ? sidebar.getBoundingClientRect().right : rect.right;
            sidebarTip.style.left = (Math.max(rect.right, railRight) + 10) + 'px';
            sidebarTip.style.top = (rect.top + rect.height / 2) + 'px';
            sidebarTip.classList.add('visible');
            sidebarTip.setAttribute('aria-hidden', 'false');

            var tipRect = sidebarTip.getBoundingClientRect();
            var shift = 0;
            if (tipRect.top < 8) {
                shift = 8 - tipRect.top;
            }
            if (tipRect.bottom + shift > window.innerHeight - 8) {
                shift -= tipRect.bottom + shift - (window.innerHeight - 8);
            }
            if (shift) {
                sidebarTip.style.top = (rect.top + rect.height / 2 + shift) + 'px';
            }
        }

        function closeSidebarDropdowns(except) {
            document.querySelectorAll('.sidebar .dropdown.open').forEach(function(openDropdown) {
                if (openDropdown === except) {
                    return;
                }

                openDropdown.classList.remove('open');
                var openButton = openDropdown.querySelector('.dropbtn');
                if (openButton) {
                    openButton.setAttribute('aria-expanded', 'false');
                }
            });
        }

        function placeSidebarFlyout(dropdown) {
            if (isMobileDrawer() || !document.body.classList.contains('sidebar-collapsed')) {
                return;
            }
            var button = dropdown.querySelector('.dropbtn');
            var panel = dropdown.querySelector('.dropdown-content');
            if (!button || !panel) {
                return;
            }
            var rect = button.getBoundingClientRect();
            var top = Math.min(Math.max(8, rect.top), Math.max(8, window.innerHeight - panel.offsetHeight - 8));
            panel.style.setProperty('--rm-flyout-top', top + 'px');
            var railRight = sidebar ? sidebar.getBoundingClientRect().right : rect.right;
            panel.style.setProperty('--rm-flyout-left', (railRight + 8) + 'px');
        }

        function positionOpenFlyouts() {
            if (isMobileDrawer() || !document.body.classList.contains('sidebar-collapsed')) {
                return;
            }
            document.querySelectorAll('.sidebar .dropdown.open').forEach(placeSidebarFlyout);
        }

        if (sidebar) {
            sidebar.addEventListener('mouseover', function(event) {
                var target = event.target.closest(tipSelector);
                if (target) {
                    showNavTip(target, true);
                }
            });
            sidebar.addEventListener('mouseout', function(event) {
                var target = event.target.closest(tipSelector);
                if (target && !target.contains(event.relatedTarget)) {
                    hideNavTip();
                }
            });
            sidebar.addEventListener('focusin', function(event) {
                var target = event.target.closest(tipSelector);
                if (target) {
                    showNavTip(target, false);
                }
            });
            sidebar.addEventListener('focusout', function(event) {
                var target = event.target.closest(tipSelector);
                if (target) {
                    hideNavTip();
                }
            });
            sidebar.addEventListener('scroll', function() {
                closeSidebarDropdowns();
                hideNavTip();
            }, { passive: true });
        }

        window.addEventListener('retailmind:navigationstart', function() {
            if (!isMobileDrawer() && document.body.classList.contains('sidebar-collapsed')) {
                closeSidebarDropdowns();
                hideNavTip();
            }
        });

        document.addEventListener('click', function(event) {
            if (!document.body.classList.contains('sidebar-collapsed')) {
                return;
            }
            if (event.target.closest('.sidebar .dropdown')) {
                return;
            }
            closeSidebarDropdowns();
        });
        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape' || event.defaultPrevented) {
                return;
            }
            var focusedDropdown = document.activeElement ? document.activeElement.closest('.sidebar .dropdown') : null;
            closeSidebarDropdowns();
            hideNavTip();
            if (focusedDropdown) {
                var ownerButton = focusedDropdown.querySelector('.dropbtn');
                if (ownerButton) {
                    ownerButton.focus();
                }
            }
        });
        var wasMobileDrawer = isMobileDrawer();
        window.addEventListener('resize', function() {
            if (isMobileDrawer() || wasMobileDrawer) {
                closeSidebarDropdowns();
            } else {
                positionOpenFlyouts();
            }
            wasMobileDrawer = isMobileDrawer();
            hideNavTip();
        });

        document.querySelectorAll('.sidebar .dropdown').forEach(function(dropdown) {
            var button = dropdown.querySelector('.dropbtn');
            if (!button) {
                return;
            }

            button.addEventListener('click', function() {
                closeSidebarDropdowns(dropdown);

                dropdown.classList.toggle('open');
                button.setAttribute('aria-expanded', dropdown.classList.contains('open') ? 'true' : 'false');
                if (dropdown.classList.contains('open')) {
                    hideNavTip();
                    placeSidebarFlyout(dropdown);
                }
            });
        });

        // Active-page groups start expanded in PHP; collapsed flyouts require an explicit click.
        if (!isMobileDrawer() && document.body.classList.contains('sidebar-collapsed')) {
            closeSidebarDropdowns();
        }
    });
</script>
<script src="<?= htmlspecialchars(app_url('assets/js/sw-register.js'), ENT_QUOTES, 'UTF-8') ?>" data-sw="<?= htmlspecialchars(app_url('sw.js'), ENT_QUOTES, 'UTF-8') ?>" data-scope="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php if (in_array($role, ['admin', 'inventory_manager'], true)): ?>
<link rel="stylesheet" href="<?= sidebar_e(app_url('assets/css/workspace-guidance.css') . '?v=' . filemtime(__DIR__ . '/../assets/css/workspace-guidance.css')) ?>">
<?php endif; ?>
<!--rm-shell-end-->

<?php if (in_array($role, ['admin', 'inventory_manager'], true)): ?>
<?php include __DIR__ . '/auth/workspace_guidance.php'; ?>
<?php endif; ?>
