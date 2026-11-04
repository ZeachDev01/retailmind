<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::CONFIGURE_MODELS);

$message = '';
$messageClass = '';
$fieldErrors = [];
$fieldGroups = [
    'accuracy' => [
        'title' => 'Candidate error limits',
        'description' => 'WAPE measures prediction error: lower is better. Each horizon must meet its limit and strictly beat repeating last week’s sales. Missing evidence blocks qualification.',
    ],
];
$definitions = [];
foreach ([7, 14, 28] as $horizon) {
    $definitions["ml_max_wape_{$horizon}d"] = [
        'label' => "{$horizon}-day maximum WAPE (%)",
        'help' => 'Allowed error from 0% to 200%. This is not an accuracy guarantee.',
        'min' => 0, 'max' => 200, 'step' => '0.1', 'type' => 'number',
        'required' => true, 'group' => 'accuracy',
    ];
}
$governance = new \App\Forecasting\StoreModelGovernanceService($pdo);
$loadLimits = static function () use ($governance): array {
    $values = [];
    foreach ($governance->getThresholds() as $horizon => $fraction) {
        $values["ml_max_wape_{$horizon}d"] = $fraction === null ? '' : (string)($fraction * 100);
    }
    return $values;
};

$settings = $loadLimits();
$scheduler = new \App\Forecasting\ForecastingSchedulerService($pdo);
$effectiveSchedule = $scheduler->getEffectiveSchedule();
$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$trainingTime = DateTimeImmutable::createFromFormat('!H:i', $effectiveSchedule['weekly_retrain_time']);
$scheduleFields = [
    'schedule_weekly_retrain_day' => $effectiveSchedule['weekly_retrain_day'],
    'schedule_hour' => $trainingTime->format('g'),
    'schedule_minute' => $trainingTime->format('i'),
    'schedule_period' => $trainingTime->format('A'),
];
$scheduleErrors = [];
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_schedule') {
    csrf_verify();
    foreach ($scheduleFields as $key => $value) {
        $scheduleFields[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    if (!in_array($scheduleFields['schedule_weekly_retrain_day'], $weekdays, true)) {
        $scheduleErrors['schedule_weekly_retrain_day'] = 'Select a weekday from Sunday through Saturday.';
    }
    if (!preg_match('/^(?:[1-9]|1[0-2])$/D', $scheduleFields['schedule_hour'])
        || !preg_match('/^[0-5][0-9]$/D', $scheduleFields['schedule_minute'])
        || !in_array($scheduleFields['schedule_period'], ['AM', 'PM'], true)) {
        $scheduleErrors['schedule_time'] = 'Select a time from 12:00 AM to 11:59 PM.';
    }
    if (!$scheduleErrors) {
        $hour = (int)$scheduleFields['schedule_hour'] % 12 + ($scheduleFields['schedule_period'] === 'PM' ? 12 : 0);
        $scheduleUpdates = [
            'schedule_weekly_retrain_day' => $scheduleFields['schedule_weekly_retrain_day'],
            'schedule_weekly_retrain_time' => sprintf('%02d:%s', $hour, $scheduleFields['schedule_minute']),
        ];
        try {
            $pdo->beginTransaction();
            $saveSchedule = $pdo->prepare('INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)');
            foreach ($scheduleUpdates as $key => $value) {
                $saveSchedule->execute([$key, $value, (int)$_SESSION['user_id']]);
            }
            $pdo->exec("DELETE FROM ml_settings WHERE setting_key IN ('schedule_weekly_retrain_day', 'schedule_weekly_retrain_time')");
            log_activity($pdo, (int)$_SESSION['user_id'], 'ML schedule update', 'Demand Forecasting', null, null, $scheduleUpdates);
            $pdo->commit();
            $effectiveSchedule = $scheduler->getEffectiveSchedule();
            $message = 'Weekly schedule saved. Training starts when due, subject to eligible history and worker availability. No training was queued or model approved by this save.';
            $messageClass = 'tag-success';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = \App\Support\OperatorAlert::message($e, 'Could not save the weekly schedule. Retry.');
            $messageClass = 'tag-warning';
        }
    } else {
        $message = 'Schedule was not saved. Review the highlighted fields below.';
        $messageClass = 'tag-warning';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_model_quality') {
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
            if (!$numericValid || !is_finite((float)$value) || (float)$value < $definition['min'] || (float)$value > $definition['max']) {
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
        }
        $updates[$key] = $value;
    }

    if (!$errors) {
        $governance->setThresholds(array_map(static fn($value): float => (float)$value / 100, $updates), (int)$_SESSION['user_id']);
        log_activity($pdo, (int)$_SESSION['user_id'], 'ML settings update', 'Demand Forecasting', null, null, $updates);
        $settings = $loadLimits();
        $message = 'Forecast settings saved. Training Eligibility remains fixed; saving does not train or approve a model.';
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
$candidateObsProgress = null;
if ($latestCandidate) {
    try {
        $candidateObsProgress = (new \App\Forecasting\CandidateEvaluationService($pdo))
            ->getCandidateObservationProgress($latestCandidate['model_version']);
    } catch (Throwable) {}
}
$approvedVersion = (string)($pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='ml_approved_model_version'")->fetchColumn() ?: '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Model Training</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css') . '?v=' . rawurlencode($styleVersion)) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/ml-settings.css')) ?>">
    <?php require_once __DIR__ . '/../report/forecast_ui.php'; ?>
</head>
<body class="forecast-workspace ml-settings-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1>Model Training</h1>
                <p class="page-subtitle">Prepare a new model for Administrator review.</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($messageClass) ?>" role="<?= $messageClass === 'tag-success' ? 'status' : 'alert' ?>" aria-live="polite">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php forecast_workflow('train'); ?>
        <?php
        if ($isTraining) {
            forecast_status_summary('Training ' . (($trainingJob['status'] ?? '') === 'queued' ? 'queued' : 'in progress'), 'Results are not ready yet.', 'Wait for this job to finish. This page refreshes automatically.');
        } elseif (($trainingJob['status'] ?? '') === 'failed') {
            forecast_status_summary('Latest training attempt failed', 'The worker could not complete the latest job.', 'Review the training history and worker status before retrying. Any earlier model keeps its existing approval status.');
        } elseif (!$latestCandidate) {
            forecast_status_summary('No trained model yet', 'There are no completed training results to review.', 'Check sales data first, then train a candidate.', app_url('components/report/data_readiness.php'), 'Check sales data');
        } elseif ($approvedVersion === $latestCandidate['model_version']) {
            forecast_status_summary($isLocalDemo ? 'Demo model approved' : 'Latest model is selected', 'Approval alone does not confirm today’s product coverage.', 'Check Demand Forecast for usable expected sales.', app_url('components/report/predictions.php'), 'See forecast status');
        } elseif (empty($candidateQuality['passed'])) {
            forecast_status_summary('Training finished. Quality checks did not pass.', 'The model has not met the required prediction-error checks. Approval is blocked.', 'Review the accuracy results below and check the sales data before training again.');
        } elseif (!$isLocalDemo && empty($candidateObsProgress['observation_complete'])) {
            forecast_status_summary('Quality checks passed. Observation is pending.', 'The model still needs future predictions checked against actual Store sales.', 'Complete observation, then ask the Administrator to review the model.');
        } else {
            forecast_status_summary('Quality checks passed. Approval is still required.', 'Training does not give permission to use the model.', $isLocalDemo ? 'Review the results before approving this Demo model. Store approval is separate.' : 'Ask the Administrator to check the remaining approval requirements.');
        }
        ?>

        <section class="dashboard-section" aria-labelledby="training-title">
            <div class="section-header">
                <div>
                    <h2 id="training-title"><i class="bi bi-cpu" aria-hidden="true"></i> Candidate model &amp; training <?php forecast_help('training-scope-help', 'Training creates a candidate. It does not replace the approved model.', 'About candidate training'); ?></h2>
                </div>
            </div>

            <?php if ($isLocalDemo): ?>
                <div class="ml-banner ml-banner-info" role="note">
                    Isolated Demo: <strong><?= htmlspecialchars(json_decode($demoMetadata, true)['source_label'] ?? 'S001') ?></strong>. Approval here enables only this Demo environment.
                </div>
            <?php endif; ?>

            <?php if (($isLocalDemo || $isHostedReplay) && !empty(json_decode($demoMetadata, true)['replay'])): ?>
                <div class="ml-banner tag-warning" role="note">
                    Only train.csv was loaded for fitting. Test sales were excluded.
                    <?php if ($isHostedReplay): ?>Hosted snapshot: train a fresh replay locally to produce new results. <?php endif; ?>
                    <a href="../report/forecast_replay.php">Open the train/test replay and stock preview</a>.
                </div>
            <?php endif; ?>

            <div class="ml-training-panel">
            <div class="ml-action-bar">
                <form class="ml-action-form" method="post">
                    <?= csrf_field() ?>
                    <button class="btn" type="submit" name="action" value="train" <?= $isTraining || $isHostedReplay ? 'disabled' : '' ?>>
                        <?= $isTraining ? 'Training in progress...' : 'Train candidate model' ?>
                    </button>
                </form>
                <div class="ml-job-status" role="status" aria-live="polite">
                    <span>Latest training job</span>
                    <strong><?= htmlspecialchars($trainingJob ? '#' . $trainingJob['job_id'] . ' · ' . ucfirst($trainingJob['status']) : 'Not started') ?></strong>
                </div>
            </div>

            <?php if ($latestCandidate): ?>
                <div class="candidate-card">
                    <details class="forecast-details"><summary>Candidate data &amp; model version</summary>
                    <div class="candidate-meta-grid">
                        <div class="candidate-meta-item">
                            <span class="candidate-meta-label">Model version</span>
                            <span class="candidate-meta-val"><code><?= htmlspecialchars($latestCandidate['model_version']) ?></code></span>
                        </div>
                        <div class="candidate-meta-item">
                            <span class="candidate-meta-label">Daily observations</span>
                            <span class="candidate-meta-val"><?= number_format((int)($candidateEvidence['metadata']['observations'] ?? 0)) ?></span>
                        </div>
                        <div class="candidate-meta-item">
                            <span class="candidate-meta-label">Products</span>
                            <span class="candidate-meta-val"><?= number_format(count($candidateEvidence['metadata']['products'] ?? [])) ?></span>
                        </div>
                        <div class="candidate-meta-item">
                            <span class="candidate-meta-label">Data window</span>
                            <span class="candidate-meta-val"><?= htmlspecialchars($candidateEvidence['metadata']['data_window_start'] ?? '—') ?> to <?= htmlspecialchars($candidateEvidence['metadata']['data_window_end'] ?? '—') ?></span>
                        </div>
                    </div>
                    </details>

                    <details class="forecast-details"><summary>Accuracy results &amp; observation details</summary>
                    <div class="table-wrap">
                        <table aria-label="Candidate evaluation metrics">
                            <thead>
                                <tr>
                                    <th scope="col">Forecast horizon</th>
                                    <th scope="col" class="u-num-col">Model error (WAPE)</th>
                                    <th scope="col" class="u-num-col">Baseline error (WAPE)</th>
                                    <th scope="col">Quality check</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (($candidateQuality['horizons'] ?? []) as $evaluation): ?>
                                <tr>
                                    <td><?= (int)$evaluation['horizon_days'] ?> days</td>
                                    <td class="u-num-col"><?= $evaluation['wape'] === null ? 'Unknown' : number_format((float)$evaluation['wape'] * 100, 2) . '%' ?></td>
                                    <td class="u-num-col"><?= $evaluation['baseline_wape'] === null ? 'Unknown' : number_format((float)$evaluation['baseline_wape'] * 100, 2) . '%' ?></td>
                                    <td><?= htmlspecialchars($evaluation['gate_status'] ?? $evaluation['candidate_gate_outcome']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($candidateQuality['reason'])): ?>
                        <p class="ml-eval-reason">WAPE measures error (lower is better). <?= htmlspecialchars($candidateQuality['reason']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($candidateObsProgress)): ?>
                        <p class="ml-eval-meta"><strong>Observation progress:</strong> <?= htmlspecialchars($candidateObsProgress['status_label'] ?? 'Under evaluation') ?> (<?= (int)($candidateObsProgress['usable_observed_days'] ?? 0) ?> of <?= (int)($candidateObsProgress['required_observed_days'] ?? 28) ?> usable target dates completed).</p>
                        <?php if (!empty($candidateObsProgress['explanation']) && empty($candidateObsProgress['observation_complete'])): ?>
                            <p class="ml-eval-reason"><?= htmlspecialchars($candidateObsProgress['explanation']) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>

                    </details>
                    <?php if ($isLocalDemo): ?>
                        <p class="ml-eval-meta">Training recipe: <?= (int)($candidateEvidence['metadata']['parameters']['n_estimators'] ?? 0) ?> trees, max depth <?= (int)($candidateEvidence['metadata']['parameters']['max_depth'] ?? 0) ?>, 28 daily forecast targets per product.</p>
                        <form method="post" class="ml-action-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="model_version" value="<?= htmlspecialchars($latestCandidate['model_version']) ?>">
                            <button class="btn" name="action" value="approve_demo" <?= $isTraining || empty($candidateQuality['passed']) || $approvedVersion === $latestCandidate['model_version'] ? 'disabled' : '' ?>>
                                <?= $approvedVersion === $latestCandidate['model_version'] ? 'Demo model approved' : 'Approve demo model' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                    <div class="ml-empty-state">
                        <i class="bi bi-cpu" aria-hidden="true"></i>
                        <div>
                            <strong>No candidate model yet</strong>
                            <p>Import product-level history, verify worker status, then train a candidate for review.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="ml-section-links">
                    <a class="btn btn-secondary" href="../report/forecast_analytics.php">Forecast analytics</a>
                </div>
                </div>
        </section>

        <section class="dashboard-section" aria-labelledby="schedule-heading">
            <div class="section-header"><div>
                <h2 id="schedule-heading">Weekly Model Retraining</h2>
                <p id="effective-training-schedule" class="section-description">Every <?= htmlspecialchars($effectiveSchedule['weekly_retrain_day']) ?> at <?= htmlspecialchars(DateTimeImmutable::createFromFormat('!H:i', $effectiveSchedule['weekly_retrain_time'])->format('g:i A')) ?>, Philippine time (Asia/Manila).</p>
            </div></div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_schedule">
                <div class="form-grid">
                    <div class="form-group<?= isset($scheduleErrors['schedule_weekly_retrain_day']) ? ' invalid' : '' ?>">
                        <label for="schedule_weekly_retrain_day">Training weekday</label>
                        <select id="schedule_weekly_retrain_day" name="schedule_weekly_retrain_day" required<?= isset($scheduleErrors['schedule_weekly_retrain_day']) ? ' aria-invalid="true" aria-describedby="schedule_day_error"' : '' ?>>
                            <?php foreach ($weekdays as $day): ?>
                                <option value="<?= $day ?>"<?= $scheduleFields['schedule_weekly_retrain_day'] === $day ? ' selected' : '' ?>><?= $day ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($scheduleErrors['schedule_weekly_retrain_day'])): ?><small id="schedule_day_error" class="field-error"><?= htmlspecialchars($scheduleErrors['schedule_weekly_retrain_day']) ?></small><?php endif; ?>
                    </div>
                    <?php foreach ([
                        'schedule_hour' => ['Training hour', range(1, 12)],
                        'schedule_minute' => ['Training minute', range(0, 59)],
                        'schedule_period' => ['AM or PM', ['AM', 'PM']],
                    ] as $key => [$label, $options]): ?>
                        <div class="form-group<?= isset($scheduleErrors['schedule_time']) ? ' invalid' : '' ?>">
                            <div class="forecast-field-label">
                                <label for="<?= $key ?>"><?= $label ?></label>
                                <?php if ($key === 'schedule_hour'): ?>
                                    <?php forecast_help('schedule_time_help', 'Select a time from 12:00 AM to 11:59 PM, Philippine time (Asia/Manila). Prefer a time after Store close. Missed training runs catch up when the PC starts.', 'About training time'); ?>
                                <?php endif; ?>
                            </div>
                            <select id="<?= $key ?>" name="<?= $key ?>" required aria-describedby="schedule_time_help<?= isset($scheduleErrors['schedule_time']) ? ' schedule_time_error' : '' ?>"<?= isset($scheduleErrors['schedule_time']) ? ' aria-invalid="true"' : '' ?>>
                                <?php foreach ($options as $option): $value = $key === 'schedule_minute' ? sprintf('%02d', $option) : (string)$option; ?>
                                    <option value="<?= $value ?>"<?= $scheduleFields[$key] === $value ? ' selected' : '' ?>><?= $value ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($scheduleErrors['schedule_time'])): ?><small id="schedule_time_error" class="field-error"><?= htmlspecialchars($scheduleErrors['schedule_time']) ?></small><?php endif; ?>
                <div class="form-actions">
                    <button class="btn" type="submit">Save weekly schedule</button>
                    <p class="section-description">Daily Forecast Generation remains separate at <?= htmlspecialchars(DateTimeImmutable::createFromFormat('!H:i', $effectiveSchedule['daily_forecast_time'])->format('g:i A')) ?>, Philippine time (Asia/Manila).</p>
                </div>
            </form>
        </section>
        <section class="ml-secondary"><details class="forecast-details"><summary>Training eligibility &amp; product history</summary>
            <?php require __DIR__ . '/../report/training_eligibility_policy.php'; ?>
            <details>
                <summary>Training Eligibility by Product</summary>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Product Training Eligibility">
                    <table class="data-table">
                        <thead><tr><th scope="col">Product</th><th scope="col">Usable days collected</th><th scope="col">Evidence and next steps</th></tr></thead>
                        <tbody>
                        <?php foreach (get_forecasting_readiness($pdo) as $readiness): ?>
                            <tr>
                                <td><?= htmlspecialchars($readiness['product_name']) ?></td>
                                <td><?= (int)$readiness['accumulated_usable_days'] ?> / <?= (int)$readiness['required_usable_days'] ?> usable days<br>
                                    <?= (int)$readiness['calendar_history_days'] ?> calendar days; <?= (int)$readiness['days_with_sales'] ?> days with sales; <?= (int)$readiness['genuine_zero_days'] ?> genuine zero days</td>
                                <td><strong><?= $readiness['training_eligible'] ? 'Ready for training' : 'Insufficient Data' ?></strong>
                                    <?php foreach (array_merge($readiness['eligibility_blockers'], $readiness['eligibility_actions']) as $detail): ?>
                                        <p><?= htmlspecialchars($detail) ?></p>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </details></section>

        <section class="ml-secondary" aria-label="Effective model recipe">
            <details class="forecast-details"><summary>Fixed model recipe &amp; approval requirements</summary>
            <p>Shared Random Forest: 15 trees, maximum depth 8, random seed 42.</p>
            <p>Forecast Horizons: 7, 14 and 28 days. Forecasts are point quantities; prediction intervals are unavailable.</p>
            <p>Training completion does not qualify a candidate. Chronological quality checks, required observation evidence,
                compatible Store artifacts and explicit local Administrator approval remain required.
                Failed or rejected candidates leave the approved model unchanged. Demo review never activates a Store model.</p>
            </details>
        </section>

        <?php if (!$isLocalDemo): ?>
        <section class="ml-secondary" aria-labelledby="settings-heading"><details class="forecast-details"<?= $fieldErrors ? ' open' : '' ?>><summary id="settings-heading">Forecast &amp; model parameters</summary>
            <div class="section-header">
                <div>

                    <p class="section-description">Review forecasting settings. Training Eligibility uses the fixed policy above.</p>
                </div>
            </div>
            <form id="model-quality-settings" method="post" novalidate>
                <input type="hidden" name="action" value="save_model_quality">
                <?= csrf_field() ?>
                <?php foreach ($fieldGroups as $groupKey => $groupMeta): ?>
                    <fieldset class="settings-fieldset">
                        <legend><?= htmlspecialchars($groupMeta['title']) ?></legend>
                        <?php if (isset($groupMeta['description'])): ?>
                            <?php forecast_help('group-' . $groupKey, $groupMeta['description'], 'About ' . $groupMeta['title']); ?>
                        <?php endif; ?>
                        <div class="form-grid">
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
                            ?>
                                <div class="<?= $groupClasses ?>">
                                    <div class="forecast-field-label">
                                        <label for="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($definition['label']) ?></label>
                                        <?php if (isset($definition['help'])): ?>
                                            <?php forecast_help($helpId, $definition['help'], 'About ' . $definition['label']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <input id="<?= htmlspecialchars($key) ?>"
                                           name="<?= htmlspecialchars($key) ?>"
                                           type="<?= htmlspecialchars($definition['type']) ?>"
                                           value="<?= htmlspecialchars((string)($settings[$key] ?? '')) ?>"
                                           <?= $inputMode ? ' inputmode="' . $inputMode . '"' : '' ?>
                                           <?= isset($definition['min']) ? ' min="' . (int)$definition['min'] . '"' : '' ?>
                                           <?= isset($definition['max']) ? ' max="' . (int)$definition['max'] . '"' : '' ?>
                                           <?= isset($definition['step']) ? ' step="' . htmlspecialchars((string)$definition['step']) . '"' : '' ?>
                                           <?= isset($definition['placeholder']) ? ' placeholder="' . htmlspecialchars((string)$definition['placeholder']) . '"' : '' ?>
                                           autocomplete="off"
                                           <?= $describedBy !== '' ? ' aria-describedby="' . htmlspecialchars($describedBy) . '"' : '' ?>
                                           <?= $hasError ? ' aria-invalid="true"' : '' ?>
                                           <?= $isRequired ? ' required' : '' ?>>
                                    <?php if ($hasError): ?>
                                        <small id="<?= htmlspecialchars($errorId) ?>" class="field-error"><?= htmlspecialchars($fieldErrors[$key]) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                <?php endforeach; ?>

                <div class="form-actions">
                    <button type="submit" class="btn">Save settings</button>
                    <p class="section-description">Saved limits are used when candidate quality is evaluated. Saving does not train or approve a model.</p>
                </div>
            </form>
        </details></section>
        <?php endif; ?>

        <section class="ml-secondary" aria-labelledby="runs-heading">
<details class="forecast-details"><summary id="runs-heading">Recent training runs</summary>
            <div class="section-header">
                <div>

                    <p class="section-description">Latest 12 training runs, newest first.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table aria-label="Recent training runs log">
                    <thead>
                        <tr>
                            <th scope="col">Started</th>
                            <th scope="col">Trigger</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="u-num-col">Duration</th>
                            <th scope="col" class="u-num-col">Products</th>
                            <th scope="col" class="u-num-col">Sale records</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($runs as $run):
                        $statusMeta = $formatRunStatus($run['status'] ?? null);
                    ?>
                        <tr>
                            <td><?= htmlspecialchars(format_display_datetime($run['started_at'] ?? null)) ?></td>
                            <td><?= htmlspecialchars($formatRunTrigger($run['trigger_type'] ?? null)) ?></td>
                            <td><span class="<?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span></td>
                            <td class="u-num-col"><?= $run['duration_seconds'] !== null ? number_format((float)$run['duration_seconds'], 2) . ' s' : '-' ?></td>
                            <td class="u-num-col"><?= number_format((int)($run['eligible_products'] ?? 0)) ?></td>
                            <td class="u-num-col"><?= number_format((int)($run['sales_records_used'] ?? 0)) ?></td>
                            <td class="table-cell-note"><?= htmlspecialchars($formatRunDetails($run['error_message'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$runs): ?>
                        <tr><td colspan="7" class="u-empty-cell-padded u-text-center">No training runs yet. Import sales history if needed, then run a model training job.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </details>
</section>
    </main>
</div>
<?php if ($isTraining): ?>
    <script>setTimeout(() => window.location.replace(window.location.href), 5000);</script>
<?php endif; ?>
</body>
</html>
