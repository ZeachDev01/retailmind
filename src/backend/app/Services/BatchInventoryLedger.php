<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use FiscalPeriodGuardService;
use Throwable;

require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';

/**
 * BatchInventoryLedger
 *
 * Transactional balance mutations for Stock Issues, counts, Supplier Pullouts and Stock Receiving.
 * Encapsulates:
 * - Row-level locking of inventory and batch records
 * - Strict First-Expired-First-Out (FEFO) allocation algorithms
 * - Handling of sealed sales packs versus loose base units
 * - Explicit batch allocation validation
 * - Invariant enforcement: total stock on hand strictly equals sum of remaining batch quantities
 * - Stock movement audit trail logging
 * - Open fiscal period enforcement
 */
class BatchInventoryLedger
{
    private PDO $pdo;
    private FiscalPeriodGuardService $fiscalPeriodGuard;
    private array $columnCache = [];

    public function __construct(PDO $pdo, ?FiscalPeriodGuardService $fiscalPeriodGuard = null)
    {
        $this->pdo = $pdo;
        $this->fiscalPeriodGuard = $fiscalPeriodGuard ?? new FiscalPeriodGuardService($pdo);
    }

    /**
     * Deduct stock for a stock issue approval report.
     *
     * @param int $productId
     * @param int $reportedQty
     * @param string $form 'piece' or 'pack'
     * @param int|null $packSize Pack size multiplier (required > 1 if form is pack)
     * @param array|null $batchAllocations Optional explicit batch allocations
     * @param int $actorId Approving actor ID
     * @param int|null $adjustmentId Associated adjustment ID
     * @param string $movementReason Movement reason (default 'adjustment')
     * @return array Mutation summary including previous stock, updated stock, base units, and batch allocations
     */
    public function recordStockIssueDeduction(
        int $productId,
        int $reportedQty,
        string $form = 'piece',
        ?int $packSize = null,
        ?array $batchAllocations = null,
        int $actorId = 0,
        ?int $adjustmentId = null,
        string $movementReason = 'adjustment'
    ): array {
        if ($productId <= 0) {
            throw new InvalidArgumentException('Product is required.');
        }
        if ($reportedQty <= 0) {
            throw new RuntimeException('Report quantity is invalid.');
        }

        $form = strtolower(trim($form)) === 'pack' ? 'pack' : 'piece';
        if ($packSize === null && $this->hasTable('products') && $this->hasColumn('products', 'pack_size')) {
            $pStmt = $this->pdo->prepare('SELECT pack_size FROM products WHERE product_id = ?');
            $pStmt->execute([$productId]);
            $pPack = $pStmt->fetchColumn();
            if ($pPack !== false && $pPack !== null && (int)$pPack > 1) {
                $packSize = (int)$pPack;
            }
        }
        $normalizedPackSize = $packSize !== null && $packSize > 1 ? $packSize : null;
        $hasPack = $normalizedPackSize !== null;

        if ($form === 'pack') {
            if (!$hasPack) {
                throw new RuntimeException('Cannot deduct packs: product has no Sales Pack configured.');
            }
            $baseUnitQty = $reportedQty * $normalizedPackSize;
        } else {
            $baseUnitQty = $reportedQty;
        }

        if ($baseUnitQty <= 0) {
            throw new RuntimeException('Report quantity is invalid.');
        }

        // Verify open fiscal period
        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'inventory adjustment');
        $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');

        $isExternalTx = $this->pdo->inTransaction();
        if (!$isExternalTx) {
            \App\Store\StoreWriteGate::begin($this->pdo);
        }

        try {
            // Lock inventory row first for deterministic lock ordering
            $invStmt = $this->pdo->prepare(
                "SELECT quantity_on_hand FROM inventory WHERE product_id = ?{$this->forUpdate()}"
            );
            $invStmt->execute([$productId]);
            $inventory = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$inventory) {
                throw new RuntimeException('Inventory record not found for this report.');
            }

            $available = (int)$inventory['quantity_on_hand'];
            if ($available < $baseUnitQty) {
                throw new RuntimeException(
                    "Insufficient available stock ({$available} on hand, {$baseUnitQty} reported). Approval refused; inventory unchanged."
                );
            }

            $hasBatchesTable = $this->hasTable('product_batches');
            $totalBatches = 0;
            if ($hasBatchesTable) {
                $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_batches WHERE product_id = ?');
                $countStmt->execute([$productId]);
                $totalBatches = (int)$countStmt->fetchColumn();
            }

            $this->lockBatches($productId);
            // Missing batches are a mismatch too; physical counts repair legacy coverage.
            if ($hasBatchesTable) {
                $batchSumStmt = $this->pdo->prepare(
                    'SELECT COALESCE(SUM(remaining_quantity), 0) FROM product_batches WHERE product_id = ?'
                );
                $batchSumStmt->execute([$productId]);
                $existingBatchSum = (int)$batchSumStmt->fetchColumn();
                if ($available !== $existingBatchSum) {
                    throw new RuntimeException(
                        "Cannot mutate inventory for product #{$productId}: inventory quantity on hand ({$available}) does not match batch total ({$existingBatchSum}). Approval refused; inventory unchanged."
                    );
                }
            }

            $finalAllocations = [];
            $hasBatchFormCols = $hasBatchesTable && $this->hasColumn('product_batches', 'sealed_quantity');

            if ($batchAllocations !== null) {
                if (!$hasBatchesTable) {
                    throw new RuntimeException('Batch allocation requested but batch tracking is not available.');
                }

                $allocations = [];
                foreach ($batchAllocations as $key => $val) {
                    if (is_array($val)) {
                        $bIdRaw = $val['batch_id'] ?? $key;
                        $qRaw = $val['quantity'] ?? $val['qty'] ?? 0;
                    } else {
                        $bIdRaw = $key;
                        $qRaw = $val;
                    }

                    if (!is_int($bIdRaw) && !(is_string($bIdRaw) && preg_match('/^\d+$/', trim($bIdRaw)))) {
                        throw new RuntimeException('Invalid batch ID.');
                    }
                    $batchId = (int)$bIdRaw;
                    if ($batchId <= 0) {
                        throw new RuntimeException('Invalid batch ID.');
                    }

                    if (is_string($qRaw)) {
                        $qStr = trim($qRaw);
                        if ($qStr === '') {
                            continue;
                        }
                        if (!preg_match('/^-?\d+$/', $qStr)) {
                            throw new RuntimeException('Allocated quantity must be a whole number.');
                        }
                        $allocQty = (int)$qStr;
                    } elseif (is_int($qRaw)) {
                        $allocQty = $qRaw;
                    } else {
                        throw new RuntimeException('Allocated quantity must be a whole number.');
                    }

                    if ($allocQty < 0) {
                        throw new RuntimeException('Allocated quantity cannot be negative.');
                    }
                    if ($allocQty === 0) {
                        continue;
                    }

                    $allocations[$batchId] = ($allocations[$batchId] ?? 0) + $allocQty;
                }

                $sumAllocated = array_sum($allocations);
                if ($sumAllocated !== $reportedQty) {
                    throw new RuntimeException(
                        "Allocated batch quantity ({$sumAllocated}) does not match reported quantity ({$reportedQty})."
                    );
                }

                // Deterministic batch locking order
                ksort($allocations, SORT_NUMERIC);

                $formSelect = $hasBatchFormCols ? ', sealed_quantity, loose_quantity' : '';
                foreach ($allocations as $batchId => $allocReportedUnits) {
                    $batchStmt = $this->pdo->prepare(
                        "SELECT batch_id, product_id, remaining_quantity{$formSelect}
                         FROM product_batches
                         WHERE batch_id = ?{$this->forUpdate()}"
                    );
                    $batchStmt->execute([$batchId]);
                    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$batch) {
                        throw new RuntimeException("Selected batch #{$batchId} not found.");
                    }
                    if ((int)$batch['product_id'] !== $productId) {
                        throw new RuntimeException("Batch #{$batchId} does not belong to product #{$productId}.");
                    }

                    if ($form === 'pack') {
                        $availPacks = (int)($batch['sealed_quantity'] ?? 0);
                        if ($availPacks < $allocReportedUnits) {
                            throw new RuntimeException(
                                "Insufficient sealed pack stock in batch #{$batchId} ({$availPacks} available, {$allocReportedUnits} allocated)."
                            );
                        }
                        $allocBaseUnits = $allocReportedUnits * ($normalizedPackSize ?? 1);
                    } else {
                        if ($hasPack && $hasBatchFormCols) {
                            $availLoose = (int)($batch['loose_quantity'] ?? 0);
                            if ($availLoose < $allocReportedUnits) {
                                throw new RuntimeException(
                                    "Insufficient loose stock in batch #{$batchId} ({$availLoose} available, {$allocReportedUnits} allocated)."
                                );
                            }
                        } else {
                            if ((int)$batch['remaining_quantity'] < $allocReportedUnits) {
                                throw new RuntimeException(
                                    "Insufficient stock in batch #{$batchId} ({$batch['remaining_quantity']} available, {$allocReportedUnits} allocated)."
                                );
                            }
                        }
                        $allocBaseUnits = $allocReportedUnits;
                    }

                    $finalAllocations[$batchId] = [
                        'reported_units' => $allocReportedUnits,
                        'base_units' => $allocBaseUnits,
                    ];
                }
            } else {
                // Automatic allocation via FEFO
                if ($totalBatches > 0) {
                    $formSelect = $hasBatchFormCols ? ', sealed_quantity, loose_quantity' : '';
                    $fefoStmt = $this->pdo->prepare(
                        "SELECT batch_id, remaining_quantity{$formSelect}, expiration_date, date_received
                         FROM product_batches
                         WHERE product_id = ? AND remaining_quantity > 0
                         ORDER BY
                            CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END,
                            expiration_date ASC,
                            date_received ASC,
                            batch_id ASC{$this->forUpdate()}"
                    );
                    $fefoStmt->execute([$productId]);
                    $availableBatches = $fefoStmt->fetchAll(PDO::FETCH_ASSOC);

                    $needed = $reportedQty;
                    foreach ($availableBatches as $batch) {
                        if ($needed <= 0) {
                            break;
                        }
                        if ($form === 'pack') {
                            $avail = (int)($batch['sealed_quantity'] ?? 0);
                            $take = min($needed, $avail);
                            if ($take > 0) {
                                $finalAllocations[(int)$batch['batch_id']] = [
                                    'reported_units' => $take,
                                    'base_units' => $take * ($normalizedPackSize ?? 1),
                                ];
                                $needed -= $take;
                            }
                        } else {
                            if ($hasPack && $hasBatchFormCols) {
                                $avail = (int)($batch['loose_quantity'] ?? 0);
                            } else {
                                $avail = (int)$batch['remaining_quantity'];
                            }
                            $take = min($needed, $avail);
                            if ($take > 0) {
                                $finalAllocations[(int)$batch['batch_id']] = [
                                    'reported_units' => $take,
                                    'base_units' => $take,
                                ];
                                $needed -= $take;
                            }
                        }
                    }

                    if ($needed > 0) {
                        if ($form === 'pack') {
                            throw new RuntimeException(
                                "Insufficient sealed pack stock available for product #{$productId} ({$needed} packs unallocated). Approval refused; inventory unchanged."
                            );
                        } elseif ($hasPack) {
                            throw new RuntimeException(
                                "Insufficient loose stock available for product #{$productId} ({$needed} units unallocated). Approval refused; inventory unchanged."
                            );
                        } else {
                            throw new RuntimeException(
                                "Insufficient batch stock available for product #{$productId} ({$needed} units unallocated). Approval refused; inventory unchanged."
                            );
                        }
                    }
                }
            }

            // Execute deductions on product_batches
            if (!empty($finalAllocations)) {
                $hasSibTable = $this->hasTable('stock_issue_batches');
                $hasSibCols = $hasSibTable && $this->hasColumn('stock_issue_batches', 'form');

                if ($form === 'pack') {
                    $deductBatchStmt = $this->pdo->prepare(
                        'UPDATE product_batches
                         SET sealed_quantity = sealed_quantity - ?,
                             remaining_quantity = remaining_quantity - ?
                         WHERE batch_id = ? AND sealed_quantity >= ? AND remaining_quantity >= ?'
                    );
                } else {
                    if ($hasPack && $hasBatchFormCols) {
                        $deductBatchStmt = $this->pdo->prepare(
                            'UPDATE product_batches
                             SET loose_quantity = loose_quantity - ?,
                                 remaining_quantity = remaining_quantity - ?
                             WHERE batch_id = ? AND loose_quantity >= ? AND remaining_quantity >= ?'
                        );
                    } else {
                        $deductBatchStmt = $this->pdo->prepare(
                            'UPDATE product_batches
                             SET remaining_quantity = remaining_quantity - ?
                             WHERE batch_id = ? AND remaining_quantity >= ?'
                        );
                    }
                }

                $recordBatchStmt = null;
                if ($hasSibTable && $adjustmentId !== null) {
                    if ($hasSibCols) {
                        $recordBatchStmt = $this->pdo->prepare(
                            'INSERT INTO stock_issue_batches (adjustment_id, batch_id, quantity, form, reported_qty)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                    } else {
                        $recordBatchStmt = $this->pdo->prepare(
                            'INSERT INTO stock_issue_batches (adjustment_id, batch_id, quantity)
                             VALUES (?, ?, ?)'
                        );
                    }
                }

                // Deterministic execution order
                ksort($finalAllocations, SORT_NUMERIC);

                foreach ($finalAllocations as $batchId => $alloc) {
                    $repUnits = $alloc['reported_units'];
                    $bUnits = $alloc['base_units'];
                    if ($form === 'pack') {
                        $deductBatchStmt->execute([$repUnits, $bUnits, $batchId, $repUnits, $bUnits]);
                    } else {
                        if ($hasPack && $hasBatchFormCols) {
                            $deductBatchStmt->execute([$repUnits, $bUnits, $batchId, $repUnits, $bUnits]);
                        } else {
                            $deductBatchStmt->execute([$bUnits, $batchId, $bUnits]);
                        }
                    }
                    if ($deductBatchStmt->rowCount() === 0) {
                        throw new RuntimeException(
                            "Batch #{$batchId} stock changed while reviewing. Approval refused; inventory unchanged."
                        );
                    }

                    if ($recordBatchStmt !== null) {
                        if ($hasSibCols) {
                            $recordBatchStmt->execute([$adjustmentId, $batchId, $bUnits, $form, $repUnits]);
                        } else {
                            $recordBatchStmt->execute([$adjustmentId, $batchId, $bUnits]);
                        }
                    }
                }
            }

            // Deduct total stock from inventory
            $deductInvStmt = $this->pdo->prepare(
                'UPDATE inventory SET quantity_on_hand = quantity_on_hand - ?
                 WHERE product_id = ? AND quantity_on_hand >= ?'
            );
            $deductInvStmt->execute([$baseUnitQty, $productId, $baseUnitQty]);
            if ($deductInvStmt->rowCount() === 0) {
                throw new RuntimeException('Stock changed while reviewing. Approval refused; inventory unchanged.');
            }

            // Retrieve updated stock on hand
            $postQohStmt = $this->pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = ?');
            $postQohStmt->execute([$productId]);
            $newQoh = (int)$postQohStmt->fetchColumn();

            // Core Invariant Check: stock on hand strictly equals sum of remaining batch quantities
            if ($hasBatchesTable) {
                $postBatchSumStmt = $this->pdo->prepare(
                    'SELECT COALESCE(SUM(remaining_quantity), 0) FROM product_batches WHERE product_id = ?'
                );
                $postBatchSumStmt->execute([$productId]);
                $postBatchSum = (int)$postBatchSumStmt->fetchColumn();
                if ($newQoh !== $postBatchSum) {
                    throw new RuntimeException(
                        "Invariant violation: post-mutation stock on hand ({$newQoh}) does not equal remaining batch sum ({$postBatchSum}) for product #{$productId}."
                    );
                }
            }

            // Record movement in stock_movements
            if ($this->hasTable('stock_movements')) {
                $hasAdjCol = $this->hasColumn('stock_movements', 'adjustment_id');
                if ($hasAdjCol) {
                    $mvStmt = $this->pdo->prepare(
                        'INSERT INTO stock_movements (product_id, change_qty, reason, moved_by, adjustment_id)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $mvStmt->execute([$productId, -$baseUnitQty, $movementReason, $actorId > 0 ? $actorId : null, $adjustmentId]);
                } else {
                    $mvStmt = $this->pdo->prepare(
                        'INSERT INTO stock_movements (product_id, change_qty, reason, moved_by)
                         VALUES (?, ?, ?, ?)'
                    );
                    $mvStmt->execute([$productId, -$baseUnitQty, $movementReason, $actorId > 0 ? $actorId : null]);
                }
            }

            if (!$isExternalTx) {
                $this->pdo->commit();
            }

            return [
                'available_before' => $available,
                'quantity_on_hand' => $newQoh,
                'base_units' => $baseUnitQty,
                'reported_qty' => $reportedQty,
                'form' => $form,
                'allocations' => $finalAllocations,
            ];
        } catch (Throwable $e) {
            if (!$isExternalTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Reconcile measured counts inside the enclosing approval transaction. */
    public function reconcileCount(array $count, int $userId): void
    {
        $this->requireTransaction();
        $countId = (int)$count['count_id'];
        $this->fiscalPeriodGuard->assertOpenForDate($count['counted_at'], 'inventory_counts', 'inventory count');
        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'inventory adjustment');
        $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');
        $productId = (int)$count['product_id'];
        $differenceQty = (int)$count['difference_qty'];
        $diffSealedTotal = (int)($count['difference_sealed_qty'] ?? 0);
        $diffLooseTotal = (int)($count['difference_loose_qty'] ?? 0);

        $hasPack = false;
        if ($this->hasColumn('products', 'pack_size')) {
            $packStmt = $this->pdo->prepare('SELECT pack_size FROM products WHERE product_id = ?');
            $packStmt->execute([$productId]);
            $hasPack = (int)$packStmt->fetchColumn() > 1;
        }

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
        $this->lockBatches($productId);
        if ($currentSystemQty !== (int)$count['system_quantity']) {
            throw new RuntimeException('Stock has changed since this count was recorded. Please record a fresh count before approval.');
        }

        $cbStmt = $this->pdo->prepare(
            "SELECT * FROM inventory_count_batches WHERE count_id = ? ORDER BY batch_id ASC, count_batch_id ASC{$this->forUpdate()}"
        );
        $cbStmt->execute([$countId]);
        $countBatches = $cbStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasBatchTable = $this->hasColumn('product_batches', 'batch_id');
        $hasBatchFormCols = $this->hasColumn('product_batches', 'sealed_quantity');

        if ($hasBatchFormCols) {
            $batchUpdateStmt = $this->pdo->prepare(
                "UPDATE product_batches
                 SET remaining_quantity = ?, sealed_quantity = ?, loose_quantity = ?
                 WHERE batch_id = ?"
            );
            $batchInsertStmt = $this->pdo->prepare(
                "INSERT INTO product_batches (
                    product_id, receiving_id, batch_number, quantity, remaining_quantity,
                    sealed_quantity, loose_quantity,
                    expiration_date, date_received, supplier, created_at
                ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, NULL, CURRENT_TIMESTAMP)"
            );
        } elseif ($hasBatchTable) {
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
        }

        if (!empty($countBatches)) {
            $countedBatchIds = [];
            foreach ($countBatches as $cb) {
                $batchId = !empty($cb['batch_id']) ? (int)$cb['batch_id'] : null;
                if ($batchId !== null) {
                    $countedBatchIds[] = $batchId;
                    $bFormSelect = $hasBatchFormCols ? ', sealed_quantity, loose_quantity' : '';
                    $pbStmt = $this->pdo->prepare(
                        "SELECT remaining_quantity{$bFormSelect} FROM product_batches WHERE batch_id = ? AND product_id = ?{$this->forUpdate()}"
                    );
                    $pbStmt->execute([$batchId, $productId]);
                    $currentBatch = $pbStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$currentBatch) {
                        throw new RuntimeException('Batch record not found for this count.');
                    }
                    if ((int)$currentBatch['remaining_quantity'] !== (int)$cb['system_quantity']) {
                        throw new RuntimeException('Stock has changed since this count was recorded. Please record a fresh count before approval.');
                    }
                    if ($hasBatchFormCols && array_key_exists('system_sealed_quantity', $cb)) {
                        $curSealed = (int)($currentBatch['sealed_quantity'] ?? 0);
                        $curLoose = (int)($currentBatch['loose_quantity'] ?? 0);
                        if ($curSealed === 0 && $curLoose === 0 && (int)$currentBatch['remaining_quantity'] > 0) {
                            $curLoose = (int)$currentBatch['remaining_quantity'];
                        }
                        if ($curSealed !== (int)$cb['system_sealed_quantity'] || $curLoose !== (int)$cb['system_loose_quantity']) {
                            throw new RuntimeException('Stock form balances have changed since this count was recorded. Please record a fresh count before approval.');
                        }
                    }
                }
            }

            // Zero out any active batches not included in this batch count so batch sum equals quantity_on_hand
            if ($hasBatchTable) {
                $otherStmt = $this->pdo->prepare(
                    "SELECT batch_id FROM product_batches WHERE product_id = ? AND remaining_quantity > 0{$this->forUpdate()}"
                );
                $otherStmt->execute([$productId]);
                foreach ($otherStmt->fetchAll(PDO::FETCH_COLUMN) as $activeId) {
                    if (!in_array((int)$activeId, $countedBatchIds, true)) {
                        if ($hasBatchFormCols) {
                            $batchUpdateStmt->execute([0, 0, 0, (int)$activeId]);
                        } else {
                            $batchUpdateStmt->execute([0, (int)$activeId]);
                        }
                    }
                }
            }

            $hasBatchAdjustments = false;
            foreach ($countBatches as $countBatch) {
                $batchId = !empty($countBatch['batch_id']) ? (int)$countBatch['batch_id'] : null;
                $physicalBatchQty = (int)$countBatch['physical_quantity'];
                $physicalSealed = (int)($countBatch['physical_sealed_quantity'] ?? 0);
                $physicalLoose = (int)($countBatch['physical_loose_quantity'] ?? $physicalBatchQty);
                if ((int)$countBatch['difference_qty'] !== 0
                    || (int)($countBatch['difference_sealed_qty'] ?? 0) !== 0
                    || (int)($countBatch['difference_loose_qty'] ?? 0) !== 0) {
                    $hasBatchAdjustments = true;
                }

                if ($hasBatchFormCols) {
                    if ($batchId !== null) {
                        $batchUpdateStmt->execute([$physicalBatchQty, $physicalSealed, $physicalLoose, $batchId]);
                    } elseif ($physicalBatchQty > 0) {
                        $batchInsertStmt->execute([
                            $productId,
                            $countBatch['batch_number'],
                            $physicalBatchQty,
                            $physicalBatchQty,
                            $physicalSealed,
                            $physicalLoose,
                            $countBatch['expiration_date'],
                        ]);
                    }
                } else {
                    if ($batchId !== null) {
                        $batchUpdateStmt->execute([$physicalBatchQty, $batchId]);
                    } elseif ($physicalBatchQty > 0) {
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
        } elseif ($hasBatchTable) {
            // Reconcile product_batches for top-level / unbatched counts so batch sums match inventory.quantity_on_hand
            $bFormSelect = $hasBatchFormCols ? ', sealed_quantity, loose_quantity' : '';
            $activeStmt = $this->pdo->prepare(
                "SELECT batch_id, remaining_quantity{$bFormSelect}
                 FROM product_batches
                 WHERE product_id = ? AND remaining_quantity > 0
                 ORDER BY (expiration_date IS NULL) ASC, expiration_date ASC, date_received ASC, batch_id ASC{$this->forUpdate()}"
            );
            $activeStmt->execute([$productId]);
            $activeBatches = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($hasBatchFormCols && array_key_exists('system_sealed_quantity', $count) && !empty($activeBatches)) {
                $curSealedSum = 0;
                $curLooseSum = 0;
                foreach ($activeBatches as $ab) {
                    $s = (int)($ab['sealed_quantity'] ?? 0);
                    $l = (int)($ab['loose_quantity'] ?? 0);
                    if ($s === 0 && $l === 0 && (int)$ab['remaining_quantity'] > 0) {
                        $l = (int)$ab['remaining_quantity'];
                    }
                    $curSealedSum += $s;
                    $curLooseSum += $l;
                }
                if ($curSealedSum !== (int)$count['system_sealed_quantity'] || $curLooseSum !== (int)$count['system_loose_quantity']) {
                    throw new RuntimeException('Stock form balances have changed since this count was recorded. Please record a fresh count before approval.');
                }
            }

            $physicalQty = (int)$count['physical_quantity'];
            $physicalSealed = (int)($count['physical_sealed_quantity'] ?? 0);
            $physicalLoose = array_key_exists('physical_loose_quantity', $count) && $count['physical_loose_quantity'] !== null
                ? (int)$count['physical_loose_quantity']
                : $physicalQty;

            if (count($activeBatches) === 1) {
                $targetBatchId = (int)$activeBatches[0]['batch_id'];
                if ($hasBatchFormCols) {
                    $batchUpdateStmt->execute([$physicalQty, $physicalSealed, $physicalLoose, $targetBatchId]);
                } else {
                    $batchUpdateStmt->execute([$physicalQty, $targetBatchId]);
                }
            } elseif (count($activeBatches) > 1 && $hasPack) {
                // For packs, merging several dated batches into one undated
                // COUNT batch would discard their expiration and supplier
                // provenance while also losing the sealed/loose placement.
                // Require the operator to count each batch instead.
                throw new RuntimeException('This product has multiple active batches. Record the count against each batch so its expiration and supplier details are preserved.');
            } else {
                // Piece-only products keep the previous behaviour: merge the
                // product-level count into one undated COUNT batch.
                foreach ($activeBatches as $ab) {
                    if ($hasBatchFormCols) {
                        $batchUpdateStmt->execute([0, 0, 0, (int)$ab['batch_id']]);
                    } else {
                        $batchUpdateStmt->execute([0, (int)$ab['batch_id']]);
                    }
                }
                if ($physicalQty > 0) {
                    if ($hasBatchFormCols) {
                        $batchInsertStmt->execute([
                            $productId,
                            'COUNT-' . $countId,
                            $physicalQty,
                            $physicalQty,
                            $physicalSealed,
                            $physicalLoose,
                            null,
                        ]);
                    } else {
                        $batchInsertStmt->execute([
                            $productId,
                            'COUNT-' . $countId,
                            $physicalQty,
                            $physicalQty,
                            null,
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

        // A same-total correction that only moves pieces between sealed and
        // loose is still a stock change worth attributing; change_qty stays 0
        // and the count's discrepancy reason explains it. 'adjustment' is the
        // only matching value in the stock_movements.reason enum.
        if ($differenceQty !== 0 || !empty($hasBatchAdjustments)
            || $diffSealedTotal !== 0 || $diffLooseTotal !== 0) {
            $this->pdo->prepare(
                "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by)
                 VALUES (?, ?, 'adjustment', ?)"
            )->execute([$productId, $differenceQty, $userId]);
        }
        $this->assertBalanced($productId, (int)$count['physical_quantity']);
    }

    /** Credit only accepted goods; receipt identity and historical batches remain distinct. */
    public function creditReceiving(array $receipt, int $userId): void
    {
        ['productId' => $productId, 'receivingId' => $receivingId, 'acceptedQty' => $acceptedQty,
            'acceptedPacks' => $acceptedPacks, 'acceptedLoose' => $acceptedLoose, 'packSizeUsed' => $packSizeUsed,
            'costPrice' => $costPrice, 'batchNumber' => $batchNumber, 'expirationDate' => $expirationDate,
            'supplier' => $supplier] = $receipt;
        if ($acceptedQty <= 0 || $acceptedQty !== $acceptedPacks * ($packSizeUsed ?? 1) + $acceptedLoose) {
            throw new RuntimeException('Accepted stock form quantities do not match Base Units.');
        }
        $this->fiscalPeriodGuard->assertOpenNow('stock_receiving', 'stock receiving');
        $this->fiscalPeriodGuard->assertOpenNow('purchase_history', 'purchase history');
        $quantity = $this->lockStock($productId);
        if ($quantity + $acceptedQty > 2147483647) {
            throw new RuntimeException('Accepted quantity would exceed the supported stock total. Enter a smaller quantity.');
        }
        $hasBatchPacks = $this->hasColumn('product_batches', 'sealed_quantity');

        $batchCols = ['product_id', 'receiving_id', 'batch_number', 'quantity', 'remaining_quantity'];
        $batchParams = [$productId, $receivingId, $batchNumber !== '' ? $batchNumber : null, $acceptedQty, $acceptedQty];

        if ($hasBatchPacks) {
            $batchCols[] = 'sealed_quantity';
            $batchCols[] = 'loose_quantity';
            $batchParams[] = $acceptedPacks;
            $batchParams[] = $acceptedLoose;
        }
        if ($this->hasColumn('product_batches', 'cost_price')) {
            $batchCols[] = 'cost_price';
            $batchParams[] = $costPrice;
        }
        $batchCols[] = 'expiration_date';
        $batchParams[] = $expirationDate;

        $batchCols[] = 'date_received';

        $batchCols[] = 'supplier';
        $batchParams[] = $supplier !== '' ? $supplier : null;

        $placeholders = [];
        foreach ($batchCols as $col) {
            $placeholders[] = $col === 'date_received' ? 'CURRENT_TIMESTAMP' : '?';
        }

        $batchSql = "INSERT INTO product_batches (" . implode(', ', $batchCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $this->pdo->prepare($batchSql)->execute($batchParams);

        $this->pdo->prepare("UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?")
            ->execute([$acceptedQty, $productId]);
        $this->pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason, moved_by)
                                   VALUES (?, ?, 'purchase', ?)")
            ->execute([$productId, $acceptedQty, $userId]);
        $this->assertBalanced($productId, $quantity + $acceptedQty);
    }

    /** Deduct the selected batch, even when another batch expires sooner. */
    public function deductPullout(array $pullout, int $userId): array
    {
        ['productId' => $productId, 'batchId' => $batchId, 'quantity' => $quantity,
            'pulledPacks' => $pulledPacks, 'pulledLoose' => $pulledLoose, 'packSizeUsed' => $packSizeUsed,
            'pulloutId' => $pulloutId, 'pulledAt' => $pulledAt] = $pullout;
        if ($quantity <= 0 || $pulledPacks < 0 || $pulledLoose < 0
            || $quantity !== $pulledPacks * $packSizeUsed + $pulledLoose) {
            throw new RuntimeException('Pullout stock form quantities do not match Base Units.');
        }
        $this->fiscalPeriodGuard->assertOpenForDate($pulledAt, 'stock_movements', 'supplier pullout');
        $this->fiscalPeriodGuard->assertOpenNow('supplier_pullouts', 'supplier pullout');
        $currentOnHand = $this->lockStock($productId);
        $stmt = $this->pdo->prepare('SELECT * FROM product_batches WHERE batch_id = ? AND product_id = ?');
        $stmt->execute([$batchId, $productId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) throw new RuntimeException('Selected batch does not belong to the product.');
        $remainingQty = (int)$batch['remaining_quantity'];
        $batchSealed = (int)($batch['sealed_quantity'] ?? 0);
        $batchLoose = (int)($batch['loose_quantity'] ?? 0);
        if ($batchSealed === 0 && $batchLoose === 0 && $remainingQty > 0) $batchLoose = $remainingQty;
        if ($quantity > $remainingQty) {
            throw new RuntimeException("Requested pullout quantity ({$quantity}) exceeds batch remaining balance ({$remainingQty}).");
        }
        if ($pulledPacks > $batchSealed) {
            throw new RuntimeException("Requested sealed packs ({$pulledPacks}) exceeds batch sealed pack balance ({$batchSealed}).");
        }
        if ($pulledLoose > $batchLoose) {
            throw new RuntimeException("Requested loose pieces ({$pulledLoose}) exceeds batch loose piece balance ({$batchLoose}).");
        }
        if ($quantity > $currentOnHand) {
            throw new RuntimeException("Requested pullout quantity ({$quantity}) exceeds total inventory on hand ({$currentOnHand}).");
        }
        $newRemainingQty = $remainingQty - $quantity;
        $newBatchSealed = $batchSealed - $pulledPacks;
        $newBatchLoose = $batchLoose - $pulledLoose;
        if ($this->hasColumn('product_batches', 'sealed_quantity')) {
            $this->pdo->prepare('UPDATE product_batches SET remaining_quantity = ?, sealed_quantity = ?, loose_quantity = ? WHERE batch_id = ?')
                ->execute([$newRemainingQty, $newBatchSealed, $newBatchLoose, $batchId]);
        } else {
            $this->pdo->prepare('UPDATE product_batches SET remaining_quantity = ? WHERE batch_id = ?')->execute([$newRemainingQty, $batchId]);
        }
        $this->pdo->prepare('UPDATE inventory SET quantity_on_hand = quantity_on_hand - ? WHERE product_id = ?')->execute([$quantity, $productId]);
        $this->assertBalanced($productId, $currentOnHand - $quantity);
        $this->pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason, moved_by, pullout_id, moved_at)
            VALUES (?, ?, 'supplier_pullout', ?, ?, ?)")->execute([$productId, -$quantity, $userId, $pulloutId, $pulledAt]);
        return ['movement_id' => (int)$this->pdo->lastInsertId(), 'remaining_batch_qty' => $newRemainingQty,
            'remaining_batch_packs' => $newBatchSealed, 'remaining_batch_loose' => $newBatchLoose,
            'new_on_hand' => $currentOnHand - $quantity];
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Inventory mutations require an enclosing workflow transaction.');
        }
    }

    private function lockBatches(int $productId): void
    {
        $stmt = $this->pdo->prepare('SELECT batch_id FROM product_batches WHERE product_id = ? ORDER BY batch_id' . $this->forUpdate());
        $stmt->execute([$productId]);
        $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function lockStock(int $productId): int
    {
        $this->requireTransaction();
        $this->fiscalPeriodGuard->assertOpenNow('inventory', 'inventory mutation');
        $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');
        $stmt = $this->pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = ?' . $this->forUpdate());
        $stmt->execute([$productId]);
        $quantity = $stmt->fetchColumn();
        if ($quantity === false) throw new RuntimeException('Inventory record not found.');
        $this->lockBatches($productId);
        $this->assertBalanced($productId, (int)$quantity);
        return (int)$quantity;
    }

    private function assertBalanced(int $productId, int $quantity): void
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(remaining_quantity), 0) FROM product_batches WHERE product_id = ?');
        $stmt->execute([$productId]);
        $batchTotal = (int)$stmt->fetchColumn();
        if ($quantity !== $batchTotal) {
            throw new RuntimeException("Inventory quantity on hand ({$quantity}) does not match batch total ({$batchTotal}). Record a physical count before changing this stock.");
        }
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function hasTable(string $table): bool
    {
        $cacheKey = "tbl:{$table}";
        if (isset($this->columnCache[$cacheKey])) {
            return $this->columnCache[$cacheKey];
        }

        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} LIMIT 0");
            $stmt->execute();
            $this->columnCache[$cacheKey] = true;
            return true;
        } catch (Throwable) {
            $this->columnCache[$cacheKey] = false;
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $cacheKey = "col:{$table}.{$column}";
        if (isset($this->columnCache[$cacheKey])) {
            return $this->columnCache[$cacheKey];
        }

        try {
            $stmt = $this->pdo->prepare("SELECT {$column} FROM {$table} LIMIT 0");
            $stmt->execute();
            $this->columnCache[$cacheKey] = true;
            return true;
        } catch (Throwable) {
            $this->columnCache[$cacheKey] = false;
            return false;
        }
    }
}

if (!class_exists('BatchInventoryLedger', false)) {
    class_alias(BatchInventoryLedger::class, 'BatchInventoryLedger');
}
