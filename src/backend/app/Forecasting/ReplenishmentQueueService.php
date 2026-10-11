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

        // 1. Fetch inbound purchase orders
        $inboundItems = [];
        try {
            $poStmt = $this->pdo->query("
                SELECT 
                    poi.product_id,
                    (poi.ordered_qty - poi.received_qty) AS open_qty,
                    po.expected_delivery_date
                FROM purchase_order_items poi
                JOIN purchase_orders po ON po.purchase_order_id = poi.purchase_order_id
                WHERE po.status IN ('approved', 'sent', 'partially_received')
            ");
            while ($item = $poStmt->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int)$item['product_id'];
                $openQty = max(0, (int)$item['open_qty']);
                if ($openQty > 0) {
                    $inboundItems[$pid][] = [
                        'qty' => $openQty,
                        'expected_delivery_date' => $item['expected_delivery_date'] ?? null
                    ];
                }
            }
        } catch (Throwable $e) {
            try {
                $poStmt = $this->pdo->query("
                    SELECT 
                        poi.product_id,
                        (poi.ordered_qty - poi.received_qty) AS open_qty
                    FROM purchase_order_items poi
                    JOIN purchase_orders po ON po.purchase_order_id = poi.purchase_order_id
                    WHERE po.status IN ('approved', 'sent', 'partially_received')
                ");
                while ($item = $poStmt->fetch(PDO::FETCH_ASSOC)) {
                    $pid = (int)$item['product_id'];
                    $openQty = max(0, (int)$item['open_qty']);
                    if ($openQty > 0) {
                        $inboundItems[$pid][] = [
                            'qty' => $openQty,
                            'expected_delivery_date' => null
                        ];
                    }
                }
            } catch (Throwable $e2) {
                // Table might not exist in minimal environments
            }
        }

        // 2. Fetch products and forecasts
        $stmt = $this->pdo->prepare("
            SELECT 
                p.product_id,
                p.sku,
                p.product_name,
                COALESCE(i.quantity_on_hand, 0) AS current_stock,
                COALESCE(sp_pref.lead_time_days, p.supplier_lead_time_days, 7) AS lead_time_days,
                COALESCE(sp_pref.minimum_order_quantity, p.minimum_order_quantity, 1) AS moq,
                
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
                SELECT product_id, lead_time_days, minimum_order_quantity
                FROM supplier_products 
                WHERE is_preferred = 1
                GROUP BY product_id
            ) sp_pref ON sp_pref.product_id = p.product_id
            
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
                        (SELECT lead_time_days FROM supplier_products WHERE product_id = df.product_id AND is_preferred = 1 LIMIT 1),
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
            
            // Effective as-of date for inbound calculation
            $origin = $row['forecast_origin'];
            $effectiveAsOf = $asOfDate ?: ($origin ?: date('Y-m-d'));
            $leadTimeCutoff = date('Y-m-d', strtotime("+{$leadTimeDays} days", strtotime($effectiveAsOf)));

            // Compute reliable vs unreliable inbound
            $reliableInbound = 0;
            $totalInbound = 0;
            if (isset($inboundItems[$productId])) {
                foreach ($inboundItems[$productId] as $inbound) {
                    $qty = $inbound['qty'];
                    $totalInbound += $qty;
                    $deliveryDate = $inbound['expected_delivery_date'];
                    // Only count as reliable timely inbound if delivery date is known, not in the past, and within lead time
                    if ($deliveryDate !== null && $deliveryDate !== '' && $deliveryDate >= $effectiveAsOf && $deliveryDate <= $leadTimeCutoff) {
                        $reliableInbound += $qty;
                    }
                }
            }
            $unreliableInbound = $totalInbound - $reliableInbound;
            $effectiveStock = $currentStock + $reliableInbound;

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
                    "%d on hand + %d reliable inbound = %d effective. No forecast available; exposure cannot be determined without forecast evidence.",
                    $currentStock, $reliableInbound, $effectiveStock
                );
                if ($unreliableInbound > 0) {
                    $explanation .= sprintf(" Note: %d open inbound units excluded (late or untimed).", $unreliableInbound);
                }
            } else {
                $f7 = (int)$row['forecast_7_day'];
                $f14 = (int)$row['forecast_14_day'];
                $f28 = (int)$row['forecast_28_day'];
                $leadTimeDemand = (int)$row['forecast_lead_time'];
                $exposure = $effectiveStock - $leadTimeDemand;

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
                    $deficit = $target - $effectiveStock;
                    if ($deficit > 0) {
                        $recommendedQty = max($moq, (int)ceil($deficit / $moq) * $moq);
                    }
                }

                $explanation = sprintf(
                    "%d on hand + %d reliable inbound = %d effective. Lead time (%d days) demand is %d. Exposure: %d.",
                    $currentStock, $reliableInbound, $effectiveStock, $leadTimeDays, $leadTimeDemand, $exposure
                );
                if ($unreliableInbound > 0) {
                    $explanation .= sprintf(" Note: %d open inbound units excluded (late or untimed).", $unreliableInbound);
                }
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
                'inbound_quantity' => $reliableInbound,
                'total_inbound_quantity' => $totalInbound,
                'unreliable_inbound_quantity' => $unreliableInbound,
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
