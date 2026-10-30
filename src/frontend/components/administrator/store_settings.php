<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

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
<body class="store-settings-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Store Settings</h1>
                <p class="page-subtitle">Configure operational review thresholds and alert limits for store activity.</p>
            </div>
        </header>
        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>" aria-live="polite"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <section class="dashboard-section" aria-labelledby="thresholds-heading">
            <div class="section-header">
                <div>
                    <h2 id="thresholds-heading">Attention thresholds</h2>
                    <p class="section-description">Operational thresholds trigger automated system reviews and supervisory warnings.</p>
                </div>
            </div>
            <form method="post">
                <?= csrf_field() ?>
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
                    <button class="btn btn-primary" type="submit">Save settings</button>
                </div>
            </form>
        </section>
    </main>
</div>
</body>
</html>
