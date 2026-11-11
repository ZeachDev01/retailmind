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

$formatError = static function ($val): string {
    if ($val === null || !is_numeric($val) || !is_finite((float)$val) || (float)$val < 0) {
        return 'Not available';
    }
    return number_format((float)$val * 100, 2) . '% error (' . number_format((float)$val, 4) . ')';
};

$formatUnits = static function ($val): string {
    if ($val === null || !is_numeric($val) || !is_finite((float)$val) || (float)$val < 0) {
        return 'Not available';
    }
    return number_format((float)$val, 2) . ' units';
};
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Model Review &amp; Approval</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/registers.css')) ?>">
    <style>
        .badge-status { display: inline-block; padding: 0.35rem 0.75rem; border-radius: 9999px; font-weight: 600; font-size: 0.85rem; }
        .badge-under-evaluation { background: var(--color-warning-subtle, #fef3c7); color: var(--color-warning-text, #92400e); border: 1px solid var(--color-warning-border, #fcd34d); }
        .badge-awaiting-approval { background: var(--color-info-subtle, #e0f2fe); color: var(--color-info-text, #075985); border: 1px solid var(--color-info-border, #bae6fd); }
        .badge-approved { background: var(--color-success-subtle, #dcfce7); color: var(--color-success-text, #166534); border: 1px solid var(--color-success-border, #86efac); }
        .progress-bar-container { background: var(--color-bg-subtle, #e5e7eb); border-radius: 8px; overflow: hidden; height: 16px; margin: 0.5rem 0; width: 100%; max-width: 400px; }
        .progress-bar-fill { background: var(--color-primary, #2563eb); height: 100%; transition: width 0.3s ease; }
        .limitations-list { padding-left: 1.25rem; margin: 0.5rem 0; line-height: 1.6; }
        .limitations-list li { margin-bottom: 0.35rem; }
        .approval-form-card { background: var(--card-bg, #ffffff); border: 1px solid var(--border-color, #e5e7eb); border-radius: 8px; padding: 1.25rem; margin-top: 1rem; }
        .incumbent-box { background: var(--color-bg-subtle, #f9fafb); border: 1px solid var(--border-color, #e5e7eb); border-radius: 6px; padding: 0.75rem 1rem; margin-top: 0.75rem; }
    </style>
    <?php require_once __DIR__ . '/../report/forecast_ui.php'; ?>
</head>
<body class="forecast-workspace model-review-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Model Review &amp; Approval</h1>
            </div>
        </header>
        <?php forecast_workflow('review'); ?>

        <?php if ($message): ?>
            <div id="review-feedback" class="alert <?= htmlspecialchars($messageClass) ?>" role="status"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card-grid" style="margin-bottom: 1.5rem;">
            <article class="stat-card with-icon">
                <span class="stat-icon"><i class="bi bi-cpu" aria-hidden="true"></i></span>
                <div class="label">Candidate Model</div>
                <div class="value" style="font-size: 1.1rem;"><?= htmlspecialchars($summary['candidate_version'] ?: 'None') ?></div>
                <div class="hint"><?= htmlspecialchars($summary['status_label'] ?? 'No candidate') ?></div>
            </article>

            <article class="stat-card with-icon status-healthy">
                <span class="stat-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                <div class="label">Approved Store Model</div>
                <div class="value" style="font-size: 1.1rem;"><?= htmlspecialchars($summary['approved_version'] ?: 'None yet (First model)') ?></div>
                <div class="hint">Approved version for Store forecasting</div>
            </article>

            <article class="stat-card with-icon">
                <span class="stat-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                <div class="label">Observation Progress</div>
                <div class="value"><?= (int)($summary['observation_progress']['usable_days'] ?? 0) ?> / <?= (int)($summary['observation_progress']['required_days'] ?? 28) ?></div>
                <div class="hint">Completed usable target dates</div>
            </article>

            <article class="stat-card with-icon <?= !empty($summary['is_recommendations_suspended']) ? 'status-warning' : (!empty($summary['approved_version']) ? 'status-healthy' : 'status-neutral') ?>">
                <span class="stat-icon"><i class="bi bi-cart-check" aria-hidden="true"></i></span>
                <div class="label">Forecast Guidance Approval</div>
                <div class="value"><?= !empty($summary['is_recommendations_suspended']) ? 'Paused' : (!empty($summary['approved_version']) ? 'Approved' : 'No approved model') ?></div>
                <div class="hint"><?= !empty($summary['is_recommendations_suspended']) ? 'Suggestions suspended locally' : (!empty($summary['approved_version']) ? 'Check Demand Forecast for current Product coverage' : 'Stock rules in effect until model approved') ?></div>
            </article>
        </div>

        <!-- Candidate Qualification & Status Banner -->
            <details class="forecast-details"><summary id="status-heading">Approval status details</summary>
            <div style="margin: 0.75rem 0;">
                <?php if ($summary['overall_status'] === 'awaiting_approval'): ?>
                    <span class="badge-status badge-awaiting-approval">Awaiting approval</span>
                    <p style="margin-top: 0.5rem;">All automated technical checks have passed. Candidate is qualified and awaiting local Administrator approval.</p>
                <?php elseif ($summary['overall_status'] === 'approved'): ?>
                    <span class="badge-status badge-approved">Selected as approved model</span>
                    <p style="margin-top: 0.5rem;">Operational status: <strong><?= htmlspecialchars($summary['operational_status']) ?></strong></p>
                <?php else: ?>
                    <span class="badge-status badge-under-evaluation">Under evaluation</span>
                    <p style="margin-top: 0.5rem;">Candidate is currently under evaluation. Required performance holdout checks or prospective observation days remain incomplete.</p>
                <?php endif; ?>
            </div>
            </details>

        <!-- Governance Actions & Operational Controls -->
        <section class="dashboard-section" aria-labelledby="actions-heading">
            <h2 id="actions-heading"><i class="bi bi-sliders" aria-hidden="true"></i> Model actions</h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 1rem;">
                <!-- Candidate Decision Box -->
                <div class="approval-form-card">
                    <h3 style="margin-top: 0; font-size: 1.05rem;">Approve or reject</h3>
                    <?php if (!empty($summary['can_approve'])): ?>
                        <form method="post" style="margin-bottom: 1rem;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="model_version" value="<?= htmlspecialchars($summary['candidate_version']) ?>">
                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label for="justification">Operational Approval Notes</label>
                                <input type="text" id="justification" name="justification" autocomplete="off" placeholder="e.g. Verified 28-day holdout and prospective observation" class="w-full" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-full">
                                <i class="bi bi-check-lg" aria-hidden="true"></i> Approve &amp; Activate Model
                            </button>
                        </form>

                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="model_version" value="<?= htmlspecialchars($summary['candidate_version']) ?>">
                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label for="reject-reason">Rejection Reason</label>
                                <input type="text" id="reject-reason" name="reason" autocomplete="off" placeholder="e.g. Unstable variance on promotional items" class="w-full" required>
                            </div>
                            <button type="submit" class="btn btn-secondary w-full">
                                <i class="bi bi-x-lg" aria-hidden="true"></i> Reject Candidate
                            </button>
                        </form>
                    <?php elseif (!empty($summary['is_current_approved'])): ?>
                        <p class="text-success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Model <strong><?= htmlspecialchars($summary['candidate_version']) ?></strong> is selected as approved. Check product coverage before buying.</p>
                    <?php else: ?>
                        <p class="text-warning"><i class="bi bi-lock-fill" aria-hidden="true"></i> Approval is blocked until technical checks and observation are complete.</p>
                    <?php endif; ?>
                </div>

                <!-- Operational Controls & Recovery Box -->
                <div class="approval-form-card">
                    <h3 style="margin-top: 0; font-size: 1.05rem;">Buying suggestions &amp; recovery</h3>

                    <!-- Pause/Resume -->
                    <div style="margin-bottom: 1.25rem;">
                        <label style="font-weight: 600; font-size: 0.9rem;">Buying suggestions</label>
                        <p style="font-size: 0.85rem; color: var(--color-text-muted, #6b7280); margin: 0.25rem 0 0.5rem 0;">
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
                                    <i class="bi bi-pause-circle" aria-hidden="true"></i> Pause Purchasing Suggestions
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resume">
                                <button type="submit" class="btn btn-primary w-full">
                                    <i class="bi bi-play-circle" aria-hidden="true"></i> Resume Purchasing Suggestions
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Rollback to Previous Model -->
                    <?php if (!empty($summary['can_rollback'])): ?>
                        <div style="border-top: 1px solid var(--border-color, #e5e7eb); padding-top: 1rem;">
                            <label style="font-weight: 600; font-size: 0.9rem;">Reversible Rollback</label>
                            <p style="font-size: 0.85rem; color: var(--color-text-muted, #6b7280); margin: 0.25rem 0 0.5rem 0;">
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
                            <label style="font-weight: 600; font-size: 0.9rem;">Reversible Rollback</label>
                            <p style="font-size: 0.85rem; color: var(--color-warning-text, #92400e); margin: 0.25rem 0 0.5rem 0;">
                                Rollback to previous model <strong><?= htmlspecialchars($summary['previous_version']) ?></strong> unavailable: <?= htmlspecialchars($summary['rollback_validation']['reason'] ?? 'Incompatible artifact') ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Activation Consequences & Operational Role -->
<details class="forecast-details"><summary id="consequences-heading">What approval changes</summary>

            <div class="incumbent-box" style="line-height: 1.6;">
                <p>Forecasts estimate sales. Replenishment Recommendations suggest buying quantities after stock and buying rules are considered. Staff decide what to buy; approval does not place orders or change stock.</p>
                <p style="margin-bottom: 0;"><strong>Operational coverage &amp; fallback:</strong> Approval enables forecast-backed suggestions for eligible products. Other products keep using stock rules.</p>
            </div>
        </details>

        <!-- Product Coverage and Input Blockers -->
        <section class="dashboard-section" aria-labelledby="products-heading">
            <h2 id="products-heading"><i class="bi bi-box-seam" aria-hidden="true"></i> Product coverage <?php forecast_help('coverage-scope-help', 'Products without enough sales history use stock rules instead of forecasts.', 'About product coverage'); ?></h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); gap: 1rem; margin-top: 1rem;">
                <div class="approval-form-card">
                    <h3 style="margin-top: 0; font-size: 1rem;"><i class="bi bi-check-circle text-success" aria-hidden="true"></i> Covered Products (<?= (int)($summary['products']['covered_count'] ?? 0) ?>)</h3>
                    <?php if (empty($summary['products']['covered'])): ?>
                        <p class="text-muted" style="font-size: 0.9rem;">No products currently covered in candidate model.</p>
                    <?php else: ?>
                        <ul style="padding-left: 1.25rem; font-size: 0.9rem; line-height: 1.5;">
                            <?php foreach ($summary['products']['covered'] as $cp): ?>
                                <li><strong><?= htmlspecialchars($cp['product_name']) ?></strong> (<?= htmlspecialchars($cp['sku']) ?>)</li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="approval-form-card">
                    <h3 style="margin-top: 0; font-size: 1rem;"><i class="bi bi-exclamation-triangle text-warning" aria-hidden="true"></i> Excluded Products &amp; Blockers (<?= (int)($summary['products']['excluded_count'] ?? 0) ?>)</h3>
                    <?php if (empty($summary['products']['excluded'])): ?>
                        <p class="text-muted" style="font-size: 0.9rem;">All active products meet data prerequisites.</p>
                    <?php else: ?>
                        <ul style="padding-left: 1.25rem; font-size: 0.9rem; line-height: 1.5;">
                            <?php foreach ($summary['products']['excluded'] as $ep): ?>
                                <li>
                                    <strong><?= htmlspecialchars($ep['product_name']) ?></strong> (<?= htmlspecialchars($ep['sku']) ?>):
                                    <br><span style="color: var(--color-warning-text, #92400e);"><?= htmlspecialchars($ep['exclusion_reason']) ?></span>
                                    <br><span style="font-size: 0.85rem; color: var(--color-text-muted, #6b7280);"><?= htmlspecialchars($ep['blocked_next_action']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Prospective Observation Holdout -->
        <section class="dashboard-section" aria-labelledby="obs-heading">
            <h2 id="obs-heading"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Observation progress</h2>
            <p class="section-description">28 usable days of actual Store sales required. <?php forecast_help('observation-help', 'Missing or Stockout Day targets do not advance observation. Recorded closures pause progress without resetting it.', 'About observation progress'); ?></p>

            <div style="margin: 0.75rem 0;">
                <div style="display: flex; justify-content: space-between; max-width: 400px; font-weight: 600; font-size: 0.9rem;">
                    <span>Completed Target Dates:</span>
                    <span><?= (int)($summary['observation_progress']['usable_days'] ?? 0) ?> / 28 days</span>
                </div>
                <div class="progress-bar-container">
                    <?php
                    $pct = min(100, max(0, round(((int)($summary['observation_progress']['usable_days'] ?? 0) / 28) * 100)));
                    ?>
                    <div class="progress-bar-fill" style="width: <?= $pct ?>%;"></div>
                </div>
                <p style="font-size: 0.9rem; color: var(--color-text-muted, #6b7280);">
                    <?= htmlspecialchars($summary['observation_progress']['explanation'] ?? '') ?>
                </p>
            </div>
        </section>

        <!-- Compare with approved model Box -->
        <section class="dashboard-section" aria-labelledby="incumbent-heading">
            <h2 id="incumbent-heading"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Compare with approved model</h2>
            <div class="incumbent-box">
                <p style="margin: 0; font-size: 0.95rem;"><?= htmlspecialchars($summary['incumbent_comparison']['status_message'] ?? '') ?></p>
                <?php if (!empty($summary['incumbent_comparison']['available'])): ?>
                    <div style="margin-top: 0.5rem; font-size: 0.9rem;">
                        <strong>Approved Model (<?= htmlspecialchars($summary['incumbent_comparison']['version']) ?>) 28-day WAPE:</strong>
                        <?= $formatError($summary['incumbent_comparison']['horizons'][28]['wape'] ?? null) ?>
                        vs <strong>Candidate 28-day WAPE:</strong>
                        <?= $formatError($summary['candidate_quality']['horizons'][28]['wape'] ?? null) ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Supporting Technical Details & Holdout Metrics -->
<details class="forecast-details"><summary id="eval-heading">Technical checks &amp; error metrics</summary>

            <p class="section-description">
                Evaluation compares candidate predictions against recorded Store sales in units and against the <strong><?= htmlspecialchars($summary['comparison_method'] ?? 'Weekly Seasonal Naive') ?></strong> baseline across 3 chronological 28-day holdout folds (<?= htmlspecialchars($summary['evaluation_periods']['start'] ?? '') ?> to <?= htmlspecialchars($summary['evaluation_periods']['end'] ?? '') ?>).
                <br><em>Note: WAPE measures relative prediction error (lower is better); it is an error metric, not an accuracy percentage. Raw MAE and RMSE reflect deviation in units sold.</em>
            </p>

            <div class="data-table-shell">
                <div class="data-table-scroll" tabindex="0" role="region" aria-labelledby="eval-heading">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Forecast Horizon</th>
                                <th scope="col">Candidate Error (WAPE)</th>
                                <th scope="col">Candidate MAE</th>
                                <th scope="col">Repeat Last Week (WAPE)</th>
                                <th scope="col">Baseline MAE</th>
                                <th scope="col">Quality Gate Limit</th>
                                <th scope="col">Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($summary['candidate_quality']['horizons'])): ?>
                            <tr><td colspan="7" style="text-align: center;">No completed chronological evaluations recorded for this candidate.</td></tr>
                        <?php else: ?>
                            <?php foreach ([7, 14, 28] as $horizon): ?>
                                <?php $hData = $summary['candidate_quality']['horizons'][$horizon] ?? null; ?>
                                <tr>
                                    <td><strong><?= $horizon ?> days ahead</strong></td>
                                    <td><?= $hData ? $formatError($hData['wape']) : 'Not available' ?></td>
                                    <td><?= $hData ? $formatUnits($hData['mae']) : 'Not available' ?></td>
                                    <td><?= $hData ? $formatError($hData['baseline_wape']) : 'Not available' ?></td>
                                    <td><?= $hData ? $formatUnits($hData['baseline_mae']) : 'Not available' ?></td>
                                    <td><?= $hData && $hData['max_wape_limit'] !== null ? $formatError($hData['max_wape_limit']) : 'Missing threshold' ?></td>
                                    <td>
                                        <?php if (!$hData || ($hData['gate_status'] ?? '') === 'blocked'): ?>
                                            <span class="tag tag-warning">Blocked: Missing threshold</span>
                                        <?php elseif (($hData['gate_status'] ?? '') === 'passed'): ?>
                                            <span class="tag tag-success">Passed</span>
                                        <?php else: ?>
                                            <span class="tag tag-danger">Failed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <!-- Governance Rules and Limitations Box -->
<details class="forecast-details"><summary id="limitations-heading">Model limitations &amp; safeguards</summary>

            <ul class="limitations-list">
                <?php foreach (($summary['limitations'] ?? []) as $limitation): ?>
                    <li><?= htmlspecialchars($limitation) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    </main>
</div>
</body>
</html>
