<?php
// components/administrator/model_review.php — Local Model Review & Approval (Ticket #368).
//
// The local Administrator reviews qualified Store candidate models and records operational
// approval or rejection without requiring routine developer access (ADR-0022).
// Automated technical checks supply qualification; approval is never automatic.
// Activation atomically updates the approved model, preserves the compatible previous model,
// records Protected Audit Records, and queues a fresh operational forecast.

require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Forecasting\StoreModelGovernanceService;
use App\Support\OperatorAlert;

require_capability(RoleCapabilityPolicy::APPROVE_MODELS);

$actorId = (int)($_SESSION['user_id'] ?? 0);
$actorRole = (string)current_role();
$activationService = new StoreModelGovernanceService($pdo);

$message = '';
$messageClass = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_local_request('Model review and recovery mutations are restricted to the local Store console.');
    csrf_verify();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'approve') {
            $version = trim((string)($_POST['model_version'] ?? ''));
            $justification = trim((string)($_POST['justification'] ?? ''));
            $cutoff = (new DateTime('now', new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d');

            $record = $activationService->activateStoreModel(
                $version,
                $cutoff,
                $actorId,
                $justification ?: 'Approved for Store demand forecasting by local Administrator',
                $actorRole,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            );

            $message = "Model '{$version}' approved and activated successfully. Fresh operational forecast has been queued for cutoff {$cutoff}.";
            $messageClass = 'tag-success';
        } elseif ($action === 'reject') {
            $version = trim((string)($_POST['model_version'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));

            $activationService->rejectCandidate(
                $version,
                $actorId,
                $reason ?: 'Rejected during local Administrator operational review',
                $actorRole,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            );

            $message = "Candidate model '{$version}' has been rejected. Operational Store guidance and records remain unchanged.";
            $messageClass = 'tag-success';
        } elseif ($action === 'rollback') {
            require_capability(RoleCapabilityPolicy::ROLL_BACK_MODELS);
            $reason = trim((string)($_POST['reason'] ?? ''));

            $rollback = $activationService->rollbackStoreModel(
                $actorId,
                $reason ?: 'Rolled back to previous approved model by Administrator',
                $actorRole,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            );

            $message = "Successfully rolled back to previous model '{$rollback['restored_model_version']}'. Fresh operational forecast has been queued.";
            $messageClass = 'tag-success';
        } elseif ($action === 'suspend') {
            require_capability(RoleCapabilityPolicy::APPROVE_MODELS);
            $reason = trim((string)($_POST['reason'] ?? ''));
            $activationService->suspendRecommendations($actorId, $reason, $actorRole, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $message = "Forecast-backed replenishment suggestions have been paused locally. Routine cashier sales, inventory counts, and purchasing continue unaffected.";
            $messageClass = 'tag-warning';
        } elseif ($action === 'resume') {
            require_capability(RoleCapabilityPolicy::APPROVE_MODELS);
            $activationService->resumeRecommendations($actorId, $actorRole, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $message = "Forecast-backed replenishment suggestions have been resumed.";
            $messageClass = 'tag-success';
        }
    } catch (Throwable $e) {
        $message = OperatorAlert::message($e, 'Could not complete model review action. Review candidate qualification and try again.');
        $messageClass = 'tag-warning';
    }
}

$summary = $activationService->getApprovalSummary();
$demoReview = null;
$demoDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (preg_match('/^retailmind_demo_[a-z0-9_]+$/D', $demoDatabase)
    && in_array(strtolower((string)env('APP_ENV')), ['development', 'test'], true)
    && in_array((string)env('DB_HOST'), ['localhost', '127.0.0.1', '::1'], true)) {
    $requestedRun = $_GET['training_run_id'] ?? null;
    $demoRuns = $pdo->query("SELECT training_run_id, model_version, metrics_json FROM model_training_runs
        WHERE status='completed' AND model_version LIKE 'demo_%' ORDER BY training_run_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($demoRuns as $run) {
        if ($requestedRun === null || (is_scalar($requestedRun) && (string)$run['training_run_id'] === (string)$requestedRun)) {
            $demoReview = $run;
            break;
        }
    }
    if ($requestedRun !== null && !$demoReview) {
        http_response_code(404);
        exit('Completed Demo training candidate not found.');
    }
}
$demoEvidence = json_decode((string)($demoReview['metrics_json'] ?? ''), true) ?: [];
$demoMetadata = $demoEvidence['metadata'] ?? [];

$reviewEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$reviewNumber = static fn($value): ?float => is_numeric($value) && is_finite((float)$value) && (float)$value >= 0 ? (float)$value : null;
$reviewPercent = static fn($value): string => $reviewNumber($value) === null ? 'Not available' : number_format($value * 100, 2) . '%';
$reviewUnits = static fn($value): string => $reviewNumber($value) === null ? 'Not available' : rtrim(rtrim(number_format($value, 2), '0'), '.') . ' units';
$approved = $summary['approved_version'];
$isPaused = $summary['is_recommendations_suspended'];
$isObservationMode = $summary['is_observation_mode'];
$hasCandidate = $summary['has_candidate'];
$canApprove = $summary['can_approve'];
$isApproved = $summary['is_current_approved'] ?? false;
$isRejected = $summary['review_rejected'] ?? false;
$blocker = $summary['approval_blocker'] ?? '';
$horizons = $summary['candidate_quality']['horizons'] ?? [];
$wape28 = $reviewNumber($horizons[28]['wape'] ?? null);
$baseline28 = $reviewNumber($horizons[28]['baseline_wape'] ?? null);
$observation = $summary['observation_progress'] ?? [];
$coverage = $summary['products'] ?? [];
$reviewDates = $summary['product_review_dates'] ?? [];
$testDates = $summary['evaluation_periods'] ?? [];
$recoveryAvailable = $approved !== '' || $isPaused;
$trainingMessage = match ($summary['training_status']) {
    'running' => 'Training in progress. Results will appear here when the model is ready for review.',
    'queued' => 'Model training is queued.',
    'failed', 'interrupted' => 'Model training did not finish. The Super Administrator can check the training status.',
    default => match ($summary['setup_state'] ?? null) {
        'collecting' => 'Waiting for more usable sales history.',
        'ready' => 'Sales history is ready. Waiting for a training run.',
        default => '',
    },
};
$statusTitle = match (true) {
    !$hasCandidate => 'No model to review yet',
    $isApproved && $isPaused => 'Forecast suggestions paused',
    $isApproved && $isObservationMode => 'Approved model',
    $isApproved => 'Approved model in use',
    $canApprove => 'Ready for review',
    $isRejected => 'Model rejected',
    empty($observation['observation_complete']) => 'Model under testing',
    default => 'Model review blocked',
};
$reviewBars = static function (array $rows) use ($reviewEscape): void {
    $max = max(array_column($rows, 'value'));
    foreach ($rows as $index => $row) {
        $width = $max > 0 ? $row['value'] / $max * 100 : 0;
        ?>
        <div class="model-review-bar">
            <span><?= $reviewEscape($row['label']) ?></span>
            <span class="model-review-track" aria-hidden="true"><i class="<?= $index === 0 ? 'is-model' : '' ?>" style="width: <?= $width ?>%"></i></span>
            <strong><?= $reviewEscape($row['display']) ?></strong>
        </div>
        <?php
    }
};
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Model Review &amp; Approval</title>
    <link rel="stylesheet" href="<?= $reviewEscape(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= $reviewEscape(theme_url('assets/css/registers.css')) ?>">
    <?php require_once __DIR__ . '/../report/forecast_ui.php'; ?>
    <script src="<?= $reviewEscape(app_url('assets/js/model-review.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/model-review.js')) ?>" defer></script>
</head>
<body class="forecast-workspace model-review-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading"><div><h1>Model Review</h1></div></header>
        <?php forecast_workflow('review'); ?>
        <?php if ($hasCandidate): ?><p><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/forecast_analytics.php') . '?model_version=' . rawurlencode($summary['candidate_version'])) ?>">View candidate Analytics</a></p><?php endif; ?>

        <div class="model-review-content">
            <?php require __DIR__ . '/../report/forecast_scheduler_health.php'; ?>
            <?php if ($demoReview): ?>
            <section class="dashboard-section model-review-panel" aria-labelledby="demo-review-heading">
                <h2 id="demo-review-heading">Completed historical Demo evidence</h2>
                <p>Training run #<?= (int)$demoReview['training_run_id'] ?> · Candidate <?= $reviewEscape($demoReview['model_version']) ?> · Qualification: <?= $reviewEscape($demoEvidence['outcome'] ?? $demoMetadata['outcome'] ?? 'Not recorded') ?><?= isset($demoMetadata['job_id']) ? ' · Job #' . (int)$demoMetadata['job_id'] : '' ?></p>
                <p>Source: <?= $reviewEscape($demoMetadata['evidence_label'] ?? 'Demo evidence; provenance unverified') ?>. History: <?= $reviewEscape($demoMetadata['data_window_start'] ?? 'Not recorded') ?> to <?= $reviewEscape($demoMetadata['data_window_end'] ?? 'Not recorded') ?>.</p>
                <p>This completed run is separate from any queued or running job. Historical Demo evaluation provides no usable Store observation days and cannot activate a Store model or provide operational buying guidance.</p>
                <a href="<?= $reviewEscape(app_url('components/report/forecast_analytics.php') . '?training_run_id=' . (int)$demoReview['training_run_id']) ?>">View this run's quality checks and historical Analytics</a>
            </section>
            <?php endif; ?>
            <?php if ($message): ?>
                <div id="review-feedback" class="alert <?= $reviewEscape($messageClass) ?>" role="status"><?= $reviewEscape($message) ?></div>
            <?php endif; ?>

            <section class="dashboard-section model-review-panel" aria-labelledby="review-heading">
                <header>
                    <h2 id="review-heading"><?= $reviewEscape($statusTitle) ?></h2>
                    <?php if ($canApprove): ?>
                        <p>All checks passed · <?= (int)$observation['usable_days'] ?> usable days reviewed</p>
                    <?php elseif ($hasCandidate && !$isApproved && !$isRejected && empty($observation['observation_complete'])): ?>
                        <p><?= (int)$observation['usable_days'] ?> of <?= (int)$observation['required_days'] ?> usable Store days reviewed.
                            <?php forecast_help('review-usable-days', 'Open days with reliable sales records count, including verified zero sales. Closed days and stockouts are excluded.', 'About usable Store days'); ?>
                        </p>
                        <progress class="model-review-progress" aria-label="Usable Store days reviewed" value="<?= (int)$observation['usable_days'] ?>" max="<?= (int)$observation['required_days'] ?>"></progress>
                    <?php endif; ?>
                    <?php if (!$isApproved && $blocker !== ''): ?>
                        <p><?= $reviewEscape($blocker) ?></p>
                    <?php endif; ?>
                    <?php if ($isPaused || $isObservationMode || $approved === ''): ?>
                        <p><?= $isPaused && !$isApproved ? 'Forecast-based suggestions are paused. Buying suggestions use stock rules.' : 'Buying suggestions currently use stock rules.' ?></p>
                    <?php endif; ?>
                    <?php if ($trainingMessage !== '' && !$canApprove): ?>
                        <p class="model-review-system"><i class="bi bi-clock-history" aria-hidden="true"></i> <?= $reviewEscape($trainingMessage) ?></p>
                    <?php endif; ?>
                    <?php if (!$hasCandidate || $isApproved): ?>
                        <a class="btn btn-primary" href="<?= $reviewEscape(app_url($isApproved ? 'components/report/predictions.php' : 'components/report/data_readiness.php')) ?>">
                            <?= $isApproved ? 'View demand forecast' : 'Check data readiness' ?>
                        </a>
                    <?php endif; ?>
                </header>

                <?php if ($hasCandidate): ?>
                    <div class="model-review-evidence">
                        <?php if ($wape28 !== null && $baseline28 !== null): ?>
                            <section class="model-review-chart" aria-labelledby="review-error-heading">
                                <div class="model-review-context">
                                    <h3 id="review-error-heading">28-day forecast error</h3>
                                    <?php forecast_help('review-chart-help', 'This compares the model with sales from the matching weekday of the previous week.'
                                        . ($testDates['start'] && $testDates['end'] ? ' Sales data window: ' . $testDates['start'] . ' to ' . $testDates['end'] . '.' : ''), 'About this forecast comparison'); ?>
                                </div>
                                <p class="text-muted">Lower error is better.</p>
                                <?php
                                $comparison = [
                                    ['label' => $isApproved ? 'Approved model' : 'New model', 'value' => $wape28, 'display' => $reviewPercent($wape28)],
                                    ['label' => 'Repeat last week', 'value' => $baseline28, 'display' => $reviewPercent($baseline28)],
                                ];
                                $incumbentWape = $reviewNumber($summary['incumbent_comparison']['horizons'][28]['wape'] ?? null);
                                if ($incumbentWape !== null) {
                                    $comparison[] = ['label' => 'Current approved model', 'value' => $incumbentWape, 'display' => $reviewPercent($incumbentWape)];
                                }
                                $reviewBars($comparison);
                                ?>
                            </section>
                        <?php else: ?>
                            <p>No completed forecast error comparison yet.</p>
                        <?php endif; ?>

                        <details class="forecast-details model-review-metrics" name="model-review-evidence" data-review-metrics>
                            <summary>Technical metrics</summary>
                            <?php if ($horizons): ?>
                                <div class="model-review-context">
                                    <div class="model-review-switch" role="group" aria-label="Forecast horizon" hidden>
                                        <?php foreach ([7, 14, 28] as $days): ?>
                                            <button type="button" class="btn btn-secondary" data-review-horizon="<?= $days ?>" aria-pressed="<?= $days === 28 ? 'true' : 'false' ?>"><?= $days ?> days</button>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php forecast_help('review-metrics-help', 'Model: ' . $summary['candidate_version'] . '. The WAPE limit is the maximum forecast error allowed for the selected horizon.', 'About the model and WAPE limit'); ?>
                                </div>
                                <?php
                                $measures = [
                                    'wape' => ['WAPE (%)', 'Total absolute forecast error as a share of recorded sales. Lower is better.', $reviewPercent],
                                    'mae' => ['MAE (units)', 'Average absolute difference between forecast and actual sales, in units. Lower is better.', $reviewUnits],
                                    'rmse' => ['RMSE (units)', 'Forecast error in units that gives larger errors more weight. Lower is better.', $reviewUnits],
                                ];
                                ?>
                                <?php foreach ([7, 14, 28] as $days): $metrics = $horizons[$days] ?? []; ?>
                                    <div class="model-review-metric-period" data-review-period="<?= $days ?>">
                                        <h4 class="model-review-period-heading"><?= $days ?> days ahead</h4>
                                        <?php if (!$metrics): ?>
                                            <p>Test results are unavailable for this horizon.</p>
                                        <?php else: ?>
                                            <div class="data-table-shell">
                                                <table class="data-table model-review-table" aria-label="<?= $days ?>-day forecast metrics">
                                                    <thead><tr><th scope="col">Measure</th><th scope="col">Model</th><th scope="col">Repeat last week</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($measures as $key => [$label, $help, $format]): ?>
                                                            <tr>
                                                                <th scope="row"><?= $reviewEscape($label) ?> <?php forecast_help("review-{$key}-{$days}", $help, "How to read {$key}"); ?></th>
                                                                <td><?= $format($metrics[$key] ?? null) ?></td>
                                                                <td><?= $format($metrics['baseline_' . $key] ?? null) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <p class="model-review-check">
                                                <strong><?= $reviewEscape(ucfirst($metrics['gate_status'])) ?></strong> · WAPE limit <?= $reviewPercent($metrics['max_wape_limit']) ?>
                                                <?php if ($metrics['gate_status'] !== 'passed' && $metrics['gate_reason'] !== ''): ?> — <?= $reviewEscape($metrics['gate_reason']) ?><?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>Test results are unavailable for this model.</p>
                            <?php endif; ?>
                        </details>

                        <?php require __DIR__ . '/model_review_products.php'; ?>

                        <?php if ($canApprove): ?>
                            <section class="model-review-decision" aria-labelledby="review-decision-heading">
                                <div class="model-review-context">
                                    <h3 id="review-decision-heading">Model decision</h3>
                                    <?php forecast_help('review-decision-help', 'Approval selects this model for Store forecasting. Stock and orders stay as they are with either decision.', 'What a model decision changes'); ?>
                                </div>
                                <p>Approval queues a forecast update. Rejection keeps the current setup.</p>
                                <div class="model-review-note-actions">
                                    <?php foreach ([
                                        'approve' => ['Approve and activate', 'justification', 'Reason for approval', 'btn-primary'],
                                        'reject' => ['Reject model', 'reason', 'Reason for rejection', 'btn-secondary'],
                                    ] as $action => [$label, $field, $fieldLabel, $buttonClass]): ?>
                                        <details class="model-review-action" name="model-review-decision">
                                            <summary class="btn <?= $buttonClass ?>"><?= $label ?></summary>
                                            <form method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="<?= $action ?>">
                                                <input type="hidden" name="model_version" value="<?= $reviewEscape($summary['candidate_version']) ?>">
                                                <label for="review-<?= $action ?>-note"><?= $fieldLabel ?></label>
                                                <input id="review-<?= $action ?>-note" name="<?= $field ?>" type="text" required>
                                                <button type="submit" class="btn <?= $buttonClass ?>">Confirm <?= $action === 'approve' ? 'approval' : 'rejection' ?></button>
                                            </form>
                                        </details>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($recoveryAvailable): ?>
                <details class="forecast-details model-review-recovery">
                    <summary>Buying suggestions &amp; recovery</summary>
                    <!-- Pause/Resume -->
                    <div style="margin-bottom: 1.25rem;">
                        <p style="font-size: 0.85rem; color: var(--muted, #626b7a); margin: 0.25rem 0 0.5rem 0;">
                            Stock rules remain available while forecast suggestions are paused.
                        </p>
                        <?php if (empty($summary['is_recommendations_suspended'])): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="suspend">
                                <div class="form-group" style="margin-bottom: 0.5rem;">
                                    <label for="pause-reason" class="sr-only">Reason for pause</label>
                                    <input type="text" id="pause-reason" name="reason" autocomplete="off" placeholder="Reason for pause (e.g. Store inventory audit)" class="w-full" required>
                                </div>
                                <button type="submit" class="btn btn-secondary w-full">
                                    <i class="bi bi-pause-circle" aria-hidden="true"></i> Pause forecast-based suggestions
                                </button>
                            </form>
                        <?php elseif ($summary['can_resume']): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resume">
                                <button type="submit" class="btn btn-primary w-full">
                                    <i class="bi bi-play-circle" aria-hidden="true"></i> Resume forecast-based suggestions
                                </button>
                            </form>
                        <?php else: ?>
                            <p>Resuming requires a compatible approved model available for Store forecasting.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Rollback to Previous Model -->
                    <?php if (!empty($summary['can_rollback'])): ?>
                        <div style="border-top: 1px solid var(--border-color, #e5e7eb); padding-top: 1rem;">
                            <h3>Previous model</h3>
                            <p style="font-size: 0.85rem; color: var(--muted, #626b7a); margin: 0.25rem 0 0.5rem 0;">
                                Restore previously approved model: <strong><?= htmlspecialchars($summary['previous_version']) ?></strong>
                            </p>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rollback">
                                <div class="form-group" style="margin-bottom: 0.5rem;">
                                    <label for="rollback-reason" class="sr-only">Reason for rollback</label>
                                    <input type="text" id="rollback-reason" name="reason" autocomplete="off" placeholder="Reason for rollback" class="w-full" required>
                                </div>
                                <button type="submit" class="btn btn-secondary w-full">
                                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Roll Back to <?= htmlspecialchars($summary['previous_version']) ?>
                                </button>
                            </form>
                        </div>
                    <?php elseif (!empty($summary['previous_version'])): ?>
                        <div style="border-top: 1px solid var(--border-color, #e5e7eb); padding-top: 1rem;">
                            <h3>Previous model</h3>
                            <p style="font-size: 0.85rem; color: var(--theme-warning-text, #92400e); margin: 0.25rem 0 0.5rem 0;">
                                Rollback to previous model <strong><?= htmlspecialchars($summary['previous_version']) ?></strong> unavailable: <?= htmlspecialchars($summary['rollback_validation']['reason'] ?? 'Incompatible artifact') ?>
                            </p>
                        </div>
                    <?php endif; ?>

                </details>
            <?php endif; ?>

            <?php if ($hasCandidate): ?>
                <details class="forecast-details model-review-limitations">
                    <summary>Model limitations</summary>
                    <ul>
                        <?php foreach ($summary['limitations'] as $limitation): ?>
                            <li><?= $reviewEscape($limitation) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
