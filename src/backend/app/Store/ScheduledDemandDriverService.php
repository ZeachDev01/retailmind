<?php
declare(strict_types=1);

namespace App\Store;

use PDO;
use Throwable;

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
     * Deterministically resolves driver revisions by type and identity without dropping other types
     * (e.g. newer price event does not hide applicable promotions; multiple promotions coexist).
     */
    public function getEffectiveDrivers(PDO $pdo, int $productId, string $effectiveDate, string $cutoffTimestamp): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM scheduled_demand_drivers 
             WHERE product_id = ? AND effective_date = ? AND scheduled_at <= ?
             ORDER BY scheduled_at DESC, driver_id DESC'
        );
        $stmt->execute([$productId, $effectiveDate, $cutoffTimestamp]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resolved = [];
        foreach ($rows as $row) {
            $type = (string)$row['driver_type'];
            $details = json_decode($row['details_json'] ?? '{}', true) ?: [];

            if ($type === 'promotion') {
                $promoId = $details['promotion_id'] ?? null;
                $key = $promoId !== null ? "promotion:{$promoId}" : 'promotion:default';
            } else {
                $key = $type;
            }

            if (!isset($resolved[$key])) {
                $resolved[$key] = $row;
            }
        }

        return array_values($resolved);
    }

    /**
     * Retrieves the effective drivers for a period.
     */
    public function getDriversForPeriod(PDO $pdo, int $productId, string $startDate, string $endDate, string $cutoffTimestamp): array
    {
        $stmt = $pdo->prepare(
            'SELECT effective_date, driver_type, details_json, scheduled_at, driver_id'
            . ' FROM scheduled_demand_drivers'
            . ' WHERE product_id = ? AND effective_date >= ? AND effective_date <= ? AND scheduled_at <= ?'
            . ' ORDER BY scheduled_at DESC, driver_id DESC'
        );
        $stmt->execute([$productId, $startDate, $endDate, $cutoffTimestamp]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resolved = [];
        foreach ($rows as $row) {
            $date = $row['effective_date'];
            $type = (string)$row['driver_type'];
            $details = json_decode($row['details_json'] ?? '{}', true) ?: [];

            if ($type === 'promotion') {
                $promoId = $details['promotion_id'] ?? null;
                $key = $promoId !== null ? "{$date}:promotion:{$promoId}" : "{$date}:promotion:default";
            } else {
                $key = "{$date}:{$type}";
            }

            if (!isset($resolved[$key])) {
                $resolved[$key] = [
                    'effective_date' => $row['effective_date'],
                    'driver_type' => $row['driver_type'],
                    'details_json' => $row['details_json'],
                ];
            }
        }

        return array_values($resolved);
    }

    /**
     * Records or cancels daily drivers for all products affected by a promotion, preserving promotion identity.
     */
    public function syncPromotionDrivers(PDO $pdo, int $promotionId, bool $isCancellation = false, ?string $scheduledAt = null): void
    {
        $stmt = $pdo->prepare('SELECT * FROM promotions WHERE promotion_id = ?');
        $stmt->execute([$promotionId]);
        $promotion = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$promotion) return;

        $scheduledAt = $scheduledAt ?? date('Y-m-d H:i:s');
        $details = json_encode([
            'promotion_id' => (int)$promotion['promotion_id'],
            'promotion_name' => $promotion['promotion_name'] ?? null,
            'discount_type' => $promotion['discount_type'],
            'discount_value' => (float)$promotion['discount_value'],
            'minimum_quantity' => (int)($promotion['minimum_quantity'] ?? 1),
            'scope' => $promotion['scope'],
            'product_id' => isset($promotion['product_id']) ? (int)$promotion['product_id'] : null,
            'category_id' => isset($promotion['category_id']) ? (int)$promotion['category_id'] : null,
            'starts_at' => $promotion['starts_at'],
            'ends_at' => $promotion['ends_at'],
            'cancelled' => $isCancellation,
        ], JSON_THROW_ON_ERROR);

        $products = [];
        if ($promotion['scope'] === 'all') {
            $products = $pdo->query('SELECT product_id FROM products')->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($promotion['scope'] === 'category') {
            $stmt = $pdo->prepare('SELECT product_id FROM products WHERE category_id = ?');
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

    /**
     * Resolves normal selling price per Base Unit deterministically by effective date and cutoff.
     * If no price driver exists for the date, checks historical transaction price with recorded
     * selling-form conversion. Returns null if neither exists; does not backfill from today's price.
     */
    public function getEffectivePrice(PDO $pdo, int $productId, string $date, string $cutoffTimestamp): ?float
    {
        // 1. Look for recorded normal selling price driver taking effect on or before this date, known at cutoff
        try {
            $stmt = $pdo->prepare(
                'SELECT details_json FROM scheduled_demand_drivers'
                . ' WHERE product_id = ? AND driver_type = "price" AND effective_date <= ? AND scheduled_at <= ?'
                . ' ORDER BY effective_date DESC, scheduled_at DESC, driver_id DESC LIMIT 1'
            );
            $stmt->execute([$productId, $date, $cutoffTimestamp]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $details = json_decode($row['details_json'] ?? '{}', true);
                if (isset($details['unit_price'])) {
                    return (float)$details['unit_price'];
                }
                if (isset($details['price'])) {
                    return (float)$details['price'];
                }
            }
        } catch (Throwable) {
            // scheduled_demand_drivers might not exist in minimal environments
        }

        // 2. Transaction prices have transaction-only coverage and require recorded selling-form conversion
        try {
            $startOfDay = $date . ' 00:00:00';
            $effectiveEnd = min($date . ' 23:59:59', $cutoffTimestamp);
            $stmt = $pdo->prepare(
                'SELECT si.unit_price, si.selling_form, si.pack_size'
                . ' FROM sale_items si'
                . ' JOIN sales s ON si.sale_id = s.sale_id'
                . ' WHERE si.product_id = ? AND s.sale_date >= ? AND s.sale_date <= ?'
                . ' ORDER BY s.sale_date DESC LIMIT 1'
            );
            $stmt->execute([$productId, $startOfDay, $effectiveEnd]);
            $trans = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($trans && isset($trans['unit_price'])) {
                if (($trans['selling_form'] ?? '') === 'pack' && !empty($trans['pack_size']) && (int)$trans['pack_size'] > 0) {
                    return (float)$trans['unit_price'] / (int)$trans['pack_size'];
                }
                return (float)$trans['unit_price'];
            }
        } catch (Throwable) {
            // sale_items or columns might not exist
        }

        // 3. Do not backfill earlier days from today's price; return null
        return null;
    }

    /**
     * Resolves whether an applicable promotion offer exists on the date as known at cutoff.
     * Unknown is distinguishable (null) from no promotion (false).
     */
    public function resolveIsOnPromotion(
        PDO $pdo,
        int $productId,
        string $date,
        string $cutoffTimestamp,
        array $effectiveDrivers,
        ?string $calendarStatus = null,
        bool $isImported = false
    ): ?bool {
        $hasPromotionDriver = false;
        $activeOfferExists = false;

        foreach ($effectiveDrivers as $driver) {
            if (($driver['driver_type'] ?? '') === 'promotion') {
                $hasPromotionDriver = true;
                $details = json_decode($driver['details_json'] ?? '{}', true) ?: [];
                if (empty($details['cancelled'])) {
                    $activeOfferExists = true;
                    break;
                }
            }
        }

        if ($activeOfferExists) {
            return true;
        }

        if ($hasPromotionDriver) {
            // All scheduled offers were cancelled
            return false;
        }

        // No promotion drivers recorded for this date
        if ($isImported || $calendarStatus === null) {
            return null; // Unknown / untracked promotion evidence
        }

        return false; // Active store operation observed without scheduled promotion
    }
}
