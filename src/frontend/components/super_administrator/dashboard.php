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
    'platform_health' => ['Platform health', 'bi-heart-pulse'],
    'ml_health' => ['Demand Forecast health', 'bi-cpu'],
];
$accessOrder = [
    'recovery' => 'Recovery Account',
];
$attentionCategoryLabels = [
    'security' => 'Security',
    'access' => 'Access',
    'backup_recovery' => 'Backup and recovery',
    'system_health' => 'System Health',
    'ml_health' => 'Demand Forecast',
    'platform_setting' => 'Platform Settings',
    'recovery_account' => 'Recovery Account',
];
$attentionActionLabels = [
    'security' => 'Review sign-ins',
    'access' => 'Review accounts',
    'backup_recovery' => 'Review backup',
    'system_health' => 'Review System Health',
    'ml_health' => 'Review Demand Forecast',
    'platform_setting' => 'Review Platform Settings',
    'recovery_account' => 'Review Recovery Account',
];
$privilegedActionCount = count(array_filter(
    $dashboard['access'] ?? [],
    static fn(array $item): bool => !in_array($item['status'] ?? '', ['inactive', 'sealed'], true)
));
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
$statusTone = static fn(string $status): string => in_array($status, ['healthy', 'inactive', 'sealed'], true)
    ? 'is-ready'
    : (in_array($status, ['critical', 'active', 'not_configured'], true) ? 'is-critical' : 'needs-attention');
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
            <header class="page-heading control-center-heading">
                <div class="control-center-heading-copy">
                    <h1>Platform Control Center</h1>
                    <p class="page-subtitle">Review platform issues and open the controls needed to resolve them.</p>
                </div>
                <div class="control-center-utilities">
                    <p class="dashboard-freshness">
                        <i class="bi bi-clock" aria-hidden="true"></i>
                        <span>Updated <?= $escape($formatDate($dashboard['freshness']['generated_at'] ?? null)) ?></span>
                    </p>
                    <a class="btn btn-quiet btn-icon" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>" data-manual-refresh>
                        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh
                    </a>
                </div>
            </header>

            <p class="dashboard-stale" role="status" aria-live="polite" hidden>
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                This view may be out of date. Updates resume automatically when this page is active and no action is in progress.
            </p>

            <?php if ($dashboard['state'] !== 'error'): ?>
            <section class="risk-horizon" aria-labelledby="risk-horizon-title">
                <div class="risk-horizon-heading">
                    <h2 id="risk-horizon-title">Platform overview</h2>
                </div>
                <div class="risk-horizon-grid">
                    <article class="horizon-signal <?= $criticalAttentionCount > 0 ? 'is-critical' : 'is-ready' ?>">
                        <span class="horizon-label"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><span>Critical</span></span><strong><?= $escape((string) $criticalAttentionCount) ?></strong><small><?= $criticalAttentionCount === 1 ? 'condition' : 'conditions' ?></small>
                    </article>
                    <article class="horizon-signal <?= $warningAttentionCount > 0 ? 'needs-attention' : 'is-ready' ?>">
                        <span class="horizon-label"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Warnings</span></span><strong><?= $escape((string) $warningAttentionCount) ?></strong><small><?= $warningAttentionCount === 1 ? 'condition' : 'conditions' ?></small>
                    </article>
                    <?php $backup = $dashboard['headline']['latest_backup'] ?? ['status' => 'warning', 'value' => 'Not available']; ?>
                    <article class="horizon-signal <?= $escape($statusTone((string) $backup['status'])) ?>">
                        <span class="horizon-label"><i class="bi bi-database-check" aria-hidden="true"></i><span>Latest successful backup</span></span><strong class="horizon-date"><?= $escape($backup['value'] === 'Not available' ? 'Unavailable' : $formatDate((string) $backup['value'])) ?></strong><small><?= $escape($statusLabel((string) $backup['status'])) ?> · <?= $escape($backup['detail'] ?? '') ?></small>
                    </article>
                    <article class="horizon-signal <?= $privilegedActionCount > 0 ? 'needs-attention' : 'is-ready' ?>">
                        <span class="horizon-label"><i class="bi bi-person-lock" aria-hidden="true"></i><span>Recovery access</span></span><strong><?= $escape((string) $privilegedActionCount) ?></strong><small><?= $privilegedActionCount === 1 ? 'action needed' : 'actions needed' ?></small>
                    </article>
                </div>
            </section>
            <?php endif; ?>

            <section class="issue-queue" aria-labelledby="platform-attention-title">
                <div class="workspace-section-heading">
                    <div><h2 id="platform-attention-title">Issue queue</h2><p>Most urgent first. After resolving an issue, return and refresh to check its status.</p></div>
                    <?php if ($dashboard['state'] !== 'error'): ?>
                        <span class="priority-label"><?= $escape((string) count($attentionItems)) ?> open</span>
                    <?php endif; ?>
                </div>

                <?php if ($dashboard['state'] === 'error'): ?>
                    <div class="dashboard-state dashboard-state-error" role="alert">
                        <i class="bi bi-cloud-slash" aria-hidden="true"></i>
                        <div><strong>Dashboard status unavailable</strong><p><?= $escape($dashboard['message']) ?></p></div>
                        <a class="btn btn-quiet" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>">Reload dashboard</a>
                    </div>
                <?php elseif ($attentionItems === []): ?>
                    <div class="dashboard-state dashboard-state-empty" role="status">
                        <i class="bi bi-check-circle" aria-hidden="true"></i>
                        <div><strong>No platform action required</strong><p><?= $escape($dashboard['message']) ?></p></div>
                    </div>
                <?php else: ?>
                    <div class="triage-list" aria-label="Open platform issues">
                        <?php foreach ($attentionItems as $item): ?>
                            <?php
                            $categoryLabel = $attentionCategoryLabels[$item['category']] ?? ucfirst(str_replace('_', ' ', $item['category']));
                            $actionLabel = $attentionActionLabels[$item['category']] ?? 'Open details';
                            ?>
                            <a class="triage-item attention-<?= $escape($item['severity']) ?>"
                                href="<?= $escape(app_url($item['destination'])) ?>" data-dashboard-action>
                                <span class="triage-severity" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
                                <span class="triage-copy"><span><?= $escape(ucfirst($item['severity'])) ?> · <?= $escape($categoryLabel) ?></span><strong><?= $escape($item['title']) ?></strong></span>
                                <span class="triage-action"><?= $escape($actionLabel) ?></span>
                                <i class="bi bi-chevron-right triage-arrow" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($dashboard['headline'] !== []): ?>
                <section class="readiness-ledger" aria-labelledby="readiness-title">
                    <div class="workspace-section-heading readiness-heading">
                        <div><h2 id="readiness-title">System status</h2></div>
                        <span class="ledger-summary"><?= $escape((string) $continuityIssueCount) ?> store <?= $continuityIssueCount === 1 ? 'check needs' : 'checks need' ?> review</span>
                    </div>
                    <div class="ledger-grid">
                        <?php foreach (['platform_health', 'ml_health'] as $key): ?>
                            <?php
                            [$label, $icon] = $headlineOrder[$key];
                            $item = $dashboard['headline'][$key];
                            ?>
                            <article class="ledger-item <?= $escape($statusTone((string) $item['status'])) ?>">
                                <i class="bi <?= $escape($icon) ?>" aria-hidden="true"></i><div><span><?= $escape($label) ?></span><strong><?= $escape($item['value']) ?></strong><small><?= $escape($item['detail']) ?></small></div><em><?= $escape($statusLabel((string) $item['status'])) ?></em>
                            </article>
                        <?php endforeach; ?>
                        <?php foreach ($accessOrder as $accessKey => $accessLabel): ?>
                            <?php $item = $dashboard['access'][$accessKey]; ?>
                            <a class="ledger-item ledger-link <?= $escape($statusTone((string) $item['status'])) ?>" href="<?= $escape(app_url($item['destination'])) ?>" data-dashboard-action>
                                <i class="bi bi-person-lock" aria-hidden="true"></i><div><span><?= $escape($accessLabel) ?></span><strong><?= $escape($statusLabel((string) $item['status'])) ?></strong><small><?= $escape($item['detail']) ?></small></div><i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
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
