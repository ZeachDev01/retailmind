<?php
// app/Services/ReceivingService.php

require_once __DIR__ . '/NotificationService.php';
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
        $productId = (int)($data['product_id'] ?? 0);
        $quantityMode = ($data['quantity_mode'] ?? 'units') === 'packages' ? 'packages' : 'units';
        $receivedPackages = max(0, (int)($data['received_packages'] ?? 0));
        $receivedQtyInput = max(0, (int)($data['received_qty'] ?? 0));
        $damagedQty = max(0, (int)($data['damaged_qty'] ?? 0));
        $purchaseOrderItemId = (int)($data['purchase_order_item_id'] ?? 0);
        $emergencyReason = trim((string)($data['emergency_reason'] ?? ''));
        $receivedQty = $receivedQtyInput;
        $acceptedQty = 0;
        $unitsPerPackageUsed = 1;
        $costPrice = (float)($data['cost_price'] ?? 0);
        $supplier = trim((string)($data['supplier'] ?? ''));
        $poNumber = trim((string)($data['po_number'] ?? ''));
        $invoiceNumber = trim((string)($data['invoice_number'] ?? ''));
        $batchNumber = trim((string)($data['batch_number'] ?? ''));
        $expirationDate = trim((string)($data['expiration_date'] ?? ''));
        $discrepancyType = trim((string)($data['discrepancy_type'] ?? 'none'));
        $discrepancyQty = max(0, (int)($data['discrepancy_qty'] ?? 0));
        $discrepancyNotes = trim((string)($data['discrepancy_notes'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));
        $replenishmentRequestId = (int)($data['replenishment_request_id'] ?? 0);
        $purchaseOrderId = 0;

        if ($productId <= 0) {
            throw new RuntimeException('Product is required.');
        }
        if (!in_array($discrepancyType, ['none', 'short', 'over', 'damaged', 'documentation', 'other'], true)) {
            throw new RuntimeException('Invalid delivery discrepancy type.');
        }

        $this->fiscalPeriodGuard->assertOpenNow('stock_receiving', 'stock receiving');

        $this->pdo->beginTransaction();
        try {
            $remainingBefore = null;
            $requestStatus = null;

            $productStmt = $this->pdo->prepare("SELECT product_id, product_name, units_per_package, cost_price, preferred_supplier FROM products WHERE product_id = ? AND status = 'active' FOR UPDATE");
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new RuntimeException('Active product not found.');
            }
            $unitsPerPackageUsed = max(1, (int)($product['units_per_package'] ?? 1));
            if ($quantityMode === 'packages') {
                if ($receivedPackages <= 0) {
                    throw new RuntimeException('Package quantity must be greater than zero.');
                }
                $receivedQty = $receivedPackages * $unitsPerPackageUsed;
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
                if ($costPrice <= 0) $costPrice = (float)($poItem['unit_cost'] ?? 0);
                $remainingPo = max(0, (int)$poItem['ordered_qty'] - (int)$poItem['received_qty']);
                if ($acceptedQty > $remainingPo && $discrepancyType === 'none') {
                    $discrepancyType = 'over';
                    $discrepancyQty = $acceptedQty - $remainingPo;
                }
            }

            if ($purchaseOrderItemId <= 0 && $replenishmentRequestId <= 0) {
                $roleStmt = $this->pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=?");
                $roleStmt->execute([$userId]);
                $role = (string)$roleStmt->fetchColumn();
                if (!in_array($role, ['admin','super_admin'], true) || $emergencyReason === '') {
                    throw new RuntimeException('Receiving must reference an approved request or purchase order. Administrators may use emergency receiving with a reason.');
                }
                $notes = trim('Emergency receiving: ' . $emergencyReason . ($notes !== '' ? ' | ' . $notes : ''));
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

            $totalCost = $costPrice > 0 ? $costPrice * $acceptedQty : 0;

            $stmt = $this->pdo->prepare("INSERT INTO stock_receiving (
                                       product_id, received_qty, received_packages, units_per_package_used, accepted_qty, damaged_qty, cost_price, total_cost, received_by,
                                       replenishment_request_id, purchase_order_id, purchase_order_item_id, supplier, po_number, invoice_number,
                                       batch_number, expiration_date, discrepancy_type, discrepancy_qty,
                                       discrepancy_notes, notes
                                   ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $productId, $receivedQty, $receivedPackages, $unitsPerPackageUsed, $acceptedQty, $damagedQty,
                $costPrice > 0 ? $costPrice : null, $totalCost, $userId,
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
                if ($costPrice > 0) {
                    $productUpdateParts[] = 'cost_price = ?';
                    $productUpdateParams[] = $costPrice;
                }
                if ($supplier !== '') {
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

                if ($costPrice > 0) {
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
                $this->pdo->prepare(
                    "INSERT INTO inventory_adjustments (product_id, adjustment_qty, adjustment_type, reported_by, reason, status)
                     VALUES (?, ?, 'damaged', ?, ?, 'pending')"
                )->execute([$productId, -$damagedQty, $userId, $damageReason]);
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

            $this->pdo->commit();
            $this->notificationService->checkAndNotifyLowStock();
            $this->notificationService->checkAndNotifyExpiringStock();
            return [
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
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getPendingReplenishmentRequests(): array
    {
        return $this->pdo->query("SELECT rr.request_id, rr.product_id, rr.request_qty, rr.status, p.sku, p.product_name,
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
                                 WHERE rr.status IN ('approved', 'partially_received')
                                 ORDER BY rr.request_date ASC")->fetchAll();
    }

    public function getActiveProducts(): array
    {
        return $this->pdo->query("SELECT product_id, sku, product_name, cost_price, units_per_package, base_unit, receiving_unit FROM products WHERE status = 'active' ORDER BY product_name")->fetchAll();
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

    public function getReceivingHistory(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT sr.receiving_id, sr.received_qty, sr.accepted_qty, sr.damaged_qty,
                               sr.cost_price, sr.received_packages, sr.units_per_package_used, sr.batch_number, sr.expiration_date, sr.discrepancy_type,
                               sr.discrepancy_qty, sr.received_at, sr.supplier, sr.po_number,
                               p.sku, p.product_name, u.full_name
                        FROM stock_receiving sr
                        JOIN products p ON sr.product_id = p.product_id
                        JOIN users u ON sr.received_by = u.user_id
                        WHERE sr.received_by = ?
                        ORDER BY sr.received_at DESC
                        LIMIT 20");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
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
