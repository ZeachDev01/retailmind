<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

$message = '';
$messageClass = '';
$fields = [
    'store_name' => 'Store name',
    'store_address' => 'Address',
    'store_phone' => 'Contact number',
    'store_email' => 'Store email',
    'business_identifier' => 'TIN or business identifier',
    'currency_symbol' => 'Currency symbol',
    'timezone' => 'Timezone',
    'expiry_alert_days' => 'Expiry alert days',
    'receipt_footer' => 'Receipt footer',
];
$fieldMeta = [
    'store_name' => ['type' => 'text', 'autocomplete' => 'organization', 'required' => true],
    'store_address' => ['type' => 'text', 'autocomplete' => 'street-address'],
    'store_phone' => ['type' => 'tel', 'autocomplete' => 'tel'],
    'store_email' => ['type' => 'email', 'autocomplete' => 'email'],
    'business_identifier' => ['type' => 'text', 'autocomplete' => 'off'],
    'currency_symbol' => ['type' => 'text', 'autocomplete' => 'off', 'maxlength' => '8'],
    'timezone' => [
        'type' => 'text',
        'autocomplete' => 'off',
        'placeholder' => 'e.g. Asia/Manila',
        'help' => 'IANA timezone identifier, such as Asia/Manila or UTC.',
    ],
    'expiry_alert_days' => [
        'type' => 'text',
        'autocomplete' => 'off',
        'placeholder' => 'e.g. 30, 15, 7',
        'help' => 'Comma-separated day thresholds before batch expiry (for example: 30, 15, 7).',
    ],
    'receipt_footer' => [
        'full' => true,
        'help' => 'Printed at the bottom of customer sales and refund receipts.',
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
<!DOCTYPE html><html lang="en"><head><?php retailmind_theme_head(); ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Platform Settings</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . $styleVersion) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content"><div class="topbar"><div><h1>Platform Settings</h1><p class="page-subtitle">Configure store identity, receipts, the Dormancy Policy, timezone, and email delivery.</p></div></div>
<?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="dashboard-section"><h2>Store and receipt</h2><p class="section-description">Business details, regional formatting, and footer text printed on customer sales slips and refund receipts.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><div class="form-grid">
<?php foreach ($fields as $key => $label):
    $meta = $fieldMeta[$key] ?? [];
    $isRequired = !empty($meta['required']);
    $isFull = !empty($meta['full']);
    $helpId = !empty($meta['help']) ? $key . '_help' : '';
?><div class="form-group<?= $isFull ? ' full' : '' ?>"><label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?><?php if ($isRequired): ?><span class="required-mark" aria-hidden="true">*</span><?php endif; ?></label><?php if ($key === 'receipt_footer'): ?><textarea id="<?= htmlspecialchars($key) ?>" name="<?= htmlspecialchars($key) ?>" rows="3"<?= $helpId !== '' ? ' aria-describedby="' . htmlspecialchars($helpId) . '"' : '' ?>><?= htmlspecialchars((string)$settings[$key]) ?></textarea><?php else: ?><input type="<?= htmlspecialchars((string)($meta['type'] ?? 'text')) ?>" id="<?= htmlspecialchars($key) ?>" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string)$settings[$key]) ?>" autocomplete="<?= htmlspecialchars((string)($meta['autocomplete'] ?? 'off')) ?>"<?= !empty($meta['maxlength']) ? ' maxlength="' . htmlspecialchars((string)$meta['maxlength']) . '"' : '' ?><?= !empty($meta['placeholder']) ? ' placeholder="' . htmlspecialchars((string)$meta['placeholder']) . '"' : '' ?><?= $helpId !== '' ? ' aria-describedby="' . htmlspecialchars($helpId) . '"' : '' ?><?= $isRequired ? ' required' : '' ?>><?php endif; ?><?php if ($helpId !== ''): ?><small class="field-help" id="<?= htmlspecialchars($helpId) ?>"><?= htmlspecialchars((string)$meta['help']) ?></small><?php endif; ?></div><?php endforeach; ?>
</div><button type="submit" class="btn">Save settings</button></form></div>
<div class="dashboard-section"><h2>Dormancy Policy</h2><p class="section-description">Dormant Accounts are disabled after the disable threshold; Administrators are warned earlier. Warn after must be fewer days than Disable after.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_dormancy"><div class="form-grid">
<div class="form-group"><label for="disable_days">Disable after (days)<span class="required-mark" aria-hidden="true">*</span></label><input type="number" min="1" step="1" id="disable_days" name="disable_days" value="<?= htmlspecialchars((string)$dormancyThresholds['disable_days']) ?>" aria-describedby="disable_days_help" required><small class="field-help" id="disable_days_help">Inactive accounts are disabled after this many days without sign-in.</small></div>
<div class="form-group"><label for="warn_days">Warn after (days)<span class="required-mark" aria-hidden="true">*</span></label><input type="number" min="0" step="1" id="warn_days" name="warn_days" value="<?= htmlspecialchars((string)$dormancyThresholds['warn_days']) ?>" aria-describedby="warn_days_help" required><small class="field-help" id="warn_days_help">Must be fewer days than the disable threshold.</small></div>
</div><button type="submit" class="btn">Save Dormancy Policy</button></form></div>
<div class="dashboard-section"><h2>Email delivery test</h2><p class="section-description">Send a verification message to confirm outbound mail delivery. Mail server credentials are managed on the server and are never shown in the browser.</p><form method="post" class="form-inline-action"><?= csrf_field() ?><input type="hidden" name="action" value="test_email"><div class="form-group form-inline-field"><label for="test_email">Test recipient<span class="required-mark" aria-hidden="true">*</span></label><input type="email" id="test_email" name="test_email" value="<?= htmlspecialchars((string)$settings['store_email']) ?>" autocomplete="email" required></div><button type="submit" class="btn">Send test email</button></form></div>
</main></div></body></html>
