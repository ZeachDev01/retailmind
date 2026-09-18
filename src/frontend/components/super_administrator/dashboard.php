<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['super_admin']);

$displayName = trim((string)($_SESSION['full_name'] ?? 'Super Administrator'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Control Center</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body class="admin-dashboard-page super-administrator-workspace">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <header class="page-heading">
                <div>
                    <p class="page-kicker">Super Administrator workspace</p>
                    <h1>Platform Control Center</h1>
                    <p class="page-subtitle">Welcome, <?= htmlspecialchars($displayName) ?>. Govern platform security, resilience, access, and technical services.</p>
                </div>
            </header>

            <section class="dashboard-section" aria-labelledby="platform-tools-title">
                <div class="section-header">
                    <div><h2 id="platform-tools-title">Platform governance</h2><p class="section-description">Technical controls are isolated from routine Store operations.</p></div>
                </div>
                <div class="quick-actions">
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/user_manager/user_manager.php')) ?>"><i class="bi bi-shield-lock"></i><strong>Users &amp; Access</strong><span>Manage ordinary and privileged identities</span></a>
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/system_administrator/system_health.php')) ?>"><i class="bi bi-heart-pulse"></i><strong>System Health</strong><span>Inspect platform readiness</span></a>
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/system_administrator/backup_restore.php')) ?>"><i class="bi bi-database-check"></i><strong>Backup &amp; Restore</strong><span>Protect platform continuity</span></a>
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/system_administrator/ml_settings.php')) ?>"><i class="bi bi-cpu"></i><strong>ML Operation</strong><span>Govern demand-forecasting controls</span></a>
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/system_administrator/system_settings.php')) ?>"><i class="bi bi-gear"></i><strong>Platform Settings</strong><span>Configure technical safeguards</span></a>
                    <a class="quick-action" href="<?= htmlspecialchars(app_url('components/system_administrator/audit_logs.php')) ?>"><i class="bi bi-clock-history"></i><strong>Protected Audit Records</strong><span>Review security and platform activity</span></a>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
