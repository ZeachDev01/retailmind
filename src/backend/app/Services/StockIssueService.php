<?php
require_once __DIR__ . "/InventorySubmission.php";
// app/Services/StockIssueService.php
//
// Cashier-to-Inventory-Manager stock-issue workflow (tickets #29 + #30).
//
// Cashier reports retain original details; Managers confirm the final quantity.
// Managers may also record confirmed issues directly. Legacy messages are read-only.

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/CashierShiftService.php';
require_once __DIR__ . '/../Dashboard/StoreOperationsDashboardWorkspace.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
use App\Dashboard\StoreOperationsDashboardWorkspace;
use App\Store\StoreWriteGate;
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/BatchInventoryLedger.php';
use App\Services\BatchInventoryLedger;
require_once __DIR__ . '/SalesWorkflowService.php';
require_once __DIR__ . '/../../includes/functions.php';

class StockIssueService
{
    public static function formatIssueQuantity(array $row): string
    {
        $form = $row['form'] ?? 'piece';
        $repQty = (int)($row['reported_qty'] ?? 0);
        $baseQty = abs((int)($row['adjustment_qty'] ?? 0));
        if ($repQty <= 0) {
            $repQty = $baseQty;
        }
        if ($form === 'pack') {
            if (($row['status'] ?? '') === 'approved' && (int)($row['pack_size'] ?? 0) > 1) {
                $repQty = intdiv($baseQty, (int)$row['pack_size']);
            }
            return '-' . $repQty . ' pack' . ($repQty === 1 ? '' : 's') . ' (' . $baseQty . ' pcs)';
        }
        return '-' . $baseQty . ' piece' . ($baseQty === 1 ? '' : 's');
    }
    public const CATEGORIES = ['damaged', 'missing', 'expired', 'other'];

    public const STATUSES = ['pending', 'returned', 'cancelled', 'approved', 'rejected'];

    // Minimum explanation length that counts as "meaningful" for Other reports.
    public const OTHER_MIN_EXPLANATION = 10;

    private PDO $pdo;
    private NotificationService $notificationService;
    private FiscalPeriodGuardService $fiscalPeriodGuard;
    private ?bool $correctionLinkColumn = null;
    private BatchInventoryLedger $ledger;

    public function __construct(PDO $pdo, ?NotificationService $notificationService = null, private ?string $activeWorkspace = null, ?BatchInventoryLedger $ledger = null)
    {
        $this->pdo = $pdo;
        $this->notificationService = $notificationService ?? new NotificationService($pdo);
        $this->fiscalPeriodGuard = new FiscalPeriodGuardService($pdo);
        $this->ledger = $ledger ?? new BatchInventoryLedger($pdo, $this->fiscalPeriodGuard);
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
        $quantity = $this->parseQuantity($data['quantity'] ?? $data['adjustment_qty'] ?? null);
        $explanation = trim((string)($data['explanation'] ?? $data['reason'] ?? ''));
        $form = $this->normalizeForm((string)($data['form'] ?? $data['selling_form'] ?? 'piece'));

        $this->validateReportFields($productId, $category, $quantity, $explanation);

        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue report');

        $this->ensureRevisionsTable();
        StoreWriteGate::begin($this->pdo);
        try {
            $shift = (new \App\Services\CashierShiftService($this->pdo))->lockOpenShift($cashierId, true);
            [$packSize, $baseUnitQty] = $this->resolveProductPackAndBaseUnits($productId, $quantity, $form, true);

            $hasFormCols = $this->hasColumn('inventory_adjustments', 'form');
            if ($hasFormCols) {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO inventory_adjustments
                        (product_id, adjustment_qty, adjustment_type, reported_by, shift_id, reason, status, form, reported_qty, pack_size)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)"
                );
                $stmt->execute([$productId, -$baseUnitQty, $category, $cashierId, (int)$shift['shift_id'], $explanation, $form, $quantity, $packSize]);
            } else {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO inventory_adjustments
                        (product_id, adjustment_qty, adjustment_type, reported_by, shift_id, reason, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                );
                $stmt->execute([$productId, -$baseUnitQty, $category, $cashierId, (int)$shift['shift_id'], $explanation]);
            }
            $adjustmentId = (int)$this->pdo->lastInsertId();

            $this->insertRevision(
                $adjustmentId,
                $cashierId,
                'submitted',
                null,
                'pending',
                [],
                [
                    'product_id' => $productId,
                    'adjustment_qty' => -$baseUnitQty,
                    'adjustment_type' => $category,
                    'reason' => $explanation,
                    'status' => 'pending',
                    'form' => $form,
                    'reported_qty' => $quantity,
                    'pack_size' => $packSize,
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
                    'adjustment_qty' => -$baseUnitQty,
                    'shift_id' => (int)$shift['shift_id'],
                    'reason' => $explanation,
                    'status' => 'pending',
                    'form' => $form,
                    'reported_qty' => $quantity,
                    'pack_size' => $packSize,
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

    /** Record a confirmed Manager issue and its deduction in one transaction. */
    public function recordConfirmedIssue(array $data, int $managerId): int
    {
        $this->assertRole($managerId, 'inventory_manager');
        $productId = (int)($data['product_id'] ?? 0);
        $category = strtolower(trim((string)($data['category'] ?? '')));
        $quantity = $this->parseQuantity($data['quantity'] ?? null);
        $explanation = trim((string)($data['explanation'] ?? ''));
        $form = $this->normalizeForm((string)($data['form'] ?? 'piece'));
        $this->validateReportFields($productId, $category, $quantity, $explanation);
        if (empty($data['operation_token'])) throw new RuntimeException('Reload the Stock Issue form before recording.');
        $submission = new InventorySubmission($this->pdo, $managerId, 'recordConfirmedIssue', [
            'operation_token' => $data['operation_token'] ?? '',
            'product_id' => $productId, 'category' => $category, 'quantity' => $quantity,
            'form' => $form, 'explanation' => $explanation,
        ]);
        if (($completed = $submission->completed()) !== null) return (int)$completed;
        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue recording');
        $this->ensureRevisionsTable();
        StoreWriteGate::begin($this->pdo);
        try {
            if (($completed = $submission->completed()) !== null) {
                $this->pdo->commit();
                return (int)$completed;
            }
            [$packSize, $baseUnitQty] = $this->resolveProductPackAndBaseUnits($productId, $quantity, $form, true);
            $hasForm = $this->hasColumn('inventory_adjustments', 'form');
            $stmt = $this->pdo->prepare('INSERT INTO inventory_adjustments
                (product_id, adjustment_qty, adjustment_type, reported_by, reason, status' . ($hasForm ? ', form, reported_qty, pack_size' : '') . ")
                VALUES (?, ?, ?, ?, ?, 'pending'" . ($hasForm ? ', ?, ?, ?' : '') . ')');
            $values = [$productId, -$baseUnitQty, $category, $managerId, $explanation];
            if ($hasForm) array_push($values, $form, $quantity, $packSize);
            $stmt->execute($values);
            $adjustmentId = (int)$this->pdo->lastInsertId();
            $this->insertRevision($adjustmentId, $managerId, 'recorded', null, 'pending', [], [
                'product_id' => $productId, 'adjustment_type' => $category, 'reason' => $explanation,
                'form' => $form, 'reported_qty' => $quantity, 'pack_size' => $packSize,
            ]);
            $report = $this->getReport($adjustmentId);
            $this->applyApproval($report, $managerId, $explanation, null, $quantity);
            $submission->remember($adjustmentId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        StoreOperationsDashboardWorkspace::invalidateCache();
        $this->notificationService->checkAndNotifyLowStock();
        $this->notificationService->checkAndNotifyExpiringStock();
        return $adjustmentId;
    }

    /** Original report details are immutable after submission. */
    public function editReport(int $adjustmentId, int $cashierId, array $data): void
    {
        $this->assertRole($cashierId, 'cashier');
        throw new RuntimeException('Submitted report details are locked. The Inventory Manager can confirm a different quantity.');
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

        $this->ensureRevisionsTable();
        StoreWriteGate::begin($this->pdo);
        try {
            $shift = (new \App\Services\CashierShiftService($this->pdo))->lockOpenShift($cashierId, true);
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

    /** Compatibility seam for callers requesting clarification. */
    public function returnReport(int $adjustmentId, int $managerId, string $reason, ?string $newCategory = null, string $operationToken = ''): void
    {
        $this->assertRole($managerId, 'inventory_manager');
        if ($newCategory !== null && trim($newCategory) !== '') {
            throw new RuntimeException('Submitted report details are locked. Explain any discrepancy in the conversation.');
        }
        $this->sendMessage($adjustmentId, $managerId, 'inventory_manager', $reason, $operationToken);
    }

    public function resubmitReport(int $adjustmentId, int $cashierId): void
    {
        $this->assertRole($cashierId, 'cashier');
        throw new RuntimeException('Resubmission is no longer required. The Inventory Manager can decide this report directly.');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Awaiting manager review',
            'returned' => 'Awaiting manager review',
            default => ucfirst($status),
        };
    }

    /** Compatibility seam: historical conversations no longer accept messages. */
    public function sendMessage(int $adjustmentId, int $senderId, string $workspace, string $body, string $operationToken = ''): void
    {
        $this->assertRole($senderId, $workspace);
        $this->assertConversationAccess($adjustmentId, $senderId, $workspace);
        throw new RuntimeException('Stock Issue messages are read-only. The Inventory Manager can decide the report directly.');
    }

    public function getConversation(int $adjustmentId, int $viewerId, string $workspace): array
    {
        $report = $this->assertConversationAccess($adjustmentId, $viewerId, $workspace);
        $messages = [];
        foreach ($this->getRevisions($adjustmentId) as $revision) {
            $values = json_decode($revision['new_values'] ?? '{}', true) ?: [];
            $body = $revision['action'] === 'message_sent' ? ($values['body'] ?? '')
                : (in_array($revision['action'], ['returned', 'clarification_imported'], true) ? ($values['review_notes'] ?? '') : '');
            if ($body === '') continue;
            $messages[] = [
                'actor_id' => $revision['actor_id'], 'actor_name' => $revision['actor_name'] ?: 'Former staff member',
                'workspace' => $revision['action'] !== 'message_sent' ? 'inventory_manager' : ($values['workspace'] ?? ''),
                'body' => $body, 'created_at' => array_key_exists('original_time', $values) ? $values['original_time'] : $revision['created_at'],
            ];
        }
        if (!$messages && in_array($report['status'], ['pending', 'returned'], true) && !empty($report['review_notes'])) {
            $messages[] = [
                'actor_id' => $report['approved_by'], 'actor_name' => $report['reviewer_name'] ?: 'Inventory Manager',
                'workspace' => 'inventory_manager', 'body' => $report['review_notes'], 'created_at' => $report['approved_at'],
            ];
        }
        return $messages;
    }

    private function preserveLegacyClarification(array $report): void
    {
        if (empty($report['review_notes']) || !in_array($report['status'], ['pending', 'returned'], true)) return;
        foreach ($this->getRevisions((int)$report['adjustment_id']) as $revision) {
            if (!in_array($revision['action'], ['returned', 'clarification_imported'], true)) continue;
            $values = json_decode($revision['new_values'] ?? '{}', true) ?: [];
            if (($values['review_notes'] ?? '') === $report['review_notes']) return;
        }
        $stmt = $this->pdo->prepare("INSERT INTO inventory_adjustment_revisions
            (adjustment_id, actor_id, action, new_values, created_at) VALUES (?, ?, 'clarification_imported', ?, ?)");
        $stmt->execute([(int)$report['adjustment_id'], $report['approved_by'], json_encode([
            'review_notes' => $report['review_notes'], 'original_time' => $report['approved_at'],
        ], JSON_THROW_ON_ERROR), $report['approved_at'] ?: $report['reported_at']]);
    }

    public function unreadConversations(int $viewerId, string $workspace): array
    {
        $this->assertRole($viewerId, $workspace);
        $stmt = $this->pdo->prepare("SELECT reference_id, COUNT(*) AS unread_count, MAX(notification_id) AS read_upto
            FROM notifications WHERE user_id = ? AND workspace = ? AND reference_type = 'stock_issue'
            AND is_read = 0 AND inapp_enabled = 1 GROUP BY reference_id");
        $stmt->execute([$viewerId, $workspace]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'reference_id');
    }

    public function markConversationRead(int $adjustmentId, int $viewerId, string $workspace, int $readUpto): void
    {
        $this->assertConversationAccess($adjustmentId, $viewerId, $workspace);
        $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND workspace = ?
            AND reference_type = 'stock_issue' AND reference_id = ? AND notification_id <= ?")
            ->execute([$viewerId, $workspace, $adjustmentId, $readUpto]);
    }

    private function assertConversationAccess(int $adjustmentId, int $viewerId, string $workspace): array
    {
        if (!in_array($workspace, ['cashier', 'inventory_manager'], true)) {
            throw new RuntimeException('Only the reporting Cashier and Inventory Managers may use this conversation.');
        }
        $this->assertRole($viewerId, $workspace);
        if (!$this->notificationService->inWorkspace($viewerId, $workspace)) {
            throw new RuntimeException('Your account cannot access this conversation.');
        }
        $report = $this->getReport($adjustmentId);
        if (!$report || ($workspace === 'cashier' && (int)$report['reported_by'] !== $viewerId)) {
            throw new RuntimeException('Stock issue report not found.');
        }
        return $report;
    }

    /**
     * Approve a pending report: atomically re-check available stock, refuse
     * when insufficient (never negative, never partial), deduct the full
     * verified quantity, and record a linked stock movement.
     */
    public function approveReport(int $adjustmentId, int $approverId, string $reviewNotes = '', ?array $batchAllocations = null, string $operationToken = '', mixed $verifiedQuantity = null): void
    {
        if ($adjustmentId <= 0) {
            throw new RuntimeException('Invalid report selected.');
        }
        $this->assertRole($approverId, 'inventory_manager');
        $verifiedQty = $verifiedQuantity === null ? null : $this->parseQuantity($verifiedQuantity);
        if ($verifiedQty !== null && $verifiedQty <= 0) {
            throw new RuntimeException('Verified quantity must be positive. Reject the report if no units are affected.');
        }
        $submission = new InventorySubmission($this->pdo, $approverId, 'approveReport', ['operation_token' => $operationToken, 'id' => $adjustmentId, 'notes' => $reviewNotes, 'batches' => $batchAllocations, 'verified_quantity' => $verifiedQty]);
        if ($submission->completed() !== null) return;

        $cashierId = 0;
        $this->ensureRevisionsTable();
        StoreWriteGate::begin($this->pdo);
        try {
            [$scopeSql, $scopeParams] = $this->productScope();
            $reportStmt = $this->pdo->prepare(
                "SELECT ia.*{$this->packSizeSelect()} FROM inventory_adjustments ia
                  JOIN products p ON p.product_id = ia.product_id
                  WHERE ia.adjustment_id = ?{$scopeSql}{$this->forUpdate()}"
            );
            $reportStmt->execute(array_merge([$adjustmentId], $scopeParams));
            $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                throw new RuntimeException('Stock issue report not found.');
            }
            if (!in_array($report['status'], ['pending', 'returned'], true)) {
                throw new RuntimeException('Only unresolved reports can be approved.');
            }
            $this->preserveLegacyClarification($report);

            $cashierId = (int)$report['reported_by'];
            $reportedQty = (int)($report['reported_qty'] ?? 0) ?: abs((int)$report['adjustment_qty']);
            $verifiedQty ??= $reportedQty;
            $form = $report['form'] ?? 'piece';
            $this->applyApproval($report, $approverId, $reviewNotes, $batchAllocations, $verifiedQty);

            $submission->remember(true);
            $this->pdo->commit();
            StoreOperationsDashboardWorkspace::invalidateCache();
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
                "An Inventory Manager approved your report. Reported: {$reportedQty}; verified and deducted: {$verifiedQty} " . ($form === 'pack' ? 'pack(s).' : 'piece(s).')
            );
        }
    }

    private function applyApproval(array $report, int $approverId, string $reviewNotes, ?array $batchAllocations, ?int $verifiedQty): void
    {
        $adjustmentId = (int)$report['adjustment_id'];
        $productId = (int)$report['product_id'];
        $form = $report['form'] ?? 'piece';
        $rawPackSize = !empty($report['pack_size']) ? (int)$report['pack_size'] : (!empty($report['product_pack_size']) ? (int)$report['product_pack_size'] : null);
        $packSize = $rawPackSize !== null && $rawPackSize > 1 ? $rawPackSize : null;

        $reportedQty = (int)($report['reported_qty'] ?? 0);
        if ($reportedQty <= 0) {
            $reportedQty = abs((int)$report['adjustment_qty']);
        }

        $verifiedQty ??= $reportedQty;
        $notes = trim($reviewNotes);
        if ($verifiedQty !== $reportedQty && $notes === '') {
            throw new RuntimeException('Explain why the verified quantity differs from the reported quantity.');
        }
        if ($form === 'pack' && (float)$verifiedQty * ($packSize ?? 1) > 2147483647) {
            throw new RuntimeException('Resulting unit quantity is too large.');
        }

        // BatchInventoryLedger atomic deduction: verifies quantity_on_hand >= ?; throws Insufficient available stock
        $mutation = $this->ledger->recordStockIssueDeduction(
            $productId,
            $verifiedQty,
            $form,
            $packSize,
            $batchAllocations,
            $approverId,
            $adjustmentId,
            'adjustment'
        );

        $available = $mutation['available_before'];
        $baseUnitQty = $mutation['base_units'];
        $finalAllocations = $mutation['allocations'];

        $auditDetail = 'adjustment: ' . $report['adjustment_type'] . ' report #' . $adjustmentId;
        if ($notes !== '') {
            $auditDetail .= ' - ' . mb_substr($notes, 0, 200);
        }

        $snapshotSql = $this->hasColumn('inventory_adjustments', 'form') ? ', reported_qty = ?, pack_size = ?' : '';
        $reviewValues = [$approverId, $notes !== '' ? $notes : null, -$baseUnitQty];
        if ($snapshotSql !== '') {
            array_push($reviewValues, $reportedQty, $packSize);
        }
        $reviewValues[] = $adjustmentId;
        $reviewStmt = $this->pdo->prepare(
            "UPDATE inventory_adjustments
              SET status = 'approved', approved_by = ?, approved_at = {$this->nowExpr()}, review_notes = ?, adjustment_qty = ?{$snapshotSql}
              WHERE adjustment_id = ? AND status IN ('pending', 'returned')"
        );
        $reviewStmt->execute($reviewValues);
        if ($reviewStmt->rowCount() === 0) {
            throw new RuntimeException('This report was already reviewed.');
        }

        $newRevisionValues = [
            'status' => 'approved',
            'quantity_on_hand' => $available - $baseUnitQty,
            'deducted_qty' => $baseUnitQty,
            'form' => $form,
            'reported_qty' => $reportedQty,
            'verified_qty' => $verifiedQty,
            'review_notes' => $notes,
        ];
        if (!empty($finalAllocations)) {
            $newRevisionValues['batches'] = $finalAllocations;
        }

        $this->insertRevision(
            $adjustmentId,
            $approverId,
            'approved',
            $report['status'],
            'approved',
            ['status' => $report['status'], 'quantity_on_hand' => $available, 'reported_qty' => $reportedQty],
            $newRevisionValues
        );

        log_activity(
            $this->pdo,
            $approverId,
            'Stock issue approved',
            'Inventory Adjustments',
            $adjustmentId,
            [
                'adjustment_id' => $adjustmentId,
                'status' => $report['status'],
                'quantity_on_hand' => $available,
            ],
            [
                'adjustment_id' => $adjustmentId,
                'status' => 'approved',
                'quantity_on_hand' => $available - $baseUnitQty,
                'deducted_qty' => $baseUnitQty,
                'form' => $form,
                'reported_qty' => $reportedQty,
                'verified_qty' => $verifiedQty,
                'detail' => $auditDetail,
                'batches' => $finalAllocations ?: null,
            ]
        );
    }

    /**
     * Reject a pending report. Requires a reason; never changes inventory.
     */
    public function rejectReport(int $adjustmentId, int $approverId, string $reason, string $operationToken = ''): void
    {
        if ($adjustmentId <= 0) {
            throw new RuntimeException('Invalid report selected.');
        }
        $this->assertRole($approverId, 'inventory_manager');
        $submission = new InventorySubmission($this->pdo, $approverId, 'rejectReport', ['operation_token' => $operationToken, 'id' => $adjustmentId, 'reason' => $reason]);
        if ($submission->completed() !== null) return;
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A rejection reason is required.');
        }
        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'stock issue rejection');

        $cashierId = 0;
        $this->ensureRevisionsTable();
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
            if (!in_array($report['status'], ['pending', 'returned'], true)) {
                throw new RuntimeException('Only unresolved reports can be rejected.');
            }
            $this->preserveLegacyClarification($report);
            $cashierId = (int)$report['reported_by'];

            $update = $this->pdo->prepare(
                "UPDATE inventory_adjustments
                  SET status = 'rejected', approved_by = ?, approved_at = {$this->nowExpr()}, review_notes = ?
                  WHERE adjustment_id = ? AND status IN ('pending', 'returned')"
            );
            $update->execute([$approverId, $reason, $adjustmentId]);
            if ($update->rowCount() === 0) {
                throw new RuntimeException('Only pending reports can be rejected.');
            }

            $this->insertRevision(
                $adjustmentId,
                $approverId,
                'rejected',
                $report['status'],
                'rejected',
                ['status' => $report['status']],
                ['status' => 'rejected', 'review_notes' => $reason]
            );

            log_activity(
                $this->pdo,
                $approverId,
                'Stock issue rejected',
                'Inventory Adjustments',
                $adjustmentId,
                ['status' => $report['status']],
                ['status' => 'rejected', 'review_notes' => $reason]
            );

            $submission->remember(true);
            $this->pdo->commit();
            StoreOperationsDashboardWorkspace::invalidateCache();
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

    public function decide(int $adjustmentId, int $approverId, string $outcome, string $notesOrReason = ''): void
    {
        if ($outcome === 'approved') {
            $this->approveReport($adjustmentId, $approverId, $notesOrReason);
        } elseif ($outcome === 'rejected') {
            $this->rejectReport($adjustmentId, $approverId, $notesOrReason);
        } else {
            throw new \InvalidArgumentException("Invalid decision outcome: {$outcome}");
        }
    }

    /**
     * Read-only oversight list (ticket #31). The authenticated active Workspace
     * takes precedence over the primary account role: Administrators and Inventory
     * Managers see every report for operational review; Cashiers see only their
     * own. Filters: status, category, product_id, product (text), cashier_id,
     * approver_id, date_from, date_to (Y-m-d). Never mutates.
     */
    public function getOversightReports(array $filters, int $viewerId): array
    {
        $viewerRole = $this->activeWorkspace ?? $this->roleOf($viewerId);
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
            "SELECT ia.*,
                    p.sku, p.product_name{$this->packSizeSelect()},
                    reporter.display_name AS cashier_name,
                    reviewer.display_name AS reviewer_name
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
     * Returns null when the report does not exist; the active Workspace applies
     * and Cashiers may only open their own reports.
     */
    public function getReportDetail(int $adjustmentId, int $viewerId): ?array
    {
        $viewerRole = $this->activeWorkspace ?? $this->roleOf($viewerId);
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
                    mover.display_name AS moved_by_name
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
            'batches' => $this->getReportBatches($adjustmentId),
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

        $formCols = $this->hasColumn('inventory_counts', 'physical_sealed_quantity')
            ? ', ic.system_sealed_quantity, ic.system_loose_quantity, ic.physical_sealed_quantity, ic.physical_loose_quantity, ic.difference_sealed_qty, ic.difference_loose_qty'
            : '';

        $stmt = $this->pdo->prepare(
            "SELECT ic.count_id, ic.product_id, ic.system_quantity, ic.physical_quantity,
                    ic.difference_qty{$formCols}, ic.discrepancy_reason, ic.counted_by, ic.approved_by,
                    ic.counted_at, ic.approved_at, ic.status, ic.related_adjustment_id,
                    p.sku, p.product_name{$this->packSizeSelect()},
                    counter.display_name AS counted_by_name,
                    approver.display_name AS approved_by_name
             FROM inventory_counts ic
             JOIN products p ON p.product_id = ic.product_id
             LEFT JOIN users counter ON counter.user_id = ic.counted_by
             LEFT JOIN users approver ON approver.user_id = ic.approved_by
             WHERE ic.related_adjustment_id = ?
             ORDER BY ic.counted_at DESC, ic.count_id DESC"
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
                    p.sku, p.product_name{$this->packSizeSelect()},
                    reporter.display_name AS cashier_name,
                    reviewer.display_name AS reviewer_name
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
        if (!$this->pdo->inTransaction()) $this->ensureRevisionsTable();
        $stmt = $this->pdo->prepare(
            'SELECT r.revision_id, r.adjustment_id, r.actor_id, r.action, r.old_status, r.new_status,
                    r.old_values, r.new_values, r.created_at,
                    actor.display_name AS actor_name
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
    public function getPendingReports(?int $limit = null, int $offset = 0, string $sort = '', string $direction = 'asc'): array
    {
        $pagination = $limit === null ? '' : ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset);
        $order = $this->reportListOrder($sort, $direction, 'ia.reported_at', 'ABS(ia.adjustment_qty)', 'ia.reported_at ASC, ia.adjustment_id ASC');
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT ia.*,
                    p.sku, p.product_name{$this->packSizeSelect()},
                    u.display_name AS cashier_name,
                    COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             JOIN users u ON u.user_id = ia.reported_by
             LEFT JOIN inventory i ON i.product_id = ia.product_id
             WHERE ia.status IN ('pending', 'returned'){$scopeSql}
             ORDER BY {$order}{$pagination}"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reports awaiting a cashier clarification reply (manager visibility).
     */
    public function getReturnedReports(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT ia.*,
                    p.sku, p.product_name{$this->packSizeSelect()},
                    reporter.display_name AS cashier_name,
                    reviewer.display_name AS reviewer_name
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
             WHERE ia.status IN ('pending', 'returned'){$scopeSql}"
        );
        $stmt->execute($scopeParams);
        return (int)$stmt->fetchColumn();
    }

    /**
     * A cashier's own report history. Viewable without an active shift.
     */
    public function getReportsByCashier(int $cashierId, int $limit = 20, ?int $adjustmentId = null, int $offset = 0, string $status = 'all'): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$where, $params] = $this->cashierReportScope($cashierId);
        if ($adjustmentId !== null) {
            $where .= ' AND ia.adjustment_id = ?';
            $params[] = $adjustmentId;
        }
        if ($status === 'pending') {
            $where .= " AND ia.status IN ('pending', 'returned')";
        } elseif ($status !== 'all') {
            $where .= ' AND ia.status = ?';
            $params[] = $status;
        }
        $stmt = $this->pdo->prepare(
            "SELECT ia.*,
                    p.sku, p.product_name{$this->packSizeSelect()},
                    reviewer.display_name AS reviewer_name
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             LEFT JOIN users reviewer ON reviewer.user_id = ia.approved_by
             WHERE {$where}
             ORDER BY ia.reported_at DESC, ia.adjustment_id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCashierReportCounts(int $cashierId): array
    {
        [$where, $params] = $this->cashierReportScope($cashierId);
        $stmt = $this->pdo->prepare(
            "SELECT ia.status, COUNT(*) AS total
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             WHERE {$where}
             GROUP BY ia.status"
        );
        $stmt->execute($params);
        $counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $total) {
            $counts['all'] += (int)$total;
            $status = $status === 'returned' ? 'pending' : $status;
            if (isset($counts[$status])) {
                $counts[$status] += (int)$total;
            }
        }
        return $counts;
    }

    private function cashierReportScope(int $cashierId): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        // Direct Inventory records carry a recorded revision. Older Cashier reports can lack a shift.
        $cashierOnly = $this->hasColumn('inventory_adjustment_revisions', 'action')
            ? " AND NOT EXISTS (SELECT 1 FROM inventory_adjustment_revisions r
                WHERE r.adjustment_id = ia.adjustment_id AND r.action = 'recorded')"
            : '';
        return ["ia.reported_by = ?{$scopeSql}{$cashierOnly}", array_merge([$cashierId], $scopeParams)];
    }

    /**
     * Recently decided reports for the manager review queue.
     */
    public function getReviewCount(): int
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             WHERE ia.status IN ('approved', 'rejected', 'cancelled'){$scopeSql}"
        );
        $stmt->execute($scopeParams);
        return (int)$stmt->fetchColumn();
    }

    public function getRecentReviews(int $limit = 50, int $offset = 0, string $sort = '', string $direction = 'desc'): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $order = $this->reportListOrder(
            $sort,
            $direction,
            'COALESCE(ia.approved_at, ia.reported_at)',
            "CASE WHEN ia.status = 'approved' THEN ABS(ia.adjustment_qty) ELSE 0 END",
            'ia.approved_at DESC, ia.reported_at DESC, ia.adjustment_id DESC'
        );
        [$scopeSql, $scopeParams] = $this->productScope();
        // LIMIT cannot be bound portably, so it is clamped to an integer above.
        $stmt = $this->pdo->prepare(
            "SELECT ia.*,
                    p.sku, p.product_name{$this->packSizeSelect()},
                    reporter.display_name AS cashier_name,
                    reviewer.display_name AS reviewer_name
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             JOIN users reporter ON reporter.user_id = ia.reported_by
             LEFT JOIN users reviewer ON reviewer.user_id = ia.approved_by
             WHERE ia.status IN ('approved', 'rejected', 'cancelled'){$scopeSql}
             ORDER BY {$order}
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function reportListOrder(string $sort, string $direction, string $dateColumn, string $quantityColumn, string $defaultOrder): string
    {
        $column = match ($sort) {
            'report' => 'ia.adjustment_id',
            'product' => 'p.product_name',
            'type' => 'ia.adjustment_type',
            'quantity' => $quantityColumn,
            'status' => 'LOWER(ia.status)',
            'date' => $dateColumn,
            default => null,
        };
        if ($column === null) {
            return $defaultOrder;
        }
        $sqlDirection = $direction === 'desc' ? 'DESC' : 'ASC';
        $order = "$column $sqlDirection";
        return $sort === 'report' ? $order : "$order, ia.adjustment_id $sqlDirection";
    }

    public function getAvailableStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = ?');
        $stmt->execute([$productId]);
        $quantity = $stmt->fetchColumn();
        return $quantity === false ? 0 : (int)$quantity;
    }

    /**
     * Active remaining batches for a product, ordered by FEFO.
     */
    public function getProductBatches(int $productId): array
    {
        if ($productId <= 0 || !$this->hasBatchesTable()) {
            return [];
        }
        $hasBatchCols = $this->hasColumn('product_batches', 'sealed_quantity');
        $formSelect = $hasBatchCols ? ', pb.sealed_quantity, pb.loose_quantity' : '';
        $stmt = $this->pdo->prepare(
            "SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.quantity, pb.remaining_quantity{$formSelect},
                    pb.expiration_date, pb.date_received, pb.supplier
             FROM product_batches pb
             WHERE pb.product_id = ? AND pb.remaining_quantity > 0
             ORDER BY
                CASE WHEN pb.expiration_date IS NULL THEN 1 ELSE 0 END,
                pb.expiration_date ASC,
                pb.date_received ASC,
                pb.batch_id ASC"
        );
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Batch deductions recorded for an approved stock-issue report.
     */
    public function getReportBatches(int $adjustmentId): array
    {
        if ($adjustmentId <= 0) {
            return [];
        }
        try {
            $hasSibCols = $this->hasColumn('stock_issue_batches', 'form');
            $sibFormSelect = $hasSibCols ? ', sib.form, sib.reported_qty' : '';
            $stmt = $this->pdo->prepare(
                "SELECT sib.id, sib.adjustment_id, sib.batch_id, sib.quantity{$sibFormSelect}, sib.created_at,
                        pb.batch_number, pb.expiration_date
                 FROM stock_issue_batches sib
                 LEFT JOIN product_batches pb ON pb.batch_id = sib.batch_id
                 WHERE sib.adjustment_id = ?
                 ORDER BY sib.id ASC"
            );
            $stmt->execute([$adjustmentId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Recommend FEFO batch allocations for a given product and quantity.
     */
    public function recommendBatchAllocation(int $productId, int $quantity, string $form = 'piece'): array
    {
        if ($quantity <= 0) {
            return [];
        }
        $batches = $this->getProductBatches($productId);
        $allocations = [];
        $needed = $quantity;
        foreach ($batches as $batch) {
            if ($needed <= 0) {
                break;
            }
            if ($form === 'pack') {
                $avail = (int)($batch['sealed_quantity'] ?? 0);
            } else {
                $avail = isset($batch['loose_quantity']) ? (int)$batch['loose_quantity'] : (int)$batch['remaining_quantity'];
            }
            $take = min($needed, $avail);
            if ($take > 0) {
                $allocations[(int)$batch['batch_id']] = $take;
                $needed -= $take;
            }
        }
        return $allocations;
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
        $role = $this->activeWorkspace ?? $this->roleOf($userId);
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

    private function hasBatchesTable(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM product_batches LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function packSizeSelect(): string
    {
        return $this->hasColumn('products', 'pack_size') ? ', p.pack_size AS product_pack_size' : '';
    }

    private function hasColumn(string $table, string $column): bool
    {
        return isset(SalesWorkflowService::getTableColumns($this->pdo, $table)[$column]);
    }

    private function parseQuantity(mixed $rawQty): int
    {
        if (!is_int($rawQty) && !(is_string($rawQty) && preg_match('/^-?\d+$/D', trim($rawQty)))) {
            throw new RuntimeException('Quantity must be a positive whole number of units.');
        }
        if (is_string($rawQty) && (float)trim($rawQty) > 2147483647) {
            throw new RuntimeException('Quantity is too large.');
        }
        $quantity = (int)$rawQty;
        if ($quantity > 2147483647) {
            throw new RuntimeException('Quantity is too large.');
        }
        return $quantity;
    }

    private function normalizeForm(string $rawForm): string
    {
        $form = strtolower(trim($rawForm));
        if ($form !== 'piece' && $form !== 'pack') {
            throw new RuntimeException('Invalid stock form. Select piece or pack.');
        }
        return $form;
    }

    /**
     * @return array{0: ?int, 1: int} [packSize, baseUnitQty]
     */
    private function resolveProductPackAndBaseUnits(int $productId, int $quantity, string $form, bool $requireActiveScoped = false): array
    {
        if ($requireActiveScoped) {
            [$scopeSql, $scopeParams] = $this->productScope();
            $stmt = $this->pdo->prepare(
                "SELECT p.product_id{$this->packSizeSelect()} FROM products p WHERE p.product_id = ? AND p.status = 'active'{$scopeSql}{$this->forUpdate()}"
            );
            $stmt->execute(array_merge([$productId], $scopeParams));
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new RuntimeException('Active product not found.');
            }
            $rawPack = $product['product_pack_size'] ?? null;
        } else {
            $rawPack = null;
            if ($this->hasColumn('products', 'pack_size')) {
                $stmt = $this->pdo->prepare('SELECT pack_size FROM products WHERE product_id = ?');
                $stmt->execute([$productId]);
                $rawPack = $stmt->fetchColumn();
            }
        }

        $packSize = !empty($rawPack) && (int)$rawPack > 1 ? (int)$rawPack : null;
        if ($form === 'pack') {
            if ($packSize === null) {
                throw new RuntimeException('Cannot report pack issue for product without Sales Pack configured.');
            }
            if (((float)$quantity * (float)$packSize) > 2147483647) {
                throw new RuntimeException('Resulting unit quantity is too large.');
            }
            return [$packSize, $quantity * $packSize];
        }

        return [$packSize, $quantity];
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
                $this->insertNotification($managerId, $adjustmentId, $title, $message, 'inventory_manager');
            }
        } catch (Throwable $e) {
            error_log('Stock issue manager notification skipped: ' . $e->getMessage());
        }
    }

    private function notifyUser(int $userId, int $adjustmentId, string $title, string $message, bool $required = false): void
    {
        try {
            $eligible = array_column($this->notificationService->recipients('cashier'), 'user_id');
            if (in_array($userId, array_map('intval', $eligible), true)) {
                $this->insertNotification($userId, $adjustmentId, $title, $message, 'cashier', $required);
            }
        } catch (Throwable $e) {
            error_log('Stock issue user notification skipped: ' . $e->getMessage());
        }
    }

    private function insertNotification(int $userId, int $adjustmentId, string $title, string $message, string $workspace, bool $required = true): void
    {
        $inApp = $required || $this->notificationService->inAppEnabled($userId, 'notify_adjustment');
        $this->notificationService->createNotification($userId, 'adjustment', $title, $message, $adjustmentId, 'stock_issue', $workspace, $inApp);
    }

    /** @return int[] */
    private function managerUserIds(): array
    {
        return array_map('intval', array_column($this->notificationService->recipients('inventory_manager'), 'user_id'));
    }

    private function productScope(): array
    {
        if (function_exists('store_product_scope')) {
            return store_product_scope('p');
        }

        return ['', []];
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
