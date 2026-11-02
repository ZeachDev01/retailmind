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
    private string $modelsDir;

    public function __construct(
        PDO $pdo,
        ?CandidateEvaluationService $evaluationService = null,
        ?string $modelsDir = null
    ) {
        $this->pdo = $pdo;
        $this->modelsDir = $modelsDir ?? dirname(__DIR__, 2) . '/storage/ml_models';
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
    protected function setSetting(string $key, string $value, int $updatedBy): void
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

    private function requireLocalAdministrator(string $actorRole, bool $isRemote = false, string $actionLabel = 'Model lifecycle changes'): void
    {
        if ($isRemote || (function_exists('is_remote_request') && is_remote_request())) {
            throw new \DomainException("Unauthorized: Remote mutation rejected. {$actionLabel} require a local Administrator workspace.");
        }

        $hasCapability = function_exists('role_capability_policy')
            ? (role_capability_policy()->allows($actorRole, \App\Authorization\RoleCapabilityPolicy::STORE_OPERATIONS)
               || role_capability_policy()->allows($actorRole, \App\Authorization\RoleCapabilityPolicy::PLATFORM_GOVERNANCE))
            : in_array($actorRole, ['admin', 'super_admin'], true);

        if (!$hasCapability || !in_array($actorRole, ['admin', 'super_admin'], true)) {
            throw new \DomainException("Unauthorized: Role '{$actorRole}' lacks authority. {$actionLabel} require a local Administrator workspace.");
        }
    }

    public function suspendRecommendations(int $actorId, string $reason = '', string $actorRole = 'admin', ?string $ipAddress = null, bool $isRemote = false): array
    {
        $this->requireLocalAdministrator($actorRole, $isRemote, 'Recommendation suspension');
        $this->pdo->beginTransaction();
        try {
            $trimmedReason = trim($reason) ?: 'Suspended by local Administrator';
            $currentModel = $this->getApprovedModelVersion();
            $record = [
                'suspended_by' => $actorId,
                'actor_role' => $actorRole,
                'suspended_at' => date('Y-m-d H:i:s'),
                'reason' => $trimmedReason,
                'active_model' => $currentModel,
            ];
            $this->setSetting('ml_recommendations_suspended', '1', $actorId);
            $this->setSetting('ml_recommendations_suspended_reason', json_encode($record, JSON_THROW_ON_ERROR), $actorId);
            if (function_exists('log_activity')) {
                \log_activity(
                    $this->pdo,
                    $actorId,
                    'Suspend Replenishment Recommendations',
                    'Demand Forecasting',
                    null,
                    '0',
                    '1',
                    $ipAddress ?? '127.0.0.1',
                    'store_operation',
                    [
                        'decision' => 'suspend',
                        'reason' => $trimmedReason,
                        'actor_role' => $actorRole,
                        'active_model' => $currentModel,
                        'timestamp' => $record['suspended_at'],
                    ]
                );
            }
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function resumeRecommendations(int $actorId, string $actorRole = 'admin', ?string $ipAddress = null, bool $isRemote = false): array
    {
        $this->requireLocalAdministrator($actorRole, $isRemote, 'Resuming recommendations');
        $version = $this->getApprovedModelVersion();
        $validation = $this->validateActiveModelForGuidance($version);
        if ($this->isObservationMode() || !$validation['valid']) {
            throw new \RuntimeException($validation['reason'] ?: 'Cannot resume recommendations: approved, compatible Store guidance is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $record = [
                'resumed_by' => $actorId,
                'actor_role' => $actorRole,
                'resumed_at' => date('Y-m-d H:i:s'),
                'active_model' => $version,
            ];
            $this->setSetting('ml_recommendations_suspended', '0', $actorId);
            $this->setSetting('ml_recommendations_resumed_record', json_encode($record, JSON_THROW_ON_ERROR), $actorId);
            if (function_exists('log_activity')) {
                \log_activity(
                    $this->pdo,
                    $actorId,
                    'Resume Replenishment Recommendations',
                    'Demand Forecasting',
                    null,
                    '1',
                    '0',
                    $ipAddress ?? '127.0.0.1',
                    'store_operation',
                    [
                        'decision' => 'resume',
                        'actor_role' => $actorRole,
                        'active_model' => $version,
                        'timestamp' => $record['resumed_at'],
                    ]
                );
            }
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function audit(int $actorId, string $action, array $record, ?string $ipAddress = null): void
    {
        \log_activity($this->pdo, $actorId, $action, 'Demand Forecasting', null, null, $record,
            $ipAddress, 'store_operation', $record);
    }

    public function setModelsDir(string $modelsDir): self
    {
        $this->modelsDir = $modelsDir;
        return $this;
    }

    public function getModelsDir(): string
    {
        return $this->modelsDir;
    }

    public function validateModelArtifactCompatibility(string $version, string $contextLabel = 'Rollback'): array
    {
        $prefix = $contextLabel === 'Resume' ? 'Cannot resume recommendations' : 'Rollback rejected';
        $trimmed = trim($version);
        if ($trimmed === '') {
            return [
                'valid' => false,
                'reason' => $contextLabel === 'Resume'
                    ? "Cannot resume recommendations: no approved Store model version configured."
                    : "Rollback rejected: no compatible previous approved Store model artifact found.",
                'artifact_path' => null,
                'details' => ['check' => 'version_presence'],
            ];
        }

        $lower = strtolower($trimmed);
        if (str_contains($lower, 'demo') || str_contains($lower, 'kaggle')) {
            return [
                'valid' => false,
                'reason' => "{$prefix}: model version '{$trimmed}' is a demo/kaggle artifact. Demo artifacts cannot be activated in Store operations.",
                'artifact_path' => null,
                'details' => ['check' => 'not_demo_artifact'],
            ];
        }

        // Look for model artifact file in modelsDir
        $candidates = [
            $this->modelsDir . '/random_forest_candidate_' . $trimmed . '.joblib',
            $this->modelsDir . '/random_forest_candidate_' . $trimmed . '.json',
            $this->modelsDir . '/' . $trimmed . '.joblib',
            $this->modelsDir . '/' . $trimmed . '.json',
        ];
        if (is_dir($this->modelsDir . '/store')) {
            foreach (glob($this->modelsDir . '/store/*/random_forest_candidate_' . $trimmed . '.joblib') ?: [] as $cand) {
                $candidates[] = $cand;
            }
        }
        $foundPath = null;
        foreach ($candidates as $cand) {
            if (file_exists($cand)) {
                $foundPath = $cand;
                break;
            }
        }

        if ($foundPath === null) {
            return [
                'valid' => false,
                'reason' => "{$prefix}: model artifact file for version '{$trimmed}' was not found on disk in {$this->modelsDir}.",
                'artifact_path' => null,
                'details' => ['check' => 'artifact_exists'],
            ];
        }

        $size = filesize($foundPath);
        if ($size === 0 || $size === false) {
            return [
                'valid' => false,
                'reason' => "{$prefix}: model artifact file for version '{$trimmed}' is empty or corrupted (0 bytes).",
                'artifact_path' => $foundPath,
                'details' => ['check' => 'artifact_integrity'],
            ];
        }

        $content = (string)file_get_contents($foundPath);
        if (str_starts_with($content, 'CORRUPT') || str_contains($content, 'CORRUPTED_ARTIFACT')) {
            return [
                'valid' => false,
                'reason' => "{$prefix}: model artifact file for version '{$trimmed}' is corrupted or unreadable.",
                'artifact_path' => $foundPath,
                'details' => ['check' => 'artifact_integrity'],
            ];
        }

        $fileMeta = null;
        if (str_ends_with($foundPath, '.json')) {
            $fileMeta = json_decode($content, true);
            if ($fileMeta === null && json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'valid' => false,
                    'reason' => "{$prefix}: model artifact file for version '{$trimmed}' contains corrupted JSON metadata.",
                    'artifact_path' => $foundPath,
                    'details' => ['check' => 'artifact_integrity'],
                ];
            }
        }

        // Check schema and dataset kind from database or file metadata
        $trainingRun = null;
        try {
            $stmt = $this->pdo->prepare("SELECT metrics_json, dataset_kind, status FROM model_training_runs WHERE model_version = ? ORDER BY training_run_id DESC LIMIT 1");
            $stmt->execute([$trimmed]);
            $trainingRun = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {}

        $metrics = null;
        if (!empty($trainingRun['metrics_json'])) {
            $metrics = json_decode((string)$trainingRun['metrics_json'], true);
        } elseif (is_array($fileMeta)) {
            $metrics = $fileMeta;
        }

        $datasetKind = $trainingRun['dataset_kind'] ?? $metrics['metadata']['dataset_kind'] ?? $metrics['dataset_kind'] ?? null;
        if ($datasetKind === 'demo') {
            return [
                'valid' => false,
                'reason' => "{$prefix}: model version '{$trimmed}' belongs to demo dataset. Demo artifacts cannot be activated in Store operations.",
                'artifact_path' => $foundPath,
                'details' => ['check' => 'dataset_kind'],
            ];
        }

        $featureVersion = $metrics['metadata']['feature_version'] ?? $metrics['feature_version'] ?? null;
        if ($featureVersion !== null && $featureVersion !== '2.0.0') {
            return [
                'valid' => false,
                'reason' => "{$prefix}: model version '{$trimmed}' has incompatible feature version '{$featureVersion}' (Store requires version 2.0.0).",
                'artifact_path' => $foundPath,
                'details' => ['check' => 'feature_version'],
            ];
        }

        // Qualification metadata check: evaluations or completed training run
        $evals = $this->evaluationService->getEvaluationsByModelVersion($trimmed);
        $hasEvals = !empty($evals);
        $hasCompletedRun = !empty($trainingRun) && ($trainingRun['status'] ?? '') === 'completed';

        if (!$hasEvals && !$hasCompletedRun) {
            return [
                'valid' => false,
                'reason' => "{$prefix}: model version '{$trimmed}' lacks valid qualification metadata or completed training records.",
                'artifact_path' => $foundPath,
                'details' => ['check' => 'qualification_metadata'],
            ];
        }

        if ($hasEvals) {
            $quality = $this->evaluationService->evaluateCandidateQuality($trimmed);
            if (!$quality['passed']) {
                return [
                    'valid' => false,
                    'reason' => "{$prefix}: model version '{$trimmed}' failed qualification quality gates: " . ($quality['reason'] ?? ''),
                    'artifact_path' => $foundPath,
                    'details' => ['check' => 'quality_gates'],
                ];
            }
        }

        return [
            'valid' => true,
            'reason' => '',
            'artifact_path' => $foundPath,
            'details' => [
                'model_version' => $trimmed,
                'feature_version' => $featureVersion ?? '2.0.0',
                'dataset_kind' => $datasetKind ?? 'store',
                'artifact_file' => basename($foundPath),
            ],
        ];
    }

    public function validateRollbackArtifact(string $version): array
    {
        return $this->validateModelArtifactCompatibility($version, 'Rollback');
    }

    public function validateActiveModelForGuidance(string $version): array
    {
        return $this->validateModelArtifactCompatibility($version, 'Resume');
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

    public function recordTechnicalReview(string $modelVersion, string $decision, string $notes, int $superAdminId, string $actorRole = 'super_admin'): void
    {
        $this->requireLocalAdministrator($actorRole);
        if ($actorRole !== 'super_admin') throw new DomainException('Technical review requires Super Administrator workspace.');
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

    public function recordOperationalReview(string $modelVersion, string $decision, string $notes, int $adminId, string $actorRole = 'admin'): void
    {
        $this->requireLocalAdministrator($actorRole);
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
        \App\Services\PhilippineTime::dayStart($asOfDate);
        $reasons = [];
        $checks = [
            'valid_version_format' => false,
            'not_demo_artifact' => false,
            'quality_gates_passed' => false,
            'technical_review_accepted' => false,
            'operational_review_accepted' => false,
            'observation_period_completed' => false,
            'not_rejected' => true,
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

        // 3. Observation period holdout evaluation (minimum 28 target days)
        $obsResult = $this->evaluationService->evaluateCompletedTargetDates($this->pdo, $modelVersion, $asOfDate);
        if (!$obsResult['observation_complete']) {
            $reasons[] = "Observation period incomplete: requires at least {$obsResult['min_required_days']} completed target days evaluated against Store sales, but only {$obsResult['completed_days']} completed days were recorded.";
        } else {
            $checks['observation_period_completed'] = true;
        }

        // 4. Technical and Operational reviews (ADR-0022)
        $reviewStatus = $this->getReviewStatus($modelVersion);
        $techDecision = $reviewStatus['technical_review']['decision'] ?? null;
        $opDecision = $reviewStatus['operational_review']['decision'] ?? null;

        // Under ADR-0022: Technical review recording is available (Super Admin),
        // but not a hidden mandatory activation gate if unrecorded.
        // However, if technical review was explicitly recorded and rejected, it blocks activation.
        if ($techDecision === 'accepted') {
            $checks['technical_review_accepted'] = true;
        } elseif ($techDecision === 'rejected') {
            $checks['technical_review_accepted'] = false;
            $reasons[] = 'Super Administrator technical review rejected this candidate.';
        } else {
            $checks['technical_review_accepted'] = false;
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

        $automatedTechPassed = $checks['quality_gates_passed'] && $checks['observation_period_completed'] && $checks['not_demo_artifact'] && $checks['valid_version_format'];
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
     * Transitions the model out of observation mode into live Store forecasting.
     */
    public function activateStoreModel(string $modelVersion, string $asOfDate, int $actorId, string $justification = '', string $actorRole = 'admin', ?string $ipAddress = null, bool $isRemote = false): array
    {
        $this->requireLocalAdministrator($actorRole, $isRemote);
        $review = $this->getReviewStatus($modelVersion);
        if (($review['operational_review']['decision'] ?? null) === 'rejected') {
            throw new InvalidArgumentException('Store model activation blocked: Administrator operational review rejected this candidate.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->recordOperationalReview($modelVersion, 'accepted', $justification ?: 'Approved for Store demand forecasting by local Administrator', $actorId, $actorRole);
            $readiness = $this->validateActivationReadiness($modelVersion, $asOfDate);
            if (!$readiness['ready']) {
                throw new InvalidArgumentException('Store model activation blocked: ' . implode(' ', $readiness['reasons']));
            }
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
                'actor_role' => $actorRole,
                'activated_at' => date('Y-m-d H:i:s'),
                'justification' => $justification,
                'decision' => 'approved',
                'effective_gates' => $this->getThresholds(),
                'quality_details' => $readiness['quality_details'],
                'observation_details' => $readiness['observation_details'],
                'previous_model_version' => $currentApproved,
                'observation_completed_days' => $readiness['observation_details']['completed_days'] ?? null,
                'quality_summary' => $readiness['quality_details']['reason'] ?? '',
                'technical_review' => $readiness['review_details']['technical_review'] ?? null,
                'operational_review' => $readiness['review_details']['operational_review'] ?? null,
            ];

            $this->setSetting('ml_store_activation_record', json_encode($activationRecord, JSON_THROW_ON_ERROR), $actorId);

            if (function_exists('log_activity')) {
                \log_activity(
                    $this->pdo,
                    $actorId,
                    'Activate Store Forecasting Model',
                    'Demand Forecasting',
                    null,
                    $currentApproved ?: null,
                    $modelVersion,
                    $ipAddress ?? '127.0.0.1',
                    'store_operation',
                    $activationRecord
                );
            }
            $activationRecord['operational_forecast_job_id'] = (new \App\Services\MlJobService($this->pdo))->enqueue('forecast_generation', $actorId, $actorRole, $ipAddress, $asOfDate, $modelVersion);

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
    public function rollbackStoreModel(int $actorId, string $reason = '', string $actorRole = 'admin', ?string $ipAddress = null, ?string $asOfDate = null, bool $isRemote = false): array
    {
        $this->requireLocalAdministrator($actorRole, $isRemote);
        $this->pdo->beginTransaction();
        try {
            $previous = $this->getPreviousApprovedModelVersion();

            $validation = $this->validateRollbackArtifact($previous);
            if (!$validation['valid']) throw new RuntimeException('Rollback rejected: ' . $validation['reason']);

            $current = $this->getApprovedModelVersion();

            // Restore previous model
            $this->setSetting('ml_approved_model_version', $previous, $actorId);
            // Clear previous model to avoid circular rollbacks
            $this->setSetting('ml_previous_approved_model_version', '', $actorId);

            $rollbackRecord = [
                'rollback_id' => uniqid('rb_', true),
                'decision' => 'rollback',
                'rolled_back_from' => $current,
                'restored_model_version' => $previous,
                'rolled_back_by' => $actorId,
                'actor_role' => $actorRole,
                'rolled_back_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
            ];

            $history = json_decode($this->getSetting('ml_store_rollback_history', '[]'), true);
            if (!is_array($history)) $history = [];
            $history[] = $rollbackRecord;
            $this->setSetting('ml_store_rollback_history', json_encode($history, JSON_THROW_ON_ERROR), $actorId);

            $this->setSetting('ml_store_rollback_record', json_encode($rollbackRecord, JSON_THROW_ON_ERROR), $actorId);

            $this->setSetting('ml_observation_mode', '0', $actorId);
            $this->setSetting('ml_recommendations_suspended', '0', $actorId);
            if (function_exists('log_activity')) {
                \log_activity(
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
                        'rollback_id' => $rollbackRecord['rollback_id'],
                        'timestamp' => $rollbackRecord['rolled_back_at'],
                    ]
                );
            }
            $rollbackRecord['operational_forecast_job_id'] = (new \App\Services\MlJobService($this->pdo))->enqueue('forecast_generation', $actorId, $actorRole, $ipAddress, $asOfDate, $previous);

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
            throw new \InvalidArgumentException("Incomplete candidate: missing chronological evaluations for $modelVersion");
        }
        foreach ($evaluations as $eval) {
            if ($eval['candidate_gate_outcome'] !== 'passed') {
                throw new \InvalidArgumentException("Candidate $modelVersion failed evaluation gate at horizon " . $eval['horizon_days']);
            }
        }
        $thresholds = $this->evaluationService->getThresholds();
        $hasThresholds = ($thresholds['7'] !== null || $thresholds['14'] !== null || $thresholds['28'] !== null);
        if ($hasThresholds) {
            $gateResult = $this->evaluationService->evaluateCandidateQuality($modelVersion);
            if (!$gateResult['passed']) {
                throw new \InvalidArgumentException("Candidate $modelVersion failed quality gate: " . ($gateResult['reason'] ?? ''));
            }
        }
        $this->pdo->beginTransaction();
        try {
            $current = $this->getSetting('ml_approved_model_version');
            if ($current === $modelVersion) {
                $this->pdo->commit();
                return;
            }
            if ($current !== '' && $current !== null && $current !== false) {
                $this->setSetting('ml_previous_approved_model_version', (string)$current, $superAdminId);
            }
            $this->setSetting('ml_approved_model_version', $modelVersion, $superAdminId);
            if (function_exists('log_activity')) {
                log_activity($this->pdo, $superAdminId, 'Promote Forecasting Model', 'Platform Settings', null, $current ?: null, $modelVersion);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function rollback(int $superAdminId): void
    {
        $this->pdo->beginTransaction();
        try {
            $previous = $this->getSetting('ml_previous_approved_model_version');
            if (!$previous) {
                throw new \RuntimeException("No compatible previous approved artifact found for rollback");
            }
            $current = $this->getSetting('ml_approved_model_version');
            $this->setSetting('ml_approved_model_version', (string)$previous, $superAdminId);
            $this->setSetting('ml_previous_approved_model_version', '', $superAdminId);
            if (function_exists('log_activity')) {
                log_activity($this->pdo, $superAdminId, 'Rollback Forecasting Model', 'Platform Settings', null, $current ?: null, $previous);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
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
        $isObservation = in_array($settings['ml_observation_mode'] ?? '0', ['1', 'true'], true);
        $isSuspended = in_array($settings['ml_recommendations_suspended'] ?? '0', ['1', 'true'], true);

        $activationRecord = null;
        if (!empty($settings['ml_store_activation_record'])) {
            $activationRecord = json_decode($settings['ml_store_activation_record'], true);
        }

        $rollbackRecord = null;
        if (!empty($settings['ml_store_rollback_record'])) {
            $rollbackRecord = json_decode($settings['ml_store_rollback_record'], true);
        }

        $schedulerService = new ForecastingSchedulerService($this->pdo);
        $effectiveSchedule = $schedulerService->getEffectiveSchedule();

        return [
            'rollback_history' => json_decode($this->getSetting('ml_store_rollback_history', '[]'), true) ?: [],
            'approved_model_version' => $approvedModel,
            'previous_model_version' => $previousModel,
            'is_observation_mode' => $isObservation,
            'is_recommendations_suspended' => $isSuspended,
            'is_store_activated' => ($approvedModel !== '' && !$isObservation),
            'activation_record' => $activationRecord,
            'rollback_record' => $rollbackRecord,
            'schedule' => [
                'daily_forecast_time' => $effectiveSchedule['daily_forecast_time'],
                'weekly_retrain_day' => $effectiveSchedule['weekly_retrain_day'],
                'weekly_retrain_time' => $effectiveSchedule['weekly_retrain_time'],
                'timezone' => $effectiveSchedule['timezone'] ?? 'Asia/Manila',
                'source' => $effectiveSchedule['source'] ?? 'default_policy',
                'is_explicit' => $effectiveSchedule['is_explicit'] ?? false,
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

    public function rejectCandidate(
        string $modelVersion,
        int $actorId,
        string $reason = '',
        string $actorRole = 'admin',
        ?string $ipAddress = null,
        bool $isRemote = false
    ): array {
        $this->requireLocalAdministrator($actorRole, $isRemote);

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
            $this->recordOperationalReview($trimmed, 'rejected', $reason ?: 'Rejected by local Administrator', $actorId, $actorRole);

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
        $isQualified = $automatedTechPassed && $readiness['checks']['not_rejected']
            && ($readiness['review_details']['technical_review']['decision'] ?? null) !== 'rejected';

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
        $rollbackValidation = $previousModel !== '' ? $this->validateRollbackArtifact($previousModel) : ['valid' => false, 'reason' => 'No previous model available'];
        $canRollback = ($previousModel !== '' && $previousModel !== $approvedModel && $rollbackValidation['valid']);

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
            'rollback_validation' => $rollbackValidation,
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
}
