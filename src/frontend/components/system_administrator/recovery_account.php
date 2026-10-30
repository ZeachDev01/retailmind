<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';

require_role(['super_admin']);

$status = (new App\Services\RecoveryAccountService($pdo))->publicStatus();
$statusKey = (string)($status['status'] ?? 'not_configured');
$statusLabel = ucwords(str_replace('_', ' ', $statusKey));

$statusMeta = match ($statusKey) {
    'sealed' => [
        'card_class' => 'status-healthy',
        'icon' => 'bi-shield-lock-fill',
        'alert_class' => 'tag-warning',
        'alert_role' => 'status',
        'hint' => 'Identity sealed; inactive until activated offline.',
        'alert_text' => '<strong>Sealed:</strong> Account is inactive and secure. Offline CLI activation required for emergency access.',
    ],
    'active' => [
        'card_class' => 'status-critical',
        'icon' => 'bi-shield-exclamation',
        'alert_class' => 'tag-danger',
        'alert_role' => 'alert',
        'hint' => 'Identity active; reseal offline when maintenance ends.',
        'alert_text' => '<strong>Attention:</strong> Recovery identity is currently unsealed. Complete recovery maintenance and reseal offline immediately.',
    ],
    default => [
        'card_class' => 'status-warning',
        'icon' => 'bi-shield-slash',
        'alert_class' => 'tag-warning',
        'alert_role' => 'status',
        'hint' => 'Identity not provisioned yet.',
        'alert_text' => '<strong>Not Configured:</strong> Offline recovery identity has not been provisioned on this host.',
    ],
};

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recovery Account Status | RetailMind</title>
    <link rel="stylesheet" href="<?= $escape(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(theme_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div>
                <h1>Recovery Account</h1>
                <p class="page-subtitle">Emergency privileged identity status and offline security boundary.</p>
            </div>
            <div class="topbar-actions" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <a class="btn btn-quiet btn-icon" href="<?= $escape(app_url('components/system_administrator/audit_logs.php')) ?>">
                    <i class="bi bi-journal-text" aria-hidden="true"></i>
                    <span>Audit Logs</span>
                </a>
                <a class="btn btn-quiet btn-icon" href="<?= $escape(app_url('components/super_administrator/dashboard.php')) ?>">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Control Center</span>
                </a>
            </div>
        </header>

        <section class="dashboard-section" aria-labelledby="status-overview-heading">
            <h2 id="status-overview-heading" style="font-size:1.15rem; font-weight:600; margin-bottom:1rem;">Status Overview</h2>

            <div class="card-grid">
                <article class="stat-card with-icon <?= $escape($statusMeta['card_class']) ?>">
                    <div class="stat-icon" aria-hidden="true"><i class="bi <?= $escape($statusMeta['icon']) ?>"></i></div>
                    <div class="value"><?= $escape($statusLabel) ?></div>
                    <div class="label">Status</div>
                    <div class="hint"><?= $escape($statusMeta['hint']) ?></div>
                </article>

                <article class="stat-card with-icon">
                    <div class="stat-icon" aria-hidden="true"><i class="bi bi-clock-history"></i></div>
                    <div class="value">
                        <?php if ($status['last_used_at']): ?>
                            <time datetime="<?= $escape($status['last_used_at']) ?>"><?= $escape(format_display_datetime($status['last_used_at'])) ?></time>
                        <?php else: ?>
                            Never
                        <?php endif; ?>
                    </div>
                    <div class="label">Last use</div>
                    <div class="hint"><?= $status['last_used_at'] ? 'Recorded emergency sign-in' : 'No recorded sign-in' ?></div>
                </article>

                <article class="stat-card with-icon">
                    <div class="stat-icon" aria-hidden="true"><i class="bi bi-terminal"></i></div>
                    <div class="value">Offline CLI</div>
                    <div class="label">Security boundary</div>
                    <div class="hint">Air-gapped; no web mutation controls</div>
                </article>
            </div>

            <div class="alert <?= $escape($statusMeta['alert_class']) ?>" role="<?= $escape($statusMeta['alert_role']) ?>" data-rm-alert-processed="1">
                <i class="bi <?= $statusKey === 'active' ? 'bi-exclamation-triangle-fill' : 'bi-shield-check' ?>" aria-hidden="true"></i>
                <span><?= $statusMeta['alert_text'] ?></span>
            </div>
        </section>

        <section class="dashboard-section" aria-labelledby="cli-guidance-heading">
            <h2 id="cli-guidance-heading" style="font-size:1.15rem; font-weight:600; margin-bottom:1rem;">Offline Operational Reference</h2>

            <div class="card-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));">
                <article class="stat-card" style="padding:1rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.4rem;">
                        <span class="badge-role" style="font-weight:600;">1. Provision</span>
                        <span style="font-size:0.75rem; color:var(--muted);">Initial setup</span>
                    </div>
                    <p style="font-size:0.85rem; margin:0 0 0.5rem 0; color:var(--text);">Create disabled identity from separate credential files.</p>
                    <code style="display:block; padding:0.4rem 0.6rem; border-radius:6px; background:var(--theme-surface-soft, #f1f5f9); font-size:0.75rem; word-break:break-all;">php src/backend/scripts/recovery_account.php provision</code>
                </article>

                <article class="stat-card" style="padding:1rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.4rem;">
                        <span class="badge-role" style="font-weight:600;">2. Activate</span>
                        <span style="font-size:0.75rem; color:var(--muted);">Emergency use</span>
                    </div>
                    <p style="font-size:0.85rem; margin:0 0 0.5rem 0; color:var(--text);">Unseal account with the offline activation secret file.</p>
                    <code style="display:block; padding:0.4rem 0.6rem; border-radius:6px; background:var(--theme-surface-soft, #f1f5f9); font-size:0.75rem; word-break:break-all;">php src/backend/scripts/recovery_account.php activate</code>
                </article>

                <article class="stat-card" style="padding:1rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.4rem;">
                        <span class="badge-role" style="font-weight:600;">3. Rotate</span>
                        <span style="font-size:0.75rem; color:var(--muted);">Post-access</span>
                    </div>
                    <p style="font-size:0.85rem; margin:0 0 0.5rem 0; color:var(--text);">Revoke active sessions and rotate credentials before reseal.</p>
                    <code style="display:block; padding:0.4rem 0.6rem; border-radius:6px; background:var(--theme-surface-soft, #f1f5f9); font-size:0.75rem; word-break:break-all;">php src/backend/scripts/recovery_account.php rotate</code>
                </article>

                <article class="stat-card" style="padding:1rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.4rem;">
                        <span class="badge-role" style="font-weight:600;">4. Reseal</span>
                        <span style="font-size:0.75rem; color:var(--muted);">Close incident</span>
                    </div>
                    <p style="font-size:0.85rem; margin:0 0 0.5rem 0; color:var(--text);">Disable web login, terminate sessions, and lock identity.</p>
                    <code style="display:block; padding:0.4rem 0.6rem; border-radius:6px; background:var(--theme-surface-soft, #f1f5f9); font-size:0.75rem; word-break:break-all;">php src/backend/scripts/recovery_account.php reseal</code>
                </article>
            </div>
        </section>
    </main>
</div>
</body>
</html>
