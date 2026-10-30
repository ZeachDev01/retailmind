<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

$message = '';
$messageClass = '';
$fields = [
    'store_name' => 'Store name',
    'business_identifier' => 'TIN / Business identifier',
    'store_email' => 'Store email',
    'store_phone' => 'Contact number',
    'store_address' => 'Store address',
    'currency_symbol' => 'Currency symbol',
    'timezone' => 'Timezone (IANA)',
    'expiry_alert_days' => 'Expiry alert lead time (days)',
    'receipt_footer' => 'Receipt footer',
];
$fieldMeta = [
    'store_name' => [
        'type' => 'text',
        'autocomplete' => 'organization',
        'required' => true,
    ],
    'business_identifier' => [
        'type' => 'text',
        'autocomplete' => 'off',
    ],
    'store_email' => [
        'type' => 'email',
        'autocomplete' => 'email',
    ],
    'store_phone' => [
        'type' => 'tel',
        'autocomplete' => 'tel',
    ],
    'store_address' => [
        'type' => 'text',
        'autocomplete' => 'street-address',
        'full' => true,
    ],
    'currency_symbol' => [
        'type' => 'text',
        'autocomplete' => 'off',
        'maxlength' => '8',
    ],
    'timezone' => [
        'type' => 'text',
        'autocomplete' => 'off',
    ],
    'expiry_alert_days' => [
        'type' => 'text',
        'autocomplete' => 'off',
    ],
    'receipt_footer' => [
        'full' => true,
    ],
];
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
            $sent = send_email_notification($recipient, 'RetailMind email test', "This is a RetailMind SMTP test sent on " . date('Y-m-d H:i:s') . '.');
            $message = $sent
                ? 'Test email sent successfully.'
                : 'Test email could not be delivered. Verify the mail server configuration and try again.';
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
        $updates = [];
        foreach ($fields as $key => $label) {
            $updates[$key] = trim((string)($_POST[$key] ?? ''));
        }
        if ($updates['store_name'] === '') {
            $message = 'Store name is required.';
            $messageClass = 'tag-danger';
        } elseif ($updates['store_email'] !== '' && filter_var($updates['store_email'], FILTER_VALIDATE_EMAIL) === false) {
            $message = 'Enter a valid store email address.';
            $messageClass = 'tag-danger';
        } elseif ($updates['timezone'] !== '' && !in_array($updates['timezone'], timezone_identifiers_list(), true)) {
            $message = 'Enter a valid IANA timezone such as Asia/Manila or UTC.';
            $messageClass = 'tag-danger';
        } else {
            save_key_value_settings($pdo, 'store_settings', $updates, (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Store settings update', 'Store Settings', null, null, $updates);
            $message = 'Store and receipt settings saved.';
            $messageClass = 'tag-success';
        }
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
                <p class="page-subtitle">Configure store identity, receipt formatting, account dormancy, and notification dispatch.</p>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>" data-rm-alert-processed="1" aria-live="polite">
                <i class="bi <?= $messageClass === 'tag-success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <section class="dashboard-section" aria-labelledby="heading-store">
            <div class="section-header">
                <h2 id="heading-store"><i class="bi bi-shop" aria-hidden="true"></i> Store &amp; receipts</h2>
                <p class="section-description">Store identity, regional defaults, and customer receipt text.</p>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <div class="form-grid">
                    <?php foreach ($fields as $key => $label):
                        $meta = $fieldMeta[$key] ?? [];
                        $isRequired = !empty($meta['required']);
                        $isFull = !empty($meta['full']);
                        $helpId = !empty($meta['help']) ? $key . '_help' : '';
                    ?>
                        <div class="form-group<?= $isFull ? ' full' : '' ?>">
                            <label for="<?= htmlspecialchars($key) ?>">
                                <?= htmlspecialchars($label) ?>
                                <?php if ($isRequired): ?><span class="required-mark" aria-hidden="true">*</span><?php endif; ?>
                            </label>
                            <?php if ($key === 'receipt_footer'): ?>
                                <textarea id="<?= htmlspecialchars($key) ?>"
                                          name="<?= htmlspecialchars($key) ?>"
                                          rows="3"
                                          <?= $helpId !== '' ? ' aria-describedby="' . htmlspecialchars($helpId) . '"' : '' ?>
                                          <?= !empty($meta['placeholder']) ? ' placeholder="' . htmlspecialchars((string)$meta['placeholder']) . '"' : '' ?>><?= htmlspecialchars((string)($settings[$key] ?? '')) ?></textarea>
                            <?php else: ?>
                                <input type="<?= htmlspecialchars((string)($meta['type'] ?? 'text')) ?>"
                                       id="<?= htmlspecialchars($key) ?>"
                                       name="<?= htmlspecialchars($key) ?>"
                                       value="<?= htmlspecialchars((string)($settings[$key] ?? '')) ?>"
                                       autocomplete="<?= htmlspecialchars((string)($meta['autocomplete'] ?? 'off')) ?>"
                                       <?= !empty($meta['maxlength']) ? ' maxlength="' . htmlspecialchars((string)$meta['maxlength']) . '"' : '' ?>
                                       <?= !empty($meta['placeholder']) ? ' placeholder="' . htmlspecialchars((string)$meta['placeholder']) . '"' : '' ?>
                                       <?= $helpId !== '' ? ' aria-describedby="' . htmlspecialchars($helpId) . '"' : '' ?>
                                       <?= $isRequired ? ' required aria-required="true"' : '' ?>>
                            <?php endif; ?>
                            <?php if ($helpId !== ''): ?>
                                <small class="field-help" id="<?= htmlspecialchars($helpId) ?>"><?= htmlspecialchars((string)$meta['help']) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> Save store settings</button>
                </div>
            </form>
        </section>

        <section class="dashboard-section" aria-labelledby="heading-dormancy">
            <div class="section-header">
                <h2 id="heading-dormancy"><i class="bi bi-shield-lock" aria-hidden="true"></i> Dormancy Policy</h2>
                <p class="section-description">Deactivation thresholds for inactive accounts. Warning lead time must be fewer days than deactivation.</p>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_dormancy">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="disable_days">
                            Disable inactive accounts (days)
                            <span class="required-mark" aria-hidden="true">*</span>
                        </label>
                        <input type="number"
                               min="1"
                               step="1"
                               id="disable_days"
                               name="disable_days"
                               value="<?= htmlspecialchars((string)$dormancyThresholds['disable_days']) ?>"
                               aria-describedby="disable_days_help"
                               aria-required="true"
                               required>
                        <small class="field-help" id="disable_days_help">Days of inactivity before an account is automatically locked.</small>
                    </div>
                    <div class="form-group">
                        <label for="warn_days">
                            Warn before deactivation (days)
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
                        <small class="field-help" id="warn_days_help">Days of inactivity before alerting administrators prior to deactivation.</small>
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
                <p class="section-description">Test outbound SMTP notification dispatch using configured mail environment.</p>
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
                           aria-describedby="test_email_help"
                           aria-required="true"
                           required>
                    <small class="field-help" id="test_email_help">Address to receive a test message verifying SMTP connectivity.</small>
                </div>
                <button type="submit" class="btn"><i class="bi bi-send" aria-hidden="true"></i> Send test email</button>
            </form>
        </section>
    </main>
</div>
</body>
</html>
