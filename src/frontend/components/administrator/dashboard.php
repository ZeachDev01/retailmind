<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

$displayName = trim((string)($_SESSION['full_name'] ?? 'Administrator'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Operations</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body class="admin-dashboard-page administrator-workspace">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <header class="page-heading">
                <div>
                    <p class="page-kicker">Administrator workspace</p>
                    <h1>Store Operations</h1>
                    <p class="page-subtitle">Welcome, <?= htmlspecialchars($displayName) ?>. Coordinate staff, compliance, sales oversight, and Store exceptions.</p>
                </div>
            </header>

            <section class="dashboard-section" aria-labelledby="store-tools-title">
                <div class="section-header">
                    <div><h2 id="store-tools-title">Store governance</h2><p class="section-description">Operational tools exclude platform health, recovery, technical ML controls, and Platform Settings.</p></div>
                </div>
                <div class="quick-actions">
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/user_manager/user_manager.php')) ?>"><i class="bi bi-people"></i><strong>Store Staff</strong><span>Manage Cashiers and Inventory Managers</span></a>
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/administrator/store_settings.php')) ?>"><i class="bi bi-sliders"></i><strong>Store Settings</strong><span>Govern operational attention thresholds</span></a>
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/system_administrator/fiscal_periods.php')) ?>"><i class="bi bi-calendar-check"></i><strong>Fiscal Periods</strong><span>Govern Store accounting windows</span></a>
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/invoice/receipt.php')) ?>"><i class="bi bi-receipt"></i><strong>Receipt Management</strong><span>Review Store sales activity</span></a>
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/system_administrator/audit_logs.php')) ?>"><i class="bi bi-clock-history"></i><strong>Operational Audit</strong><span>Review Store lifecycle records</span></a>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
