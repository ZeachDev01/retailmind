<?php
// app.php
require_once __DIR__ . '/../../backend/includes/auth.php';
require_once __DIR__ . '/../../backend/includes/functions.php';
require_once __DIR__ . '/../../backend/app/Services/SalesService.php';
require_once __DIR__ . '/../../backend/app/Services/DashboardService.php';
require_role(['admin']);

$salesService = new SalesService($pdo);
$dashboardService = new DashboardService($pdo);

$rangeDays = in_array((int)($_GET['range'] ?? 30), [7, 30, 90], true) ? (int)($_GET['range'] ?? 30) : 30;
$adminMetrics = $dashboardService->getAdminMetrics($rangeDays);
$salesSummary = $salesService->getSalesSummary();
$recentTransactions = $salesService->getRecentTransactions();
$recentCriticalActions = $dashboardService->getRecentCriticalActions();
$salesTrend = $dashboardService->getSalesTrend($rangeDays);
$inventoryByCategory = $dashboardService->getInventoryValueByCategory(8);
$adminAttentionCount = ($adminMetrics['open_periods'] > 1 ? 1 : 0) + count($recentCriticalActions);
$lastUpdated = date('M d, Y g:i A');
$adminRoleLabel = ucwords(str_replace('_', ' ', (string)current_role()));
$adminName = trim((string)($_SESSION['full_name'] ?? 'System Admin'));
$adminInitials = '';
foreach (preg_split('/\s+/', $adminName) ?: [] as $namePart) {
    if ($namePart !== '') {
        $adminInitials .= strtoupper(substr($namePart, 0, 1));
    }
}
$adminInitials = substr($adminInitials ?: 'SA', 0, 2);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body class="admin-dashboard-page">
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
        <header class="admin-mobile-topbar" aria-label="Mobile dashboard header">
            <div class="admin-mobile-brand">
                <button type="button" class="admin-mobile-menu" data-admin-mobile-menu aria-label="Open menu">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <a class="admin-mobile-logo" href="<?= htmlspecialchars(app_url('components/dashboard.php')) ?>" aria-label="RetailMind dashboard">
                    <span class="admin-mobile-logo-mark"><i class="bi bi-archive" aria-hidden="true"></i></span>
                    <span class="admin-mobile-logo-text">RetailMind</span>
                </a>
            </div>
            <div class="admin-mobile-tools">
                <button type="button" class="admin-mobile-search" data-command-open aria-label="Search pages">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </button>
                <a class="admin-mobile-avatar" href="<?= htmlspecialchars(app_url('components/auth/user_info.php')) ?>" aria-label="Open user information">
                    <?= htmlspecialchars($adminInitials) ?>
                </a>
            </div>
        </header>
        <header class="page-heading">
            <div>
                <h1>Admin Dashboard</h1>
                <p class="page-subtitle"><span class="desktop-subtitle">Review access, compliance, sales activity, system health, and inventory value.</span><span class="mobile-subtitle">Review access, compliance, and health.</span></p>
            </div>
            <div class="page-heading-actions">
                <form method="GET"><select class="u-select-filter" name="range" onchange="this.form.submit()" aria-label="Dashboard range"><option value="7" <?= $rangeDays === 7 ? 'selected' : '' ?>>Last 7 days</option><option value="30" <?= $rangeDays === 30 ? 'selected' : '' ?>>Last 30 days</option><option value="90" <?= $rangeDays === 90 ? 'selected' : '' ?>>Last 90 days</option></select></form>
                <a class="btn btn-quiet btn-icon" href="?range=<?= $rangeDays ?>"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
            </div>
        </header>

        <div class="quick-actions">
            <a class="quick-action" href="<?= htmlspecialchars(app_url('components/modals/manage_users.php')) ?>" data-user-management-open>
                <i class="bi bi-person" aria-hidden="true"></i>
                <strong>Users</strong>
                <span>Control access and team roles</span>
            </a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('components/inventory_management/inventory_overview.php')) ?>">
                <i class="bi bi-box-seam" aria-hidden="true"></i>
                <strong>Inventory</strong>
                <span>Review stock and product levels</span>
            </a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">
                <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
                <strong>Demand Forecasting</strong>
                <span>Review demand predictions and reorder suggestions</span>
            </a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('components/report/forecast_analytics.php')) ?>">
                <i class="bi bi-bar-chart-line" aria-hidden="true"></i>
                <strong>Forecast Analytics</strong>
                <span>Compare forecast performance and actual demand</span>
            </a>
        </div>

        <div class="card-grid">
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('components/cashier/shifts.php')) ?>"><article class="stat-card with-icon <?= $adminMetrics['active_cashiers_today'] > 0 ? 'success' : 'warning' ?>"><span class="stat-icon"><i class="bi bi-person-check-fill"></i></span><div class="value"><?= (int)$adminMetrics['active_cashiers_today'] ?></div><div class="label">Active Cashier Sessions</div><div class="hint">Cashiers with sales today</div><div class="stat-meta"><span>Review shifts</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('components/invoice/receipt.php')) ?>"><article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-cash-stack"></i></span><div class="value">₱<?= number_format((float)$adminMetrics['period_sales'], 2) ?></div><div class="label">Sales — <?= $rangeDays ?> Days</div><div class="hint">Lifetime: ₱<?= number_format((float)$adminMetrics['total_sales'], 2) ?></div><div class="stat-meta"><span>View receipts</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('components/modals/audit_log.php')) ?>" data-audit-log-open><article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-clock-history"></i></span><div class="value"><?= (int)$adminMetrics['audit_events'] ?></div><div class="label">Audit Events</div><div class="hint">Actions in the activity log</div><div class="stat-meta"><span>Review activity</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('components/modals/fiscal_periods.php')) ?>" data-fiscal-periods-open><article class="stat-card with-icon <?= $adminMetrics['open_periods'] > 1 ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-calendar-check"></i></span><div class="value"><?= (int)$adminMetrics['open_periods'] ?> / <?= (int)$adminMetrics['closed_periods'] ?></div><div class="label">Open / Closed Periods</div><div class="hint">Close old periods promptly</div><div class="stat-meta"><span>Manage periods</span><i class="bi bi-arrow-right"></i></div></article></a>
        </div>

        <section class="dashboard-section admin-attention-section u-mb-15">
            <div class="section-header"><div><h3>Administrative Attention</h3><p class="section-description">Items that may require review or compliance action.</p></div><span class="decision-pill <?= $adminAttentionCount ? 'action' : 'ok' ?>"><?= $adminAttentionCount ? $adminAttentionCount . ' item(s)' : 'All clear' ?></span></div>
            <div class="attention-list">
                <?php if ($adminMetrics['open_periods'] > 1): ?><div class="attention-item"><span class="attention-icon"><i class="bi bi-calendar-x"></i></span><span class="attention-copy"><strong>Multiple fiscal periods are open</strong><span>Review completed periods and close them to prevent late entries.</span></span><a class="btn btn-small" href="<?= htmlspecialchars(app_url('components/modals/fiscal_periods.php')) ?>" data-fiscal-periods-open>Review</a></div><?php endif; ?>
                <?php if ($recentCriticalActions): ?><div class="attention-item"><span class="attention-icon"><i class="bi bi-shield-exclamation"></i></span><span class="attention-copy"><strong><?= count($recentCriticalActions) ?> recent critical audit action(s)</strong><span>Review reversals, adjustments, locking, deletion, or void activity.</span></span><a class="btn btn-small" href="<?= htmlspecialchars(app_url('components/modals/audit_log.php')) ?>" data-audit-log-open>Review</a></div><?php endif; ?>
                <?php if (!$adminAttentionCount): ?><div class="empty-state u-empty-min-120"><div><i class="bi bi-shield-check"></i><strong>No urgent administrative issues</strong><span>Fiscal-period and critical-audit indicators are currently clear.</span></div></div><?php endif; ?>
            </div>
        </section>

        <div class="dashboard-layout equal">
            <section class="dashboard-section"><div class="section-header"><div><h3>Sales Trend</h3><p class="section-description">Daily sales for the selected dashboard range.</p></div></div><canvas id="adminSalesChart" height="125"></canvas></section>
            <section class="dashboard-section"><div class="section-header"><div><h3>Inventory Value by Category</h3><p class="section-description">Current inventory cost distribution.</p></div><a href="<?= htmlspecialchars(app_url('components/inventory_management/inventory_overview.php')) ?>">Inventory overview</a></div><canvas id="adminCategoryChart" height="125"></canvas></section>
        </div>

        <div class="dashboard-section u-mt-15">
            <div class="section-header">
                <div>
                    <h3>Recent Sales</h3>
                    <p class="section-description">Today's total: &#8369;<?= number_format((float)$salesSummary['total_sales_today'], 2) ?></p>
                </div>
            </div>
            <div class="table-wrap admin-sales-table">
                <table>
                    <tr><th>Receipt #</th><th>Total Amount</th><th>Items</th><th>Payment</th><th>Cashier</th><th>Date</th><th>Action</th></tr>
                    <?php foreach ($recentTransactions as $tx): ?>
                    <tr>
                        <td><?= htmlspecialchars($tx['receipt_no']) ?></td>
                        <td>&#8369;<?= number_format((float)$tx['total_amount'], 2) ?></td>
                        <td><?= (int)$tx['item_count'] ?></td>
                        <td><?= htmlspecialchars($tx['payment_method']) ?></td>
                        <td><?= htmlspecialchars($tx['cashier_name']) ?></td>
                        <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($tx['sale_date']))) ?></td>
                        <td>
                            <button type="button" class="view-receipt-btn" data-sale-id="<?= (int)$tx['sale_id'] ?>" style="background:none;border:none;color:#3b82f6;text-decoration:none;cursor:pointer;padding:0;font:inherit;">View</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentTransactions): ?>
                    <tr><td class="u-empty-cell" colspan="7">No recent sales recorded yet.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="receiptModal" class="receipt-modal" aria-hidden="true">
    <div class="receipt-modal-content" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle">
        <div class="receipt-modal-header">
            <h3 id="receiptModalTitle">Receipt Details</h3>
            <button type="button" class="receipt-modal-close" id="closeReceiptModal" aria-label="Close receipt details">&times;</button>
        </div>
        <div class="receipt-modal-body" id="receiptModalBody">
            <div class="receipt-modal-loading">Loading receipt details...</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('receiptModal');
    const modalBody = document.getElementById('receiptModalBody');
    const closeButton = document.getElementById('closeReceiptModal');

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        modalBody.innerHTML = '<div class="receipt-modal-loading">Loading receipt details...</div>';
    }

    document.querySelectorAll('.view-receipt-btn').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            const saleId = this.getAttribute('data-sale-id');
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            modalBody.innerHTML = '<div class="receipt-modal-loading">Loading receipt details...</div>';

            fetch('<?= htmlspecialchars(app_url('components/invoice/receipt.php')) ?>?action=view&ajax=1&sale_id=' + encodeURIComponent(saleId))
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unable to load receipt');
                    }
                    return response.text();
                })
                .then(function (html) {
                    modalBody.innerHTML = html;
                })
                .catch(function () {
                    modalBody.innerHTML = '<div class="receipt-modal-loading">Unable to load receipt details right now.</div>';
                });
        });
    });

    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) {
            closeModal();
        }
    });

    document.querySelectorAll('[data-admin-mobile-menu]').forEach(function (button) {
        button.addEventListener('click', function () {
            const menuToggle = document.getElementById('menuToggle');
            if (menuToggle) {
                menuToggle.click();
            }
        });
    });
});
</script>

<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/admin.css')) ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
if(window.Chart){Chart.defaults.color='#64748b';Chart.defaults.font.family=getComputedStyle(document.body).fontFamily;
new Chart(document.getElementById('adminSalesChart'),{type:'line',data:{labels:<?= json_encode(array_map(static fn(array $row): string => date('M d', strtotime($row['sale_day'])), $salesTrend)) ?>,datasets:[{label:'Sales (₱)',data:<?= json_encode(array_map(static fn(array $row): float => (float)$row['total_sales'], $salesTrend)) ?>,borderWidth:2,tension:.32,fill:true}]},options:{responsive:true,interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'₱'+Number(c.raw).toLocaleString(undefined,{minimumFractionDigits:2})}}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'₱'+Number(v).toLocaleString()}}}}});
new Chart(document.getElementById('adminCategoryChart'),{type:'doughnut',data:{labels:<?= json_encode(array_column($inventoryByCategory, 'category_name')) ?>,datasets:[{data:<?= json_encode(array_map(static fn(array $row): float => (float)$row['inventory_value'], $inventoryByCategory)) ?>}]},options:{responsive:true,plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:c=>`${c.label}: ₱${Number(c.raw).toLocaleString(undefined,{minimumFractionDigits:2})}`}}}}});}
</script>
</body>
</html>
