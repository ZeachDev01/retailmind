<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);
require_once __DIR__ . '/../../../backend/app/Receipts/ReceiptLogo.php';
require_once __DIR__ . '/../../../backend/app/Services/CloudflareTunnelService.php';
require_once __DIR__ . '/../../../backend/app/Services/QrCodeService.php';

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
        } elseif ($action === 'save_tunnel_token') {
            $tunnelToken = trim((string)($_POST['tunnel_token'] ?? ''));
            $tunnelHostname = trim((string)($_POST['tunnel_hostname'] ?? ''));
            $ts = new \App\Services\CloudflareTunnelService();
            if ($tunnelToken === '') {
                $ts->clearToken();
                $message = 'Permanent Cloudflare Tunnel token cleared. Ephemeral quick tunnel fallback enabled.';
            } else {
                $ts->setToken($tunnelToken, $tunnelHostname !== '' ? $tunnelHostname : null);
                log_activity($pdo, (int)$_SESSION['user_id'], 'Cloudflare Tunnel token updated', 'Store Settings', null, null, [
                    'hostname' => $tunnelHostname,
                ]);
                $message = 'Permanent Cloudflare Tunnel token configured.';
            }
        } elseif ($action === 'clear_tunnel_token') {
            $ts = new \App\Services\CloudflareTunnelService();
            $ts->clearToken();
            log_activity($pdo, (int)$_SESSION['user_id'], 'Cloudflare Tunnel token cleared', 'Store Settings');
            $message = 'Permanent Cloudflare Tunnel token cleared. Ephemeral quick tunnel fallback enabled.';
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
$tunnelService = new \App\Services\CloudflareTunnelService();
$tunnelStatus = $tunnelService->getStatus();
$qrService = new \App\Services\QrCodeService();
$initialQrSvg = (!empty($tunnelStatus['online']) && !empty($tunnelStatus['url'])) ? $qrService->generateSvg($tunnelStatus['url'], 220) : '';
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

        <section class="dashboard-section" id="remote-access-card" aria-labelledby="remote-access-heading">
            <div class="section-header">
                <div class="remote-access-header-wrap">
                    <div>
                        <h2 id="remote-access-heading">Remote Access</h2>
                        <p class="section-description">Secure Cloudflare tunnel for supervisor and back-office remote monitoring without port forwarding.</p>
                    </div>
                    <div class="remote-badges-group">
                        <span id="remote-mode-badge" class="<?= !empty($tunnelStatus['token_configured']) ? 'tag-primary' : 'tag-secondary' ?>">
                            <?= !empty($tunnelStatus['token_configured']) ? 'Permanent Named Tunnel' : 'Quick Tunnel (Ephemeral)' ?>
                        </span>
                        <span id="remote-status-badge" class="<?= ($tunnelStatus['status'] ?? 'offline') === 'online' ? 'tag-success' : (($tunnelStatus['status'] ?? 'offline') === 'starting' ? 'tag-warning' : 'tag-danger') ?>" role="status">
                            <?= ($tunnelStatus['status'] ?? 'offline') === 'online' ? 'Online' : (($tunnelStatus['status'] ?? 'offline') === 'starting' ? 'Connecting...' : 'Offline') ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="remote-access-content">
                <div class="remote-toggle-row">
                    <div>
                        <label for="remote-access-toggle" class="remote-toggle-title">Enable Cloudflare Tunnel</label>
                        <p class="field-help">Activate the portable background tunnel daemon to expose an encrypted connection.</p>
                    </div>
                    <div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="remote-access-toggle" role="switch" <?= !empty($tunnelStatus['online']) ? 'checked' : '' ?> aria-checked="<?= !empty($tunnelStatus['online']) ? 'true' : 'false' ?>" aria-label="Toggle Remote Access Tunnel">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
                <div id="remote-url-container" class="remote-url-container<?= (!empty($tunnelStatus['online']) && !empty($tunnelStatus['url'])) ? '' : ' u-hidden' ?>">
                    <label for="remote-public-url" class="remote-url-label">Active Remote URL</label>
                    <div class="remote-url-group">
                        <input type="text" id="remote-public-url" class="remote-url-input" readonly spellcheck="false" value="<?= htmlspecialchars((string)($tunnelStatus['url'] ?? '')) ?>" placeholder="Tunnel is offline">
                        <button type="button" id="copy-remote-url-btn" class="btn btn-secondary" title="Copy public URL to clipboard">
                            <span id="copy-btn-text">Copy URL</span>
                        </button>
                        <button type="button" id="show-qr-code-btn" class="btn btn-secondary" title="Display mobile QR code">
                            <span>📱 Show QR</span>
                        </button>
                    </div>
                    <small class="field-help">Share this URL only with authorized supervisors. Physical cashier operations remain locked to local store registers.</small>
                </div>
                <div id="remote-access-feedback" class="field-help u-mt-1" role="status" aria-live="polite"></div>

                <hr class="u-my-2">

                <form method="post" class="remote-token-config-form">
                    <?= csrf_field() ?>
                    <h3 class="subsection-title">Permanent Tunnel Token Configuration</h3>
                    <p class="field-help">Configure a Cloudflare Zero Trust permanent tunnel token (<code>cloudflared run --token &lt;token&gt;</code>) to route through your custom fixed domain instead of ephemeral quick tunnels.</p>
                    <div class="form-grid u-mt-1">
                        <div>
                            <label for="tunnel_token" class="field-label">Permanent Tunnel Token</label>
                            <input type="password" id="tunnel_token" name="tunnel_token" class="input-text" autocomplete="off"
                                   value="<?= !empty($tunnelStatus['masked_token']) ? htmlspecialchars((string)$tunnelStatus['masked_token']) : '' ?>"
                                   placeholder="Paste eyJh... token from Cloudflare Zero Trust">
                            <small class="field-help">Stored securely in configuration. Paste a new token to update, or clear to revert to ephemeral quick tunnels.</small>
                        </div>
                        <div>
                            <label for="tunnel_hostname" class="field-label">Public Domain / Hostname (Optional)</label>
                            <input type="text" id="tunnel_hostname" name="tunnel_hostname" class="input-text" autocomplete="off"
                                   value="<?= htmlspecialchars((string)($tunnelStatus['hostname'] ?? '')) ?>"
                                   placeholder="e.g. supervisor.mystore.com">
                            <small class="field-help">Custom domain mapped in your Cloudflare Zero Trust dashboard.</small>
                        </div>
                    </div>
                    <div class="form-actions u-mt-1">
                        <button type="submit" name="action" value="save_tunnel_token" class="btn btn-primary">Save Tunnel Token</button>
                        <?php if (!empty($tunnelStatus['token_configured'])): ?>
                            <button type="submit" name="action" value="clear_tunnel_token" class="btn btn-secondary">Clear Token</button>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="remote-service-hint u-mt-2">
                    <p class="field-help">
                        <strong>Dedicated Store PC Service:</strong> For 24/7 supervisor access with automatic startup at boot and crash recovery, install the Windows Service:
                        <code>powershell -ExecutionPolicy Bypass -File .\src\backend\scripts\tunnel\service_install.ps1</code>
                        &bull; <a href="<?= htmlspecialchars(app_url('components/auth/help.php#tunnel-deployment')) ?>" target="_blank">5-Minute Dedicated Store PC Setup Guide</a>
                    </p>
                </div>
            </div>
        </section>

        <!-- Dedicated Mobile QR Code Modal -->
        <div id="qr-code-modal" class="qr-modal-backdrop u-hidden" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title" tabindex="-1">
            <div class="qr-modal-dialog">
                <div class="qr-modal-header">
                    <h3 id="qr-modal-title">Mobile Access QR Code</h3>
                    <button type="button" id="close-qr-modal-btn" class="modal-close-btn" aria-label="Close dialog">&times;</button>
                </div>
                <div class="qr-modal-body">
                    <p class="qr-modal-description">Scan this QR code with your smartphone camera to connect to RetailMind remotely without typing.</p>
                    <div id="qr-code-display" class="qr-code-display" aria-label="Scannable QR code of active remote URL">
                        <?= !empty($initialQrSvg) ? $initialQrSvg : '' ?>
                    </div>
                    <div class="qr-modal-url-wrap">
                        <input type="text" id="modal-remote-url" class="remote-url-input" readonly spellcheck="false" value="<?= htmlspecialchars((string)($tunnelStatus['url'] ?? '')) ?>" placeholder="Tunnel is offline">
                        <button type="button" id="modal-copy-url-btn" class="btn btn-secondary" title="Copy active URL to clipboard">
                            <span id="modal-copy-btn-text">Copy URL</span>
                        </button>
                    </div>
                    <small class="field-help u-text-center">Only share this URL with authorized store supervisors.</small>
                </div>
                <div class="qr-modal-footer">
                    <button type="button" id="close-qr-modal-footer-btn" class="btn btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="<?= htmlspecialchars(theme_url('assets/vendor/qrcode/qrcode.min.js')) ?>"></script>
<script>
(function() {
    const toggle = document.getElementById('remote-access-toggle');
    const badge = document.getElementById('remote-status-badge');
    const urlContainer = document.getElementById('remote-url-container');
    const urlInput = document.getElementById('remote-public-url');
    const copyBtn = document.getElementById('copy-remote-url-btn');
    const copyText = document.getElementById('copy-btn-text');
    const showQrBtn = document.getElementById('show-qr-code-btn');
    const modal = document.getElementById('qr-code-modal');
    const modalCloseBtn = document.getElementById('close-qr-modal-btn');
    const modalFooterCloseBtn = document.getElementById('close-qr-modal-footer-btn');
    const modalUrlInput = document.getElementById('modal-remote-url');
    const modalCopyBtn = document.getElementById('modal-copy-url-btn');
    const modalCopyText = document.getElementById('modal-copy-btn-text');
    const qrDisplay = document.getElementById('qr-code-display');
    const feedback = document.getElementById('remote-access-feedback');
    const csrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const apiUrl = 'remote_access_api.php';

    let pollTimer = null;
    let pollCount = 0;

    function renderQrCode(url) {
        if (!qrDisplay || !url) return;
        qrDisplay.innerHTML = '';
        if (window.QRCode) {
            new QRCode(qrDisplay, {
                text: url,
                width: 220,
                height: 220,
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            // Fallback: request SVG from backend API
            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ action: 'qrcode', url: url }),
                credentials: 'same-origin'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.success && data.qr_svg) {
                    qrDisplay.innerHTML = data.qr_svg;
                }
            })
            .catch(function() {});
        }
    }

    function handleModalEscape(e) {
        if (e.key === 'Escape') {
            closeQrModal();
        }
    }

    function openQrModal() {
        const url = (urlInput ? urlInput.value.trim() : '');
        if (!url) return;
        if (modalUrlInput) modalUrlInput.value = url;
        if (qrDisplay && (!qrDisplay.innerHTML.trim() || qrDisplay.innerHTML.indexOf('<svg') === -1)) {
            renderQrCode(url);
        }
        if (modal) {
            modal.classList.remove('u-hidden');
            if (modalCloseBtn) modalCloseBtn.focus();
            document.addEventListener('keydown', handleModalEscape);
        }
    }

    function closeQrModal() {
        if (modal) {
            modal.classList.add('u-hidden');
            document.removeEventListener('keydown', handleModalEscape);
            if (showQrBtn) showQrBtn.focus();
        }
    }

    if (showQrBtn) {
        showQrBtn.addEventListener('click', openQrModal);
    }
    if (modalCloseBtn) {
        modalCloseBtn.addEventListener('click', closeQrModal);
    }
    if (modalFooterCloseBtn) {
        modalFooterCloseBtn.addEventListener('click', closeQrModal);
    }
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeQrModal();
            }
        });
    }

    function setBadge(state, text) {
        badge.className = '';
        if (state === 'online') {
            badge.className = 'tag-success';
            badge.textContent = text || 'Online';
        } else if (state === 'starting') {
            badge.className = 'tag-warning';
            badge.textContent = text || 'Connecting...';
        } else {
            badge.className = 'tag-danger';
            badge.textContent = text || 'Offline';
        }
    }

    function setFeedback(msg, isError) {
        if (!feedback) return;
        feedback.textContent = msg || '';
        feedback.style.color = isError ? 'var(--danger, #dc2626)' : 'var(--muted, #64748b)';
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        pollCount = 0;
    }

    function handleOnlinePayload(data) {
        setBadge('online');
        if (urlInput) urlInput.value = data.url;
        if (modalUrlInput) modalUrlInput.value = data.url;
        if (urlContainer) urlContainer.classList.remove('u-hidden');
        if (toggle) {
            toggle.checked = true;
            toggle.disabled = false;
        }
        if (data.qr_svg && qrDisplay) {
            qrDisplay.innerHTML = data.qr_svg;
        } else if (data.url) {
            renderQrCode(data.url);
        }
        setFeedback('Remote access tunnel is active and ready.');
    }

    function pollStatus() {
        stopPolling();
        pollCount = 0;
        pollTimer = setInterval(function() {
            pollCount++;
            if (pollCount > 15) {
                stopPolling();
                setFeedback('Connecting is taking longer than expected. Check the tunnel log.', true);
                return;
            }
            fetch(apiUrl, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.success) return;
                    if (data.status === 'online' && data.url) {
                        stopPolling();
                        handleOnlinePayload(data);
                    } else if (!data.online) {
                        stopPolling();
                        setBadge('offline');
                        if (toggle) {
                            toggle.checked = false;
                            toggle.disabled = false;
                        }
                        if (urlContainer) urlContainer.classList.add('u-hidden');
                        closeQrModal();
                        setFeedback('Tunnel terminated unexpectedly.', true);
                    }
                })
                .catch(function() {
                    // Ignore transient network errors during poll
                });
        }, 1500);
    }

    if (toggle) {
        toggle.addEventListener('change', function() {
            const shouldStart = toggle.checked;
            toggle.disabled = true;
            stopPolling();

            if (shouldStart) {
                setBadge('starting');
                setFeedback('Starting portable tunnel daemon...');
                urlContainer.classList.remove('u-hidden');
                urlInput.value = '';
                urlInput.placeholder = 'Extracting dynamic public URL...';

                fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ action: 'start' }),
                    credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    toggle.disabled = false;
                    if (data && data.success) {
                        if (data.status === 'online' && data.url) {
                            handleOnlinePayload(data);
                        } else {
                            setBadge('starting');
                            pollStatus();
                        }
                    } else {
                        toggle.checked = false;
                        setBadge('offline');
                        urlContainer.classList.add('u-hidden');
                        closeQrModal();
                        setFeedback(data && data.error ? data.error : 'Failed to start tunnel daemon.', true);
                    }
                })
                .catch(function(err) {
                    toggle.disabled = false;
                    toggle.checked = false;
                    setBadge('offline');
                    urlContainer.classList.add('u-hidden');
                    closeQrModal();
                    setFeedback('Network error while connecting to remote access service.', true);
                });
            } else {
                setBadge('starting', 'Stopping...');
                setFeedback('Stopping tunnel daemon...');

                fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ action: 'stop' }),
                    credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    toggle.disabled = false;
                    setBadge('offline');
                    urlContainer.classList.add('u-hidden');
                    urlInput.value = '';
                    closeQrModal();
                    setFeedback('Remote access tunnel stopped.');
                })
                .catch(function() {
                    toggle.disabled = false;
                    setBadge('offline');
                    urlContainer.classList.add('u-hidden');
                    closeQrModal();
                    setFeedback('Remote access tunnel stopped.');
                });
            }
        });
    }

    function bindClipboardCopy(buttonEl, labelEl, sourceInput) {
        if (!buttonEl) return;
        buttonEl.addEventListener('click', function() {
            const url = sourceInput ? sourceInput.value.trim() : '';
            if (!url) return;

            function onCopied() {
                const prev = labelEl ? labelEl.textContent : 'Copy URL';
                if (labelEl) labelEl.textContent = '✓ Copied!';
                buttonEl.classList.add('btn-primary');
                buttonEl.classList.remove('btn-secondary');
                setTimeout(function() {
                    if (labelEl) labelEl.textContent = prev;
                    buttonEl.classList.remove('btn-primary');
                    buttonEl.classList.add('btn-secondary');
                }, 2000);
            }

            function fallbackCopy() {
                if (sourceInput) sourceInput.select();
                try {
                    document.execCommand('copy');
                    onCopied();
                } catch (e) {
                    setFeedback('Unable to copy automatically. Please copy the URL manually.', true);
                }
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(onCopied).catch(fallbackCopy);
            } else {
                fallbackCopy();
            }
        });
    }

    bindClipboardCopy(copyBtn, copyText, urlInput);
    bindClipboardCopy(modalCopyBtn, modalCopyText, modalUrlInput);
})();
</script>
</body>
</html>
