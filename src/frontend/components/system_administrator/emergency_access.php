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
        $message = $exception->getMessage();
        $messageClass = 'tag-warning';
    }
}

$status = $service->status($actorUserId);
$isActive = $status !== null && $status['status'] === 'active';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Emergency Access</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1>Emergency Access</h1>
                <p class="page-subtitle">Temporarily perform isolated Store operations during an incident.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="status"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <section class="dashboard-section" aria-labelledby="emergency-status-heading">
            <h2 id="emergency-status-heading">Current status</h2>
            <?php if ($status === null): ?>
                <p>No Emergency Access session has been created for this account.</p>
            <?php else: ?>
                <dl>
                    <dt>Status</dt><dd><strong><?= htmlspecialchars(ucfirst((string)$status['status'])) ?></strong></dd>
                    <dt>Reason</dt><dd><?= htmlspecialchars((string)$status['reason']) ?></dd>
                    <dt>Activated</dt><dd><?= htmlspecialchars(format_display_datetime($status['activated_at'])) ?></dd>
                    <dt>Expires</dt><dd><?= htmlspecialchars(format_display_datetime($status['expires_at'])) ?></dd>
                    <dt>Configured duration</dt><dd><?= (int)$status['duration_minutes'] ?> minutes</dd>
                    <dt>Session identifier</dt><dd><?= (int)$status['session_id'] ?></dd>
                    <?php if ($status['revoked_at'] !== null): ?>
                        <dt>Revoked</dt><dd><?= htmlspecialchars(format_display_datetime($status['revoked_at'])) ?></dd>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        </section>

        <section class="dashboard-section" aria-labelledby="emergency-action-heading">
            <h2 id="emergency-action-heading"><?= $isActive ? 'End Emergency Access' : 'Activate Emergency Access' ?></h2>
            <?php if ($isActive): ?>
                <p>Revocation takes effect immediately. Further Store mutations will be denied.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="revoke">
                    <button class="btn" type="submit">Revoke access now</button>
                </form>
            <?php else: ?>
                <p>Access is actor-bound, short-lived, and limited to approved operational capabilities. All permitted actions are correlated to this session in Protected Audit Records.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="activate">
                    <div class="form-group">
                        <label for="reason">Incident reason</label>
                        <textarea id="reason" name="reason" rows="4" maxlength="500" required></textarea>
                    </div>
                    <button class="btn" type="submit">Activate temporary access</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
