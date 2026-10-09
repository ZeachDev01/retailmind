<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use RuntimeException;

class CandidateEvaluationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Gets the latest candidate evaluations for a specific model version
     * This is available to promotion and Forecast Readiness consumers.
     */
    public function getEvaluationsByModelVersion(string $modelVersion): array
    {
        $stmt = $this->pdo->prepare("
            SELECT evaluation_id, horizon_days, mae, rmse, wape, 
                   baseline_mae, baseline_rmse, baseline_wape, 
                   candidate_gate_outcome, missing_metric_reason, 
                   data_window_start, data_window_end
            FROM ml_model_evaluations
            WHERE model_version = :model_version
            ORDER BY horizon_days ASC
        ");
        $stmt->execute(['model_version' => $modelVersion]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Set approved error/quality thresholds as Platform Settings
     * Only Super Administrator should call this.
     */
    public function setThresholds(array $thresholds, int $updatedBy): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO platform_settings (setting_key, setting_value, updated_by, updated_at) 
            VALUES (:key, :value, :user, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP
        ");
        
        foreach (['7', '14', '28'] as $horizon) {
            $key = "ml_max_wape_{$horizon}d";
            if (isset($thresholds[$key])) {
                $stmt->execute([
                    'key' => $key,
                    'value' => (string)$thresholds[$key],
                    'user' => $updatedBy
                ]);
            }
        }
    }
}
