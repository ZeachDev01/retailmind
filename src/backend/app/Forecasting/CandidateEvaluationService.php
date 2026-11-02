<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use RuntimeException;
use Throwable;

class CandidateEvaluationService
{
    private PDO $pdo;
    private ?StoreModelGovernanceService $governance = null;

    public function __construct(PDO $pdo, ?StoreModelGovernanceService $governance = null)
    {
        $this->pdo = $pdo;
        $this->governance = $governance;
    }

    private function governance(): StoreModelGovernanceService
    {
        if ($this->governance === null) {
            $this->governance = new StoreModelGovernanceService($this->pdo, $this);
        }
        return $this->governance;
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
        $this->governance()->setThresholds($thresholds, $updatedBy);
    }

    /**
     * Get approved error/quality thresholds from Platform Settings.
     */
    public function getThresholds(): array
    {
        return $this->governance()->getThresholds();
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
    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $pdo->query("SELECT {$column} FROM {$table} LIMIT 0");
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function evaluateCompletedTargetDates(PDO $pdo, string $modelVersion, string $asOfDate): array
    {
        try {
            $hasOrigin = $this->hasColumn($pdo, 'demand_forecasts', 'forecast_origin');
            $originCondition = $hasOrigin ? "AND df.forecast_origin IS NOT NULL AND df.forecast_origin < df.target_date" : "";

            $stmt = $pdo->prepare("
                SELECT df.product_id, df.target_date, df.predicted_units, df.horizon_day
                FROM demand_forecasts df
                WHERE df.model_version = ?
                  AND df.target_date <= ?
                  AND df.predicted_units IS NOT NULL
                  AND df.predicted_units >= 0
                  {$originCondition}
                ORDER BY df.target_date ASC
            ");
            $stmt->execute([$modelVersion, $asOfDate]);
            $forecasts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $forecasts = [];
        }

        if (empty($forecasts)) {
            return [
                'completed_days' => 0,
                'min_required_days' => 28,
                'usable_observed_days' => 0,
                'required_observed_days' => 28,
                'observation_complete' => false,
                'status' => 'under_evaluation',
                'status_label' => 'Under evaluation',
                'explanation' => 'Under evaluation: no completed target dates available for evaluation yet (0/28). Requires at least 28 completed target dates backed by usable Store observations.',
                'message' => 'No completed target dates available for evaluation yet.',
                'metrics_by_horizon' => []
            ];
        }

        $targetDates = [];
        $closuresEncountered = 0;
        $stockoutsEncountered = 0;
        $missingEncountered = 0;

        $historyService = new DemandHistoryService();
        $byHorizon = [];

        foreach ($forecasts as $fc) {
            $pid = (int)$fc['product_id'];
            $date = $fc['target_date'];
            $h = (int)$fc['horizon_day'];
            $pred = (float)$fc['predicted_units'];

            $ev = $historyService->getDailyEvidence($pdo, $pid, $date, $date, $asOfDate . ' 23:59:59');
            if (empty($ev)) {
                $missingEncountered++;
                continue;
            }

            $day = $ev[0];
            $class = $day['classification'] ?? '';

            if ($class === 'closed') {
                $closuresEncountered++;
                continue;
            }
            if ($class === 'stockout_censored') {
                $stockoutsEncountered++;
                continue;
            }
            if (!in_array($class, ['valid_demand', 'genuine_zero'], true)) {
                $missingEncountered++;
                continue;
            }

            $targetDates[$date] = true;
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

        $completedDaysCount = count($targetDates);
        $isComplete = ($completedDaysCount >= 28 && !empty($metrics[28]['count']));
        $status = $isComplete ? 'awaiting_approval' : 'under_evaluation';
        $statusLabel = $isComplete ? 'Awaiting approval' : 'Under evaluation';
        $explanation = $isComplete
            ? 'Observation period completed: 28+ usable target days evaluated against Store sales.'
            : "Under evaluation: requires at least 28 completed target dates backed by usable Store observations (currently {$completedDaysCount}/28). Missing or Stockout Day targets cannot advance observation; recorded closures pause accumulation without resetting progress.";

        return [
            'completed_days' => $completedDaysCount,
            'min_required_days' => 28,
            'usable_observed_days' => $completedDaysCount,
            'required_observed_days' => 28,
            'observation_complete' => $isComplete,
            'status' => $status,
            'status_label' => $statusLabel,
            'explanation' => $explanation,
            'message' => $isComplete ? 'Observation completed' : 'Observation incomplete',
            'closures_paused' => $closuresEncountered,
            'stockouts_excluded' => $stockoutsEncountered,
            'missing_excluded' => $missingEncountered,
            'metrics_by_horizon' => $metrics
        ];
    }

    public function getCandidateObservationProgress(string $modelVersion, ?string $asOfDate = null): array
    {
        if ($asOfDate === null) {
            $asOfDate = (new \DateTime('now', new \DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d');
        }
        $eval = $this->evaluateCompletedTargetDates($this->pdo, $modelVersion, $asOfDate);
        return [
            'model_version' => $modelVersion,
            'as_of_date' => $asOfDate,
            'usable_observed_days' => $eval['usable_observed_days'],
            'required_observed_days' => $eval['required_observed_days'],
            'completed_days' => $eval['completed_days'],
            'min_required_days' => $eval['min_required_days'],
            'observation_complete' => $eval['observation_complete'],
            'status' => $eval['status'],
            'status_label' => $eval['status_label'],
            'explanation' => $eval['explanation'],
            'closures_paused' => $eval['closures_paused'] ?? 0,
            'stockouts_excluded' => $eval['stockouts_excluded'] ?? 0,
            'missing_excluded' => $eval['missing_excluded'] ?? 0,
            'metrics_by_horizon' => $eval['metrics_by_horizon'],
        ];
    }

    public function isObservationMode(): bool
    {
        return $this->governance()->isObservationMode();
    }

    public function setObservationMode(bool $enabled, int $updatedBy): void
    {
        $this->governance()->setObservationMode($enabled, $updatedBy);
    }

    public function isRecommendationsSuspended(): bool
    {
        return $this->governance()->isRecommendationsSuspended();
    }

    public function setRecommendationsSuspended(bool $suspended, int $updatedBy): void
    {
        $this->governance()->setRecommendationsSuspended($suspended, $updatedBy);
    }
}
