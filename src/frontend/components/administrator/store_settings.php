<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

$service = new App\Attention\AttentionSettingsService($pdo, new App\Attention\SystemClock());
$fields = [
    'cash_variance_amount' => [
        'label' => 'Cash variance review threshold (₱)',
        'help' => 'Requires Shift variance review and raises Store Overview attention when counted cash difference reaches or exceeds this amount.',
    ],
    'inventory_overdue_hours' => [
        'label' => 'Stock Issue overdue warning (hours)',
        'help' => 'Raises a Store Overview warning when an open Stock Issue remains pending longer than this duration.',
    ],
    'inventory_high_value_amount' => [
        'label' => 'High-value Stock Issue alert (₱)',
        'help' => 'Raises a critical Store Overview alert when an open Stock Issue risk value reaches or exceeds this amount.',
    ],
    'fiscal_period_days_to_close' => [
        'label' => 'Fiscal Period closing warning (days)',
        'help' => 'Raises an upcoming closure warning when an active Fiscal Period has this many or fewer days remaining.',
    ],
];
$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $updates = [];
        foreach ($fields as $key => $config) {
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
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Store Settings</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Store Settings</h1>
                <p class="page-subtitle">Configure thresholds that trigger Store Overview attention and required operational reviews.</p>
            </div>
        </header>
        <?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <section class="dashboard-section">
            <h2>Attention thresholds</h2>
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <?php foreach ($fields as $key => $config): ?>
                        <div class="form-group">
                            <label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($config['label']) ?></label>
                            <input id="<?= htmlspecialchars($key) ?>" type="number" min="0" step="0.01" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string)($requested[$key] ?? 0)) ?>" required>
                            <small class="muted"><?= htmlspecialchars($config['help']) ?></small>
                            <?php if (isset($effective[$key]) && isset($requested[$key]) && $effective[$key] !== $requested[$key]): ?>
                                <small>Effective Platform-limited value: <?= htmlspecialchars((string)$effective[$key]) ?></small>
                            <?php endif; ?>
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