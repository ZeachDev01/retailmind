<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Attention\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Canonical stock-condition fact queries for slice-1 parity refactors.
 * Methods preserve existing consumer behavior; semantic unification is follow-up work.
 */
final class StockConditionReader
{
    private const MANILA = 'Asia/Manila';

    /** @return array<int, array<string, mixed>> NotificationService low-stock query (unscoped). */
    public static function lowStockProductsForAlerts(PDO $pdo): array
    {
        $sql = "SELECT p.product_id, p.product_name, p.sku, i.quantity_on_hand, p.reorder_level
                FROM products p
                JOIN inventory i ON p.product_id = i.product_id
                WHERE i.quantity_on_hand <= p.reorder_level";
        return $pdo->query($sql)->fetchAll();
    }

    /** @return array<int, array<string, mixed>> StockBrief reorder-breach query (LEFT JOIN/COALESCE). */
    public static function reorderBreachProductsForStockBrief(PDO $pdo): array
    {
        $stockSql = "SELECT p.product_id, p.product_name, p.sku, COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand, p.reorder_level
                     FROM products p
                     LEFT JOIN inventory i ON p.product_id = i.product_id
                     WHERE COALESCE(i.quantity_on_hand, 0) <= p.reorder_level
                     ORDER BY (COALESCE(i.quantity_on_hand, 0) <= 0) DESC, i.quantity_on_hand ASC, p.product_name ASC";
        return $pdo->query($stockSql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public static function eligibleExpiryReminders(PDO $pdo, Clock $clock, int $days = 30): array
    {
        $reminders = [];
        foreach (self::expiringStockBatchesForAlerts($pdo, $clock, $days) as $batch) {
            $daysRemaining = (int)$batch['days_remaining'];
            $threshold = (int)($batch['warning_lead_time_days'] ?? $days);
            $reminders[] = $batch + [
                'type' => 'expiring_stock',
                'threshold' => $threshold,
                'reference_type' => 'product_batch_expiry_' . $threshold,
                'title' => "Expiry {$threshold}-Day Alert: " . $batch['product_name'],
                'message' => "Batch {$batch['batch_number']} of {$batch['product_name']} ({$batch['sku']}) expires on {$batch['expiration_date']} ({$daysRemaining} day(s) remaining). Stock: {$batch['remaining_quantity']}.",
            ];
        }
        foreach (self::expiredStockBatchesForAlerts($pdo, $clock) as $batch) {
            $reminders[] = $batch + [
                'type' => 'expired_stock',
                'reference_type' => 'product_batch',
                'title' => ($batch['supplier_pullout_eligible'] ? 'Expired bread—waiting for supplier collection: ' : 'Expired Stock Alert: ') . $batch['product_name'],
                'message' => "Batch {$batch['batch_number']} of {$batch['product_name']} ({$batch['sku']}) expired on {$batch['expiration_date']}. Remaining: {$batch['remaining_quantity']}.",
            ];
        }
        return $reminders;
    }

    /** @return array<int, array<string, mixed>> NotificationService getExpiringBatches(maxDays,false). */
    public static function expiringStockBatchesForAlerts(PDO $pdo, Clock $clock, int $days): array
    {
        $today = self::manilaToday($clock);
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $diffExpr = "CAST(ROUND(julianday(pb.expiration_date) - julianday(?)) AS INTEGER)";
            $sql = "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                           {$diffExpr} AS days_remaining, p.sku, p.product_name, p.warning_lead_time_days, p.supplier_pullout_eligible
                    FROM product_batches pb
                    JOIN products p ON p.product_id = pb.product_id
                    WHERE pb.remaining_quantity > 0
                      AND pb.expiration_date IS NOT NULL
                      AND pb.expiration_date >= ?
                      AND {$diffExpr} <= COALESCE(p.warning_lead_time_days, ?)
                    ORDER BY pb.expiration_date ASC, p.product_name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$today, $today, $today, $days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                       DATEDIFF(pb.expiration_date, ?) AS days_remaining, p.sku, p.product_name, p.warning_lead_time_days, p.supplier_pullout_eligible
                FROM product_batches pb
                JOIN products p ON p.product_id = pb.product_id
                WHERE pb.remaining_quantity > 0
                  AND pb.expiration_date IS NOT NULL
                  AND pb.expiration_date >= ?
                  AND DATEDIFF(pb.expiration_date, ?) <= COALESCE(p.warning_lead_time_days, ?)
                ORDER BY pb.expiration_date ASC, p.product_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$today, $today, $today, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> NotificationService getExpiringBatches(...,true). */
    public static function expiredStockBatchesForAlerts(PDO $pdo, Clock $clock): array
    {
        $today = self::manilaToday($clock);
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $diffExpr = "CAST(ROUND(julianday(pb.expiration_date) - julianday(?)) AS INTEGER)";
            $sql = "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                           {$diffExpr} AS days_remaining, p.sku, p.product_name, p.warning_lead_time_days, p.supplier_pullout_eligible
                    FROM product_batches pb
                    JOIN products p ON p.product_id = pb.product_id
                    WHERE pb.remaining_quantity > 0
                      AND pb.expiration_date IS NOT NULL
                      AND pb.expiration_date < ?
                    ORDER BY pb.expiration_date ASC, p.product_name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$today, $today]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                       DATEDIFF(pb.expiration_date, ?) AS days_remaining, p.sku, p.product_name, p.warning_lead_time_days, p.supplier_pullout_eligible
                FROM product_batches pb
                JOIN products p ON p.product_id = pb.product_id
                WHERE pb.remaining_quantity > 0
                  AND pb.expiration_date IS NOT NULL
                  AND pb.expiration_date < ?
                ORDER BY pb.expiration_date ASC, p.product_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$today, $today]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> StockBriefService buildReport expiry query (current behavior). */
    public static function expiryBatchesForStockBrief(PDO $pdo, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $todayQuoted = $pdo->quote($today);
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $diffExpr = "CAST(ROUND(julianday(pb.expiration_date) - julianday({$todayQuoted})) AS INTEGER)";
        } else {
            $diffExpr = "DATEDIFF(pb.expiration_date, {$todayQuoted})";
        }

        $expSql = "SELECT pb.batch_id, pb.batch_number, pb.remaining_quantity, pb.expiration_date,
                          {$diffExpr} AS days_remaining, p.product_name, p.sku
                   FROM product_batches pb
                   JOIN products p ON p.product_id = pb.product_id
                   WHERE pb.remaining_quantity > 0
                     AND pb.expiration_date IS NOT NULL
                     AND ({$diffExpr} <= COALESCE(p.warning_lead_time_days, 30) OR pb.expiration_date < {$todayQuoted})
                   ORDER BY pb.expiration_date ASC LIMIT 50";
        return $pdo->query($expSql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function manilaToday(Clock $clock): string
    {
        return $clock->now()->setTimezone(new DateTimeZone(self::MANILA))->format('Y-m-d');
    }
}
