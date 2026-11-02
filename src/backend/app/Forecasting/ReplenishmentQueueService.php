<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use Throwable;
use DateTimeImmutable;
use DateTimeZone;

class ReplenishmentQueueService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function roundQuantity(int $quantity, int $minimumOrder, int $packageSize): int
    {
        if ($quantity <= 0) {
            return 0;
        }
        return (int)(ceil(max($quantity, max(1, $minimumOrder)) / max(1, $packageSize)) * max(1, $packageSize));
    }

    public function getRecommendations(?int $jobId = null, ?string $asOfDate = null): array
    {
        $forecasts = $jobId ? array_column($this->getQueue($jobId, $asOfDate), null, 'product_id') : [];
        $settings = new CandidateEvaluationService($this->pdo);
        $observation = $settings->isObservationMode();
        $suspended = $settings->isRecommendationsSuspended();

        $approvedVersion = '';
        try {
            $approvedVersion = (string)($this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'")->fetchColumn() ?: '');
        } catch (Throwable $e) {}

        $today = new DateTimeImmutable($asOfDate ?? 'today', new DateTimeZone('Asia/Manila'));
        $origin = $today->modify('-1 day')->format('Y-m-d');

        $jobInfo = null;
        if ($jobId) {
            try {
                $stmt = $this->pdo->prepare("SELECT trigger_type, status FROM ml_jobs WHERE job_id = ?");
                $stmt->execute([$jobId]);
                $jobInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
        }
        $isOperationalSuccess = $jobInfo && $jobInfo['trigger_type'] === 'forecast_generation' && $jobInfo['status'] === 'succeeded';
        $isJobFailed = $jobInfo && $jobInfo['status'] === 'failed';
        $isJobObservation = $jobInfo && $jobInfo['trigger_type'] === 'forecast_observation';

        $latestJobId = null;
        try {
            $latestJobId = (new DemandForecastService($this->pdo))->getLatestSuccessfulJobId();
        } catch (Throwable $e) {}
        $isSuperseded = ($jobId !== null && $latestJobId !== null && $jobId < $latestJobId);

        $jobMeta = null;
        if ($jobId) {
            try {
                $jobMeta = (new DemandForecastService($this->pdo))->getJobMetadata($jobId);
            } catch (Throwable $e) {}
        }

        $isDemoDb = false;
        try {
            $isDemoDb = (bool)($this->pdo->query("SELECT 1 FROM platform_settings WHERE setting_key = 'demo_import_metadata'")->fetchColumn() ?: false);
        } catch (Throwable $e) {}

        $isIncompatibleModel = false;
        $incompatibilityReason = '';
        if ($jobMeta) {
            if (!$isDemoDb && !empty($jobMeta['is_demo'])) {
                $isIncompatibleModel = true;
                $incompatibilityReason = 'Demo model cannot be used in Store database';
            } elseif ($isDemoDb && empty($jobMeta['is_demo']) && ($jobMeta['dataset_kind'] ?? null) === 'store') {
                $isIncompatibleModel = true;
                $incompatibilityReason = 'Store model cannot be used in Demo database';
            } elseif (!empty($jobMeta['feature_version']) && $jobMeta['feature_version'] !== '2.0.0') {
                $isIncompatibleModel = true;
                $incompatibilityReason = 'Obsolete feature schema ' . $jobMeta['feature_version'];
            }
        }

        // If forecasts are empty because job was observation/failed/superseded, but historical forecast rows exist, load them for metadata display
        if ($jobId && empty($forecasts)) {
            try {
                $rawRows = $this->buildQueue($jobId);
                $forecasts = array_column($rawRows, null, 'product_id');
            } catch (Throwable $e) {}
        }

        $products = $this->pdo->query("SELECT p.product_id, p.sku, p.product_name,
            p.reorder_level, p.safety_stock, p.minimum_order_quantity, p.units_per_package,
            COALESCE(i.quantity_on_hand, 0) AS current_stock
            FROM products p LEFT JOIN inventory i ON i.product_id = p.product_id
            WHERE p.status = 'active'
            ORDER BY COALESCE(i.quantity_on_hand, 0), p.product_name")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$product) {
            $stock = (int)$product['current_stock'];
            $reorder = (int)$product['reorder_level'];
            $forecast = $forecasts[(int)$product['product_id']] ?? [];
            $hasForecast = ($forecast['readiness_level'] ?? null) !== null;
            $stockRuleQuantity = $stock <= $reorder ? max(0, $reorder + (int)$product['safety_stock'] - $stock) : 0;
            $product['stock_rule_quantity'] = self::roundQuantity($stockRuleQuantity,
                (int)$product['minimum_order_quantity'], (int)$product['units_per_package']);

            // One unified eligibility rule for displayed expected sales and purchase calculation:
            // operational success, complete current period, approved compatible Store model/Product/schema,
            // supported readiness/inputs, no observation-only status, no suspension, not superseded.
            $usableForecast = $hasForecast
                && $isOperationalSuccess
                && !$observation
                && !$suspended
                && !$isSuperseded
                && !$isIncompatibleModel
                && !empty($forecast['complete_forecast'])
                && in_array($forecast['readiness_level'], ['Low', 'Medium', 'High'], true)
                && $forecast['forecast_origin'] === $origin
                && $approvedVersion !== ''
                && $forecast['model_version'] === $approvedVersion;

            if ($usableForecast) {
                $product += $forecast;
                $product['recommended_quantity'] = self::roundQuantity((int)$forecast['recommended_quantity'],
                    (int)$forecast['moq'], (int)$product['units_per_package']);
                $product['expected_sales_28_day'] = $forecast['forecast_28_day'];
                $product['expected_sales_period'] = $today->format('Y-m-d') . ' – ' . $today->modify('+27 days')->format('Y-m-d');
                $product['basis'] = 'Demand Forecast';
                $product['forecast_status'] = $forecast['readiness_level'] . ' readiness forecast';
                $product['job_id'] = $forecast['job_id'] ?? $jobId;
                $product['limitation_marker'] = $forecast['limitation_marker'] ?? null;
                $product['is_forecast_backed'] = true;
                $product['forecast_quantity'] = (int)$forecast['forecast_28_day'];
                $product['exclusion_reason'] = null;
                $product['exclusion_category'] = null;
                $product['exclusion_action'] = null;

                if ((int)$product['recommended_quantity'] === 0) {
                    $product['explanation'] .= ' Stock on hand is sufficient; no purchase needed today.';
                }
            } else {
                $product['recommended_quantity'] = $product['stock_rule_quantity'];
                $product['expected_sales_28_day'] = null;
                $product['expected_sales_period'] = null;
                $product['urgency_label'] = 'Unknown';
                $product['stock_exposure'] = null;
                $product['basis'] = 'Stock-rule suggestion';
                $product['is_forecast_backed'] = false;
                $product['forecast_quantity'] = null;

                if ($product['stock_rule_quantity'] === 0) {
                    $product['explanation'] = sprintf('%d on hand is above reorder level %d (safety stock %d). Stock is sufficient; no purchase needed today.',
                        $stock, $reorder, (int)$product['safety_stock']);
                } else {
                    $product['explanation'] = sprintf('%d on hand; reorder level %d + safety stock %d. Stock-rule suggestion rounded to the minimum quantity and package size.',
                        $stock, $reorder, (int)$product['safety_stock']);
                }

                $exclusionReason = 'Forecast unavailable';
                $exclusionCategory = 'evidence';
                $exclusionAction = 'Replenishment continues on stock rules';

                if ($observation || $isJobObservation) {
                    $exclusionReason = 'Observation Mode: forecast is observation telemetry only';
                    $exclusionCategory = 'restriction';
                    $exclusionAction = 'Administrator: Complete candidate observation';
                } elseif ($suspended) {
                    $exclusionReason = 'Recommendations Suspended by Administrator';
                    $exclusionCategory = 'restriction';
                    $exclusionAction = 'Administrator: Resume forecast recommendations';
                } elseif ($isJobFailed) {
                    $exclusionReason = 'Failed forecast: run #' . $jobId;
                    $exclusionCategory = 'recovery';
                    $exclusionAction = 'Automatic retry scheduled; no action needed';
                } elseif ($approvedVersion === '') {
                    $exclusionReason = 'Unapproved model: no approved Store model';
                    $exclusionCategory = 'approval';
                    $exclusionAction = 'Administrator: Review and approve candidate model in Model Review';
                } elseif ($isIncompatibleModel) {
                    $exclusionReason = 'Incompatible model: ' . $incompatibilityReason;
                    $exclusionCategory = 'recovery';
                    $exclusionAction = 'Super Administrator: Verify model schema and database environment';
                } elseif ($isSuperseded) {
                    $exclusionReason = 'Superseded forecast: run #' . $jobId;
                    $exclusionCategory = 'freshness';
                    $exclusionAction = 'Use latest forecast run';
                } elseif (!$hasForecast) {
                    $exclusionReason = 'Forecast unavailable: no forecast generated for this product';
                    $exclusionCategory = 'evidence';
                    $exclusionAction = 'Accumulate sales history or wait for next scheduled forecast run';
                } elseif ($forecast['forecast_origin'] !== $origin) {
                    $exclusionReason = 'Stale forecast: ' . $forecast['forecast_origin'] . ' (expected ' . $origin . ')';
                    $exclusionCategory = 'freshness';
                    $exclusionAction = 'Wait for next scheduled forecast run at 01:00 Asia/Manila';
                } elseif ($forecast['model_version'] !== $approvedVersion) {
                    $exclusionReason = 'Unapproved model: model ' . ($forecast['model_version'] ?: 'none') . ' is not approved';
                    $exclusionCategory = 'approval';
                    $exclusionAction = 'Administrator: Review and approve candidate model in Model Review';
                } elseif (empty($forecast['complete_forecast'])) {
                    $exclusionReason = 'Incomplete forecast: missing full 28-day predictions';
                    $exclusionCategory = 'evidence';
                    $exclusionAction = 'Wait for next scheduled forecast run';
                } elseif (!in_array($forecast['readiness_level'], ['Low', 'Medium', 'High'], true)) {
                    $exclusionReason = 'Insufficient history: readiness level is ' . ($forecast['readiness_level'] ?? 'Insufficient');
                    $exclusionCategory = 'evidence';
                    $exclusionAction = 'Accumulate more sales history; replenishment continues on stock rules';
                }

                $product['exclusion_reason'] = $exclusionReason;
                $product['exclusion_category'] = $exclusionCategory;
                $product['exclusion_action'] = $exclusionAction;

                if (!$hasForecast) {
                    $product['forecast_status'] = 'Forecast unavailable';
                    $product['forecast_origin'] = null;
                    $product['model_version'] = null;
                    $product['readiness_level'] = null;
                    $product['limitation_marker'] = null;
                    $product['job_id'] = $jobId;
                } else {
                    $product['forecast_origin'] = $forecast['forecast_origin'] ?? null;
                    $product['model_version'] = $forecast['model_version'] ?? null;
                    $product['readiness_level'] = $forecast['readiness_level'] ?? null;
                    $product['limitation_marker'] = $forecast['limitation_marker'] ?? null;
                    $product['job_id'] = $forecast['job_id'] ?? $jobId;

                    $statusParts = [];
                    if ($observation || $isJobObservation) {
                        $statusParts[] = 'Observation Mode: forecast is observation telemetry only';
                    }
                    if ($suspended) {
                        $statusParts[] = 'Recommendations Suspended by Administrator';
                    }
                    if (!$observation && !$suspended && !$isJobObservation) {
                        if ($isJobFailed) {
                            $statusParts[] = 'Failed forecast: run #' . $jobId;
                        } elseif ($isSuperseded) {
                            $statusParts[] = 'Superseded forecast: run #' . $jobId;
                        } elseif ($forecast['forecast_origin'] !== $origin) {
                            $statusParts[] = 'Stale forecast: ' . $forecast['forecast_origin'];
                        } elseif ($approvedVersion === '' || $forecast['model_version'] !== $approvedVersion) {
                            $statusParts[] = 'Unapproved model: ' . ($forecast['model_version'] ?: 'none');
                        } elseif ($isIncompatibleModel) {
                            $statusParts[] = 'Incompatible model: ' . $incompatibilityReason;
                        } elseif (empty($forecast['complete_forecast'])) {
                            $statusParts[] = 'Incomplete forecast';
                        } elseif (!in_array($forecast['readiness_level'], ['Low', 'Medium', 'High'], true)) {
                            $statusParts[] = $forecast['readiness_level'] . ' readiness forecast';
                        } else {
                            $statusParts[] = $forecast['readiness_level'] . ' readiness forecast';
                        }
                    }
                    $product['forecast_status'] = implode('; ', $statusParts);
                }
            }

            $product['stock_status'] = $stock <= 0 ? 'Out of stock' : ($stock <= $reorder ? 'Low stock' : 'In stock');
        }
        unset($product);
        return $forecasts && !$observation && !$suspended ? $this->sortByRisk($products) : $products;
    }

    public function getQueue(int $jobId, ?string $asOfDate = null): array
    {
        $job = $this->pdo->prepare("SELECT 1 FROM ml_jobs WHERE job_id = ?
            AND trigger_type = 'forecast_generation' AND status = 'succeeded'");
        $job->execute([$jobId]);
        if (!$job->fetchColumn()) {
            return [];
        }

        return $this->buildQueue($jobId);
    }

    public static function replayDatabaseAllowed(string $database): bool
    {
        $hostedReplay = (string)\env('FORECAST_REPLAY_DATABASE', '');
        return ($hostedReplay !== '' && $database === $hostedReplay)
            || (preg_match('/^retailmind_demo_[a-z0-9_]+$/D', $database)
                && in_array(strtolower((string)\env('APP_ENV')), ['development', 'test'], true));
    }

    public function getReplayPreview(int $jobId): array
    {
        $database = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $replay = json_decode((string)$this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='demo_replay_result'")->fetchColumn(), true);
        if (!self::replayDatabaseAllowed($database)
            || (int)($replay['preview_job'] ?? 0) !== $jobId) {
            return [];
        }
        $job = $this->pdo->prepare("SELECT 1 FROM ml_jobs WHERE job_id=? AND trigger_type='forecast_observation' AND status='succeeded'");
        $job->execute([$jobId]);
        return $job->fetchColumn() ? array_values(array_filter($this->buildQueue($jobId),
            static fn(array $product): bool => $product['forecast_7_day'] !== null)) : [];
    }

    private function buildQueue(int $jobId): array
    {
        // Check observation mode and recommendation suspension status
        $isObservationMode = false;
        $isSuspended = false;
        try {
            $evalService = new CandidateEvaluationService($this->pdo);
            $isObservationMode = $evalService->isObservationMode();
            $isSuspended = $evalService->isRecommendationsSuspended();
        } catch (Throwable $e) {
            // Degrade gracefully
        }

        $hasTargetDate = false;
        $hasLimitationMarker = false;
        try {
            $this->pdo->query("SELECT target_date, limitation_marker FROM demand_forecasts LIMIT 0");
            $hasTargetDate = true;
            $hasLimitationMarker = true;
        } catch (Throwable $e) {
            // Column may not exist in lightweight test fixtures
        }

        $targetDateCheck = $hasTargetDate ? 'AND COUNT(DISTINCT df.target_date) = 28' : '';
        $limitationSelect = $hasLimitationMarker ? 'MAX(df.limitation_marker) as limitation_marker,' : 'NULL as limitation_marker,';

        // 1. Fetch products and forecasts (goods count only when received; no supplier joins or open POs)
        $stmt = $this->pdo->prepare("
            SELECT 
                p.product_id,
                p.sku,
                p.product_name,
                COALESCE(i.quantity_on_hand, 0) AS current_stock,
                COALESCE(p.supplier_lead_time_days, 7) AS lead_time_days,
                COALESCE(p.minimum_order_quantity, 1) AS moq,
                
                fc.has_forecast,
                fc.f7 AS forecast_7_day,
                fc.f14 AS forecast_14_day,
                fc.f28 AS forecast_28_day,
                fc.f_lead AS forecast_lead_time,
                fc.readiness_level,
                fc.forecast_origin,
                fc.complete_forecast,
                fc.model_version,
                fc.limitation_marker
                
            FROM products p
            LEFT JOIN inventory i ON p.product_id = i.product_id
            
            LEFT JOIN (
                SELECT 
                    df.product_id,
                    1 AS has_forecast,
                    MAX(df.readiness_level) as readiness_level,
                    MAX(df.forecast_origin) as forecast_origin,
                    MAX(df.model_version) as model_version,
                    {$limitationSelect}
                    CASE WHEN COUNT(*) = 28 AND COUNT(DISTINCT df.horizon_day) = 28
                        {$targetDateCheck}
                        AND MIN(df.horizon_day) = 1 AND MAX(df.horizon_day) = 28
                        AND MIN(df.predicted_units) >= 0 AND COUNT(df.predicted_units) = 28
                        AND COUNT(DISTINCT df.forecast_origin) = 1
                        AND COUNT(DISTINCT df.model_version) = 1
                        AND COUNT(df.forecast_origin) = 28 AND COUNT(df.model_version) = 28
                        AND COUNT(df.readiness_level) = 28
                        THEN 1 ELSE 0 END as complete_forecast,
                    SUM(CASE WHEN df.horizon_day <= 7 THEN df.predicted_units ELSE 0 END) as f7,
                    SUM(CASE WHEN df.horizon_day <= 14 THEN df.predicted_units ELSE 0 END) as f14,
                    SUM(CASE WHEN df.horizon_day <= 28 THEN df.predicted_units ELSE 0 END) as f28,
                    SUM(CASE WHEN df.horizon_day <= COALESCE(
                        (SELECT supplier_lead_time_days FROM products WHERE product_id = df.product_id LIMIT 1),
                        7
                    ) THEN df.predicted_units ELSE 0 END) as f_lead
                FROM demand_forecasts df
                WHERE df.job_id = :job_id
                GROUP BY df.product_id
            ) fc ON fc.product_id = p.product_id
            
            WHERE p.status = 'active'
        ");
        
        $stmt->execute(['job_id' => $jobId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $queue = [];
        
        foreach ($rows as $row) {
            $productId = (int)$row['product_id'];
            $currentStock = (int)$row['current_stock'];
            $leadTimeDays = max(1, (int)($row['lead_time_days'] ?? 7));
            $moq = max(1, (int)($row['moq'] ?? 1));

            $hasForecast = !empty($row['has_forecast']) && $row['readiness_level'] !== null;
            $isComplete = !empty($row['complete_forecast']);
            $isSupportedReadiness = in_array($row['readiness_level'], ['Low', 'Medium', 'High'], true);
            $hasUsableForecast = $hasForecast && $isComplete && $isSupportedReadiness;
            
            if (!$hasUsableForecast) {
                // Missing, incomplete, or unsupported forecast: must remain explicitly Unknown, never zero demand or Covered
                $urgency = 'Unknown';
                $exposure = null;
                $recommendedQty = 0;
                $f7 = null;
                $f14 = null;
                $f28 = null;
                $leadTimeDemand = null;
                $explanation = sprintf(
                    "%d on hand. %s; exposure cannot be determined without forecast evidence.",
                    $currentStock,
                    !$hasForecast ? 'No forecast available' : (!$isComplete ? 'Incomplete forecast' : 'Insufficient readiness')
                );
            } else {
                $f7 = (int)$row['forecast_7_day'];
                $f14 = (int)$row['forecast_14_day'];
                $f28 = (int)$row['forecast_28_day'];
                $leadTimeDemand = (int)$row['forecast_lead_time'];
                $exposure = $currentStock - $leadTimeDemand;

                // Urgency rule: Urgent/Watch/Covered apply only where supporting forecast evidence exists
                if ($exposure < 0) {
                    $urgency = 'Urgent';
                } elseif ($exposure < $f7) {
                    $urgency = 'Watch';
                } else {
                    $urgency = 'Covered';
                }

                $recommendedQty = 0;
                if ($urgency === 'Urgent' || $urgency === 'Watch') {
                    // target stock: lead time demand + 28 days safety
                    $target = $leadTimeDemand + $f28;
                    $deficit = $target - $currentStock;
                    if ($deficit > 0) {
                        $recommendedQty = max($moq, (int)ceil($deficit / $moq) * $moq);
                    }
                }

                $explanation = sprintf(
                    "%d on hand. Lead time (%d days) demand is %d. Exposure: %d.",
                    $currentStock, $leadTimeDays, $leadTimeDemand, $exposure
                );
            }

            if ($isObservationMode) {
                $explanation .= " (Observation Mode: recommendations are non-binding observation telemetry only).";
            } elseif ($isSuspended) {
                $explanation .= " (Recommendations Suspended by Administrator).";
            }

            $queue[] = [
                'job_id' => $jobId,
                'product_id' => $productId,
                'sku' => $row['sku'],
                'product_name' => $row['product_name'],
                'current_stock' => $currentStock,
                'lead_time_days' => $leadTimeDays,
                'moq' => $moq,
                'forecast_7_day' => $f7,
                'forecast_14_day' => $f14,
                'forecast_28_day' => $f28,
                'forecast_lead_time' => $leadTimeDemand,
                'readiness_level' => $row['readiness_level'],
                'forecast_origin' => $row['forecast_origin'],
                'complete_forecast' => (bool)$row['complete_forecast'],
                'model_version' => $row['model_version'],
                'limitation_marker' => $row['limitation_marker'],
                'stock_exposure' => $exposure,
                'urgency_label' => $urgency,
                'recommended_quantity' => $recommendedQty,
                'explanation' => $explanation,
                'is_observation_mode' => $isObservationMode,
                'is_recommendations_suspended' => $isSuspended,
                'recommendations_active' => (!$isObservationMode && !$isSuspended)
            ];
        }

        return $this->sortByRisk($queue);
    }

    private function sortByRisk(array $queue): array
    {
        // Sort by risk: Urgent -> Watch -> Unknown -> Covered, then by exposure ascending, then product_id ascending
        usort($queue, function($a, $b) {
            $urgencyRank = [
                'Urgent' => 1,
                'Watch' => 2,
                'Unknown' => 3,
                'Covered' => 4,
            ];
            $rankA = $urgencyRank[$a['urgency_label']] ?? 5;
            $rankB = $urgencyRank[$b['urgency_label']] ?? 5;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            if ($a['stock_exposure'] !== null && $b['stock_exposure'] !== null) {
                if ($a['stock_exposure'] !== $b['stock_exposure']) {
                    return $a['stock_exposure'] <=> $b['stock_exposure'];
                }
            } elseif ($a['stock_exposure'] !== null) {
                return -1;
            } elseif ($b['stock_exposure'] !== null) {
                return 1;
            }
            return $a['product_id'] <=> $b['product_id'];
        });

        return $queue;
    }
}
