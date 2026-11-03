<?php
declare(strict_types=1);

namespace App\Forecasting;

require_once __DIR__ . '/StoreModelGovernanceService.php';

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

    public function getPredictionPerformanceEvidence(PDO $pdo, string $modelVersion, string $asOfDate, ?string $association = null): array
    {
        $timezone = new \DateTimeZone('Asia/Manila');
        try {
            $stmt = $pdo->prepare("
                SELECT df.forecast_id, df.product_id, df.target_date, df.predicted_units, df.horizon_day, df.forecast_origin
                FROM demand_forecasts df
                JOIN ml_jobs j ON j.job_id = df.job_id
                WHERE df.model_version = ?
                  AND df.target_date <= ?
                  AND df.predicted_units >= 0
                  AND df.horizon_day BETWEEN 1 AND 28
                  AND df.forecast_origin < df.target_date
                  AND df.created_at < df.target_date
                  AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded'
                  AND j.completed_at < df.target_date
                ORDER BY df.target_date ASC, df.product_id ASC, df.forecast_origin DESC, df.forecast_id DESC
            ");
            $stmt->execute([$modelVersion, $asOfDate]);
            $forecasts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $forecasts = [];
        }

        // Demo check
        $isDemo = false;
        $demoLabel = null;
        try {
            $demoStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'demo_import_metadata'");
            if ($demoStmt && ($demoVal = $demoStmt->fetchColumn())) {
                $isDemo = true;
                $decoded = json_decode((string)$demoVal, true);
                $demoLabel = 'Demo Evidence (' . ($decoded['source_label'] ?? 'S001') . ')';
            }
        } catch (\Throwable) {}

        // Default empty structure
        $emptyHorizons = [];
        foreach ([7, 14, 28] as $h) {
            $emptyHorizons[$h] = [
                'mae' => null,
                'rmse' => null,
                'wape' => null,
                'overestimated_units' => null,
                'underestimated_units' => null,
                'overestimated_count' => 0,
                'underestimated_count' => 0,
                'exact_count' => 0,
                'usable_comparisons' => 0,
                'baseline_mae' => null,
                'baseline_rmse' => null,
                'baseline_wape' => null,
                'baseline_overestimated_units' => null,
                'baseline_underestimated_units' => null,
            ];
        }

        // Check verified expiry losses
        $verifiedExpiry = null;
        if ($this->hasColumn($pdo, 'stock_movements', 'change_qty')) {
            try {
                $checkExp = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE reason IN ('expired', 'expiry', 'spoilage', 'damaged_expired') AND moved_at <= ?");
                $checkExp->execute([$asOfDate . ' 23:59:59']);
                if ((int)$checkExp->fetchColumn() > 0) {
                    $expStmt = $pdo->prepare("SELECT COALESCE(SUM(ABS(change_qty)), 0) FROM stock_movements WHERE reason IN ('expired', 'expiry', 'spoilage', 'damaged_expired') AND moved_at <= ?");
                    $expStmt->execute([$asOfDate . ' 23:59:59']);
                    $verifiedExpiry = (int)$expStmt->fetchColumn();
                }
            } catch (\Throwable) {
                $verifiedExpiry = null;
            }
        }

        $baseResult = [
            'model_version' => $modelVersion,
            'model_association' => $association ?? 'candidate',
            'is_demo' => $isDemo,
            'demo_label' => $demoLabel,
            'model_inputs_description' => 'Store daily sales history, day of week, and lag patterns. Prices and promotions are not active model inputs.',
            'status' => 'unavailable',
            'status_label' => 'Unavailable',
            'explanation' => 'No completed forecasts or usable Store sales observations available yet for evaluation. Genuine Store prediction evidence is unavailable.',
            'completed_target_dates' => 0,
            'required_target_dates' => 28,
            'usable_comparison_count' => 0,
            'products_evaluated' => [],
            'product_count' => 0,
            'date_range' => ['start' => null, 'end' => null],
            'incomplete_demand_count' => 0,
            'closures_paused' => 0,
            'missing_count' => 0,
            'verified_stockouts' => null,
            'verified_stockouts_display' => 'Unavailable',
            'verified_expiry_losses' => $verifiedExpiry,
            'verified_expiry_losses_display' => $verifiedExpiry !== null ? (string)$verifiedExpiry : 'Unavailable',
            'causal_claim' => null,
            'causal_claim_note' => 'Advisory guidance only. Predictions do not claim causal reduction of stockouts or waste.',
            'metrics_by_horizon' => $emptyHorizons,
        ];

        if (empty($forecasts)) {
            return $baseResult;
        }

        $historyService = new DemandHistoryService();
        $targetDates = [];
        $productTargetDates = [];
        foreach ($forecasts as $forecast) $productTargetDates[(int)$forecast['product_id']] = [];
        $productsEvaluated = [];
        $stockoutsEncountered = 0;
        $closuresEncountered = 0;
        $missingEncountered = 0;

        $comparisonsByHorizon = [7 => [], 14 => [], 28 => []];
        $baselineByHorizon = [7 => [], 14 => [], 28 => []];
        $baselineCache = [];

        $seenTargets = [];
        foreach ($forecasts as $fc) {
            $pid = (int)$fc['product_id'];
            $date = $fc['target_date'];
            $h = (int)$fc['horizon_day'];
            $pred = (float)$fc['predicted_units'];
            $targetKey = $pid . ':' . $date;
            if (isset($seenTargets[$targetKey])) continue;
            $seenTargets[$targetKey] = true;

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

            $actual = (float)$day['valid_sales_units'];
            $targetDates[$date] = true;
            $productTargetDates[$pid][$date] = true;
            $productsEvaluated[$pid] = true;

            foreach ([7, 14, 28] as $horizonLimit) {
                if ($h <= $horizonLimit) {
                    $comparisonsByHorizon[$horizonLimit][] = [
                        'pid' => $pid,
                        'date' => $date,
                        'predicted' => $pred,
                        'actual' => $actual,
                    ];
                }
            }

            // Repeat the final observed week available at the forecast origin, never future outcomes.
            $previous = (new \DateTimeImmutable($date, $timezone))->modify('-7 days');
            while ($previous->format('Y-m-d') > $fc['forecast_origin']) {
                $previous = $previous->modify('-7 days');
            }
            $prevWeekDate = $previous->format('Y-m-d');
            $originCutoff = $fc['forecast_origin'] . ' 23:59:59';
            $cacheKey = "{$pid}_{$prevWeekDate}_{$originCutoff}";
            if (!array_key_exists($cacheKey, $baselineCache)) {
                $baseEv = $historyService->getDailyEvidence($pdo, $pid, $prevWeekDate, $prevWeekDate, $originCutoff);
                if (!empty($baseEv) && in_array($baseEv[0]['classification'] ?? '', ['valid_demand', 'genuine_zero'], true)) {
                    $baselineCache[$cacheKey] = (float)$baseEv[0]['valid_sales_units'];
                } else {
                    $baselineCache[$cacheKey] = null;
                }
            }

            $baseUnits = $baselineCache[$cacheKey];
            if ($baseUnits !== null) {
                foreach ([7, 14, 28] as $horizonLimit) {
                    if ($h <= $horizonLimit) {
                        $baselineByHorizon[$horizonLimit][] = [
                            'pid' => $pid,
                            'date' => $date,
                            'predicted' => $baseUnits,
                            'actual' => $actual,
                        ];
                    }
                }
            }
        }

        $completedDaysCount = min(array_map('count', $productTargetDates));
        $totalUsableComparisons = count($comparisonsByHorizon[28]);

        // Compute metrics per horizon
        $metricsByHorizon = [];
        foreach ([7, 14, 28] as $h) {
            $comps = $comparisonsByHorizon[$h];
            $count = count($comps);

            if ($count === 0) {
                $metricsByHorizon[$h] = $emptyHorizons[$h];
                continue;
            }

            $overUnits = 0.0;
            $underUnits = 0.0;
            $overCount = 0;
            $underCount = 0;
            $exactCount = 0;
            $absErrors = [];
            $sqErrors = [];
            $sumActual = 0.0;

            foreach ($comps as $c) {
                $p = $c['predicted'];
                $a = $c['actual'];
                $diff = $p - $a;

                if ($p > $a) {
                    $overUnits += ($p - $a);
                    $overCount++;
                } elseif ($a > $p) {
                    $underUnits += ($a - $p);
                    $underCount++;
                } else {
                    $exactCount++;
                }

                $abs = abs($diff);
                $absErrors[] = $abs;
                $sqErrors[] = $diff * $diff;
                $sumActual += $a;
            }

            $mae = array_sum($absErrors) / $count;
            $rmse = sqrt(array_sum($sqErrors) / $count);
            $wape = $sumActual > 0 ? (array_sum($absErrors) / $sumActual) : null;

            // Baseline metrics on matching eligible periods
            $baseComps = $baselineByHorizon[$h];
            $baseCount = count($baseComps);
            $baseMae = null;
            $baseRmse = null;
            $baseWape = null;
            $baseOverUnits = null;
            $baseUnderUnits = null;

            if ($baseCount > 0 && $baseCount === $count) {
                $bOverUnits = 0.0;
                $bUnderUnits = 0.0;
                $bAbsErrors = [];
                $bSqErrors = [];
                $bSumActual = 0.0;

                foreach ($baseComps as $bc) {
                    $bp = $bc['predicted'];
                    $ba = $bc['actual'];
                    $bdiff = $bp - $ba;

                    if ($bp > $ba) {
                        $bOverUnits += ($bp - $ba);
                    } elseif ($ba > $bp) {
                        $bUnderUnits += ($ba - $bp);
                    }

                    $bAbs = abs($bdiff);
                    $bAbsErrors[] = $bAbs;
                    $bSqErrors[] = $bdiff * $bdiff;
                    $bSumActual += $ba;
                }

                $baseMae = round(array_sum($bAbsErrors) / $baseCount, 4);
                $baseRmse = round(sqrt(array_sum($bSqErrors) / $baseCount), 4);
                $baseWape = $bSumActual > 0 ? round(array_sum($bAbsErrors) / $bSumActual, 4) : null;
                $baseOverUnits = round($bOverUnits, 2);
                $baseUnderUnits = round($bUnderUnits, 2);
            }

            $metricsByHorizon[$h] = [
                'mae' => round($mae, 4),
                'rmse' => round($rmse, 4),
                'wape' => $wape !== null ? round($wape, 4) : null,
                'overestimated_units' => round($overUnits, 2),
                'underestimated_units' => round($underUnits, 2),
                'overestimated_count' => $overCount,
                'underestimated_count' => $underCount,
                'exact_count' => $exactCount,
                'usable_comparisons' => $count,
                'baseline_mae' => $baseMae,
                'baseline_rmse' => $baseRmse,
                'baseline_wape' => $baseWape,
                'baseline_overestimated_units' => $baseOverUnits,
                'baseline_underestimated_units' => $baseUnderUnits,
            ];
        }

        // Verified stockouts
        $verifiedStockouts = null;
        if ($stockoutsEncountered > 0) {
            $verifiedStockouts = $stockoutsEncountered;
        }
        if ($this->hasColumn($pdo, 'stock_movements', 'reason')) {
            try {
                $stkStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE reason IN ('stockout', 'out_of_stock') AND moved_at <= ?");
                $stkStmt->execute([$asOfDate . ' 23:59:59']);
                $smStockouts = (int)$stkStmt->fetchColumn();
                if ($smStockouts > 0) {
                    $verifiedStockouts = ($verifiedStockouts ?? 0) + $smStockouts;
                }
            } catch (\Throwable) {}
        }

        // Date range
        $dateKeys = array_keys($targetDates);
        sort($dateKeys);
        $dateRange = [
            'start' => !empty($dateKeys) ? $dateKeys[0] : null,
            'end' => !empty($dateKeys) ? $dateKeys[count($dateKeys) - 1] : null,
        ];

        // State determination
        if ($isDemo) {
            $status = 'collecting_evidence';
            $statusLabel = 'Demo evidence';
            $explanation = 'Demo outcomes demonstrate the workflow only; genuine Store prediction evidence is still collecting.';
        } elseif ($completedDaysCount >= 28) {
            $status = 'genuine_evidence';
            $statusLabel = 'Genuine Store evidence';
            $explanation = 'Performance measured against genuine Store sales outcomes across 28+ completed target dates for every evaluated Product.';
        } elseif (!empty($targetDates)) {
            $status = 'collecting_evidence';
            $statusLabel = 'Still collecting evidence';
            $explanation = "Still collecting evidence: {$completedDaysCount} of 28 completed target dates backed by usable Store observations. Requires at least 28 completed target dates backed by usable Store observations for every evaluated Product before prediction performance can be established.";
        } else {
            $status = 'unavailable';
            $statusLabel = 'Unavailable';
            $explanation = 'No completed forecasts or usable Store sales observations available yet for evaluation. Genuine Store prediction evidence is unavailable.';
        }

        return [
            'model_version' => $modelVersion,
            'model_association' => $association ?? 'candidate',
            'is_demo' => $isDemo,
            'demo_label' => $demoLabel,
            'model_inputs_description' => 'Store daily sales history, day of week, and lag patterns. Prices and promotions are not active model inputs.',
            'status' => $status,
            'status_label' => $statusLabel,
            'explanation' => $explanation,
            'completed_target_dates' => $completedDaysCount,
            'required_target_dates' => 28,
            'usable_comparison_count' => $totalUsableComparisons,
            'products_evaluated' => array_keys($productsEvaluated),
            'product_count' => count($productsEvaluated),
            'date_range' => $dateRange,
            'incomplete_demand_count' => $stockoutsEncountered,
            'closures_paused' => $closuresEncountered,
            'missing_count' => $missingEncountered,
            'verified_stockouts' => $verifiedStockouts,
            'verified_stockouts_display' => $verifiedStockouts !== null ? (string)$verifiedStockouts : 'Unavailable',
            'verified_expiry_losses' => $verifiedExpiry,
            'verified_expiry_losses_display' => $verifiedExpiry !== null ? (string)$verifiedExpiry : 'Unavailable',
            'causal_claim' => null,
            'causal_claim_note' => 'Advisory guidance only. Predictions do not claim causal reduction of stockouts or waste.',
            'metrics_by_horizon' => $metricsByHorizon,
        ];
    }
}
