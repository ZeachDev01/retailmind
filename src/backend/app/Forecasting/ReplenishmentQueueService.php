<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use Throwable;

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

    public function getRecommendations(?int $jobId = null): array
    {
        $forecasts = $jobId ? array_column($this->getQueue($jobId), null, 'product_id') : [];
        $settings = new CandidateEvaluationService($this->pdo);
        $observation = $settings->isObservationMode();
        $suspended = $settings->isRecommendationsSuspended();
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
            $available = ($forecast['readiness_level'] ?? null) !== null;
            $useForecast = $available && !$observation && !$suspended;
            $quantity = $useForecast ? (int)$forecast['recommended_quantity']
                : ($stock <= $reorder ? max(0, $reorder + (int)$product['safety_stock'] - $stock) : 0);
            if ($useForecast) {
                $product += $forecast;
            }
            $product['recommended_quantity'] = self::roundQuantity($quantity,
                (int)($useForecast ? $forecast['moq'] : $product['minimum_order_quantity']),
                (int)$product['units_per_package']);
            $product['stock_status'] = $stock <= 0 ? 'Out of stock' : ($stock <= $reorder ? 'Low stock' : 'In stock');
            $product['basis'] = $useForecast ? 'Demand Forecast' : 'Stock rules';
            $product['forecast_status'] = !$available ? 'Forecast unavailable'
                : ($observation ? 'Observation Mode: forecast is observation telemetry only'
                    : ($suspended ? 'Recommendations Suspended by Administrator' : $forecast['readiness_level'] . ' readiness forecast'));
            if ($observation && $suspended) {
                $product['forecast_status'] .= '; Recommendations Suspended by Administrator';
            }
            if (!$useForecast) {
                $product['urgency_label'] = 'Unknown';
                $product['stock_exposure'] = null;
                $product['forecast_origin'] = null;
                $product['explanation'] = sprintf('%d on hand; reorder level %d + safety stock %d. Stock-rule suggestion rounded to the minimum quantity and package size.',
                    $stock, $reorder, (int)$product['safety_stock']);
            }
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
                fc.forecast_origin
                
            FROM products p
            LEFT JOIN inventory i ON p.product_id = i.product_id
            
            LEFT JOIN (
                SELECT 
                    df.product_id,
                    1 AS has_forecast,
                    MAX(df.readiness_level) as readiness_level,
                    MAX(df.forecast_origin) as forecast_origin,
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
            
            if (!$hasForecast) {
                // Missing forecast: must remain explicitly Unknown, never zero demand or Covered
                $urgency = 'Unknown';
                $exposure = null;
                $recommendedQty = 0;
                $f7 = null;
                $f14 = null;
                $f28 = null;
                $leadTimeDemand = null;
                $explanation = sprintf(
                    "%d on hand. No forecast available; exposure cannot be determined without forecast evidence.",
                    $currentStock
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
