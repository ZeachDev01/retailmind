<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
require_once __DIR__ . '/../../../backend/app/Services/CloudflareTunnelService.php';
require_once __DIR__ . '/../../../backend/app/Services/TunnelSetupBlockBuilder.php';

$message = '';
$messageClass = '';
$generatedTunnelBlock = '';
$generatedTunnelBlockType = '';
$dormancyPolicyService = new \App\Authorization\DormancyPolicyService($pdo);
$appRoot = (string)(realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
$localAppPort = 80;
$appUrlPort = parse_url((string)env('APP_URL', ''), PHP_URL_PORT);
if (is_int($appUrlPort) && $appUrlPort > 0) {
    $localAppPort = $appUrlPort;
} elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] > 0) {
    $localAppPort = (int)$_SERVER['SERVER_PORT'];
}

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
            'generate_tunnel_setup_block', 'generate_tunnel_removal_block',
        ], true)) {
            require_local_request();

            if ($action === 'save_brevo' || $action === 'test_brevo') {
                $senderEmail = trim((string)($_POST['brevo_sender_email'] ?? ''));
                $apiKeyInput = trim((string)($_POST['brevo_api_key'] ?? ''));
                $savedSettings = get_store_settings($pdo);
                $savedKey = (string)($savedSettings['brevo_api_key'] ?? '');
                $apiKey = $apiKeyInput !== ''
                    ? $apiKeyInput
                    : ($savedKey !== '' ? decrypt_store_email_secret($savedKey) : '');
                // Real Brevo keys never contain whitespace; this catches pasted prose before it overwrites a working key.
                $testRecipient = trim((string)($_POST['test_recipient'] ?? ''));
                if (filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false || $apiKey === '' || strlen($apiKey) > 512 || preg_match('/\s/', $apiKey) === 1) {
                    throw new InvalidArgumentException('Enter a Brevo API key and a valid verified sender email address.');
                }
                if ($action === 'test_brevo' && ($testRecipient === '' || filter_var($testRecipient, FILTER_VALIDATE_EMAIL) === false)) {
                    throw new InvalidArgumentException('Enter a valid test recipient email address.');
                }
                $previousSettings = [
                    'brevo_api_key' => $savedKey,
                    'brevo_sender_email' => (string)($savedSettings['brevo_sender_email'] ?? ''),
                    'store_email_alerts_enabled' => (string)($savedSettings['store_email_alerts_enabled'] ?? '0'),
                ];
                $saveBrevo = static function (array $updates) use ($pdo): void {
                    \App\Store\StoreWriteGate::run($pdo, function () use ($pdo, $updates): void {
                        save_key_value_settings($pdo, 'store_settings', $updates, (int)$_SESSION['user_id']);
                        $pdo->exec("DELETE FROM store_settings WHERE setting_key IN (
                            'smtp_provider', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
                            'smtp_from_email', 'smtp_password', 'smtp_email_enabled'
                        )");
                    });
                };
                $brevoUpdates = [
                    'brevo_api_key' => $apiKeyInput !== ''
                        ? encrypt_store_email_secret($apiKeyInput)
                        : $savedKey,
                    'brevo_sender_email' => $senderEmail,
                    'store_email_alerts_enabled' => '0',
                ];
                if ($action === 'test_brevo') {
                    // Keep the typed test address so a failed attempt does not make the user retype it.
                    $brevoUpdates['brevo_test_recipient'] = $testRecipient;
                }
                $saveBrevo($brevoUpdates);

                if ($action === 'save_brevo') {
                    log_activity($pdo, (int)$_SESSION['user_id'], 'Brevo email settings updated', 'Platform Settings', null, null, [
                        'brevo_sender_email' => $senderEmail,
                    ]);
                    $message = 'Brevo settings saved. Send a test email to enable Store email alerts.';
                    $messageClass = 'tag-success';
                } else {
                    $recipient = $testRecipient;
                    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                        $message = 'Brevo settings saved. Enter a valid test recipient email address, then try again.';
                        $messageClass = 'tag-warning';
                    } elseif (send_email_notification($recipient, 'RetailMind email test', 'Your Brevo API settings are working. Store email alerts are now enabled.')) {
                        $saveBrevo(['store_email_alerts_enabled' => '1']);
                        log_activity($pdo, (int)$_SESSION['user_id'], 'Brevo email settings updated', 'Platform Settings', null, null, [
                            'brevo_sender_email' => $senderEmail,
                        ]);
                        $message = 'Test email sent to ' . $recipient . '. Store email alerts are enabled.';
                        $messageClass = 'tag-success';
                    } else {
                        // A failed test must not replace a working key with the rejected one.
                        $saveBrevo($previousSettings);
                        $detail = '';
                        try {
                            $stmt = $pdo->query("SELECT error_message FROM email_delivery_log WHERE subject = 'RetailMind email test' ORDER BY email_log_id DESC LIMIT 1");
                            $detail = trim((string)$stmt->fetchColumn());
                        } catch (Throwable) {
                        }
                        $message = 'Test email failed' . ($detail !== '' ? ': ' . $detail : '')
                            . '. Previous Brevo settings were kept. Check the API key and verified sender, then try again.';
                        $messageClass = 'tag-warning';
                    }
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
            } elseif ($action === 'generate_tunnel_setup_block' || $action === 'generate_tunnel_removal_block') {
                $tunnelServiceForBlock = new \App\Services\CloudflareTunnelService();
                $savedToken = $tunnelServiceForBlock->getToken();
                $builder = new \App\Services\TunnelSetupBlockBuilder();
                $tunnelMode = $builder->mode($savedToken);
                $blockInput = [
                    'app_root' => $appRoot,
                    'local_port' => $localAppPort,
                    'token' => $savedToken,
                    'hostname' => $tunnelServiceForBlock->getHostname(),
                    'mode' => $tunnelMode,
                ];

                if ($action === 'generate_tunnel_setup_block') {
                    $generatedTunnelBlock = $builder->buildSetupBlock($blockInput);
                    $generatedTunnelBlockType = 'setup';
                    log_activity($pdo, (int)$_SESSION['user_id'], 'Remote Access setup block generated', 'Platform Settings', null, null, null, null, null, [
                        'mode' => $tunnelMode,
                    ]);
                    $message = $tunnelMode === 'permanent'
                        ? 'Store PC setup block generated for the permanent named tunnel.'
                        : 'Interim Quick Tunnel setup block generated. The public URL will change on restart.';
                } else {
                    $generatedTunnelBlock = $builder->buildRemovalBlock([
                        'app_root' => $appRoot,
                        'local_port' => $localAppPort,
                    ]);
                    $generatedTunnelBlockType = 'removal';
                    log_activity($pdo, (int)$_SESSION['user_id'], 'Remote Access removal block generated', 'Platform Settings', null, null, null, null, null, [
                        'mode' => $tunnelMode,
                    ]);
                    $message = 'Remote Access removal block generated.';
                }
                $messageClass = 'tag-success';
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
$brevoTestRecipient = trim((string)($settings['brevo_test_recipient'] ?? ''));
if (filter_var($brevoTestRecipient, FILTER_VALIDATE_EMAIL) === false) {
    $brevoTestRecipient = trim((string)($settings['store_email'] ?? ''));
}
if (filter_var($brevoTestRecipient, FILTER_VALIDATE_EMAIL) === false) {
    $brevoTestRecipient = '';
}
$tunnelService = new \App\Services\CloudflareTunnelService();
$tunnelStatus = $tunnelService->getStatus();
$tunnelNotifyEnabled = ($settings['tunnel_url_notify_enabled'] ?? '1') !== '0';
$emailAlertsEnabled = !empty($settings['store_email_alerts_enabled']);
$remoteModeLabel = !empty($tunnelStatus['token_configured'])
    ? 'Custom domain'
    : (!empty($tunnelStatus['url']) ? 'Quick tunnel' : 'Offline');
$remoteModeClass = !empty($tunnelStatus['token_configured'])
    ? 'is-info'
    : (!empty($tunnelStatus['url']) ? 'is-on' : 'is-off');
$remoteModeIcon = !empty($tunnelStatus['token_configured'])
    ? 'bi-globe2'
    : (!empty($tunnelStatus['url']) ? 'bi-lightning-charge-fill' : 'bi-cloud-slash');
$styleVersion = @filemtime(__DIR__ . '/../../assets/css/style.css') ?: 1;
$platformSettingsStyleVersion = @filemtime(__DIR__ . '/../../assets/css/platform-settings.css') ?: 1;
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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/platform-settings.css') . '?v=' . $platformSettingsStyleVersion) ?>">
</head>
<body class="platform-settings-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
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
                <h2 id="heading-dormancy"><span class="settings-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span> Dormancy Policy</h2>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_dormancy">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="disable_days">
                            Disable after (days)
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
                            Warn after (days)
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
                               aria-required="true"
                               required>
                    </div>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check" aria-hidden="true"></i> Save policy</button>
                </div>
            </form>
        </section>

        <section class="dashboard-section" aria-labelledby="email-heading">
            <div class="section-header">
                <h2 id="email-heading"><span class="settings-icon"><i class="bi bi-envelope-paper" aria-hidden="true"></i></span> Email (Brevo)</h2>
                <span class="platform-settings-pill <?= $brevoConfigured ? 'is-on' : 'is-off' ?>"><?= $brevoConfigured ? 'Configured' : 'Not configured' ?></span>
            </div>
            <form method="post" id="platform-brevo-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_brevo">
                <div class="form-grid settings-email-grid">
                    <div class="form-group">
                        <label for="brevo_api_key">API key</label>
                        <input id="brevo_api_key" name="brevo_api_key" type="password" autocomplete="new-password" placeholder="<?= $brevoConfigured ? 'Saved — leave blank to keep' : 'Enter Brevo API key' ?>">
                    </div>
                    <div class="form-group">
                        <label for="brevo_sender_email">Verified sender</label>
                        <input id="brevo_sender_email" name="brevo_sender_email" type="email" autocomplete="email" spellcheck="false" value="<?= htmlspecialchars((string)($settings['brevo_sender_email'] ?? '')) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="brevo_test_recipient">Test recipient</label>
                        <input id="brevo_test_recipient" name="test_recipient" type="email" autocomplete="email" spellcheck="false" value="<?= htmlspecialchars($brevoTestRecipient) ?>" placeholder="you@example.com">
                    </div>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" name="action" value="save_brevo" class="btn btn-primary">Save settings</button>
                    <button type="submit" name="action" value="test_brevo" class="btn btn-secondary">Save &amp; test</button>
                </div>
            </form>
            <form method="post" class="settings-status-row">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="disable_brevo">
                <span class="platform-settings-pill <?= $emailAlertsEnabled ? 'is-on' : 'is-off' ?>"><i class="bi <?= $emailAlertsEnabled ? 'bi-bell-fill' : 'bi-bell-slash' ?>" aria-hidden="true"></i> Alerts <?= $emailAlertsEnabled ? 'on' : 'off' ?></span>
                <button type="submit" class="btn btn-secondary"<?= $emailAlertsEnabled ? '' : ' disabled' ?>>Disable alerts</button>
            </form>
        </section>

        <section class="dashboard-section" aria-labelledby="tunnel-heading">
            <div class="section-header">
                <h2 id="tunnel-heading"><span class="settings-icon"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i></span> Remote Access</h2>
                <span class="platform-settings-pill <?= $remoteModeClass ?>"><i class="bi <?= $remoteModeIcon ?>" aria-hidden="true"></i> <?= $remoteModeLabel ?></span>
            </div>

            <div class="settings-meta">
                <div class="settings-meta-item">
                    <span class="settings-meta-label">Public URL</span>
                    <span class="settings-meta-value"><?= !empty($tunnelStatus['url']) ? htmlspecialchars((string)$tunnelStatus['url']) : 'Tunnel is offline' ?></span>
                </div>
            </div>

            <form method="post">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="tunnel_token">Permanent token</label>
                        <input type="password" id="tunnel_token" name="tunnel_token" autocomplete="off"
                               value="<?= !empty($tunnelStatus['masked_token']) ? htmlspecialchars((string)$tunnelStatus['masked_token']) : '' ?>"
                               placeholder="eyJh… from Cloudflare Zero Trust">
                    </div>
                    <div class="form-group">
                        <label for="tunnel_hostname">Custom hostname (optional)</label>
                        <input type="text" id="tunnel_hostname" name="tunnel_hostname" autocomplete="off"
                               value="<?= htmlspecialchars((string)($tunnelStatus['hostname'] ?? '')) ?>"
                               placeholder="supervisor.mystore.com">
                    </div>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" name="action" value="save_tunnel_token" class="btn btn-primary">Save token</button>
                    <?php if (!empty($tunnelStatus['token_configured'])): ?>
                        <button type="submit" name="action" value="clear_tunnel_token" class="btn btn-secondary">Clear token</button>
                    <?php endif; ?>
                </div>
            </form>

            <form method="post" class="u-mt-2">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="tunnel_url_notify_enabled" class="settings-check">
                        <input type="checkbox" id="tunnel_url_notify_enabled" name="tunnel_url_notify_enabled" value="1"<?= $tunnelNotifyEnabled ? ' checked' : '' ?>>
                        <span>Email admins when the URL changes</span>
                    </label>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" name="action" value="save_tunnel_notify" class="btn btn-primary">Save</button>
                    <button type="submit" name="action" value="test_tunnel_notify" class="btn btn-secondary">Send test</button>
                </div>
            </form>

            <div class="tunnel-setup">
                <h3 class="tunnel-setup-heading">Cloudflare dashboard checklist</h3>
                <ol class="tunnel-checklist">
                    <li>Create a tunnel in Cloudflare Zero Trust &rarr; Networks &rarr; Tunnels.</li>
                    <li>Add a public hostname that points to <code>http://127.0.0.1:<?= (int)$localAppPort ?></code>. This needs a domain you own.</li>
                    <li>Create an owner-only Cloudflare Access policy for that hostname.</li>
                    <li>Copy the tunnel token, save it above, then generate the Store PC setup block.</li>
                </ol>

                <?php if (!empty($tunnelStatus['token_configured'])): ?>
                    <p class="tunnel-setup-note">Permanent mode uses the saved token and hostname. Re-run the block to repair the service or apply a changed token.</p>
                    <?php if (empty($tunnelStatus['hostname'])): ?>
                        <div class="tunnel-setup-warning" role="note">
                            <strong>No custom hostname saved.</strong> The named tunnel can still run, but this card cannot show the public URL until you add one.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="tunnel-setup-warning" role="note">
                        <strong>Interim Quick Tunnel.</strong> No token is saved, so the generated block creates a Quick Tunnel.
                        Its public URL changes every time the service restarts, and Cloudflare Access is not in front of it.
                        RetailMind sign-in and the application read-only boundary still apply. Save a token and hostname, then regenerate to upgrade.
                    </div>
                <?php endif; ?>

                <form method="post" class="u-mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_tunnel_setup_block">
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-magic" aria-hidden="true"></i> Generate Store PC setup block</button>
                    </div>
                </form>

                <form method="post" class="u-mt-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_tunnel_removal_block">
                    <div class="form-actions">
                        <button type="submit" class="btn btn-secondary"><i class="bi bi-trash3" aria-hidden="true"></i> Generate removal block</button>
                    </div>
                </form>

                <?php if ($generatedTunnelBlock !== ''): ?>
                    <div class="tunnel-setup-result">
                        <div class="tunnel-setup-result-heading">
                            <h3 class="tunnel-setup-heading">
                                <?= $generatedTunnelBlockType === 'removal' ? 'Remove Remote Access' : 'Run once in an elevated PowerShell' ?>
                            </h3>
                            <button type="button" class="btn btn-secondary btn-sm" data-copy-target="<?= $generatedTunnelBlockType === 'removal' ? 'tunnel-removal-block' : 'tunnel-setup-block' ?>">Copy block</button>
                        </div>
                        <textarea id="<?= $generatedTunnelBlockType === 'removal' ? 'tunnel-removal-block' : 'tunnel-setup-block' ?>" class="tunnel-setup-block" readonly spellcheck="false" aria-label="<?= $generatedTunnelBlockType === 'removal' ? 'Remote Access removal PowerShell block' : 'Store PC Remote Access setup PowerShell block' ?>"><?= htmlspecialchars($generatedTunnelBlock) ?></textarea>
                    </div>
                <?php endif; ?>
            </div>
        </section>

    </main>
</div>
<script>
(() => {
    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.getElementById(button.getAttribute('data-copy-target') || '');
            if (!target) {
                return;
            }
            const previous = button.textContent;
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(target.value);
                } else {
                    target.focus();
                    target.select();
                    document.execCommand('copy');
                }
                button.textContent = 'Copied!';
                setTimeout(() => { button.textContent = previous; }, 2000);
            } catch (error) {
                target.focus();
                target.select();
            }
        });
    });
})();
</script>
</body>
</html>
