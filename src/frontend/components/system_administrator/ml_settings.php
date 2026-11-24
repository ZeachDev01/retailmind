<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_capability(\App\Authorization\RoleCapabilityPolicy::CONFIGURE_TRAINING_SETTINGS);

$message = '';
$messageClass = '';
$governance = new \App\Forecasting\StoreModelGovernanceService($pdo);
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
$jobService = new \App\Services\MlJobService($pdo);
$trainingJobs = $jobService->getRecentTrainingJobs();
$trainingJob = $trainingJobs[0] ?? null;
$isTraining = in_array($trainingJob['status'] ?? '', ['queued', 'running'], true);
$trainingReadiness = get_forecasting_readiness($pdo);
$eligibleTrainingProducts = array_values(array_filter(
    $trainingReadiness,
    static fn(array $item): bool => !empty($item['training_eligible'])
));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['train', 'approve_demo'], true)) {
    csrf_verify();
    try {
        if ($_POST['action'] === 'train') {
            if ($isHostedReplay) {
                throw new RuntimeException('This hosted replay contains saved results. Train a fresh replay locally, then import its snapshot.');
            }
            require_capability(\App\Authorization\RoleCapabilityPolicy::RETRAIN_MODELS);
            $jobId = $jobService->enqueue('model_retraining',
                (int)$_SESSION['user_id'], current_role(), $_SERVER['REMOTE_ADDR'] ?? null);
            $trainingJob = $jobService->getJobStatus($jobId);
            $trainingJobs = $jobService->getRecentTrainingJobs();
            $isTraining = true;
            $message = 'Training queued. Job #' . $jobId . '. This page refreshes while the background worker trains and evaluates the candidate.';
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
            (new \App\Forecasting\StoreModelGovernanceService($pdo, $evaluation))->promote($version, (int)$_SESSION['user_id']);
            $governance->setObservationMode(false, (int)$_SESSION['user_id']);
            $governance->setRecommendationsSuspended(false, (int)$_SESSION['user_id']);
            $message = 'Demo model approved. Open forecasts and select Generate Now. Store forecasting was not activated.';
        }
        $messageClass = 'tag-success';
    } catch (Throwable $e) {
        $message = \App\Support\OperatorAlert::message($e, 'Could not complete the model action. Review the candidate and worker status, then retry.');
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
    return 'Review the job diagnostic reference before retrying.';
};

$historyView = is_string($_GET['history'] ?? null) && $_GET['history'] === 'runs' ? 'runs' : 'jobs';

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
            </div>
            <div class="ml-page-actions"><a class="btn btn-secondary" href="training_configuration.php">Configure</a></div>
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
            forecast_status_summary('No trained model yet', 'There are no completed training results to review.', 'Check which Products meet the training data requirements.', app_url('components/report/data_readiness.php'), 'Check training readiness');
        } elseif ($approvedVersion === $latestCandidate['model_version']) {
            forecast_status_summary($isLocalDemo ? 'Demo model approved' : 'Latest model is selected', 'Approval alone does not confirm todayâ€™s product coverage.', 'Check Demand Forecast for usable expected sales.', app_url('components/report/predictions.php'), 'See forecast status');
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
                    <h2 id="training-title"><i class="bi bi-cpu" aria-hidden="true"></i> Training overview <?php forecast_help('training-scope-help', 'Training creates a candidate. It does not replace the approved model.', 'About candidate training'); ?></h2>
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
                    <div class="ml-readiness" aria-label="Training readiness">
                        <div class="ml-setting-heading">
                            <h3><?= $isHostedReplay ? 'Saved replay' : ($isLocalDemo ? 'Demo training' : ($eligibleTrainingProducts === [] ? 'Not ready to train' : 'Ready to train')) ?></h3>
                            <?php
                            ob_start();
                            require __DIR__ . '/../report/training_eligibility_policy.php';
                            $eligibilityHelp = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(ob_get_clean()), ENT_QUOTES, 'UTF-8')));
                            forecast_help('overview-training-requirements', $eligibilityHelp, 'About training requirements');
                            ?>
                        </div>
                        <p class="ml-readiness-count"><?= count($eligibleTrainingProducts) ?> of <?= count($trainingReadiness) ?> Products ready</p>
                        <?php if (!$isLocalDemo && !$isHostedReplay): ?>
                            <p class="ml-readiness-note"><?= $eligibleTrainingProducts === [] ? 'No Products meet the training requirements yet.' : 'Training uses ready Products only.' ?></p>
                        <?php endif; ?>
                    </div>
                    <form class="ml-action-form" method="post">
                        <?= csrf_field() ?>
                        <button class="btn" type="submit" name="action" value="train" <?= $isTraining || $isHostedReplay || (!$isLocalDemo && !$isHostedReplay && $eligibleTrainingProducts === []) ? 'disabled' : '' ?>>
                            <?= $isTraining ? 'Training in progress…' : 'Train candidate model' ?>
                        </button>
                    </form>
                </div>
                <div class="ml-training-status" aria-label="Training status">
                    <div class="ml-job-status" role="status" aria-live="polite">
                        <span>Latest training job</span>
                        <strong><?= htmlspecialchars($trainingJob ? '#' . $trainingJob['job_id'] . ' · ' . (($trainingJob['status'] ?? '') === 'succeeded' ? 'Completed' : ucfirst($trainingJob['status'])) : 'Not started') ?></strong>
                    </div>
                    <?php
                    $schedulerHealth = \App\Forecasting\ForecastingSchedulerService::getSchedulerHealth();
                    $schedulerLabels = ['idle' => 'Up to date', 'work_pending' => 'Work waiting', 'stale' => 'Check overdue', 'unavailable' => 'Status unavailable'];
                    ?>
                    <div class="ml-job-status" role="status" aria-live="polite" data-scheduler-state="<?= htmlspecialchars($schedulerHealth['state']) ?>">
                        <span>Automatic training</span>
                        <strong><?= htmlspecialchars($schedulerLabels[$schedulerHealth['state']]) ?></strong>
                        <p><?= $schedulerHealth['checked_at'] ? 'Last checked: ' . htmlspecialchars($schedulerHealth['checked_at']) . ' · Asia/Manila' : 'No check recorded yet.' ?></p>
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
                            <span class="candidate-meta-val"><?= htmlspecialchars($candidateEvidence['metadata']['data_window_start'] ?? 'â€”') ?> to <?= htmlspecialchars($candidateEvidence['metadata']['data_window_end'] ?? 'â€”') ?></span>
                        </div>
                    </div>
                    </details>

                    <details class="forecast-details"><summary>Accuracy results &amp; observation details</summary>
                    <div class="table-wrap" tabindex="0" role="region" aria-label="Candidate evaluation metrics">
                        <table aria-label="Candidate evaluation metrics" data-no-ghost-rows>
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
                            <button type="submit" class="btn" name="action" value="approve_demo" <?= $isTraining || empty($candidateQuality['passed']) || $approvedVersion === $latestCandidate['model_version'] ? 'disabled' : '' ?>>
                                <?= $approvedVersion === $latestCandidate['model_version'] ? 'Demo model approved' : 'Approve demo model' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

                </div>
        </section>

        <section class="dashboard-section" aria-labelledby="history-heading">
            <div class="section-header"><h2 id="history-heading">Training history</h2></div>
            <div class="ml-history-summary"><span><?= count($trainingJobs) ?> recent jobs</span><span><?= count($runs) ?> recent runs</span><?php forecast_help('training-history-help', 'Jobs track requests, waiting work and diagnostic references. Runs record actual training execution, duration and the history used. A completed job or run does not mean the candidate passed quality checks or received approval. Switch between jobs and runs to investigate a training attempt.', 'About training history'); ?></div>
            <nav class="ml-history-tabs" aria-label="Training history">
                <a href="?history=jobs#history-heading"<?= $historyView === 'jobs' ? ' aria-current="page"' : '' ?>>Training jobs</a>
                <a href="?history=runs#history-heading"<?= $historyView === 'runs' ? ' aria-current="page"' : '' ?>>Recent training runs</a>
            </nav>
            <?php if ($historyView === 'jobs'): ?>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Training job history">
                <table aria-label="Recent training jobs" data-no-ghost-rows>
                    <thead><tr><th scope="col">Job</th><th scope="col">Requested</th><th scope="col">State</th><th scope="col">Candidate qualification</th><th scope="col">Result / diagnostic</th></tr></thead>
                    <tbody>
                    <?php foreach ($trainingJobs as $job): $jobStatus = $formatRunStatus($job['status']); ?>
                        <tr>
                            <td>#<?= (int)$job['job_id'] ?></td>
                            <td><?= htmlspecialchars(format_display_datetime($job['queued_at'] ?? null)) ?></td>
                            <td><span class="<?= htmlspecialchars($jobStatus['class']) ?>"><?= htmlspecialchars($jobStatus['label']) ?></span></td>
                            <td><?= $job['status'] === 'succeeded' ? htmlspecialchars(ucfirst($job['candidate_qualification'] ?? 'incomplete')) . ' - approval separate' : 'Not evaluated' ?></td>
                            <td><?php if ($job['status'] === 'failed'): ?>
                                Diagnostic reference: <code><?= htmlspecialchars($job['diagnostic_reference']) ?></code>
                                <?php elseif (!empty($job['model_version'])): ?><a href="../report/forecast_analytics.php?training_run_id=<?= (int)$job['training_run_id'] ?>"><?= htmlspecialchars($job['model_version']) ?></a>
                                <?php else: ?><?= htmlspecialchars($job['safe_explanation']) ?><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$trainingJobs): ?><tr><td colspan="5">No training jobs yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Training run history">
                <table aria-label="Recent training runs log" data-no-ghost-rows>
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
                            <td class="u-num-col"><?= $run['duration_seconds'] !== null ? number_format((float)$run['duration_seconds'], 2) . '&nbsp;s' : 'â€”' ?></td>
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
            <?php endif; ?>
        </section>
    </main>
</div>
<?php if ($isTraining): ?>
    <script>setTimeout(() => window.location.replace(window.location.href), 5000);</script>
<?php endif; ?>
</body>
</html>
