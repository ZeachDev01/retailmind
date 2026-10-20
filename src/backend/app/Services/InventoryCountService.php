<?php
// app/Services/InventoryCountService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
use App\Store\StoreWriteGate;
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/SalesWorkflowService.php';
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
        $hasPackCols = $this->hasColumn('products', 'pack_size');
        $packFields = $hasPackCols ? ', p.pack_size, p.pack_barcode, p.pack_price' : '';

        $stmt = $this->pdo->prepare(
            "SELECT p.product_id, p.sku, p.product_name, i.quantity_on_hand{$packFields}
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
        $packSize = !empty($product['pack_size']) && (int)$product['pack_size'] > 1 ? (int)$product['pack_size'] : null;
        $hasSalesPack = $packSize !== null;

        $hasBatchForms = $this->hasColumn('product_batches', 'sealed_quantity');
        $formCols = $hasBatchForms ? ', sealed_quantity, loose_quantity' : '';

        $batchStmt = $this->pdo->prepare(
            "SELECT batch_id, product_id, receiving_id, batch_number, quantity, remaining_quantity{$formCols},
                    expiration_date, date_received, supplier
             FROM product_batches
             WHERE product_id = ? AND remaining_quantity > 0
             ORDER BY (expiration_date IS NULL) ASC, expiration_date ASC, date_received ASC, batch_id ASC"
        );
        $batchStmt->execute([$productId]);
        $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

        $batchSum = 0;
        $batchSealedSum = 0;
        $batchLooseSum = 0;
        $today = date('Y-m-d');
        foreach ($batches as &$batch) {
            $batch['batch_id'] = (int)$batch['batch_id'];
            $batch['quantity'] = (int)$batch['quantity'];
            $batch['remaining_quantity'] = (int)$batch['remaining_quantity'];
            $batch['is_expired'] = !empty($batch['expiration_date']) && $batch['expiration_date'] < $today;
            $batchSum += $batch['remaining_quantity'];

            $sealed = (int)($batch['sealed_quantity'] ?? 0);
            $loose = (int)($batch['loose_quantity'] ?? 0);
            if ($sealed === 0 && $loose === 0 && $batch['remaining_quantity'] > 0) {
                $loose = $batch['remaining_quantity'];
            }
            $batch['sealed_quantity'] = $sealed;
            $batch['loose_quantity'] = $loose;
            $batchSealedSum += $sealed;
            $batchLooseSum += $loose;
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
            'pack_size' => $packSize,
            'pack_barcode' => $product['pack_barcode'] ?? null,
            'pack_price' => isset($product['pack_price']) && $product['pack_price'] !== null ? (float)$product['pack_price'] : null,
            'has_sales_pack' => $hasSalesPack,
            'batch_sealed_sum' => $batchSealedSum,
            'batch_loose_sum' => $batchLooseSum,
        ];
    }

    public function getCountBatches(int $countId): array
    {
        $packSelect = $this->hasColumn('products', 'pack_size') ? ', p.pack_size AS product_pack_size' : '';
        $stmt = $this->pdo->prepare(
            "SELECT icb.*, pb.batch_number AS original_batch_number, pb.expiration_date AS original_expiration_date{$packSelect}
             FROM inventory_count_batches icb
             JOIN inventory_counts ic ON ic.count_id = icb.count_id
             JOIN products p ON p.product_id = ic.product_id
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

        $packSize = null;
        if ($this->hasColumn('products', 'pack_size')) {
            $prodStmt = $this->pdo->prepare("SELECT pack_size FROM products WHERE product_id = ?");
            $prodStmt->execute([$productId]);
            $rawPack = $prodStmt->fetchColumn();
            $packSize = !empty($rawPack) && (int)$rawPack > 1 ? (int)$rawPack : null;
        }
        $hasPack = $packSize !== null;

        $rawBatches = $data['batches'] ?? null;
        $hasBatches = is_array($rawBatches) && count($rawBatches) > 0;
        $batchEntries = [];
        $totalBatchPhysical = 0;
        $totalBatchSealed = 0;
        $totalBatchLoose = 0;

        $parseNonNegativeInt = static function ($val, string $msg): int {
            $isNonNegativeInt = (is_int($val) && $val >= 0) || (is_string($val) && preg_match('/^\d+$/D', $val));
            if (!$isNonNegativeInt) {
                throw new RuntimeException($msg);
            }
            if ((is_string($val) && (float)$val > 2147483647) || (int)$val > 2147483647) {
                throw new RuntimeException('Physically counted quantity is too large.');
            }
            return (int)$val;
        };

        if ($hasBatches) {
            $seenBatchIds = [];
            foreach ($rawBatches as $b) {
                $bId = !empty($b['batch_id']) ? (int)$b['batch_id'] : null;
                $bSealedRaw = $b['physical_sealed_quantity'] ?? null;
                $bLooseRaw = $b['physical_loose_quantity'] ?? null;
                $bQtyRaw = $b['physical_quantity'] ?? null;

                if ($bId === null && ($bQtyRaw === null || $bQtyRaw === '' || (int)$bQtyRaw <= 0)
                    && ($bSealedRaw === null || $bSealedRaw === '' || (int)$bSealedRaw <= 0)
                    && ($bLooseRaw === null || $bLooseRaw === '' || (int)$bLooseRaw <= 0)) {
                    continue;
                }

                if (($bSealedRaw !== null && $bSealedRaw !== '') || ($bLooseRaw !== null && $bLooseRaw !== '')) {
                    $bSealed = ($bSealedRaw !== null && $bSealedRaw !== '') ? $parseNonNegativeInt($bSealedRaw, 'Batch physically counted quantity must be a non-negative whole number.') : 0;
                    $bLoose = ($bLooseRaw !== null && $bLooseRaw !== '') ? $parseNonNegativeInt($bLooseRaw, 'Batch physically counted quantity must be a non-negative whole number.') : 0;
                    if (!$hasPack && $bSealed > 0) {
                        throw new RuntimeException('Cannot record sealed packs for a product without a Sales Pack configured.');
                    }
                    if ($hasPack && (((float)$bSealed * (float)$packSize) + (float)$bLoose) > 2147483647) {
                        throw new RuntimeException('Physically counted quantity is too large.');
                    }
                    $bQty = $hasPack ? ($bSealed * $packSize) + $bLoose : $bLoose;
                    if ($bQtyRaw !== null && $bQtyRaw !== '') {
                        $givenQty = $parseNonNegativeInt($bQtyRaw, 'Batch physically counted quantity must be a non-negative whole number.');
                        if ($givenQty !== $bQty) {
                            throw new RuntimeException('Batch total base units does not match sum of sealed and loose counts.');
                        }
                    }
                } else {
                    $bQty = $parseNonNegativeInt($bQtyRaw, 'Batch physically counted quantity must be a non-negative whole number.');
                    $bSealed = 0;
                    $bLoose = $bQty;
                }

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
                    'physical_sealed_quantity' => $bSealed,
                    'physical_loose_quantity' => $bLoose,
                    'expiration_date' => $bExp,
                    'batch_number' => $bNum,
                    'notes' => $bNotes,
                ];
                if (((float)$totalBatchPhysical + (float)$bQty) > 2147483647) {
                    throw new RuntimeException('Physically counted quantity is too large.');
                }
                $totalBatchPhysical += $bQty;
                $totalBatchSealed += $bSealed;
                $totalBatchLoose += $bLoose;
            }

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
            $physicalSealed = $totalBatchSealed;
            $physicalLoose = $totalBatchLoose;
        } elseif (isset($data['physical_sealed_quantity']) || isset($data['physical_loose_quantity'])) {
            $physicalSealed = isset($data['physical_sealed_quantity']) && $data['physical_sealed_quantity'] !== ''
                ? $parseNonNegativeInt($data['physical_sealed_quantity'], 'Physically counted quantity must be a non-negative whole number.') : 0;
            $physicalLoose = isset($data['physical_loose_quantity']) && $data['physical_loose_quantity'] !== ''
                ? $parseNonNegativeInt($data['physical_loose_quantity'], 'Physically counted quantity must be a non-negative whole number.') : 0;
            if (!$hasPack && $physicalSealed > 0) {
                throw new RuntimeException('Cannot record sealed packs for a product without a Sales Pack configured.');
            }
            if ($hasPack && (((float)$physicalSealed * (float)$packSize) + (float)$physicalLoose) > 2147483647) {
                throw new RuntimeException('Physically counted quantity is too large.');
            }
            $physicalQty = $hasPack ? ($physicalSealed * $packSize) + $physicalLoose : $physicalLoose;
        } else {
            if ($hasPack) {
                // A total cannot prove a sealed pack exists; assuming it is all
                // loose would reinterpret unopened packs without the operator
                // saying so. Do not divide by pack size either.
                throw new RuntimeException('Enter the counted sealed packs and loose pieces separately for this product; a total alone cannot show sealed-pack availability.');
            }
            $physicalQty = $parseNonNegativeInt($data['physical_quantity'] ?? null, 'Physically counted quantity must be a non-negative whole number.');
            $physicalSealed = 0;
            $physicalLoose = $physicalQty;
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

            // Compute system sealed and loose sums
            $systemSealedTotal = 0;
            $systemLooseTotal = 0;
            if ($this->hasColumn('product_batches', 'sealed_quantity')) {
                $batchSumStmt = $this->pdo->prepare("SELECT remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE product_id = ? AND remaining_quantity > 0");
                $batchSumStmt->execute([$productId]);
                while ($bRow = $batchSumStmt->fetch(PDO::FETCH_ASSOC)) {
                    $s = (int)($bRow['sealed_quantity'] ?? 0);
                    $l = (int)($bRow['loose_quantity'] ?? 0);
                    if ($s === 0 && $l === 0 && (int)$bRow['remaining_quantity'] > 0) {
                        $l = (int)$bRow['remaining_quantity'];
                    }
                    $systemSealedTotal += $s;
                    $systemLooseTotal += $l;
                }
            } else {
                $systemLooseTotal = $systemQty;
            }

            $diffSealedTotal = $physicalSealed - $systemSealedTotal;
            $diffLooseTotal = $physicalLoose - $systemLooseTotal;

            $hasCountForms = $this->hasColumn('inventory_counts', 'physical_sealed_quantity');
            if ($hasCountForms) {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO inventory_counts (
                        product_id, system_quantity, physical_quantity, difference_qty,
                        system_sealed_quantity, system_loose_quantity,
                        physical_sealed_quantity, physical_loose_quantity,
                        difference_sealed_qty, difference_loose_qty,
                        discrepancy_reason, counted_by, counted_at, status, related_adjustment_id
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'pending', ?)"
                );
                $stmt->execute([
                    $productId,
                    $systemQty,
                    $physicalQty,
                    $differenceQty,
                    $systemSealedTotal,
                    $systemLooseTotal,
                    $physicalSealed,
                    $physicalLoose,
                    $diffSealedTotal,
                    $diffLooseTotal,
                    $reason,
                    $userId,
                    $relatedAdjustmentId > 0 ? $relatedAdjustmentId : null,
                ]);
            } else {
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
            }

            $countId = (int)$this->pdo->lastInsertId();

            if (count($batchEntries) > 0) {
                $hasCbForms = $this->hasColumn('inventory_count_batches', 'physical_sealed_quantity');
                if ($hasCbForms) {
                    $batchInsertStmt = $this->pdo->prepare(
                        "INSERT INTO inventory_count_batches (
                            count_id, batch_id, system_quantity, physical_quantity, difference_qty,
                            system_sealed_quantity, system_loose_quantity,
                            physical_sealed_quantity, physical_loose_quantity,
                            difference_sealed_qty, difference_loose_qty,
                            expiration_date, batch_number, notes
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                } else {
                    $batchInsertStmt = $this->pdo->prepare(
                        "INSERT INTO inventory_count_batches (
                            count_id, batch_id, system_quantity, physical_quantity, difference_qty,
                            expiration_date, batch_number, notes
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                }

                $hasBatchCols = $this->hasColumn('product_batches', 'sealed_quantity');
                $batchFormSelect = $hasBatchCols ? ', sealed_quantity, loose_quantity' : '';
                $batchCheckStmt = $this->pdo->prepare(
                    "SELECT remaining_quantity, expiration_date, batch_number{$batchFormSelect}
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
                        $batchSystemSealed = (int)($productBatch['sealed_quantity'] ?? 0);
                        $batchSystemLoose = (int)($productBatch['loose_quantity'] ?? 0);
                        if ($batchSystemSealed === 0 && $batchSystemLoose === 0 && $batchSystemQty > 0) {
                            $batchSystemLoose = $batchSystemQty;
                        }
                        $batchExp = $productBatch['expiration_date'];
                        $batchNum = $productBatch['batch_number'];
                    } else {
                        $batchSystemQty = 0;
                        $batchSystemSealed = 0;
                        $batchSystemLoose = 0;
                        $batchExp = $entry['expiration_date'];
                        $batchNum = $entry['batch_number'];
                    }

                    $diff = $entry['physical_quantity'] - $batchSystemQty;
                    $diffSealed = $entry['physical_sealed_quantity'] - $batchSystemSealed;
                    $diffLoose = $entry['physical_loose_quantity'] - $batchSystemLoose;

                    if ($hasCbForms) {
                        $batchInsertStmt->execute([
                            $countId,
                            $entryBatchId,
                            $batchSystemQty,
                            $entry['physical_quantity'],
                            $diff,
                            $batchSystemSealed,
                            $batchSystemLoose,
                            $entry['physical_sealed_quantity'],
                            $entry['physical_loose_quantity'],
                            $diffSealed,
                            $diffLoose,
                            $batchExp,
                            $batchNum,
                            $entry['notes'],
                        ]);
                    } else {
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
                } elseif (count($activeBatches) > 1) {
                    // Merging several dated batches into one undated COUNT batch
                    // would discard their expiration and supplier provenance.
                    // Require the operator to count each batch instead.
                    throw new RuntimeException('This product has multiple active batches. Record the count against each batch so its expiration and supplier details are preserved.');
                } else {
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
        $hasPackCol = $this->hasColumn('products', 'pack_size');
        $packSelect = $hasPackCol ? ', p.pack_size AS product_pack_size' : '';
        $stmt = $this->pdo->prepare(
            "SELECT ic.*, p.sku, p.product_name{$packSelect},
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

    private function hasColumn(string $table, string $column): bool
    {
        return isset(SalesWorkflowService::getTableColumns($this->pdo, $table)[$column]);
    }
}
