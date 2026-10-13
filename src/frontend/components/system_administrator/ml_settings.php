<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::CONFIGURE_MODELS);

$message = '';
$messageClass = '';
$fieldErrors = [];
$fieldGroups = [
    'history' => [
        'title' => 'Sales History & Horizon',
        'description' => 'Controls how much daily store history is sampled and how far ahead demand is projected.',
    ],
    'forest' => [
        'title' => 'Random Forest Complexity',
        'description' => 'Controls ensemble size and decision-tree splitting thresholds during training.',
    ],
    'schedule' => [
        'title' => 'Automatic Retraining Triggers',
        'description' => 'Defines when scheduled or sales-volume retraining runs are queued.',
    ],
    'accuracy' => [
        'title' => 'Accuracy Guardrails & Special Dates',
        'description' => 'Sets error warning thresholds, demand confidence intervals, and store-specific event dates.',
    ],
];
$definitions = [
    'minimum_history_days' => [
        'label' => 'Minimum calendar history (days)',
        'min' => 14,
        'max' => 365,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
        'help' => '14 to 365 days. Shortest calendar span required before a product is eligible for training.',
    ],
    'preferred_history_days' => [
        'label' => 'Preferred calendar history (days)',
        'min' => 30,
        'max' => 730,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
        'help' => '30 to 730 days. Target history span for full seasonal and day-of-week weighting.',
    ],
    'minimum_nonzero_sales_days' => [
        'label' => 'Minimum days with sales',
        'min' => 1,
        'max' => 365,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
        'help' => '1 to 365 days. Active selling days required within the history window.',
    ],
    'history_window_days' => [
        'label' => 'Maximum training history (days)',
        'min' => 60,
        'max' => 1095,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
        'help' => '60 to 1,095 days. Upper lookback cap used when sampling historical sales records.',
    ],
    'forecast_period_days' => [
        'label' => 'Forecast period (days)',
        'min' => 7,
        'max' => 90,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
        'help' => '7 to 90 days. Forward horizon generated on each prediction run.',
    ],
    'n_estimators' => [
        'label' => 'Random Forest trees',
        'min' => 50,
        'max' => 1000,
        'type' => 'number',
        'required' => true,
        'group' => 'forest',
        'help' => '50 to 1,000 trees. Higher counts reduce variance at the cost of longer training time.',
    ],
    'max_depth' => [
        'label' => 'Maximum tree depth',
        'min' => 2,
        'max' => 60,
        'type' => 'number',
        'required' => true,
        'group' => 'forest',
        'help' => '2 to 60 levels. Controls how deeply each tree can branch on calendar and lag features.',
    ],
    'min_samples_split' => [
        'label' => 'Minimum samples to split',
        'min' => 2,
        'max' => 50,
        'type' => 'number',
        'required' => true,
        'group' => 'forest',
        'help' => '2 to 50 observations required before an internal node can split.',
    ],
    'min_samples_leaf' => [
        'label' => 'Minimum samples per leaf',
        'min' => 1,
        'max' => 30,
        'type' => 'number',
        'required' => true,
        'group' => 'forest',
        'help' => '1 to 30 observations required in every terminal leaf node.',
    ],
    'retrain_frequency_days' => [
        'label' => 'Scheduled retraining interval (days)',
        'min' => 1,
        'max' => 90,
        'type' => 'number',
        'required' => true,
        'group' => 'schedule',
        'help' => '1 to 90 days between automatic scheduled retraining runs.',
    ],
    'retrain_new_sales_records' => [
        'label' => 'Retrain after new sale items',
        'min' => 1,
        'max' => 100000,
        'type' => 'number',
        'required' => true,
        'group' => 'schedule',
        'help' => '1 to 100,000 new sale line items before volume-triggered retraining queues.',
    ],
    'accuracy_threshold_wape' => [
        'label' => 'Maximum acceptable WAPE (%)',
        'min' => 1,
        'max' => 200,
        'step' => '0.1',
        'type' => 'number',
        'required' => true,
        'group' => 'accuracy',
        'help' => '1.0% to 200.0%. Error ceiling before forecast accuracy warnings surface.',
    ],
    'prediction_interval_lower' => [
        'label' => 'Prediction interval lower percentile',
        'min' => 1,
        'max' => 49,
        'type' => 'number',
        'required' => true,
        'group' => 'accuracy',
        'help' => '1st to 49th percentile used for the conservative lower demand band.',
    ],
    'prediction_interval_upper' => [
        'label' => 'Prediction interval upper percentile',
        'min' => 51,
        'max' => 99,
        'type' => 'number',
        'required' => true,
        'group' => 'accuracy',
        'help' => '51st to 99th percentile used for the upper safety-stock demand band.',
    ],
    'holiday_dates' => [
        'label' => 'Additional special dates (YYYY-MM-DD, comma-separated)',
        'type' => 'text',
        'required' => false,
        'group' => 'accuracy',
        'full' => true,
        'placeholder' => 'e.g. 2026-11-27, 2026-12-24 (optional)',
        'help' => 'Optional store promo days, closures, or local holidays in YYYY-MM-DD format separated by commas. Leave blank if none.',
    ],
];

$settings = get_ml_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $updates = [];
    $errors = [];
    foreach ($definitions as $key => $definition) {
        $value = trim((string)($_POST[$key] ?? ''));
        $settings[$key] = $value;
        if ($definition['type'] === 'number') {
            $step = (string)($definition['step'] ?? '1');
            $isFloatStep = strpos($step, '.') !== false;
            $numericValid = is_numeric($value) && ($isFloatStep || preg_match('/^-?\d+$/', $value) === 1);
            if (!$numericValid || (float)$value < $definition['min'] || (float)$value > $definition['max']) {
                $rangeMessage = sprintf(
                    '%s must be between %s and %s.',
                    $definition['label'],
                    number_format((float)$definition['min'], $isFloatStep ? 1 : 0),
                    number_format((float)$definition['max'], $isFloatStep ? 1 : 0)
                );
                $fieldErrors[$key] = $rangeMessage;
                $errors[] = $rangeMessage;
                continue;
            }
        } elseif ($key === 'holiday_dates') {
            if ($value !== '') {
                $normalizedDates = [];
                foreach (array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== '') as $date) {
                    $parsed = DateTime::createFromFormat('Y-m-d', $date);
                    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                        $dateError = "Invalid special date \"{$date}\". Use YYYY-MM-DD format separated by commas.";
                        $fieldErrors[$key] = $dateError;
                        $errors[] = $dateError;
                        break;
                    }
                    $normalizedDates[$date] = $date;
                }
                if (!isset($fieldErrors[$key])) {
                    ksort($normalizedDates);
                    $value = implode(', ', array_values($normalizedDates));
                    $settings[$key] = $value;
                }
            }
        }
        $updates[$key] = $value;
    }

    if (!isset($fieldErrors['minimum_history_days'], $fieldErrors['preferred_history_days'])
        && (int)$updates['minimum_history_days'] > (int)$updates['preferred_history_days']
    ) {
        $crossError = 'Minimum calendar history cannot exceed Preferred calendar history.';
        $fieldErrors['minimum_history_days'] = $crossError;
        $errors[] = $crossError;
    }

    if (!isset($fieldErrors['preferred_history_days'], $fieldErrors['history_window_days'])
        && (int)$updates['preferred_history_days'] > (int)$updates['history_window_days']
    ) {
        $crossError = 'Preferred calendar history cannot exceed Maximum training history.';
        $fieldErrors['preferred_history_days'] = $crossError;
        $errors[] = $crossError;
    }

    if (!isset($fieldErrors['minimum_nonzero_sales_days'], $fieldErrors['minimum_history_days'])
        && (int)$updates['minimum_nonzero_sales_days'] > (int)$updates['minimum_history_days']
    ) {
        $crossError = 'Minimum days with sales cannot exceed Minimum calendar history.';
        $fieldErrors['minimum_nonzero_sales_days'] = $crossError;
        $errors[] = $crossError;
    }

    if (!isset($fieldErrors['min_samples_leaf'], $fieldErrors['min_samples_split'])
        && (int)$updates['min_samples_leaf'] > (int)$updates['min_samples_split']
    ) {
        $crossError = 'Minimum samples per leaf cannot exceed Minimum samples to split.';
        $fieldErrors['min_samples_leaf'] = $crossError;
        $errors[] = $crossError;
    }

    if (!isset($fieldErrors['prediction_interval_lower'], $fieldErrors['prediction_interval_upper'])
        && (int)$updates['prediction_interval_lower'] >= (int)$updates['prediction_interval_upper']
    ) {
        $crossError = 'Prediction interval lower percentile must be strictly less than the upper percentile.';
        $fieldErrors['prediction_interval_lower'] = $crossError;
        $errors[] = $crossError;
    }

    if (!$errors) {
        save_key_value_settings($pdo, 'ml_settings', $updates, (int)$_SESSION['user_id']);
        log_activity($pdo, (int)$_SESSION['user_id'], 'ML settings update', 'Demand Forecasting', null, null, $updates);
        $settings = get_ml_settings($pdo);
        $message = 'Random Forest settings saved. Retrain the model to apply them.';
        $messageClass = 'tag-success';
    } else {
        $message = 'Review the highlighted fields below. ' . implode(' ', $errors);
        $messageClass = 'tag-warning';
    }
}

try {
    $runs = $pdo->query("SELECT started_at, trigger_type, status, duration_seconds, eligible_products, sales_records_used, error_message FROM model_training_runs ORDER BY started_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $runs = [];
}

$formatRunTrigger = static function (?string $trigger): string {
    $normalized = strtolower(trim((string)$trigger));
    if ($normalized === '') {
        return '-';
    }
    $labels = [
        'manual' => 'Manual',
        'scheduled' => 'Scheduled',
        'auto' => 'Automatic',
        'automatic' => 'Automatic',
        'cli' => 'Command line',
        'command_line' => 'Command line',
    ];
    return $labels[$normalized] ?? ucfirst(str_replace(['_', '-'], ' ', $normalized));
};

$formatRunStatus = static function (?string $status): array {
    $normalized = strtolower(trim((string)$status));
    if (in_array($normalized, ['completed', 'success', 'succeeded', 'ok'], true)) {
        return ['label' => 'Completed', 'class' => 'tag-success'];
    }
    if (in_array($normalized, ['running', 'in_progress', 'pending', 'queued', 'skipped', 'warning'], true)) {
        return ['label' => ucfirst(str_replace('_', ' ', $normalized)), 'class' => 'tag-warning'];
    }
    if (in_array($normalized, ['failed', 'error', 'aborted'], true)) {
        return ['label' => 'Failed', 'class' => 'tag-danger'];
    }
    return [
        'label' => $normalized !== '' ? ucfirst(str_replace('_', ' ', $normalized)) : '-',
        'class' => 'tag-warning',
    ];
};

$formatRunDetails = static function (?string $errorMessage): string {
    $raw = trim((string)$errorMessage);
    if ($raw === '') {
        return '-';
    }
    if (preg_match('/SQLSTATE|PDOException|Stack trace|#0\s|Traceback/i', $raw) === 1) {
        return 'Training run encountered an internal execution error. Review server logs and retry.';
    }
    return mb_strimwidth($raw, 0, 100, '...');
};

$stylePath = __DIR__ . '/../../assets/css/style.css';
$styleVersion = is_file($stylePath) ? (string)filemtime($stylePath) : '1';
?>
<!DOCTYPE html><html lang="en"><head><?php retailmind_theme_head(); ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ML Operation</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . rawurlencode($styleVersion)) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
<div class="topbar"><div><h1>ML Operation</h1><p class="page-subtitle">Manage Store sales history, Random Forest settings, and training runs.</p></div><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">Open forecasts</a></div>
<?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section class="dashboard-section"><h2>Forecast Data Import</h2><p class="section-description">Upload this Store's historical daily sales totals, preview them, and confirm the import. Model retraining remains a separate action.</p><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/system_administrator/forecast_import.php')) ?>">Import sales history</a></section>
<section class="dashboard-section"><div class="section-header"><div><h2>Random Forest Configuration</h2><p class="section-description">Configure history windows, tree complexity, automatic retraining triggers, and prediction interval bounds. Fields marked with <span class="required-mark" aria-hidden="true">*</span> are required.</p></div></div><form method="post" novalidate><?= csrf_field() ?>
<?php foreach ($fieldGroups as $groupKey => $groupMeta): ?>
<fieldset class="settings-fieldset"><legend><?= htmlspecialchars($groupMeta['title']) ?></legend><p class="fieldset-description"><?= htmlspecialchars($groupMeta['description']) ?></p><div class="form-grid">
<?php foreach ($definitions as $key => $definition):
    if (($definition['group'] ?? '') !== $groupKey) {
        continue;
    }
    $isRequired = !empty($definition['required']);
    $hasError = isset($fieldErrors[$key]);
    $helpId = $key . '_help';
    $errorId = $key . '_error';
    $describedBy = $hasError ? ($errorId . ' ' . $helpId) : $helpId;
    $groupClasses = 'form-group' . (!empty($definition['full']) ? ' full' : '') . ($hasError ? ' invalid' : '');
    $inputMode = $definition['type'] === 'number' ? (isset($definition['step']) ? 'decimal' : 'numeric') : null;
?><div class="<?= $groupClasses ?>"><label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($definition['label']) ?><?php if ($isRequired): ?> <span class="required-mark" aria-hidden="true">*</span><?php endif; ?></label><input id="<?= htmlspecialchars($key) ?>" name="<?= htmlspecialchars($key) ?>" type="<?= htmlspecialchars($definition['type']) ?>" value="<?= htmlspecialchars((string)($settings[$key] ?? '')) ?>"<?= $inputMode ? ' inputmode="' . $inputMode . '"' : '' ?><?= isset($definition['min']) ? ' min="' . (int)$definition['min'] . '"' : '' ?><?= isset($definition['max']) ? ' max="' . (int)$definition['max'] . '"' : '' ?><?= isset($definition['step']) ? ' step="' . htmlspecialchars((string)$definition['step']) . '"' : '' ?><?= isset($definition['placeholder']) ? ' placeholder="' . htmlspecialchars((string)$definition['placeholder']) . '"' : '' ?> autocomplete="off" aria-describedby="<?= htmlspecialchars($describedBy) ?>"<?= $hasError ? ' aria-invalid="true"' : '' ?><?= $isRequired ? ' required' : '' ?>><?php if ($hasError): ?><small id="<?= htmlspecialchars($errorId) ?>" class="field-error"><?= htmlspecialchars($fieldErrors[$key]) ?></small><?php endif; ?><small id="<?= htmlspecialchars($helpId) ?>" class="field-help"><?= htmlspecialchars($definition['help']) ?></small></div><?php endforeach; ?>
</div></fieldset>
<?php endforeach; ?>
<div class="form-actions"><button type="submit" class="btn">Save ML settings</button></div></form></section>
<section class="dashboard-section"><div class="section-header"><div><h2>Recent training runs</h2><p class="section-description">Every manual, scheduled, automatic, or command-line attempt is recorded.</p></div></div><div class="table-wrap"><table><thead><tr><th scope="col">Started</th><th scope="col">Trigger</th><th scope="col">Status</th><th scope="col" class="u-num-col">Duration</th><th scope="col" class="u-num-col">Products</th><th scope="col" class="u-num-col">Records</th><th scope="col">Details</th></tr></thead><tbody>
<?php foreach ($runs as $run):
    $statusMeta = $formatRunStatus($run['status'] ?? null);
?><tr><td><?= htmlspecialchars(format_display_datetime($run['started_at'] ?? null)) ?></td><td><?= htmlspecialchars($formatRunTrigger($run['trigger_type'] ?? null)) ?></td><td><span class="<?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span></td><td class="u-num-col"><?= $run['duration_seconds'] !== null ? number_format((float)$run['duration_seconds'], 2) . ' s' : '-' ?></td><td class="u-num-col"><?= number_format((int)($run['eligible_products'] ?? 0)) ?></td><td class="u-num-col"><?= number_format((int)($run['sales_records_used'] ?? 0)) ?></td><td class="table-cell-note"><?= htmlspecialchars($formatRunDetails($run['error_message'] ?? null)) ?></td></tr><?php endforeach; ?>
<?php if (!$runs): ?><tr><td colspan="7" class="u-empty-cell-padded u-text-center">No training runs recorded yet. Save your configuration above or import sales history, then run a forecast training job.</td></tr><?php endif; ?></tbody></table></div></section>
</main></div></body></html>
