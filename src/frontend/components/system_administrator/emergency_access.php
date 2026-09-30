<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::ACTIVATE_EMERGENCY_ACCESS);

$actorUserId = (int)$_SESSION['user_id'];
$service = emergency_access_service($pdo);
$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'activate') {
            $service->activate($actorUserId, (string)current_role(), (string)($_POST['reason'] ?? ''));
            $message = 'Emergency Access activated.';
            $messageClass = 'tag-success';
        } elseif ($action === 'revoke') {
            $service->revoke($actorUserId, (string)current_role());
            $message = 'Emergency Access revoked.';
            $messageClass = 'tag-success';
        }
    } catch (Throwable $exception) {
        error_log('Emergency Access update failed: ' . $exception->getMessage());
        $message = 'Emergency Access could not be updated. Try again. If the problem continues, review System Health.';
        $messageClass = 'tag-warning';
    }
}

$status = $service->status($actorUserId);
$isActive = $status !== null && $status['status'] === 'active';
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$statusLabel = $status === null ? 'Not activated' : ucfirst((string)$status['status']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Emergency Access</title>
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(app_url('assets/css/emergency-access.css') . '?v=' . filemtime(__DIR__ . '/../../assets/css/emergency-access.css')) ?>">
</head>
<body class="emergency-access-page <?= $isActive ? 'emergency-access-active' : 'emergency-access-inactive' ?>">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">


                <?php if ($message !== ''): ?>
                    <div class="alert emergency-access-alert <?= $escape($messageClass) ?>" role="status">
                        <i class="bi <?= $messageClass === 'tag-success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                        <span><?= $escape($message) ?></span>
                    </div>
                <?php endif; ?>

                <div class="emergency-access-layout">
                    <section class="emergency-status-stage" aria-labelledby="emergency-status-heading">
                        <div class="emergency-status-primary">
                            <div class="emergency-status-copy">

                                <h1 id="emergency-status-heading"><?= $escape($isActive ? 'Emergency Access is active' : 'Emergency Access is off') ?></h1>
                                <p><?= $escape($isActive
                                    ? 'End access when incident work is complete.'
                                    : 'Activate only when an incident requires approved Store operations.') ?></p>
                            </div>
                        </div>

                        <?php if ($isActive): ?>
                            <div class="emergency-expiry" aria-label="Emergency Access expiry">
                                <span>Session expires</span>
                                <strong><?= $escape(format_display_datetime($status['expires_at'])) ?></strong>
                                <small><?= (int)$status['duration_minutes'] ?> minute configured duration</small>
                            </div>
                        <?php endif; ?>

                        <p class="emergency-safeguards">Only your account can use this access. It expires automatically, and actions are recorded in Protected Audit Records.</p>

                        </section>

                    <aside class="emergency-action-panel <?= $isActive ? 'is-active' : '' ?>" aria-labelledby="emergency-action-heading">
                        <div class="emergency-action-heading">
                            <div>
                                <h2 id="emergency-action-heading"><?= $isActive ? 'End the active session' : 'Request temporary access' ?></h2>
                                <p><?= $escape($isActive
                                    ? 'Revoke when incident work is complete.'
                                    : 'Describe why access is needed.') ?></p>
                            </div>
                        </div>

                        <?php if ($isActive): ?>
                            <div class="emergency-action-note">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                <p><strong>Confirm incident work is complete.</strong> Any operation that depends on this Emergency Access session will be denied after revocation.</p>
                            </div>
                            <form method="post" class="emergency-action-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="revoke">
                                <button class="btn emergency-revoke-button" type="submit"><i class="bi bi-lock-fill" aria-hidden="true"></i> Revoke Emergency Access</button>
                            </form>
                        <?php else: ?>
                            <form method="post" class="emergency-action-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="activate">
                                <div class="form-group">
                                    <div class="emergency-label-row">
                                        <label for="reason">Incident reason</label>
                                        <span id="reason-count" aria-live="polite">0 / 500</span>
                                    </div>
                                    <textarea id="reason" name="reason" rows="4" maxlength="500" placeholder="What happened, and which Store operation needs access?" aria-describedby="reason-help" required></textarea>
                                    <small id="reason-help">This reason is saved in the session record.</small>
                                </div>
                                <label class="emergency-confirmation">
                                    <input type="checkbox" name="scope_acknowledged" required>
                                    <span>I understand that access is temporary, limited to approved capabilities, and recorded.</span>
                                </label>
                                <button class="btn emergency-activate-button" type="submit"><i class="bi bi-unlock-fill" aria-hidden="true"></i> Activate Emergency Access</button>
                            </form>
                        <?php endif; ?>
                    </aside><?php if ($status !== null): ?>
                            <div class="emergency-session-record">
                                <div class="section-header">
                                    <div>
                                        <h3>Session record</h3>

                                    </div>
                                    <span class="emergency-record-status <?= $isActive ? 'is-active' : 'is-closed' ?>"><?= $escape($statusLabel) ?></span>
                                </div>
                                <dl class="emergency-session-details">
                                    <div class="emergency-reason-row">
                                        <dt>Incident reason</dt>
                                        <dd><?= $escape((string)$status['reason']) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Activated</dt>
                                        <dd><?= $escape(format_display_datetime($status['activated_at'])) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Expires</dt>
                                        <dd><?= $escape(format_display_datetime($status['expires_at'])) ?></dd>
                                    </div>
                                    <?php if ($status['revoked_at'] !== null): ?>
                                        <div>
                                            <dt>Revoked</dt>
                                            <dd><?= $escape(format_display_datetime($status['revoked_at'])) ?></dd>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <dt>Session ID</dt>
                                        <dd class="emergency-session-id">EA-<?= (int)$status['session_id'] ?></dd>
                                    </div>
                                </dl>
                            </div>
                        <?php else: ?>
                            <div class="emergency-empty-record">
                                <i class="bi bi-clock-history" aria-hidden="true"></i>
                                <div><strong>No session for this account</strong><span>A session record will appear here after activation.</span></div>
                            </div>
                        <?php endif; ?>

                </div>
            </main>
</div>
<?php if (!$isActive): ?>
<script>
    (function () {
        document.querySelectorAll('textarea[name="reason"]').forEach(function (reason) {
            var counter = reason.closest('.form-group').querySelector('[aria-live="polite"]');
            if (!counter) return;
            var updateCount = function () { counter.textContent = reason.value.length + ' / 500'; };
            reason.addEventListener('input', updateCount);
            updateCount();
        });
    })();
</script>
<?php endif; ?>
</body>
</html>
