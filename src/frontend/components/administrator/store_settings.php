<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

$service = new App\Attention\AttentionSettingsService($pdo, new App\Attention\SystemClock());
$fields = [
    'reversal_count' => 'Sales reversals',
    'unusual_discount_percent' => 'Unusual discount (%)',
    'cash_variance_amount' => 'Cash variance amount',
    'overdue_approval_hours' => 'Approval overdue after (hours)',
    'inventory_escalation_count' => 'Escalated inventory risks',
    'inventory_overdue_hours' => 'Inventory risk overdue after (hours)',
    'inventory_high_value_amount' => 'High-value inventory risk amount',
    'inventory_unresolved_hours' => 'Inventory risk unresolved after (hours)',
    'fiscal_period_days_to_close' => 'Fiscal Period closing warning (days)',
];
$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $updates = [];
        foreach ($fields as $key => $label) {
            $updates[$key] = $_POST[$key] ?? '';
        }
        $service->updateStore(current_role(), $updates, (int)$_SESSION['user_id']);
        log_activity($pdo, (int)$_SESSION['user_id'], 'Store attention thresholds updated', 'Store Settings', null, null, $updates);
        $message = 'Store attention thresholds saved. Platform safety limits are applied automatically.';
        $messageClass = 'tag-success';
    } catch (Throwable $exception) {
        $message = \App\Support\OperatorAlert::message($exception, 'The Store Setting could not be saved. Check the values and try again. Tell your Administrator if this keeps happening.');
        $messageClass = 'tag-warning';
    }
}

$requested = $service->storeSettings();
$effective = $service->thresholdsFor('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Store Settings</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div><p class="page-kicker">Administrator workspace</p><h1>Store Settings</h1><p class="page-subtitle">Govern sales, cash, approval, inventory, and Fiscal Period attention thresholds within Platform safety limits.</p></div>
        </header>
        <?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <section class="dashboard-section">
            <h2>Attention thresholds</h2>
            <p class="section-description">If a requested value exceeds a Platform safety limit, RetailMind uses the stricter effective value shown below.</p>
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <?php foreach ($fields as $key => $label): ?>
                        <div class="form-group">
                            <label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></label>
                            <input id="<?= htmlspecialchars($key) ?>" type="number" min="0" step="0.01" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string)$requested[$key]) ?>" required>
                            <?php if ($effective[$key] !== $requested[$key]): ?><small>Effective Platform-limited value: <?= htmlspecialchars((string)$effective[$key]) ?></small><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn" type="submit">Save Store thresholds</button>
            </form>
        </section>
    </main>
</div>
</body>
</html>
