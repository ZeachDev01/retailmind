<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/app/Services/SystemHealthService.php';
require_role(['super_admin']);

use App\Attention\SystemClock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\DashboardWorkspace;

$backendPath = $GLOBALS['app']['backend_path'] ?? dirname(__DIR__, 3) . '/backend';
$workspace = new DashboardWorkspace(
    $pdo,
    new SystemClock(),
    new SystemHealthService($pdo, $backendPath),
    new RoleCapabilityPolicy()
);
$dashboard = $workspace->load('super_admin', 30);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'Never';
    }
    try {
        return (new DateTimeImmutable($value))->format('M j, Y · g:i A');
    } catch (Throwable) {
        return 'Unavailable';
    }
};
$formatShortDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'Never';
    }
    try {
        return (new DateTimeImmutable($value))->format('M j · g:i A');
    } catch (Throwable) {
        return 'Unavailable';
    }
};
$statusLabel = static fn(string $status): string => [
    'healthy' => 'Healthy',
    'warning' => 'Needs attention',
    'critical' => 'Critical',
    'active' => 'Active',
    'inactive' => 'Inactive',
    'sealed' => 'Sealed',
    'unsealed' => 'Unsealed',
    'not_configured' => 'Not configured',
][$status] ?? ucfirst(str_replace('_', ' ', $status));

$headlineOrder = [
    'platform_health' => ['Platform Services', 'bi-heart-pulse', 'components/system_administrator/system_health.php'],
    'ml_health' => ['Demand Forecast', 'bi-cpu', 'components/system_administrator/ml_settings.php'],
];

$attentionCategoryLabels = [
    'security' => 'Security',
    'access' => 'Access',
    'backup_recovery' => 'Backup',
    'system_health' => 'System Health',
    'ml_health' => 'Demand Forecast',
    'platform_setting' => 'Settings',
    'recovery_account' => 'Recovery Account',
];

$attentionActionLabels = [
    'security' => 'Review',
    'access' => 'Manage',
    'backup_recovery' => 'Inspect',
    'system_health' => 'Diagnose',
    'ml_health' => 'Configure',
    'platform_setting' => 'Settings',
    'recovery_account' => 'Audit',
];

$attentionItems = $dashboard['attention'] ?? [];
$criticalAttentionCount = count(array_filter(
    $attentionItems,
    static fn(array $item): bool => ($item['severity'] ?? '') === 'critical'
));
$warningAttentionCount = count(array_filter(
    $attentionItems,
    static fn(array $item): bool => ($item['severity'] ?? '') === 'warning'
));
$continuityIssueCount = count(array_filter(
    $dashboard['continuity'] ?? [],
    static fn(array $item): bool => !($item['ok'] ?? false)
));

$backup = $dashboard['headline']['latest_backup'] ?? ['status' => 'warning', 'value' => 'Not available'];
$backupDate = $backup['value'] === 'Not available' ? 'Unavailable' : $formatDate((string)$backup['value']);
$platformHealth = $dashboard['headline']['platform_health'] ?? ['status' => 'healthy'];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Control Center</title>
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/dashboard.css')) ?>?v=<?= $escape((string) filemtime(__DIR__ . '/../../assets/css/dashboard.css')) ?>">
</head>
<body class="admin-dashboard-page super-administrator-workspace">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content" data-dashboard-refresh data-stale-after="<?= $escape($dashboard['freshness']['stale_after'] ?? '') ?>">
            
            <header class="superadmin-header">
                <div class="superadmin-header-title">
                    <div class="title-with-pill">
                        <h1>Platform Control Center</h1>
                        <span class="role-chip">Super Admin</span>
                    </div>
                </div>
                <div class="superadmin-header-meta">
                    <span class="sync-status">
                        <span class="pulse-dot" aria-hidden="true"></span>
                        <span>Synced <?= $escape($formatShortDate($dashboard['freshness']['generated_at'] ?? null)) ?></span>
                    </span>
                    <a class="btn-refresh-icon" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>" title="Refresh Dashboard" aria-label="Refresh Dashboard">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    </a>
                </div>
            </header>

            <p class="dashboard-stale" role="status" aria-live="polite" hidden>
                <i class="bi bi-pause-circle" aria-hidden="true"></i>
                <span>Auto-refresh paused while working</span>
            </p>

            <?php if ($dashboard['state'] !== 'error'): ?>
            <section class="signals-grid" aria-label="Platform Health Indicators">
                <article class="signal-card <?= $criticalAttentionCount > 0 ? 'signal-critical' : 'signal-calm' ?>">
                    <div class="signal-top">
                        <span class="signal-label">Critical Issues</span>
                        <i class="bi bi-shield-exclamation signal-icon" aria-hidden="true"></i>
                    </div>
                    <div class="signal-body">
                        <strong class="signal-val"><?= $escape((string) $criticalAttentionCount) ?></strong>
                        <span class="signal-status-pill"><?= $criticalAttentionCount > 0 ? 'Attention' : 'Clear' ?></span>
                    </div>
                </article>

                <article class="signal-card <?= $warningAttentionCount > 0 ? 'signal-warning' : 'signal-calm' ?>">
                    <div class="signal-top">
                        <span class="signal-label">Warnings</span>
                        <i class="bi bi-exclamation-triangle signal-icon" aria-hidden="true"></i>
                    </div>
                    <div class="signal-body">
                        <strong class="signal-val"><?= $escape((string) $warningAttentionCount) ?></strong>
                        <span class="signal-status-pill"><?= $warningAttentionCount > 0 ? 'Review' : 'Clear' ?></span>
                    </div>
                </article>

                <article class="signal-card <?= ($backup['status'] ?? '') === 'healthy' ? 'signal-calm' : 'signal-warning' ?>">
                    <div class="signal-top">
                        <span class="signal-label">Database Backup</span>
                        <i class="bi bi-database-check signal-icon" aria-hidden="true"></i>
                    </div>
                    <div class="signal-body">
                        <strong class="signal-val signal-val-text"><?= $escape($backupDate) ?></strong>
                        <span class="signal-status-pill"><?= ($backup['status'] ?? '') === 'healthy' ? 'Current' : 'Outdated' ?></span>
                    </div>
                </article>

                <article class="signal-card <?= ($platformHealth['status'] ?? '') === 'healthy' ? 'signal-calm' : 'signal-warning' ?>">
                    <div class="signal-top">
                        <span class="signal-label">Core Engine</span>
                        <i class="bi bi-heart-pulse signal-icon" aria-hidden="true"></i>
                    </div>
                    <div class="signal-body">
                        <strong class="signal-val signal-val-text"><?= $escape($statusLabel((string)($platformHealth['status'] ?? 'healthy'))) ?></strong>
                        <span class="signal-status-pill"><?= ($platformHealth['status'] ?? '') === 'healthy' ? 'Optimal' : 'Check' ?></span>
                    </div>
                </article>
            </section>
            <?php endif; ?>

            <div class="superadmin-split-grid">
                
                <section class="superadmin-panel issue-panel" aria-labelledby="queue-heading">
                    <div class="panel-header">
                        <div class="panel-header-title">
                            <h2 id="queue-heading">Issue Queue</h2>
                            <span class="count-badge <?= count($attentionItems) > 0 ? 'count-active' : '' ?>">
                                <?= count($attentionItems) ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($dashboard['state'] === 'error'): ?>
                        <div class="state-block state-error" role="alert">
                            <i class="bi bi-cloud-slash" aria-hidden="true"></i>
                            <div class="state-copy">
                                <strong>Platform status unavailable</strong>
                            </div>
                            <a class="btn btn-sm btn-quiet" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>">Reload</a>
                        </div>
                    <?php elseif ($attentionItems === []): ?>
                        <div class="state-block state-nominal" role="status">
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            <div class="state-copy">
                                <strong>All platform checks nominal</strong>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="queue-list" role="list">
                            <?php foreach ($attentionItems as $item): ?>
                                <?php
                                $categoryLabel = $attentionCategoryLabels[$item['category']] ?? ucfirst(str_replace('_', ' ', $item['category']));
                                $actionLabel = $attentionActionLabels[$item['category']] ?? 'Review';
                                $severity = $item['severity'] ?? 'warning';
                                ?>
                                <a class="queue-item severity-<?= $escape($severity) ?>"
                                   href="<?= $escape(app_url($item['destination'])) ?>"
                                   data-dashboard-action>
                                    <div class="queue-meta">
                                        <span class="severity-tag severity-<?= $escape($severity) ?>"><?= $escape(strtoupper($severity)) ?></span>
                                        <span class="category-tag"><?= $escape($categoryLabel) ?></span>
                                    </div>
                                    <div class="queue-title">
                                        <strong><?= $escape($item['title']) ?></strong>
                                    </div>
                                    <div class="queue-action">
                                        <span class="action-btn-link"><?= $escape($actionLabel) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="superadmin-panel status-panel" aria-labelledby="status-heading">
                    <div class="panel-header">
                        <div class="panel-header-title">
                            <h2 id="status-heading">System Status</h2>
                        </div>
                        <span class="continuity-chip <?= $continuityIssueCount === 0 ? 'is-good' : 'is-warn' ?>">
                            <?= $continuityIssueCount === 0 ? 'Continuity Optimal' : $escape((string)$continuityIssueCount) . ' Irregular' ?>
                        </span>
                    </div>

                    <div class="services-group">
                        <?php foreach (['platform_health', 'ml_health'] as $key): ?>
                            <?php
                            if (!isset($headlineOrder[$key], $dashboard['headline'][$key])) continue;
                            [$label, $icon, $dest] = $headlineOrder[$key];
                            $item = $dashboard['headline'][$key];
                            $status = (string)($item['status'] ?? 'healthy');
                            $isHealthy = $status === 'healthy';
                            ?>
                            <a class="service-row" href="<?= $escape(app_url($dest)) ?>" data-dashboard-action>
                                <div class="service-left">
                                    <i class="bi <?= $escape($icon) ?>" aria-hidden="true"></i>
                                    <span class="service-name"><?= $escape($label) ?></span>
                                </div>
                                <div class="service-right">
                                    <span class="service-badge <?= $isHealthy ? 'badge-healthy' : 'badge-attention' ?>">
                                        <span class="service-dot" aria-hidden="true"></span>
                                        <?= $escape($statusLabel($status)) ?>
                                    </span>
                                    <i class="bi bi-chevron-right chevron-icon" aria-hidden="true"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($dashboard['continuity'])): ?>
                    <div class="continuity-matrix">
                        <div class="matrix-title">Store Continuity</div>
                        <div class="matrix-grid">
                            <?php foreach ($dashboard['continuity'] as $metric): ?>
                                <div class="matrix-card <?= ($metric['ok'] ?? false) ? 'matrix-ok' : 'matrix-warn' ?>">
                                    <div class="matrix-header">
                                        <span class="matrix-label"><?= $escape($metric['label']) ?></span>
                                        <span class="matrix-indicator" aria-hidden="true"></span>
                                    </div>
                                    <div class="matrix-state">
                                        <strong><?= ($metric['ok'] ?? false) ? 'Nominal' : 'Review' ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </section>

            </div>

        </main>
    </div>
    <script>
    (() => {
        const dashboard = document.querySelector('[data-dashboard-refresh]');
        const staleNotice = document.querySelector('.dashboard-stale');
        if (!dashboard || !staleNotice) return;

        let actionInProgress = false;
        const interactiveFields = 'input, select, textarea, [contenteditable="true"]';
        document.addEventListener('focusin', event => {
            if (event.target.matches?.(interactiveFields)) actionInProgress = true;
        });
        document.addEventListener('focusout', event => {
            if (event.target.matches?.(interactiveFields)) actionInProgress = false;
        });
        document.addEventListener('submit', () => { actionInProgress = true; });
        document.querySelectorAll('[data-dashboard-action]').forEach(link => {
            link.addEventListener('click', () => { actionInProgress = true; });
        });

        const refreshWhenSafe = () => {
            if (actionInProgress || document.hidden || window.__retailMindActionInProgress === true) {
                staleNotice.hidden = false;
                window.setTimeout(refreshWhenSafe, 30000);
                return;
            }
            RetailMindUI.navigate(window.location.href, {fetch: {headers: {'X-RetailMind-Background': '1'}}});
        };

        const staleAt = Date.parse((dashboard.dataset.staleAfter || '').replace(' ', 'T'));
        window.setInterval(() => {
            if (Number.isFinite(staleAt) && Date.now() >= staleAt) staleNotice.hidden = false;
        }, 30000);
        window.setTimeout(refreshWhenSafe, 300000);
    })();
    </script>
</body>
</html>
