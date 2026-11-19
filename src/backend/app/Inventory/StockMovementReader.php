<?php
declare(strict_types=1);

namespace App\Inventory;

use App\Forecasting\ForecastReadinessQuery;
use App\Services\PhilippineTime;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class StockMovementReader
{
    public static function period(?string $today = null): array
    {
        $date = new DateTimeImmutable($today ?? PhilippineTime::today(), new DateTimeZone('Asia/Manila'));
        $windows = [];
        for ($i = 0; $i < 3; $i++) {
            $end = $date->modify('-' . ($i * 30) . ' days');
            $start = $end->modify('-30 days');
            $windows[] = ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
                'label' => $start->format('M j, Y') . ' – ' . $end->modify('-1 day')->format('M j, Y')];
        }
        return [
            'start' => $windows[2]['start'], 'recent_start' => $windows[0]['start'],
            'end' => $date->format('Y-m-d'), 'today_end' => $date->modify('+1 day')->format('Y-m-d'),
            'windows' => $windows, 'label' => $windows[0]['label'],
            'review_label' => (new DateTimeImmutable($windows[2]['start']))->format('M j, Y') . ' – ' . $date->modify('-1 day')->format('M j, Y'),
        ];
    }

    /** Gross recorded sales; today's movements are reversed only for the historical balance. */
    public static function read(PDO $pdo, array $products, array $period): array
    {
        $products = array_values(array_filter($products, static fn(array $p): bool => ($p['status'] ?? 'active') === 'active'));
        if (!$products) return [];
        $ids = array_map('intval', array_column($products, 'product_id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $units = ForecastReadinessQuery::salesUnitsSql($pdo, 'si');
        $query = $pdo->prepare("SELECT si.product_id, DATE(s.sale_date) AS sale_day, SUM({$units}) AS units
            FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
            WHERE si.product_id IN ({$placeholders}) AND s.sale_date >= ? AND s.sale_date < ?
            GROUP BY si.product_id, DATE(s.sale_date) HAVING SUM({$units}) > 0");
        $query->execute(array_merge($ids, [$period['start'], $period['end']]));
        $sales = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach ($period['windows'] as $i => $window) {
                if ($row['sale_day'] >= $window['start'] && $row['sale_day'] < $window['end']) {
                    $id = (int)$row['product_id'];
                    $sales[$id][$i]['units'] = ($sales[$id][$i]['units'] ?? 0) + (int)$row['units'];
                    $sales[$id][$i]['days'] = ($sales[$id][$i]['days'] ?? 0) + 1;
                    break;
                }
            }
        }

        $history = [];
        foreach ($products as $product) {
            $stock = (int)$product['quantity_on_hand'];
            $history[(int)$product['product_id']] = ['balance' => $stock, 'remaining' => $stock, 'minimum' => $stock,
                'received' => 0, 'sale_out' => 0, 'returns' => 0, 'other' => 0];
        }
        $query = $pdo->prepare("SELECT product_id, change_qty, reason, moved_at FROM stock_movements
            WHERE product_id IN ({$placeholders}) AND moved_at >= ? AND moved_at < ?
            ORDER BY moved_at DESC, movement_id DESC");
        $query->execute(array_merge($ids, [$period['start'], $period['today_end']]));
        while ($movement = $query->fetch(PDO::FETCH_ASSOC)) {
            $item = &$history[(int)$movement['product_id']];
            $change = (int)$movement['change_qty'];
            $item['balance'] -= $change;
            if ($movement['moved_at'] >= $period['end']) {
                $item['remaining'] -= $change;
                $item['minimum'] = $item['remaining'];
            } else {
                $item['minimum'] = min($item['minimum'], $item['balance']);
                if ($movement['moved_at'] >= $period['recent_start']) {
                    $bucket = match ($movement['reason']) {
                        'purchase' => 'received', 'sale' => 'sale_out', 'return' => 'returns', default => 'other',
                    };
                    $item[$bucket] += $change;
                }
            }
            unset($item);
        }
        $rows = [];
        foreach ($products as $product) {
            $id = (int)$product['product_id'];
            $created = substr((string)($product['created_at'] ?? ''), 0, 10);
            if ($created > $period['end']) continue;
            $item = $history[$id];
            $sold = $days = $salesPeriods = [];
            foreach ($period['windows'] as $i => $window) {
                $unitsSold = (int)($sales[$id][$i]['units'] ?? 0);
                $sellingDays = (int)($sales[$id][$i]['days'] ?? 0);
                $sold[] = $unitsSold;
                $days[] = $sellingDays;
                $coverage = match (true) {
                    $created === '' => 'unknown',
                    $created >= $window['end'] => 'unavailable',
                    $created > $window['start'] => 'partial',
                    default => 'complete',
                };
                $showFigures = $unitsSold > 0 || $coverage === 'complete' || $coverage === 'partial';
                $salesPeriods[] = $window + [
                    'units_sold' => $showFigures ? $unitsSold : null,
                    'selling_days' => $showFigures ? $sellingDays : null,
                    'coverage' => $coverage,
                ];
            }
            $total = array_sum($sold);
            $kind = match (true) {
                $created === '' || $created > $period['start'] => 'limited',
                $total === 0 && (int)$product['quantity_on_hand'] > 0 => 'dead',
                $item['minimum'] <= 0 || (int)$product['quantity_on_hand'] <= 0 => 'stockout',
                $days[0] >= 10 => 'fast',
                default => 'slow',
            };
            $label = match ($kind) {
                'limited' => 'Limited history', 'stockout' => 'Stockout affected',
                'dead' => 'Dead Stock', 'fast' => 'Fast-Moving', default => 'Slow-Moving',
            };
            $note = match (true) {
                $kind === 'limited' => 'Less than 90 days of product history, or creation date unavailable. A complete movement judgment is unavailable.',
                $kind === 'stockout' || $item['minimum'] <= 0 => 'Stock is unavailable now or reached zero or below in the 90-day review. Sales may have been affected by unavailable stock.',
                default => 'Reconstructed stock stayed positive throughout the 90-day review.',
            };
            $trend = in_array($kind, ['limited', 'stockout'], true) || $item['minimum'] <= 0 ? null : ($days[0] * 2 <=> $days[1] + $days[2]);
            $item['balance'] = $item['remaining'] - $item['received'] - $item['sale_out'] - $item['returns'] - $item['other'];
            $rows[] = array_merge($product, $item, ['period_sales' => $sold, 'period_days' => $days,
                'sales_periods' => array_reverse($salesPeriods),
                'total_sales' => $total, 'recent_sales' => $sold[0], 'selling_days' => $days[0],
                'trend' => $trend, 'previous_average' => ($days[1] + $days[2]) / 2,
                'movement_kind' => $kind, 'movement_label' => $label, 'history_note' => $note]);
        }
        usort($rows, static fn(array $a, array $b): int => ($a['selling_days'] <=> $b['selling_days']) ?: strnatcasecmp($a['product_name'], $b['product_name']));
        return $rows;
    }
}
