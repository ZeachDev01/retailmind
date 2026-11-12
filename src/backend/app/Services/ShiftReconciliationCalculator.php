<?php

namespace App\Services;

use PDO;

/**
 * Authoritative Shift Reconciliation Calculator.
 *
 * Encapsulates the four core financial aggregation queries and drawer balance arithmetic:
 * 1) sales breakdown by payment method,
 * 2) cash drawer movements,
 * 3) append-only cash refunds,
 * 4) legacy approved reversals.
 *
 * Both single-shift preview/closing and batched store shift reporting delegate their
 * SQL aggregation and expected-cash arithmetic to this calculator.
 */
class ShiftReconciliationCalculator
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Default financial summary with zeroed balances.
     */
    public static function defaultSummary(float $openingCash = 0.0): array
    {
        return [
            'sale_count' => 0,
            'refund_count' => 0,
            'total_sales' => 0.0,
            'cash_sales' => 0.0,
            'card_sales' => 0.0,
            'ewallet_sales' => 0.0,
            'discounts' => 0.0,
            'cash_in' => 0.0,
            'cash_out' => 0.0,
            'safe_drop' => 0.0,
            'cash_refunds' => 0.0,
            'card_refunds' => 0.0,
            'ewallet_refunds' => 0.0,
            'total_refunds' => 0.0,
            'reversal_cash' => 0.0,
            'approved_reversal_cash' => 0.0,
            'opening_cash' => round($openingCash, 2),
            'calculated_expected_cash' => round($openingCash, 2),
        ];
    }

    /**
     * Authoritative expected cash arithmetic (penny exact).
     *
     * Expected cash = opening cash + cash sales + cash-ins - cash-outs - safe drops
     *                 - issuing-drawer Cash Refunds - approved legacy reversals.
     */
    public static function calculateExpectedCash(
        float $openingCash,
        float $cashSales,
        float $cashIn,
        float $cashOut,
        float $safeDrop,
        float $cashRefunds,
        float $reversalCash
    ): float {
        $cents = (int)round($openingCash * 100)
            + (int)round($cashSales * 100)
            + (int)round($cashIn * 100)
            - (int)round($cashOut * 100)
            - (int)round($safeDrop * 100)
            - (int)round($cashRefunds * 100)
            - (int)round($reversalCash * 100);

        return round($cents / 100, 2);
    }

    /**
     * Authoritative cash variance arithmetic.
     * Positive = overage, Negative = shortage.
     */
    public static function calculateVariance(float $countedCash, float $expectedCash): float
    {
        $countedCents = (int)round($countedCash * 100);
        $expectedCents = (int)round($expectedCash * 100);
        return round(($countedCents - $expectedCents) / 100, 2);
    }

    /**
     * Calculate financial summary for a single shift.
     *
     * Executes the 4 aggregate queries for this shift.
     */
    public function calculateForShift(int $shiftId, float $openingCash = 0.0): array
    {
        $results = $this->calculateForSubquery('?', [$shiftId], [$shiftId => $openingCash]);
        return $results[$shiftId] ?? self::defaultSummary($openingCash);
    }

    /**
     * Calculate financial summaries for a batch of shift IDs.
     *
     * Retains 4 aggregate queries across all batched shifts.
     * Empty array executes 0 queries.
     *
     * @param int[] $shiftIds
     * @param array<int, float> $shiftOpeningCashes [shift_id => opening_cash]
     * @return array<int, array> [shift_id => summary]
     */
    public function calculateBatch(array $shiftIds, array $shiftOpeningCashes = []): array
    {
        if (empty($shiftIds)) {
            return [];
        }
        $cleanIds = array_values(array_filter(array_map('intval', $shiftIds), fn(int $id) => $id > 0));
        if (empty($cleanIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        return $this->calculateForSubquery($placeholders, $cleanIds, $shiftOpeningCashes);
    }

    /**
     * Calculate financial summaries for shifts matching a subquery.
     *
     * Encapsulates the 4 core financial aggregation queries:
     * 1) Sales breakdown by payment method
     * 2) Cash drawer movements
     * 3) Append-only cash refunds
     * 4) Legacy approved reversals
     *
     * @param string $subquerySql The expression inside IN (...)
     * @param array $params Query parameters for the subquery
     * @param array<int, float> $shiftOpeningCashes Pre-known opening cash keyed by shift_id
     * @return array<int, array> [shift_id => summary]
     */
    public function calculateForSubquery(string $subquerySql, array $params = [], array $shiftOpeningCashes = []): array
    {
        $summaries = [];
        foreach ($shiftOpeningCashes as $id => $opening) {
            $summaries[(int)$id] = self::defaultSummary((float)$opening);
        }

        // Query 1: Sales breakdown by payment method
        $salesSql = "SELECT shift_id,
                            COUNT(*) AS sale_count,
                            COALESCE(SUM(total_amount), 0) AS total_sales,
                            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) AS cash_sales,
                            COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) AS card_sales,
                            COALESCE(SUM(CASE WHEN payment_method = 'ewallet' THEN total_amount ELSE 0 END), 0) AS ewallet_sales,
                            COALESCE(SUM(discount_amount), 0) AS discounts
                     FROM sales
                     WHERE shift_id IN ($subquerySql)
                     GROUP BY shift_id";
        $salesStmt = $this->pdo->prepare($salesSql);
        $salesStmt->execute($params);
        foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['shift_id'];
            if (!isset($summaries[$id])) {
                $summaries[$id] = self::defaultSummary(0.0);
            }
            $summaries[$id]['sale_count'] = (int)$row['sale_count'];
            $summaries[$id]['total_sales'] = round((float)$row['total_sales'], 2);
            $summaries[$id]['cash_sales'] = round((float)$row['cash_sales'], 2);
            $summaries[$id]['card_sales'] = round((float)$row['card_sales'], 2);
            $summaries[$id]['ewallet_sales'] = round((float)$row['ewallet_sales'], 2);
            $summaries[$id]['discounts'] = round((float)$row['discounts'], 2);
        }

        // Query 2: Cash drawer movements
        $movesSql = "SELECT shift_id,
                            COALESCE(SUM(CASE WHEN movement_type IN ('cash_in', 'pay_in') THEN amount ELSE 0 END), 0) AS cash_in,
                            COALESCE(SUM(CASE WHEN movement_type IN ('cash_out', 'pay_out') THEN amount ELSE 0 END), 0) AS cash_out,
                            COALESCE(SUM(CASE WHEN movement_type = 'safe_drop' THEN amount ELSE 0 END), 0) AS safe_drop
                     FROM cash_drawer_movements
                     WHERE shift_id IN ($subquerySql)
                     GROUP BY shift_id";
        $movesStmt = $this->pdo->prepare($movesSql);
        $movesStmt->execute($params);
        foreach ($movesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['shift_id'];
            if (!isset($summaries[$id])) {
                $summaries[$id] = self::defaultSummary(0.0);
            }
            $summaries[$id]['cash_in'] = round((float)$row['cash_in'], 2);
            $summaries[$id]['cash_out'] = round((float)$row['cash_out'], 2);
            $summaries[$id]['safe_drop'] = round((float)$row['safe_drop'], 2);
        }

        // Query 3: Append-only Cash Refunds
        $refundsSql = "SELECT shift_id,
                              COUNT(*) AS refund_count,
                              COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN refund_amount ELSE 0 END), 0) AS cash_refunds,
                              COALESCE(SUM(CASE WHEN payment_method = 'card' THEN refund_amount ELSE 0 END), 0) AS card_refunds,
                              COALESCE(SUM(CASE WHEN payment_method = 'ewallet' THEN refund_amount ELSE 0 END), 0) AS ewallet_refunds
                       FROM cash_refunds
                       WHERE shift_id IN ($subquerySql)
                       GROUP BY shift_id";
        $refundsStmt = $this->pdo->prepare($refundsSql);
        $refundsStmt->execute($params);
        foreach ($refundsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['shift_id'];
            if (!isset($summaries[$id])) {
                $summaries[$id] = self::defaultSummary(0.0);
            }
            $cashRef = round((float)$row['cash_refunds'], 2);
            $cardRef = round((float)$row['card_refunds'], 2);
            $ewalletRef = round((float)$row['ewallet_refunds'], 2);
            $summaries[$id]['cash_refunds'] = $cashRef;
            $summaries[$id]['refund_count'] = (int)$row['refund_count'];
            $summaries[$id]['card_refunds'] = $cardRef;
            $summaries[$id]['ewallet_refunds'] = $ewalletRef;
            $summaries[$id]['total_refunds'] = round($cashRef + $cardRef + $ewalletRef, 2);
        }

        // Query 4: Approved legacy cash reversals
        $reversalsSql = "SELECT s.shift_id,
                                COALESCE(SUM(sr.refund_amount), 0) AS reversal_cash
                         FROM sale_reversals sr
                         JOIN sales s ON s.sale_id = sr.sale_id
                         WHERE s.shift_id IN ($subquerySql)
                           AND sr.status = 'approved'
                           AND sr.settlement_method = 'cash'
                         GROUP BY s.shift_id";
        $reversalsStmt = $this->pdo->prepare($reversalsSql);
        $reversalsStmt->execute($params);
        foreach ($reversalsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['shift_id'];
            if (!isset($summaries[$id])) {
                $summaries[$id] = self::defaultSummary(0.0);
            }
            $revCash = round((float)$row['reversal_cash'], 2);
            $summaries[$id]['reversal_cash'] = $revCash;
            $summaries[$id]['approved_reversal_cash'] = $revCash;
        }

        // Compute authoritative expected cash for all shifts
        foreach ($summaries as $id => &$sum) {
            $opening = (float)($shiftOpeningCashes[$id] ?? $sum['opening_cash'] ?? 0.0);
            $sum['opening_cash'] = round($opening, 2);
            $sum['calculated_expected_cash'] = self::calculateExpectedCash(
                $opening,
                (float)$sum['cash_sales'],
                (float)$sum['cash_in'],
                (float)$sum['cash_out'],
                (float)$sum['safe_drop'],
                (float)$sum['cash_refunds'],
                (float)$sum['reversal_cash']
            );
        }
        unset($sum);

        return $summaries;
    }
}
