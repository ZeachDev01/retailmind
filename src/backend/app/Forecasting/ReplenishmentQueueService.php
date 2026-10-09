<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;

class ReplenishmentQueueService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getQueue(int $jobId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                p.product_id,
                p.sku,
                p.product_name,
                COALESCE(i.quantity_on_hand, 0) AS current_stock,
                COALESCE(sp_pref.lead_time_days, p.supplier_lead_time_days) AS lead_time_days,
                COALESCE(sp_pref.minimum_order_quantity, p.minimum_order_quantity) AS moq,
                COALESCE(inbound.qty, 0) AS inbound_quantity,
                
                COALESCE(fc.f7, 0) AS forecast_7_day,
                COALESCE(fc.f14, 0) AS forecast_14_day,
                COALESCE(fc.f28, 0) AS forecast_28_day,
                COALESCE(fc.f_lead, 0) AS forecast_lead_time,
                
                fc.readiness_level,
                fc.forecast_origin
                
            FROM products p
            LEFT JOIN inventory i ON p.product_id = i.product_id
            
            LEFT JOIN (
                SELECT product_id, lead_time_days, minimum_order_quantity
                FROM supplier_products 
                WHERE is_preferred = 1
            ) sp_pref ON sp_pref.product_id = p.product_id
            
            LEFT JOIN (
                SELECT poi.product_id, SUM(poi.ordered_qty - poi.received_qty) AS qty
                FROM purchase_order_items poi
                JOIN purchase_orders po ON po.purchase_order_id = poi.purchase_order_id
                WHERE po.status IN ('approved', 'sent', 'partially_received')
                GROUP BY poi.product_id
            ) inbound ON inbound.product_id = p.product_id
            
            LEFT JOIN (
                SELECT 
                    df.product_id,
                    MAX(df.readiness_level) as readiness_level,
                    MAX(df.forecast_origin) as forecast_origin,
                    SUM(CASE WHEN df.horizon_day <= 7 THEN df.predicted_units ELSE 0 END) as f7,
                    SUM(CASE WHEN df.horizon_day <= 14 THEN df.predicted_units ELSE 0 END) as f14,
                    SUM(CASE WHEN df.horizon_day <= 28 THEN df.predicted_units ELSE 0 END) as f28,
                    SUM(CASE WHEN df.horizon_day <= COALESCE(
                        (SELECT lead_time_days FROM supplier_products WHERE product_id = df.product_id AND is_preferred = 1 LIMIT 1),
                        (SELECT supplier_lead_time_days FROM products WHERE product_id = df.product_id LIMIT 1)
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
            $currentStock = (int)$row['current_stock'];
            $inbound = (int)$row['inbound_quantity'];
            $leadTimeDemand = (int)$row['forecast_lead_time'];
            $moq = (int)$row['moq'];
            $leadTimeDays = (int)$row['lead_time_days'];
            
            $effectiveStock = $currentStock + $inbound;
            $exposure = $effectiveStock - $leadTimeDemand;
            
            // Urgency rule
            $urgency = 'Covered';
            if ($exposure < 0) {
                $urgency = 'Urgent';
            } elseif ($exposure < (int)$row['forecast_7_day']) {
                $urgency = 'Watch';
            }
            
            $recommendedQty = 0;
            if ($urgency === 'Urgent' || $urgency === 'Watch') {
                // target stock: lead time demand + 28 days safety
                $target = $leadTimeDemand + (int)$row['forecast_28_day'];
                $deficit = $target - $effectiveStock;
                if ($deficit > 0) {
                    $recommendedQty = max($moq, (int)ceil($deficit / $moq) * $moq);
                }
            }
            
            $queue[] = [
                'product_id' => (int)$row['product_id'],
                'sku' => $row['sku'],
                'product_name' => $row['product_name'],
                'current_stock' => $currentStock,
                'inbound_quantity' => $inbound,
                'lead_time_days' => $leadTimeDays,
                'moq' => $moq,
                'forecast_7_day' => (int)$row['forecast_7_day'],
                'forecast_14_day' => (int)$row['forecast_14_day'],
                'forecast_28_day' => (int)$row['forecast_28_day'],
                'forecast_lead_time' => $leadTimeDemand,
                'readiness_level' => $row['readiness_level'],
                'forecast_origin' => $row['forecast_origin'],
                'stock_exposure' => $exposure,
                'urgency_label' => $urgency,
                'recommended_quantity' => $recommendedQty,
                'explanation' => sprintf(
                    "%d on hand + %d inbound = %d effective. Lead time demand is %d. Exposure: %d.",
                    $currentStock, $inbound, $effectiveStock, $leadTimeDemand, $exposure
                )
            ];
        }
        
        // Sort by exposure (lowest first, meaning highest risk), then by urgency
        usort($queue, function($a, $b) {
            if ($a['stock_exposure'] !== $b['stock_exposure']) {
                return $a['stock_exposure'] <=> $b['stock_exposure'];
            }
            return $a['product_id'] <=> $b['product_id'];
        });
        
        return $queue;
    }
}
