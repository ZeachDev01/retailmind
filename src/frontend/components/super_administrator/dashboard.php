<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/app/Services/SystemHealthService.php';
require_role(['super_admin']);

use App\Attention\SystemClock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\DashboardWorkspace;

$displayName = trim((string)($_SESSION['full_name'] ?? 'Super Administrator'));
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
        return (new DateTimeImmutable($value))->format('m-d-Y g:i A');
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
    'critical_attention' => ['Critical conditions', 'bi-exclamation-octagon'],
    'latest_backup' => ['Latest Database Backup', 'bi-database-check'],
    'platform_health' => ['Platform health', 'bi-heart-pulse'],
    'ml_health' => ['Demand Forecast health', 'bi-cpu'],
    'privileged_accounts' => ['Active access risks', 'bi-person-lock'],
];
$accessOrder = [
    'emergency' => 'Emergency Access',
    'recovery' => 'Recovery Account',
];
$accessActionLabels = [
    'emergency' => [
        'active' => 'Review active session',
        'inactive' => 'Review access',
    ],
    'recovery' => [
        'not_configured' => 'Set up recovery account',
        'unsealed' => 'Review recovery account',
        'sealed' => 'Review recovery account',
    ],
];
$attentionCategoryLabels = [
    'security' => 'Security',
    'access' => 'Access',
    'backup_recovery' => 'Backup and recovery',
    'system_health' => 'System Health',
    'ml_health' => 'Demand Forecast',
    'platform_setting' => 'Platform Settings',
    'emergency_access' => 'Emergency Access',
    'recovery_account' => 'Recovery Account',
];
$attentionActionLabels = [
    'security' => 'Review sign-ins',
    'access' => 'Review accounts',
    'backup_recovery' => 'Review backup',
    'system_health' => 'Review System Health',
    'ml_health' => 'Review Demand Forecast',
    'platform_setting' => 'Review Platform Settings',
    'emergency_access' => 'Review Emergency Access',
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
$selectedIssue = $attentionItems[0] ?? null;
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
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/dashboard.css')) ?>?v=<?= $escape((string) filemtime(__DIR__ . '/../../assets/css/dashboard.css')) ?>">
</head>
<body class="admin-dashboard-page super-administrator-workspace">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content" data-dashboard-refresh data-stale-after="<?= $escape($dashboard['freshness']['stale_after'] ?? '') ?>">
            <header class="page-heading control-center-heading">
                <div class="control-center-heading-copy">
                    <h1>Platform Control Center</h1>
                    <p class="page-subtitle">Triage active risks, follow the next step, and confirm recovery.</p>
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
                Dashboard update paused while you finish the current action. It will retry automatically.
            </p>

            <section class="risk-horizon" aria-labelledby="risk-horizon-title">
                <div class="risk-horizon-heading">
                    <h2 id="risk-horizon-title">Risk horizon</h2>
                    <p>Live conditions that determine what needs attention first.</p>
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
                        <span class="horizon-label"><i class="bi bi-database-check" aria-hidden="true"></i><span>Latest backup</span></span><strong class="horizon-date"><?= $escape($backup['value'] === 'Not available' ? 'Unavailable' : $formatDate((string) $backup['value'])) ?></strong><small><?= $escape($statusLabel((string) $backup['status'])) ?></small>
                    </article>
                    <article class="horizon-signal <?= $privilegedActionCount > 0 ? 'needs-attention' : 'is-ready' ?>">
                        <span class="horizon-label"><i class="bi bi-person-lock" aria-hidden="true"></i><span>Access readiness</span></span><strong><?= $escape((string) $privilegedActionCount) ?></strong><small><?= $privilegedActionCount === 1 ? 'action needed' : 'actions needed' ?></small>
                    </article>
                </div>
            </section>

            <div class="triage-workspace">
                <section class="issue-queue" aria-labelledby="platform-attention-title">
                    <div class="workspace-section-heading">
                        <div><h2 id="platform-attention-title">Issue queue</h2><p>Ordered by severity. Choose a condition to see its resolution path.</p></div>
                        <span class="priority-label"><?= $escape((string) count($attentionItems)) ?> open</span>
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
                            <?php foreach ($attentionItems as $index => $item): ?>
                                <?php
                                $categoryLabel = $attentionCategoryLabels[$item['category']] ?? ucfirst(str_replace('_', ' ', $item['category']));
                                $actionLabel = $attentionActionLabels[$item['category']] ?? 'Open details';
                                ?>
                                <button type="button" class="triage-item attention-<?= $escape($item['severity']) ?>" type="button"
                                    aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                                    data-triage-item
                                    data-severity="<?= $escape(ucfirst($item['severity'])) ?>"
                                    data-category="<?= $escape($categoryLabel) ?>"
                                    data-title="<?= $escape($item['title']) ?>"
                                    data-explanation="<?= $escape($item['explanation']) ?>"
                                    data-action-label="<?= $escape($actionLabel) ?>"
                                    data-destination="<?= $escape(app_url($item['destination'])) ?>">
                                    <span class="triage-severity" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
                                    <span class="triage-copy"><span><?= $escape(ucfirst($item['severity'])) ?> · <?= $escape($categoryLabel) ?></span><strong><?= $escape($item['title']) ?></strong><small><?= $escape($item['explanation']) ?></small></span>
                                    <i class="bi bi-chevron-right triage-arrow" aria-hidden="true"></i>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <aside class="resolution-workbench" aria-labelledby="resolution-title" aria-live="polite">
                    <?php if ($selectedIssue !== null): ?>
                        <?php
                        $selectedCategory = $attentionCategoryLabels[$selectedIssue['category']] ?? ucfirst(str_replace('_', ' ', $selectedIssue['category']));
                        $selectedActionLabel = $attentionActionLabels[$selectedIssue['category']] ?? 'Open details';
                        ?>
                        <div class="resolution-heading">
                            <div><h2 id="resolution-title">Resolution guide</h2><p data-resolution-context><?= $escape(ucfirst($selectedIssue['severity'])) ?> · <?= $escape($selectedCategory) ?></p></div>
                            <span class="resolution-status"><i class="bi bi-record-circle" aria-hidden="true"></i> In review</span>
                        </div>
                        <h3 data-resolution-title><?= $escape($selectedIssue['title']) ?></h3>
                        <ol class="resolution-steps">
                            <li class="is-current"><span>1</span><div><strong>Review the signal</strong><p data-resolution-explanation><?= $escape($selectedIssue['explanation']) ?></p></div></li>
                            <li><span>2</span><div><strong>Open the correct control</strong><p>Use the linked control to inspect the source and apply the required change.</p><a class="btn resolution-primary" href="<?= $escape(app_url($selectedIssue['destination'])) ?>" data-resolution-action data-dashboard-action><?= $escape($selectedActionLabel) ?><i class="bi bi-arrow-right" aria-hidden="true"></i></a></div></li>
                            <li><span>3</span><div><strong>Verify recovery</strong><p>Return here and refresh. The issue clears when the signal is healthy again.</p><a class="resolution-verify" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>" data-manual-refresh><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh and verify</a></div></li>
                        </ol>
                    <?php else: ?>
                        <div class="resolution-complete"><i class="bi bi-shield-check" aria-hidden="true"></i><h2 id="resolution-title">No resolution needed</h2><p>All monitored conditions are within their configured thresholds.</p></div>
                    <?php endif; ?>
                </aside>
            </div>

            <?php if ($dashboard['headline'] !== []): ?>
                <section class="readiness-ledger" aria-labelledby="readiness-title">
                    <div class="workspace-section-heading readiness-heading">
                        <div><h2 id="readiness-title">Readiness ledger</h2><p>Supporting signals to check after the immediate risk is contained.</p></div>
                        <span class="ledger-summary"><?= $escape((string) $continuityIssueCount) ?> store <?= $continuityIssueCount === 1 ? 'signal' : 'signals' ?> need review</span>
                    </div>
                    <div class="ledger-grid">
                        <?php foreach (['platform_health', 'ml_health', 'latest_backup'] as $key): ?>
                            <?php
                            [$label, $icon] = $headlineOrder[$key];
                            $item = $dashboard['headline'][$key];
                            $displayValue = $key === 'latest_backup' && $item['value'] !== 'Not available' ? $formatDate((string) $item['value']) : $item['value'];
                            ?>
                            <article class="ledger-item <?= $escape($statusTone((string) $item['status'])) ?>">
                                <i class="bi <?= $escape($icon) ?>" aria-hidden="true"></i><div><span><?= $escape($label) ?></span><strong><?= $escape($displayValue) ?></strong><small><?= $escape($item['detail']) ?></small></div><em><?= $escape($statusLabel((string) $item['status'])) ?></em>
                            </article>
                        <?php endforeach; ?>
                        <?php foreach ($accessOrder as $accessKey => $accessLabel): ?>
                            <?php $item = $dashboard['access'][$accessKey]; ?>
                            <a class="ledger-item ledger-link <?= $escape($statusTone((string) $item['status'])) ?>" href="<?= $escape(app_url($item['destination'])) ?>" data-dashboard-action>
                                <i class="bi bi-person-lock" aria-hidden="true"></i><div><span><?= $escape($accessLabel) ?></span><strong><?= $escape($statusLabel((string) $item['status'])) ?></strong><small><?= $escape($item['detail']) ?></small></div><i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <nav class="control-shortcuts" aria-labelledby="control-shortcuts-title">
                        <div class="control-shortcuts-heading">
                            <div>
                                <h3 id="control-shortcuts-title">Control shortcuts</h3>
                                <p>Open a platform control directly.</p>
                            </div>
                            <span><?= $escape((string) count($dashboard['actions'])) ?> controls</span>
                        </div>
                        <div class="control-shortcut-list">
                            <?php foreach ($dashboard['actions'] as $action): ?>
                                <a class="control-shortcut-row" href="<?= $escape(app_url($action['destination'])) ?>" data-dashboard-action>
                                    <span class="control-shortcut-icon"><i class="bi <?= $escape($action['icon']) ?>" aria-hidden="true"></i></span>
                                    <span class="control-shortcut-copy">
                                        <strong><?= $escape($action['label']) ?></strong>
                                        <small><?= $escape($action['description']) ?></small>
                                    </span>
                                    <span class="control-shortcut-state">Open</span>
                                    <i class="bi bi-chevron-right control-shortcut-arrow" aria-hidden="true"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </nav>
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

        const triageItems = [...document.querySelectorAll('[data-triage-item]')];
        const resolutionTitle = document.querySelector('[data-resolution-title]');
        const resolutionContext = document.querySelector('[data-resolution-context]');
        const resolutionExplanation = document.querySelector('[data-resolution-explanation]');
        const resolutionAction = document.querySelector('[data-resolution-action]');
        triageItems.forEach(item => {
            item.addEventListener('click', () => {
                triageItems.forEach(candidate => candidate.setAttribute('aria-pressed', candidate === item ? 'true' : 'false'));
                if (!resolutionTitle || !resolutionContext || !resolutionExplanation || !resolutionAction) return;
                resolutionTitle.textContent = item.dataset.title || '';
                resolutionContext.textContent = `${item.dataset.severity || ''} · ${item.dataset.category || ''}`;
                resolutionExplanation.textContent = item.dataset.explanation || '';
                resolutionAction.firstChild.textContent = item.dataset.actionLabel || 'Open details';
                resolutionAction.href = item.dataset.destination || '#';
                resolutionTitle.focus?.({preventScroll: true});
            });
        });

        const refreshWhenSafe = () => {
            if (actionInProgress || document.hidden || window.__retailMindActionInProgress === true) {
                staleNotice.hidden = false;
                window.setTimeout(refreshWhenSafe, 30000);
                return;
            }
            window.location.reload();
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
