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
$mobileHomeTarget = $role === 'cashier' ? 'components/cashier/pos.php' : ($role === 'inventory_manager' ? 'components/inventory_management/dashboard.php' : 'components/dashboard.php');
$flashMessages = [];
foreach (['success', 'error', 'warning', 'info'] as $flashType) {
    $flashKey = '_flash_' . $flashType;
    if (!empty($_SESSION[$flashKey])) {
        $flashMessages[$flashType] = (string)$_SESSION[$flashKey];
        unset($_SESSION[$flashKey]);
    }
}

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
?>
    <a href="<?= sidebar_e(app_url($item['path'])) ?>" <?= sidebar_active_attr($item['path'], $extraClass) ?> title="<?= sidebar_e($item['label']) ?>"><i class="bi <?= sidebar_e($item['icon']) ?>" aria-hidden="true"></i><span><?= sidebar_e($item['label']) ?></span><?php if (!empty($item['badge'])): ?><span class="sidebar-badge"><?= sidebar_e((string)$item['badge']) ?></span><?php endif; ?></a>
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

$adminSystemItems = [
    ['path' => 'components/user_manager/user_manager.php', 'icon' => 'bi-people', 'label' => 'Manage Users'],
    ['path' => 'components/system_administrator/audit_logs.php', 'icon' => 'bi-clock-history', 'label' => 'Audit Logs'],
    ['path' => 'components/system_administrator/fiscal_periods.php', 'icon' => 'bi-calendar-check', 'label' => 'Fiscal Periods'],
    ['path' => 'components/system_administrator/system_health.php', 'icon' => 'bi-heart-pulse', 'label' => 'System Health'],
    ['path' => 'components/system_administrator/ml_settings.php', 'icon' => 'bi-sliders', 'label' => 'ML Settings'],
    ['path' => 'components/system_administrator/backup_restore.php', 'icon' => 'bi-database-check', 'label' => 'Backup & Restore'],
    ['path' => 'components/system_administrator/system_settings.php', 'icon' => 'bi-gear', 'label' => 'System Settings'],
];

$adminStockItems = [
    ['path' => 'components/inventory_management/inventory_overview.php', 'icon' => 'bi-boxes', 'label' => 'Inventory Overview'],
    ['path' => 'components/inventory_management/inventory_insights.php', 'icon' => 'bi-lightbulb', 'label' => 'Inventory Insights'],
    ['path' => 'components/inventory_management/products.php', 'icon' => 'bi-box-seam', 'label' => 'Products & Stock'],
    ['path' => 'components/invoice/transactions.php', 'icon' => 'bi-receipt', 'label' => 'Inventory Transactions'],
    ['path' => 'components/inventory_management/inventory_counts.php', 'icon' => 'bi-sliders', 'label' => 'Inventory Counts'],
    ['path' => 'components/inventory_management/csv_import.php', 'icon' => 'bi-box-arrow-in-down', 'label' => 'CSV Import'],
    ['path' => 'components/inventory_management/reorder_planner.php', 'icon' => 'bi-diagram-3', 'label' => 'Reorder Planning'],
    ['path' => 'components/inventory_management/replenishment_requests.php', 'icon' => 'bi-truck', 'label' => 'Replenishment Requests'],
    ['path' => 'components/inventory_management/suppliers.php', 'icon' => 'bi-building', 'label' => 'Suppliers'],
    ['path' => 'components/invoice/purchase_orders.php', 'icon' => 'bi-clipboard-check', 'label' => 'Purchase Orders'],
];

$managerInventoryItems = [
    ['path' => 'components/inventory_management/inventory_overview.php', 'icon' => 'bi-boxes', 'label' => 'Overview'],
    ['path' => 'components/inventory_management/inventory_insights.php', 'icon' => 'bi-lightbulb', 'label' => 'Insights & Risk'],
    ['path' => 'components/inventory_management/products.php', 'icon' => 'bi-box-seam', 'label' => 'Products & Stock'],
    ['path' => 'components/invoice/transactions.php', 'icon' => 'bi-receipt', 'label' => 'Transactions'],
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
                        ['path' => 'components/invoice/purchase_orders.php', 'icon' => 'bi-clipboard-check', 'label' => 'Purchase Orders'],
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
<?php if (!isset($isEmbedded) || !$isEmbedded): ?>
    <div class="admin-mobile-topbar" aria-label="Mobile navigation">
        <div class="admin-mobile-brand">
            <button type="button" class="admin-mobile-menu" id="menuToggle" aria-label="Open menu" aria-expanded="false" aria-controls="appSidebar">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <a class="admin-mobile-logo" href="<?= sidebar_e(app_url($mobileHomeTarget)) ?>" aria-label="RetailMind home">
                <span class="admin-mobile-logo-mark"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                <span class="admin-mobile-logo-text">RetailMind</span>
            </a>
        </div>
        <div class="admin-mobile-tools">
            <button type="button" class="admin-mobile-search" data-command-open aria-label="Search pages">
                <i class="bi bi-search" aria-hidden="true"></i>
            </button>
            <a class="admin-mobile-avatar" href="<?= sidebar_e(app_url('components/auth/user_info.php')) ?>" aria-label="Open user information">
                <?= sidebar_e($sidebarInitials) ?>
            </a>
        </div>
    </div>
<?php endif; ?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></div>
        <div class="sidebar-brand-copy">
            <h2>RetailMind</h2>
            <span>Inventory &amp; Forecasting</span>
        </div>
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
                <a href="<?= sidebar_e(app_url('components/auth/preferences.php')) ?>"><i class="bi bi-sliders" aria-hidden="true"></i><span>Preferences</span></a>
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
            <iframe title="Users &amp; Access Management" id="userManagementFrame" loading="lazy"></iframe>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var userOverlay = document.getElementById('userManagementOverlay');
            var userFrame = document.getElementById('userManagementFrame');
            if (!userOverlay || !userFrame) return;
            var userSource = <?= json_encode(app_url('components/user_manager/user_manager.php?embed=1')) ?>;

            function closeUserManagement() {
                userOverlay.classList.remove('open');
                userOverlay.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('user-management-open');
            }
            document.querySelectorAll('[data-user-management-open]').forEach(function(link) {
                link.addEventListener('click', function(event) {
                    event.preventDefault();
                    userFrame.src = userSource;
                    userOverlay.classList.add('open');
                    userOverlay.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('user-management-open');
                });
            });
            userOverlay.querySelectorAll('[data-user-management-close]').forEach(function(button) {
                button.addEventListener('click', closeUserManagement);
            });
            userOverlay.addEventListener('click', function(event) {
                if (event.target === userOverlay) closeUserManagement();
            });
            window.addEventListener('message', function(event) {
                if (event.data && event.data.type === 'close-user-management') closeUserManagement();
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && userOverlay.classList.contains('open')) closeUserManagement();
            });
        });
    </script>
<?php endif; ?>

<div id="rm-flash-messages" hidden<?php foreach ($flashMessages as $flashType => $flashMessage): ?> data-<?= sidebar_e($flashType) ?>="<?= sidebar_e($flashMessage) ?>" <?php endforeach; ?>></div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            menuToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
            var menuIcon = menuToggle.querySelector('i');
            if (menuIcon) {
                menuIcon.className = 'bi ' + (isOpen ? 'bi-x-lg' : 'bi-list');
            }
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
