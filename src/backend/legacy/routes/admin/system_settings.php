<?php
require_once __DIR__ . '/../includes/auth.php';
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
    'receipt_footer' => 'Receipt footer',
];
$attentionFields = [
    'failed_login_count' => 'Failed sign-ins',
    'locked_account_count' => 'Locked accounts',
    'backup_age_days' => 'Maximum backup age (days)',
    'recovery_failure_count' => 'Recovery failures',
    'service_unhealthy_count' => 'Unhealthy services',
    'ml_model_age_days' => 'Maximum model age (days)',
    'store_reversal_count_max' => 'Store reversal threshold safety maximum',
    'store_unusual_discount_percent_max' => 'Store discount threshold safety maximum (%)',
    'store_cash_variance_amount_max' => 'Store cash variance threshold safety maximum',
    'store_overdue_approval_hours_max' => 'Store overdue approval safety maximum (hours)',
    'store_inventory_overdue_hours_max' => 'Store inventory overdue safety maximum (hours)',
    'store_inventory_high_value_amount_max' => 'Store high-value risk safety maximum',
    'store_inventory_unresolved_hours_max' => 'Store unresolved risk safety maximum (hours)',
];
$attentionSettingsService = new \App\Attention\AttentionSettingsService($pdo, new \App\Attention\SystemClock());
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'test_email') {
        $recipient = trim((string)($_POST['test_email'] ?? ''));
        $sent = send_email_notification($recipient, 'RetailMind email test', "This is a RetailMind SMTP test sent on " . date('Y-m-d H:i:s') . '.');
        $message = $sent ? 'Test email sent successfully.' : 'Test email failed. Check Composer installation and MAIL_* settings in .env.';
        $messageClass = $sent ? 'tag-success' : 'tag-warning';
    } elseif ($action === 'save_attention') {
        try {
            $updates = [];
            foreach ($attentionFields as $key => $label) {
                $updates[$key] = $_POST[$key] ?? '';
            }
            $attentionSettingsService->updatePlatform(current_role(), $updates, (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Platform attention thresholds updated', 'Platform Settings', null, null, $updates);
            $message = 'Platform attention thresholds and Store safety limits saved.';
            $messageClass = 'tag-success';
        } catch (Throwable $exception) {
            $message = \App\Support\OperatorAlert::message($exception, 'The setting could not be saved. Check the values and try again. Tell your Super Administrator if this keeps happening.');
            $messageClass = 'tag-warning';
        }
    } else {
        $updates = [];
        foreach ($fields as $key => $label) {
            $updates[$key] = trim((string)($_POST[$key] ?? ''));
        }
        if ($updates['store_name'] === '') {
            $message = 'Store name is required.';
            $messageClass = 'tag-warning';
        } else {
            save_key_value_settings($pdo, 'store_settings', $updates, (int)$_SESSION['user_id']);
            log_activity($pdo, (int)$_SESSION['user_id'], 'Store settings update', 'Store Settings', null, null, $updates);
            $message = 'Store and receipt settings saved.';
            $messageClass = 'tag-success';
        }
    }
}
$settings = get_store_settings($pdo);
$attentionSettings = $attentionSettingsService->platformSettings();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System Settings</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../../../../frontend/components/sidebar.php'; ?><main class="main-content"><div class="topbar"><div><h1>System Settings</h1><p class="page-subtitle">Configure store identity, receipts, timezone, and email delivery.</p></div></div>
<?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="dashboard-section"><h3>Store and receipt</h3><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><div class="form-grid">
<?php foreach ($fields as $key => $label): ?><div class="form-group"><label><?= htmlspecialchars($label) ?></label><?php if ($key === 'receipt_footer'): ?><textarea name="<?= htmlspecialchars($key) ?>" rows="3"><?= htmlspecialchars((string)$settings[$key]) ?></textarea><?php else: ?><input name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string)$settings[$key]) ?>"<?= $key === 'store_name' ? ' required' : '' ?>><?php endif; ?></div><?php endforeach; ?>
</div><button class="btn">Save settings</button></form></div>
<div class="dashboard-section"><h3>Platform attention thresholds</h3><p class="section-description">Technical thresholds and hard safety limits are governed only by the Super Administrator. Store Settings may choose stricter values but cannot exceed these limits.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_attention"><div class="form-grid">
<?php foreach ($attentionFields as $key => $label): ?><div class="form-group"><label><?= htmlspecialchars($label) ?></label><input type="number" min="0" step="0.01" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string)$attentionSettings[$key]) ?>" required></div><?php endforeach; ?>
</div><button class="btn">Save attention thresholds</button></form></div>
<div class="dashboard-section"><h3>Email delivery test</h3><p class="section-description">SMTP credentials remain in the server’s <code>.env</code> file and are not displayed in the browser.</p><form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="test_email"><div class="form-group" style="min-width:280px;margin:0"><label>Test recipient</label><input type="email" name="test_email" value="<?= htmlspecialchars((string)$settings['store_email']) ?>" required></div><button class="btn">Send test email</button></form></div>
</main></div></body></html>
