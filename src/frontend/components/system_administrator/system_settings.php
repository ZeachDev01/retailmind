<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

$message = '';
$messageClass = '';
$dormancyPolicyService = new \App\Authorization\DormancyPolicyService($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'test_email') {
        $recipient = trim((string)($_POST['test_email'] ?? ''));
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $message = 'Enter a valid recipient email address to send a test message.';
            $messageClass = 'tag-danger';
        } else {
            $sent = send_email_notification($recipient, 'RetailMind email test', "This is a RetailMind Brevo API test sent on " . date('Y-m-d H:i:s') . '.');
            $message = $sent
                ? 'Test email sent successfully.'
                : 'Test email could not be delivered. Verify the Brevo API key and sender in Store Settings.';
            $messageClass = $sent ? 'tag-success' : 'tag-danger';
        }
    } elseif ($action === 'save_dormancy') {
        try {
            $updates = [
                'disable_days' => trim((string)($_POST['disable_days'] ?? '')),
                'warn_days' => trim((string)($_POST['warn_days'] ?? '')),
            ];
            $dormancyPolicyService->update((string)current_role(), $updates, (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Dormancy Policy thresholds updated', 'Platform Settings', null, null, $updates);
            $message = 'Dormancy Policy thresholds saved.';
            $messageClass = 'tag-success';
        } catch (Throwable $exception) {
            $message = \App\Support\OperatorAlert::message($exception, 'The Dormancy Policy could not be saved. Check the values and try again. Tell your Super Administrator if this keeps happening.');
            $messageClass = 'tag-danger';
        }
    } else {
        $message = 'Choose a platform setting to save. Store and receipt settings are managed in the Administrator Workspace.';
        $messageClass = 'tag-danger';
    }
}
$settings = get_store_settings($pdo);
$dormancyThresholds = $dormancyPolicyService->thresholds();
$styleVersion = @filemtime(__DIR__ . '/../../assets/css/style.css') ?: 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Settings | RetailMind</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . $styleVersion) ?>">
</head>
<body class="platform-settings-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div>
                <h1>Platform Settings</h1>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>" data-rm-alert-processed="1" aria-live="polite">
                <i class="bi <?= $messageClass === 'tag-success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <section class="dashboard-section" aria-labelledby="heading-dormancy">
            <div class="section-header">
                <h2 id="heading-dormancy"><i class="bi bi-shield-lock" aria-hidden="true"></i> Dormancy Policy</h2>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_dormancy">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="disable_days">
                            Disable after inactivity (days)
                            <span class="required-mark" aria-hidden="true">*</span>
                        </label>
                        <input type="number"
                               min="1"
                               step="1"
                               id="disable_days"
                               name="disable_days"
                               value="<?= htmlspecialchars((string)$dormancyThresholds['disable_days']) ?>"
                               aria-required="true"
                               required>
                    </div>
                    <div class="form-group">
                        <label for="warn_days">
                            Warn after inactivity (days)
                            <span class="required-mark" aria-hidden="true">*</span>
                        </label>
                        <input type="number"
                               min="0"
                               step="1"
                               id="warn_days"
                               name="warn_days"
                               value="<?= htmlspecialchars((string)$dormancyThresholds['warn_days']) ?>"
                               aria-describedby="warn_days_help"
                               aria-required="true"
                               required>
                        <small class="field-help" id="warn_days_help">Must be fewer than the disable threshold.</small>
                    </div>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check" aria-hidden="true"></i> Save Dormancy Policy</button>
                </div>
            </form>
        </section>

        <section class="dashboard-section" aria-labelledby="heading-email-test">
            <div class="section-header">
                <h2 id="heading-email-test"><i class="bi bi-envelope-check" aria-hidden="true"></i> Email delivery test</h2>
<<<<<<< HEAD
                <p class="section-description">Test outbound email using the Brevo API settings saved in Store Settings.</p>
=======
>>>>>>> 45dcc92ff63cad9ed66d7ddc0c5f2ff504182085
            </div>
            <form method="post" class="form-inline-action">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="test_email">
                <div class="form-group form-inline-field">
                    <label for="test_email">
                        Recipient email
                        <span class="required-mark" aria-hidden="true">*</span>
                    </label>
                    <input type="email"
                           id="test_email"
                           name="test_email"
                           value="<?= htmlspecialchars((string)$settings['store_email']) ?>"
                           autocomplete="email"
                           placeholder="admin@example.com"
                           aria-required="true"
                           required>
<<<<<<< HEAD
                    <small class="field-help" id="test_email_help">Address to receive a test message from the configured Brevo sender.</small>
=======
>>>>>>> 45dcc92ff63cad9ed66d7ddc0c5f2ff504182085
                </div>
                <button type="submit" class="btn"><i class="bi bi-send" aria-hidden="true"></i> Send test email</button>
            </form>
        </section>
    </main>
</div>
</body>
</html>
