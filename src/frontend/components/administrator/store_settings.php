<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);
require_once __DIR__ . '/../../../backend/app/Receipts/ReceiptLogo.php';

$receiptFields = [
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
        'help' => 'Optional when a receipt logo is saved. The logo appears alone on receipts.',
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
$settings = get_store_settings($pdo);
$receiptLogo = \App\Receipts\ReceiptLogo::source($settings['receipt_logo'] ?? '');

$service = new App\Attention\AttentionSettingsService($pdo, new App\Attention\SystemClock());
$fields = [
    'cash_variance_amount' => [
        'label' => 'Cash variance review threshold (₱)',
        'step' => '0.01',
    ],
    'inventory_overdue_hours' => [
        'label' => 'Stock Issue overdue warning (hours)',
        'step' => '1',
    ],
    'inventory_high_value_amount' => [
        'label' => 'High-value Stock Issue alert (₱)',
        'step' => '0.01',
    ],
    'fiscal_period_days_to_close' => [
        'label' => 'Fiscal Period closing warning (days)',
        'step' => '1',
    ],
];
$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_local_request();
    csrf_verify();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_receipts') {
            $updates = [];
            foreach ($receiptFields as $key => $label) {
                if (!is_string($_POST[$key] ?? null)) {
                    throw new InvalidArgumentException('Check the Store and receipt fields and try again.');
                }
                $updates[$key] = trim($_POST[$key]);
            }
            if ($updates['store_name'] === '' && $receiptLogo === '') {
                throw new InvalidArgumentException('Store name is required unless a receipt logo is saved.');
            }
            if ($updates['store_email'] !== '' && filter_var($updates['store_email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Enter a valid store email address.');
            }
            if ($updates['timezone'] !== '' && !in_array($updates['timezone'], timezone_identifiers_list(), true)) {
                throw new InvalidArgumentException('Enter a valid IANA timezone such as Asia/Manila or UTC.');
            }
            if ($updates['store_email'] !== trim((string)($settings['store_email'] ?? ''))
                && !empty($settings['store_email_alerts_enabled'])) {
                $updates['store_email_alerts_enabled'] = '0';
            }
            \App\Store\StoreWriteGate::run($pdo, function () use ($pdo, $updates): void {
                save_key_value_settings($pdo, 'store_settings', $updates, (int)$_SESSION['user_id']);
                log_activity($pdo, (int)$_SESSION['user_id'], 'Store settings update', 'Store Settings', null, null, $updates);
            });
            $message = isset($updates['store_email_alerts_enabled'])
                ? 'Store and receipt settings saved. Test email again to enable alerts for the new Store email address.'
                : 'Store and receipt settings saved.';
        } elseif ($action === 'save_receipt_logo' || $action === 'remove_receipt_logo') {
            if ($action === 'remove_receipt_logo' && trim((string)($settings['store_name'] ?? '')) === '') {
                throw new InvalidArgumentException('Save a Store name before removing the receipt logo.');
            }
            $logo = $action === 'remove_receipt_logo' ? '' : \App\Receipts\ReceiptLogo::fromUpload($_FILES['receipt_logo'] ?? null);
            \App\Store\StoreWriteGate::run($pdo, function () use ($pdo, $logo, $action): void {
                save_key_value_settings($pdo, 'store_settings', ['receipt_logo' => $logo], (int)$_SESSION['user_id']);
                log_activity($pdo, (int)$_SESSION['user_id'], 'Receipt logo update', 'Store Settings', null, null, ['action' => $action]);
            });
            $message = $logo === '' ? 'Receipt logo removed. Existing receipts keep their original logo.' : 'Receipt logo saved. New receipts will use this logo.';
        } elseif ($action === 'save_brevo' || $action === 'test_brevo') {
            $senderEmail = trim((string)($_POST['brevo_sender_email'] ?? ''));
            $apiKeyInput = trim((string)($_POST['brevo_api_key'] ?? ''));
            $savedSettings = get_store_settings($pdo);
            $apiKey = $apiKeyInput !== ''
                ? $apiKeyInput
                : (!empty($savedSettings['brevo_api_key']) ? decrypt_store_email_secret((string)$savedSettings['brevo_api_key']) : '');
            if (filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false || $apiKey === '' || strlen($apiKey) > 512) {
                throw new InvalidArgumentException('Enter a Brevo API key and a valid verified sender email address.');
            }
            $updates = [
                'brevo_api_key' => $apiKeyInput !== ''
                    ? encrypt_store_email_secret($apiKeyInput)
                    : (string)$savedSettings['brevo_api_key'],
                'brevo_sender_email' => $senderEmail,
                'store_email_alerts_enabled' => '0',
            ];
            \App\Store\StoreWriteGate::run($pdo, function () use ($pdo, $updates): void {
                save_key_value_settings($pdo, 'store_settings', $updates, (int)$_SESSION['user_id']);
                $pdo->exec("DELETE FROM store_settings WHERE setting_key IN (
                    'smtp_provider', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
                    'smtp_from_email', 'smtp_password', 'smtp_email_enabled'
                )");
            });
            log_activity($pdo, (int)$_SESSION['user_id'], 'Brevo email settings updated', 'Store Settings', null, null, [
                'brevo_sender_email' => $senderEmail,
            ]);

            if ($action === 'test_brevo') {
                $recipient = trim((string)($savedSettings['store_email'] ?? ''));
                if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                    $message = 'Brevo settings saved. Add a valid Store email address, then send a test email.';
                    $messageClass = 'tag-warning';
                } else {
                    if (send_email_notification($recipient, 'RetailMind email test', 'Your Brevo API settings are working. Store email alerts are now enabled.')) {
                        $updates['store_email_alerts_enabled'] = '1';
                        \App\Store\StoreWriteGate::run($pdo, function () use ($pdo, $updates): void {
                            save_key_value_settings($pdo, 'store_settings', ['store_email_alerts_enabled' => $updates['store_email_alerts_enabled']], (int)$_SESSION['user_id']);
                        });
                        $message = 'Test email sent. Store email alerts are enabled.';
                        $messageClass = 'tag-success';
                    } else {
                        $message = 'Test email failed. Check the Brevo API key, verified sender, and Store email address, then try again. Email alerts remain disabled.';
                        $messageClass = 'tag-warning';
                    }
                }
            } else {
                $message = 'Brevo settings saved. Send a test email to enable Store email alerts.';
                $messageClass = 'tag-success';
            }
        } elseif ($action === 'disable_brevo') {
            \App\Store\StoreWriteGate::run($pdo, function () use ($pdo): void {
                save_key_value_settings($pdo, 'store_settings', ['store_email_alerts_enabled' => '0'], (int)$_SESSION['user_id']);
            });
            $message = 'Store email alerts disabled.';
        } elseif ($action === 'save_attention') {
            $updates = [];
            foreach ($fields as $key => $config) {
                $updates[$key] = $_POST[$key] ?? '';
            }
            $service->updateStore(current_role(), $updates, (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Store attention thresholds updated', 'Store Settings', null, null, $updates);
            $message = 'Store attention thresholds saved. Platform safety limits are applied automatically.';
        } else {
            throw new InvalidArgumentException('Choose a Store Settings section to save.');
        }
        if ($messageClass === '') $messageClass = 'tag-success';
    } catch (Throwable $exception) {
        $message = \App\Support\OperatorAlert::message($exception, 'The Store Setting could not be saved. Check the values and try again. Tell your Administrator if this keeps happening.');
        $messageClass = 'tag-warning';
    }
}

$settings = get_store_settings($pdo);
$receiptLogo = \App\Receipts\ReceiptLogo::source($settings['receipt_logo'] ?? '');
$requested = $service->storeSettings();
$effective = $service->thresholdsFor('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Store Configuration</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/store-settings.css')) ?>">
</head>
<body class="store-settings-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Store Configuration</h1>
                <p class="page-subtitle">Maintain Store identity, customer receipts, and operational attention thresholds.</p>
            </div>
        </header>
        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>" aria-live="polite"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <section class="dashboard-section" aria-labelledby="heading-store">
            <div class="section-header">
                <h2 id="heading-store">Store &amp; receipts</h2>
                <p class="section-description">Store identity, regional defaults, and customer receipt text.</p>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_receipts">
                <div class="form-grid">
                    <?php foreach ($receiptFields as $key => $label):
                        $meta = $fieldMeta[$key] ?? [];
                        $isRequired = !empty($meta['required']) && !($key === 'store_name' && $receiptLogo !== '');
                        $isFull = !empty($meta['full']);
                        $helpId = !empty($meta['help']) && ($key !== 'store_name' || $receiptLogo !== '') ? $key . '_help' : '';
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
                    <button type="submit" class="btn btn-primary">Save store settings</button>
                </div>
            </form>
            <form method="post" enctype="multipart/form-data" class="receipt-logo-form">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="receipt_logo">Receipt logo</label>
                    <input type="file" id="receipt_logo" name="receipt_logo" accept="image/jpeg,image/png" aria-describedby="receipt-logo-help">
                    <small class="field-help" id="receipt-logo-help"><strong>Recommended size: 384 × 192 px (wide) or 192 × 192 px (square).</strong><br>JPG or PNG, up to 2 MB and 4 million pixels. Automatically fits 58/80 mm receipts in black and white and trims blank edges. Use a simple, high-contrast logo. Existing receipts keep their original logo. Remove it to print the Store name only.</small>
                    <?php if ($receiptLogo !== ''): ?>
                        <img id="receipt-logo-preview" class="receipt-logo-preview" src="<?= htmlspecialchars($receiptLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Saved receipt logo" width="192" height="96" loading="lazy">
                    <?php else: ?>
                        <p class="field-help">No receipt logo saved.</p>
                    <?php endif; ?>
                </div>
                <div class="form-actions">
                    <button type="submit" name="action" value="save_receipt_logo" class="btn btn-primary">Save receipt logo</button>
                    <?php if ($receiptLogo !== ''): ?>
                        <button type="submit" name="action" value="remove_receipt_logo" class="btn btn-secondary">Remove receipt logo</button>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="dashboard-section" aria-labelledby="thresholds-heading">
            <div class="section-header">
                <div>
                    <h2 id="thresholds-heading">Attention thresholds</h2>
                    <p class="section-description">Operational thresholds trigger automated system reviews and supervisory warnings.</p>
                </div>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_attention">
                <div class="form-grid">
                    <?php foreach ($fields as $key => $config): ?>
                        <?php $hasLimit = isset($effective[$key], $requested[$key]) && (float)$effective[$key] !== (float)$requested[$key]; ?>
                        <div class="form-group">
                            <label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($config['label']) ?></label>
                            <input id="<?= htmlspecialchars($key) ?>" type="number" min="0" step="<?= htmlspecialchars($config['step']) ?>" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string)($requested[$key] ?? 0)) ?>" autocomplete="off"<?= $hasLimit ? ' aria-describedby="' . htmlspecialchars($key) . '-limit"' : '' ?> required>
                            <?php if ($hasLimit): ?>
                                <small id="<?= htmlspecialchars($key) ?>-limit" class="field-help u-text-muted">Platform-limited value: <?= htmlspecialchars((string)$effective[$key]) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions u-mt-1">
                    <button class="btn btn-primary" type="submit">Save attention thresholds</button>
                </div>
            </form>
        </section>
        <?php $brevoConfigured = !empty($settings['brevo_api_key']); ?>
        <section class="dashboard-section" aria-labelledby="email-heading">
            <div class="section-header">
                <div>
                    <h2 id="email-heading">Brevo email</h2>
                    <p class="section-description">Brevo sends Store alerts and other application emails. Enter a Brevo API key and a verified sender address. The API key is encrypted at rest and never displayed.</p>
                </div>
            </div>
            <form method="post" id="store-brevo-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_brevo" id="brevo-action">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="brevo_api_key">Brevo API key</label>
                        <input id="brevo_api_key" name="brevo_api_key" type="password" autocomplete="new-password" placeholder="<?= $brevoConfigured ? 'Saved; leave blank to keep it' : 'Enter Brevo API key' ?>">
                        <small class="field-help">Use an API key, not an SMTP key. Leave blank to keep the saved key.</small>
                    </div>
                    <div class="form-group">
                        <label for="brevo_sender_email">Verified sender email</label>
                        <input id="brevo_sender_email" name="brevo_sender_email" type="email" autocomplete="email" spellcheck="false" value="<?= htmlspecialchars((string)($settings['brevo_sender_email'] ?? '')) ?>" required>
                        <small class="field-help">This sender must be verified in your Brevo account. Emails display the Store name.</small>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Brevo settings</button>
                    <button type="submit" class="btn btn-secondary" onclick="document.getElementById('brevo-action').value='test_brevo'">Save and send test email</button>
                </div>
            </form>
            <form method="post" class="form-actions">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="disable_brevo">
                <button type="submit" class="btn btn-secondary"<?= empty($settings['store_email_alerts_enabled']) ? ' disabled' : '' ?>>Disable Store alerts</button>
                <span class="field-help" role="status"><?= !empty($settings['store_email_alerts_enabled']) ? 'Store email alerts are enabled.' : 'Store alerts are disabled until a test email succeeds.' ?></span>
            </form>
        </section>
    </main>
</div>
</body>
</html>
