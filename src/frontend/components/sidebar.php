<?php
// components/sidebar.php
// Include after auth.php has run. Uses current_role() to show relevant links.
$role = current_role();
$notificationCount = 0;
try {
    if (isset($pdo, $_SESSION['user_id'])) {
        $notificationCount = get_notification_count($pdo, (int)$_SESSION['user_id']);
    }
} catch (Throwable $e) {
    $notificationCount = 0;
}
$sidebarUserName = trim((string)($_SESSION['full_name'] ?? 'RetailMind User'));
$sidebarInitials = '';
foreach (preg_split('/\s+/', $sidebarUserName) ?: [] as $namePart) {
    if ($namePart !== '') {
        $sidebarInitials .= strtoupper(substr($namePart, 0, 1));
    }
}
$sidebarInitials = substr($sidebarInitials ?: 'RM', 0, 2);
$sidebarRoleLabel = ucwords(str_replace('_', ' ', (string)$role));
$commandProductTarget = in_array($role, ['admin', 'super_admin', 'inventory_manager'], true) ? app_url('components/inventory_management/products.php') : app_url('components/cashier/pos.php');

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$currentPath = '/' . trim(str_replace('\\', '/', $currentPath), '/');

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
    return $classes !== '' ? ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"' : '';
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
    $isUserManagement = ($item['path'] ?? '') === 'components/modals/manage_users.php';
    $isAuditLog = ($item['path'] ?? '') === 'components/modals/audit_log.php';
    $isFiscalPeriods = ($item['path'] ?? '') === 'components/modals/fiscal_periods.php';
    $isSystemHealth = ($item['path'] ?? '') === 'components/modals/system_health.php';
    ?>
    <a href="<?= sidebar_e(app_url($item['path'])) ?>"<?= sidebar_active_attr($item['path'], $extraClass) ?><?= $isUserManagement ? ' data-user-management-open' : '' ?><?= $isAuditLog ? ' data-audit-log-open' : '' ?><?= $isFiscalPeriods ? ' data-fiscal-periods-open' : '' ?><?= $isSystemHealth ? ' data-system-health-open' : '' ?> title="<?= sidebar_e($item['label']) ?>"><i class="bi <?= sidebar_e($item['icon']) ?>" aria-hidden="true"></i><span><?= sidebar_e($item['label']) ?></span><?php if (!empty($item['badge'])): ?><span class="sidebar-badge"><?= sidebar_e((string)$item['badge']) ?></span><?php endif; ?></a>
    <?php
}

function sidebar_render_dropdown(array $item): void
{
    $paths = sidebar_item_paths($item['items']);
    $openClass = sidebar_dropdown_class($paths);
    ?>
    <div class="dropdown<?= $openClass ?>">
        <button class="dropbtn" aria-expanded="<?= $openClass ? 'true' : 'false' ?>" type="button" title="<?= sidebar_e($item['label']) ?>"><i class="bi <?= sidebar_e($item['icon']) ?>" aria-hidden="true"></i><span><?= sidebar_e($item['label']) ?></span><?php if (!empty($item['badge'])): ?><span class="sidebar-badge"><?= sidebar_e((string)$item['badge']) ?></span><?php endif; ?><i class="bi bi-chevron-right dropdown-chevron" aria-hidden="true"></i></button>
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
        <div class="sidebar-section-title"><?= sidebar_e($section['title']) ?></div>
        <?php foreach ($section['items'] as $item): ?>
            <?php sidebar_render_item($item); ?>
        <?php endforeach; ?>
    </div>
    <?php
}

$notificationItems = [
    ['path' => 'components/notification/notifications.php', 'icon' => 'bi-bell', 'label' => 'View Notifications'],
];

if ($role !== 'cashier') {
    $notificationItems[] = ['path' => 'components/notification/notification_preferences.php', 'icon' => 'bi-gear', 'label' => 'Preferences'];
}

$adminSystemItems = [
    ['path' => 'components/modals/manage_users.php', 'icon' => 'bi-people', 'label' => 'Manage Users'],
    ['path' => 'components/modals/audit_log.php', 'icon' => 'bi-clock-history', 'label' => 'Audit Log'],
    ['path' => 'components/modals/fiscal_periods.php', 'icon' => 'bi-calendar-check', 'label' => 'Fiscal Periods'],
    ['path' => 'components/modals/system_health.php', 'icon' => 'bi-heart-pulse', 'label' => 'System Health'],
    ['path' => 'components/system_administrator/ml_settings.php', 'icon' => 'bi-sliders', 'label' => 'ML Settings'],
    ['path' => 'components/system_administrator/backup_restore.php', 'icon' => 'bi-database-check', 'label' => 'Backup & Restore'],
    ['path' => 'components/system_administrator/system_settings.php', 'icon' => 'bi-gear', 'label' => 'System Settings'],
];

$adminStockItems = [
    ['path' => 'components/inventory_management/inventory_overview.php', 'icon' => 'bi-boxes', 'label' => 'Inventory Overview'],
    ['path' => 'components/inventory_management/inventory_insights.php', 'icon' => 'bi-lightbulb', 'label' => 'Inventory Insights'],
    ['path' => 'components/inventory_management/products.php', 'icon' => 'bi-box-seam', 'label' => 'Products & Stock'],
    ['path' => 'components/inventory_management/transactions.php', 'icon' => 'bi-receipt', 'label' => 'Inventory Transactions'],
    ['path' => 'components/inventory_management/inventory_counts.php', 'icon' => 'bi-sliders', 'label' => 'Inventory Counts'],
    ['path' => 'components/inventory_management/csv_import.php', 'icon' => 'bi-box-arrow-in-down', 'label' => 'CSV Import'],
    ['path' => 'components/inventory_management/reorder_planner.php', 'icon' => 'bi-diagram-3', 'label' => 'Reorder Planning'],
    ['path' => 'components/inventory_management/replenishment_requests.php', 'icon' => 'bi-truck', 'label' => 'Replenishment Requests'],
    ['path' => 'components/inventory_management/suppliers.php', 'icon' => 'bi-building', 'label' => 'Suppliers'],
    ['path' => 'components/inventory_management/purchase_orders.php', 'icon' => 'bi-clipboard-check', 'label' => 'Purchase Orders'],
];

$managerInventoryItems = [
    ['path' => 'components/inventory_management/inventory_overview.php', 'icon' => 'bi-boxes', 'label' => 'Overview'],
    ['path' => 'components/inventory_management/inventory_insights.php', 'icon' => 'bi-lightbulb', 'label' => 'Insights & Risk'],
    ['path' => 'components/inventory_management/products.php', 'icon' => 'bi-box-seam', 'label' => 'Products & Stock'],
    ['path' => 'components/inventory_management/transactions.php', 'icon' => 'bi-receipt', 'label' => 'Transactions'],
    ['path' => 'components/inventory_management/inventory_counts.php', 'icon' => 'bi-sliders', 'label' => 'Inventory Counts'],
    ['path' => 'components/inventory_management/csv_import.php', 'icon' => 'bi-box-arrow-in-down', 'label' => 'CSV Import'],
    ['path' => 'components/inventory_management/reorder_planner.php', 'icon' => 'bi-diagram-3', 'label' => 'Reorder Planning'],
    ['path' => 'components/inventory_management/replenishment_requests.php', 'icon' => 'bi-truck', 'label' => 'Replenishment Requests'],
];

$adminSalesItems = [
    ['path' => 'components/invoice/receipt.php', 'icon' => 'bi-receipt', 'label' => 'Sales Transaction'],
    ['path' => 'components/invoice/reversals.php', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Sales Reversals'],
    ['path' => 'components/invoice/sales_history.php', 'icon' => 'bi-clock-history', 'label' => 'Transaction History'],
    ['path' => 'components/inventory_management/promotions.php', 'icon' => 'bi-percent', 'label' => 'Promotions'],
];

$salesHistoryItems = [
    ['path' => 'components/invoice/receipt.php', 'icon' => 'bi-receipt', 'label' => 'Sales Transaction'],
    ['path' => 'components/invoice/reversals.php', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Sales Reversals'],
    ['path' => 'components/invoice/sales_history.php', 'icon' => 'bi-clock-history', 'label' => 'Sales History'],
];

$reportItems = [
    ['path' => 'components/report/predictions.php', 'icon' => 'bi-graph-up-arrow', 'label' => 'Demand Forecasting'],
    ['path' => 'components/report/forecast_analytics.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Forecast Analytics'],
    ['path' => 'components/report/forecast_exceptions.php', 'icon' => 'bi-exclamation-diamond', 'label' => 'Forecast Exceptions'],
    ['path' => 'components/report/data_readiness.php', 'icon' => 'bi-database-check', 'label' => 'Data Readiness'],
    ['path' => 'components/report/stock_receiving.php', 'icon' => 'bi-box-arrow-in-down', 'label' => 'Stock Receiving'],
    ['path' => 'components/report/inventory_adjustments.php', 'icon' => 'bi-sliders', 'label' => 'Inventory Adjustments'],
    ['path' => 'components/report/report_generation.php', 'icon' => 'bi-file-earmark-bar-graph', 'label' => 'Report Generation'],
];

$warehouseItems = [
    ['path' => 'components/report/stock_receiving.php', 'icon' => 'bi-box-arrow-in-down', 'label' => 'Stock Receiving'],
    ['path' => 'components/report/inventory_adjustments.php', 'icon' => 'bi-sliders', 'label' => 'Inventory Adjustments'],
];

$workspaceSection = [
    'title' => 'Workspace',
    'items' => [
        ['icon' => 'bi-bell', 'label' => 'Notifications', 'badge' => $notificationCount > 0 ? ($notificationCount > 99 ? '99+' : $notificationCount) : null, 'items' => $notificationItems],
    ],
];

$adminSections = [
    [
        'title' => 'Administration',
        'items' => [
            ['path' => 'components/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ['icon' => 'bi-gear', 'label' => 'System Administration', 'items' => $adminSystemItems],
        ],
    ],
    [
        'title' => 'Inventory',
        'items' => [
            ['icon' => 'bi-boxes', 'label' => 'Inventory Management', 'items' => $adminStockItems],
            ['icon' => 'bi-receipt', 'label' => 'Sales', 'items' => $adminSalesItems],
            ['icon' => 'bi-file-earmark-bar-graph', 'label' => 'Reports', 'items' => $reportItems],
        ],
    ],
];

$roleSections = [
    'admin' => $adminSections,
    'super_admin' => $adminSections,
    'inventory_manager' => [
        [
            'title' => 'Operations',
            'items' => [
                ['path' => 'components/inventory_management/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
                ['icon' => 'bi-boxes', 'label' => 'Inventory', 'items' => $managerInventoryItems],
                [
                    'icon' => 'bi-truck',
                    'label' => 'Replenishment',
                    'items' => [
                        ['path' => 'components/inventory_management/replenishment_requests.php', 'icon' => 'bi-truck', 'label' => 'Requests'],
                        ['path' => 'components/report/predictions.php', 'icon' => 'bi-graph-up-arrow', 'label' => 'Forecasts'],
                        ['path' => 'components/report/forecast_analytics.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Analytics'],
                        ['path' => 'components/report/forecast_exceptions.php', 'icon' => 'bi-exclamation-diamond', 'label' => 'Exceptions'],
                        ['path' => 'components/inventory_management/suppliers.php', 'icon' => 'bi-building', 'label' => 'Suppliers'],
                        ['path' => 'components/inventory_management/purchase_orders.php', 'icon' => 'bi-clipboard-check', 'label' => 'Purchase Orders'],
                        ['path' => 'components/report/data_readiness.php', 'icon' => 'bi-database-check', 'label' => 'Data Readiness'],
                    ],
                ],
            ],
        ],
        [
            'title' => 'Reports',
            'items' => [
                ['icon' => 'bi-receipt', 'label' => 'Sales', 'items' => $salesHistoryItems],
            ],
        ],
    ],
    'cashier' => [
        [
            'title' => 'Sales',
            'items' => [
                ['path' => 'components/cashier/pos.php', 'icon' => 'bi-cart-check', 'label' => 'Point of Sale'],
                ['path' => 'components/cashier/shifts.php', 'icon' => 'bi-cash-stack', 'label' => 'Cashier Shift'],
                ['path' => 'components/cashier/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Cashier Dashboard'],
                ['icon' => 'bi-boxes', 'label' => 'Warehouse', 'items' => $warehouseItems],
            ],
        ],
        [
            'title' => 'Documents',
            'items' => [
                ['icon' => 'bi-receipt', 'label' => 'Invoices', 'items' => $salesHistoryItems],
            ],
        ],
    ],
];

$sections = $roleSections[$role] ?? [];
?>
<script>
(function() {
    var href = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css';
    if (document.head && !document.querySelector('link[href="' + href + '"]')) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }
})();
</script>
<button type="button" class="menu-toggle" id="menuToggle" aria-label="Open menu" aria-expanded="false" aria-controls="appSidebar">
    <span></span><span></span><span></span>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></div>
        <div class="sidebar-brand-copy">
            <h2>RetailMind</h2>
            <span>Inventory &amp; Forecasting</span>
        </div>
        <button type="button" class="sidebar-collapse" id="sidebarCollapse" aria-label="Collapse sidebar" title="Collapse sidebar">
            <i class="bi bi-layout-sidebar-inset-reverse" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <?php sidebar_render_section($workspaceSection); ?>

        <?php foreach ($sections as $section): ?>
            <?php sidebar_render_section($section); ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <button type="button" class="sidebar-profile" id="sidebarProfile" aria-expanded="false" aria-controls="sidebarProfileMenu">
            <span class="sidebar-avatar"><?= sidebar_e($sidebarInitials) ?></span>
            <span class="sidebar-profile-copy">
                <strong><?= sidebar_e($sidebarUserName) ?></strong>
                <span><?= sidebar_e($sidebarRoleLabel) ?></span>
            </span>
            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
        </button>
        <div class="sidebar-profile-menu" id="sidebarProfileMenu">
            <a href="<?= sidebar_e(app_url('components/auth/user_info.php')) ?>"><i class="bi bi-person-circle" aria-hidden="true"></i><span>User Info</span></a>
            <?php if ($role !== 'cashier'): ?>
                <a href="<?= sidebar_e(app_url('components/notification/notification_preferences.php')) ?>"><i class="bi bi-sliders" aria-hidden="true"></i><span>Preferences</span></a>
            <?php endif; ?>
            <a href="<?= sidebar_e(app_url('components/auth/logout.php')) ?>" class="sidebar-logout"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Logout</span></a>
        </div>
    </div>
</div>

<div class="global-top-tools" aria-label="Global tools">
    <button type="button" class="global-tool-button" data-command-open title="Search pages (Ctrl+K)"><i class="bi bi-search" aria-hidden="true"></i><span>Search</span></button>
    <span class="global-tool-button connection-status" id="rm-connection-status" aria-live="polite">Online</span>
</div>

<div class="command-overlay" id="commandPalette" aria-hidden="true" data-products-api="<?= sidebar_e(app_url('components/barcodeScanner/apiScanner/products.php')) ?>" data-product-target="<?= sidebar_e($commandProductTarget) ?>">
    <section class="command-dialog" role="dialog" aria-modal="true" aria-label="Search RetailMind">
        <div class="command-search-row">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="commandSearch" placeholder="Search products, reports, settings, and pages..." autocomplete="off">
            <button type="button" class="command-close" data-command-close aria-label="Close search"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="command-results" id="commandResults"></div>
        <div class="command-footer"><span>↑↓ Navigate</span><span>Enter Open</span><span>Esc Close</span></div>
    </section>
</div>

<?php if (in_array($role, ['admin', 'super_admin'], true)): ?>
<div class="user-management-overlay" id="userManagementOverlay" aria-hidden="true">
    <div class="user-management-frame" role="dialog" aria-modal="true" aria-label="Users and access management">
        <button type="button" class="user-management-frame-close" data-user-management-close aria-label="Close user management"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        <iframe title="Users &amp; Access Management" id="userManagementFrame" loading="lazy"></iframe>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var userOverlay = document.getElementById('userManagementOverlay');
    var userFrame = document.getElementById('userManagementFrame');
    if (!userOverlay || !userFrame) return;
    var userSource = <?= json_encode(app_url('components/modals/manage_users.php?embed=1')) ?>;
    function closeUserManagement() {
        userOverlay.classList.remove('open');
        userOverlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('user-management-open');
    }
    document.querySelectorAll('[data-user-management-open]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            userFrame.src = userSource;
            userOverlay.classList.add('open');
            userOverlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('user-management-open');
        });
    });
    userOverlay.querySelectorAll('[data-user-management-close]').forEach(function (button) { button.addEventListener('click', closeUserManagement); });
    userOverlay.addEventListener('click', function (event) { if (event.target === userOverlay) closeUserManagement(); });
    window.addEventListener('message', function (event) { if (event.data && event.data.type === 'close-user-management') closeUserManagement(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && userOverlay.classList.contains('open')) closeUserManagement(); });
});
</script>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'super_admin'], true)): ?>
<div class="system-health-overlay" id="systemHealthOverlay" aria-hidden="true">
    <div class="system-health-frame" role="dialog" aria-modal="true" aria-label="System health">
        <button type="button" class="system-health-frame-close" data-system-health-close aria-label="Close system health"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        <iframe title="System Health" id="systemHealthFrame" loading="lazy"></iframe>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var healthOverlay = document.getElementById('systemHealthOverlay');
    var healthFrame = document.getElementById('systemHealthFrame');
    if (!healthOverlay || !healthFrame) return;
    var healthSource = <?= json_encode(app_url('components/modals/system_health.php?embed=1')) ?>;
    function closeSystemHealth() {
        healthOverlay.classList.remove('open');
        healthOverlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('system-health-open');
    }
    document.querySelectorAll('[data-system-health-open]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            healthFrame.src = healthSource;
            healthOverlay.classList.add('open');
            healthOverlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('system-health-open');
        });
    });
    healthOverlay.querySelectorAll('[data-system-health-close]').forEach(function (button) { button.addEventListener('click', closeSystemHealth); });
    healthOverlay.addEventListener('click', function (event) { if (event.target === healthOverlay) closeSystemHealth(); });
    window.addEventListener('message', function (event) { if (event.data && event.data.type === 'close-system-health') closeSystemHealth(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && healthOverlay.classList.contains('open')) closeSystemHealth(); });
});
</script>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'super_admin'], true)): ?>
<div class="fiscal-periods-overlay" id="fiscalPeriodsOverlay" aria-hidden="true">
    <div class="fiscal-periods-frame" role="dialog" aria-modal="true" aria-label="Fiscal period management">
        <button type="button" class="fiscal-periods-frame-close" data-fiscal-periods-close aria-label="Close fiscal period management"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        <iframe title="Fiscal Period Management" id="fiscalPeriodsFrame" loading="lazy"></iframe>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var fiscalOverlay = document.getElementById('fiscalPeriodsOverlay');
    var fiscalFrame = document.getElementById('fiscalPeriodsFrame');
    if (!fiscalOverlay || !fiscalFrame) return;
    var fiscalSource = <?= json_encode(app_url('components/modals/fiscal_periods.php?embed=1')) ?>;
    function closeFiscalPeriods() {
        fiscalOverlay.classList.remove('open');
        fiscalOverlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('fiscal-periods-open');
    }
    document.querySelectorAll('[data-fiscal-periods-open]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            fiscalFrame.src = fiscalSource;
            fiscalOverlay.classList.add('open');
            fiscalOverlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('fiscal-periods-open');
        });
    });
    fiscalOverlay.querySelectorAll('[data-fiscal-periods-close]').forEach(function (button) { button.addEventListener('click', closeFiscalPeriods); });
    fiscalOverlay.addEventListener('click', function (event) { if (event.target === fiscalOverlay) closeFiscalPeriods(); });
    window.addEventListener('message', function (event) { if (event.data && event.data.type === 'close-fiscal-periods') closeFiscalPeriods(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && fiscalOverlay.classList.contains('open')) closeFiscalPeriods(); });
});
</script>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'super_admin'], true)): ?>
<div class="audit-log-overlay" id="auditLogOverlay" aria-hidden="true">
    <div class="audit-log-frame" role="dialog" aria-modal="true" aria-label="Audit activity log">
        <button type="button" class="audit-log-frame-close" data-audit-log-close aria-label="Close audit log"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        <iframe title="Audit Activity Log" id="auditLogFrame" loading="lazy"></iframe>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var auditOverlay = document.getElementById('auditLogOverlay');
    var auditFrame = document.getElementById('auditLogFrame');
    if (!auditOverlay || !auditFrame) return;
    var auditSource = <?= json_encode(app_url('components/modals/audit_log.php?embed=1')) ?>;
    function closeAuditLog() {
        auditOverlay.classList.remove('open');
        auditOverlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('audit-log-open');
    }
    document.querySelectorAll('[data-audit-log-open]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            auditFrame.src = auditSource;
            auditOverlay.classList.add('open');
            auditOverlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('audit-log-open');
        });
    });
    auditOverlay.querySelectorAll('[data-audit-log-close]').forEach(function (button) { button.addEventListener('click', closeAuditLog); });
    auditOverlay.addEventListener('click', function (event) { if (event.target === auditOverlay) closeAuditLog(); });
    window.addEventListener('message', function (event) { if (event.data && event.data.type === 'close-audit-log') closeAuditLog(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && auditOverlay.classList.contains('open')) closeAuditLog(); });
});
</script>
<?php endif; ?>

<script src="<?= sidebar_e(app_url('assets/js/ui.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var menuToggle = document.getElementById('menuToggle');
    var sidebar = document.getElementById('appSidebar');
    var overlay = document.getElementById('sidebarOverlay');

    function setSidebarOpen(isOpen) {
        sidebar.classList.toggle('open', isOpen);
        overlay.classList.toggle('open', isOpen);
        menuToggle.classList.toggle('active', isOpen);
        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        document.body.classList.toggle('no-scroll', isOpen);
    }

    if (menuToggle && sidebar && overlay) {
        menuToggle.addEventListener('click', function() {
            setSidebarOpen(!sidebar.classList.contains('open'));
        });
        overlay.addEventListener('click', function() {
            setSidebarOpen(false);
        });
        sidebar.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                setSidebarOpen(false);
            });
        });
        window.addEventListener('resize', function() {
            if (window.innerWidth > 900) {
                setSidebarOpen(false);
            }
        });
    }

    document.querySelectorAll('.sidebar .dropdown').forEach(function(dropdown) {
        var button = dropdown.querySelector('.dropbtn');
        if (!button) {
            return;
        }

        button.addEventListener('click', function() {
            document.querySelectorAll('.sidebar .dropdown.open').forEach(function(openDropdown) {
                if (openDropdown === dropdown) {
                    return;
                }

                openDropdown.classList.remove('open');
                var openButton = openDropdown.querySelector('.dropbtn');
                if (openButton) {
                    openButton.setAttribute('aria-expanded', 'false');
                }
            });

            dropdown.classList.toggle('open');
            button.setAttribute('aria-expanded', dropdown.classList.contains('open') ? 'true' : 'false');
        });
        button.addEventListener('mousedown', function(event) {
            event.preventDefault();
        });
    });
});
</script>
