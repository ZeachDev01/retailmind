<?php
// app/Services/SaleReversalService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../../includes/functions.php';

class SaleReversalService
{
    private PDO $pdo;
    private NotificationService $notificationService;
    private FiscalPeriodGuardService $fiscalPeriodGuard;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->notificationService = new NotificationService($pdo);
        $this->fiscalPeriodGuard = new FiscalPeriodGuardService($pdo);
    }

    public function getSaleWithItems(int $saleId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date,
                    u.full_name AS cashier_name
             FROM sales s
             JOIN users u ON u.user_id = s.cashier_id
             WHERE s.sale_id = ?"
        );
        $stmt->execute([$saleId]);
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
        $params = [];
        $where = '';
        if ($saleId !== null) {
            $where = 'WHERE sr.sale_id = ?';
            $params[] = $saleId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT sr.*, s.total_amount, requester.full_name AS requested_by_name,
                    approver.full_name AS approved_by_name
             FROM sale_reversals sr
             JOIN sales s ON s.sale_id = sr.sale_id
             LEFT JOIN users requester ON requester.user_id = sr.requested_by
             LEFT JOIN users approver ON approver.user_id = sr.approved_by
             $where
             ORDER BY sr.created_at DESC, sr.reversal_id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
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
        $allowedTypes = ['cancel', 'return', 'refund', 'exchange'];
        if (!in_array($type, $allowedTypes, true)) {
            throw new RuntimeException('Invalid reversal type.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A reversal reason is required.');
        }

        $settlementMethod = in_array($settlementMethod, ['none', 'cash', 'card', 'ewallet', 'exchange'], true)
            ? $settlementMethod
            : 'none';

        $sale = $this->getSaleWithItems($saleId);
        if (!$sale) {
            throw new RuntimeException('Sale not found.');
        }
        if (!empty($sale['approved_full_reversal'])) {
            throw new RuntimeException('This sale already has an approved full cancellation.');
        }

        $this->fiscalPeriodGuard->assertOpenForDate($sale['sale_date'], 'sales', 'sale return');
        $this->fiscalPeriodGuard->assertOpenForDate($sale['sale_date'], 'sale_reversals', 'sale return');
        $this->fiscalPeriodGuard->assertOpenNow('sale_reversals', 'sale return');

        $itemPayload = $this->buildItemPayload($sale, $type, $requestedItems);
        if (!$itemPayload) {
            throw new RuntimeException('Select at least one return item with a valid quantity.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO sale_reversals
                    (sale_id, reversal_type, status, reason, settlement_method, refund_amount, exchange_details, requested_by)
                 VALUES (?, ?, 'pending', ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $saleId,
                $type,
                $reason,
                $settlementMethod,
                max(0, $refundAmount),
                trim($exchangeDetails),
                $requestedBy,
            ]);
            $reversalId = (int)$this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                "INSERT INTO sale_reversal_items
                    (reversal_id, sale_item_id, product_id, quantity, unit_price, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($itemPayload as $item) {
                $itemStmt->execute([
                    $reversalId,
                    $item['sale_item_id'],
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['subtotal'],
                ]);
            }

            $this->logActivity(
                $requestedBy,
                'Sale reversal requested',
                'Sales Reversals',
                $reversalId,
                ['sale_id' => $saleId, 'status' => 'completed'],
                [
                    'reversal_id' => $reversalId,
                    'sale_id' => $saleId,
                    'reversal_type' => $type,
                    'status' => 'pending',
                    'reason' => $reason,
                    'settlement_method' => $settlementMethod,
                    'refund_amount' => max(0, $refundAmount),
                    'item_count' => count($itemPayload),
                ]
            );

            $this->pdo->commit();
            return $reversalId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function approveReversal(int $reversalId, int $approvedBy): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM sale_reversals WHERE reversal_id = ? FOR UPDATE"
            );
            $stmt->execute([$reversalId]);
            $reversal = $stmt->fetch();

            if (!$reversal) {
                throw new RuntimeException('Reversal request not found.');
            }
            if ($reversal['status'] !== 'pending') {
                throw new RuntimeException('Only pending reversal requests can be approved.');
            }

            $saleStmt = $this->pdo->prepare("SELECT sale_date FROM sales WHERE sale_id = ?");
            $saleStmt->execute([(int)$reversal['sale_id']]);
            $saleDate = $saleStmt->fetchColumn();
            if (!$saleDate) {
                throw new RuntimeException('Sale not found for this reversal request.');
            }
            $this->fiscalPeriodGuard->assertOpenForDate((string)$saleDate, 'sales', 'sale return');
            $this->fiscalPeriodGuard->assertOpenForDate((string)$saleDate, 'sale_reversals', 'sale return');
            $this->fiscalPeriodGuard->assertOpenNow('sale_reversals', 'sale return');
            $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');

            $itemsStmt = $this->pdo->prepare(
                "SELECT sri.*, si.quantity AS sold_quantity,
                        COALESCE(approved.approved_qty, 0) AS already_reversed
                 FROM sale_reversal_items sri
                 JOIN sale_items si ON si.sale_item_id = sri.sale_item_id
                 LEFT JOIN (
                    SELECT sri2.sale_item_id, SUM(sri2.quantity) AS approved_qty
                    FROM sale_reversal_items sri2
                    JOIN sale_reversals sr2 ON sr2.reversal_id = sri2.reversal_id
                    WHERE sr2.status = 'approved'
                    GROUP BY sri2.sale_item_id
                 ) approved ON approved.sale_item_id = sri.sale_item_id
                 WHERE sri.reversal_id = ?
                 FOR UPDATE"
            );
            $itemsStmt->execute([$reversalId]);
            $items = $itemsStmt->fetchAll();

            foreach ($items as $item) {
                $remaining = (int)$item['sold_quantity'] - (int)$item['already_reversed'];
                if ((int)$item['quantity'] > $remaining) {
                    throw new RuntimeException('One or more return quantities now exceed the remaining sold quantity.');
                }

                $this->restoreSaleItemBatches(
                    (int)$item['sale_item_id'],
                    (int)$item['quantity'],
                    (int)$item['already_reversed']
                );

                $this->pdo->prepare(
                    "UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?"
                )->execute([(int)$item['quantity'], (int)$item['product_id']]);

                $this->pdo->prepare(
                    "UPDATE products SET quantity_sold = GREATEST(quantity_sold - ?, 0) WHERE product_id = ?"
                )->execute([(int)$item['quantity'], (int)$item['product_id']]);

                $this->pdo->prepare(
                    "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by)
                     VALUES (?, ?, 'return', ?)"
                )->execute([(int)$item['product_id'], (int)$item['quantity'], $approvedBy]);
            }

            $this->pdo->prepare(
                "UPDATE sale_reversals
                 SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP
                 WHERE reversal_id = ?"
            )->execute([$approvedBy, $reversalId]);

            $this->logActivity(
                $approvedBy,
                'Sale reversal approved',
                'Sales Reversals',
                $reversalId,
                [
                    'reversal_id' => $reversalId,
                    'sale_id' => (int)$reversal['sale_id'],
                    'status' => 'pending',
                ],
                [
                    'reversal_id' => $reversalId,
                    'sale_id' => (int)$reversal['sale_id'],
                    'reversal_type' => $reversal['reversal_type'],
                    'status' => 'approved',
                    'restored_item_lines' => count($items),
                ]
            );

            $this->pdo->commit();
            $this->notificationService->checkAndNotifyLowStock();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function rejectReversal(int $reversalId, int $rejectedBy, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A rejection reason is required.');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE sale_reversals
             SET status = 'rejected', approved_by = ?, approved_at = CURRENT_TIMESTAMP, rejection_reason = ?
             WHERE reversal_id = ? AND status = 'pending'"
        );
        $stmt->execute([$rejectedBy, $reason, $reversalId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Only pending reversal requests can be rejected.');
        }

        $this->logActivity(
            $rejectedBy,
            'Sale reversal rejected',
            'Sales Reversals',
            $reversalId,
            ['status' => 'pending'],
            ['status' => 'rejected', 'rejection_reason' => $reason]
        );
    }

    private function buildItemPayload(array $sale, string $type, array $requestedItems): array
    {
        $payload = [];
        foreach ($sale['items'] as $item) {
            $remaining = (int)$item['quantity'] - (int)$item['reversed_qty'];
            if ($remaining <= 0) {
                continue;
            }

            $quantity = $type === 'cancel'
                ? $remaining
                : (int)($requestedItems[$item['sale_item_id']] ?? 0);

            if ($quantity <= 0) {
                continue;
            }
            if ($quantity > $remaining) {
                throw new RuntimeException('Return quantity cannot exceed the remaining sold quantity.');
            }

            $unitPrice = (float)$item['unit_price'];
            $payload[] = [
                'sale_item_id' => (int)$item['sale_item_id'],
                'product_id' => (int)$item['product_id'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $unitPrice * $quantity,
            ];
        }

        return $payload;
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

    private function restoreSaleItemBatches(int $saleItemId, int $quantity, int $alreadyReversed): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT batch_id, quantity
             FROM sale_item_batches
             WHERE sale_item_id = ?
             ORDER BY sale_item_batch_id ASC
             FOR UPDATE"
        );
        $stmt->execute([$saleItemId]);
        $allocations = $stmt->fetchAll();

        if (!$allocations) {
            return;
        }

        $remainingToSkip = $alreadyReversed;
        $remainingToRestore = $quantity;

        foreach ($allocations as $allocation) {
            if ($remainingToRestore <= 0) {
                break;
            }

            $allocatedQty = (int)$allocation['quantity'];
            if ($remainingToSkip >= $allocatedQty) {
                $remainingToSkip -= $allocatedQty;
                continue;
            }

            $availableFromAllocation = $allocatedQty - $remainingToSkip;
            $remainingToSkip = 0;
            $restoreQty = min($remainingToRestore, $availableFromAllocation);

            $this->pdo->prepare(
                "UPDATE product_batches
                 SET remaining_quantity = remaining_quantity + ?
                 WHERE batch_id = ?"
            )->execute([$restoreQty, (int)$allocation['batch_id']]);

            $remainingToRestore -= $restoreQty;
        }
    }

    private function logActivity(
        int $userId,
        string $action,
        ?string $module = null,
        ?int $recordId = null,
        $previousValue = null,
        $newValue = null
    ): void
    {
        log_activity($this->pdo, $userId, $action, $module, $recordId, $previousValue, $newValue);
    }
}
