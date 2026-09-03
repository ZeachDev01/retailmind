<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/app/Services/SystemHealthService.php';
require_role(['admin']);

$backendPath = $GLOBALS['app']['backend_path'] ?? dirname(__DIR__, 3) . '/backend';
$service = new SystemHealthService($pdo, $backendPath);
$checks = $service->checks();
$counts = ['healthy' => 0, 'warning' => 0, 'critical' => 0];
foreach ($checks as $check) {
    $counts[$check['status']]++;
}
$overall = $counts['critical'] > 0 ? 'critical' : ($counts['warning'] > 0 ? 'warning' : 'healthy');
$labels = ['healthy' => 'Healthy', 'warning' => 'Needs attention', 'critical' => 'Critical'];
$icons = ['healthy' => 'bi-check-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'critical' => 'bi-x-octagon-fill'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Health</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/admin.css')) ?>">
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/../sidebar.php'; ?>
<div class="main-content">
<header class="page-heading">
    <div>
        <h1>System Health</h1>
        <p class="page-subtitle">Check database readiness, storage permissions, forecasting, backups, email, and application logs.</p>
        <p class="section-description">Checked <?= htmlspecialchars(date('M d, Y g:i A')) ?></p>
    </div>
    <div class="page-heading-actions"><a class="btn btn-quiet btn-icon" href="system_health.php"><i class="bi bi-arrow-clockwise"></i>Refresh</a><span class="decision-pill <?= $overall === 'healthy' ? 'ok' : 'action' ?>"><?= htmlspecialchars($labels[$overall]) ?></span></div>
</header>

<div class="health-summary">
    <article class="stat-card success"><div class="value"><?= $counts['healthy'] ?></div><div class="label">Healthy checks</div><div class="hint">Operating normally</div></article>
    <article class="stat-card warning"><div class="value"><?= $counts['warning'] ?></div><div class="label">Warnings</div><div class="hint">Review recommended</div></article>
    <article class="stat-card <?= $counts['critical'] ? 'warning' : 'success' ?>"><div class="value"><?= $counts['critical'] ?></div><div class="label">Critical checks</div><div class="hint">Immediate action required</div></article>
</div>

<section class="dashboard-section">
    <div class="section-header"><div><h3>Environment checks</h3><p class="section-description">These checks are read-only and never modify the database.</p></div></div>
    <?php foreach ($checks as $check): ?>
        <div class="health-check">
            <i class="bi <?= htmlspecialchars($icons[$check['status']]) ?> health-icon <?= htmlspecialchars($check['status']) ?>"></i>
            <div><div class="health-category"><?= htmlspecialchars($check['category']) ?></div><strong><?= htmlspecialchars($check['name']) ?></strong><p class="health-detail"><?= htmlspecialchars($check['detail']) ?></p></div>
        </div>
    <?php endforeach; ?>
</section>

<section class="dashboard-section" style="margin-top:1.5rem">
    <div class="section-header"><div><h3>Recommended maintenance commands</h3><p class="section-description">Run these commands from the project directory when deploying or maintaining RetailMind.</p></div></div>
    <div class="attention-list">
        <div class="attention-item"><span class="attention-icon"><i class="bi bi-database-gear"></i></span><span class="attention-copy"><strong>Apply pending migrations</strong><span><code>php backend/scripts/migrate.php</code></span></span></div>
        <div class="attention-item"><span class="attention-icon"><i class="bi bi-shield-check"></i></span><span class="attention-copy"><strong>Run release checks</strong><span><code>bash backend/tests/run_all.sh</code></span></span></div>
        <div class="attention-item"><span class="attention-icon"><i class="bi bi-file-earmark-zip"></i></span><span class="attention-copy"><strong>Build a clean deployment package</strong><span><code>bash backend/scripts/build_release.sh</code></span></span></div>
    </div>
</section>
</div>
</div>
</body>
</html>
