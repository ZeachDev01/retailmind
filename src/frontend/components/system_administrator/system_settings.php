<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
require_once __DIR__ . '/../../../backend/app/Services/CloudflareTunnelService.php';
require_once __DIR__ . '/../../../backend/app/Services/TunnelSetupBlockBuilder.php';
header('Cache-Control: no-store, no-cache, must-revalidate');

$message = '';
$messageClass = '';
$generatedTunnelBlock = '';
$generatedTunnelBlockType = '';
$dormancyPolicyService = new \App\Authorization\DormancyPolicyService($pdo);
$scheduledBackupSettings = new \App\Backup\ScheduledBackupSettings($pdo);
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
        } elseif ($action === 'save_backup_schedule') {
            $updates = [
                'weekday' => trim((string)($_POST['backup_schedule_weekday'] ?? '')),
                'time' => trim((string)($_POST['backup_schedule_time'] ?? '')),
                'destination' => trim((string)($_POST['backup_destination_path'] ?? '')),
            ];
            $scheduledBackupSettings->update((string)current_role(), $updates, (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Scheduled backup settings updated', 'Platform Settings', null, null, $updates);
            $message = 'Scheduled Backup settings saved. The next run uses the new schedule.';
            $messageClass = 'tag-success';
        } elseif (in_array($action, [
            'save_brevo', 'test_brevo', 'disable_brevo',
            'save_tunnel_token', 'clear_tunnel_token', 'save_tunnel_notify', 'test_tunnel_notify',
            'generate_tunnel_setup_block', 'generate_tunnel_removal_block',
            'test_tunnel_connection',
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
                    if (!$ts->clearToken()) { throw new RuntimeException('Could not clear the saved tunnel token.'); }
                    $message = 'Saved Cloudflare token cleared. The active connection is unchanged.';
                } else {
                    if (!$ts->setToken($tunnelToken, $tunnelHostname)) { throw new RuntimeException('Could not save the tunnel token.'); }
                    log_activity($pdo, (int)$_SESSION['user_id'], 'Cloudflare Tunnel token updated', 'Platform Settings', null, null, [
                        'hostname' => $tunnelHostname,
                    ]);
                    $message = 'Cloudflare settings saved. Generate and run the custom domain setup block to apply them.';
                }
            } elseif ($action === 'clear_tunnel_token') {
                if (!(new \App\Services\CloudflareTunnelService())->clearToken()) { throw new RuntimeException('Could not clear the saved tunnel token.'); }
                log_activity($pdo, (int)$_SESSION['user_id'], 'Cloudflare Tunnel token cleared', 'Platform Settings');
                $message = 'Saved Cloudflare token cleared. The active connection is unchanged.';
            } elseif ($action === 'test_tunnel_connection') {
                $tested = (new \App\Services\CloudflareTunnelService())->getStatus(true);
                $message = !empty($tested['online'])
                    ? 'Public RetailMind connection verified.'
                    : 'Public connection could not be verified. Check the setup script output, internet, domain/DNS, and provider quota.';
                $messageClass = !empty($tested['online']) ? 'tag-success' : 'tag-warning';
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
                $requestedMode = (string)($_POST['setup_mode'] ?? ($savedToken ? 'permanent' : 'ngrok'));
                if (!in_array($requestedMode, ['ngrok', 'permanent', 'quick'], true)) {
                    throw new InvalidArgumentException('Choose a supported Remote Access setup.');
                }
                $tunnelMode = $builder->mode($savedToken, $requestedMode);
                if ($requestedMode === 'permanent' && (!$savedToken || !$tunnelServiceForBlock->getHostname())) {
                    throw new InvalidArgumentException('Save the Cloudflare token and custom hostname first.');
                }
                $blockInput = [
                    'app_root' => $appRoot,
                    'local_port' => $localAppPort,
                    'token' => $tunnelMode === 'ngrok' ? trim((string)($_POST['ngrok_token'] ?? '')) : $savedToken,
                    'hostname' => $tunnelMode === 'ngrok' ? strtolower(trim((string)($_POST['ngrok_hostname'] ?? ''))) : $tunnelServiceForBlock->getHostname(),
                    'mode' => $tunnelMode,
                    'health_path' => app_url('remote_health.php'),
                ];

                if ($action === 'generate_tunnel_setup_block') {
                    $generatedTunnelBlock = $builder->buildSetupBlock($blockInput);
                    $generatedTunnelBlockType = 'setup';
                    log_activity($pdo, (int)$_SESSION['user_id'], 'Remote Access setup block generated', 'Platform Settings', null, null, null, null, null, [
                        'mode' => $tunnelMode,
                    ]);
                    $message = $tunnelMode === 'ngrok'
                        ? 'Free ngrok setup block generated. Copy it into PowerShell as Administrator on this Store PC.'
                        : ($tunnelMode === 'permanent'
                        ? 'Store PC setup block generated for the permanent named tunnel.'
                        : 'Interim Quick Tunnel setup block generated. The public URL will change on restart.');
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
$backupSchedule = $scheduledBackupSettings->get();
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
$remoteTunnelState = (string)($tunnelStatus['status'] ?? 'offline');
$remoteModeLabel = $remoteTunnelState === 'online' ? 'Online' : ($remoteTunnelState === 'starting' ? 'Connecting' : 'Offline');
$remoteProviderLabel = ['ngrok' => 'ngrok Free', 'permanent' => 'Cloudflare', 'quick' => 'Cloudflare Quick Tunnel'][$tunnelStatus['mode'] ?? 'quick'] ?? 'Not configured';
if (empty($tunnelStatus['installed'])) { $remoteProviderLabel = 'Not set up'; }
$remotePublicUrl = (string)($tunnelStatus['configured_url'] ?? $tunnelStatus['url'] ?? '');
$remoteSetupProvider = (string)($_POST['setup_mode'] ?? (in_array($_POST['action'] ?? '', ['save_tunnel_token', 'clear_tunnel_token'], true) ? 'permanent' : ($_GET['provider'] ?? (($tunnelStatus['mode'] ?? '') === 'permanent' ? 'permanent' : 'ngrok'))));
if (!in_array($remoteSetupProvider, ['ngrok', 'permanent'], true)) { $remoteSetupProvider = 'ngrok'; }
$ngrokHostname = ($tunnelStatus['mode'] ?? '') === 'ngrok' ? (string)parse_url($remotePublicUrl, PHP_URL_HOST) : '';
$remoteModeClass = $remoteTunnelState === 'online' ? 'is-on' : ($remoteTunnelState === 'starting' ? 'is-info' : 'is-off');
$remoteModeIcon = $remoteTunnelState === 'online' ? 'bi-globe2' : ($remoteTunnelState === 'starting' ? 'bi-arrow-repeat' : 'bi-cloud-slash');
$styleVersion = @filemtime(__DIR__ . '/../../assets/css/style.css') ?: 1;
$platformSettingsStyleVersion = @filemtime(__DIR__ . '/../../assets/css/platform-settings.css') ?: 1;
$storeSettingsStyleVersion = @filemtime(__DIR__ . '/../../assets/css/store-settings.css') ?: 1;
$platformSections = [
    'dormancy-policy' => ['Dormancy Policy', 'bi-shield-lock'],
    'scheduled-backup' => ['Scheduled Backup', 'bi-calendar2-week'],
    'email' => ['Email (Brevo)', 'bi-envelope-paper'],
    'remote-access' => ['Remote Access', 'bi-cloud-arrow-up'],
];
$activePlatformSection = (string)($_GET['section'] ?? 'dormancy-policy');
if (!isset($platformSections[$activePlatformSection])) $activePlatformSection = 'dormancy-policy';
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
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/store-settings.css') . '?v=' . $storeSettingsStyleVersion) ?>">
</head>
<body class="platform-settings-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <nav class="platform-settings-nav" aria-label="Platform settings sections">
            <?php foreach ($platformSections as $section => [$label, $icon]): ?>
                <a href="?section=<?= htmlspecialchars($section) ?>"<?= $activePlatformSection === $section ? ' class="active" aria-current="page"' : '' ?>>
                    <i class="bi <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="platform-settings-content">
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

        <section class="dashboard-section" data-platform-section="dormancy-policy" aria-labelledby="heading-dormancy"<?= $activePlatformSection !== 'dormancy-policy' ? ' hidden' : '' ?>>
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

        <section class="dashboard-section" data-platform-section="scheduled-backup" aria-labelledby="heading-backup-schedule"<?= $activePlatformSection !== 'scheduled-backup' ? ' hidden' : '' ?>>
            <div class="section-header">
                <h2 id="heading-backup-schedule"><span class="settings-icon"><i class="bi bi-calendar2-week" aria-hidden="true"></i></span> Scheduled Backup</h2>
            </div>
            <div class="settings-meta">
                <div class="settings-meta-item">
                    <span class="settings-meta-label">Next scheduled run</span>
                    <span class="settings-meta-value"><?= htmlspecialchars(format_display_datetime((string)$backupSchedule['next_run_at'])) ?></span>
                </div>
                <div class="settings-meta-item">
                    <span class="settings-meta-label">Last successful run</span>
                    <span class="settings-meta-value"><?= $backupSchedule['last_success_at'] !== null ? htmlspecialchars(format_display_datetime((string)$backupSchedule['last_success_at'])) : 'None yet' ?></span>
                </div>
                <div class="settings-meta-item">
                    <span class="settings-meta-label">Last outcome</span>
                    <span class="settings-meta-value"><span class="tag <?= htmlspecialchars(\App\Backup\ScheduledBackupSettings::outcomeClass((string)$backupSchedule['last_outcome'])) ?>"><?= htmlspecialchars(\App\Backup\ScheduledBackupSettings::outcomeLabel((string)$backupSchedule['last_outcome'])) ?></span></span>
                </div>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_backup_schedule">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="backup_schedule_weekday">Weekday</label>
                        <select id="backup_schedule_weekday" name="backup_schedule_weekday">
                            <?php foreach (\App\Backup\ScheduledBackupSettings::WEEKDAY_NAMES as $weekdayIndex => $weekdayName): ?>
                                <option value="<?= (int)$weekdayIndex ?>"<?= $backupSchedule['weekday'] === $weekdayIndex ? ' selected' : '' ?>><?= htmlspecialchars($weekdayName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="backup_schedule_time">Local time</label>
                        <input type="time" id="backup_schedule_time" name="backup_schedule_time" value="<?= htmlspecialchars((string)$backupSchedule['time']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="backup_destination_path">Backup Destination</label>
                        <input type="text" id="backup_destination_path" name="backup_destination_path" spellcheck="false" autocomplete="off" value="<?= htmlspecialchars((string)$backupSchedule['destination_configured']) ?>" placeholder="<?= htmlspecialchars((string)$backupSchedule['destination']) ?>">
                        <span class="backup-hint">Use an absolute folder outside the application and web root. Leave blank to use the private recovery store.</span>
                    </div>
                </div>
                <div class="form-actions u-mt-1">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check" aria-hidden="true"></i> Save schedule</button>
                </div>
            </form>
        </section>

        <section class="dashboard-section" data-platform-section="email" aria-labelledby="email-heading"<?= $activePlatformSection !== 'email' ? ' hidden' : '' ?>>
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

        <section class="dashboard-section remote-access-section" data-platform-section="remote-access" aria-labelledby="tunnel-heading"<?= $activePlatformSection !== 'remote-access' ? ' hidden' : '' ?>>
            <?php
            $remoteProviderMarks = [
                'ngrok' => '<svg viewBox="0 6.8 24 12" aria-hidden="true" focusable="false"><path d="M18.951 7.598v6.713h1.463v-1.69l1.61 1.69H24v-.08l-2.123-2.153 2.002-1.846v-.07H21.95l-1.537 1.496v-4.06zm-2.93 2.41a2.626 2.626 0 00-1.787.635 2.049 2.049 0 00-.703 1.556c-.002.75.311 1.287.7 1.643.526.478 1.221.626 1.767.623.666 0 1.34-.195 1.805-.62.521-.483.713-1.029.713-1.607 0-.73-.31-1.247-.71-1.603-.553-.475-1.202-.628-1.785-.627zm-9.062.039c-1.188-.005-2.1.977-2.104 2.25-.004 1.296.908 2.108 2.032 2.096.664.002.983-.244 1.308-.541v1.193h-1.37l-1.03 1.158v.2H9.66v-6.24H8.195v.435c-.381-.408-.772-.542-1.236-.551zm-4.805.11l-.691.786v-.771H0v4.15h1.463v-2.799c.547.002 1.023-.002 1.49-.003v2.802h1.465v-2.595c-.004-.547-.1-.819-.307-1.061a1.431 1.431 0 00-.914-.51zm8.114.005v4.15h1.468l.002-2.779h1.065l1.164-1.314v-.057h-1.598l-.635.715v-.715zm-2.946 1.115c.504 0 .96.444.948.948a.956.956 0 01-.948.945c-.523 0-.931-.403-.947-.945-.002-.52.443-.94.947-.948zm8.703.001c.525 0 .94.434.944.95-.027.544-.42.95-.944.95s-.934-.417-.95-.95a.955.955 0 01.95-.95z"/></svg>',
                'cloudflare' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M16.5088 16.8447c.1475-.5068.0908-.9707-.1553-1.3154-.2246-.3164-.6045-.499-1.0615-.5205l-8.6592-.1123a.1559.1559 0 0 1-.1333-.0713c-.0283-.042-.0351-.0986-.021-.1553.0278-.084.1123-.1484.2036-.1562l8.7359-.1123c1.0351-.0489 2.1601-.8868 2.5537-1.9136l.499-1.3013c.0215-.0561.0293-.1128.0147-.168-.5625-2.5463-2.835-4.4453-5.5499-4.4453-2.5039 0-4.6284 1.6177-5.3876 3.8614-.4927-.3658-1.1187-.5625-1.794-.499-1.2026.119-2.1665 1.083-2.2861 2.2856-.0283.31-.0069.6128.0635.894C1.5683 13.171 0 14.7754 0 16.752c0 .1748.0142.3515.0352.5273.0141.083.0844.1475.1689.1475h15.9814c.0909 0 .1758-.0645.2032-.1553l.12-.4268zm2.7568-5.5634c-.0771 0-.1611 0-.2383.0112-.0566 0-.1054.0415-.127.0976l-.3378 1.1744c-.1475.5068-.0918.9707.1543 1.3164.2256.3164.6055.498 1.0625.5195l1.8437.1133c.0557 0 .1055.0263.1329.0703.0283.043.0351.1074.0214.1562-.0283.084-.1132.1485-.204.1553l-1.921.1123c-1.041.0488-2.1582.8867-2.5527 1.914l-.1406.3585c-.0283.0713.0215.1416.0986.1416h6.5977c.0771 0 .1474-.0489.169-.126.1122-.4082.1757-.837.1757-1.2803 0-2.6025-2.125-4.727-4.7344-4.727"/></svg>',
            ];
            ?>
            <div class="section-header remote-section-header">
                <h2 id="tunnel-heading"><span class="settings-icon"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i></span> Remote Access</h2>
                <span id="remote-mode-pill" class="platform-settings-pill <?= $remoteModeClass ?>">
                    <span class="remote-status-dot" aria-hidden="true"></span>
                    <i id="remote-mode-icon" class="bi <?= $remoteModeIcon ?>" aria-hidden="true"></i>
                    <span id="remote-mode-label"><?= $remoteModeLabel ?></span>
                </span>
            </div>

            <div class="remote-console" id="remote-console">
                <div class="remote-console-head">
                    <div class="remote-identity">
                        <div class="remote-identity-copy">
                            <strong id="remote-active-provider"><?= htmlspecialchars($remoteProviderLabel) ?></strong>
                            <span id="remote-toggle-status-text" class="remote-identity-state"><?= $remoteTunnelState === 'online' ? 'Active &amp; encrypted' : ($remoteTunnelState === 'starting' ? 'Checking the public connection…' : 'Connection is stopped or unavailable') ?></span>
                        </div>
                    </div>
                    <label class="remote-enable-control" for="remote-access-toggle">
                        <span>Enable connection</span>
                        <span class="toggle-switch">
                            <input type="checkbox" id="remote-access-toggle" role="switch" <?= $remoteTunnelState !== 'offline' ? 'checked' : '' ?> aria-checked="<?= $remoteTunnelState !== 'offline' ? 'true' : 'false' ?>" aria-label="Enable connection">
                            <span class="toggle-slider"></span>
                        </span>
                    </label>
                </div>
                <div class="remote-link-block">
                    <div class="remote-link-head">
                        <span class="remote-eyebrow">Public link</span>
                        <div class="remote-link-actions">
                            <button type="button" id="remote-copy-link" class="btn btn-secondary btn-sm" data-copy-target="remote-link-copy-value"<?= $remotePublicUrl === '' ? ' disabled' : '' ?>><i class="bi bi-copy" aria-hidden="true"></i> Copy link</button>
                            <form method="post" class="remote-test-form">
                                <?= csrf_field() ?>
                                <button type="submit" name="action" value="test_tunnel_connection" class="btn btn-secondary btn-sm">Test connection</button>
                            </form>
                        </div>
                    </div>
                    <div class="remote-link-row">
                        <a id="tunnel-public-url" href="<?= $remotePublicUrl !== '' ? htmlspecialchars($remotePublicUrl) : '#' ?>" target="_blank" rel="noopener noreferrer"<?= $remotePublicUrl === '' ? ' hidden' : '' ?>><?= htmlspecialchars($remotePublicUrl) ?></a>
                        <span id="remote-link-empty"<?= $remotePublicUrl !== '' ? ' hidden' : '' ?>>No public link yet.</span>
                        <input type="hidden" id="remote-link-copy-value" value="<?= htmlspecialchars($remotePublicUrl) ?>">
                    </div>
                    <p id="remote-link-note" class="remote-link-note"<?= $remoteTunnelState === 'online' || $remotePublicUrl === '' ? ' hidden' : '' ?>><?= $remoteTunnelState === 'online' ? '' : 'Public access has not been verified.' ?></p>
                </div>
                <div id="remote-access-feedback" class="remote-feedback" role="status" aria-live="polite"></div>
            </div>

            <form method="get" class="remote-provider-tabs" role="group" aria-label="Remote Access provider setup">
                <input type="hidden" name="section" value="remote-access">
                <button type="submit" class="remote-provider-tab btn-quiet" name="provider" value="ngrok" data-remote-provider="ngrok" aria-pressed="<?= $remoteSetupProvider === 'ngrok' ? 'true' : 'false' ?>" aria-controls="remote-ngrok-panel">
                    <span>ngrok Free</span>
                </button>
                <button type="submit" class="remote-provider-tab btn-quiet" name="provider" value="permanent" data-remote-provider="permanent" aria-pressed="<?= $remoteSetupProvider === 'permanent' ? 'true' : 'false' ?>" aria-controls="remote-cloudflare-panel">
                    <span>Cloudflare</span>
                </button>
            </form>

            <div id="remote-ngrok-panel" data-remote-provider-panel="ngrok"<?= $remoteSetupProvider !== 'ngrok' ? ' hidden' : '' ?>>
                <h3 class="remote-panel-title"><span class="remote-mark" data-mark="ngrok" aria-hidden="true"><?= $remoteProviderMarks['ngrok'] ?></span> Free setup</h3>
                <form method="post" class="remote-provider-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_tunnel_setup_block">
                    <input type="hidden" name="setup_mode" value="ngrok">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="ngrok_token">ngrok authtoken</label>
                            <input type="password" id="ngrok_token" name="ngrok_token" autocomplete="new-password" maxlength="512" placeholder="Paste token to generate a setup script" required>
                        </div>
                        <div class="form-group">
                            <label for="ngrok_hostname">Assigned free hostname</label>
                            <input type="text" id="ngrok_hostname" name="ngrok_hostname" autocomplete="off" spellcheck="false" value="<?= htmlspecialchars($ngrokHostname) ?>" placeholder="your-name.ngrok-free.dev" required>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Generate free setup block</button>
                    </div>
                </form>
                <details class="remote-details">
                    <summary>Get your ngrok credentials</summary>
                    <ol class="tunnel-checklist">
                        <li>Create your Store’s account at <a href="https://dashboard.ngrok.com/signup" target="_blank" rel="noopener noreferrer">ngrok</a>.</li>
                        <li>Copy only the authtoken, not the full command. Find your assigned hostname under Domains.</li>
                        <li>Generate the script here, copy it, and run it in PowerShell as Administrator on this PC.</li>
                        <li>Free plan: 1 GB outbound and 20,000 requests per month. ngrok shows a browser warning first.</li>
                    </ol>
                </details>
            </div>

            <div id="remote-cloudflare-panel" data-remote-provider-panel="permanent"<?= $remoteSetupProvider !== 'permanent' ? ' hidden' : '' ?>>
                <h3 class="remote-panel-title"><span class="remote-mark" data-mark="cloudflare" aria-hidden="true"><?= $remoteProviderMarks['cloudflare'] ?></span> Custom domain</h3>
                <form method="post" class="remote-provider-form">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="tunnel_token">Cloudflare permanent token</label>
                            <input type="password" id="tunnel_token" name="tunnel_token" autocomplete="off" value="<?= !empty($tunnelStatus['masked_token']) ? htmlspecialchars((string)$tunnelStatus['masked_token']) : '' ?>" placeholder="Paste Cloudflare tunnel token">
                        </div>
                        <div class="form-group">
                            <label for="tunnel_hostname">Custom hostname</label>
                            <input type="text" id="tunnel_hostname" name="tunnel_hostname" autocomplete="off" spellcheck="false" value="<?= htmlspecialchars((string)($tunnelStatus['hostname'] ?? '')) ?>" placeholder="remote.yourstore.com">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="action" value="save_tunnel_token" class="btn btn-secondary">Save Cloudflare settings</button>
                        <?php if (!empty($tunnelStatus['token_configured'])): ?>
                            <button type="submit" name="action" value="clear_tunnel_token" class="btn btn-secondary">Clear saved token</button>
                        <?php endif; ?>
                    </div>
                </form>
                <form method="post" class="remote-generate-cloudflare">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_tunnel_setup_block">
                    <input type="hidden" name="setup_mode" value="permanent">
                    <button type="submit" class="btn btn-primary"<?= empty($tunnelStatus['token_configured']) || empty($tunnelStatus['hostname']) ? ' disabled' : '' ?>>Generate custom domain setup block</button>
                </form>
                <details class="remote-details">
                    <summary>Cloudflare dashboard checklist</summary>
                    <ol class="tunnel-checklist">
                        <li>Add a domain you own to Cloudflare and create a Named Tunnel.</li>
                        <li>Route your public hostname to <code>http://127.0.0.1:<?= (int)$localAppPort ?></code>.</li>
                        <li>Copy the tunnel token and save it with the hostname above.</li>
                        <li>Generate and run the setup script as Administrator, then share the new link.</li>
                        <li>The script checks Cloudflare before it stops ngrok. A failed check restores the previous setup.</li>
                    </ol>
                </details>
            </div>

            <?php if ($generatedTunnelBlock !== ''): ?>
                <div class="tunnel-setup-result">
                    <div class="tunnel-setup-result-heading">
                        <h3 class="tunnel-setup-heading"><?= $generatedTunnelBlockType === 'removal' ? 'Remove Remote Access' : 'Run in PowerShell as Administrator' ?></h3>
                        <button type="button" class="btn btn-secondary btn-sm" data-copy-target="<?= $generatedTunnelBlockType === 'removal' ? 'tunnel-removal-block' : 'tunnel-setup-block' ?>">Copy block</button>
                    </div>
                    <p class="remote-token-warning"><i class="bi bi-shield-lock" aria-hidden="true"></i> Contains your token. Keep it private.</p>
                    <textarea id="<?= $generatedTunnelBlockType === 'removal' ? 'tunnel-removal-block' : 'tunnel-setup-block' ?>" class="tunnel-setup-block" readonly spellcheck="false" aria-label="<?= $generatedTunnelBlockType === 'removal' ? 'Remote Access removal PowerShell block' : 'Store PC Remote Access setup PowerShell block' ?>"><?= htmlspecialchars($generatedTunnelBlock) ?></textarea>
                </div>
            <?php endif; ?>

            <p class="remote-operating-note">RetailMind login and role permissions protect Store access. Keep this PC and its internet connection running during store hours.</p>
            <details class="remote-details remote-maintenance">
                <summary>Email alerts and removal</summary>
                <form method="post">
                    <?= csrf_field() ?>
                    <label for="tunnel_url_notify_enabled" class="settings-check">
                        <input type="checkbox" id="tunnel_url_notify_enabled" name="tunnel_url_notify_enabled" value="1"<?= $tunnelNotifyEnabled ? ' checked' : '' ?>>
                        <span>Email admins when the public link changes</span>
                    </label>
                    <div class="form-actions">
                        <button type="submit" name="action" value="save_tunnel_notify" class="btn btn-secondary">Save alerts</button>
                        <button type="submit" name="action" value="test_tunnel_notify" class="btn btn-secondary">Send test email</button>
                    </div>
                </form>
                <form method="post" class="remote-removal-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_tunnel_removal_block">
                    <button type="submit" class="btn btn-secondary">Generate removal block</button>
                    <p class="remote-help">Removes tunnel services. Store data is kept.</p>
                </form>
            </details>
        </section>

        </div>
    </main>
</div>
<script>
(() => {
    document.addEventListener('DOMContentLoaded', () => {
        if (window.innerWidth > 900 && !document.body.classList.contains('sidebar-collapsed')) {
            document.getElementById('sidebarBrandToggle')?.click();
        }
        document.querySelector('.platform-settings-nav a[aria-current="page"]')?.addEventListener('click', event => event.preventDefault());
    });
})();
</script>
<script>
(() => {
    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.getElementById(button.getAttribute('data-copy-target') || '');
            if (!target) {
                return;
            }
            const previous = button.textContent;
            let copyField = target;
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(target.value);
                } else {
                    if (target.type === 'hidden') {
                        copyField = document.createElement('textarea');
                        copyField.value = target.value;
                        copyField.className = 'remote-copy-buffer';
                        document.body.append(copyField);
                    }
                    copyField.focus();
                    copyField.select();
                    document.execCommand('copy');
                }
                button.textContent = 'Copied!';
                setTimeout(() => { button.textContent = previous; }, 2000);
            } catch (error) {
                if (target.type !== 'hidden') { target.focus(); target.select(); }
            } finally {
                if (copyField !== target) { copyField.remove(); }
            }
        });
    });
})();
</script>
<script type="application/json" id="remote-access-toggle-config"><?= json_encode([
    'apiUrl' => app_url('components/administrator/remote_access_api.php'),
    'csrfToken' => csrf_token(),
    'permanent' => !empty($tunnelStatus['token_configured']),
    'mode' => $tunnelStatus['mode'] ?? 'quick',
    'installed' => !empty($tunnelStatus['installed']),
    'configuredUrl' => $remotePublicUrl,
    'state' => $remoteTunnelState,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= htmlspecialchars(theme_url('assets/js/remote-access-toggle.js')) ?>"></script>
</body>
</html>
