<?php
// app/Services/ReceivingService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
use App\Store\StoreWriteGate;
require_once __DIR__ . '/FiscalPeriodGuardService.php';

class ReceivingService
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

    public function receiveStock(array $data, int $userId): array
    {
        return $this->receiveShoppingTrip([$data], $data, $userId)[0];
    }

    public function receiveShoppingTrip(array $lines, array $entry, int $userId): array
    {
        if (function_exists('require_capability')) {
            require_capability(App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
        }
        if ($lines === [] || !array_is_list($lines)) {
            throw new RuntimeException('Add at least one product line before saving the shopping entry.');
        }
        $products = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw new RuntimeException('Each product line must contain receiving details.');
            }
            $productId = $this->wholeNumber($line['product_id'] ?? 0, 'Product on line ' . ($index + 1));
            if ($productId <= 0) throw new RuntimeException('Choose a product on line ' . ($index + 1) . '.');
            if (isset($products[$productId])) {
                throw new RuntimeException('Duplicate product on line ' . ($index + 1) . '. Remove the duplicate line or choose another product.');
            }
            $products[$productId] = true;
        }
        $shared = [
            'supplier' => $entry['supplier'] ?? '',
            'invoice_number' => $entry['invoice_number'] ?? '',
            'po_number' => $entry['po_number'] ?? '',
        ];
        $this->fiscalPeriodGuard->assertOpenNow('stock_receiving', 'stock receiving');
        // Serialize receipts before the fail-fast Store gate; overlapping receipts can both finish.
        $lockSql = "CONCAT('receiving:', LEFT(SHA2(DATABASE(), 256), 48))";
        if ((int)$this->pdo->query("SELECT GET_LOCK({$lockSql}, 10)")->fetchColumn() !== 1) {
            throw new RuntimeException('Another receiving entry is still saving. Wait a moment, then try again.');
        }
        try {
            StoreWriteGate::begin($this->pdo);
            $results = [];
            foreach ($lines as $index => $line) {
                try {
                    $results[] = $this->receiveLine(array_replace($line, $shared), $userId);
                } catch (RuntimeException $e) {
                    if ($e instanceof PDOException) throw $e;
                    throw new RuntimeException('Line ' . ($index + 1) . ': ' . $e->getMessage(), 0, $e);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        } finally {
            try {
                $this->pdo->query("SELECT RELEASE_LOCK({$lockSql})");
            } catch (Throwable $e) {
                error_log('Receiving lock release failed: ' . $e->getMessage());
            }
        }
        try {
            $this->notificationService->checkAndNotifyLowStock();
            $this->notificationService->checkAndNotifyExpiringStock();
        } catch (Throwable $e) {
            error_log('Receiving notifications failed: ' . $e->getMessage());
        }
        return $results;
    }

    private function receiveLine(array $data, int $userId): array
    {
        $productId = $this->wholeNumber($data['product_id'] ?? 0, 'Product');
        $quantityMode = $data['quantity_mode'] ?? 'units';
        if (!in_array($quantityMode, ['units', 'packages'], true)) {
            throw new RuntimeException('Choose Base units or Packages / cases.');
        }
        $receivedPackages = $this->wholeNumber($data['received_packages'] ?? 0, 'Package quantity');
        $receivedQtyInput = $this->wholeNumber($data['received_qty'] ?? 0, 'Received quantity');
        $damagedQty = $this->wholeNumber($data['damaged_qty'] ?? 0, 'Damaged quantity');
        $purchaseOrderItemId = $this->wholeNumber($data['purchase_order_item_id'] ?? '', 'Purchase-order line', true);
        $receivedQty = $receivedQtyInput;
        $acceptedQty = 0;
        $unitsPerPackageUsed = 1;
        $costInput = $this->text($data['cost_price'] ?? '', 'Purchase cost', 20);
        if ($costInput !== '' && !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $costInput)) {
            throw new RuntimeException('Purchase cost must be a non-negative amount with at most two decimal places.');
        }
        $costPrice = $costInput === '' ? null : (float)$costInput;
        $supplier = $this->text($data['supplier'] ?? '', 'Supplier / shopping store', 150);
        $poNumber = $this->text($data['po_number'] ?? '', 'PO number', 100);
        $invoiceNumber = $this->text($data['invoice_number'] ?? '', 'Receipt reference', 100);
        $batchNumber = $this->text($data['batch_number'] ?? '', 'Batch number', 100);
        $expirationDate = $this->text($data['expiration_date'] ?? '', 'Expiration date', 10);
        if ($expirationDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expirationDate);
            if (!$date || $date->format('Y-m-d') !== $expirationDate || $expirationDate < '1000-01-01') {
                throw new RuntimeException('Enter a valid expiration date.');
            }
        }
        $discrepancyType = $data['discrepancy_type'] ?? 'none';
        $discrepancyQty = $this->wholeNumber($data['discrepancy_qty'] ?? 0, 'Discrepancy quantity');
        $discrepancyNotes = $this->text($data['discrepancy_notes'] ?? '', 'Discrepancy details', 65535);
        $notes = $this->text($data['notes'] ?? '', 'Notes', 65535);
        $replenishmentRequestId = $this->wholeNumber($data['replenishment_request_id'] ?? '', 'Replenishment request', true);
        $direct = $purchaseOrderItemId === 0 && $replenishmentRequestId === 0;
        $purchaseOrderId = 0;

        if ($productId <= 0) {
            throw new RuntimeException('Product is required.');
        }
        if (!in_array($discrepancyType, ['none', 'short', 'over', 'damaged', 'documentation', 'other'], true)) {
            throw new RuntimeException('Invalid delivery discrepancy type.');
        }

        $remainingBefore = null;
        $requestStatus = null;

        [$scopeSql, $scopeParams] = $this->productScope();
        $productStmt = $this->pdo->prepare("SELECT product_id, product_name, units_per_package, quantity_purchased FROM products p WHERE product_id = ? AND status = 'active'{$scopeSql} FOR UPDATE");
        $productStmt->execute(array_merge([$productId], $scopeParams));
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new RuntimeException('Active product not found.');
        }
        $unitsPerPackageUsed = $quantityMode === 'packages'
            ? $this->wholeNumber($product['units_per_package'] ?? 0, 'Saved pack size') : 1;
        if ($quantityMode === 'packages') {
            if ($unitsPerPackageUsed <= 0) {
                throw new RuntimeException('Set a positive whole-number pack size on the product before receiving packages.');
            }
            if ($receivedPackages <= 0) {
                throw new RuntimeException('Package quantity must be greater than zero.');
            }
            $receivedQty = $receivedPackages * $unitsPerPackageUsed;
            if ($receivedQty > 2147483647) {
                throw new RuntimeException('Resulting unit quantity is too large.');
            }
        } else {
            if ($receivedQtyInput <= 0) {
                throw new RuntimeException('Received quantity must be greater than zero.');
            }
            $receivedQty = $receivedQtyInput;
            $receivedPackages = 0;
        }
        if ($damagedQty > $receivedQty) {
            throw new RuntimeException('Damaged quantity cannot exceed quantity received.');
        }
        $acceptedQty = $receivedQty - $damagedQty;
        $inventoryStmt = $this->pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = ? FOR UPDATE');
        $inventoryStmt->execute([$productId]);
        $currentStock = $inventoryStmt->fetchColumn();
        if ($currentStock === false) {
            throw new RuntimeException('This product has no inventory record. Ask your Administrator to check the product.');
        }
        if ((int)$currentStock + $acceptedQty > 2147483647
            || (int)$product['quantity_purchased'] + $acceptedQty > 2147483647) {
            throw new RuntimeException('Accepted quantity would exceed the supported stock total. Enter a smaller quantity.');
        }
        if ($acceptedQty <= 0 && $damagedQty <= 0) {
            throw new RuntimeException('At least one delivered unit must be accepted or reported damaged.');
        }
        if ($acceptedQty > 0) {
            $this->fiscalPeriodGuard->assertOpenNow('purchase_history', 'purchase history');
            $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');
        }
        if ($damagedQty > 0) {
            $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'inventory adjustment');
        }

        if ($purchaseOrderItemId > 0) {
            $poStmt = $this->pdo->prepare("SELECT poi.*, po.purchase_order_id, po.po_number, po.status AS po_status,
                    s.supplier_name, rr.request_id
                FROM purchase_order_items poi
                JOIN purchase_orders po ON po.purchase_order_id = poi.purchase_order_id
                LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
                LEFT JOIN replenishment_requests rr ON rr.request_id = poi.replenishment_request_id
                WHERE poi.purchase_order_item_id = ? FOR UPDATE");
            $poStmt->execute([$purchaseOrderItemId]);
            $poItem = $poStmt->fetch(PDO::FETCH_ASSOC);
            if (!$poItem || !in_array($poItem['po_status'], ['approved','sent','partially_received'], true)) {
                throw new RuntimeException('Only approved or sent purchase-order items can be received.');
            }
            if ((int)$poItem['product_id'] !== $productId) {
                throw new RuntimeException('Product does not match the selected purchase-order item.');
            }
            $purchaseOrderId = (int)$poItem['purchase_order_id'];
            $replenishmentRequestId = (int)($poItem['replenishment_request_id'] ?? 0);
            $poNumber = (string)$poItem['po_number'];
            if ($supplier === '') $supplier = trim((string)($poItem['supplier_name'] ?? ''));
            if ($costPrice === null) $costPrice = (float)($poItem['unit_cost'] ?? 0);
            $remainingPo = max(0, (int)$poItem['ordered_qty'] - (int)$poItem['received_qty']);
            if ($remainingPo <= 0) {
                throw new RuntimeException('Only purchase-order lines with remaining quantity can be received.');
            }
            if ($acceptedQty > $remainingPo && $discrepancyType === 'none') {
                $discrepancyType = 'over';
                $discrepancyQty = $acceptedQty - $remainingPo;
            }
        }

        if ($replenishmentRequestId > 0) {
            $request = $this->getRequestForReceiving($replenishmentRequestId);
            if (!$request) {
                throw new RuntimeException('Replenishment request not found.');
            }
            if ((int)$request['product_id'] !== $productId) {
                throw new RuntimeException('Selected product does not match the replenishment request.');
            }
            if (!in_array($request['status'], ['approved', 'partially_received'], true)) {
                throw new RuntimeException('Only approved replenishment requests can receive stock.');
            }

            $remainingBefore = max(0, (int)$request['request_qty'] - (int)$request['received_to_date']);
            if ($receivedQty > $remainingBefore && $discrepancyType === 'none') {
                $discrepancyType = 'over';
                $discrepancyQty = $receivedQty - $remainingBefore;
            }
        }

        if ($damagedQty > 0 && $discrepancyType === 'none') {
            $discrepancyType = 'damaged';
            $discrepancyQty = $damagedQty;
        }

        $totalCost = $costPrice !== null ? $costPrice * $acceptedQty : 0;
        if ($totalCost > 99999999.99) {
            throw new RuntimeException('Total purchase cost is too large.');
        }

        $stmt = $this->pdo->prepare("INSERT INTO stock_receiving (
                                   product_id, received_qty, received_packages, units_per_package_used, accepted_qty, damaged_qty, cost_price, total_cost, received_by,
                                   replenishment_request_id, purchase_order_id, purchase_order_item_id, supplier, po_number, invoice_number,
                                   batch_number, expiration_date, discrepancy_type, discrepancy_qty,
                                   discrepancy_notes, notes
                               ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $productId, $receivedQty, $receivedPackages, $unitsPerPackageUsed, $acceptedQty, $damagedQty,
            $costPrice, $totalCost, $userId,
            $replenishmentRequestId > 0 ? $replenishmentRequestId : null,
            $purchaseOrderId > 0 ? $purchaseOrderId : null,
            $purchaseOrderItemId > 0 ? $purchaseOrderItemId : null,
            $supplier !== '' ? $supplier : null, $poNumber !== '' ? $poNumber : null,
            $invoiceNumber !== '' ? $invoiceNumber : null, $batchNumber !== '' ? $batchNumber : null,
            $expirationDate !== '' ? $expirationDate : null, $discrepancyType, $discrepancyQty,
            $discrepancyNotes !== '' ? $discrepancyNotes : null, $notes,
        ]);

        $receivingId = (int)$this->pdo->lastInsertId();
        if ($acceptedQty > 0) {
            $this->pdo->prepare(
                "INSERT INTO product_batches
                    (product_id, receiving_id, batch_number, quantity, remaining_quantity, expiration_date, date_received, supplier)
                 VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
            )->execute([
                $productId,
                $receivingId,
                $batchNumber !== '' ? $batchNumber : null,
                $acceptedQty,
                $acceptedQty,
                $expirationDate !== '' ? $expirationDate : null,
                $supplier !== '' ? $supplier : null,
            ]);

            $this->pdo->prepare("UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?")
                ->execute([$acceptedQty, $productId]);
            $this->pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason, moved_by)
                                       VALUES (?, ?, 'purchase', ?)")
                ->execute([$productId, $acceptedQty, $userId]);

            $productUpdateParts = ['quantity_purchased = quantity_purchased + ?'];
            $productUpdateParams = [$acceptedQty];
            if ($costPrice !== null) {
                $productUpdateParts[] = 'cost_price = ?';
                $productUpdateParams[] = $costPrice;
            }
            if (!$direct && $supplier !== '') {
                $productUpdateParts[] = 'supplier = ?';
                $productUpdateParams[] = $supplier;
                $productUpdateParts[] = 'preferred_supplier = ?';
                $productUpdateParams[] = $supplier;
            }
            if ($expirationDate !== '') {
                $productUpdateParts[] = 'expiration_date = ?';
                $productUpdateParams[] = $expirationDate;
            }
            $productUpdateParams[] = $productId;
            $this->pdo->prepare('UPDATE products SET ' . implode(', ', $productUpdateParts) . ' WHERE product_id = ?')
                ->execute($productUpdateParams);

            if ($costPrice !== null) {
                $this->pdo->prepare(
                    "INSERT INTO purchase_history (product_id, supplier, quantity_purchased, cost_price, total_cost, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$productId, $supplier !== '' ? $supplier : null, $acceptedQty, $costPrice, $totalCost, $userId]);
            }
        }

        if ($damagedQty > 0) {
            $damageReason = 'Damaged upon delivery';
            if ($batchNumber !== '') {
                $damageReason .= " (batch {$batchNumber})";
            }
            if ($discrepancyNotes !== '') {
                $damageReason .= ': ' . $discrepancyNotes;
            }
            // Bought damage was excluded before stock was added; no later deduction is due.
            $this->pdo->prepare(
                "INSERT INTO inventory_adjustments (product_id, adjustment_qty, adjustment_type, reported_by, reason, status, review_notes)
                 VALUES (?, ?, 'damaged', ?, ?, ?, ?)"
            )->execute([$productId, -$damagedQty, $userId, $damageReason,
                $direct ? 'cancelled' : 'pending',
                $direct ? "Receiving #{$receivingId}: excluded from accepted stock; no stock deduction required." : null]);
        }

        if ($purchaseOrderItemId > 0) {
            $this->pdo->prepare("UPDATE purchase_order_items SET received_qty = LEAST(ordered_qty, received_qty + ?) WHERE purchase_order_item_id = ?")
                ->execute([$acceptedQty, $purchaseOrderItemId]);
            $poTotalsStmt = $this->pdo->prepare("SELECT COALESCE(SUM(ordered_qty),0) ordered_qty, COALESCE(SUM(received_qty),0) received_qty FROM purchase_order_items WHERE purchase_order_id=?");
            $poTotalsStmt->execute([$purchaseOrderId]);
            $poTotals = $poTotalsStmt->fetch(PDO::FETCH_ASSOC);
            $poStatus = (int)$poTotals['received_qty'] >= (int)$poTotals['ordered_qty'] ? 'fully_received' : 'partially_received';
            $this->pdo->prepare("UPDATE purchase_orders SET status=? WHERE purchase_order_id=? AND status<>'cancelled'")
                ->execute([$poStatus, $purchaseOrderId]);
        }

        if ($replenishmentRequestId > 0) {
            $requestAfter = $this->getRequestForReceiving($replenishmentRequestId);
            $receivedToDate = (int)($requestAfter['received_to_date'] ?? 0);
            $requestQty = (int)($requestAfter['request_qty'] ?? 0);
            $requestStatus = $receivedToDate >= $requestQty ? 'received' : 'partially_received';

            $this->pdo->prepare("UPDATE replenishment_requests SET status = ? WHERE request_id = ?")
                ->execute([$requestStatus, $replenishmentRequestId]);
        }

        $recordingStmt = $this->pdo->prepare('SELECT sr.received_at, u.full_name FROM stock_receiving sr JOIN users u ON u.user_id = sr.received_by WHERE sr.receiving_id = ?');
        $recordingStmt->execute([$receivingId]);
        $recording = $recordingStmt->fetch(PDO::FETCH_ASSOC);
        return [
            'product_name' => $product['product_name'],
            'recorded_by' => $recording['full_name'],
            'received_at' => $recording['received_at'],
            'receiving_id' => $receivingId,
            'received_qty' => $receivedQty,
            'accepted_qty' => $acceptedQty,
            'damaged_qty' => $damagedQty,
            'received_packages' => $receivedPackages,
            'units_per_package_used' => $unitsPerPackageUsed,
            'purchase_order_id' => $purchaseOrderId ?: null,
            'remaining_qty' => $replenishmentRequestId > 0 ? $this->getRemainingQuantity($replenishmentRequestId) : null,
            'request_status' => $requestStatus,
        ];
    }

    public function getPendingReplenishmentRequests(): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare("SELECT rr.request_id, rr.product_id, rr.request_qty, rr.status, p.sku, p.product_name,
                                        COALESCE(received.received_to_date, 0) AS received_to_date,
                                        GREATEST(rr.request_qty - COALESCE(received.received_to_date, 0), 0) AS remaining_qty
                                 FROM replenishment_requests rr
                                 JOIN products p ON rr.product_id = p.product_id
                                 LEFT JOIN (
                                     SELECT replenishment_request_id, SUM(accepted_qty) AS received_to_date
                                     FROM stock_receiving
                                     WHERE replenishment_request_id IS NOT NULL
                                     GROUP BY replenishment_request_id
                                 ) received ON received.replenishment_request_id = rr.request_id
                                 WHERE rr.status IN ('approved', 'partially_received'){$scopeSql}
                                 ORDER BY rr.request_date ASC");
        $stmt->execute($scopeParams);
        return $stmt->fetchAll();
    }

    public function getActiveProducts(): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare("SELECT product_id, sku, product_name, cost_price, units_per_package, base_unit, receiving_unit FROM products p WHERE status = 'active'{$scopeSql} ORDER BY product_name");
        $stmt->execute($scopeParams);
        return $stmt->fetchAll();
    }

    public function getReceivablePurchaseOrderItems(): array
    {
        return $this->pdo->query("SELECT poi.purchase_order_item_id, poi.purchase_order_id, poi.replenishment_request_id,
                    poi.product_id, poi.ordered_qty, poi.received_qty,
                    GREATEST(poi.ordered_qty-poi.received_qty,0) remaining_qty, poi.unit_cost,
                    po.po_number, po.status, s.supplier_name, p.sku, p.product_name, p.units_per_package,
                    p.base_unit, p.receiving_unit
                FROM purchase_order_items poi JOIN purchase_orders po ON po.purchase_order_id=poi.purchase_order_id
                LEFT JOIN suppliers s ON s.supplier_id=po.supplier_id JOIN products p ON p.product_id=poi.product_id
                WHERE po.status IN ('approved','sent','partially_received') AND poi.received_qty < poi.ordered_qty
                ORDER BY po.created_at, p.product_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getReceivingHistory(?int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT sr.receiving_id, sr.received_qty, sr.accepted_qty, sr.damaged_qty,
                               sr.cost_price, sr.received_packages, sr.units_per_package_used, sr.batch_number, sr.expiration_date, sr.discrepancy_type,
                               sr.discrepancy_qty, sr.received_at, sr.supplier, sr.po_number, sr.invoice_number,
                               p.sku, p.product_name, u.full_name
                        FROM stock_receiving sr
                        JOIN products p ON sr.product_id = p.product_id
                        JOIN users u ON sr.received_by = u.user_id
                        WHERE (? IS NULL OR sr.received_by = ?)
                        ORDER BY sr.received_at DESC, sr.receiving_id DESC
                        LIMIT 20");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();
    }

    private function wholeNumber($value, string $label, bool $optional = false): int
    {
        if ($optional && $value === '') return 0;
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/^\d+$/D', (string)$value)
            || strlen((string)$value) > 10 || (float)$value > 2147483647) {
            throw new RuntimeException("{$label} must be a non-negative whole number within range.");
        }
        return (int)$value;
    }

    private function text($value, string $label, int $maxLength): string
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || strlen((string)$value) > $maxLength) {
            throw new RuntimeException("{$label} must be text no longer than {$maxLength} bytes.");
        }
        return trim((string)$value);
    }

    private function productScope(): array
    {
        if (function_exists('store_product_scope')) {
            return store_product_scope('p');
        }

        return (new App\Store\StoreScope($this->pdo))->productScope('p');
    }

    private function getRequestForReceiving(int $requestId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT rr.*,
                    COALESCE(received.received_to_date, 0) AS received_to_date
             FROM replenishment_requests rr
             LEFT JOIN (
                 SELECT replenishment_request_id, SUM(accepted_qty) AS received_to_date
                 FROM stock_receiving
                 WHERE replenishment_request_id IS NOT NULL
                 GROUP BY replenishment_request_id
             ) received ON received.replenishment_request_id = rr.request_id
             WHERE rr.request_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        return $request ?: null;
    }

    private function getRemainingQuantity(int $requestId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT GREATEST(rr.request_qty - COALESCE(SUM(sr.accepted_qty), 0), 0)
             FROM replenishment_requests rr
             LEFT JOIN stock_receiving sr ON sr.replenishment_request_id = rr.request_id
             WHERE rr.request_id = ?
             GROUP BY rr.request_id, rr.request_qty"
        );
        $stmt->execute([$requestId]);
        return (int)$stmt->fetchColumn();
    }
}
