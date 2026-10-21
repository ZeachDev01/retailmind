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
$demoMetadata = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'")->fetchColumn();
$demoDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$isLocalDemo = $demoMetadata !== false
    && preg_match('/^retailmind_demo_[a-z0-9_]+$/D', $demoDatabase)
    && in_array(strtolower((string)env('APP_ENV')), ['development', 'test'], true)
    && in_array((string)env('DB_HOST'), ['localhost', '127.0.0.1', '::1'], true);
$isHostedReplay = !$isLocalDemo
    && \App\Forecasting\ReplenishmentQueueService::replayDatabaseAllowed($demoDatabase)
    && !empty(json_decode((string)$demoMetadata, true)['replay']);
$latestCandidate = $pdo->query("SELECT model_version, metrics_json FROM model_training_runs
    WHERE status='completed' ORDER BY training_run_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$trainingJob = $pdo->query("SELECT job_id,status,operator_message FROM ml_jobs
    WHERE trigger_type='model_retraining' ORDER BY job_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$isTraining = in_array($trainingJob['status'] ?? '', ['queued', 'running'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['train', 'approve_demo'], true)) {
    csrf_verify();
    try {
        if ($_POST['action'] === 'train') {
            if ($isHostedReplay) {
                throw new RuntimeException('This hosted replay contains saved results. Train a fresh replay locally, then import its snapshot.');
            }
            require_capability(\App\Authorization\RoleCapabilityPolicy::RETRAIN_MODELS);
            if ($isTraining) throw new RuntimeException('A training job is already queued or running.');
            $jobId = (new \App\Services\MlJobService($pdo))->enqueue('model_retraining',
                (int)$_SESSION['user_id'], current_role(), $_SERVER['REMOTE_ADDR'] ?? null);
            $trainingJob = ['job_id' => $jobId, 'status' => 'queued'];
            $isTraining = true;
            $message = 'Training queued. This page refreshes while the background worker trains and evaluates the candidate.';
        } else {
            require_capability(\App\Authorization\RoleCapabilityPolicy::APPROVE_MODELS);
            $version = (string)($_POST['model_version'] ?? '');
            $candidateMetadata = json_decode($latestCandidate['metrics_json'] ?? '{}', true)['metadata'] ?? [];
            if (!$isLocalDemo || $isTraining || !str_starts_with($version, 'demo_')
                || $version !== ($latestCandidate['model_version'] ?? '')
                || ($candidateMetadata['dataset_kind'] ?? '') !== 'demo'
                || ($candidateMetadata['dataset_database'] ?? '') !== $demoDatabase) {
                throw new RuntimeException('Review the latest local Demo candidate before approving it. Store activation is separate.');
            }
            $evaluation = new \App\Forecasting\CandidateEvaluationService($pdo);
            (new \App\Forecasting\ModelPromotionService($pdo, $evaluation))->promote($version, (int)$_SESSION['user_id']);
            $evaluation->setObservationMode(false, (int)$_SESSION['user_id']);
            $evaluation->setRecommendationsSuspended(false, (int)$_SESSION['user_id']);
            $message = 'Demo model approved. Open forecasts and select Generate Now. Store forecasting was not activated.';
        }
        $messageClass = 'tag-success';
    } catch (Throwable $e) {
        $message = \App\Support\OperatorAlert::message($e, 'Could not complete the model action. Review the candidate and worker status, then retry.');
        $messageClass = 'tag-warning';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['action'] ?? '', ['train', 'approve_demo'], true)) {
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
$candidateEvidence = json_decode($latestCandidate['metrics_json'] ?? '{}', true);
$candidateQuality = $latestCandidate
    ? (new \App\Forecasting\CandidateEvaluationService($pdo))->evaluateCandidateQuality($latestCandidate['model_version']) : null;
$approvedVersion = (string)($pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='ml_approved_model_version'")->fetchColumn() ?: '');
?>
<!DOCTYPE html><html lang="en"><head><?php retailmind_theme_head(); ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ML Operation</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . rawurlencode($styleVersion)) ?>"><link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/ml-settings.css')) ?>"></head>
<body class="ml-settings-page"><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
<div class="topbar"><div><h1>ML Operation</h1></div><div class="ml-page-actions"><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/system_administrator/forecast_import.php')) ?>">Import sales history</a><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">Open forecasts</a></div></div>
<?php if ($message): ?><div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section class="dashboard-section" aria-labelledby="training-title">
    <div class="section-header"><div><h2 id="training-title">Train and review a candidate</h2>
        <p class="section-description">Training tests a new model against later sales and the repeating-week baseline. It does not replace the approved model.</p></div></div>
    <?php if ($isLocalDemo): ?><p>Isolated Demo: <?= htmlspecialchars(json_decode($demoMetadata, true)['source_label'] ?? 'S001') ?>. Approval here enables only this Demo, never Store forecasting.</p><?php endif; ?>
    <?php if (($isLocalDemo || $isHostedReplay) && !empty(json_decode($demoMetadata, true)['replay'])): ?><p class="tag-warning">Only train.csv was loaded for fitting. Test sales were excluded. <?php if ($isHostedReplay): ?>Hosted snapshot: train a fresh replay locally to produce new results. <?php endif; ?><a href="../report/forecast_replay.php">Open the train/test replay and stock preview</a>.</p><?php endif; ?>
    <form method="post"><?= csrf_field() ?><button class="btn" type="submit" name="action" value="train" <?= $isTraining || $isHostedReplay ? 'disabled' : '' ?>><?= $isTraining ? 'Training in progress' : 'Train model' ?></button></form>
    <p role="status">Latest training job: <?= htmlspecialchars($trainingJob ? '#' . $trainingJob['job_id'] . ' — ' . $trainingJob['status'] : 'Not started') ?>.</p>
    <?php if ($latestCandidate): ?>
        <p>Candidate: <code><?= htmlspecialchars($latestCandidate['model_version']) ?></code>.
            <?= (int)($candidateEvidence['metadata']['observations'] ?? 0) ?> daily observations;
            <?= count($candidateEvidence['metadata']['products'] ?? []) ?> products.
            <?= htmlspecialchars($candidateEvidence['metadata']['data_window_start'] ?? '') ?> to <?= htmlspecialchars($candidateEvidence['metadata']['data_window_end'] ?? '') ?>.</p>
        <div class="table-wrap"><table aria-label="Candidate evaluation"><thead><tr><th>Horizon</th><th>Model WAPE</th><th>Baseline WAPE</th><th>Quality gate</th></tr></thead><tbody>
        <?php foreach (($candidateQuality['horizons'] ?? []) as $evaluation): ?>
            <tr><td><?= (int)$evaluation['horizon_days'] ?> days</td><td><?= $evaluation['wape'] === null ? 'Unknown' : number_format((float)$evaluation['wape'] * 100, 2) . '%' ?></td><td><?= $evaluation['baseline_wape'] === null ? 'Unknown' : number_format((float)$evaluation['baseline_wape'] * 100, 2) . '%' ?></td><td><?= htmlspecialchars($evaluation['gate_status'] ?? $evaluation['candidate_gate_outcome']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <p>WAPE measures error; lower is better. <?= htmlspecialchars($candidateQuality['reason'] ?? '') ?></p>
        <?php if ($isLocalDemo): ?><p>Training recipe: <?= (int)($candidateEvidence['metadata']['parameters']['n_estimators'] ?? 0) ?> trees,
            maximum depth <?= (int)($candidateEvidence['metadata']['parameters']['max_depth'] ?? 0) ?>;
            28 daily forecast targets per product. The shared pipeline fixes these settings for this experiment.</p><?php endif; ?>
        <?php if ($isLocalDemo): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="model_version" value="<?= htmlspecialchars($latestCandidate['model_version']) ?>"><button class="btn" name="action" value="approve_demo" <?= $isTraining || empty($candidateQuality['passed']) || $approvedVersion === $latestCandidate['model_version'] ? 'disabled' : '' ?>><?= $approvedVersion === $latestCandidate['model_version'] ? 'Demo model approved' : 'Approve demo model' ?></button></form>
        <?php endif; ?>
    <?php else: ?><p>No candidate yet. Import product-level history, start the background worker, then train.</p><?php endif; ?>
    <p><a class="btn btn-secondary" href="../report/forecast_analytics.php">Review forecast analytics</a> <a class="btn btn-secondary" href="../report/predictions.php">Open forecasts</a></p>
</section>
<?php if (!$isLocalDemo): ?>
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
<?php endif; ?>
<section class="dashboard-section"><div class="section-header"><div><h2>Recent training runs</h2><p class="section-description">Latest 12 runs, newest first.</p></div></div><div class="table-wrap"><table><thead><tr><th scope="col">Started</th><th scope="col">Trigger</th><th scope="col">Status</th><th scope="col" class="u-num-col">Duration</th><th scope="col" class="u-num-col">Products</th><th scope="col" class="u-num-col">Sale records</th><th scope="col">Details</th></tr></thead><tbody>
<?php foreach ($runs as $run):
    $statusMeta = $formatRunStatus($run['status'] ?? null);
?><tr><td><?= htmlspecialchars(format_display_datetime($run['started_at'] ?? null)) ?></td><td><?= htmlspecialchars($formatRunTrigger($run['trigger_type'] ?? null)) ?></td><td><span class="<?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span></td><td class="u-num-col"><?= $run['duration_seconds'] !== null ? number_format((float)$run['duration_seconds'], 2) . ' s' : '-' ?></td><td class="u-num-col"><?= number_format((int)($run['eligible_products'] ?? 0)) ?></td><td class="u-num-col"><?= number_format((int)($run['sales_records_used'] ?? 0)) ?></td><td class="table-cell-note"><?= htmlspecialchars($formatRunDetails($run['error_message'] ?? null)) ?></td></tr><?php endforeach; ?>
<?php if (!$runs): ?><tr><td colspan="7" class="u-empty-cell-padded u-text-center">No training runs yet. Import sales history if needed, then run a model training job.</td></tr><?php endif; ?></tbody></table></div></section>
</main></div><?php if ($isTraining): ?><script>setTimeout(() => window.location.replace(window.location.href), 5000);</script><?php endif; ?></body></html>
