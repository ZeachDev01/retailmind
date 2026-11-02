<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/**
 * Service governing Store Demand Forecasting model lifecycle, automated quality gates,
 * dual technical/operational reviews, live Store activation, reversible rollbacks,
 * recommendation suspension, and centralized platform settings persistence.
 *
 * Implements ADR-0005, ADR-0022: Routine Store forecasting guidance is enabled
 * only after explicit operational approval by local Administrator (or Super Admin),
 * passing automated quality gates, at least 28 completed observation target days,
 * genuine Store trading history (demo models strictly barred), and verified scheduler.
 */
class StoreModelGovernanceService
{
    protected PDO $pdo;
    protected CandidateEvaluationService $evaluationService;

    public function __construct(
        PDO $pdo,
        ?CandidateEvaluationService $evaluationService = null
    ) {
        $this->pdo = $pdo;
        $this->evaluationService = $evaluationService ?? new CandidateEvaluationService($pdo, $this);
    }

    /**
     * Centralized platform settings getter.
     */
    public function getSetting(string $key, ?string $default = null): ?string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return $val !== false ? (string)$val : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }

    /**
     * Centralized platform settings setter.
     */
    public function setSetting(string $key, string $value, int $updatedBy): void
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

    /**
     * Centralized multiple platform settings getter.
     */
    public function getSettings(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $this->pdo->prepare("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ($placeholders)");
            $stmt->execute($keys);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getApprovedModelVersion(): string
    {
        return (string)($this->getSetting('ml_approved_model_version', '') ?? '');
    }

    public function getPreviousApprovedModelVersion(): string
    {
        return (string)($this->getSetting('ml_previous_approved_model_version', '') ?? '');
    }

    public function isObservationMode(): bool
    {
        $val = $this->getSetting('ml_observation_mode', '0');
        return $val === '1' || $val === 'true';
    }

    public function setObservationMode(bool $enabled, int $updatedBy): void
    {
        $this->setSetting('ml_observation_mode', $enabled ? '1' : '0', $updatedBy);
    }

    public function isRecommendationsSuspended(): bool
    {
        $val = $this->getSetting('ml_recommendations_suspended', '0');
        return $val === '1' || $val === 'true';
    }

    public function setRecommendationsSuspended(bool $suspended, int $updatedBy, string $reason = ''): void
    {
        $this->setSetting('ml_recommendations_suspended', $suspended ? '1' : '0', $updatedBy);
        if ($suspended && $reason !== '') {
            $this->setSetting('ml_recommendations_suspended_reason', json_encode([
                'suspended_by' => $updatedBy,
                'suspended_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR), $updatedBy);
        }
    }

    public function suspendRecommendations(int $updatedBy, string $reason = ''): void
    {
        $this->setRecommendationsSuspended(true, $updatedBy, $reason);
        if (function_exists('log_activity')) {
            log_activity($this->pdo, $updatedBy, 'Suspend Replenishment Recommendations', 'Forecasting', null, null, [
                'reason' => $reason
            ]);
        }
    }

    public function resumeRecommendations(int $updatedBy): void
    {
        $this->setRecommendationsSuspended(false, $updatedBy);
        if (function_exists('log_activity')) {
            log_activity($this->pdo, $updatedBy, 'Resume Replenishment Recommendations', 'Forecasting', null, null, null);
        }
    }

    public function getThresholds(): array
    {
        $rows = $this->getSettings(['ml_max_wape_7d', 'ml_max_wape_14d', 'ml_max_wape_28d']);
        return [
            '7' => isset($rows['ml_max_wape_7d']) && is_numeric($rows['ml_max_wape_7d']) ? (float)$rows['ml_max_wape_7d'] : null,
            '14' => isset($rows['ml_max_wape_14d']) && is_numeric($rows['ml_max_wape_14d']) ? (float)$rows['ml_max_wape_14d'] : null,
            '28' => isset($rows['ml_max_wape_28d']) && is_numeric($rows['ml_max_wape_28d']) ? (float)$rows['ml_max_wape_28d'] : null,
        ];
    }

    public function setThresholds(array $thresholds, int $updatedBy): void
    {
        foreach (['7', '14', '28'] as $horizon) {
            $key = "ml_max_wape_{$horizon}d";
            if (isset($thresholds[$key])) {
                $this->setSetting($key, (string)$thresholds[$key], $updatedBy);
            }
        }
    }

    public function recordTechnicalReview(string $modelVersion, string $decision, string $notes, int $superAdminId): void
    {
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException("Technical review decision must be 'accepted' or 'rejected'.");
        }

        $data = json_encode([
            'model_version' => $modelVersion,
            'decision' => $decision,
            'notes' => $notes,
            'reviewed_by' => $superAdminId,
            'reviewed_at' => date('Y-m-d H:i:s')
        ], JSON_THROW_ON_ERROR);

        $this->setSetting("ml_review_technical_{$modelVersion}", $data, $superAdminId);

        if (function_exists('log_activity')) {
            log_activity($this->pdo, $superAdminId, 'Technical Evaluation Review', 'Forecasting', null, null, [
                'model_version' => $modelVersion,
                'decision' => $decision,
                'notes' => $notes
            ]);
        }
    }

    public function recordOperationalReview(string $modelVersion, string $decision, string $notes, int $adminId): void
    {
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException("Operational review decision must be 'accepted' or 'rejected'.");
        }

        $data = json_encode([
            'model_version' => $modelVersion,
            'decision' => $decision,
            'notes' => $notes,
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s')
        ], JSON_THROW_ON_ERROR);

        $this->setSetting("ml_review_operational_{$modelVersion}", $data, $adminId);

        if (function_exists('log_activity')) {
            log_activity($this->pdo, $adminId, 'Operational Usefulness Review', 'Forecasting', null, null, [
                'model_version' => $modelVersion,
                'decision' => $decision,
                'notes' => $notes
            ]);
        }
    }

    public function getReviewStatus(string $modelVersion): array
    {
        $tech = null;
        $op = null;

        try {
            $techRaw = $this->getSetting("ml_review_technical_{$modelVersion}");
            if ($techRaw) {
                $tech = json_decode($techRaw, true);
            }

            $opRaw = $this->getSetting("ml_review_operational_{$modelVersion}");
            if ($opRaw) {
                $op = json_decode($opRaw, true);
            }
        } catch (Throwable $e) {}

        return [
            'technical_review' => $tech,
            'operational_review' => $op,
            'both_accepted' => (($tech['decision'] ?? '') === 'accepted' && ($op['decision'] ?? '') === 'accepted')
        ];
    }

    /**
     * Determines whether a candidate model version meets all criteria for live Store activation.
     * Enforces ADR-0005 quality and observation gates and ADR-0022 local Administrator operational approval.
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
        if ($trimmed !== '' && (str_contains($lower, 'demo') || str_contains($lower, 'kaggle'))) {
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
        $reviewStatus = $this->getReviewStatus($modelVersion);
        $techDecision = $reviewStatus['technical_review']['decision'] ?? null;
        $opDecision = $reviewStatus['operational_review']['decision'] ?? null;

        // Under ADR-0022: Technical review recording is available (Super Admin),
        // but not a hidden mandatory activation gate if unrecorded.
        // However, if technical review was explicitly recorded and rejected, it blocks activation.
        if ($techDecision === 'accepted') {
            $checks['technical_review_accepted'] = true;
        } elseif ($techDecision === 'rejected') {
            $reasons[] = 'Super Administrator technical review rejected this candidate.';
        }

        // Operational review by Administrator (or Super Admin acting operationally)
        if ($opDecision === 'accepted') {
            $checks['operational_review_accepted'] = true;
        } elseif ($opDecision === 'rejected') {
            $reasons[] = 'Administrator operational review rejected this candidate.';
        } else {
            $reasons[] = 'Administrator operational review is missing or not accepted.';
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
    public function activateStoreModel(string $modelVersion, string $asOfDate, int $actorId, string $justification = ''): array
    {
        $readiness = $this->validateActivationReadiness($modelVersion, $asOfDate);
        if (!$readiness['ready']) {
            throw new InvalidArgumentException(
                "Store model activation blocked: " . implode(' ', $readiness['reasons'])
            );
        }

        $this->pdo->beginTransaction();
        try {
            $currentApproved = $this->getApprovedModelVersion();

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

            // Record durable activation metadata
            $activationRecord = [
                'model_version' => $modelVersion,
                'as_of_date' => $asOfDate,
                'activated_by' => $actorId,
                'activated_at' => date('Y-m-d H:i:s'),
                'justification' => $justification,
                'previous_model_version' => $currentApproved,
                'observation_completed_days' => $readiness['observation_details']['completed_days'] ?? null,
                'quality_summary' => $readiness['quality_details']['reason'] ?? '',
                'technical_review' => $readiness['review_details']['technical_review'] ?? null,
                'operational_review' => $readiness['review_details']['operational_review'] ?? null,
            ];

            $this->setSetting('ml_store_activation_record', json_encode($activationRecord, JSON_THROW_ON_ERROR), $actorId);

            if (function_exists('log_activity')) {
                log_activity(
                    $this->pdo,
                    $actorId,
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
    public function rollbackStoreModel(int $actorId, string $reason = ''): array
    {
        $this->pdo->beginTransaction();
        try {
            $previous = $this->getPreviousApprovedModelVersion();

            if ($previous === '') {
                throw new RuntimeException("Rollback rejected: no compatible previous approved Store model artifact found.");
            }

            // Never roll back to demo artifacts
            $lower = strtolower($previous);
            if (str_contains($lower, 'demo') || str_contains($lower, 'kaggle')) {
                throw new RuntimeException("Rollback rejected: previous model version '$previous' is a demo/kaggle artifact. Demo artifacts cannot be activated in Store operations.");
            }

            $current = $this->getApprovedModelVersion();

            // Restore previous model
            $this->setSetting('ml_approved_model_version', $previous, $actorId);
            // Clear previous model to avoid circular rollbacks
            $this->setSetting('ml_previous_approved_model_version', '', $actorId);

            $rollbackRecord = [
                'rolled_back_from' => $current,
                'restored_model_version' => $previous,
                'rolled_back_by' => $actorId,
                'rolled_back_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
            ];

            $this->setSetting('ml_store_rollback_record', json_encode($rollbackRecord, JSON_THROW_ON_ERROR), $actorId);

            if (function_exists('log_activity')) {
                log_activity(
                    $this->pdo,
                    $actorId,
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
     * Direct / Super Admin model promotion (legacy platform promotion entry point).
     */
    public function promote(string $modelVersion, int $superAdminId): void
    {
        $evaluations = $this->evaluationService->getEvaluationsByModelVersion($modelVersion);
        if (count($evaluations) < 3) {
            throw new InvalidArgumentException("Incomplete candidate: missing chronological evaluations for $modelVersion");
        }

        foreach ($evaluations as $eval) {
            if ($eval['candidate_gate_outcome'] !== 'passed') {
                throw new InvalidArgumentException("Candidate $modelVersion failed evaluation gate at horizon " . $eval['horizon_days']);
            }
        }

        $thresholds = $this->getThresholds();
        $hasThresholds = ($thresholds['7'] !== null || $thresholds['14'] !== null || $thresholds['28'] !== null);
        if ($hasThresholds) {
            $gateResult = $this->evaluationService->evaluateCandidateQuality($modelVersion);
            if (!$gateResult['passed']) {
                throw new InvalidArgumentException("Candidate $modelVersion failed quality gate: " . $gateResult['reason']);
            }
        }

        $this->pdo->beginTransaction();
        try {
            $current = $this->getApprovedModelVersion();

            if ($current === $modelVersion) {
                $this->pdo->commit();
                return;
            }

            if ($current !== '') {
                $this->setSetting('ml_previous_approved_model_version', $current, $superAdminId);
            }

            $this->setSetting('ml_approved_model_version', $modelVersion, $superAdminId);

            if (function_exists('log_activity')) {
                log_activity($this->pdo, $superAdminId, 'Promote Forecasting Model', 'Platform Settings', null, $current ?: null, $modelVersion);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Direct / Super Admin model rollback (legacy platform promotion entry point).
     */
    public function rollback(int $superAdminId): void
    {
        $this->pdo->beginTransaction();
        try {
            $previous = $this->getPreviousApprovedModelVersion();

            if ($previous === '') {
                throw new RuntimeException("No compatible previous approved artifact found for rollback");
            }

            $current = $this->getApprovedModelVersion();

            $this->setSetting('ml_approved_model_version', $previous, $superAdminId);
            $this->setSetting('ml_previous_approved_model_version', '', $superAdminId);

            if (function_exists('log_activity')) {
                log_activity($this->pdo, $superAdminId, 'Rollback Forecasting Model', 'Platform Settings', null, $current ?: null, $previous);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Gets the full activation and governance status of the Store forecasting pipeline.
     */
    public function getActivationStatus(): array
    {
        $settings = $this->getSettings([
            'ml_approved_model_version', 
            'ml_previous_approved_model_version',
            'ml_observation_mode',
            'ml_recommendations_suspended',
            'ml_store_activation_record',
            'ml_store_rollback_record',
            'schedule_daily_forecast_time',
            'schedule_weekly_retrain_day',
            'schedule_weekly_retrain_time'
        ]);

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
}
