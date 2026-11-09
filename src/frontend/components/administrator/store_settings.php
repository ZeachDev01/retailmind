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
                ? 'Store and receipt settings saved. Store email alerts were disabled because the Store email changed. Ask your Super Administrator to send a test email from Platform Settings to re-enable them.'
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
                                          rows="3" autocomplete="off"
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
        <section class="dashboard-section" id="remote-access-card" aria-labelledby="remote-access-heading">
            <div class="section-header">
                <div class="remote-access-header-wrap">
                    <div>
                        <h2 id="remote-access-heading">Remote Access</h2>
                        <p class="section-description">Encrypted external access for supervisors without port forwarding.</p>
                    </div>
                    <div class="remote-badges-group">
                        <span id="remote-mode-badge" class="remote-pill <?= !empty($tunnelStatus['token_configured']) ? 'pill-primary' : 'pill-muted' ?>">
                            <?= !empty($tunnelStatus['token_configured']) ? 'Custom Domain' : 'Quick Tunnel' ?>
                        </span>
                        <span id="remote-status-badge" class="remote-status-pill <?= ($tunnelStatus['status'] ?? 'offline') === 'online' ? 'status-online' : (($tunnelStatus['status'] ?? 'offline') === 'starting' ? 'status-starting' : 'status-offline') ?>" role="status">
                            <?= ($tunnelStatus['status'] ?? 'offline') === 'online' ? 'Online' : (($tunnelStatus['status'] ?? 'offline') === 'starting' ? 'Connecting...' : 'Offline') ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="remote-access-content">
                <!-- Master Toggle Panel -->
                <div class="remote-switch-panel">
                    <div class="remote-switch-info">
                        <div class="remote-icon-badge" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="2" y1="12" x2="22" y2="12"></line>
                                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                            </svg>
                        </div>
                        <div>
                            <label for="remote-access-toggle" class="remote-toggle-title">Cloudflare Tunnel</label>
                            <span id="remote-toggle-status-text" class="remote-toggle-sub">
                                <?= !empty($tunnelStatus['online']) ? 'Active &amp; encrypted' : 'Disabled' ?>
                            </span>
                        </div>
                    </div>
                    <div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="remote-access-toggle" role="switch" <?= !empty($tunnelStatus['online']) ? 'checked' : '' ?> aria-checked="<?= !empty($tunnelStatus['online']) ? 'true' : 'false' ?>" aria-label="Toggle Remote Access Tunnel">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>

                <!-- Active URL Output Box -->
                <div id="remote-url-container" class="remote-url-container<?= (!empty($tunnelStatus['online']) && !empty($tunnelStatus['url'])) ? '' : ' u-hidden' ?>">
                    <div class="remote-url-top">
                        <span class="remote-url-label">Public Tunnel URL</span>
                        <span class="remote-url-security-note">Supervisors only &bull; Cashiers stay local</span>
                    </div>
                    <div class="remote-url-group">
                        <div class="remote-input-wrapper">
                            <svg class="remote-link-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                            </svg>
                            <input type="text" id="remote-public-url" class="remote-url-input" readonly spellcheck="false" value="<?= htmlspecialchars((string)($tunnelStatus['url'] ?? '')) ?>" placeholder="Tunnel is offline">
                        </div>
                        <div class="remote-btn-group">
                            <button type="button" id="copy-remote-url-btn" class="btn btn-secondary" title="Copy URL">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                <span id="copy-btn-text">Copy</span>
                            </button>
                            <button type="button" id="show-qr-code-btn" class="btn btn-secondary" title="Display mobile QR code">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                                <span>QR</span>
                            </button>
                            <a id="open-remote-url-btn" href="<?= htmlspecialchars((string)($tunnelStatus['url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" title="Open URL in new tab">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                <span>Open</span>
                            </a>
                        </div>
                    </div>
                </div>

                <div id="remote-access-feedback" class="remote-feedback" role="status" aria-live="polite"></div>

                <!-- Dedicated Store PC 24/7 Strip -->
                <div class="remote-service-strip">
                    <div class="remote-service-left">
                        <span class="remote-service-badge">24/7 Service</span>
                        <span class="remote-service-text">Auto-start on Windows boot with crash recovery.</span>
                    </div>
                    <div class="remote-service-actions">
                        <button type="button" class="btn btn-secondary btn-sm" id="copy-service-cmd-btn" data-cmd="powershell -ExecutionPolicy Bypass -File .\src\backend\scripts\tunnel\service_install.ps1" title="Copy PowerShell installation command">
                            <span id="service-cmd-text">Copy PowerShell command</span>
                        </button>
                        <a href="<?= htmlspecialchars(app_url('components/auth/help.php#tunnel-deployment')) ?>" target="_blank" class="remote-guide-link">Guide &rarr;</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Dedicated Mobile QR Code Modal -->
        <div id="qr-code-modal" class="qr-modal-backdrop u-hidden" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title" tabindex="-1">
            <div class="qr-modal-dialog">
                <div class="qr-modal-header">
                    <h3 id="qr-modal-title">Mobile Access</h3>
                    <button type="button" id="close-qr-modal-btn" class="modal-close-btn" aria-label="Close dialog">&times;</button>
                </div>
                <div class="qr-modal-body">
                    <p class="qr-modal-description">Scan with your smartphone camera to connect instantly.</p>
                    <div id="qr-code-display" class="qr-code-display" aria-label="Scannable QR code of active remote URL">
                        <?= !empty($initialQrSvg) ? $initialQrSvg : '' ?>
                    </div>
                    <div class="qr-modal-url-wrap">
                        <input type="text" id="modal-remote-url" class="remote-url-input" readonly spellcheck="false" value="<?= htmlspecialchars((string)($tunnelStatus['url'] ?? '')) ?>" placeholder="Tunnel is offline">
                        <button type="button" id="modal-copy-url-btn" class="btn btn-secondary" title="Copy active URL">
                            <span id="modal-copy-btn-text">Copy URL</span>
                        </button>
                    </div>
                    <small class="field-help u-text-center">Authorized store supervisors only.</small>
                </div>
                <div class="qr-modal-footer">
                    <button type="button" id="close-qr-modal-footer-btn" class="btn btn-secondary">Done</button>
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

    const toggleSub = document.getElementById('remote-toggle-status-text');
    const openBtn = document.getElementById('open-remote-url-btn');
    const serviceCmdBtn = document.getElementById('copy-service-cmd-btn');
    const serviceCmdText = document.getElementById('service-cmd-text');

    function setBadge(state, text) {
        if (!badge) return;
        badge.className = 'remote-status-pill';
        if (state === 'online') {
            badge.classList.add('status-online');
            badge.textContent = text || 'Online';
            if (toggleSub) toggleSub.textContent = 'Active & encrypted';
        } else if (state === 'starting') {
            badge.classList.add('status-starting');
            badge.textContent = text || 'Connecting...';
            if (toggleSub) toggleSub.textContent = 'Connecting...';
        } else {
            badge.classList.add('status-offline');
            badge.textContent = text || 'Offline';
            if (toggleSub) toggleSub.textContent = 'Disabled';
        }
    }

    function setFeedback(msg, isError) {
        if (!feedback) return;
        feedback.textContent = msg || '';
        feedback.className = 'remote-feedback' + (isError ? ' is-error' : (msg ? ' is-success' : ''));
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
        if (openBtn) openBtn.href = data.url;
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

    if (serviceCmdBtn) {
        serviceCmdBtn.addEventListener('click', function() {
            const cmd = serviceCmdBtn.getAttribute('data-cmd');
            if (!cmd) return;
            const originalText = serviceCmdText ? serviceCmdText.textContent : 'Copy PowerShell command';
            function onCmdCopied() {
                if (serviceCmdText) serviceCmdText.textContent = '✓ Copied!';
                serviceCmdBtn.classList.add('btn-primary');
                serviceCmdBtn.classList.remove('btn-secondary');
                setTimeout(function() {
                    if (serviceCmdText) serviceCmdText.textContent = originalText;
                    serviceCmdBtn.classList.remove('btn-primary');
                    serviceCmdBtn.classList.add('btn-secondary');
                }, 2000);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(cmd).then(onCmdCopied).catch(function() {
                    setFeedback('Failed to copy command to clipboard.', true);
                });
            }
        });
    }
})();
</script>
</body>
</html>
