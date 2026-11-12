<?php
declare(strict_types=1);

namespace App\Inventory;

use App\Forecasting\ForecastReadinessQuery;
use App\Services\PhilippineTime;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class StockRiskReader
{
    /** Historical guidance only; reads the same scoped products and expiry warnings as Stock Overview. */
    public static function read(PDO $pdo, array $products, array $expiringBatches, ?string $today = null): array
    {
        $products = array_values(array_filter($products, static fn(array $p): bool => ($p['status'] ?? 'active') === 'active'));
        if (!$products) return [];

        $date = new DateTimeImmutable($today ?? PhilippineTime::today(), new DateTimeZone('Asia/Manila'));
        $start30 = $date->modify('-29 days')->format('Y-m-d');
        $start90 = $date->modify('-89 days')->format('Y-m-d');
        $end = $date->modify('+1 day')->format('Y-m-d');
        $ids = array_map('intval', array_column($products, 'product_id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $units = ForecastReadinessQuery::salesUnitsSql($pdo, 'si');
        $salesQuery = $pdo->prepare("SELECT si.product_id,
            SUM(CASE WHEN s.sale_date >= ? THEN {$units} ELSE 0 END) AS units30,
            SUM({$units}) AS units90
            FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
            WHERE si.product_id IN ({$placeholders}) AND s.sale_date >= ? AND s.sale_date < ?
            GROUP BY si.product_id");
        $salesQuery->execute(array_merge([$start30], $ids, [$start90, $end]));
        $sales = [];
        foreach ($salesQuery->fetchAll(PDO::FETCH_ASSOC) as $row) $sales[(int)$row['product_id']] = $row;

        $history = [];
        foreach ($products as $product) {
            $stock = (int)$product['quantity_on_hand'];
            $history[(int)$product['product_id']] = ['balance' => $stock, 'min30' => $stock, 'min90' => $stock, 'in' => 0, 'out' => 0];
        }
        $movements = $pdo->prepare("SELECT product_id, change_qty, moved_at FROM stock_movements
            WHERE product_id IN ({$placeholders}) AND moved_at >= ? AND moved_at < ?
            ORDER BY moved_at DESC, movement_id DESC");
        $movements->execute(array_merge($ids, [$start90, $end]));
        while ($movement = $movements->fetch(PDO::FETCH_ASSOC)) {
            $item = &$history[(int)$movement['product_id']];
            $change = (int)$movement['change_qty'];
            $item['balance'] -= $change;
            $item['min90'] = min($item['min90'], $item['balance']);
            if ($movement['moved_at'] >= $start30) $item['min30'] = min($item['min30'], $item['balance']);
            $item['in'] += max(0, $change);
            $item['out'] += max(0, -$change);
            unset($item);
        }
        $expiry = [];
        foreach ($expiringBatches as $batch) {
            $id = (int)$batch['product_id'];
            $expiry[$id] = ($expiry[$id] ?? 0) + (int)$batch['remaining_quantity'];
        }

        $rows = [];
        foreach ($products as $product) {
            $id = (int)$product['product_id'];
            $stock = (int)$product['quantity_on_hand'];
            $expired = (int)($product['expired_quantity'] ?? 0);
            $available = max(0, $stock - $expired);
            $units30 = (int)($sales[$id]['units30'] ?? 0);
            $units90 = (int)($sales[$id]['units90'] ?? 0);
            $created = substr((string)($product['created_at'] ?? ''), 0, 10);
            $has30 = $created !== '' && $created <= $start30 && $history[$id]['min30'] > 0;
            $has90 = $created !== '' && $created <= $start90 && $history[$id]['min90'] > 0;
            $cover = $has30 && $units30 > 0 ? $available / ($units30 / 30) : null;
            $flags = [];
            if ($expired > 0 || ($expiry[$id] ?? 0) > 0 || !empty($product['has_missing_expiration'])) $flags[] = 'expiry';
            if ($stock > 0 && $has90 && $units90 === 0) $flags[] = 'no-sales';
            if ($cover !== null && $cover > 30 && $stock > 0) $flags[] = 'excess';
            if ($stock > 0 && (!$has30 || ($units30 === 0 && !$has90))) $flags[] = 'limited';
            $label = 'No risk flagged';
            $kind = 'normal';
            $action = 'Continue normal monitoring. Review buying quantities in Replenishment Recommendations.';
            if ($stock <= 0) {
                $label = 'Out of stock';
                $action = 'Review Replenishment Recommendations for buying guidance.';
            } elseif (in_array('limited', $flags, true)) {
                $kind = 'limited';
                $label = $created === '' || $created > $start30 ? 'Limited history' : 'Stockout affected history';
                $action = 'Let sales history build while stock is available before judging the sales pace.';
            } elseif (in_array('excess', $flags, true)) {
                $kind = 'excess';
                $label = 'Possible excess';
                $action = 'Review remaining stock before buying more. This estimate assumes the recent sales pace continues.';
            }
            if (in_array('no-sales', $flags, true)) {
                $kind = 'no-sales';
                $label = 'No sales in 90 days';
                $action = 'Check shelf placement and demand before buying more. No recorded sales does not prove there is no demand.';
            }
            if (in_array('expiry', $flags, true)) {
                $kind = 'expiry';
                $label = $expired > 0 ? 'Expired stock' : (($expiry[$id] ?? 0) > 0 ? 'Expiring soon' : 'Missing expiration');
                $action = $expired > 0 ? 'Keep expired stock off the shelf. Use the existing Stock Issue or Supplier Pullout workflow.'
                    : (($expiry[$id] ?? 0) > 0 ? 'Sell the earliest-expiring eligible batch first.' : 'Check and record missing batch expiration dates.');
            }
            $historyNote = !$has30 ? ($created === '' || $created > $start30 ? 'Less than 30 days of established product history.' : 'Recorded balance reached zero or below in the past 30 days.')
                : (!$has90 ? 'The full 90-day availability history is not established.' : 'Recorded balance stayed above zero for the past 90 days.');
            $rows[] = array_merge($product, [
                'units30' => $units30, 'units90' => $units90, 'days_of_stock' => $cover,
                'saleable_stock' => $available, 'expiring_quantity' => $expiry[$id] ?? 0,
                'flags' => $flags, 'risk_kind' => $kind, 'risk_label' => $label, 'suggested_action' => $action,
                'history_note' => $historyNote, 'opening_balance' => $history[$id]['balance'],
                'stock_in90' => $history[$id]['in'], 'stock_out90' => $history[$id]['out'],
            ]);
        }
        $priority = ['expiry' => 0, 'no-sales' => 1, 'excess' => 2, 'limited' => 3, 'normal' => 4];
        usort($rows, static fn(array $a, array $b): int => ($priority[$a['risk_kind']] <=> $priority[$b['risk_kind']]) ?: strnatcasecmp($a['product_name'], $b['product_name']));
        return $rows;
    }
}
