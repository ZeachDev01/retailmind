<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
require_once __DIR__ . '/../../../backend/app/Services/CloudflareTunnelService.php';

$message = '';
$messageClass = '';
$dormancyPolicyService = new \App\Authorization\DormancyPolicyService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'save_dormancy') {
            $updates = [
                'disable_days' => trim((string)($_POST['disable_days'] ?? '')),
                'warn_days' => trim((string)($_POST['warn_days'] ?? '')),
            ];
            $dormancyPolicyService->update((string)current_role(), $updates, (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Dormancy Policy thresholds updated', 'Platform Settings', null, null, $updates);
            $message = 'Dormancy Policy thresholds saved.';
            $messageClass = 'tag-success';
        } elseif (in_array($action, [
            'save_brevo', 'test_brevo', 'disable_brevo',
            'save_tunnel_token', 'clear_tunnel_token', 'save_tunnel_notify', 'test_tunnel_notify',
        ], true)) {
            require_local_request();

            if ($action === 'save_brevo' || $action === 'test_brevo') {
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
                log_activity($pdo, (int)$_SESSION['user_id'], 'Brevo email settings updated', 'Platform Settings', null, null, [
                    'brevo_sender_email' => $senderEmail,
                ]);

                if ($action === 'test_brevo') {
                    $recipient = trim((string)($savedSettings['store_email'] ?? ''));
                    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                        $stmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ?');
                        $stmt->execute([(int)$_SESSION['user_id']]);
                        $recipient = trim((string)$stmt->fetchColumn());
                    }
                    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                        $message = 'Brevo settings saved. Add a valid email address, then send a test email.';
                        $messageClass = 'tag-warning';
                    } elseif (send_email_notification($recipient, 'RetailMind email test', 'Your Brevo API settings are working. Store email alerts are now enabled.')) {
                        \App\Store\StoreWriteGate::run($pdo, function () use ($pdo): void {
                            save_key_value_settings($pdo, 'store_settings', ['store_email_alerts_enabled' => '1'], (int)$_SESSION['user_id']);
                        });
                        $message = 'Test email sent. Store email alerts are enabled.';
                        $messageClass = 'tag-success';
                    } else {
                        $message = 'Test email failed. Check the Brevo API key and verified sender, then try again. Email alerts remain disabled.';
                        $messageClass = 'tag-warning';
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
            } elseif ($action === 'save_tunnel_token') {
                $tunnelToken = trim((string)($_POST['tunnel_token'] ?? ''));
                $tunnelHostname = trim((string)($_POST['tunnel_hostname'] ?? ''));
                $ts = new \App\Services\CloudflareTunnelService();
                // The masked placeholder is pre-filled for display only; resubmitting it must keep the saved token.
                if (str_contains($tunnelToken, '••••')) {
                    $tunnelToken = (string)($ts->getToken() ?? '');
                }
                if ($tunnelToken === '') {
                    $ts->clearToken();
                    $message = 'Permanent Cloudflare Tunnel token cleared. Ephemeral quick tunnel fallback enabled.';
                } else {
                    $ts->setToken($tunnelToken, $tunnelHostname !== '' ? $tunnelHostname : null);
                    log_activity($pdo, (int)$_SESSION['user_id'], 'Cloudflare Tunnel token updated', 'Platform Settings', null, null, [
                        'hostname' => $tunnelHostname,
                    ]);
                    $message = 'Permanent Cloudflare Tunnel token configured.';
                }
            } elseif ($action === 'clear_tunnel_token') {
                (new \App\Services\CloudflareTunnelService())->clearToken();
                log_activity($pdo, (int)$_SESSION['user_id'], 'Cloudflare Tunnel token cleared', 'Platform Settings');
                $message = 'Permanent Cloudflare Tunnel token cleared. Ephemeral quick tunnel fallback enabled.';
            } elseif ($action === 'save_tunnel_notify') {
                $enabled = !empty($_POST['tunnel_url_notify_enabled']) ? '1' : '0';
                \App\Store\StoreWriteGate::run($pdo, function () use ($pdo, $enabled): void {
                    save_key_value_settings($pdo, 'store_settings', ['tunnel_url_notify_enabled' => $enabled], (int)$_SESSION['user_id']);
                });
                $message = $enabled === '1'
                    ? 'Remote access URL change notifications enabled.'
                    : 'Remote access URL change notifications disabled.';
            } elseif ($action === 'test_tunnel_notify') {
                $recipients = tunnel_notify_recipients($pdo);
                $storeName = trim((string)(get_store_settings($pdo)['store_name'] ?? 'RetailMind'));
                $sent = 0;
                foreach ($recipients as $recipient) {
                    if (send_email_notification($recipient, $storeName . ' remote access test', 'This is a test of remote access URL change notifications.')) {
                        $sent++;
                    }
                }
                if ($sent > 0) {
                    $message = 'Test notification sent to ' . $sent . ' recipient(s).';
                    $messageClass = 'tag-success';
                } else {
                    $message = 'Test notification failed. Check the Brevo API key and verified sender, then try again.';
                    $messageClass = 'tag-warning';
                }
            }
        } else {
            $message = 'Choose a platform setting to save. Store and receipt settings are managed in the Administrator Workspace.';
            $messageClass = 'tag-danger';
        }
        if ($messageClass === '') {
            $messageClass = 'tag-success';
        }
    } catch (Throwable $exception) {
        $message = \App\Support\OperatorAlert::message($exception, 'The platform setting could not be saved. Check the values and try again. Tell your Super Administrator if this keeps happening.');
        $messageClass = 'tag-danger';
    }
}

$dormancyThresholds = $dormancyPolicyService->thresholds();
$settings = get_store_settings($pdo);
$brevoConfigured = !empty($settings['brevo_api_key']);
$tunnelService = new \App\Services\CloudflareTunnelService();
$tunnelStatus = $tunnelService->getStatus();
$tunnelNotifyEnabled = ($settings['tunnel_url_notify_enabled'] ?? '1') !== '0';
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
                               inputmode="numeric"
                               value="<?= htmlspecialchars((string)$dormancyThresholds['disable_days']) ?>"
                               autocomplete="off"
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
                               inputmode="numeric"
                               value="<?= htmlspecialchars((string)$dormancyThresholds['warn_days']) ?>"
                               autocomplete="off"
                               aria-describedby="warn_days_help"
                               aria-required="true"
                               required>
                    </div>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check" aria-hidden="true"></i> Save Dormancy Policy</button>
                </div>
            </form>
        </section>

        <section class="dashboard-section" aria-labelledby="email-heading">
            <div class="section-header">
                <div>
                    <h2 id="email-heading"><i class="bi bi-envelope-paper" aria-hidden="true"></i> Email (Brevo)</h2>
                    <p class="section-description">Brevo sends Store alerts and other application emails. Enter a Brevo API key and a verified sender address. The API key is encrypted at rest and never displayed.</p>
                </div>
            </div>
            <form method="post" id="platform-brevo-form">
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
                        <small class="field-help">Must be verified in your Brevo account. To send without whitelisting the server IP, keep Brevo's "Blocking unauthorized IP addresses" deactivated (Account &rarr; Security &rarr; Authorized IPs).</small>
                    </div>
                </div>
                <div class="form-actions u-mt-1">
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

        <section class="dashboard-section" aria-labelledby="tunnel-heading">
            <div class="section-header">
                <div>
                    <h2 id="tunnel-heading"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Cloudflare Remote Access</h2>
                    <p class="section-description">Configure the permanent tunnel token and supervisor URL-change notifications. The tunnel start/stop switch and public URL stay in the Administrator Workspace.</p>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Mode</label>
                    <p class="field-help"><?= !empty($tunnelStatus['token_configured']) ? 'Custom domain (permanent token configured)' : 'Quick Tunnel (ephemeral domain)' ?></p>
                </div>
                <div class="form-group">
                    <label>Public URL</label>
                    <p class="field-help"><?= !empty($tunnelStatus['url']) ? htmlspecialchars((string)$tunnelStatus['url']) : 'Tunnel is offline' ?></p>
                </div>
            </div>

            <form method="post">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="tunnel_token">Permanent Token</label>
                        <input type="password" id="tunnel_token" name="tunnel_token" autocomplete="off"
                               value="<?= !empty($tunnelStatus['masked_token']) ? htmlspecialchars((string)$tunnelStatus['masked_token']) : '' ?>"
                               placeholder="eyJh… from Cloudflare Zero Trust">
                        <small class="field-help">Paste a Cloudflare Tunnel token for a fixed domain. Leave blank and save to clear it and fall back to a Quick Tunnel.</small>
                    </div>
                    <div class="form-group">
                        <label for="tunnel_hostname">Custom Hostname (Optional)</label>
                        <input type="text" id="tunnel_hostname" name="tunnel_hostname" autocomplete="off"
                               value="<?= htmlspecialchars((string)($tunnelStatus['hostname'] ?? '')) ?>"
                               placeholder="supervisor.mystore.com">
                    </div>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" name="action" value="save_tunnel_token" class="btn btn-primary">Save Token</button>
                    <?php if (!empty($tunnelStatus['token_configured'])): ?>
                        <button type="submit" name="action" value="clear_tunnel_token" class="btn btn-secondary">Clear Token</button>
                    <?php endif; ?>
                </div>
            </form>

            <form method="post" class="u-mt-2">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="tunnel_url_notify_enabled">
                        <input type="checkbox" id="tunnel_url_notify_enabled" name="tunnel_url_notify_enabled" value="1"<?= $tunnelNotifyEnabled ? ' checked' : '' ?>>
                        Email admins when the remote URL changes
                    </label>
                    <small class="field-help">Sends the new public URL to active Admin and Super Admin users whenever a Quick Tunnel domain rotates.</small>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" name="action" value="save_tunnel_notify" class="btn btn-primary">Save Notification Setting</button>
                    <button type="submit" name="action" value="test_tunnel_notify" class="btn btn-secondary">Send Test Notification</button>
                </div>
            </form>
        </section>

    </main>
</div>
</body>
</html>
