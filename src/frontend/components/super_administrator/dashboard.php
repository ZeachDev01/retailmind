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
    'critical_attention' => ['Critical attention', 'bi-exclamation-octagon'],
    'latest_backup' => ['Latest successful backup', 'bi-database-check'],
    'platform_health' => ['Platform/service health', 'bi-heart-pulse'],
    'ml_health' => ['ML health', 'bi-cpu'],
    'privileged_accounts' => ['Privileged-account anomalies', 'bi-person-lock'],
];
$accessOrder = [
    'emergency' => 'Emergency Access',
    'recovery' => 'Recovery Account',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Control Center</title>
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/style.css')) ?>">
</head>
<body class="admin-dashboard-page super-administrator-workspace">
    <div class="app-shell">
        <?php include __DIR__ . '/../sidebar.php'; ?>
        <main class="main-content" data-dashboard-refresh data-stale-after="<?= $escape($dashboard['freshness']['stale_after'] ?? '') ?>">
            <header class="page-heading control-center-heading">
                <div>
                    <p class="page-kicker">Super Administrator workspace</p>
                    <h1>Platform Control Center</h1>
                    <p class="page-subtitle">Welcome, <?= $escape($displayName) ?>. Govern platform security, resilience, access, and technical services.</p>
                    <p class="dashboard-freshness">
                        <i class="bi bi-clock" aria-hidden="true"></i>
                        Last updated <?= $escape($formatDate($dashboard['freshness']['generated_at'] ?? null)) ?>
                    </p>
                </div>
                <div class="page-heading-actions">
                    <a class="btn btn-quiet btn-icon" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>" data-manual-refresh>
                        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh status
                    </a>
                </div>
            </header>

            <p class="dashboard-stale" role="status" aria-live="polite" hidden>
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                This view is stale. Automatic refresh is waiting for the current action to finish.
            </p>

            <section class="dashboard-section governance-queue admin-attention-section" aria-labelledby="platform-attention-title">
                <div class="section-header">
                    <div>
                        <p class="section-eyebrow">Act first</p>
                        <h2 id="platform-attention-title">Platform attention queue</h2>
                        <p class="section-description">Live platform-governance conditions, ordered by severity.</p>
                    </div>
                </div>

                <?php if ($dashboard['state'] === 'error'): ?>
                    <div class="dashboard-state dashboard-state-error" role="alert">
                        <i class="bi bi-cloud-slash" aria-hidden="true"></i>
                        <div><strong>Status unavailable</strong><p><?= $escape($dashboard['message']) ?></p></div>
                        <a class="btn btn-quiet" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>">Try again</a>
                    </div>
                <?php elseif ($dashboard['attention'] === []): ?>
                    <div class="dashboard-state dashboard-state-empty" role="status">
                        <i class="bi bi-check-circle" aria-hidden="true"></i>
                        <div><strong>No action required</strong><p><?= $escape($dashboard['message']) ?></p></div>
                    </div>
                <?php else: ?>
                    <div class="attention-list" aria-live="polite">
                        <?php foreach ($dashboard['attention'] as $item): ?>
                            <article class="attention-item attention-<?= $escape($item['severity']) ?>">
                                <span class="attention-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
                                <div class="attention-copy">
                                    <span class="severity-label"><?= $escape(ucfirst($item['severity'])) ?> · <?= $escape(str_replace('_', ' ', $item['category'])) ?></span>
                                    <strong><?= $escape($item['title']) ?></strong>
                                    <span><?= $escape($item['explanation']) ?></span>
                                </div>
                                <a class="btn btn-small" href="<?= $escape(app_url($item['destination'])) ?>" data-dashboard-action>Review</a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($dashboard['headline'] !== []): ?>
                <section aria-labelledby="platform-status-title">
                    <div class="section-header control-center-section-header">
                        <div><h2 id="platform-status-title">Platform status</h2><p class="section-description">Security, recovery, service, and model readiness at a glance.</p></div>
                    </div>
                    <div class="card-grid control-center-headlines">
                        <?php foreach ($headlineOrder as $key => [$label, $icon]): ?>
                            <?php
                            $item = $dashboard['headline'][$key];
                            $displayValue = $key === 'latest_backup' && $item['value'] !== 'Not available'
                                ? $formatDate((string)$item['value'])
                                : $item['value'];
                            ?>
                            <article class="stat-card with-icon status-<?= $escape($item['status']) ?>">
                                <span class="stat-icon"><i class="bi <?= $escape($icon) ?>" aria-hidden="true"></i></span>
                                <div class="value"><?= $escape($displayValue) ?></div>
                                <div class="label"><?= $escape($label) ?></div>
                                <div class="hint"><?= $escape($item['detail']) ?></div>
                                <div class="stat-meta"><span class="decision-pill <?= $item['status'] === 'healthy' ? 'ok' : 'action' ?>"><?= $escape($statusLabel($item['status'])) ?></span></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="dashboard-layout equal control-center-detail-grid">
                    <section class="dashboard-section" aria-labelledby="privileged-access-title">
                        <div class="section-header"><div><h2 id="privileged-access-title">Privileged access</h2><p class="section-description">Status only; credentials are never displayed.</p></div></div>
                        <div class="indicator-list">
                            <?php foreach ($accessOrder as $accessKey => $accessLabel): ?>
                                <?php $item = $dashboard['access'][$accessKey]; ?>
                                <a class="indicator-item indicator-link" href="<?= $escape(app_url($item['destination'])) ?>" data-dashboard-action>
                                    <span><strong><?= $escape($accessLabel) ?></strong><span><?= $escape($item['detail']) ?><?php if (array_key_exists('last_used_at', $item)): ?> Last used: <?= $escape($formatDate($item['last_used_at'])) ?>.<?php endif; ?></span></span>
                                    <span class="decision-pill <?= in_array($item['status'], ['inactive', 'sealed'], true) ? 'ok' : 'action' ?>"><?= $escape($statusLabel($item['status'])) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="dashboard-section" aria-labelledby="store-continuity-title">
                        <div class="section-header"><div><h2 id="store-continuity-title">Store continuity</h2><p class="section-description">Compact read-only operating pulse; no routine Store controls.</p></div></div>
                        <div class="continuity-grid">
                            <?php foreach ($dashboard['continuity'] as $item): ?>
                                <article class="continuity-item <?= $item['ok'] ? 'is-ok' : 'needs-attention' ?>">
                                    <i class="bi <?= $item['ok'] ? 'bi-check-circle' : 'bi-exclamation-circle' ?>" aria-hidden="true"></i>
                                    <div><strong><?= $escape($item['label']) ?></strong><p><?= $escape($item['detail']) ?></p></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            <?php endif; ?>

            <section class="dashboard-section control-center-actions" aria-labelledby="platform-tools-title">
                <div class="section-header">
                    <div><h2 id="platform-tools-title">Platform governance</h2><p class="section-description">Only destinations permitted by the role capability policy are shown.</p></div>
                </div>
                <div class="quick-actions">
                    <?php foreach ($dashboard['actions'] as $action): ?>
                        <a class="quick-action" href="<?= $escape(app_url($action['destination'])) ?>" data-dashboard-action>
                            <i class="bi <?= $escape($action['icon']) ?>" aria-hidden="true"></i>
                            <strong><?= $escape($action['label']) ?></strong>
                            <span><?= $escape($action['description']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
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
