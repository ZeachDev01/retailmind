<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::CONFIGURE_MODELS);

$message = '';
$messageClass = '';
$fieldErrors = [];
$fieldGroups = [
    'history' => [
        'title' => 'Sales history & forecast period',
    ],
    'schedule' => [
        'title' => 'Automatic retraining',
    ],
    'accuracy' => [
        'title' => 'Accuracy & special dates',
        'description' => 'Demand percentiles define the lower and upper ends of the forecast range.',
    ],
    'forest' => [
        'title' => 'Advanced: Random Forest tuning',
        'description' => 'Adjust the number and complexity of trees in the model.',
    ],
];
$definitions = [
    'minimum_history_days' => [
        'label' => 'Minimum calendar history (days)',
        'help' => 'Required calendar span before a product is eligible for training.',
        'min' => 14,
        'max' => 365,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
    ],
    'preferred_history_days' => [
        'label' => 'Preferred calendar history (days)',
        'help' => 'At least the minimum history; no more than the maximum training history.',
        'min' => 30,
        'max' => 730,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
    ],
    'minimum_nonzero_sales_days' => [
        'label' => 'Minimum days with sales',
        'min' => 1,
        'max' => 365,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
    ],
    'history_window_days' => [
        'label' => 'Maximum training history (days)',
        'min' => 60,
        'max' => 1095,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
    ],
    'forecast_period_days' => [
        'label' => 'Forecast period (days)',
        'min' => 7,
        'max' => 90,
        'type' => 'number',
        'required' => true,
        'group' => 'history',
    ],
    'n_estimators' => [
        'label' => 'Random Forest trees',
        'min' => 50,
        'max' => 1000,
        'type' => 'number',
        'required' => true,
        'group' => 'forest',
    ],
    'max_depth' => [
        'label' => 'Maximum tree depth',
        'min' => 2,
        'max' => 60,
        'type' => 'number',
        'required' => true,
        'group' => 'forest',
    ],
    'min_samples_split' => [
        'label' => 'Minimum samples to split',
        'help' => 'Training samples needed to split a tree node.',
        'min' => 2,
        'max' => 50,
        'type' => 'number',
        'required' => true,
        'group' => 'forest',
    ],
    'min_samples_leaf' => [
        'label' => 'Minimum samples per leaf',
        'help' => 'Samples per leaf; no more than the minimum samples to split.',
        'min' => 1,
        'max' => 30,
        'type' => 'number',
        'required' => true,
        'group' => 'forest',
    ],
    'retrain_frequency_days' => [
        'label' => 'Scheduled retraining interval (days)',
        'min' => 1,
        'max' => 90,
        'type' => 'number',
        'required' => true,
        'group' => 'schedule',
    ],
    'retrain_new_sales_records' => [
        'label' => 'New sale items before retraining',
        'help' => 'Count sale-item records, rather than receipts or units sold.',
        'min' => 1,
        'max' => 100000,
        'type' => 'number',
        'required' => true,
        'group' => 'schedule',
    ],
    'accuracy_threshold_wape' => [
        'label' => 'Forecast error warning threshold (WAPE %)',
        'help' => 'Weighted absolute percentage error. Lower values mean less forecast error.',
        'full' => true,
        'min' => 1,
        'max' => 200,
        'step' => '0.1',
        'type' => 'number',
        'required' => true,
        'group' => 'accuracy',
    ],
    'prediction_interval_lower' => [
        'label' => 'Lower demand percentile',
        'min' => 1,
        'max' => 49,
        'type' => 'number',
        'required' => true,
        'group' => 'accuracy',
    ],
    'prediction_interval_upper' => [
        'label' => 'Upper demand percentile',
        'min' => 51,
        'max' => 99,
        'type' => 'number',
        'required' => true,
        'group' => 'accuracy',
    ],
    'holiday_dates' => [
        'label' => 'Additional special dates (optional)',
        'help' => 'Use YYYY-MM-DD dates separated by commas. Leave blank if none.',
        'type' => 'text',
        'required' => false,
        'group' => 'accuracy',
        'full' => true,
        'placeholder' => '2026-11-27, 2026-12-24',
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

    if (isset($updates['minimum_history_days'], $updates['preferred_history_days'])
        && (int)$updates['minimum_history_days'] > (int)$updates['preferred_history_days']
    ) {
        $crossError = 'Minimum calendar history cannot exceed Preferred calendar history.';
        $fieldErrors['minimum_history_days'] = $crossError;
        $errors[] = $crossError;
    }

    if (isset($updates['preferred_history_days'], $updates['history_window_days'])
        && (int)$updates['preferred_history_days'] > (int)$updates['history_window_days']
    ) {
        $crossError = 'Preferred calendar history cannot exceed Maximum training history.';
        $fieldErrors['preferred_history_days'] = $crossError;
        $errors[] = $crossError;
    }

    if (isset($updates['minimum_nonzero_sales_days'], $updates['minimum_history_days'])
        && (int)$updates['minimum_nonzero_sales_days'] > (int)$updates['minimum_history_days']
    ) {
        $crossError = 'Minimum days with sales cannot exceed Minimum calendar history.';
        $fieldErrors['minimum_nonzero_sales_days'] = $crossError;
        $errors[] = $crossError;
    }

    if (isset($updates['min_samples_leaf'], $updates['min_samples_split'])
        && (int)$updates['min_samples_leaf'] > (int)$updates['min_samples_split']
    ) {
        $crossError = 'Minimum samples per leaf cannot exceed Minimum samples to split.';
        $fieldErrors['min_samples_leaf'] = $crossError;
        $errors[] = $crossError;
    }

    if (isset($updates['prediction_interval_lower'], $updates['prediction_interval_upper'])
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
        $message = 'Settings were not saved. Review the highlighted fields below.';
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
<!DOCTYPE html><html lang="en"><head><?php retailmind_theme_head(); ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ML Operation</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . rawurlencode($styleVersion)) ?>"><link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/ml-settings.css')) ?>"></head>
<body class="ml-settings-page"><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
<div class="topbar"><div><h1>ML Operation</h1></div><div class="ml-page-actions"><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/system_administrator/forecast_import.php')) ?>">Import sales history</a><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">Open forecasts</a></div></div>
<?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section class="dashboard-section"><div class="section-header"><div><h2>Forecast settings</h2></div></div><form method="post" novalidate><?= csrf_field() ?>
<?php foreach ($fieldGroups as $groupKey => $groupMeta): ?>
<?php if ($groupKey === 'forest'): ?><details class="ml-tuning"<?= $fieldErrors ? ' open' : '' ?>><summary id="ml-tuning-title"><?= htmlspecialchars($groupMeta['title']) ?></summary><?php endif; ?>
<fieldset class="settings-fieldset"<?= $groupKey === 'forest' ? ' aria-labelledby="ml-tuning-title"' : '' ?>><?php if ($groupKey !== 'forest'): ?><legend><?= htmlspecialchars($groupMeta['title']) ?></legend><?php endif; ?><?php if (isset($groupMeta['description'])): ?><p class="fieldset-description"><?= htmlspecialchars($groupMeta['description']) ?></p><?php endif; ?><div class="form-grid">
<?php foreach ($definitions as $key => $definition):
    if (($definition['group'] ?? '') !== $groupKey) {
        continue;
    }
    $isRequired = !empty($definition['required']);
    $hasError = isset($fieldErrors[$key]);
    $errorId = $key . '_error';
    $helpId = $key . '_help';
    $describedBy = trim((isset($definition['help']) ? $helpId : '') . ($hasError ? ' ' . $errorId : ''));
    $groupClasses = 'form-group' . (!empty($definition['full']) ? ' full' : '') . ($hasError ? ' invalid' : '');
    $inputMode = $definition['type'] === 'number' ? (isset($definition['step']) ? 'decimal' : 'numeric') : null;
?><div class="<?= $groupClasses ?>"><label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($definition['label']) ?></label><input id="<?= htmlspecialchars($key) ?>" name="<?= htmlspecialchars($key) ?>" type="<?= htmlspecialchars($definition['type']) ?>" value="<?= htmlspecialchars((string)($settings[$key] ?? '')) ?>"<?= $inputMode ? ' inputmode="' . $inputMode . '"' : '' ?><?= isset($definition['min']) ? ' min="' . (int)$definition['min'] . '"' : '' ?><?= isset($definition['max']) ? ' max="' . (int)$definition['max'] . '"' : '' ?><?= isset($definition['step']) ? ' step="' . htmlspecialchars((string)$definition['step']) . '"' : '' ?><?= isset($definition['placeholder']) ? ' placeholder="' . htmlspecialchars((string)$definition['placeholder']) . '"' : '' ?> autocomplete="off"<?= $describedBy !== '' ? ' aria-describedby="' . htmlspecialchars($describedBy) . '"' : '' ?><?= $hasError ? ' aria-invalid="true"' : '' ?><?= $isRequired ? ' required' : '' ?>><?php if (isset($definition['help'])): ?><small id="<?= htmlspecialchars($helpId) ?>" class="field-help"><?= htmlspecialchars($definition['help']) ?></small><?php endif; ?><?php if ($hasError): ?><small id="<?= htmlspecialchars($errorId) ?>" class="field-error"><?= htmlspecialchars($fieldErrors[$key]) ?></small><?php endif; ?></div><?php endforeach; ?>
</div></fieldset>
<?php if ($groupKey === 'forest'): ?></details><?php endif; ?>
<?php endforeach; ?>
<div class="form-actions"><button type="submit" class="btn">Save ML settings</button><p class="section-description">Saving does not start retraining. Retrain the model to apply changes.</p></div></form></section>
<section class="dashboard-section"><div class="section-header"><div><h2>Recent training runs</h2><p class="section-description">Latest 12 runs, newest first.</p></div></div><div class="table-wrap"><table><thead><tr><th scope="col">Started</th><th scope="col">Trigger</th><th scope="col">Status</th><th scope="col" class="u-num-col">Duration</th><th scope="col" class="u-num-col">Products</th><th scope="col" class="u-num-col">Sale records</th><th scope="col">Details</th></tr></thead><tbody>
<?php foreach ($runs as $run):
    $statusMeta = $formatRunStatus($run['status'] ?? null);
?><tr><td><?= htmlspecialchars(format_display_datetime($run['started_at'] ?? null)) ?></td><td><?= htmlspecialchars($formatRunTrigger($run['trigger_type'] ?? null)) ?></td><td><span class="<?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span></td><td class="u-num-col"><?= $run['duration_seconds'] !== null ? number_format((float)$run['duration_seconds'], 2) . ' s' : '-' ?></td><td class="u-num-col"><?= number_format((int)($run['eligible_products'] ?? 0)) ?></td><td class="u-num-col"><?= number_format((int)($run['sales_records_used'] ?? 0)) ?></td><td class="table-cell-note"><?= htmlspecialchars($formatRunDetails($run['error_message'] ?? null)) ?></td></tr><?php endforeach; ?>
<?php if (!$runs): ?><tr><td colspan="7" class="u-empty-cell-padded u-text-center">No training runs yet. Import sales history if needed, then run a model training job.</td></tr><?php endif; ?></tbody></table></div></section>
</main></div></body></html>
