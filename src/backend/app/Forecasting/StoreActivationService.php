<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use DomainException;
use Throwable;
use DateTime;
use DateTimeZone;

/**
 * Service governing Store Demand Forecasting activation, quality gating,
 * local Administrator approval and replacement (ADR-0022), reversible rollback,
 * and recommendation suspension.
 *
 * Implements ADR-0022: The local Administrator approves qualified Store models
 * after fixed automated technical checks pass; the Super Administrator retains
 * initial technical setup and maintenance.
 *
 * Implements ADR-0005 single pipeline and ADR-0016 sales/purchasing distinction.
 */
class StoreActivationService
{
    private PDO $pdo;
    private CandidateEvaluationService $evaluationService;
    private ModelPromotionService $promotionService;

    public function __construct(
        PDO $pdo,
        ?CandidateEvaluationService $evaluationService = null,
        ?ModelPromotionService $promotionService = null
    ) {
        $this->pdo = $pdo;
        $this->evaluationService = $evaluationService ?? new CandidateEvaluationService($pdo);
        $this->promotionService = $promotionService ?? new ModelPromotionService($pdo, $this->evaluationService);
    }

    /**
     * Determines whether a candidate model version meets all criteria for live Store activation.
     * Fixed automated technical checks supply qualification; local Administrator makes the operational decision.
     */
    public function validateActivationReadiness(string $modelVersion, string $asOfDate): array
    {
        $reasons = [];
        $checks = [
            'valid_version_format' => false,
            'not_demo_artifact' => false,
            'quality_gates_passed' => false,
            'observation_period_completed' => false,
            'not_rejected' => true,
            'technical_review_accepted' => false,
            'operational_review_accepted' => true,
        ];

        // 1. Version format & Demo isolation check
        $trimmed = trim($modelVersion);
        if ($trimmed === '') {
            $reasons[] = 'Model version cannot be empty.';
        } else {
            $checks['valid_version_format'] = true;
        }

        $lower = strtolower($trimmed);
        if (str_contains($lower, 'demo') || str_contains($lower, 'kaggle')) {
            $reasons[] = "Refusing activation for demo/kaggle model '$modelVersion'. Demo artifacts and weights are strictly isolated from Store operations.";
        } else {
            $checks['not_demo_artifact'] = true;
        }

        // 2. Candidate quality evaluation check (7, 14, 28-day chronological holdouts vs baseline)
        $qualityResult = $this->evaluationService->evaluateCandidateQuality($modelVersion);
        if (!$qualityResult['passed']) {
            $reasons[] = 'Candidate model failed quality gates: ' . $qualityResult['reason'];
        } else {
            $checks['quality_gates_passed'] = true;
        }

        // 3. Observation period holdout evaluation (minimum 28 target dates backed by usable Store observations)
        $obsResult = $this->evaluationService->evaluateCompletedTargetDates($this->pdo, $modelVersion, $asOfDate);
        if (!$obsResult['observation_complete']) {
            $reasons[] = "Observation period incomplete: requires at least {$obsResult['min_required_days']} completed target days evaluated against Store sales, but only {$obsResult['completed_days']} completed days were recorded.";
        } else {
            $checks['observation_period_completed'] = true;
        }

        // 4. Technical and Operational reviews (ADR-0022)
        $reviewStatus = $this->promotionService->getReviewStatus($modelVersion);
        $techDecision = $reviewStatus['technical_review']['decision'] ?? null;
        $opDecision = $reviewStatus['operational_review']['decision'] ?? null;

        // Automated technical qualification replaces mandatory routine Super Admin sign-off (ADR-0022).
        // If automated checks pass, technical qualification is satisfied without developer intervention.
        $automatedTechPassed = $checks['quality_gates_passed'] && $checks['observation_period_completed'] && $checks['not_demo_artifact'] && $checks['valid_version_format'];
        if ($techDecision === 'accepted' || $automatedTechPassed) {
            $checks['technical_review_accepted'] = true;
        } else {
            $checks['technical_review_accepted'] = false;
            $reasons[] = 'Automated technical qualification checks are incomplete.';
        }

        // Operational review decision:
        if ($opDecision === 'accepted') {
            $checks['operational_review_accepted'] = true;
        } else {
            $checks['operational_review_accepted'] = false;
            if ($opDecision === 'rejected') {
                $checks['not_rejected'] = false;
                $reasons[] = "Candidate model '{$modelVersion}' was rejected during operational review.";
            } else {
                $reasons[] = 'Administrator operational review is missing or not accepted.';
            }
        }

        $ready = empty($reasons);
        $status = $automatedTechPassed ? ($ready ? 'awaiting_approval' : ($opDecision === 'rejected' ? 'rejected' : 'awaiting_approval')) : 'under_evaluation';
        $statusLabel = $automatedTechPassed ? 'Awaiting approval' : 'Under evaluation';

        return [
            'ready' => $ready,
            'status' => $status,
            'status_label' => $statusLabel,
            'model_version' => $modelVersion,
            'as_of_date' => $asOfDate,
            'reasons' => $reasons,
            'checks' => $checks,
            'quality_details' => $qualityResult,
            'review_details' => $reviewStatus,
            'observation_details' => $obsResult,
        ];
    }

    /**
     * Activates a candidate Store model after verifying all gating criteria.
     * Scoped for local Administrator approval and replacement (ADR-0022).
     * Transitions the model out of observation mode into live Store forecasting,
     * preserves compatible previous approved model, writes Protected Audit Records,
     * and queues a fresh operational forecast for the completed-day cutoff.
     */
    public function activateStoreModel(
        string $modelVersion,
        string $asOfDate,
        int $actorId,
        string $justification = '',
        string $actorRole = 'admin',
        ?string $ipAddress = null
    ): array {
        // Enforce role authorization (ADR-0022)
        if (!in_array($actorRole, ['admin', 'super_admin'], true)) {
            throw new DomainException("Unauthorized: Role '{$actorRole}' lacks model approval authority.");
        }

        // Check if candidate was previously rejected
        $reviewStatus = $this->promotionService->getReviewStatus($modelVersion);
        if (($reviewStatus['operational_review']['decision'] ?? null) === 'rejected') {
            throw new InvalidArgumentException("Store model activation blocked: Candidate model '{$modelVersion}' was rejected during operational review.");
        }

        // Record operational approval decision by local Administrator (ADR-0022)
        $this->promotionService->recordOperationalReview(
            $modelVersion,
            'accepted',
            $justification ?: 'Approved for Store demand forecasting by local Administrator',
            $actorId
        );

        $readiness = $this->validateActivationReadiness($modelVersion, $asOfDate);
        if (!$readiness['ready']) {
            throw new InvalidArgumentException(
                "Store model activation blocked: " . implode(' ', $readiness['reasons'])
            );
        }

        $this->pdo->beginTransaction();
        try {
            // Retrieve current approved model
            $stmt = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'");
            $currentApproved = (string)($stmt->fetchColumn() ?: '');

            // Preserve previous approved model (if not already current and not empty)
            if ($currentApproved !== '' && $currentApproved !== $modelVersion) {
                $this->setSetting('ml_previous_approved_model_version', $currentApproved, $actorId);
            }

            // Set new approved Store model
            $this->setSetting('ml_approved_model_version', $modelVersion, $actorId);

            // Exit observation mode
            $this->setSetting('ml_observation_mode', '0', $actorId);

            // Ensure recommendations are active
            $this->setSetting('ml_recommendations_suspended', '0', $actorId);

            // Snapshot effective gates
            $thresholds = $this->evaluationService->getThresholds();

            // Record durable activation metadata
            $activationRecord = [
                'model_version' => $modelVersion,
                'as_of_date' => $asOfDate,
                'activated_by' => $actorId,
                'actor_role' => $actorRole,
                'activated_at' => date('Y-m-d H:i:s'),
                'decision' => 'approved',
                'justification' => $justification,
                'previous_model_version' => $currentApproved,
                'effective_gates' => $thresholds,
                'observation_completed_days' => $readiness['observation_details']['completed_days'] ?? null,
                'quality_summary' => $readiness['quality_details']['reason'] ?? '',
                'quality_details' => $readiness['quality_details'] ?? null,
                'observation_details' => $readiness['observation_details'] ?? null,
            ];

            $this->setSetting('ml_store_activation_record', json_encode($activationRecord, JSON_THROW_ON_ERROR), $actorId);

            // Write Protected Audit Record
            if (function_exists('log_activity')) {
                log_activity(
                    $this->pdo,
                    $actorId,
                    'Activate Store Forecasting Model',
                    'Demand Forecasting',
                    null,
                    $currentApproved ?: null,
                    $modelVersion,
                    $ipAddress ?? '127.0.0.1',
                    'store_operation',
                    [
                        'decision' => 'approved',
                        'candidate' => $modelVersion,
                        'effective_gates' => $thresholds,
                        'previous_model' => $currentApproved,
                        'as_of_date' => $asOfDate,
                        'justification' => $justification,
                        'actor_role' => $actorRole,
                    ]
                );
            }

            // Queue a fresh operational forecast for the completed-day cutoff after approval
            $jobService = new \App\Services\MlJobService($this->pdo);
            $jobId = $jobService->enqueue('forecast_generation', $actorId, $actorRole, $ipAddress ?? '127.0.0.1', $asOfDate, $modelVersion);
            $activationRecord['operational_forecast_job_id'] = $jobId;

            $this->pdo->commit();
            return $activationRecord;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Rejects a candidate Store model during operational review.
     * Preserves approved models, historical forecasts, and Store records intact.
     */
    public function rejectCandidate(
        string $modelVersion,
        int $actorId,
        string $reason = '',
        string $actorRole = 'admin',
        ?string $ipAddress = null
    ): array {
        if (!in_array($actorRole, ['admin', 'super_admin'], true)) {
            throw new DomainException("Unauthorized: Role '{$actorRole}' lacks model rejection authority.");
        }

        $trimmed = trim($modelVersion);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Candidate model version cannot be empty.');
        }

        $this->pdo->beginTransaction();
        try {
            $rejectionRecord = [
                'model_version' => $trimmed,
                'rejected_by' => $actorId,
                'actor_role' => $actorRole,
                'rejected_at' => date('Y-m-d H:i:s'),
                'decision' => 'rejected',
                'reason' => $reason,
            ];

            $this->setSetting("ml_store_rejection_{$trimmed}", json_encode($rejectionRecord, JSON_THROW_ON_ERROR), $actorId);
            $this->promotionService->recordOperationalReview($trimmed, 'rejected', $reason ?: 'Rejected by local Administrator', $actorId);

            if (function_exists('log_activity')) {
                log_activity(
                    $this->pdo,
                    $actorId,
                    'Reject Store Forecasting Candidate',
                    'Demand Forecasting',
                    null,
                    null,
                    $trimmed,
                    $ipAddress ?? '127.0.0.1',
                    'store_operation',
                    [
                        'decision' => 'rejected',
                        'candidate' => $trimmed,
                        'reason' => $reason,
                        'actor_role' => $actorRole,
                        'rejected_at' => date('Y-m-d H:i:s'),
                    ]
                );
            }

            $this->pdo->commit();
            return $rejectionRecord;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Performs a clean, reversible rollback to the previous approved Store model version.
     * Restores previous compatible model and queues a fresh operational forecast.
     */
    public function rollbackStoreModel(
        int $actorId,
        string $reason = '',
        string $actorRole = 'admin',
        ?string $ipAddress = null,
        ?string $asOfDate = null
    ): array {
        if (!in_array($actorRole, ['admin', 'super_admin'], true)) {
            throw new DomainException("Unauthorized: Role '{$actorRole}' lacks rollback authority.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_previous_approved_model_version'");
            $previous = (string)($stmt->fetchColumn() ?: '');

            if ($previous === '') {
                throw new RuntimeException("Rollback rejected: no compatible previous approved Store model artifact found.");
            }

            // Never roll back to demo artifacts
            $lower = strtolower($previous);
            if (str_contains($lower, 'demo') || str_contains($lower, 'kaggle')) {
                throw new RuntimeException("Rollback rejected: previous model version '$previous' is a demo/kaggle artifact. Demo artifacts cannot be activated in Store operations.");
            }

            $stmtCurrent = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'");
            $current = (string)($stmtCurrent->fetchColumn() ?: '');

            // Restore previous model
            $this->setSetting('ml_approved_model_version', $previous, $actorId);
            // Clear previous model to avoid circular rollbacks
            $this->setSetting('ml_previous_approved_model_version', '', $actorId);

            // Restore live operation
            $this->setSetting('ml_observation_mode', '0', $actorId);
            $this->setSetting('ml_recommendations_suspended', '0', $actorId);

            $rollbackRecord = [
                'rolled_back_from' => $current,
                'restored_model_version' => $previous,
                'rolled_back_by' => $actorId,
                'actor_role' => $actorRole,
                'rolled_back_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
                'decision' => 'rollback',
            ];

            $this->setSetting('ml_store_rollback_record', json_encode($rollbackRecord, JSON_THROW_ON_ERROR), $actorId);

            if (function_exists('log_activity')) {
                log_activity(
                    $this->pdo,
                    $actorId,
                    'Rollback Store Forecasting Model',
                    'Demand Forecasting',
                    null,
                    $current ?: null,
                    $previous,
                    $ipAddress ?? '127.0.0.1',
                    'store_operation',
                    [
                        'decision' => 'rollback',
                        'restored_model' => $previous,
                        'previous_active' => $current,
                        'reason' => $reason,
                        'actor_role' => $actorRole,
                    ]
                );
            }

            // Queue a fresh operational forecast for the completed-day cutoff after rollback
            $cutoff = $asOfDate ?? (new DateTime('now', new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d');
            $jobService = new \App\Services\MlJobService($this->pdo);
            $jobId = $jobService->enqueue('forecast_generation', $actorId, $actorRole, $ipAddress ?? '127.0.0.1', $cutoff, $previous);
            $rollbackRecord['operational_forecast_job_id'] = $jobId;

            $this->pdo->commit();
            return $rollbackRecord;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Suspends replenishment recommendations immediately without affecting routine Store operations.
     * Cashier sales, inventory counts, adjustments, and purchasing continue unaffected.
     */
    public function suspendRecommendations(int $actorId, string $reason = '', string $actorRole = 'admin'): void
    {
        if (!in_array($actorRole, ['admin', 'super_admin'], true)) {
            throw new DomainException("Unauthorized role for suspension");
        }

        $this->setSetting('ml_recommendations_suspended', '1', $actorId);
        $this->setSetting('ml_recommendations_suspended_reason', json_encode([
            'suspended_by' => $actorId,
            'actor_role' => $actorRole,
            'suspended_at' => date('Y-m-d H:i:s'),
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR), $actorId);

        if (function_exists('log_activity')) {
            log_activity($this->pdo, $actorId, 'Suspend Replenishment Recommendations', 'Demand Forecasting', null, null, null, null, 'store_operation', [
                'reason' => $reason,
                'actor_role' => $actorRole,
            ]);
        }
    }

    /**
     * Resumes replenishment recommendations.
     */
    public function resumeRecommendations(int $actorId, string $actorRole = 'admin'): void
    {
        if (!in_array($actorRole, ['admin', 'super_admin'], true)) {
            throw new DomainException("Unauthorized role for resuming recommendations");
        }

        $this->setSetting('ml_recommendations_suspended', '0', $actorId);

        if (function_exists('log_activity')) {
            log_activity($this->pdo, $actorId, 'Resume Replenishment Recommendations', 'Demand Forecasting', null, null, null, null, 'store_operation', [
                'actor_role' => $actorRole,
            ]);
        }
    }

    /**
     * Gets the full activation and governance status of the Store forecasting pipeline.
     */
    public function getActivationStatus(): array
    {
        $settings = [];
        try {
            $stmt = $this->pdo->query("
                SELECT setting_key, setting_value 
                FROM platform_settings 
                WHERE setting_key IN (
                    'ml_approved_model_version', 
                    'ml_previous_approved_model_version',
                    'ml_observation_mode',
                    'ml_recommendations_suspended',
                    'ml_store_activation_record',
                    'ml_store_rollback_record',
                    'schedule_daily_forecast_time',
                    'schedule_weekly_retrain_day',
                    'schedule_weekly_retrain_time'
                )
            ");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {}

        $approvedModel = $settings['ml_approved_model_version'] ?? '';
        $previousModel = $settings['ml_previous_approved_model_version'] ?? '';
        $isObservation = ($settings['ml_observation_mode'] ?? '0') === '1';
        $isSuspended = ($settings['ml_recommendations_suspended'] ?? '0') === '1';

        $activationRecord = null;
        if (!empty($settings['ml_store_activation_record'])) {
            $activationRecord = json_decode($settings['ml_store_activation_record'], true);
        }

        $rollbackRecord = null;
        if (!empty($settings['ml_store_rollback_record'])) {
            $rollbackRecord = json_decode($settings['ml_store_rollback_record'], true);
        }

        return [
            'approved_model_version' => $approvedModel,
            'previous_model_version' => $previousModel,
            'is_observation_mode' => $isObservation,
            'is_recommendations_suspended' => $isSuspended,
            'is_store_activated' => ($approvedModel !== '' && !$isObservation),
            'activation_record' => $activationRecord,
            'rollback_record' => $rollbackRecord,
            'schedule' => [
                'daily_forecast_time' => $settings['schedule_daily_forecast_time'] ?? '01:00',
                'weekly_retrain_day' => $settings['schedule_weekly_retrain_day'] ?? 'Sunday',
                'weekly_retrain_time' => $settings['schedule_weekly_retrain_time'] ?? '01:00',
            ],
        ];
    }

    /**
     * Builds a comprehensive plain-English approval summary for local Administrator review.
     * Names covered/excluded products, weekly Seasonal Naive comparison method and periods,
     * required checks, observation progress, and remaining limitations.
     * Shows a valid matching incumbent comparison when available, or explicitly states its absence.
     */
    public function getApprovalSummary(?string $modelVersion = null, ?string $asOfDate = null): array
    {
        if ($asOfDate === null) {
            $asOfDate = (new DateTime('now', new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d');
        }

        $status = $this->getActivationStatus();
        $approvedModel = $status['approved_model_version'];
        $previousModel = $status['previous_model_version'];

        // Determine candidate model version
        if ($modelVersion === null || trim($modelVersion) === '') {
            try {
                $stmt = $this->pdo->query("SELECT model_version FROM model_training_runs WHERE status = 'completed' ORDER BY training_run_id DESC LIMIT 1");
                $modelVersion = (string)($stmt->fetchColumn() ?: '');
            } catch (Throwable) {
                $modelVersion = '';
            }
            if ($modelVersion === '') {
                try {
                    $stmt = $this->pdo->query("SELECT model_version FROM ml_model_evaluations ORDER BY evaluation_id DESC LIMIT 1");
                    $modelVersion = (string)($stmt->fetchColumn() ?: '');
                } catch (Throwable) {
                    $modelVersion = '';
                }
            }
        }

        if ($modelVersion === '') {
            return [
                'has_candidate' => false,
                'candidate_version' => '',
                'approved_version' => $approvedModel,
                'previous_version' => $previousModel,
                'overall_status' => 'no_candidate',
                'status_label' => 'No candidate available',
                'operational_status' => $approvedModel !== '' ? 'Ready to generate forecasts' : 'Not approved for routine use',
                'message' => 'No completed candidate model training is available for review yet.',
                'can_approve' => false,
                'can_reject' => false,
                'can_rollback' => ($previousModel !== ''),
                'limitations' => $this->getStandardLimitations(),
            ];
        }

        // 1. Candidate Quality Evaluation
        $qualityResult = $this->evaluationService->evaluateCandidateQuality($modelVersion);
        $candidateEvals = $this->evaluationService->getEvaluationsByModelVersion($modelVersion);
        $thresholds = $this->evaluationService->getThresholds();

        $horizonsData = [];
        $dataWindowStart = null;
        $dataWindowEnd = null;

        foreach ($candidateEvals as $row) {
            $h = (int)$row['horizon_days'];
            $maxWape = $thresholds[(string)$h] ?? null;
            $dataWindowStart ??= $row['data_window_start'] ?? null;
            $dataWindowEnd = $row['data_window_end'] ?? $dataWindowEnd;

            $horizonsData[$h] = [
                'horizon_days' => $h,
                'mae' => $row['mae'] !== null ? round((float)$row['mae'], 4) : null,
                'rmse' => $row['rmse'] !== null ? round((float)$row['rmse'], 4) : null,
                'wape' => $row['wape'] !== null ? round((float)$row['wape'], 4) : null,
                'baseline_mae' => $row['baseline_mae'] !== null ? round((float)$row['baseline_mae'], 4) : null,
                'baseline_rmse' => $row['baseline_rmse'] !== null ? round((float)$row['baseline_rmse'], 4) : null,
                'baseline_wape' => $row['baseline_wape'] !== null ? round((float)$row['baseline_wape'], 4) : null,
                'max_wape_limit' => $maxWape,
                'gate_outcome' => $row['candidate_gate_outcome'] ?? 'pending',
                'gate_status' => $this->determineHorizonGateStatus($row, $maxWape),
            ];
        }

        // 2. Incumbent Model Comparison
        $incumbentComparison = [
            'available' => false,
            'version' => $approvedModel,
            'status_message' => 'No previous approved model available for comparison (First Store model qualification)',
            'horizons' => [],
        ];

        if ($approvedModel !== '' && $approvedModel !== $modelVersion) {
            $incumbentEvals = $this->evaluationService->getEvaluationsByModelVersion($approvedModel);
            if (!empty($incumbentEvals)) {
                $incumbentHorizons = [];
                foreach ($incumbentEvals as $iRow) {
                    $ih = (int)$iRow['horizon_days'];
                    $incumbentHorizons[$ih] = [
                        'horizon_days' => $ih,
                        'mae' => $iRow['mae'] !== null ? round((float)$iRow['mae'], 4) : null,
                        'rmse' => $iRow['rmse'] !== null ? round((float)$iRow['rmse'], 4) : null,
                        'wape' => $iRow['wape'] !== null ? round((float)$iRow['wape'], 4) : null,
                    ];
                }
                $incumbentComparison = [
                    'available' => true,
                    'version' => $approvedModel,
                    'status_message' => "Matching incumbent comparison against approved model {$approvedModel}",
                    'horizons' => $incumbentHorizons,
                ];
            } else {
                $incumbentComparison['status_message'] = "Approved model {$approvedModel} lacks recorded holdout evaluations for comparison.";
            }
        }

        // 3. Observation Progress (28 target dates holdout)
        $obsProgress = $this->evaluationService->getCandidateObservationProgress($modelVersion, $asOfDate);

        // 4. Products Covered and Excluded with Blocked Actions
        $productSummary = $this->getProductCoverageSummary($asOfDate, $modelVersion);

        // 5. Readiness and Status Wording
        $readiness = $this->validateActivationReadiness($modelVersion, $asOfDate);
        $isCurrentApproved = ($approvedModel === $modelVersion);
        $automatedTechPassed = $readiness['checks']['quality_gates_passed'] && $readiness['checks']['observation_period_completed'] && $readiness['checks']['not_demo_artifact'] && $readiness['checks']['valid_version_format'];
        $isQualified = $automatedTechPassed && $readiness['checks']['not_rejected'];

        if ($isCurrentApproved) {
            $overallStatus = 'approved';
            $statusLabel = 'Selected as approved model';
            $operationalStatus = !empty($productSummary['covered']) ? 'Ready to generate forecasts' : 'Blocked: missing valid current inputs';
            $canApprove = false;
        } elseif ($isQualified) {
            $overallStatus = 'awaiting_approval';
            $statusLabel = 'Awaiting approval';
            $operationalStatus = 'Awaiting local Administrator approval';
            $canApprove = true;
        } else {
            $overallStatus = 'under_evaluation';
            $statusLabel = 'Under evaluation';
            $operationalStatus = 'Under evaluation: technical qualification incomplete';
            $canApprove = false;
        }

        $canReject = !$isCurrentApproved && $readiness['checks']['not_rejected'];
        $canRollback = ($previousModel !== '' && $previousModel !== $approvedModel);

        // Latest background ML job status
        $latestJob = null;
        try {
            $jobStmt = $this->pdo->query("SELECT job_id, trigger_type, status, attempts, queued_at, completed_at, operator_message FROM ml_jobs ORDER BY job_id DESC LIMIT 1");
            $latestJob = $jobStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {}

        return [
            'has_candidate' => true,
            'candidate_version' => $modelVersion,
            'approved_version' => $approvedModel,
            'previous_version' => $previousModel,
            'as_of_date' => $asOfDate,
            'overall_status' => $overallStatus,
            'status_label' => $statusLabel,
            'operational_status' => $operationalStatus,
            'is_current_approved' => $isCurrentApproved,
            'can_approve' => $canApprove,
            'can_reject' => $canReject,
            'can_rollback' => $canRollback,
            'is_recommendations_suspended' => $status['is_recommendations_suspended'],
            'is_observation_mode' => $status['is_observation_mode'],
            'comparison_method' => "Weekly Seasonal Naive (repeating last week's recorded sales for the matching day of the week)",
            'evaluation_periods' => [
                'start' => $dataWindowStart ?? 'Chronological start',
                'end' => $dataWindowEnd ?? 'Chronological end',
                'holdouts' => '3 chronological 28-day holdout folds (84 days total)',
            ],
            'candidate_quality' => [
                'passed' => $qualityResult['passed'],
                'reason' => $qualityResult['reason'],
                'horizons' => $horizonsData,
            ],
            'incumbent_comparison' => $incumbentComparison,
            'observation_progress' => [
                'usable_days' => $obsProgress['usable_observed_days'],
                'required_days' => $obsProgress['required_observed_days'],
                'completed_days' => $obsProgress['completed_days'],
                'observation_complete' => $obsProgress['observation_complete'],
                'status_label' => $obsProgress['status_label'],
                'explanation' => $obsProgress['explanation'],
            ],
            'products' => $productSummary,
            'required_checks' => [
                'valid_version_format' => [
                    'label' => 'Valid model version format',
                    'passed' => $readiness['checks']['valid_version_format'],
                ],
                'not_demo_artifact' => [
                    'label' => 'Store production dataset (not Demo/Kaggle artifact)',
                    'passed' => $readiness['checks']['not_demo_artifact'],
                ],
                'quality_gates_passed' => [
                    'label' => '7/14/28-day quality gates pass configured WAPE limits',
                    'passed' => $readiness['checks']['quality_gates_passed'],
                ],
                'baseline_outperformed' => [
                    'label' => 'Outperforms weekly Seasonal Naive baseline across horizons',
                    'passed' => $qualityResult['passed'],
                ],
                'observation_period_completed' => [
                    'label' => '28 completed target dates backed by usable Store observations',
                    'passed' => $readiness['checks']['observation_period_completed'],
                ],
                'not_rejected' => [
                    'label' => 'Candidate not rejected in operational review',
                    'passed' => $readiness['checks']['not_rejected'],
                ],
            ],
            'limitations' => $this->getStandardLimitations(),
            'latest_job' => $latestJob,
        ];
    }

    private function determineHorizonGateStatus(array $row, ?float $maxWape): string
    {
        if ($maxWape === null) {
            return 'blocked';
        }
        $w = isset($row['wape']) ? (float)$row['wape'] : null;
        $bw = isset($row['baseline_wape']) ? (float)$row['baseline_wape'] : null;
        if ($w === null) {
            return 'blocked';
        }
        if ($w > $maxWape || ($bw !== null && $w >= $bw)) {
            return 'failed';
        }
        return 'passed';
    }

    private function getProductCoverageSummary(string $asOfDate, string $candidateVersion): array
    {
        $covered = [];
        $excluded = [];

        try {
            $stmt = $this->pdo->query("SELECT product_id, sku, product_name, status FROM products ORDER BY product_id ASC");
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $products = [];
        }

        $eligibilityService = new StoreTrainingEligibilityService();

        foreach ($products as $p) {
            $pid = (int)$p['product_id'];
            $name = $p['product_name'] ?: "Product #{$pid}";
            $sku = $p['sku'] ?: "SKU-{$pid}";

            try {
                $eval = $eligibilityService->evaluateProductEligibility($this->pdo, $pid, $asOfDate);
            } catch (Throwable $e) {
                $eval = ['eligible' => false, 'reason' => $e->getMessage(), 'accumulated_usable_days' => 0];
            }

            if (!empty($eval['eligible'])) {
                $covered[] = [
                    'product_id' => $pid,
                    'sku' => $sku,
                    'product_name' => $name,
                    'accumulated_usable_days' => $eval['accumulated_usable_days'] ?? 140,
                    'status' => 'Ready for training',
                    'guidance' => 'Included in candidate model evaluation and eligible for operational forecasts upon approval.',
                ];
            } else {
                $days = $eval['accumulated_usable_days'] ?? 0;
                $reason = $eval['reason'] ?? "Insufficient usable history (has {$days}/140 accumulated days)";
                $needed = max(0, 140 - $days);
                $blockedAction = $needed > 0
                    ? "Accumulate {$needed} more usable sales days; replenishment continues on stock rules."
                    : "Resolve recent feature window / holdout gap; replenishment continues on stock rules.";

                $excluded[] = [
                    'product_id' => $pid,
                    'sku' => $sku,
                    'product_name' => $name,
                    'accumulated_usable_days' => $days,
                    'status' => 'Insufficient Data',
                    'exclusion_reason' => $reason,
                    'blocked_next_action' => $blockedAction,
                ];
            }
        }

        return [
            'covered' => $covered,
            'excluded' => $excluded,
            'covered_count' => count($covered),
            'excluded_count' => count($excluded),
        ];
    }

    private function getStandardLimitations(): array
    {
        return [
            'Prediction error (WAPE) measures deviation from recorded sales; it is an error metric, not an accuracy percentage.',
            'Technical qualification (passing quality gates and 28-day observation) does not constitute automatic approval; local Administrator review is required.',
            'Recorded sales reflect completed transactions; customer demand lost during stockout intervals cannot be observed or reconstructed.',
            'Seasonal Naive comparison repeats weekly sales; sudden seasonal or market shifts require manual inventory oversight.',
            'Past chronological holdout evaluation does not guarantee future prediction accuracy.',
            'Excluded products with insufficient history or feature window gaps automatically fall back to stock rules.',
        ];
    }

    /**
     * Checks actual production runtime capabilities (Windows Store PC or Linux VPS).
     */
    public function verifyRuntimeEnvironment(string $backendPath): array
    {
        $osFamily = PHP_OS_FAMILY;
        $modelsDir = $backendPath . '/storage/ml_models';
        $logsDir = $backendPath . '/storage/logs';

        $storageReady = is_dir($modelsDir) && is_writable($modelsDir);
        $logsReady = is_dir($logsDir) && is_writable($logsDir);

        return [
            'os_family' => $osFamily,
            'php_version' => PHP_VERSION,
            'storage_models_writable' => $storageReady,
            'storage_logs_writable' => $logsReady,
            'supported_runtime' => in_array($osFamily, ['Windows', 'Linux'], true),
        ];
    }

    private function setSetting(string $key, string $value, int $updatedBy): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM platform_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        if ((int)$stmt->fetchColumn() > 0) {
            $update = $this->pdo->prepare("UPDATE platform_settings SET setting_value = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?");
            $update->execute([$value, $updatedBy, $key]);
        } else {
            $insert = $this->pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
            $insert->execute([$key, $value, $updatedBy]);
        }
    }
}
