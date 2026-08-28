<?php
// modules/system_administrator/dashboard.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/SalesService.php';
require_once __DIR__ . '/../../../backend/app/Services/DashboardService.php';
require_role(['admin']);

$salesService = new SalesService($pdo);
$dashboardService = new DashboardService($pdo);

$rangeDays = in_array((int)($_GET['range'] ?? 30), [7, 30, 90], true) ? (int)($_GET['range'] ?? 30) : 30;
$adminMetrics = $dashboardService->getAdminMetrics($rangeDays);
$salesSummary = $salesService->getSalesSummary();
$recentTransactions = $salesService->getRecentTransactions();
$recentCriticalActions = $dashboardService->getRecentCriticalActions();
$periodTotal = max(1, $adminMetrics['open_periods'] + $adminMetrics['closed_periods']);
$openPeriodWidth = min(100, round(($adminMetrics['open_periods'] / $periodTotal) * 100));
$closedPeriodWidth = min(100, round(($adminMetrics['closed_periods'] / $periodTotal) * 100));
$salesTrend = $dashboardService->getSalesTrend($rangeDays);
$inventoryByCategory = $dashboardService->getInventoryValueByCategory(8);
$adminAttentionCount = ($adminMetrics['open_periods'] > 1 ? 1 : 0) + count($recentCriticalActions);
$lastUpdated = date('M d, Y g:i A');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <div class="main-content">
        <header class="page-heading">
            <div>
                <h1>Admin Dashboard</h1>
                <p class="page-subtitle">Review access, compliance, sales activity, system health, and inventory value.</p>
                <p class="section-description">Last updated <?= htmlspecialchars($lastUpdated) ?></p>
            </div>
            <div class="page-heading-actions">
                <form method="GET"><select name="range" onchange="this.form.submit()" aria-label="Dashboard range" style="min-height:42px;border:1px solid var(--border);border-radius:10px;padding:.55rem .75rem;background:#fff"><option value="7" <?= $rangeDays === 7 ? 'selected' : '' ?>>Last 7 days</option><option value="30" <?= $rangeDays === 30 ? 'selected' : '' ?>>Last 30 days</option><option value="90" <?= $rangeDays === 90 ? 'selected' : '' ?>>Last 90 days</option></select></form>
                <a class="btn btn-quiet btn-icon" href="?range=<?= $rangeDays ?>"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
                <span class="badge-role">Admin: <?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>
        </header>

        <div class="quick-actions">
            <a class="quick-action" href="<?= htmlspecialchars(app_url('modules/system_administrator/manage_users.php')) ?>">
                <strong>Manage Users</strong>
                <span>Control access and team roles</span>
            </a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('modules/system_administrator/audit_log.php')) ?>">
                <strong>Review Audit Log</strong>
                <span>Investigate critical activity</span>
            </a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('modules/system_administrator/fiscal_periods.php')) ?>">
                <strong>Fiscal Periods</strong>
                <span>Close or lock accounting windows</span>
            </a>
            <a class="quick-action" href="<?= htmlspecialchars(app_url('modules/system_administrator/system_health.php')) ?>">
                <strong>System Health</strong>
                <span>Check migrations, backups, storage, and forecasting</span>
            </a>
        </div>

        <div class="card-grid">
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('modules/system_administrator/manage_users.php')) ?>"><article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-people-fill"></i></span><div class="value"><?= (int)$adminMetrics['total_users'] ?></div><div class="label">Total Users</div><div class="hint">All configured accounts</div><div class="stat-meta"><span>Manage access</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('cashier/shifts.php')) ?>"><article class="stat-card with-icon <?= $adminMetrics['active_cashiers_today'] > 0 ? 'success' : 'warning' ?>"><span class="stat-icon"><i class="bi bi-person-check-fill"></i></span><div class="value"><?= (int)$adminMetrics['active_cashiers_today'] ?></div><div class="label">Active Cashier Sessions</div><div class="hint">Cashiers with sales today</div><div class="stat-meta"><span>Review shifts</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('modules/invoice/receipt.php')) ?>"><article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-cash-stack"></i></span><div class="value">₱<?= number_format((float)$adminMetrics['period_sales'], 2) ?></div><div class="label">Sales — <?= $rangeDays ?> Days</div><div class="hint">Lifetime: ₱<?= number_format((float)$adminMetrics['total_sales'], 2) ?></div><div class="stat-meta"><span>View receipts</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('modules/system_administrator/audit_log.php')) ?>"><article class="stat-card with-icon"><span class="stat-icon"><i class="bi bi-clock-history"></i></span><div class="value"><?= (int)$adminMetrics['audit_events'] ?></div><div class="label">Audit Events</div><div class="hint">Actions in the activity log</div><div class="stat-meta"><span>Review activity</span><i class="bi bi-arrow-right"></i></div></article></a>
            <a class="stat-card-link" href="<?= htmlspecialchars(app_url('modules/system_administrator/fiscal_periods.php')) ?>"><article class="stat-card with-icon <?= $adminMetrics['open_periods'] > 1 ? 'warning' : 'success' ?>"><span class="stat-icon"><i class="bi bi-calendar-check"></i></span><div class="value"><?= (int)$adminMetrics['open_periods'] ?> / <?= (int)$adminMetrics['closed_periods'] ?></div><div class="label">Open / Closed Periods</div><div class="hint">Close old periods promptly</div><div class="stat-meta"><span>Manage periods</span><i class="bi bi-arrow-right"></i></div></article></a>
        </div>

        <section class="dashboard-section" style="margin-bottom:1.5rem">
            <div class="section-header"><div><h3>Administrative Attention</h3><p class="section-description">Items that may require review or compliance action.</p></div><span class="decision-pill <?= $adminAttentionCount ? 'action' : 'ok' ?>"><?= $adminAttentionCount ? $adminAttentionCount . ' item(s)' : 'All clear' ?></span></div>
            <div class="attention-list">
                <?php if ($adminMetrics['open_periods'] > 1): ?><div class="attention-item"><span class="attention-icon"><i class="bi bi-calendar-x"></i></span><span class="attention-copy"><strong>Multiple fiscal periods are open</strong><span>Review completed periods and close them to prevent late entries.</span></span><a class="btn btn-small" href="<?= htmlspecialchars(app_url('modules/system_administrator/fiscal_periods.php')) ?>">Review</a></div><?php endif; ?>
                <?php if ($recentCriticalActions): ?><div class="attention-item"><span class="attention-icon"><i class="bi bi-shield-exclamation"></i></span><span class="attention-copy"><strong><?= count($recentCriticalActions) ?> recent critical audit action(s)</strong><span>Review reversals, adjustments, locking, deletion, or void activity.</span></span><a class="btn btn-small" href="<?= htmlspecialchars(app_url('modules/system_administrator/audit_log.php')) ?>">Review</a></div><?php endif; ?>
                <?php if (!$adminAttentionCount): ?><div class="empty-state" style="min-height:120px"><div><i class="bi bi-shield-check"></i><strong>No urgent administrative issues</strong><span>Fiscal-period and critical-audit indicators are currently clear.</span></div></div><?php endif; ?>
            </div>
        </section>

        <div class="dashboard-layout equal">
            <section class="dashboard-section"><div class="section-header"><div><h3>Sales Trend</h3><p class="section-description">Daily sales for the selected dashboard range.</p></div></div><canvas id="adminSalesChart" height="125"></canvas></section>
            <section class="dashboard-section"><div class="section-header"><div><h3>Inventory Value by Category</h3><p class="section-description">Current inventory cost distribution.</p></div><a href="<?= htmlspecialchars(app_url('modules/inventory_management/inventory_overview.php')) ?>">Inventory overview</a></div><canvas id="adminCategoryChart" height="125"></canvas></section>
        </div>

        <div class="dashboard-layout equal">
            <div class="dashboard-section">
                <h3>Fiscal Period Status</h3>
                <div class="chart-list">
                    <div class="chart-row">
                        <div class="chart-label">Open periods</div>
                        <div class="chart-track"><div class="chart-fill" style="width:<?= $openPeriodWidth ?>%;"></div></div>
                        <div class="chart-value"><?= (int)$adminMetrics['open_periods'] ?></div>
                    </div>
                    <div class="chart-row">
                        <div class="chart-label">Closed or locked</div>
                        <div class="chart-track"><div class="chart-fill" style="width:<?= $closedPeriodWidth ?>%;"></div></div>
                        <div class="chart-value"><?= (int)$adminMetrics['closed_periods'] ?></div>
                    </div>
                </div>
                <p class="section-description" style="margin-top:1rem;">
                    <?= $adminMetrics['open_periods'] > 1 ? 'Review open periods and close completed accounting windows.' : 'Fiscal period status is under control.' ?>
                </p>
            </div>

            <div class="dashboard-section">
                <h3>Recent Critical Actions</h3>
                <div class="indicator-list">
                    <?php foreach ($recentCriticalActions as $action): ?>
                    <div class="indicator-item">
                        <div>
                            <strong><?= htmlspecialchars($action['action']) ?></strong>
                            <span><?= htmlspecialchars($action['user_name']) ?> - <?= htmlspecialchars(date('M d, Y H:i', strtotime($action['created_at']))) ?></span>
                        </div>
                        <span class="decision-pill action">Review</span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$recentCriticalActions): ?>
                    <div class="indicator-item">
                        <div>
                            <strong>No critical actions found</strong>
                            <span>Recent audit activity has no high-priority keywords.</span>
                        </div>
                        <span class="decision-pill ok">Clear</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="dashboard-section" style="margin-top:1.5rem;">
            <div class="section-header">
                <div>
                    <h3>Recent Sales</h3>
                    <p class="section-description">Today's total: &#8369;<?= number_format((float)$salesSummary['total_sales_today'], 2) ?></p>
                </div>
            </div>
            <div class="table-wrap">
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
                    <tr><td colspan="7" style="text-align:center;color:var(--muted);">No recent sales recorded yet.</td></tr>
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

            fetch('<?= htmlspecialchars(app_url('modules/invoice/receipt.php')) ?>?action=view&ajax=1&sale_id=' + encodeURIComponent(saleId))
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
});
</script>

<style>
.receipt-modal {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    z-index: 1100;
}
.receipt-modal.show { display: flex; }
.receipt-modal-content {
    width: min(860px, 100%);
    max-height: 90vh;
    overflow-y: auto;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.24);
}
.receipt-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
}
.receipt-modal-close {
    border: none;
    background: transparent;
    font-size: 1.45rem;
    cursor: pointer;
    line-height: 1;
    color: var(--muted);
}
.receipt-modal-body { padding: 1rem 1.25rem 1.25rem; }
.receipt-modal-loading { color: var(--muted); padding: 1rem 0; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
if(window.Chart){Chart.defaults.color='#64748b';Chart.defaults.font.family=getComputedStyle(document.body).fontFamily;
new Chart(document.getElementById('adminSalesChart'),{type:'line',data:{labels:<?= json_encode(array_map(static fn(array $row): string => date('M d', strtotime($row['sale_day'])), $salesTrend)) ?>,datasets:[{label:'Sales (₱)',data:<?= json_encode(array_map(static fn(array $row): float => (float)$row['total_sales'], $salesTrend)) ?>,borderWidth:2,tension:.32,fill:true}]},options:{responsive:true,interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'₱'+Number(c.raw).toLocaleString(undefined,{minimumFractionDigits:2})}}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'₱'+Number(v).toLocaleString()}}}}});
new Chart(document.getElementById('adminCategoryChart'),{type:'doughnut',data:{labels:<?= json_encode(array_column($inventoryByCategory, 'category_name')) ?>,datasets:[{data:<?= json_encode(array_map(static fn(array $row): float => (float)$row['inventory_value'], $inventoryByCategory)) ?>}]},options:{responsive:true,plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:c=>`${c.label}: ₱${Number(c.raw).toLocaleString(undefined,{minimumFractionDigits:2})}`}}}}});}
</script>
</body>
</html>
