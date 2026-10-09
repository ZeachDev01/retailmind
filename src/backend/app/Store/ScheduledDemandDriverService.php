<?php

namespace App\Store;

use PDO;

class ScheduledDemandDriverService
{
    /**
     * Records a scheduled driver for a specific product and effective date.
     */
    public function recordDriver(PDO $pdo, int $productId, string $driverType, string $effectiveDate, string $scheduledAt, string $detailsJson): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO scheduled_demand_drivers (product_id, driver_type, effective_date, scheduled_at, details_json) 
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$productId, $driverType, $effectiveDate, $scheduledAt, $detailsJson]);
    }

    /**
     * Retrieves the latest scheduled drivers for a product on a specific date, as known at the cutoff time.
     */
    public function getEffectiveDrivers(PDO $pdo, int $productId, string $effectiveDate, string $cutoffTimestamp): array
    {
        // Get the latest recorded driver for this product and date before the cutoff.
        $stmt = $pdo->prepare(
            'SELECT * FROM scheduled_demand_drivers 
             WHERE product_id = ? AND effective_date = ? AND scheduled_at <= ?
             ORDER BY scheduled_at DESC LIMIT 1'
        );
        $stmt->execute([$productId, $effectiveDate, $cutoffTimestamp]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? [$row] : [];
    }

    /**
     * Records or cancels daily drivers for all products affected by a promotion.
     */
    public function syncPromotionDrivers(PDO $pdo, int $promotionId, bool $isCancellation = false): void
    {
        $stmt = $pdo->prepare('SELECT * FROM promotions WHERE promotion_id = ?');
        $stmt->execute([$promotionId]);
        $promotion = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$promotion) return;
        
        $scheduledAt = date('Y-m-d H:i:s');
        $details = $isCancellation ? '{"cancelled": true}' : json_encode([
            'promotion_id' => $promotion['promotion_id'],
            'discount_type' => $promotion['discount_type'],
            'discount_value' => (float)$promotion['discount_value']
        ], JSON_THROW_ON_ERROR);

        $products = [];
        if ($promotion['scope'] === 'all') {
            $products = $pdo->query("SELECT product_id FROM products")->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($promotion['scope'] === 'category') {
            $stmt = $pdo->prepare("SELECT product_id FROM products WHERE category_id = ?");
            $stmt->execute([$promotion['category_id']]);
            $products = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($promotion['scope'] === 'product') {
            $products = [(int)$promotion['product_id']];
        }

        $start = strtotime(substr($promotion['starts_at'], 0, 10));
        $end = strtotime(substr($promotion['ends_at'], 0, 10));
        
        $insert = $pdo->prepare(
            'INSERT INTO scheduled_demand_drivers (product_id, driver_type, effective_date, scheduled_at, details_json) 
             VALUES (?, "promotion", ?, ?, ?)'
        );

        foreach ($products as $productId) {
            for ($time = $start; $time <= $end; $time += 86400) {
                $insert->execute([$productId, date('Y-m-d', $time), $scheduledAt, $details]);
            }
        }
    }
}
