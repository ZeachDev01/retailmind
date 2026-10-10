<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use RuntimeException;
use Throwable;

class CandidateEvaluationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Gets the latest candidate evaluations for a specific model version.
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
     * Set approved error/quality thresholds as Platform Settings.
     * Only Super Administrator should call this.
     */
    public function setThresholds(array $thresholds, int $updatedBy): void
    {
        foreach (['7', '14', '28'] as $horizon) {
            $key = "ml_max_wape_{$horizon}d";
            if (isset($thresholds[$key])) {
                $this->setSetting($key, (string)$thresholds[$key], $updatedBy);
            }
        }
    }

    /**
     * Get approved error/quality thresholds from Platform Settings.
     */
    public function getThresholds(): array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT setting_key, setting_value 
                FROM platform_settings 
                WHERE setting_key IN ('ml_max_wape_7d', 'ml_max_wape_14d', 'ml_max_wape_28d')
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $rows = [];
        }

        return [
            '7' => isset($rows['ml_max_wape_7d']) && is_numeric($rows['ml_max_wape_7d']) ? (float)$rows['ml_max_wape_7d'] : null,
            '14' => isset($rows['ml_max_wape_14d']) && is_numeric($rows['ml_max_wape_14d']) ? (float)$rows['ml_max_wape_14d'] : null,
            '28' => isset($rows['ml_max_wape_28d']) && is_numeric($rows['ml_max_wape_28d']) ? (float)$rows['ml_max_wape_28d'] : null,
        ];
    }

    /**
     * Evaluates a candidate model version against Super Administrator quality gates.
     */
    public function evaluateCandidateQuality(string $modelVersion): array
    {
        $evaluations = $this->getEvaluationsByModelVersion($modelVersion);
        if (count($evaluations) < 3) {
            return [
                'passed' => false,
                'reason' => "Incomplete evaluations: expected 7, 14, and 28-day horizons, found " . count($evaluations),
                'horizons' => $evaluations
            ];
        }

        $thresholds = $this->getThresholds();
        $passedAll = true;
        $reasons = [];

        foreach ($evaluations as &$eval) {
            $h = (string)$eval['horizon_days'];
            $maxWape = $thresholds[$h] ?? null;

            if ($maxWape === null) {
                $eval['gate_status'] = 'blocked';
                $eval['gate_reason'] = "Missing quality threshold for {$h}-day horizon";
                $passedAll = false;
                $reasons[] = "Missing quality threshold for {$h}-day horizon";
                continue;
            }

            $wape = (float)($eval['wape'] ?? 999.0);
            $baselineWape = isset($eval['baseline_wape']) ? (float)$eval['baseline_wape'] : null;

            if ($wape > $maxWape) {
                $eval['gate_status'] = 'failed';
                $eval['gate_reason'] = "WAPE {$wape} exceeds maximum allowed {$maxWape}";
                $passedAll = false;
                $reasons[] = "Horizon {$h}d WAPE {$wape} exceeds threshold {$maxWape}";
            } elseif ($baselineWape !== null && $wape >= $baselineWape) {
                $eval['gate_status'] = 'failed';
                $eval['gate_reason'] = "Model WAPE {$wape} does not outperform seasonal baseline {$baselineWape}";
                $passedAll = false;
                $reasons[] = "Horizon {$h}d does not outperform seasonal baseline";
            } else {
                $eval['gate_status'] = 'passed';
                $eval['gate_reason'] = "Satisfies threshold {$maxWape} and outperforms baseline";
            }
        }

        return [
            'passed' => $passedAll,
            'reason' => empty($reasons) ? 'All quality gates passed' : implode('; ', $reasons),
            'horizons' => $evaluations
        ];
    }

    /**
     * Evaluates completed forecast target dates against actual Store demand history.
     */
    public function evaluateCompletedTargetDates(PDO $pdo, string $modelVersion, string $asOfDate): array
    {
        $stmt = $pdo->prepare("
            SELECT df.product_id, df.target_date, df.predicted_units, df.horizon_day
            FROM demand_forecasts df
            WHERE df.model_version = ?
              AND df.target_date <= ?
            ORDER BY df.target_date ASC
        ");
        $stmt->execute([$modelVersion, $asOfDate]);
        $forecasts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($forecasts)) {
            return [
                'completed_days' => 0,
                'min_required_days' => 28,
                'observation_complete' => false,
                'message' => 'No completed target dates available for evaluation yet.',
                'metrics_by_horizon' => []
            ];
        }

        $targetDates = array_unique(array_column($forecasts, 'target_date'));
        $completedDaysCount = count($targetDates);

        $historyService = new DemandHistoryService();
        $byHorizon = [];

        foreach ($forecasts as $fc) {
            $pid = (int)$fc['product_id'];
            $date = $fc['target_date'];
            $h = (int)$fc['horizon_day'];
            $pred = (float)$fc['predicted_units'];

            $ev = $historyService->getDailyEvidence($pdo, $pid, $date, $date, $asOfDate . ' 23:59:59');
            if (empty($ev)) continue;

            $day = $ev[0];
            if (!in_array($day['classification'], ['valid_demand', 'genuine_zero'], true)) {
                continue;
            }

            $actual = (float)$day['valid_sales_units'];
            $byHorizon[$h]['actuals'][] = $actual;
            $byHorizon[$h]['preds'][] = $pred;
        }

        $metrics = [];
        foreach ([7, 14, 28] as $horizon) {
            $actuals = [];
            $preds = [];
            foreach ($byHorizon as $h => $data) {
                if ($h <= $horizon) {
                    $actuals = array_merge($actuals, $data['actuals']);
                    $preds = array_merge($preds, $data['preds']);
                }
            }

            if (empty($actuals)) {
                $metrics[$horizon] = ['mae' => null, 'rmse' => null, 'wape' => null, 'count' => 0];
                continue;
            }

            $n = count($actuals);
            $absErrors = [];
            $sqErrors = [];
            $sumActual = array_sum($actuals);

            for ($i = 0; $i < $n; $i++) {
                $diff = abs($preds[$i] - $actuals[$i]);
                $absErrors[] = $diff;
                $sqErrors[] = $diff * $diff;
            }

            $mae = array_sum($absErrors) / $n;
            $rmse = sqrt(array_sum($sqErrors) / $n);
            $wape = $sumActual > 0 ? (array_sum($absErrors) / $sumActual) : 0.0;

            $metrics[$horizon] = [
                'mae' => round($mae, 4),
                'rmse' => round($rmse, 4),
                'wape' => round($wape, 4),
                'count' => $n
            ];
        }

        return [
            'completed_days' => $completedDaysCount,
            'min_required_days' => 28,
            'observation_complete' => ($completedDaysCount >= 28),
            'metrics_by_horizon' => $metrics
        ];
    }

    public function isObservationMode(): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_observation_mode'");
            $val = $stmt->fetchColumn();
            return $val === '1' || $val === 'true';
        } catch (Throwable $e) {
            return false;
        }
    }

    public function setObservationMode(bool $enabled, int $updatedBy): void
    {
        $this->setSetting('ml_observation_mode', $enabled ? '1' : '0', $updatedBy);
    }

    public function isRecommendationsSuspended(): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_recommendations_suspended'");
            $val = $stmt->fetchColumn();
            return $val === '1' || $val === 'true';
        } catch (Throwable $e) {
            return false;
        }
    }

    public function setRecommendationsSuspended(bool $suspended, int $updatedBy): void
    {
        $this->setSetting('ml_recommendations_suspended', $suspended ? '1' : '0', $updatedBy);
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
