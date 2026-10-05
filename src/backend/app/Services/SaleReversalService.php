<?php
// app/Services/SaleReversalService.php

require_once __DIR__ . '/LegacyReversalTransitionService.php';
require_once __DIR__ . '/../../includes/functions.php';

class SaleReversalService
{
    private PDO $pdo;
    private App\Store\StoreScope $storeScope;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->storeScope = new App\Store\StoreScope($pdo);
    }

    public function getSaleWithItems(int $saleId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date,
                    u.full_name AS cashier_name
             FROM sales s
             JOIN users u ON u.user_id = s.cashier_id
             WHERE s.sale_id = ? AND (
                 u.branch_id = ? OR EXISTS (
                     SELECT 1 FROM sale_items scope_si
                     JOIN products scope_p ON scope_p.product_id = scope_si.product_id
                     WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = ?
                 )
             )"
        );
        $storeId = $this->storeScope->id();
        $stmt->execute([$saleId, $storeId, $storeId]);
        $sale = $stmt->fetch();

        if (!$sale) {
            return null;
        }

        $itemsStmt = $this->pdo->prepare(
            "SELECT si.sale_item_id, si.product_id, si.quantity, si.unit_price, si.subtotal,
                    p.sku, p.product_name,
                    COALESCE(reversed.approved_qty, 0) AS reversed_qty
             FROM sale_items si
             JOIN products p ON p.product_id = si.product_id
             LEFT JOIN (
                SELECT sri.sale_item_id, SUM(sri.quantity) AS approved_qty
                FROM sale_reversal_items sri
                JOIN sale_reversals sr ON sr.reversal_id = sri.reversal_id
                WHERE sr.status = 'approved'
                GROUP BY sri.sale_item_id
             ) reversed ON reversed.sale_item_id = si.sale_item_id
             WHERE si.sale_id = ?
             ORDER BY si.sale_item_id"
        );
        $itemsStmt->execute([$saleId]);
        $sale['items'] = $itemsStmt->fetchAll();
        $sale['approved_full_reversal'] = $this->hasApprovedFullReversal($saleId);

        return $sale;
    }

    public function getReversals(?int $saleId = null): array
    {
        $storeId = $this->storeScope->id();
        $params = [$storeId, $storeId];
        $where = 'WHERE (s.sale_id IS NULL OR cashier.user_id IS NULL OR cashier.branch_id = ? OR EXISTS (
            SELECT 1 FROM sale_items scope_si
            JOIN products scope_p ON scope_p.product_id = scope_si.product_id
            WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = ?
        ))';
        if ($saleId !== null) {
            $where .= ' AND sr.sale_id = ?';
            $params[] = $saleId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT sr.*, s.total_amount, requester.full_name AS requested_by_name,
                    approver.full_name AS approved_by_name
             FROM sale_reversals sr
             LEFT JOIN sales s ON s.sale_id = sr.sale_id
             LEFT JOIN users cashier ON cashier.user_id = s.cashier_id
             LEFT JOIN users requester ON requester.user_id = sr.requested_by
             LEFT JOIN users approver ON approver.user_id = sr.approved_by
             $where
             ORDER BY sr.created_at DESC, sr.reversal_id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Read-only preview; decisions recheck every condition under locks. */
    public function reviewBalances(int $reversalId): array
    {
        $stmt = $this->pdo->prepare('SELECT r.*,s.total_amount,s.shift_id,s.payment_method AS original_method,
            cs.status AS shift_status,cs.locked_at FROM sale_reversals r
            LEFT JOIN sales s ON s.sale_id=r.sale_id LEFT JOIN cashier_shifts cs ON cs.shift_id=s.shift_id WHERE r.reversal_id=?');
        $stmt->execute([$reversalId]);
        $record = $stmt->fetch();
        if (!$record || $record['total_amount'] === null) return ['limitation'=>'Broken sale link: leave pending for investigation.'];
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM sale_reversals WHERE sale_id=? AND status='approved'");
        $stmt->execute([$record['sale_id']]);
        $record['paid_before'] = round((float)$record['total_amount']-(float)$stmt->fetchColumn(),2);
        $record['paid_after'] = $record['paid_before']-(float)$record['refund_amount'];
        $stmt = $this->pdo->prepare("SELECT i.*,si.quantity AS sold_quantity,p.product_name,inv.quantity_on_hand,
            COALESCE((SELECT SUM(a.quantity) FROM sale_reversal_items a JOIN sale_reversals r ON r.reversal_id=a.reversal_id
                WHERE a.sale_item_id=i.sale_item_id AND r.status='approved'),0) AS approved_quantity
            FROM sale_reversal_items i LEFT JOIN sale_items si ON si.sale_item_id=i.sale_item_id
            LEFT JOIN products p ON p.product_id=i.product_id LEFT JOIN inventory inv ON inv.product_id=i.product_id WHERE i.reversal_id=?");
        $stmt->execute([$reversalId]);
        $record['items'] = $stmt->fetchAll();
        if ($record['shift_id']) {
            $record['cash_before'] = (new App\Services\CashierShiftService($this->pdo))->calculateShift((int)$record['shift_id'])['calculated_expected_cash'];
            $record['cash_after'] = $record['cash_before']-($record['settlement_method']==='cash' ? (float)$record['refund_amount'] : 0);
        }
        return $record;
    }

    public function requestReversal(
        int $saleId,
        string $type,
        string $reason,
        array $requestedItems,
        int $requestedBy,
        string $settlementMethod,
        float $refundAmount = 0.0,
        string $exchangeDetails = ''
    ): int {
        throw new RuntimeException('New Legacy Reversal requests are disabled. Use full or partial Cash Refunds; an exchange requires a refund plus a new sale.');
    }

    public function approveReversal(int $reversalId, int $approvedBy, string $reason = '', array $evidence = []): void
    {
        (new App\Services\LegacyReversalTransitionService($this->pdo))->decide($reversalId, $approvedBy, 'approved', $reason, $evidence);
    }

    public function rejectReversal(int $reversalId, int $rejectedBy, string $reason, array $evidence = []): void
    {
        (new App\Services\LegacyReversalTransitionService($this->pdo))->decide($reversalId, $rejectedBy, 'rejected', $reason, $evidence);
    }

    private function hasApprovedFullReversal(int $saleId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM sale_reversals
             WHERE sale_id = ? AND reversal_type = 'cancel' AND status = 'approved'"
        );
        $stmt->execute([$saleId]);
        return (int)$stmt->fetchColumn() > 0;
    }

}
