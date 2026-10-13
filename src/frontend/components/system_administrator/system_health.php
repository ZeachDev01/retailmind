<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/app/Services/SystemHealthService.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

$backendPath = $GLOBALS['app']['backend_path'] ?? dirname(__DIR__, 3) . '/backend';
$actorRole = (string)current_role();
$logReviews = new \App\Attention\ApplicationLogReviewService(
    $pdo, new \App\Attention\SystemClock(), $backendPath . '/storage/logs/app.log'
);
$logReviewMessage = (string)($_SESSION['application_log_review_message'] ?? '');
unset($_SESSION['application_log_review_message']);
$logReviewError = '';
$logSnapshot = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'acknowledge_application_log') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    try {
        if (($_POST['review_confirmed'] ?? '') !== '1') {
            throw new InvalidArgumentException('Confirm that you investigated the log entries before acknowledging.');
        }
        $logReviews->acknowledge(
            $actorRole, (int)$_SESSION['user_id'], (string)($_POST['log_fingerprint'] ?? ''),
            (string)($_POST['review_result'] ?? ''), (string)($_POST['review_note'] ?? ''), get_client_ip_address()
        );
        $_SESSION['application_log_review_message'] = 'Log review saved. This log version is acknowledged; new log entries and other unhealthy checks can still trigger a warning.';
        header('Location: ' . app_url('components/system_administrator/system_health.php') . '#application-log-review');
        exit;
    } catch (Throwable $exception) {
        $logReviewError = \App\Support\OperatorAlert::message($exception, 'The log review could not be saved. Refresh the page and try again.');
    }
}
try {
    $logSnapshot = $logReviews->reviewSnapshot($actorRole);
} catch (Throwable $exception) {
    $logReviewError = \App\Support\OperatorAlert::message($exception, 'Recent log entries could not be loaded. Check log access and refresh.');
}
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
$styleCssVersion = (string)(@filemtime(__DIR__ . '/../../assets/css/style.css') ?: '1');
$systemHealthCssVersion = (string)(@filemtime(__DIR__ . '/../../assets/css/system-health.css') ?: '1');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . rawurlencode($styleCssVersion)) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/system-health.css') . '?v=' . rawurlencode($systemHealthCssVersion)) ?>">
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
                    <span class="decision-pill <?= $overall === 'healthy' ? 'ok' : ($overall === 'critical' ? 'critical' : 'action') ?>"><i class="bi <?= htmlspecialchars($icons[$overall]) ?>" aria-hidden="true"></i> <?= htmlspecialchars($labels[$overall]) ?></span>
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
                            <p class="section-description">Checks report current conditions. Log acknowledgment records your review without changing other checks.</p>
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
                            <div><strong>Apply pending migrations</strong><code tabindex="0" aria-label="Apply pending migrations command">php src/backend/scripts/migrate.php</code></div>
                        </div>
                        <div class="maintenance-item">
                            <span class="maintenance-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                            <div><strong>Run release checks</strong><code tabindex="0" aria-label="Run release checks command">bash src/backend/tests/run_all.sh</code></div>
                        </div>
                        <div class="maintenance-item">
                            <span class="maintenance-icon"><i class="bi bi-file-earmark-zip" aria-hidden="true"></i></span>
                            <div><strong>Build a deployment package</strong><code tabindex="0" aria-label="Build a deployment package command">bash src/backend/scripts/build_release.sh</code></div>
                        </div>
                    </div>
                    <div class="system-health-note">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span>Health checks report current conditions only. Refresh after applying maintenance changes.</span>
                    </div>
                </aside>
            </div>
            <section class="dashboard-section application-log-review" id="application-log-review" aria-labelledby="log-review-title">
                <div class="section-header">
                    <div>
                        <h2 id="log-review-title">Review application log</h2>
                        <p class="section-description">Investigate the cause, verify the affected action, then record your review. Acknowledgment preserves the log and does not clear database or storage failures.</p>
                    </div>
                    <a class="btn btn-quiet" href="<?= htmlspecialchars(app_url('components/system_administrator/audit_logs.php')) ?>">Review history</a>
                </div>
                <?php if ($logReviewMessage !== ''): ?>
                    <p class="alert tag-success" role="status" data-rm-alert-processed="1"><?= htmlspecialchars($logReviewMessage) ?></p>
                <?php endif; ?>
                <?php if ($logReviewError !== ''): ?>
                    <p id="log-review-error" class="alert tag-warning" role="alert" data-rm-alert-processed="1"><?= htmlspecialchars($logReviewError) ?></p>
                <?php endif; ?>
                <?php if ($logSnapshot === null): ?>
                    <p class="system-health-empty">Application log details are currently unavailable. Check storage permissions for <strong>storage/logs/app.log</strong> and refresh checks.</p>
                <?php elseif ($logSnapshot['size'] === 0): ?>
                    <p class="system-health-empty">No application log entries need review.</p>
                <?php else: ?>
                    <p>Recent excerpt, up to 16 KB, from <strong>app.log</strong>. The excerpt may start mid-entry; inspect the full file if more context is needed. Do not include passwords or tokens in your review note.</p>
                    <pre class="application-log-excerpt" tabindex="0" aria-label="Recent application log entries"><?= htmlspecialchars($logSnapshot['excerpt']) ?></pre>
                    <?php if ($logSnapshot['acknowledged']): ?>
                        <p class="alert tag-success" role="status" data-rm-alert-processed="1">This log version has been acknowledged. New entries will require another review.</p>
                    <?php else: ?>
                        <?php
                        $hasFormError = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $logReviewError !== '');
                        $resultErrorAttr = $hasFormError && ($_POST['review_result'] ?? '') === '' ? ' aria-invalid="true" aria-describedby="log-review-error"' : '';
                        $noteDescribedBy = $hasFormError && trim((string)($_POST['review_note'] ?? '')) === '' ? 'log-review-error log-review-note-help' : 'log-review-note-help';
                        $noteInvalidAttr = $hasFormError && trim((string)($_POST['review_note'] ?? '')) === '' ? ' aria-invalid="true"' : '';
                        $confirmErrorAttr = $hasFormError && ($_POST['review_confirmed'] ?? '') !== '1' ? ' aria-invalid="true" aria-describedby="log-review-error"' : '';
                        ?>
                        <form method="POST" class="application-log-review-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="acknowledge_application_log">
                            <input type="hidden" name="log_fingerprint" value="<?= htmlspecialchars($logSnapshot['fingerprint']) ?>">
                            <div class="form-group">
                                <label for="log-review-result">Review result <span class="required-indicator" aria-hidden="true">*</span></label>
                                <select id="log-review-result" name="review_result" required<?= $resultErrorAttr ?>>
                                    <option value="">Choose a result</option>
                                    <?php foreach (\App\Attention\ApplicationLogReviewService::RESULTS as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" <?= ($_POST['review_result'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="log-review-note">Investigation and verification note <span class="required-indicator" aria-hidden="true">*</span></label>
                                <textarea id="log-review-note" name="review_note" rows="3" maxlength="1000" required placeholder="Describe the root cause, the fix or why the entry was expected, and how you verified it." aria-describedby="<?= $noteDescribedBy ?>"<?= $noteInvalidAttr ?>><?= htmlspecialchars((string)($_POST['review_note'] ?? '')) ?></textarea>
                                <p id="log-review-note-help" class="section-description">Record the cause, what you fixed or why the diagnostic was expected, and how you checked it (up to 1,000 characters).</p>
                            </div>
                            <label class="application-log-review-confirm"><input type="checkbox" name="review_confirmed" value="1" required<?= ($_POST['review_confirmed'] ?? '') === '1' ? ' checked' : '' ?><?= $confirmErrorAttr ?>> <span>I investigated these entries and verified the result. <span class="required-indicator" aria-hidden="true">*</span></span></label>
                            <button type="submit" class="btn btn-primary">Save review and acknowledge log warning</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
