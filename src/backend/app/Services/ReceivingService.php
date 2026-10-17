<?php
// app/Services/ReceivingService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
use App\Store\StoreWriteGate;
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../../includes/functions.php';

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
        ];
        $this->fiscalPeriodGuard->assertOpenNow('stock_receiving', 'stock receiving');
        // Serialize receipts before the fail-fast Store gate; overlapping receipts can both finish.
        $lockSql = "CONCAT('receiving:', LEFT(SHA2(DATABASE(), 256), 48))";
        if ((int)$this->pdo->query("SELECT GET_LOCK({$lockSql}, 10)")->fetchColumn() !== 1) {
            throw new RuntimeException('Another receiving entry is still saving. Wait a moment, then try again.');
        }
        try {
            StoreWriteGate::begin($this->pdo);
            $groupStmt = $this->pdo->prepare("INSERT INTO stock_receiving_groups (received_by, supplier, invoice_number, notes) VALUES (?, ?, ?, ?)");
            $groupStmt->execute([
                $userId,
                $shared['supplier'] !== '' ? $shared['supplier'] : null,
                $shared['invoice_number'] !== '' ? $shared['invoice_number'] : null,
                !empty($entry['notes']) ? trim((string)$entry['notes']) : null,
            ]);
            $groupId = (int)$this->pdo->lastInsertId();

            $results = [];
            foreach ($lines as $index => $line) {
                try {
                    $results[] = $this->receiveLine(array_replace($line, $shared, ['group_id' => $groupId]), $userId);
                } catch (RuntimeException $e) {
                    if ($e instanceof PDOException) {
                        error_log((string)$e);
                        throw new RuntimeException('Line ' . ($index + 1) . ' could not be saved. Nothing was recorded. Check the product details and try again.', 0, $e);
                    }
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
        $receivedPackages = $this->wholeNumber(($data['received_packages'] ?? '') === '' && $quantityMode === 'units' ? 0 : ($data['received_packages'] ?? 0), 'Package quantity');
        $receivedQtyInput = $this->wholeNumber(($data['received_qty'] ?? '') === '' && $quantityMode === 'packages' ? 0 : ($data['received_qty'] ?? 0), 'Received quantity');
        $damagedQty = $this->wholeNumber($data['damaged_qty'] ?? 0, 'Damaged quantity');
        $receivedQty = $receivedQtyInput;
        $acceptedQty = 0;
        $unitsPerPackageUsed = 1;
        $costInput = $this->text($data['cost_price'] ?? '', 'Purchase cost', 20);
        if ($costInput !== '' && !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $costInput)) {
            throw new RuntimeException('Purchase cost must be a non-negative amount with at most two decimal places.');
        }
        $costPrice = $costInput === '' ? null : (float)$costInput;
        $supplier = $this->text($data['supplier'] ?? '', 'Shopping store', 150);
        $invoiceNumber = $this->text($data['invoice_number'] ?? '', 'Receipt reference', 100);
        $batchNumber = $this->text($data['batch_number'] ?? '', 'Batch number', 100);
        $expirationDate = validate_calendar_date($data['expiration_date'] ?? null);
        $discrepancyType = $data['discrepancy_type'] ?? 'none';
        $discrepancyQty = $this->wholeNumber($data['discrepancy_qty'] ?? 0, 'Discrepancy quantity');
        $discrepancyNotes = $this->text($data['discrepancy_notes'] ?? '', 'Discrepancy details', 65535);
        $notes = $this->text($data['notes'] ?? '', 'Notes', 65535);
        if ($productId <= 0) {
            throw new RuntimeException('Product is required.');
        }
        if (!in_array($discrepancyType, ['none', 'short', 'over', 'damaged', 'documentation', 'other'], true)) {
            throw new RuntimeException('Invalid delivery discrepancy type.');
        }

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

        if ($damagedQty > 0 && $discrepancyType === 'none') {
            $discrepancyType = 'damaged';
            $discrepancyQty = $damagedQty;
        }

        $totalCost = $costPrice !== null ? $costPrice * $acceptedQty : 0;
        if ($totalCost > 99999999.99) {
            throw new RuntimeException('Total purchase cost is too large.');
        }

        $groupId = isset($data['group_id']) && (int)$data['group_id'] > 0 ? (int)$data['group_id'] : null;

        $stmt = $this->pdo->prepare("INSERT INTO stock_receiving (
                                   group_id, product_id, received_qty, received_packages, units_per_package_used, accepted_qty, damaged_qty, cost_price, total_cost, received_by,
                                   supplier, invoice_number,
                                   batch_number, expiration_date, discrepancy_type, discrepancy_qty,
                                   discrepancy_notes, notes
                               ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $groupId, $productId, $receivedQty, $receivedPackages, $unitsPerPackageUsed, $acceptedQty, $damagedQty,
            $costPrice, $totalCost, $userId,
            $supplier !== '' ? $supplier : null,
            $invoiceNumber !== '' ? $invoiceNumber : null, $batchNumber !== '' ? $batchNumber : null,
            $expirationDate, $discrepancyType, $discrepancyQty,
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
                $expirationDate,
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
                'cancelled',
                "Receiving #{$receivingId}: excluded from accepted stock; no stock deduction required."]);
        }

        $recordingStmt = $this->pdo->prepare('SELECT sr.received_at, u.full_name FROM stock_receiving sr JOIN users u ON u.user_id = sr.received_by WHERE sr.receiving_id = ?');
        $recordingStmt->execute([$receivingId]);
        $recording = $recordingStmt->fetch(PDO::FETCH_ASSOC);
        return [
            'group_id' => $groupId,
            'product_name' => $product['product_name'],
            'recorded_by' => $recording['full_name'],
            'received_at' => $recording['received_at'],
            'receiving_id' => $receivingId,
            'received_qty' => $receivedQty,
            'accepted_qty' => $acceptedQty,
            'damaged_qty' => $damagedQty,
            'received_packages' => $receivedPackages,
            'units_per_package_used' => $unitsPerPackageUsed,
        ];
    }

    public function getActiveProducts(): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $stmt = $this->pdo->prepare("SELECT p.product_id, p.sku, p.product_name, p.cost_price, p.units_per_package, p.base_unit, p.receiving_unit, p.category_id, c.category_name, p.barcode, p.case_barcode, p.variant_label, p.product_image, COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand, p.reorder_level, p.safety_stock FROM products p LEFT JOIN categories c ON p.category_id = c.category_id LEFT JOIN inventory i ON p.product_id = i.product_id WHERE p.status = 'active'{$scopeSql} ORDER BY p.product_name");
        $stmt->execute($scopeParams);
        return $stmt->fetchAll();
    }

    public function getReceivingHistory(?int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT sr.receiving_id, sr.group_id, sr.received_qty, sr.accepted_qty, sr.damaged_qty,
                               sr.cost_price, sr.received_packages, sr.units_per_package_used, sr.batch_number, sr.expiration_date, sr.discrepancy_type,
                               sr.discrepancy_qty, sr.received_at, sr.supplier, sr.invoice_number,
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

    /** Returns 20 complete receipts plus one next-page probe. */
    public function getGroupedReceivingHistory(?int $userId = null, int $page = 1, string $search = ''): array
    {
        $offset = (min(100000, max(1, $page)) - 1) * 20;
        $from = 'FROM stock_receiving sr
            LEFT JOIN stock_receiving_groups srg ON srg.group_id = sr.group_id
            JOIN products p ON sr.product_id = p.product_id
            JOIN users u ON sr.received_by = u.user_id';
        $key = "CASE WHEN sr.group_id IS NULL THEN CONCAT('r_', sr.receiving_id) ELSE CONCAT('g_', sr.group_id) END";
        $stmt = $this->pdo->prepare("SELECT
                sr.receiving_id, sr.group_id, sr.product_id, sr.received_qty, sr.received_packages,
                sr.units_per_package_used, sr.accepted_qty, sr.damaged_qty,
                sr.cost_price, sr.total_cost, sr.received_by, sr.received_at,
                sr.supplier, sr.invoice_number, sr.batch_number, sr.expiration_date,
                sr.discrepancy_type, sr.discrepancy_qty, sr.discrepancy_notes, sr.notes,
                p.sku, p.product_name,
                srg.group_id AS srg_group_id, srg.received_by AS group_received_by,
                srg.received_at AS group_received_at, srg.supplier AS group_supplier,
                srg.invoice_number AS group_invoice, srg.notes AS group_notes,
                u.full_name AS recorded_by
            {$from}
            JOIN (
                SELECT {$key} AS entry_key
                {$from}
                WHERE (? IS NULL OR sr.received_by = ?)
                GROUP BY entry_key
                HAVING MAX(LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', p.product_name, p.sku,
                    COALESCE(srg.supplier, sr.supplier), COALESCE(srg.invoice_number, sr.invoice_number),
                    u.full_name, sr.received_at, sr.batch_number, sr.discrepancy_notes, sr.notes, srg.notes)))) > 0
                ORDER BY MAX(COALESCE(srg.received_at, sr.received_at)) DESC,
                    MAX(COALESCE(sr.group_id, sr.receiving_id)) DESC, entry_key DESC
                LIMIT 21 OFFSET {$offset}
            ) history_page ON history_page.entry_key = {$key}
            WHERE (? IS NULL OR sr.received_by = ?)
            ORDER BY COALESCE(srg.received_at, sr.received_at) DESC, COALESCE(sr.group_id, sr.receiving_id) DESC,
                history_page.entry_key DESC, sr.receiving_id ASC");
        // One extra complete receipt lets the page offer Next without a separate count query.
        $stmt->execute([$userId, $userId, $search, $userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $row) {
            $hasGroup = $row['group_id'] !== null;
            $groupKey = $hasGroup ? 'g_' . (int)$row['group_id'] : 'r_' . (int)$row['receiving_id'];
            $displayId = $hasGroup ? (int)$row['group_id'] : (int)$row['receiving_id'];
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'entry_key' => $groupKey,
                    'group_id' => $displayId,
                    'has_explicit_group' => $hasGroup,
                    'recorded_by' => $row['recorded_by'],
                    'received_by' => (int)$row['received_by'],
                    'received_at' => $row['group_received_at'] ?? $row['received_at'],
                    'supplier' => $row['group_supplier'] ?? $row['supplier'],
                    'invoice_number' => $row['group_invoice'] ?? $row['invoice_number'],
                    'notes' => $row['group_notes'] ?? $row['notes'],
                    'total_received_qty' => 0,
                    'total_accepted_qty' => 0,
                    'total_damaged_qty' => 0,
                    'lines' => [],
                ];
            }
            $groups[$groupKey]['total_received_qty'] += (int)$row['received_qty'];
            $groups[$groupKey]['total_accepted_qty'] += (int)$row['accepted_qty'];
            $groups[$groupKey]['total_damaged_qty'] += (int)$row['damaged_qty'];
            $groups[$groupKey]['lines'][] = [
                'receiving_id' => (int)$row['receiving_id'],
                'group_id' => $row['group_id'] !== null ? (int)$row['group_id'] : null,
                'product_id' => (int)$row['product_id'],
                'product_name' => $row['product_name'],
                'sku' => $row['sku'],
                'received_qty' => (int)$row['received_qty'],
                'received_packages' => (int)$row['received_packages'],
                'units_per_package_used' => (int)$row['units_per_package_used'],
                'quantity_mode' => (int)$row['received_packages'] > 0 ? 'packages' : 'units',
                'accepted_qty' => (int)$row['accepted_qty'],
                'damaged_qty' => (int)$row['damaged_qty'],
                'cost_price' => $row['cost_price'] !== null ? (float)$row['cost_price'] : null,
                'total_cost' => (float)$row['total_cost'],
                'batch_number' => $row['batch_number'],
                'expiration_date' => $row['expiration_date'],
                'discrepancy_type' => $row['discrepancy_type'],
                'discrepancy_qty' => (int)$row['discrepancy_qty'],
                'discrepancy_notes' => $row['discrepancy_notes'],
                'notes' => $row['notes'],
                'supplier' => $row['supplier'],
                'invoice_number' => $row['invoice_number'],
                'received_at' => $row['received_at'],
            ];
        }

        return array_values($groups);
    }

    private function wholeNumber($value, string $label): int
    {
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
}
