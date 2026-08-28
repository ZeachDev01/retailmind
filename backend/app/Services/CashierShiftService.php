<?php

class CashierShiftService
{
    public function __construct(private PDO $pdo) {}

    public function getOpenShift(int $cashierId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cs.*, u.full_name
             FROM cashier_shifts cs JOIN users u ON u.user_id = cs.cashier_id
             WHERE cs.cashier_id = ? AND cs.status = 'open'
             ORDER BY cs.opened_at DESC LIMIT 1"
        );
        $stmt->execute([$cashierId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function openShift(int $cashierId, float $openingCash): int
    {
        if ($openingCash < 0) {
            throw new RuntimeException('Opening cash cannot be negative.');
        }
        if ($this->getOpenShift($cashierId)) {
            throw new RuntimeException('This cashier already has an open shift.');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO cashier_shifts (cashier_id, opening_cash) VALUES (?, ?)"
        );
        $stmt->execute([$cashierId, $openingCash]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addDrawerMovement(int $cashierId, string $type, float $amount, string $reason, ?int $recordedBy = null): int
    {
        if (!in_array($type, ['pay_in', 'pay_out'], true)) {
            throw new RuntimeException('Invalid drawer movement type.');
        }
        if ($amount <= 0 || trim($reason) === '') {
            throw new RuntimeException('Amount and reason are required.');
        }
        $shift = $this->getOpenShift($cashierId);
        if (!$shift) {
            throw new RuntimeException('No open shift was found.');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO cash_drawer_movements (shift_id, movement_type, amount, reason, recorded_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([(int)$shift['shift_id'], $type, $amount, trim($reason), $recordedBy ?? $cashierId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function calculateShift(int $shiftId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cs.*, u.full_name
             FROM cashier_shifts cs JOIN users u ON u.user_id = cs.cashier_id
             WHERE cs.shift_id = ?"
        );
        $stmt->execute([$shiftId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$shift) {
            throw new RuntimeException('Shift not found.');
        }

        $salesStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS sale_count,
                    COALESCE(SUM(total_amount),0) AS total_sales,
                    COALESCE(SUM(CASE WHEN payment_method='cash' THEN total_amount ELSE 0 END),0) AS cash_sales,
                    COALESCE(SUM(CASE WHEN payment_method='card' THEN total_amount ELSE 0 END),0) AS card_sales,
                    COALESCE(SUM(CASE WHEN payment_method='ewallet' THEN total_amount ELSE 0 END),0) AS ewallet_sales,
                    COALESCE(SUM(discount_amount),0) AS discounts
             FROM sales WHERE shift_id = ?"
        );
        $salesStmt->execute([$shiftId]);
        $sales = $salesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $moveStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN movement_type='pay_in' THEN amount ELSE 0 END),0) AS pay_in,
                    COALESCE(SUM(CASE WHEN movement_type='pay_out' THEN amount ELSE 0 END),0) AS pay_out
             FROM cash_drawer_movements WHERE shift_id = ?"
        );
        $moveStmt->execute([$shiftId]);
        $movements = $moveStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $refundStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(sr.refund_amount),0)
             FROM sale_reversals sr JOIN sales s ON s.sale_id = sr.sale_id
             WHERE s.shift_id = ? AND sr.status='approved' AND sr.settlement_method='cash'"
        );
        $refundStmt->execute([$shiftId]);
        $cashRefunds = (float)$refundStmt->fetchColumn();

        $expected = (float)$shift['opening_cash'] + (float)($sales['cash_sales'] ?? 0)
            + (float)($movements['pay_in'] ?? 0) - (float)($movements['pay_out'] ?? 0) - $cashRefunds;

        return array_merge($shift, $sales, $movements, [
            'cash_refunds' => $cashRefunds,
            'calculated_expected_cash' => round($expected, 2),
        ]);
    }

    public function closeShift(int $cashierId, float $actualCash, string $notes, ?int $reviewedBy = null): array
    {
        $shift = $this->getOpenShift($cashierId);
        if (!$shift) {
            throw new RuntimeException('No open shift was found.');
        }
        if ($actualCash < 0) {
            throw new RuntimeException('Actual cash cannot be negative.');
        }
        $summary = $this->calculateShift((int)$shift['shift_id']);
        $expected = (float)$summary['calculated_expected_cash'];
        $variance = round($actualCash - $expected, 2);
        $stmt = $this->pdo->prepare(
            "UPDATE cashier_shifts
             SET status='closed', closed_at=NOW(), expected_cash=?, actual_cash=?, cash_variance=?, closing_notes=?,
                 reviewed_by=?, reviewed_at=CASE WHEN ? IS NULL THEN NULL ELSE NOW() END
             WHERE shift_id=? AND status='open'"
        );
        $stmt->execute([$expected, $actualCash, $variance, trim($notes) ?: null, $reviewedBy, $reviewedBy, (int)$shift['shift_id']]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('The shift was already closed.');
        }
        return $this->calculateShift((int)$shift['shift_id']);
    }

    public function recentShifts(?int $cashierId = null, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT cs.*, u.full_name FROM cashier_shifts cs JOIN users u ON u.user_id=cs.cashier_id";
        $params = [];
        if ($cashierId !== null) {
            $sql .= ' WHERE cs.cashier_id = ?';
            $params[] = $cashierId;
        }
        $sql .= " ORDER BY cs.opened_at DESC LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function drawerMovements(int $shiftId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cdm.*, u.full_name FROM cash_drawer_movements cdm
             JOIN users u ON u.user_id=cdm.recorded_by WHERE cdm.shift_id=? ORDER BY cdm.created_at DESC"
        );
        $stmt->execute([$shiftId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
