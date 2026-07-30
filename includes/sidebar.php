<?php
// includes/sidebar.php
// Include after auth.php has run. Uses current_role() to show relevant links.
$role = current_role();

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
    <a href="<?= sidebar_e(app_url($item['path'])) ?>"<?= sidebar_active_attr($item['path'], $extraClass) ?>><i class="bi <?= sidebar_e($item['icon']) ?>" aria-hidden="true"></i><span><?= sidebar_e($item['label']) ?></span></a>
    <?php
}

function sidebar_render_dropdown(array $item): void
{
    $paths = sidebar_item_paths($item['items']);
    $openClass = sidebar_dropdown_class($paths);
    ?>
    <div class="dropdown<?= $openClass ?>">
        <button class="dropbtn" aria-expanded="<?= $openClass ? 'true' : 'false' ?>" type="button"><i class="bi <?= sidebar_e($item['icon']) ?>" aria-hidden="true"></i><span><?= sidebar_e($item['label']) ?></span></button>
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
    ['path' => 'notification/notifications.php', 'icon' => 'bi-bell', 'label' => 'View Notifications'],
    ['path' => 'notification/notification_preferences.php', 'icon' => 'bi-gear', 'label' => 'Preferences'],
];

$adminSystemItems = [
    ['path' => 'admin/manage_users.php', 'icon' => 'bi-people', 'label' => 'Manage Users'],
    ['path' => 'admin/system_settings.php', 'icon' => 'bi-gear', 'label' => 'System Settings'],
    ['path' => 'admin/ml_settings.php', 'icon' => 'bi-sliders', 'label' => 'ML Settings'],
    ['path' => 'admin/backup_restore.php', 'icon' => 'bi-database-check', 'label' => 'Backup & Restore'],
    ['path' => 'admin/fiscal_periods.php', 'icon' => 'bi-calendar-check', 'label' => 'Fiscal Periods'],
    ['path' => 'admin/audit_log.php', 'icon' => 'bi-clock-history', 'label' => 'Audit Log'],
];

$adminStockItems = [
    ['path' => 'manager/inventory_overview.php', 'icon' => 'bi-boxes', 'label' => 'Inventory Overview'],
    ['path' => 'manager/products.php', 'icon' => 'bi-box-seam', 'label' => 'Products Management'],
    ['path' => 'manager/transactions.php', 'icon' => 'bi-receipt', 'label' => 'Inventory Transactions'],
    ['path' => 'manager/inventory_counts.php', 'icon' => 'bi-sliders', 'label' => 'Inventory Counts'],
    ['path' => 'manager/csv_import.php', 'icon' => 'bi-box-arrow-in-down', 'label' => 'CSV Import'],
    ['path' => 'manager/replenishment_requests.php', 'icon' => 'bi-truck', 'label' => 'Replenishment Requests'],
];

$managerInventoryItems = [
    ['path' => 'manager/inventory_overview.php', 'icon' => 'bi-boxes', 'label' => 'Overview'],
    ['path' => 'manager/products.php', 'icon' => 'bi-box-seam', 'label' => 'Products & Stock'],
    ['path' => 'manager/transactions.php', 'icon' => 'bi-receipt', 'label' => 'Transactions'],
    ['path' => 'manager/inventory_counts.php', 'icon' => 'bi-sliders', 'label' => 'Inventory Counts'],
    ['path' => 'manager/csv_import.php', 'icon' => 'bi-box-arrow-in-down', 'label' => 'CSV Import'],
    ['path' => 'manager/replenishment_requests.php', 'icon' => 'bi-truck', 'label' => 'Replenishment Requests'],
];

$adminSalesItems = [
    ['path' => 'invoice/receipt.php', 'icon' => 'bi-receipt', 'label' => 'Sales Transaction'],
    ['path' => 'invoice/reversals.php', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Sales Reversals'],
    ['path' => 'invoice/sales_history.php', 'icon' => 'bi-clock-history', 'label' => 'Transaction History'],
];

$salesHistoryItems = [
    ['path' => 'invoice/receipt.php', 'icon' => 'bi-receipt', 'label' => 'Sales Transaction'],
    ['path' => 'invoice/reversals.php', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Sales Reversals'],
    ['path' => 'invoice/sales_history.php', 'icon' => 'bi-clock-history', 'label' => 'Sales History'],
];

$reportItems = [
    ['path' => 'report/predictions.php', 'icon' => 'bi-graph-up-arrow', 'label' => 'Demand Forecasting'],
    ['path' => 'report/forecast_analytics.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Forecast Analytics'],
    ['path' => 'report/data_readiness.php', 'icon' => 'bi-database-check', 'label' => 'Data Readiness'],
    ['path' => 'report/stock_receiving.php', 'icon' => 'bi-box-arrow-in-down', 'label' => 'Stock Receiving'],
    ['path' => 'report/inventory_adjustments.php', 'icon' => 'bi-sliders', 'label' => 'Damage Reporting'],
    ['path' => 'report/report_generation.php', 'icon' => 'bi-file-earmark-bar-graph', 'label' => 'Report Generation'],
];

$warehouseItems = [
    ['path' => 'report/stock_receiving.php', 'icon' => 'bi-box-arrow-in-down', 'label' => 'Stock Receiving'],
    ['path' => 'report/inventory_adjustments.php', 'icon' => 'bi-sliders', 'label' => 'Damage Report'],
];

$workspaceSection = [
    'title' => 'Workspace',
    'items' => [
        ['icon' => 'bi-bell', 'label' => 'Notifications', 'items' => $notificationItems],
    ],
];

$adminSections = [
    [
        'title' => 'Administration',
        'items' => [
            ['path' => 'admin/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ['icon' => 'bi-gear', 'label' => 'System Administration', 'items' => $adminSystemItems],
        ],
    ],
    [
        'title' => 'Inventory',
        'items' => [
            ['icon' => 'bi-boxes', 'label' => 'Stock', 'items' => $adminStockItems],
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
                ['path' => 'manager/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
                ['icon' => 'bi-boxes', 'label' => 'Inventory', 'items' => $managerInventoryItems],
                [
                    'icon' => 'bi-truck',
                    'label' => 'Replenishment',
                    'items' => [
                        ['path' => 'manager/replenishment_requests.php', 'icon' => 'bi-truck', 'label' => 'Requests'],
                        ['path' => 'report/predictions.php', 'icon' => 'bi-graph-up-arrow', 'label' => 'Forecasts'],
                        ['path' => 'report/forecast_analytics.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Analytics'],
                        ['path' => 'report/data_readiness.php', 'icon' => 'bi-database-check', 'label' => 'Data Readiness'],
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
                ['path' => 'cashier/pos.php', 'icon' => 'bi-cart-check', 'label' => 'Point of Sale'],
                ['path' => 'cashier/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'My Sales'],
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
        <div>
            <h2>InvenSys</h2>
            <span>Inventory Control</span>
        </div>
    </div>

    <?php sidebar_render_section($workspaceSection); ?>

    <?php foreach ($sections as $section): ?>
        <?php sidebar_render_section($section); ?>
    <?php endforeach; ?>

    <?php sidebar_render_link(['path' => 'logout.php', 'icon' => 'bi-box-arrow-right', 'label' => 'Logout'], 'sidebar-logout'); ?>
</div>
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
