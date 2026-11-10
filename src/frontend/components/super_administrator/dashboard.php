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
$backupRecommendation = (new \App\Backup\BackupKeyRecommendation($pdo))->recommendation((int)$_SESSION['user_id'], (string)current_role());
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
    'platform_health' => ['Platform health', 'bi-heart-pulse', 'components/system_administrator/system_health.php'],
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

$relativeTime = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'time unknown';
    }
    try {
        $then = new DateTimeImmutable($value);
    } catch (Throwable) {
        return 'time unknown';
    }
    $seconds = time() - $then->getTimestamp();
    if ($seconds < 60) {
        return 'just now';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min ago';
    }
    if ($seconds < 86400) {
        $hours = intdiv($seconds, 3600);
        return $hours . ($hours === 1 ? ' hour ago' : ' hours ago');
    }
    $days = intdiv($seconds, 86400);
    return $days . ($days === 1 ? ' day ago' : ' days ago');
};

$severityGroups = [
    'critical' => ['label' => 'Critical', 'icon' => 'bi-exclamation-octagon', 'note' => 'Needs action now'],
    'warning' => ['label' => 'Warning', 'icon' => 'bi-exclamation-triangle', 'note' => 'Review when you can'],
    'info' => ['label' => 'Notice', 'icon' => 'bi-info-circle', 'note' => 'Recorded for awareness'],
];
$groupedAttention = ['critical' => [], 'warning' => [], 'info' => []];
foreach ($attentionItems as $item) {
    $severity = isset($groupedAttention[$item['severity'] ?? '']) ? $item['severity'] : 'warning';
    $groupedAttention[$severity][] = $item;
}

$categoryIcons = [
    'security' => 'bi-shield-exclamation',
    'access' => 'bi-person-lock',
    'backup_recovery' => 'bi-database-exclamation',
    'system_health' => 'bi-heart-pulse',
    'ml_health' => 'bi-cpu',
    'platform_setting' => 'bi-sliders',
    'recovery_account' => 'bi-key',
];

$verdictParts = [];
if ($criticalAttentionCount > 0) {
    $verdictParts[] = $criticalAttentionCount . ($criticalAttentionCount === 1 ? ' critical issue' : ' critical issues');
}
if ($warningAttentionCount > 0) {
    $verdictParts[] = $warningAttentionCount . ($warningAttentionCount === 1 ? ' warning' : ' warnings');
}
$noticeCount = count($groupedAttention['info']);
$verdictTone = $criticalAttentionCount > 0 ? 'critical' : ($warningAttentionCount > 0 ? 'warning' : 'nominal');
$verdictHeading = match ($verdictTone) {
    'critical' => 'Action required',
    'warning' => 'Review needed',
    default => $attentionItems === [] ? 'All platform checks nominal' : 'All nominal, with notices',
};
$verdictIcon = match ($verdictTone) {
    'critical' => 'bi-exclamation-octagon',
    'warning' => 'bi-exclamation-triangle',
    default => 'bi-shield-check',
};
$verdictSentence = match (true) {
    $verdictParts !== [] => ucfirst(implode(' and ', $verdictParts))
        . ($criticalAttentionCount + $warningAttentionCount === 1 ? ' needs' : ' need') . ' your review.',
    $noticeCount > 0 => $noticeCount . ($noticeCount === 1 ? ' notice is' : ' notices are') . ' recorded for awareness.',
    default => 'No platform condition has crossed its review threshold.',
};

$recordServices = [];
foreach (['platform_health', 'ml_health'] as $serviceKey) {
    if (!isset($headlineOrder[$serviceKey], $dashboard['headline'][$serviceKey])) {
        continue;
    }
    [$serviceLabel, $serviceIcon, $serviceDestination] = $headlineOrder[$serviceKey];
    $recordServices[] = [
        'label' => $serviceLabel,
        'icon' => $serviceIcon,
        'destination' => $serviceDestination,
        'status' => (string)($dashboard['headline'][$serviceKey]['status'] ?? 'healthy'),
        'detail' => (string)($dashboard['headline'][$serviceKey]['detail'] ?? ''),
    ];
}
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

            <?php $recoveryKeyHref = app_url('components/system_administrator/backup_restore.php') . '#recovery-key'; require __DIR__ . '/../backup/recovery_key_recommendation.php'; ?>

            <?php if ($dashboard['state'] === 'error'): ?>
                <section class="superadmin-panel" aria-labelledby="unavailable-heading">
                    <div class="panel-header">
                        <div class="panel-header-title">
                            <h2 id="unavailable-heading">Platform status unavailable</h2>
                        </div>
                    </div>
                    <div class="state-block state-error" role="alert">
                        <i class="bi bi-cloud-slash" aria-hidden="true"></i>
                        <div class="state-copy">
                            <strong>Current platform status could not be loaded</strong>
                            <p><?= $escape((string)($dashboard['message'] ?? 'Refresh to try again.')) ?></p>
                        </div>
                        <a class="btn btn-sm btn-quiet" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>">Reload</a>
                    </div>
                </section>
            <?php else: ?>

            <section class="platform-verdict verdict-<?= $escape($verdictTone) ?>" aria-labelledby="verdict-heading">
                <div class="verdict-main">
                    <span class="verdict-icon" aria-hidden="true"><i class="bi <?= $escape($verdictIcon) ?>"></i></span>
                    <div class="verdict-copy">
                        <h2 id="verdict-heading"><?= $escape($verdictHeading) ?></h2>
                        <p><?= $escape($verdictSentence) ?></p>
                    </div>
                </div>
                <p class="verdict-refresh"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Checks rerun every 5 minutes</p>
            </section>

            <div class="superadmin-body-grid">

                <section class="superadmin-panel attention-panel" aria-labelledby="queue-heading">
                    <div class="panel-header">
                        <div class="panel-header-title">
                            <h2 id="queue-heading">Platform attention queue</h2>
                            <span class="count-badge <?= count($attentionItems) > 0 ? 'count-active' : '' ?>">
                                <?= count($attentionItems) ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($attentionItems === []): ?>
                        <div class="state-block state-nominal" role="status">
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            <div class="state-copy">
                                <strong>All platform checks nominal</strong>
                                <p>Nothing has crossed its review threshold. The queue fills when a check does.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="attention-groups">
                            <?php foreach ($severityGroups as $severityKey => $severityMeta): ?>
                                <?php if ($groupedAttention[$severityKey] === []) continue; ?>
                                <section class="attention-group severity-<?= $escape($severityKey) ?>">
                                    <h3 class="attention-group-title">
                                        <i class="bi <?= $escape($severityMeta['icon']) ?>" aria-hidden="true"></i>
                                        <?= $escape($severityMeta['label']) ?>
                                        <span class="group-note"><?= $escape($severityMeta['note']) ?></span>
                                    </h3>
                                    <?php foreach ($groupedAttention[$severityKey] as $item): ?>
                                        <?php
                                        $categoryLabel = $attentionCategoryLabels[$item['category']] ?? ucfirst(str_replace('_', ' ', $item['category']));
                                        $actionLabel = $attentionActionLabels[$item['category']] ?? 'Review';
                                        $categoryIcon = $categoryIcons[$item['category']] ?? 'bi-dot';
                                        ?>
                                        <a class="attention-row severity-<?= $escape($severityKey) ?>"
                                           href="<?= $escape(app_url($item['destination'])) ?>"
                                           data-dashboard-action>
                                            <span class="attention-row-icon" aria-hidden="true"><i class="bi <?= $escape($categoryIcon) ?>"></i></span>
                                            <span class="attention-row-body">
                                                <span class="attention-row-title">
                                                    <strong><?= $escape($item['title']) ?></strong>
                                                </span>
                                                <span class="attention-row-explain"><?= $escape($item['explanation']) ?></span>
                                                <span class="attention-row-meta">
                                                    <span><i class="bi bi-tag" aria-hidden="true"></i><?= $escape($categoryLabel) ?></span>
                                                </span>
                                            </span>
                                            <span class="attention-row-action"><?= $escape($actionLabel) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                                        </a>
                                    <?php endforeach; ?>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="superadmin-panel status-panel" aria-labelledby="status-heading">
                    <div class="panel-header">
                        <div class="panel-header-title">
                            <h2 id="status-heading">Platform status</h2>
                        </div>
                        <span class="continuity-chip <?= $continuityIssueCount === 0 ? 'is-good' : 'is-warn' ?>">
                            <?= $continuityIssueCount === 0 ? 'Store continuity nominal' : $escape((string)$continuityIssueCount) . ' continuity gaps' ?>
                        </span>
                    </div>

                    <div class="services-group">
                        <?php foreach ($recordServices as $service): ?>
                            <?php $isHealthy = $service['status'] === 'healthy'; ?>
                            <a class="service-row" href="<?= $escape(app_url($service['destination'])) ?>" data-dashboard-action>
                                <div class="service-left">
                                    <i class="bi <?= $escape($service['icon']) ?>" aria-hidden="true"></i>
                                    <div class="service-copy">
                                        <span class="service-name"><?= $escape($service['label']) ?></span>
                                        <?php if ($service['detail'] !== ''): ?>
                                            <span class="service-detail"><?= $escape($service['detail']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="service-right">
                                    <span class="service-badge <?= $isHealthy ? 'badge-healthy' : 'badge-attention' ?>">
                                        <span class="service-dot" aria-hidden="true"></span>
                                        <?= $escape($statusLabel($service['status'])) ?>
                                    </span>
                                    <i class="bi bi-chevron-right chevron-icon" aria-hidden="true"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>

                        <a class="service-row" href="<?= $escape(app_url('components/system_administrator/backup_restore.php')) ?>" data-dashboard-action>
                            <div class="service-left">
                                <i class="bi bi-database-check" aria-hidden="true"></i>
                                <div class="service-copy">
                                    <span class="service-name">Latest backup</span>
                                    <span class="service-detail"><?= $escape($backupDate) ?><?= $backup['value'] === 'Not available' ? '' : ' · ' . $escape($relativeTime((string)$backup['value'])) ?></span>
                                </div>
                            </div>
                            <div class="service-right">
                                <span class="service-badge <?= ($backup['status'] ?? '') === 'healthy' ? 'badge-healthy' : 'badge-attention' ?>">
                                    <span class="service-dot" aria-hidden="true"></span>
                                    <?= ($backup['status'] ?? '') === 'healthy' ? 'Current' : 'Outdated' ?>
                                </span>
                                <i class="bi bi-chevron-right chevron-icon" aria-hidden="true"></i>
                            </div>
                        </a>
                    </div>

                    <?php if (!empty($dashboard['continuity'])): ?>
                    <div class="continuity-section">
                        <h3 class="record-section-title">Store continuity</h3>
                        <div class="continuity-list">
                            <?php foreach ($dashboard['continuity'] as $metric): ?>
                                <div class="continuity-row <?= ($metric['ok'] ?? false) ? 'is-ok' : 'is-warn' ?>">
                                    <span class="continuity-dot" aria-hidden="true"></span>
                                    <div class="continuity-copy">
                                        <strong><?= $escape($metric['label']) ?></strong>
                                        <p><?= $escape($metric['detail'] ?? '') ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </section>

            </div>
            <?php endif; ?>

        </main>
    </div>
    <script src="<?= $escape(app_url('assets/js/backup_key_recommendation.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/backup_key_recommendation.js')) ?>"></script>
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
