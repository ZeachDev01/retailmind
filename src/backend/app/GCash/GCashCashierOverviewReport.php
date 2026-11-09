<?php

declare(strict_types=1);

namespace App\GCash;

use App\Services\CashierShiftService;
use App\Services\PhilippineTime;
use PDO;

/**
 * Cashier overview read module. Composes GCash Live Custody (session/expected
 * balances), session/shift activity metrics, and the Cashier's unresolved
 * exchanges. The facade only delegates; SQL and shape live here.
 */
final class GCashCashierOverviewReport
{
    public function __construct(
        private PDO $pdo,
        private GCashLiveCustodySession $custody,
        private GCashExchangeReview $review,
    ) {
    }

    public function cashierOverview(int $cashierId): array
    {
        $shift = (new CashierShiftService($this->pdo))->getOpenShift($cashierId);
        $shiftId = $shift ? (int)$shift['shift_id'] : null;

        $session = $this->custody->getCurrentSession();
        $sessionId = null;
        $isControlling = false;
        if ($session && (int)$session['cashier_id'] === $cashierId) {
            $sessionId = (int)$session['session_id'];
            $isControlling = true;
        }

        $expectedCash = 0.0;
        $expectedWallet = 0.0;
        $lastCheckedAt = null;
        $lastCheckedFormatted = null;

        if ($session && $isControlling) {
            $expected = $this->custody->calculateSessionExpected($sessionId);
            $expectedCash = (float)$expected['expected_service_cash'];
            $expectedWallet = (float)$expected['expected_wallet_balance'];
            $lastCheckedAt = $session['opened_at'];
            $lastCheckedFormatted = PhilippineTime::format($session['opened_at']);
        }

        $metrics = $this->activityMetrics($cashierId, $isControlling ? $sessionId : null, $shiftId);

        $needsChecking = [];
        foreach ($this->review->unresolvedExchanges($cashierId) as $nc) {
            $nc += $this->review->failedExchangeBalance($nc);
            $needsChecking[] = $nc;
        }

        $unassigned = $this->pdo->query(
            "SELECT transaction_id, transaction_type, amount, cashier_id, created_at
             FROM gcash_transactions
             WHERE verification_status = 'Needs checking' AND responsible_user_id IS NULL
             ORDER BY transaction_id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'shift' => $shift,
            'session' => $isControlling ? $session : null,
            'is_controlling_cashier' => $isControlling,
            'expected_service_cash' => $expectedCash,
            'expected_wallet_balance' => $expectedWallet,
            'last_checked_at' => $lastCheckedAt,
            'last_checked_formatted' => $lastCheckedFormatted,
            'cash_in_principal' => $metrics['cash_in_principal'],
            'cash_out_principal' => $metrics['cash_out_principal'],
            'gross_fees_cash' => $metrics['gross_fees_cash'],
            'gross_fees_wallet' => $metrics['gross_fees_wallet'],
            'gross_fees_total' => round($metrics['gross_fees_cash'] + $metrics['gross_fees_wallet'], 2),
            'needs_checking' => $needsChecking,
            'unassigned_exchanges' => $unassigned,
            'needs_checking_count' => count($needsChecking),
        ];
    }

    /** @return array{cash_in_principal: float, cash_out_principal: float, gross_fees_cash: float, gross_fees_wallet: float} */
    private function activityMetrics(int $cashierId, ?int $sessionId, ?int $shiftId): array
    {
        $empty = ['cash_in_principal' => 0.0, 'cash_out_principal' => 0.0, 'gross_fees_cash' => 0.0, 'gross_fees_wallet' => 0.0];
        $scopeColumn = $sessionId !== null ? 'session_id' : ($shiftId !== null ? 'shift_id' : null);
        if ($scopeColumn === null) {
            return $empty;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN transaction_type = 'Cash-In' AND verification_status = 'Completed' THEN principal_amount ELSE 0 END), 0) AS cash_in_principal,
                COALESCE(SUM(CASE WHEN transaction_type = 'Cash-Out' AND verification_status = 'Completed' THEN principal_amount ELSE 0 END), 0) AS cash_out_principal,
                COALESCE(SUM(CASE WHEN fee_method = 'cash' AND verification_status = 'Completed' THEN gross_fee ELSE 0 END), 0) AS gross_fees_cash,
                COALESCE(SUM(CASE WHEN fee_method = 'wallet' AND verification_status = 'Completed' THEN gross_fee ELSE 0 END), 0) AS gross_fees_wallet
             FROM gcash_transactions
             WHERE cashier_id = ? AND {$scopeColumn} = ?"
        );
        $stmt->execute([$cashierId, $sessionId ?? $shiftId]);
        $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$metrics) {
            return $empty;
        }

        return [
            'cash_in_principal' => round((float)$metrics['cash_in_principal'], 2),
            'cash_out_principal' => round((float)$metrics['cash_out_principal'], 2),
            'gross_fees_cash' => round((float)$metrics['gross_fees_cash'], 2),
            'gross_fees_wallet' => round((float)$metrics['gross_fees_wallet'], 2),
        ];
    }
}
