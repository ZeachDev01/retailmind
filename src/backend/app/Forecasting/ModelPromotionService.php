<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use RuntimeException;
use InvalidArgumentException;

class ModelPromotionService
{
    private PDO $pdo;
    private CandidateEvaluationService $evaluationService;

    public function __construct(PDO $pdo, CandidateEvaluationService $evaluationService)
    {
        $this->pdo = $pdo;
        $this->evaluationService = $evaluationService;
    }

    public function promote(string $modelVersion, int $superAdminId): void
    {
        $evaluations = $this->evaluationService->getEvaluationsByModelVersion($modelVersion);
        if (count($evaluations) < 3) { // Expecting 7, 14, 28
            throw new InvalidArgumentException("Incomplete candidate: missing chronological evaluations for $modelVersion");
        }

        foreach ($evaluations as $eval) {
            if ($eval['candidate_gate_outcome'] !== 'passed') {
                throw new InvalidArgumentException("Candidate $modelVersion failed evaluation gate at horizon " . $eval['horizon_days']);
            }
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'");
            $current = $stmt->fetchColumn();

            if ($current === $modelVersion) {
                // Retry/concurrent request handling - duplicate promotion
                $this->pdo->commit();
                return;
            }

            // Save previous
            if ($current !== false && $current !== '') {
                $this->setSetting('ml_previous_approved_model_version', (string)$current, $superAdminId);
            }

            // Promote new
            $this->setSetting('ml_approved_model_version', $modelVersion, $superAdminId);

            log_activity($this->pdo, $superAdminId, 'Promote Forecasting Model', 'Platform Settings', null, $current ?: null, $modelVersion);

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
            $stmt = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_previous_approved_model_version'");
            $previous = $stmt->fetchColumn();

            if (!$previous) {
                throw new RuntimeException("No compatible previous approved artifact found for rollback");
            }

            $stmtCurrent = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'");
            $current = $stmtCurrent->fetchColumn();

            // Set current to previous
            $this->setSetting('ml_approved_model_version', (string)$previous, $superAdminId);
            // Clear previous
            $this->setSetting('ml_previous_approved_model_version', '', $superAdminId);

            log_activity($this->pdo, $superAdminId, 'Rollback Forecasting Model', 'Platform Settings', null, $current ?: null, $previous);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
