<?php
// app/Services/StockIssueService.php
//
// Cashier-to-Inventory-Manager stock-issue workflow (ticket #29).
//
// Cashiers report damaged / missing / expired / other stock discrepancies as
// pending reports. Submission never mutates inventory. Inventory Managers
// review the queue: rejection records actor + timestamp + reason without
// touching inventory, while approval atomically re-checks available stock,
// refuses insufficient stock, deducts the full reported quantity, and records
// a linked stock movement.

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../../includes/functions.php';

class StockIssueService
{
    public const CATEGORIES = ['damaged', 'missing', 'expired', 'other'];

    // Minimum explanation length that counts as "meaningful" for Other reports.
    public const OTHER_MIN_EXPLANATION = 10;

    private PDO $pdo;
    private NotificationService $notificationService;
    private FiscalPeriodGuardService $fiscalPeriodGuard;

    public function __construct(PDO $pdo, ?NotificationService $notificationService = null)
    {
        $this->pdo = $pdo;
        $this->notificationService = $notificationService ?? new NotificationService($pdo);
        $this->fiscalPeriodGuard = new FiscalPeriodGuardService($pdo);
    }

    /**
     * Submit a pending stock-issue report. Never changes inventory.
     *
     * @return int The new inventory_adjustments.adjustment_id.
     */
    public function submitReport(array $data, int $cashierId): int
    {
        $this->assertRole($cashierId, 'cashier');

        $productId = (int)($data['product_id'] ?? 0);
        $category = strtolower(trim((string)($data['category'] ?? $data['adjustment_type'] ?? '')));
        $quantity = (int)($data['quantity'] ?? $data['adjustment_qty'] ?? 0);
        $explanation = trim((string)($data['explanation'] ?? $data['reason'] ?? ''));

        if ($productId <= 0) {
            throw new RuntimeException('Product is required.');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new RuntimeException('Select Damaged, Missing/Lost, Expired, or Other.');
        }
        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be a positive number of units.');
        }
        if ($explanation === '') {
            throw new RuntimeException('An explanation is required for every stock issue report.');
        }
        if ($category === 'other' && mb_strlen($explanation) < self::OTHER_MIN_EXPLANATION) {
            throw new RuntimeException(
                'Other reports require a meaningful explanation of at least ' . self::OTHER_MIN_EXPLANATION . ' characters.'
            );
        }

        $shift = $this->getOpenShift($cashierId);
        if ($shift === null) {
            throw new RuntimeException('An active cashier shift is required to submit a stock issue report.');
        }

        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue report');

        $this->pdo->beginTransaction();
        try {
            [$scopeSql, $scopeParams] = $this->productScope();
            $productStmt = $this->pdo->prepare(
                "SELECT p.product_id FROM products p WHERE p.product_id = ? AND p.status = 'active'{$scopeSql}{$this->forUpdate()}"
            );
            $productStmt->execute(array_merge([$productId], $scopeParams));
            if (!$productStmt->fetch()) {
                throw new RuntimeException('Active product not found.');
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO inventory_adjustments
                    (product_id, adjustment_qty, adjustment_type, reported_by, shift_id, reason, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            );
            // Stored negative: reported units are unavailable, but remain sellable
            // (inventory untouched) until a manager approves the deduction.
            $stmt->execute([$productId, -$quantity, $category, $cashierId, (int)$shift['shift_id'], $explanation]);
            $adjustmentId = (int)$this->pdo->lastInsertId();

            log_activity(
                $this->pdo,
                $cashierId,
                'Stock issue reported',
                'Inventory Adjustments',
                $adjustmentId,
                null,
                [
                    'adjustment_id' => $adjustmentId,
                    'product_id' => $productId,
                    'adjustment_type' => $category,
                    'adjustment_qty' => -$quantity,
                    'shift_id' => (int)$shift['shift_id'],
                    'reason' => $explanation,
                    'status' => 'pending',
                ]
            );

            $this->pdo->commit();
            return $adjustmentId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Approve a pending report: atomically re-check available stock, refuse
     * when insufficient (never negative, never partial), deduct the full
     * reported quantity, and record a linked stock movement.
     */
    public function approveReport(int $adjustmentId, int $approverId, string $reviewNotes = ''): void
    {
        if ($adjustmentId <= 0) {
            throw new RuntimeException('Invalid report selected.');
        }
        $this->assertRole($approverId, 'inventory_manager');

        $this->pdo->beginTransaction();
        try {
            [$scopeSql, $scopeParams] = $this->productScope();
            $reportStmt = $this->pdo->prepare(
                "SELECT ia.* FROM inventory_adjustments ia
                  JOIN products p ON p.product_id = ia.product_id
                  WHERE ia.adjustment_id = ?{$scopeSql}{$this->forUpdate()}"
            );
            $reportStmt->execute(array_merge([$adjustmentId], $scopeParams));
            $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                throw new RuntimeException('Stock issue report not found.');
            }
            if ($report['status'] !== 'pending') {
                throw new RuntimeException('Only pending reports can be approved.');
            }

            $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'inventory adjustment');
            $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');

            $productId = (int)$report['product_id'];
            $quantity = abs((int)$report['adjustment_qty']);
            if ($quantity <= 0) {
                throw new RuntimeException('Report quantity is invalid.');
            }

            $inventoryStmt = $this->pdo->prepare(
                "SELECT quantity_on_hand FROM inventory WHERE product_id = ?{$this->forUpdate()}"
            );
            $inventoryStmt->execute([$productId]);
            $inventory = $inventoryStmt->fetch(PDO::FETCH_ASSOC);
            if (!$inventory) {
                throw new RuntimeException('Inventory record not found for this report.');
            }
            $available = (int)$inventory['quantity_on_hand'];
            if ($available < $quantity) {
                throw new RuntimeException(
                    "Insufficient available stock ({$available} on hand, {$quantity} reported). Approval refused; inventory unchanged."
                );
            }

            // Conditional deduction: concurrent approvals cannot drive stock negative.
            $deductStmt = $this->pdo->prepare(
                'UPDATE inventory SET quantity_on_hand = quantity_on_hand - ?
                  WHERE product_id = ? AND quantity_on_hand >= ?'
            );
            $deductStmt->execute([$quantity, $productId, $quantity]);
            if ($deductStmt->rowCount() === 0) {
                throw new RuntimeException('Stock changed while reviewing. Approval refused; inventory unchanged.');
            }

            $notes = trim($reviewNotes);
            // stock_movements.reason is an enum, so the category/cashier link
            // lives on the report row (adjustment_id FK); keep the human
            // context in the audit record instead.
            $auditDetail = 'adjustment: ' . $report['adjustment_type'] . ' report #' . $adjustmentId;
            if ($notes !== '') {
                $auditDetail .= ' - ' . mb_substr($notes, 0, 200);
            }
            $this->pdo->prepare(
                'INSERT INTO stock_movements (product_id, change_qty, reason, moved_by, adjustment_id)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$productId, -$quantity, 'adjustment', $approverId, $adjustmentId]);

            $reviewStmt = $this->pdo->prepare(
                "UPDATE inventory_adjustments
                  SET status = 'approved', approved_by = ?, approved_at = {$this->nowExpr()}, review_notes = ?
                  WHERE adjustment_id = ? AND status = 'pending'"
            );
            $reviewStmt->execute([$approverId, $notes !== '' ? $notes : null, $adjustmentId]);
            if ($reviewStmt->rowCount() === 0) {
                throw new RuntimeException('This report was already reviewed.');
            }

            log_activity(
                $this->pdo,
                $approverId,
                'Stock issue approved',
                'Inventory Adjustments',
                $adjustmentId,
                [
                    'adjustment_id' => $adjustmentId,
                    'status' => 'pending',
                    'quantity_on_hand' => $available,
                ],
                [
                    'adjustment_id' => $adjustmentId,
                    'status' => 'approved',
                    'quantity_on_hand' => $available - $quantity,
                    'deducted_qty' => $quantity,
                    'detail' => $auditDetail,
                ]
            );

            $this->pdo->commit();
            $this->notificationService->checkAndNotifyLowStock();
            $this->notificationService->checkAndNotifyExpiringStock();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reject a pending report. Requires a reason; never changes inventory.
     */
    public function rejectReport(int $adjustmentId, int $approverId, string $reason): void
    {
        if ($adjustmentId <= 0) {
            throw new RuntimeException('Invalid report selected.');
        }
        $this->assertRole($approverId, 'inventory_manager');
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A rejection reason is required.');
        }

        // SQLite has no multi-table UPDATE; it uses a scoped SELECT + simple UPDATE.
        if ($this->isSqlite()) {
            $this->rejectReportSqlite($adjustmentId, $approverId, $reason);
            return;
        }

        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "UPDATE inventory_adjustments ia
              JOIN products p ON p.product_id = ia.product_id
              SET ia.status = 'rejected', ia.approved_by = ?, ia.approved_at = {$this->nowExpr()}, ia.review_notes = ?
              WHERE ia.adjustment_id = ? AND ia.status = 'pending'{$scopeSql}"
        );
        // MySQL multi-table UPDATE placeholder order: SET values, then WHERE id, then scope params.
        $stmt->execute(array_merge([$approverId, $reason, $adjustmentId], $scopeParams));
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Only pending reports can be rejected.');
        }

        log_activity(
            $this->pdo,
            $approverId,
            'Stock issue rejected',
            'Inventory Adjustments',
            $adjustmentId,
            ['status' => 'pending'],
            ['status' => 'rejected', 'review_notes' => $reason]
        );
    }

    /**
     * Pending reports for the manager review queue, with available stock attached.
     */
    public function getPendingReports(): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT ia.adjustment_id, ia.product_id, ia.adjustment_qty, ia.adjustment_type,
                    ia.reported_by, ia.shift_id, ia.reported_at, ia.reason, ia.status,
                    p.sku, p.product_name,
                    u.full_name AS cashier_name,
                    COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             JOIN users u ON u.user_id = ia.reported_by
             LEFT JOIN inventory i ON i.product_id = ia.product_id
             WHERE ia.status = 'pending'{$scopeSql}
             ORDER BY ia.reported_at ASC"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A cashier's own report history. Viewable without an active shift.
     */
    public function getReportsByCashier(int $cashierId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        [$scopeSql, $scopeParams] = $this->productScope();
        // LIMIT cannot be bound portably, so it is clamped to an integer above.
        $stmt = $this->pdo->prepare(
            "SELECT ia.adjustment_id, ia.product_id, ia.adjustment_qty, ia.adjustment_type,
                    ia.shift_id, ia.reported_at, ia.reason, ia.status, ia.approved_at, ia.review_notes,
                    p.sku, p.product_name,
                    reviewer.full_name AS reviewer_name
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             LEFT JOIN users reviewer ON reviewer.user_id = ia.approved_by
             WHERE ia.reported_by = ?{$scopeSql}
             ORDER BY ia.reported_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute(array_merge([$cashierId], $scopeParams));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recently decided reports for the manager review queue.
     */
    public function getRecentReviews(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        [$scopeSql, $scopeParams] = $this->productScope();
        // LIMIT cannot be bound portably, so it is clamped to an integer above.
        $stmt = $this->pdo->prepare(
            "SELECT ia.adjustment_id, ia.product_id, ia.adjustment_qty, ia.adjustment_type,
                    ia.reported_by, ia.shift_id, ia.reported_at, ia.reason, ia.status,
                    ia.approved_by, ia.approved_at, ia.review_notes,
                    p.sku, p.product_name,
                    reporter.full_name AS cashier_name,
                    reviewer.full_name AS reviewer_name
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             JOIN users reporter ON reporter.user_id = ia.reported_by
             LEFT JOIN users reviewer ON reviewer.user_id = ia.approved_by
             WHERE ia.status IN ('approved', 'rejected'){$scopeSql}
             ORDER BY ia.approved_at DESC, ia.reported_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAvailableStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = ?');
        $stmt->execute([$productId]);
        $quantity = $stmt->fetchColumn();
        return $quantity === false ? 0 : (int)$quantity;
    }

    public function getOpenShift(int $cashierId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT shift_id, cashier_id, status FROM cashier_shifts
              WHERE cashier_id = ? AND status = 'open'
              ORDER BY opened_at DESC LIMIT 1"
        );
        $stmt->execute([$cashierId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        return $shift ?: null;
    }

    private function rejectReportSqlite(int $adjustmentId, int $approverId, string $reason): void
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $check = $this->pdo->prepare(
            "SELECT ia.adjustment_id FROM inventory_adjustments ia
              JOIN products p ON p.product_id = ia.product_id
              WHERE ia.adjustment_id = ? AND ia.status = 'pending'{$scopeSql}"
        );
        $check->execute(array_merge([$adjustmentId], $scopeParams));
        if (!$check->fetch()) {
            throw new RuntimeException('Only pending reports can be rejected.');
        }
        $update = $this->pdo->prepare(
            "UPDATE inventory_adjustments
              SET status = 'rejected', approved_by = ?, approved_at = {$this->nowExpr()}, review_notes = ?
              WHERE adjustment_id = ? AND status = 'pending'"
        );
        $update->execute([$approverId, $reason, $adjustmentId]);
        if ($update->rowCount() === 0) {
            throw new RuntimeException('Only pending reports can be rejected.');
        }

        log_activity(
            $this->pdo,
            $approverId,
            'Stock issue rejected',
            'Inventory Adjustments',
            $adjustmentId,
            ['status' => 'pending'],
            ['status' => 'rejected', 'review_notes' => $reason]
        );
    }

    private function assertRole(int $userId, string $expectedRole): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?'
        );
        $stmt->execute([$userId]);
        $role = (string)$stmt->fetchColumn();
        if ($role !== $expectedRole) {
            $label = $expectedRole === 'cashier' ? 'Cashiers' : 'Inventory Managers';
            throw new RuntimeException("{$label} only: this action requires the {$expectedRole} role.");
        }
    }

    private function productScope(): array
    {
        if (function_exists('store_product_scope')) {
            return store_product_scope('p');
        }

        return (new App\Store\StoreScope($this->pdo))->productScope('p');
    }

    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    private function forUpdate(): string
    {
        // SQLite does not support SELECT ... FOR UPDATE; the surrounding
        // transaction plus conditional writes provide the safety there.
        return $this->isSqlite() ? '' : ' FOR UPDATE';
    }

    private function nowExpr(): string
    {
        return $this->isSqlite() ? 'CURRENT_TIMESTAMP' : 'NOW()';
    }
}
