<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/app/Services/SystemHealthService.php';
require_role(['admin']);

$backendPath = $GLOBALS['app']['backend_path'] ?? dirname(__DIR__, 3) . '/backend';
$service = new SystemHealthService($pdo, $backendPath);
$checks = $service->checks();
$counts = ['healthy' => 0, 'warning' => 0, 'critical' => 0];
$checksByCategory = [];

foreach ($checks as $check) {
    $status = (string)$check['status'];
    $category = (string)$check['category'];
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
    $checksByCategory[$category][] = $check;
}

$overall = $counts['critical'] > 0 ? 'critical' : ($counts['warning'] > 0 ? 'warning' : 'healthy');
$labels = ['healthy' => 'Healthy', 'warning' => 'Needs attention', 'critical' => 'Critical'];
$icons = ['healthy' => 'bi-check-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'critical' => 'bi-x-octagon-fill'];
$checkedAt = format_display_datetime('now');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/system-health.css')) ?>">
</head>
<body class="system-health-page">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content">
            <header class="page-heading system-health-heading">
                <div>
                    <h1>System Health</h1>
                    <p class="page-subtitle">Check database readiness, storage permissions, forecasting, backups, email, and application logs.</p>
                    <p class="system-health-checked"><i class="bi bi-clock" aria-hidden="true"></i> Checked <?= htmlspecialchars($checkedAt) ?></p>
                </div>
                <div class="page-heading-actions">
                    <a class="btn btn-quiet btn-icon" href="<?= htmlspecialchars(app_url('components/system_administrator/system_health.php')) ?>"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh checks</a>
                    <span class="decision-pill <?= $overall === 'healthy' ? 'ok' : 'action' ?>"><?= htmlspecialchars($labels[$overall]) ?></span>
                </div>
            </header>

            <div class="card-grid system-health-stats" aria-label="System health summary">
                <article class="stat-card with-icon success">
                    <span class="stat-icon"><i class="bi bi-check-circle" aria-hidden="true"></i></span>
                    <div class="value"><?= $counts['healthy'] ?></div>
                    <div class="label">Healthy Checks</div>
                    <div class="hint">Operating normally</div>
                </article>
                <article class="stat-card with-icon warning">
                    <span class="stat-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
                    <div class="value"><?= $counts['warning'] ?></div>
                    <div class="label">Warnings</div>
                    <div class="hint">Review recommended</div>
                </article>
                <article class="stat-card with-icon system-health-critical <?= $counts['critical'] === 0 ? 'is-clear' : '' ?>">
                    <span class="stat-icon"><i class="bi bi-x-octagon" aria-hidden="true"></i></span>
                    <div class="value"><?= $counts['critical'] ?></div>
                    <div class="label">Critical Checks</div>
                    <div class="hint"><?= $counts['critical'] > 0 ? 'Immediate action required' : 'No critical issues' ?></div>
                </article>
            </div>

            <div class="system-health-layout">
                <section class="dashboard-section system-health-checks" aria-labelledby="environment-checks-title">
                    <div class="section-header">
                        <div>
                            <h2 id="environment-checks-title">Environment checks</h2>
                            <p class="section-description">These checks are read-only and never modify the database.</p>
                        </div>
                        <span class="system-health-check-count"><?= count($checks) ?> checks</span>
                    </div>

                    <div class="system-health-groups">
                        <?php foreach ($checksByCategory as $category => $categoryChecks): ?>
                            <section class="system-health-group" aria-labelledby="health-category-<?= htmlspecialchars(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $category)), ENT_QUOTES, 'UTF-8') ?>">
                                <h3 id="health-category-<?= htmlspecialchars(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $category)), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category) ?></h3>
                                <div class="system-health-group-list">
                                    <?php foreach ($categoryChecks as $check): ?>
                                        <article class="health-check">
                                            <span class="health-icon <?= htmlspecialchars($check['status']) ?>" aria-hidden="true"><i class="bi <?= htmlspecialchars($icons[$check['status']]) ?>"></i></span>
                                            <div class="health-check-copy">
                                                <div class="health-check-title">
                                                    <strong><?= htmlspecialchars($check['name']) ?></strong>
                                                    <span class="health-status <?= htmlspecialchars($check['status']) ?>"><?= htmlspecialchars($labels[$check['status']]) ?></span>
                                                </div>
                                                <p class="health-detail"><?= htmlspecialchars($check['detail']) ?></p>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </section>

                <aside class="dashboard-section system-health-maintenance" aria-labelledby="maintenance-title">
                    <div class="section-header">
                        <div>
                            <h2 id="maintenance-title">Recommended maintenance commands</h2>
                            <p class="section-description">Run these from the project directory during deployment or maintenance.</p>
                        </div>
                    </div>
                    <div class="maintenance-list">
                        <div class="maintenance-item">
                            <span class="maintenance-icon"><i class="bi bi-database-gear" aria-hidden="true"></i></span>
                            <div><strong>Apply pending migrations</strong><code>php backend/scripts/migrate.php</code></div>
                        </div>
                        <div class="maintenance-item">
                            <span class="maintenance-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                            <div><strong>Run release checks</strong><code>bash backend/tests/run_all.sh</code></div>
                        </div>
                        <div class="maintenance-item">
                            <span class="maintenance-icon"><i class="bi bi-file-earmark-zip" aria-hidden="true"></i></span>
                            <div><strong>Build a deployment package</strong><code>bash backend/scripts/build_release.sh</code></div>
                        </div>
                    </div>
                    <div class="system-health-note">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span>Health checks report current conditions only. Refresh after applying maintenance changes.</span>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</body>
</html>
