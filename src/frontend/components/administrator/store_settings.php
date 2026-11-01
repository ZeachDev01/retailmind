<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

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
            if ($updates['store_name'] === '') {
                throw new InvalidArgumentException('Store name is required.');
            }
            if ($updates['store_email'] !== '' && filter_var($updates['store_email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Enter a valid store email address.');
            }
            if ($updates['timezone'] !== '' && !in_array($updates['timezone'], timezone_identifiers_list(), true)) {
                throw new InvalidArgumentException('Enter a valid IANA timezone such as Asia/Manila or UTC.');
            }
            \App\Store\StoreWriteGate::run($pdo, function () use ($pdo, $updates): void {
                save_key_value_settings($pdo, 'store_settings', $updates, (int)$_SESSION['user_id']);
                log_activity($pdo, (int)$_SESSION['user_id'], 'Store settings update', 'Store Settings', null, null, $updates);
            });
            $message = 'Store and receipt settings saved.';
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
        $messageClass = 'tag-success';
    } catch (Throwable $exception) {
        $message = \App\Support\OperatorAlert::message($exception, 'The Store Setting could not be saved. Check the values and try again. Tell your Administrator if this keeps happening.');
        $messageClass = 'tag-warning';
    }
}

$settings = get_store_settings($pdo);
$requested = $service->storeSettings();
$effective = $service->thresholdsFor('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Store Settings</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
</head>
<body class="store-settings-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Store Settings</h1>
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
                    <button type="submit" class="btn btn-primary">Save store settings</button>
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
                            <input id="<?= htmlspecialchars($key) ?>" type="number" min="0" step="<?= htmlspecialchars($config['step']) ?>" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string)($requested[$key] ?? 0)) ?>"<?= $hasLimit ? ' aria-describedby="' . htmlspecialchars($key) . '-limit"' : '' ?> required>
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
    </main>
</div>
</body>
</html>
