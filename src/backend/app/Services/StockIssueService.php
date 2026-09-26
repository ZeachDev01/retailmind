<?php
// app/Services/StockIssueService.php
//
// Cashier-to-Inventory-Manager stock-issue workflow (tickets #29 + #30).
//
// Lifecycle: pending -> approved | rejected | returned | cancelled, plus
// returned -> pending (resubmit). Cashiers edit/cancel their own pending
// reports and edit/resubmit their own returned reports. Managers return a
// pending report with a mandatory reason and may recategorize an Other report
// while returning, but never rewrite quantity/explanation. Approved and
// rejected reports are permanently locked. Every action appends a revision;
// managers are notified about new/resubmitted reports and cashiers about
// returned/approved/rejected reports.

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
use App\Store\StoreWriteGate;
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../../includes/functions.php';

class StockIssueService
{
    public const CATEGORIES = ['damaged', 'missing', 'expired', 'other'];

    public const STATUSES = ['pending', 'returned', 'cancelled', 'approved', 'rejected'];

    // Minimum explanation length that counts as "meaningful" for Other reports.
    public const OTHER_MIN_EXPLANATION = 10;

    private PDO $pdo;
    private NotificationService $notificationService;
    private FiscalPeriodGuardService $fiscalPeriodGuard;
    private ?bool $correctionLinkColumn = null;

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

        $this->validateReportFields($productId, $category, $quantity, $explanation);

        $shift = $this->getOpenShift($cashierId);
        if ($shift === null) {
            throw new RuntimeException('An active cashier shift is required to submit a stock issue report.');
        }

        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue report');

        StoreWriteGate::begin($this->pdo);
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

            $this->ensureRevisionsTable();
            $this->insertRevision(
                $adjustmentId,
                $cashierId,
                'submitted',
                null,
                'pending',
                [],
                [
                    'product_id' => $productId,
                    'adjustment_qty' => -$quantity,
                    'adjustment_type' => $category,
                    'reason' => $explanation,
                    'status' => 'pending',
                ]
            );

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
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->notifyManagers($adjustmentId, "New stock issue report #{$adjustmentId}", 'A cashier submitted a stock issue report for review.');
        return $adjustmentId;
    }

    /**
     * Cashier edit of their own pending or returned report. Never changes
     * status or inventory; appends an edited revision.
     */
    public function editReport(int $adjustmentId, int $cashierId, array $data): void
    {
        if ($adjustmentId <= 0) {
            throw new RuntimeException('Invalid report selected.');
        }
        $this->assertRole($cashierId, 'cashier');
        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue correction');

        StoreWriteGate::begin($this->pdo);
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
            if ((int)$report['reported_by'] !== $cashierId) {
                throw new RuntimeException('Cashiers can only edit their own reports.');
            }
            if (!in_array($report['status'], ['pending', 'returned'], true)) {
                throw new RuntimeException('Only pending or returned reports can be edited.');
            }

            $hasQuantity = array_key_exists('quantity', $data) || array_key_exists('adjustment_qty', $data);
            $hasCategory = array_key_exists('category', $data) || array_key_exists('adjustment_type', $data);
            $hasExplanation = array_key_exists('explanation', $data) || array_key_exists('reason', $data);
            if (!$hasQuantity && !$hasCategory && !$hasExplanation) {
                throw new RuntimeException('No changes supplied for the report.');
            }

            $quantity = $hasQuantity ? (int)($data['quantity'] ?? $data['adjustment_qty'] ?? 0) : abs((int)$report['adjustment_qty']);
            $category = $hasCategory
                ? strtolower(trim((string)($data['category'] ?? $data['adjustment_type'] ?? '')))
                : (string)$report['adjustment_type'];
            $explanation = $hasExplanation
                ? trim((string)($data['explanation'] ?? $data['reason'] ?? ''))
                : (string)($report['reason'] ?? '');

            $this->validateReportFields((int)$report['product_id'], $category, $quantity, $explanation);

            $oldValues = [
                'adjustment_qty' => (int)$report['adjustment_qty'],
                'adjustment_type' => (string)$report['adjustment_type'],
                'reason' => (string)($report['reason'] ?? ''),
                'status' => (string)$report['status'],
            ];
            $newValues = [
                'adjustment_qty' => -$quantity,
                'adjustment_type' => $category,
                'reason' => $explanation,
                'status' => (string)$report['status'],
            ];
            if ($oldValues === $newValues) {
                throw new RuntimeException('No changes supplied for the report.');
            }

            $update = $this->pdo->prepare(
                "UPDATE inventory_adjustments
                  SET adjustment_qty = ?, adjustment_type = ?, reason = ?
                  WHERE adjustment_id = ? AND reported_by = ? AND status IN ('pending', 'returned')"
            );
            $update->execute([-$quantity, $category, $explanation, $adjustmentId, $cashierId]);
            if ($update->rowCount() === 0) {
                throw new RuntimeException('This report changed while editing. Refresh and try again.');
            }

            $this->ensureRevisionsTable();
            $this->insertRevision($adjustmentId, $cashierId, 'edited', (string)$report['status'], (string)$report['status'], $oldValues, $newValues);

            log_activity(
                $this->pdo,
                $cashierId,
                'Stock issue edited',
                'Inventory Adjustments',
                $adjustmentId,
                $oldValues,
                $newValues
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cashier cancel of their own pending report: pending -> cancelled.
     */
    public function cancelReport(int $adjustmentId, int $cashierId): void
    {
        if ($adjustmentId <= 0) {
            throw new RuntimeException('Invalid report selected.');
        }
        $this->assertRole($cashierId, 'cashier');
        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue cancellation');

        StoreWriteGate::begin($this->pdo);
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
            if ((int)$report['reported_by'] !== $cashierId) {
                throw new RuntimeException('Cashiers can only cancel their own reports.');
            }
            if ($report['status'] !== 'pending') {
                throw new RuntimeException('Only pending reports can be cancelled.');
            }

            $update = $this->pdo->prepare(
                "UPDATE inventory_adjustments
                  SET status = 'cancelled'
                  WHERE adjustment_id = ? AND reported_by = ? AND status = 'pending'"
            );
            $update->execute([$adjustmentId, $cashierId]);
            if ($update->rowCount() === 0) {
                throw new RuntimeException('This report changed while cancelling. Refresh and try again.');
            }

            $this->ensureRevisionsTable();
            $this->insertRevision(
                $adjustmentId,
                $cashierId,
                'cancelled',
                'pending',
                'cancelled',
                ['status' => 'pending'],
                ['status' => 'cancelled']
            );

            log_activity(
                $this->pdo,
                $cashierId,
                'Stock issue cancelled',
                'Inventory Adjustments',
                $adjustmentId,
                ['status' => 'pending'],
                ['status' => 'cancelled']
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Manager return of a pending report: pending -> returned. Requires a
     * reason. May recategorize an Other report to a concrete category; the
     * reported quantity and explanation are never rewritten here.
     */
    public function returnReport(int $adjustmentId, int $managerId, string $reason, ?string $newCategory = null): void
    {
        if ($adjustmentId <= 0) {
            throw new RuntimeException('Invalid report selected.');
        }
        $this->assertRole($managerId, 'inventory_manager');
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A return reason is required.');
        }
        $recategorize = null;
        if ($newCategory !== null && trim($newCategory) !== '') {
            $recategorize = strtolower(trim($newCategory));
            if (!in_array($recategorize, self::CATEGORIES, true)) {
                throw new RuntimeException('Select Damaged, Missing/Lost, Expired, or Other.');
            }
            if ($recategorize === 'other') {
                throw new RuntimeException('Recategorization must move an Other report to a concrete type.');
            }
        }
        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue return');

        $report = null;
        StoreWriteGate::begin($this->pdo);
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
                throw new RuntimeException('Only pending reports can be returned.');
            }
            if ($recategorize !== null) {
                if ((string)$report['adjustment_type'] !== 'other') {
                    throw new RuntimeException('Only Other reports can be recategorized.');
                }
                if ($recategorize === (string)$report['adjustment_type']) {
                    throw new RuntimeException('No category change supplied.');
                }
            }

            if ($recategorize !== null) {
                $update = $this->pdo->prepare(
                    "UPDATE inventory_adjustments
                      SET status = 'returned', approved_by = ?, approved_at = {$this->nowExpr()}, review_notes = ?, adjustment_type = ?
                      WHERE adjustment_id = ? AND status = 'pending'"
                );
                $update->execute([$managerId, $reason, $recategorize, $adjustmentId]);
            } else {
                $update = $this->pdo->prepare(
                    "UPDATE inventory_adjustments
                      SET status = 'returned', approved_by = ?, approved_at = {$this->nowExpr()}, review_notes = ?
                      WHERE adjustment_id = ? AND status = 'pending'"
                );
                $update->execute([$managerId, $reason, $adjustmentId]);
            }
            if ($update->rowCount() === 0) {
                throw new RuntimeException('This report changed while returning. Refresh and try again.');
            }

            $oldValues = ['status' => 'pending', 'adjustment_type' => (string)$report['adjustment_type']];
            $newValues = ['status' => 'returned', 'adjustment_type' => $recategorize ?? (string)$report['adjustment_type'], 'review_notes' => $reason];
            $this->ensureRevisionsTable();
            $this->insertRevision($adjustmentId, $managerId, 'returned', 'pending', 'returned', $oldValues, $newValues);

            log_activity(
                $this->pdo,
                $managerId,
                'Stock issue returned',
                'Inventory Adjustments',
                $adjustmentId,
                $oldValues,
                $newValues
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $cashierId = (int)($report['reported_by'] ?? 0);
        if ($cashierId > 0) {
            $this->notifyUser(
                $cashierId,
                $adjustmentId,
                "Stock issue report #{$adjustmentId} returned",
                'An Inventory Manager returned your report: ' . $reason
            );
        }
    }

    /**
     * Cashier resubmit of their own returned report: returned -> pending.
     */
    public function resubmitReport(int $adjustmentId, int $cashierId): void
    {
        if ($adjustmentId <= 0) {
            throw new RuntimeException('Invalid report selected.');
        }
        $this->assertRole($cashierId, 'cashier');
        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue resubmission');

        StoreWriteGate::begin($this->pdo);
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
            if ((int)$report['reported_by'] !== $cashierId) {
                throw new RuntimeException('Cashiers can only resubmit their own reports.');
            }
            if ($report['status'] !== 'returned') {
                throw new RuntimeException('Only returned reports can be resubmitted.');
            }

            $update = $this->pdo->prepare(
                "UPDATE inventory_adjustments
                  SET status = 'pending'
                  WHERE adjustment_id = ? AND reported_by = ? AND status = 'returned'"
            );
            $update->execute([$adjustmentId, $cashierId]);
            if ($update->rowCount() === 0) {
                throw new RuntimeException('This report changed while resubmitting. Refresh and try again.');
            }

            $this->ensureRevisionsTable();
            $this->insertRevision(
                $adjustmentId,
                $cashierId,
                'resubmitted',
                'returned',
                'pending',
                ['status' => 'returned'],
                ['status' => 'pending']
            );

            log_activity(
                $this->pdo,
                $cashierId,
                'Stock issue resubmitted',
                'Inventory Adjustments',
                $adjustmentId,
                ['status' => 'returned'],
                ['status' => 'pending']
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->notifyManagers($adjustmentId, "Stock issue report #{$adjustmentId} resubmitted", 'A cashier resubmitted a returned report for review.');
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

        $cashierId = 0;
        StoreWriteGate::begin($this->pdo);
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
            $cashierId = (int)$report['reported_by'];

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

            $this->ensureRevisionsTable();
            $this->insertRevision(
                $adjustmentId,
                $approverId,
                'approved',
                'pending',
                'approved',
                ['status' => 'pending', 'quantity_on_hand' => $available],
                ['status' => 'approved', 'quantity_on_hand' => $available - $quantity, 'deducted_qty' => $quantity, 'review_notes' => $notes]
            );

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

        if ($cashierId > 0) {
            $this->notifyUser(
                $cashierId,
                $adjustmentId,
                "Stock issue report #{$adjustmentId} approved",
                'An Inventory Manager approved your report and deducted the reported quantity.'
            );
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
        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue rejection');

        $cashierId = 0;
        StoreWriteGate::begin($this->pdo);
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
                throw new RuntimeException('Only pending reports can be rejected.');
            }
            $cashierId = (int)$report['reported_by'];

            $update = $this->pdo->prepare(
                "UPDATE inventory_adjustments
                  SET status = 'rejected', approved_by = ?, approved_at = {$this->nowExpr()}, review_notes = ?
                  WHERE adjustment_id = ? AND status = 'pending'"
            );
            $update->execute([$approverId, $reason, $adjustmentId]);
            if ($update->rowCount() === 0) {
                throw new RuntimeException('Only pending reports can be rejected.');
            }

            $this->ensureRevisionsTable();
            $this->insertRevision(
                $adjustmentId,
                $approverId,
                'rejected',
                'pending',
                'rejected',
                ['status' => 'pending'],
                ['status' => 'rejected', 'review_notes' => $reason]
            );

            log_activity(
                $this->pdo,
                $approverId,
                'Stock issue rejected',
                'Inventory Adjustments',
                $adjustmentId,
                ['status' => 'pending'],
                ['status' => 'rejected', 'review_notes' => $reason]
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($cashierId > 0) {
            $this->notifyUser(
                $cashierId,
                $adjustmentId,
                "Stock issue report #{$adjustmentId} rejected",
                'An Inventory Manager rejected your report: ' . $reason
            );
        }
    }

    /**
     * Read-only oversight list (ticket #31). The viewer's role is derived from
     * the database, never trusted from the caller: Administrators and Inventory
     * Managers see every report for operational review; Cashiers see only their
     * own. Filters: status, category, product_id, product (text), cashier_id,
     * approver_id, date_from, date_to (Y-m-d). Never mutates.
     */
    public function getOversightReports(array $filters, int $viewerId): array
    {
        $viewerRole = $this->roleOf($viewerId);
        $this->assertOversightRole($viewerRole);

        $where = ['1 = 1'];
        $params = [];
        if ($viewerRole === 'cashier') {
            $where[] = 'ia.reported_by = ?';
            $params[] = $viewerId;
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $status = strtolower($status);
            if (!in_array($status, self::STATUSES, true)) {
                throw new RuntimeException('Unknown stock issue status filter.');
            }
            $where[] = 'ia.status = ?';
            $params[] = $status;
        }

        $category = trim((string)($filters['category'] ?? ''));
        if ($category !== '') {
            $category = strtolower($category);
            if (!in_array($category, self::CATEGORIES, true)) {
                throw new RuntimeException('Select Damaged, Missing/Lost, Expired, or Other.');
            }
            $where[] = 'ia.adjustment_type = ?';
            $params[] = $category;
        }

        $productId = (int)($filters['product_id'] ?? 0);
        if ($productId > 0) {
            $where[] = 'ia.product_id = ?';
            $params[] = $productId;
        }

        $productQuery = trim((string)($filters['product'] ?? ''));
        if ($productQuery !== '') {
            $where[] = '(p.sku LIKE ? OR p.product_name LIKE ?)';
            $like = '%' . $productQuery . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $cashierId = (int)($filters['cashier_id'] ?? 0);
        if ($cashierId > 0) {
            $where[] = 'ia.reported_by = ?';
            $params[] = $cashierId;
        }

        $approverId = (int)($filters['approver_id'] ?? 0);
        if ($approverId > 0) {
            $where[] = 'ia.approved_by = ?';
            $params[] = $approverId;
        }

        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $this->assertFilterDate($dateFrom);
            $where[] = 'ia.reported_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string)($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $this->assertFilterDate($dateTo);
            $where[] = 'ia.reported_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        [$scopeSql, $scopeParams] = $this->productScope();
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
             WHERE " . implode(' AND ', $where) . $scopeSql . '
             ORDER BY ia.reported_at DESC, ia.adjustment_id DESC
             LIMIT 200'
        );
        $stmt->execute(array_merge($params, $scopeParams));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Full read-only report detail for oversight: the report row, append-only
     * revisions, linked stock movements, and any correction inventory counts.
     * Returns null when the report does not exist; the viewer's role is
     * derived from the database and Cashiers may only open their own reports.
     */
    public function getReportDetail(int $adjustmentId, int $viewerId): ?array
    {
        $viewerRole = $this->roleOf($viewerId);
        $this->assertOversightRole($viewerRole);
        $report = $this->getReport($adjustmentId);
        if ($report === null) {
            return null;
        }
        if ($viewerRole === 'cashier' && (int)$report['reported_by'] !== $viewerId) {
            throw new RuntimeException('Cashiers can only view their own reports.');
        }

        $movementStmt = $this->pdo->prepare(
            'SELECT sm.movement_id, sm.product_id, sm.change_qty, sm.reason, sm.moved_by, sm.adjustment_id, sm.moved_at,
                    mover.full_name AS moved_by_name
             FROM stock_movements sm
             LEFT JOIN users mover ON mover.user_id = sm.moved_by
             WHERE sm.adjustment_id = ?
             ORDER BY sm.movement_id ASC'
        );
        $movementStmt->execute([$adjustmentId]);

        return [
            'report' => $report,
            'revisions' => $this->getRevisions($adjustmentId),
            'stock_movements' => $movementStmt->fetchAll(PDO::FETCH_ASSOC),
            'correction_counts' => $this->getCorrectionCounts($adjustmentId),
        ];
    }

    /**
     * Correction inventory counts linked to a stock-issue report through
     * inventory_counts.related_adjustment_id. The original report is never
     * rewritten; both records stay independently auditable.
     */
    public function getCorrectionCounts(int $adjustmentId): array
    {
        if (!$this->hasCorrectionLinkColumn()) {
            // inventory_counts.related_adjustment_id arrives via migration;
            // detail views degrade gracefully until it is applied.
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT ic.count_id, ic.product_id, ic.system_quantity, ic.physical_quantity,
                    ic.difference_qty, ic.discrepancy_reason, ic.counted_by, ic.approved_by,
                    ic.counted_at, ic.approved_at, ic.status, ic.related_adjustment_id,
                    p.sku, p.product_name,
                    counter.full_name AS counted_by_name,
                    approver.full_name AS approved_by_name
             FROM inventory_counts ic
             JOIN products p ON p.product_id = ic.product_id
             LEFT JOIN users counter ON counter.user_id = ic.counted_by
             LEFT JOIN users approver ON approver.user_id = ic.approved_by
             WHERE ic.related_adjustment_id = ?
             ORDER BY ic.counted_at DESC, ic.count_id DESC'
        );
        $stmt->execute([$adjustmentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Single report with product/cashier/reviewer context. Viewing never locks.
     */
    public function getReport(int $adjustmentId): ?array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT ia.*,
                    p.sku, p.product_name,
                    reporter.full_name AS cashier_name,
                    reviewer.full_name AS reviewer_name
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             JOIN users reporter ON reporter.user_id = ia.reported_by
             LEFT JOIN users reviewer ON reviewer.user_id = ia.approved_by
             WHERE ia.adjustment_id = ?{$scopeSql}
             LIMIT 1"
        );
        $stmt->execute(array_merge([$adjustmentId], $scopeParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Append-only revision trail for a report, oldest first.
     */
    public function getRevisions(int $adjustmentId): array
    {
        $this->ensureRevisionsTable();
        $stmt = $this->pdo->prepare(
            'SELECT r.revision_id, r.adjustment_id, r.actor_id, r.action, r.old_status, r.new_status,
                    r.old_values, r.new_values, r.created_at,
                    actor.full_name AS actor_name
             FROM inventory_adjustment_revisions r
             LEFT JOIN users actor ON actor.user_id = r.actor_id
             WHERE r.adjustment_id = ?
             ORDER BY r.created_at ASC, r.revision_id ASC'
        );
        $stmt->execute([$adjustmentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                    ia.approved_at, ia.review_notes,
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
     * Returned reports awaiting cashier correction (manager visibility).
     */
    public function getReturnedReports(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        [$scopeSql, $scopeParams] = $this->productScope();
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
             WHERE ia.status = 'returned'{$scopeSql}
             ORDER BY ia.approved_at DESC, ia.reported_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Accurate count for the manager pending-review badge.
     */
    public function getPendingCount(): int
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             WHERE ia.status = 'pending'{$scopeSql}"
        );
        $stmt->execute($scopeParams);
        return (int)$stmt->fetchColumn();
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
             WHERE ia.status IN ('approved', 'rejected', 'returned', 'cancelled'){$scopeSql}
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

    private function validateReportFields(int $productId, string $category, int $quantity, string $explanation): void
    {
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
    }

    private function assertOversightRole(string $viewerRole): void
    {
        // Deliberate scope for this Store-operations oversight surface (ADR-0001):
        // Administrators own Store operational exceptions, Inventory Managers keep
        // operational review, and Cashiers see only their own reports. The Super
        // Administrator inspects Store operations through audit records and the
        // platform workspace, not this page.
        if (!in_array($viewerRole, ['admin', 'inventory_manager', 'cashier'], true)) {
            throw new RuntimeException(
                'Stock issue oversight is limited to Administrators, Inventory Managers, and Cashiers viewing their own reports.'
            );
        }
    }

    private function assertFilterDate(string $value): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new RuntimeException('Date filters must use the YYYY-MM-DD format.');
        }
    }

    private function roleOf(int $userId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?'
        );
        $stmt->execute([$userId]);
        return (string)$stmt->fetchColumn();
    }

    private function hasCorrectionLinkColumn(): bool
    {
        if ($this->correctionLinkColumn !== null) {
            return $this->correctionLinkColumn;
        }

        if ($this->isSqlite()) {
            $columns = [];
            try {
                foreach ($this->pdo->query('PRAGMA table_info(inventory_counts)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $columns[] = strtolower((string)$column['name']);
                }
            } catch (Throwable $e) {
                $columns = [];
            }
            $this->correctionLinkColumn = in_array('related_adjustment_id', $columns, true);
            return $this->correctionLinkColumn;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND table_name = 'inventory_counts'
                    AND column_name = 'related_adjustment_id'"
            );
            $stmt->execute();
            $this->correctionLinkColumn = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $this->correctionLinkColumn = false;
        }
        return $this->correctionLinkColumn;
    }

    private function assertRole(int $userId, string $expectedRole): void
    {
        $role = $this->roleOf($userId);
        if ($role !== $expectedRole) {
            $label = $expectedRole === 'cashier' ? 'Cashiers' : 'Inventory Managers';
            throw new RuntimeException("{$label} only: this action requires the {$expectedRole} role.");
        }
    }

    private function ensureRevisionsTable(): void
    {
        if ($this->isSqlite()) {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS inventory_adjustment_revisions (
                    revision_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    adjustment_id INTEGER NOT NULL,
                    actor_id INTEGER NULL,
                    action TEXT NOT NULL,
                    old_status TEXT NULL,
                    new_status TEXT NULL,
                    old_values TEXT NULL,
                    new_values TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_adjustment_revisions_adjustment ON inventory_adjustment_revisions (adjustment_id)'
            );
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `inventory_adjustment_revisions` (
                `revision_id` INT NOT NULL AUTO_INCREMENT,
                `adjustment_id` INT NOT NULL,
                `actor_id` INT NULL,
                `action` VARCHAR(50) NOT NULL,
                `old_status` VARCHAR(20) NULL,
                `new_status` VARCHAR(20) NULL,
                `old_values` TEXT NULL,
                `new_values` TEXT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`revision_id`),
                KEY `idx_adjustment_revisions_adjustment` (`adjustment_id`),
                CONSTRAINT `fk_adjustment_revisions_adjustment` FOREIGN KEY (`adjustment_id`)
                    REFERENCES `inventory_adjustments` (`adjustment_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function insertRevision(
        int $adjustmentId,
        ?int $actorId,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        array $oldValues,
        array $newValues
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_adjustment_revisions
                (adjustment_id, actor_id, action, old_status, new_status, old_values, new_values)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $adjustmentId,
            $actorId,
            $action,
            $oldStatus,
            $newStatus,
            $oldValues === [] ? null : json_encode($oldValues),
            $newValues === [] ? null : json_encode($newValues),
        ]);
    }

    private function notifyManagers(int $adjustmentId, string $title, string $message): void
    {
        try {
            foreach ($this->managerUserIds() as $managerId) {
                $this->insertNotification($managerId, $adjustmentId, $title, $message);
            }
        } catch (Throwable $e) {
            error_log('Stock issue manager notification skipped: ' . $e->getMessage());
        }
    }

    private function notifyUser(int $userId, int $adjustmentId, string $title, string $message): void
    {
        try {
            $this->insertNotification($userId, $adjustmentId, $title, $message);
        } catch (Throwable $e) {
            error_log('Stock issue user notification skipped: ' . $e->getMessage());
        }
    }

    private function insertNotification(int $userId, int $adjustmentId, string $title, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message, reference_id, reference_type)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        // `adjustment` is an existing notifications.type enum value; the
        // stock-issue link lives in reference_type so no enum change is needed.
        $stmt->execute([$userId, 'adjustment', $title, $message, $adjustmentId, 'stock_issue']);
    }

    /**
     * @return int[]
     */
    private function managerUserIds(): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT u.user_id FROM users u
                  JOIN roles r ON r.role_id = u.role_id
                  WHERE r.role_name = 'inventory_manager' AND u.status = 'active'"
            );
            $stmt->execute();
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            $stmt = $this->pdo->prepare(
                "SELECT u.user_id FROM users u
                  JOIN roles r ON r.role_id = u.role_id
                  WHERE r.role_name = 'inventory_manager'"
            );
            $stmt->execute();
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
