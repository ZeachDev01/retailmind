<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin']);

$message = '';
$messageClass = '';
$definitions = [
    'minimum_history_days' => ['label' => 'Minimum calendar history (days)', 'min' => 14, 'max' => 365, 'type' => 'number'],
    'preferred_history_days' => ['label' => 'Preferred calendar history (days)', 'min' => 30, 'max' => 730, 'type' => 'number'],
    'minimum_nonzero_sales_days' => ['label' => 'Minimum days with sales', 'min' => 1, 'max' => 365, 'type' => 'number'],
    'history_window_days' => ['label' => 'Maximum training history (days)', 'min' => 60, 'max' => 1095, 'type' => 'number'],
    'forecast_period_days' => ['label' => 'Forecast period (days)', 'min' => 7, 'max' => 90, 'type' => 'number'],
    'n_estimators' => ['label' => 'Random Forest trees', 'min' => 50, 'max' => 1000, 'type' => 'number'],
    'max_depth' => ['label' => 'Maximum tree depth', 'min' => 2, 'max' => 60, 'type' => 'number'],
    'min_samples_split' => ['label' => 'Minimum samples to split', 'min' => 2, 'max' => 50, 'type' => 'number'],
    'min_samples_leaf' => ['label' => 'Minimum samples per leaf', 'min' => 1, 'max' => 30, 'type' => 'number'],
    'retrain_frequency_days' => ['label' => 'Scheduled retraining interval (days)', 'min' => 1, 'max' => 90, 'type' => 'number'],
    'retrain_new_sales_records' => ['label' => 'Retrain after new sale items', 'min' => 1, 'max' => 100000, 'type' => 'number'],
    'accuracy_threshold_wape' => ['label' => 'Maximum acceptable WAPE (%)', 'min' => 1, 'max' => 200, 'step' => '0.1', 'type' => 'number'],
    'prediction_interval_lower' => ['label' => 'Prediction interval lower percentile', 'min' => 1, 'max' => 49, 'type' => 'number'],
    'prediction_interval_upper' => ['label' => 'Prediction interval upper percentile', 'min' => 51, 'max' => 99, 'type' => 'number'],
    'holiday_dates' => ['label' => 'Additional special dates (YYYY-MM-DD, comma-separated)', 'type' => 'text'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $updates = [];
    $errors = [];
    foreach ($definitions as $key => $definition) {
        $value = trim((string)($_POST[$key] ?? ''));
        if ($definition['type'] === 'number') {
            if (!is_numeric($value) || (float)$value < $definition['min'] || (float)$value > $definition['max']) {
                $errors[] = $definition['label'] . ' is outside the allowed range.';
                continue;
            }
        } elseif ($key === 'holiday_dates' && $value !== '') {
            foreach (array_filter(array_map('trim', explode(',', $value))) as $date) {
                $parsed = DateTime::createFromFormat('Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                    $errors[] = "Invalid special date: {$date}.";
                }
            }
        }
        $updates[$key] = $value;
    }
    if (!$errors) {
        save_key_value_settings($pdo, 'ml_settings', $updates, (int)$_SESSION['user_id']);
        log_activity($pdo, (int)$_SESSION['user_id'], 'ML settings update', 'Demand Forecasting', null, null, $updates);
        $message = 'Random Forest settings saved. Retrain the model to apply them.';
        $messageClass = 'tag-success';
    } else {
        $message = implode(' ', $errors);
        $messageClass = 'tag-warning';
    }
}

$settings = get_ml_settings($pdo);
try {
    $runs = $pdo->query("SELECT * FROM model_training_runs ORDER BY started_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $runs = [];
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ML Settings</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
<div class="topbar"><div><h1>Random Forest Settings</h1><p class="page-subtitle">Control data readiness, tree parameters, prediction ranges, and automatic retraining.</p></div><a class="btn" href="<?= htmlspecialchars(app_url('report/predictions.php')) ?>">Open forecasts</a></div>
<?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="dashboard-section"><form method="post"><?= csrf_field() ?><div class="form-grid">
<?php foreach ($definitions as $key => $definition): ?><div class="form-group"><label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($definition['label']) ?></label><input id="<?= htmlspecialchars($key) ?>" name="<?= htmlspecialchars($key) ?>" type="<?= $definition['type'] ?>" value="<?= htmlspecialchars((string)($settings[$key] ?? '')) ?>"<?= isset($definition['min']) ? ' min="' . $definition['min'] . '"' : '' ?><?= isset($definition['max']) ? ' max="' . $definition['max'] . '"' : '' ?><?= isset($definition['step']) ? ' step="' . $definition['step'] . '"' : '' ?> required></div><?php endforeach; ?>
</div><button class="btn">Save ML settings</button></form></div>
<div class="dashboard-section"><div class="section-header"><div><h3>Recent training runs</h3><p class="section-description">Every manual, scheduled, automatic, or command-line attempt is recorded.</p></div></div><div class="table-wrap"><table><tr><th>Started</th><th>Trigger</th><th>Status</th><th>Duration</th><th>Products</th><th>Records</th><th>Error</th></tr>
<?php foreach ($runs as $run): ?><tr><td><?= htmlspecialchars($run['started_at']) ?></td><td><?= htmlspecialchars($run['trigger_type']) ?></td><td><?= htmlspecialchars($run['status']) ?></td><td><?= $run['duration_seconds'] !== null ? number_format((float)$run['duration_seconds'], 2) . ' s' : '-' ?></td><td><?= (int)$run['eligible_products'] ?></td><td><?= (int)$run['sales_records_used'] ?></td><td><?= htmlspecialchars(mb_strimwidth((string)($run['error_message'] ?? ''), 0, 100, '...')) ?></td></tr><?php endforeach; ?>
<?php if (!$runs): ?><tr><td colspan="7" class="muted" style="text-align:center">No training history yet. Run the database migration and train the model.</td></tr><?php endif; ?></table></div></div>
</main></div></body></html>
