<?php
// app/Services/InventoryCountService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
use App\Store\StoreWriteGate;
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../../includes/functions.php';

class InventoryCountService
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

    public function getProductBatchCoverage(int $productId): array
    {
        if ($productId <= 0) {
            throw new RuntimeException('Product is required.');
        }
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT p.product_id, p.sku, p.product_name, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON i.product_id = p.product_id
             WHERE p.product_id = ?{$scopeSql}"
        );
        $stmt->execute(array_merge([$productId], $scopeParams));
        $product = $stmt->fetch();
        if (!$product) {
            throw new RuntimeException('Inventory record not found for the selected product.');
        }

        $onHand = (int)$product['quantity_on_hand'];

        $batchStmt = $this->pdo->prepare(
            "SELECT batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity,
                    expiration_date, date_received, supplier
             FROM product_batches
             WHERE product_id = ? AND remaining_quantity > 0
             ORDER BY (expiration_date IS NULL) ASC, expiration_date ASC, date_received ASC, batch_id ASC"
        );
        $batchStmt->execute([$productId]);
        $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

        $batchSum = 0;
        $today = date('Y-m-d');
        foreach ($batches as &$batch) {
            $batch['batch_id'] = (int)$batch['batch_id'];
            $batch['quantity'] = (int)$batch['quantity'];
            $batch['remaining_quantity'] = (int)$batch['remaining_quantity'];
            $batch['is_expired'] = !empty($batch['expiration_date']) && $batch['expiration_date'] < $today;
            $batchSum += $batch['remaining_quantity'];
        }
        unset($batch);

        $unbatchedQty = max(0, $onHand - $batchSum);
        $hasMismatch = ($onHand !== $batchSum);

        return [
            'product_id' => $productId,
            'sku' => (string)$product['sku'],
            'product_name' => (string)$product['product_name'],
            'on_hand' => $onHand,
            'batch_sum' => $batchSum,
            'unbatched_qty' => $unbatchedQty,
            'has_mismatch' => $hasMismatch,
            'batches' => $batches,
        ];
    }

    public function getCountBatches(int $countId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT icb.*, pb.batch_number AS original_batch_number, pb.expiration_date AS original_expiration_date
             FROM inventory_count_batches icb
             LEFT JOIN product_batches pb ON pb.batch_id = icb.batch_id
             WHERE icb.count_id = ?
             ORDER BY icb.count_batch_id ASC"
        );
        $stmt->execute([$countId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recordCount(array $data, int $userId): int
    {
        $productId = (int)($data['product_id'] ?? 0);
        $reason = trim((string)($data['discrepancy_reason'] ?? ''));
        $relatedAdjustmentId = (int)($data['related_adjustment_id'] ?? 0);
        if ($productId <= 0) {
            throw new RuntimeException('Product is required.');
        }
        if ($reason === '') {
            throw new RuntimeException('Reason for discrepancy is required.');
        }

        if ($relatedAdjustmentId > 0) {
            // Correcting an erroneous approved stock-issue report is a
            // separate controlled Inventory Manager inventory-count transaction;
            // the original report is never reopened or rewritten.
            $this->assertRole($userId, 'inventory_manager');
            $this->loadApprovedStockIssueReport($relatedAdjustmentId, $productId);
        }

        $this->fiscalPeriodGuard->assertOpenNow('inventory_counts', 'inventory count');

        $rawBatches = $data['batches'] ?? null;
        $hasBatches = is_array($rawBatches) && count($rawBatches) > 0;
        $batchEntries = [];
        $totalBatchPhysical = 0;

        $parseNonNegativeInt = static function ($val, string $msg): int {
            $isNonNegativeInt = (is_int($val) && $val >= 0) || (is_string($val) && preg_match('/^\d+$/D', $val));
            if (!$isNonNegativeInt) {
                throw new RuntimeException($msg);
            }
            return (int)$val;
        };

        if ($hasBatches) {
            $seenBatchIds = [];
            foreach ($rawBatches as $b) {
                $bId = !empty($b['batch_id']) ? (int)$b['batch_id'] : null;
                $bQtyRaw = $b['physical_quantity'] ?? null;
                if ($bId === null && ($bQtyRaw === null || $bQtyRaw === '' || (int)$bQtyRaw <= 0)) {
                    continue;
                }
                $bQty = $parseNonNegativeInt($bQtyRaw, 'Batch physically counted quantity must be a non-negative whole number.');

                if ($bId !== null) {
                    if (isset($seenBatchIds[$bId])) {
                        throw new RuntimeException('Duplicate batch line in count submission.');
                    }
                    $seenBatchIds[$bId] = true;
                }

                $bExp = null;
                if (!empty($b['expiration_date'])) {
                    $bExp = validate_calendar_date($b['expiration_date']);
                }
                $bNum = isset($b['batch_number']) && trim((string)$b['batch_number']) !== ''
                    ? trim((string)$b['batch_number'])
                    : null;
                $bNotes = isset($b['notes']) && trim((string)$b['notes']) !== ''
                    ? trim((string)$b['notes'])
                    : null;

                $batchEntries[] = [
                    'batch_id' => $bId,
                    'physical_quantity' => $bQty,
                    'expiration_date' => $bExp,
                    'batch_number' => $bNum,
                    'notes' => $bNotes,
                ];
                $totalBatchPhysical += $bQty;
            }

            if (count($batchEntries) > 0) {
                if (isset($data['physical_quantity']) && $data['physical_quantity'] !== '') {
                    $physicalQty = $parseNonNegativeInt($data['physical_quantity'], 'Physically counted quantity must be a non-negative whole number.');
                    if ($physicalQty !== $totalBatchPhysical) {
                        throw new RuntimeException('Physically counted quantity does not match the sum of batch quantities.');
                    }
                } else {
                    $physicalQty = $totalBatchPhysical;
                }
            } else {
                $physicalQty = $parseNonNegativeInt($data['physical_quantity'] ?? null, 'Physically counted quantity must be a non-negative whole number.');
            }
        } else {
            $physicalQty = $parseNonNegativeInt($data['physical_quantity'] ?? null, 'Physically counted quantity must be a non-negative whole number.');
        }

        StoreWriteGate::begin($this->pdo);
        try {
            [$scopeSql, $scopeParams] = $this->productScope();
            $stmt = $this->pdo->prepare(
                "SELECT i.quantity_on_hand
                 FROM inventory i
                 JOIN products p ON p.product_id = i.product_id
                 WHERE i.product_id = ?{$scopeSql}{$this->forUpdate()}"
            );
            $stmt->execute(array_merge([$productId], $scopeParams));
            $inventory = $stmt->fetch();

            if (!$inventory) {
                throw new RuntimeException('Inventory record not found for the selected product.');
            }

            $systemQty = (int)$inventory['quantity_on_hand'];
            $differenceQty = $physicalQty - $systemQty;

            $stmt = $this->pdo->prepare(
                "INSERT INTO inventory_counts (
                    product_id, system_quantity, physical_quantity, difference_qty,
                    discrepancy_reason, counted_by, counted_at, status, related_adjustment_id
                 ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'pending', ?)"
            );
            $stmt->execute([
                $productId,
                $systemQty,
                $physicalQty,
                $differenceQty,
                $reason,
                $userId,
                $relatedAdjustmentId > 0 ? $relatedAdjustmentId : null,
            ]);

            $countId = (int)$this->pdo->lastInsertId();

            if (count($batchEntries) > 0) {
                $batchInsertStmt = $this->pdo->prepare(
                    "INSERT INTO inventory_count_batches (
                        count_id, batch_id, system_quantity, physical_quantity, difference_qty,
                        expiration_date, batch_number, notes
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $batchCheckStmt = $this->pdo->prepare(
                    "SELECT remaining_quantity, expiration_date, batch_number
                     FROM product_batches
                     WHERE batch_id = ? AND product_id = ?{$this->forUpdate()}"
                );

                foreach ($batchEntries as $entry) {
                    $entryBatchId = $entry['batch_id'];
                    if ($entryBatchId !== null) {
                        $batchCheckStmt->execute([$entryBatchId, $productId]);
                        $productBatch = $batchCheckStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$productBatch) {
                            throw new RuntimeException("Selected batch #{$entryBatchId} not found for this product.");
                        }
                        $batchSystemQty = (int)$productBatch['remaining_quantity'];
                        $batchExp = $productBatch['expiration_date'];
                        $batchNum = $productBatch['batch_number'];
                    } else {
                        $batchSystemQty = 0;
                        $batchExp = $entry['expiration_date'];
                        $batchNum = $entry['batch_number'];
                    }

                    $diff = $entry['physical_quantity'] - $batchSystemQty;
                    $batchInsertStmt->execute([
                        $countId,
                        $entryBatchId,
                        $batchSystemQty,
                        $entry['physical_quantity'],
                        $diff,
                        $batchExp,
                        $batchNum,
                        $entry['notes'],
                    ]);
                }
            }

            $this->pdo->commit();
            return $countId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function approveCount(int $countId, int $userId): void
    {
        if ($countId <= 0) {
            throw new RuntimeException('Invalid count selected.');
        }

        StoreWriteGate::begin($this->pdo);
        try {
            [$scopeSql, $scopeParams] = $this->productScope();
            $stmt = $this->pdo->prepare(
                "SELECT ic.*
                 FROM inventory_counts ic
                 JOIN products p ON p.product_id = ic.product_id
                 WHERE ic.count_id = ?{$scopeSql}{$this->forUpdate()}"
            );
            $stmt->execute(array_merge([$countId], $scopeParams));
            $count = $stmt->fetch();

            if (!$count) {
                throw new RuntimeException('Inventory count not found.');
            }
            if ($count['status'] !== 'pending') {
                throw new RuntimeException('Only pending counts can be approved.');
            }

            $this->fiscalPeriodGuard->assertOpenForDate($count['counted_at'], 'inventory_counts', 'inventory count');
            $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'inventory adjustment');
            $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');

            $productId = (int)$count['product_id'];
            $differenceQty = (int)$count['difference_qty'];

            $inventoryStmt = $this->pdo->prepare(
                "SELECT quantity_on_hand
                 FROM inventory
                 WHERE product_id = ?{$this->forUpdate()}"
            );
            $inventoryStmt->execute([$productId]);
            $inventory = $inventoryStmt->fetch();

            if (!$inventory) {
                throw new RuntimeException('Inventory record not found for this count.');
            }

            $currentSystemQty = (int)$inventory['quantity_on_hand'];
            if ($currentSystemQty !== (int)$count['system_quantity']) {
                throw new RuntimeException('Stock has changed since this count was recorded. Please record a fresh count before approval.');
            }

            $cbStmt = $this->pdo->prepare(
                "SELECT * FROM inventory_count_batches WHERE count_id = ? ORDER BY count_batch_id ASC{$this->forUpdate()}"
            );
            $cbStmt->execute([$countId]);
            $countBatches = $cbStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($countBatches)) {
                // Lock and revalidate all batch lines
                foreach ($countBatches as $cb) {
                    $batchId = !empty($cb['batch_id']) ? (int)$cb['batch_id'] : null;
                    if ($batchId !== null) {
                        $pbStmt = $this->pdo->prepare(
                            "SELECT remaining_quantity FROM product_batches WHERE batch_id = ? AND product_id = ?{$this->forUpdate()}"
                        );
                        $pbStmt->execute([$batchId, $productId]);
                        $currentBatch = $pbStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$currentBatch) {
                            throw new RuntimeException('Batch record not found for this count.');
                        }
                        if ((int)$currentBatch['remaining_quantity'] !== (int)$cb['system_quantity']) {
                            throw new RuntimeException('Stock has changed since this count was recorded. Please record a fresh count before approval.');
                        }
                    }
                }

                // Apply batch updates
                $batchUpdateStmt = $this->pdo->prepare(
                    "UPDATE product_batches
                     SET remaining_quantity = ?
                     WHERE batch_id = ?"
                );
                $batchInsertStmt = $this->pdo->prepare(
                    "INSERT INTO product_batches (
                        product_id, receiving_id, batch_number, quantity, remaining_quantity,
                        expiration_date, date_received, supplier, created_at
                    ) VALUES (?, NULL, ?, ?, ?, ?, CURRENT_TIMESTAMP, NULL, CURRENT_TIMESTAMP)"
                );

                $hasBatchAdjustments = false;
                foreach ($countBatches as $countBatch) {
                    $batchId = !empty($countBatch['batch_id']) ? (int)$countBatch['batch_id'] : null;
                    $physicalBatchQty = (int)$countBatch['physical_quantity'];
                    if ((int)$countBatch['difference_qty'] !== 0) {
                        $hasBatchAdjustments = true;
                    }
                    if ($batchId !== null) {
                        $batchUpdateStmt->execute([$physicalBatchQty, $batchId]);
                    } else {
                        if ($physicalBatchQty > 0) {
                            $batchInsertStmt->execute([
                                $productId,
                                $countBatch['batch_number'],
                                $physicalBatchQty,
                                $physicalBatchQty,
                                $countBatch['expiration_date'],
                            ]);
                        }
                    }
                }
            }

            $this->pdo->prepare(
                "UPDATE inventory
                 SET quantity_on_hand = ?
                 WHERE product_id = ?"
            )->execute([(int)$count['physical_quantity'], $productId]);

            if ($differenceQty !== 0 || !empty($hasBatchAdjustments)) {
                $this->pdo->prepare(
                    "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by)
                     VALUES (?, ?, 'adjustment', ?)"
                )->execute([$productId, $differenceQty, $userId]);
            }

            $this->pdo->prepare(
                "UPDATE inventory_counts
                 SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP
                 WHERE count_id = ?"
            )->execute([$userId, $countId]);

            log_activity(
                $this->pdo,
                $userId,
                'Stock adjustment approval',
                'Inventory Counts',
                $countId,
                [
                    'count_id' => $countId,
                    'product_id' => $productId,
                    'status' => 'pending',
                    'quantity_on_hand' => $currentSystemQty,
                ],
                [
                    'count_id' => $countId,
                    'product_id' => $productId,
                    'status' => 'approved',
                    'quantity_on_hand' => (int)$count['physical_quantity'],
                    'difference_qty' => $differenceQty,
                    'reason' => $count['discrepancy_reason'],
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


    public function rejectCount(int $countId, int $userId): void
    {
        if ($countId <= 0) {
            throw new RuntimeException('Invalid count selected.');
        }

        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "UPDATE inventory_counts ic
             JOIN products p ON p.product_id = ic.product_id
             SET ic.status = 'rejected', ic.approved_by = ?, ic.approved_at = CURRENT_TIMESTAMP
             WHERE ic.count_id = ? AND ic.status = 'pending'{$scopeSql}"
        );
        $stmt->execute(array_merge([$userId, $countId], $scopeParams));

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Only pending counts can be rejected.');
        }
    }

    public function getActiveProducts(): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT p.product_id, p.sku, p.product_name, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON i.product_id = p.product_id
             WHERE p.status = 'active'{$scopeSql}
             ORDER BY p.product_name"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll();
    }

    public function getRecentCounts(int $limit = 50): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT ic.*, p.sku, p.product_name,
                    counted_user.full_name AS counted_by_name,
                    approved_user.full_name AS approved_by_name,
                    (SELECT COUNT(*) FROM inventory_count_batches icb WHERE icb.count_id = ic.count_id) AS batch_count
             FROM inventory_counts ic
             JOIN products p ON p.product_id = ic.product_id
             JOIN users counted_user ON counted_user.user_id = ic.counted_by
             LEFT JOIN users approved_user ON approved_user.user_id = ic.approved_by
             WHERE 1 = 1{$scopeSql}
             ORDER BY ic.counted_at DESC
             LIMIT ?"
        );
        foreach ($scopeParams as $index => $value) {
            $stmt->bindValue($index + 1, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(count($scopeParams) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getPendingCountTotal(): int
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM inventory_counts ic
             JOIN products p ON p.product_id = ic.product_id
             WHERE ic.status = 'pending'{$scopeSql}"
        );
        $stmt->execute($scopeParams);
        return (int)$stmt->fetchColumn();
    }

    private function loadApprovedStockIssueReport(int $adjustmentId, int $productId): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare(
            "SELECT ia.adjustment_id, ia.product_id, ia.status
             FROM inventory_adjustments ia
             JOIN products p ON p.product_id = ia.product_id
             WHERE ia.adjustment_id = ?{$scopeSql}"
        );
        $stmt->execute(array_merge([$adjustmentId], $scopeParams));
        $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$adjustment) {
            throw new RuntimeException('Stock issue report not found.');
        }
        if ((string)$adjustment['status'] !== 'approved') {
            throw new RuntimeException('Only approved stock issue reports can be corrected through a separate inventory count.');
        }
        if ((int)$adjustment['product_id'] !== $productId) {
            throw new RuntimeException('The correction count must target the same product as the stock issue report.');
        }
        return $adjustment;
    }

    private function assertRole(int $userId, string $expectedRole): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?'
        );
        $stmt->execute([$userId]);
        $role = (string)$stmt->fetchColumn();
        if ($role !== $expectedRole) {
            throw new RuntimeException('Only Inventory Managers can link a correction count to a stock issue report.');
        }
    }

    private function productScope(): array
    {
        if (function_exists('store_product_scope')) {
            return store_product_scope('p');
        }

        return (new App\Store\StoreScope($this->pdo))->productScope('p');
    }

    private function forUpdate(): string
    {
        // SQLite does not support SELECT ... FOR UPDATE; the surrounding
        // transaction still protects the read-then-write window there.
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
