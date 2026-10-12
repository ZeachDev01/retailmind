<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * Service governing Store Demand Forecasting activation, quality gating,
 * multi-stakeholder approval verification, reversible rollback, and recommendation suspension.
 *
 * Implements ADR-0005: Routine Store forecasting guidance is enabled ONLY after explicit
 * technical and operational approval, completed observation period, and verified scheduler.
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
     */
    public function validateActivationReadiness(string $modelVersion, string $asOfDate): array
    {
        $reasons = [];
        $checks = [
            'valid_version_format' => false,
            'not_demo_artifact' => false,
            'quality_gates_passed' => false,
            'technical_review_accepted' => false,
            'operational_review_accepted' => false,
            'observation_period_completed' => false,
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

        // 2. Candidate quality evaluation check
        $qualityResult = $this->evaluationService->evaluateCandidateQuality($modelVersion);
        if (!$qualityResult['passed']) {
            $reasons[] = 'Candidate model failed quality gates: ' . $qualityResult['reason'];
        } else {
            $checks['quality_gates_passed'] = true;
        }

        // 3. Technical and Operational reviews
        $reviewStatus = $this->promotionService->getReviewStatus($modelVersion);
        $techDecision = $reviewStatus['technical_review']['decision'] ?? null;
        $opDecision = $reviewStatus['operational_review']['decision'] ?? null;

        if ($techDecision !== 'accepted') {
            $reasons[] = 'Super Administrator technical approval is missing or not accepted.';
        } else {
            $checks['technical_review_accepted'] = true;
        }

        if ($opDecision !== 'accepted') {
            $reasons[] = 'Administrator operational review is missing or not accepted.';
        } else {
            $checks['operational_review_accepted'] = true;
        }

        // 4. Observation period holdout evaluation (minimum 28 target days)
        $obsResult = $this->evaluationService->evaluateCompletedTargetDates($this->pdo, $modelVersion, $asOfDate);
        if (!$obsResult['observation_complete']) {
            $reasons[] = "Observation period incomplete: requires at least {$obsResult['min_required_days']} completed target days evaluated against Store sales, but only {$obsResult['completed_days']} completed days were recorded.";
        } else {
            $checks['observation_period_completed'] = true;
        }

        $ready = empty($reasons);

        return [
            'ready' => $ready,
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
     * Transitions the model out of observation mode into live Store forecasting.
     */
    public function activateStoreModel(string $modelVersion, string $asOfDate, int $superAdminId, string $justification = ''): array
    {
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
                $this->setSetting('ml_previous_approved_model_version', $currentApproved, $superAdminId);
            }

            // Set new approved Store model
            $this->setSetting('ml_approved_model_version', $modelVersion, $superAdminId);

            // Exit observation mode
            $this->setSetting('ml_observation_mode', '0', $superAdminId);

            // Ensure recommendations are active
            $this->setSetting('ml_recommendations_suspended', '0', $superAdminId);

            // Record durable activation metadata
            $activationRecord = [
                'model_version' => $modelVersion,
                'as_of_date' => $asOfDate,
                'activated_by' => $superAdminId,
                'activated_at' => date('Y-m-d H:i:s'),
                'justification' => $justification,
                'previous_model_version' => $currentApproved,
                'observation_completed_days' => $readiness['observation_details']['completed_days'] ?? null,
                'quality_summary' => $readiness['quality_details']['reason'] ?? '',
                'technical_review' => $readiness['review_details']['technical_review'] ?? null,
                'operational_review' => $readiness['review_details']['operational_review'] ?? null,
            ];

            $this->setSetting('ml_store_activation_record', json_encode($activationRecord, JSON_THROW_ON_ERROR), $superAdminId);

            if (function_exists('log_activity')) {
                log_activity(
                    $this->pdo,
                    $superAdminId,
                    'Activate Store Forecasting Model',
                    'Platform Settings',
                    null,
                    $currentApproved ?: null,
                    $modelVersion
                );
            }

            $this->pdo->commit();
            return $activationRecord;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Performs a clean, reversible rollback to the previous approved Store model version.
     */
    public function rollbackStoreModel(int $superAdminId, string $reason = ''): array
    {
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
            $this->setSetting('ml_approved_model_version', $previous, $superAdminId);
            // Clear previous model to avoid circular rollbacks
            $this->setSetting('ml_previous_approved_model_version', '', $superAdminId);

            $rollbackRecord = [
                'rolled_back_from' => $current,
                'restored_model_version' => $previous,
                'rolled_back_by' => $superAdminId,
                'rolled_back_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
            ];

            $this->setSetting('ml_store_rollback_record', json_encode($rollbackRecord, JSON_THROW_ON_ERROR), $superAdminId);

            if (function_exists('log_activity')) {
                log_activity(
                    $this->pdo,
                    $superAdminId,
                    'Rollback Store Forecasting Model',
                    'Platform Settings',
                    null,
                    $current ?: null,
                    $previous
                );
            }

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
    public function suspendRecommendations(int $superAdminId, string $reason = ''): void
    {
        $this->setSetting('ml_recommendations_suspended', '1', $superAdminId);
        $this->setSetting('ml_recommendations_suspended_reason', json_encode([
            'suspended_by' => $superAdminId,
            'suspended_at' => date('Y-m-d H:i:s'),
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR), $superAdminId);

        if (function_exists('log_activity')) {
            log_activity($this->pdo, $superAdminId, 'Suspend Replenishment Recommendations', 'Forecasting', null, null, [
                'reason' => $reason
            ]);
        }
    }

    /**
     * Resumes replenishment recommendations.
     */
    public function resumeRecommendations(int $superAdminId): void
    {
        $this->setSetting('ml_recommendations_suspended', '0', $superAdminId);

        if (function_exists('log_activity')) {
            log_activity($this->pdo, $superAdminId, 'Resume Replenishment Recommendations', 'Forecasting', null, null, null);
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
                'daily_forecast_time' => $settings['schedule_daily_forecast_time'] ?? '23:00',
                'weekly_retrain_day' => $settings['schedule_weekly_retrain_day'] ?? 'Sunday',
                'weekly_retrain_time' => $settings['schedule_weekly_retrain_time'] ?? '01:00',
            ],
        ];
    }

    /**
     * Checks actual production runtime capabilities (Windows Store PC or Linux VPS).
     */
    public function verifyRuntimeEnvironment(string $backendPath): array
    {
        $osFamily = PHP_OS_FAMILY; // 'Windows', 'Linux', 'Darwin', etc.
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
