<?php
require_once __DIR__ . "/InventorySubmission.php";
// app/Services/SupplierPulloutService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/PhilippineTime.php';
require_once __DIR__ . '/SalesWorkflowService.php';
require_once __DIR__ . '/../../includes/functions.php';

use App\Store\StoreWriteGate;
use App\Services\PhilippineTime;

class SupplierPulloutService
{
    private PDO $pdo;
    private FiscalPeriodGuardService $fiscalPeriodGuard;
    private NotificationService $notificationService;
    private ?string $actorRole;

    public function __construct(PDO $pdo, ?FiscalPeriodGuardService $fiscalPeriodGuard = null, ?string $actorRole = null)
    {
        $this->pdo = $pdo;
        $this->fiscalPeriodGuard = $fiscalPeriodGuard ?? new FiscalPeriodGuardService($pdo);
        $this->notificationService = new NotificationService($pdo);
        $this->actorRole = $actorRole;
    }

    public static function formatFormBreakdown(int $packs, int $loose, int $packSize = 1): string
    {
        if ($packs <= 0 && ($packSize <= 1 || $loose <= 0)) {
            return '';
        }
        if ($packs > 0) {
            $parts = [$packs . ' pack' . ($packs === 1 ? '' : 's')];
            if ($loose > 0) {
                $parts[] = $loose . ' loose';
            }
            return implode(' + ', $parts);
        }
        return '0 packs + ' . $loose . ' loose';
    }

    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    private function rowLock(): string
    {
        return $this->isSqlite() ? '' : ' FOR UPDATE';
    }

    private function storeProductScope(): array
    {
        if (function_exists('store_product_scope')) {
            return store_product_scope('p');
        }

        return (new App\Store\StoreScope($this->pdo))->productScope('p');
    }

    private function roleOf(int $userId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.role_name
             FROM users u
             JOIN roles r ON u.role_id = r.role_id
             WHERE u.user_id = ?'
        );
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();
        return is_string($role) ? $role : '';
    }

    private function assertManagerRole(int $userId): void
    {
        $role = $this->actorRole ?? $this->roleOf($userId);
        if ($role !== 'inventory_manager') {
            throw new RuntimeException('Inventory Managers only: recording supplier pullouts requires the inventory_manager role.');
        }
    }

    private function parseWholeCount($rawValue, string $label, bool $required = false): int
    {
        if ($rawValue === null || $rawValue === '') {
            if ($required) {
                throw new InvalidArgumentException("{$label} is required.");
            }
            return 0;
        }
        if (!is_numeric($rawValue) || is_bool($rawValue)) {
            throw new InvalidArgumentException("{$label} must be a positive whole number.");
        }
        if (is_float($rawValue) || (is_string($rawValue) && str_contains($rawValue, '.'))) {
            throw new InvalidArgumentException('Fractional quantities are not allowed. Provide a positive whole number.');
        }
        if (!preg_match('/^-?\d+$/D', trim((string)$rawValue))) {
            throw new InvalidArgumentException("{$label} must be a positive whole number.");
        }
        $val = (int)$rawValue;
        if (($required ? $val <= 0 : $val < 0) || (float)$rawValue > 2147483647) {
            throw new InvalidArgumentException("{$label} must be greater than zero.");
        }
        return $val;
    }

    public function recordPullout(array $data, int $userId): array
    {
        $this->assertManagerRole($userId);
        $submission = new InventorySubmission($this->pdo, $userId, 'pullout', $data);
        if (($completed = $submission->completed()) !== null) return $completed;

        $productId = (int)($data['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new InvalidArgumentException('Valid product is required.');
        }

        $batchId = (int)($data['batch_id'] ?? 0);
        if ($batchId <= 0) {
            throw new InvalidArgumentException('Valid batch is required.');
        }

        $hasPacksInput = array_key_exists('pulled_packs', $data) && $data['pulled_packs'] !== '' && $data['pulled_packs'] !== null;
        $hasLooseInput = array_key_exists('pulled_loose', $data) && $data['pulled_loose'] !== '' && $data['pulled_loose'] !== null;
        $hasFormInput = $hasPacksInput || $hasLooseInput;
        $hasQtyInput = array_key_exists('quantity', $data) && $data['quantity'] !== '' && $data['quantity'] !== null;

        $pulledPacks = 0;
        $pulledLoose = 0;
        $quantity = 0;
        $explicitQty = null;

        if ($hasFormInput) {
            $pulledPacks = $this->parseWholeCount($data['pulled_packs'] ?? null, 'Pulled packs');
            $pulledLoose = $this->parseWholeCount($data['pulled_loose'] ?? null, 'Pulled loose pieces');
            if ($pulledPacks <= 0 && $pulledLoose <= 0) {
                throw new InvalidArgumentException('Pullout quantity must be greater than zero.');
            }
            if ($hasQtyInput) {
                $explicitQty = $this->parseWholeCount($data['quantity'], 'Pullout quantity', true);
            }
        } else {
            $quantity = $this->parseWholeCount($data['quantity'] ?? null, 'Pullout quantity', true);
            $pulledPacks = 0;
            $pulledLoose = $quantity;
        }

        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('Reason for supplier pullout is required.');
        }

        $pulledAt = !empty($data['pulled_at']) ? trim((string)$data['pulled_at']) : (new DateTimeImmutable('now', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d H:i:s');
        $notes = !empty($data['notes']) ? trim((string)$data['notes']) : null;
        $supplier = !empty($data['supplier']) ? trim((string)$data['supplier']) : null;

        $this->fiscalPeriodGuard->assertOpenForDate($pulledAt, 'stock_movements', 'supplier pullout');
        $this->fiscalPeriodGuard->assertOpenNow('inventory', 'supplier pullout');
        $this->fiscalPeriodGuard->assertOpenNow('supplier_pullouts', 'supplier pullout');

        StoreWriteGate::begin($this->pdo);

        try {
            [$scopeSql, $scopeParams] = $this->storeProductScope();
            $productTableCols = SalesWorkflowService::getTableColumns($this->pdo, 'products');
            $productCols = isset($productTableCols['pack_size']) ? 'product_id, pack_size' : 'product_id';
            $scopeStmt = $this->pdo->prepare("SELECT {$productCols} FROM products p WHERE p.product_id = ?{$scopeSql}");
            $scopeStmt->execute(array_merge([$productId], $scopeParams));
            $productRow = $scopeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$productRow) {
                throw new RuntimeException("Product #{$productId} not found or inaccessible in current store scope.");
            }

            $productPackSize = isset($productRow['pack_size']) && (int)$productRow['pack_size'] > 1 ? (int)$productRow['pack_size'] : 1;

            $batchStmt = $this->pdo->prepare(
                "SELECT * FROM product_batches WHERE batch_id = ?" . $this->rowLock()
            );
            $batchStmt->execute([$batchId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new RuntimeException("Batch #{$batchId} not found.");
            }

            if ((int)$batch['product_id'] !== $productId) {
                throw new RuntimeException("Batch #{$batchId} does not belong to the selected product (#{$productId}).");
            }

            $normalizedBatch = $this->normalizeBatchFormRow($batch);
            $remainingQty = (int)$normalizedBatch['remaining_quantity'];
            $batchSealed = (int)$normalizedBatch['sealed_quantity'];
            $batchLoose = (int)$normalizedBatch['loose_quantity'];

            $pbTableCols = SalesWorkflowService::getTableColumns($this->pdo, 'product_batches');
            $hasBatchFormCols = isset($pbTableCols['sealed_quantity']) && isset($pbTableCols['loose_quantity']);

            if ($pulledPacks > 0 && $productPackSize <= 1) {
                throw new RuntimeException('Cannot pull out sealed packs: product has no Sales Pack configured.');
            }

            $packSizeUsed = $productPackSize > 1 ? $productPackSize : 1;
            if ($hasFormInput) {
                $quantity = ($pulledPacks * $packSizeUsed) + $pulledLoose;
                if ($explicitQty !== null && $explicitQty !== $quantity) {
                    throw new InvalidArgumentException("Pullout quantity ({$explicitQty}) does not match pulled packs and loose pieces ({$quantity} base units).");
                }
            }

            if ($quantity > $remainingQty) {
                throw new RuntimeException("Requested pullout quantity ({$quantity}) exceeds batch remaining balance ({$remainingQty}).");
            }

            if ($pulledPacks > $batchSealed) {
                throw new RuntimeException("Requested sealed packs ({$pulledPacks}) exceeds batch sealed pack balance ({$batchSealed}).");
            }

            if ($pulledLoose > $batchLoose) {
                throw new RuntimeException("Requested loose pieces ({$pulledLoose}) exceeds batch loose piece balance ({$batchLoose}).");
            }

            $invStmt = $this->pdo->prepare(
                "SELECT quantity_on_hand FROM inventory WHERE product_id = ?" . $this->rowLock()
            );
            $invStmt->execute([$productId]);
            $inventory = $invStmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                throw new RuntimeException("Inventory record for product #{$productId} not found.");
            }

            $currentOnHand = (int)$inventory['quantity_on_hand'];
            if ($quantity > $currentOnHand) {
                throw new RuntimeException("Requested pullout quantity ({$quantity}) exceeds total inventory on hand ({$currentOnHand}).");
            }

            if ($supplier === null && !empty($batch['supplier'])) {
                $supplier = (string)$batch['supplier'];
            }

            $newRemainingQty = $remainingQty - $quantity;
            $newBatchSealed = $batchSealed - $pulledPacks;
            $newBatchLoose = $batchLoose - $pulledLoose;

            if ($hasBatchFormCols) {
                $updateBatch = $this->pdo->prepare(
                    "UPDATE product_batches SET remaining_quantity = ?, sealed_quantity = ?, loose_quantity = ? WHERE batch_id = ?"
                );
                $updateBatch->execute([$newRemainingQty, $newBatchSealed, $newBatchLoose, $batchId]);
            } else {
                $updateBatch = $this->pdo->prepare(
                    "UPDATE product_batches SET remaining_quantity = remaining_quantity - ? WHERE batch_id = ?"
                );
                $updateBatch->execute([$quantity, $batchId]);
            }

            $updateInv = $this->pdo->prepare(
                "UPDATE inventory SET quantity_on_hand = quantity_on_hand - ? WHERE product_id = ?"
            );
            $updateInv->execute([$quantity, $productId]);

            $spTableCols = SalesWorkflowService::getTableColumns($this->pdo, 'supplier_pullouts');
            if (isset($spTableCols['pulled_packs'])) {
                $pulloutStmt = $this->pdo->prepare(
                    "INSERT INTO supplier_pullouts (product_id, batch_id, quantity, pulled_packs, pulled_loose, pack_size_used, reason, supplier, pulled_by, pulled_at, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $pulloutStmt->execute([$productId, $batchId, $quantity, $pulledPacks, $pulledLoose, $packSizeUsed, $reason, $supplier, $userId, $pulledAt, $notes]);
            } else {
                $pulloutStmt = $this->pdo->prepare(
                    "INSERT INTO supplier_pullouts (product_id, batch_id, quantity, reason, supplier, pulled_by, pulled_at, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $pulloutStmt->execute([$productId, $batchId, $quantity, $reason, $supplier, $userId, $pulledAt, $notes]);
            }
            $pulloutId = (int)$this->pdo->lastInsertId();

            $movementStmt = $this->pdo->prepare(
                "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by, pullout_id, moved_at)
                 VALUES (?, ?, 'supplier_pullout', ?, ?, ?)"
            );
            $movementStmt->execute([$productId, -$quantity, $userId, $pulloutId, $pulledAt]);
            $movementId = (int)$this->pdo->lastInsertId();

            $result = [
                'pullout_id' => $pulloutId,
                'movement_id' => $movementId,
                'product_id' => $productId,
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'pulled_packs' => $pulledPacks,
                'pulled_loose' => $pulledLoose,
                'pack_size_used' => $packSizeUsed,
                'remaining_batch_qty' => $newRemainingQty,
                'remaining_batch_packs' => $newBatchSealed,
                'remaining_batch_loose' => $newBatchLoose,
                'new_on_hand' => $currentOnHand - $quantity,
                'reason' => $reason,
                'supplier' => $supplier,
                'pulled_by' => $userId,
                'pulled_at' => $pulledAt,
                'notes' => $notes,
            ];
            $submission->remember($result);
            $this->pdo->commit();

            try {
                $this->notificationService->checkAndNotifyLowStock();
            } catch (Throwable) {
                // Non-critical background alert
            }

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getPullouts(array $filters = []): array
    {
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $spCols = SalesWorkflowService::getTableColumns($this->pdo, 'supplier_pullouts');
        $srCols = SalesWorkflowService::getTableColumns($this->pdo, 'stock_receiving');
        $pCols = SalesWorkflowService::getTableColumns($this->pdo, 'products');

        $hasPulloutForms = isset($spCols['pulled_packs']);
        $hasReceivingForms = isset($srCols['accepted_packs']);
        $hasProductPackSize = isset($pCols['pack_size']);

        $pulloutFormSelect = $hasPulloutForms
            ? 'sp.pulled_packs, sp.pulled_loose, sp.pack_size_used,'
            : '0 AS pulled_packs, sp.quantity AS pulled_loose, 1 AS pack_size_used,';
        $receivingFormSelect = $hasReceivingForms
            ? 'COALESCE(SUM(sr.accepted_packs), 0) AS total_replaced_packs, COALESCE(SUM(sr.accepted_loose), 0) AS total_replaced_loose,'
            : '0 AS total_replaced_packs, COALESCE(SUM(sr.accepted_qty), 0) AS total_replaced_loose,';
        $productPackSelect = $hasProductPackSize ? 'p.pack_size,' : 'NULL AS pack_size,';

        $sql = "SELECT sp.pullout_id, sp.product_id, sp.batch_id, sp.quantity, {$pulloutFormSelect} sp.reason,
                       sp.supplier, sp.pulled_by, sp.pulled_at, sp.notes,
                       p.product_name, p.sku, {$productPackSelect}
                       pb.batch_number, pb.expiration_date,
                       COALESCE(SUM(sr.accepted_qty), 0) AS total_replaced_qty,
                       {$receivingFormSelect}
                       COUNT(sr.receiving_id) AS replacement_count,
                       u.full_name AS pulled_by_name
                FROM supplier_pullouts sp
                JOIN products p ON sp.product_id = p.product_id
                JOIN product_batches pb ON sp.batch_id = pb.batch_id
                JOIN users u ON sp.pulled_by = u.user_id
                LEFT JOIN stock_receiving sr ON sr.pullout_id = sp.pullout_id
                WHERE 1 = 1{$scopeSql}";
        $params = $scopeParams;

        if (!empty($filters['pullout_id'])) {
            $sql .= " AND sp.pullout_id = ?";
            $params[] = (int)$filters['pullout_id'];
        }
        if (!empty($filters['product_id'])) {
            $sql .= " AND sp.product_id = ?";
            $params[] = (int)$filters['product_id'];
        }
        if (!empty($filters['batch_id'])) {
            $sql .= " AND sp.batch_id = ?";
            $params[] = (int)$filters['batch_id'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (p.product_name LIKE ? OR p.sku LIKE ? OR pb.batch_number LIKE ? OR sp.reason LIKE ? OR sp.supplier LIKE ?)";
            $searchTerm = '%' . trim((string)$filters['search']) . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $groupCols = "sp.pullout_id, sp.product_id, sp.batch_id, sp.quantity";
        if ($hasPulloutForms) {
            $groupCols .= ", sp.pulled_packs, sp.pulled_loose, sp.pack_size_used";
        }
        $groupCols .= ", sp.reason, sp.supplier, sp.pulled_by, sp.pulled_at, sp.notes, p.product_name, p.sku";
        if ($hasProductPackSize) {
            $groupCols .= ", p.pack_size";
        }
        $groupCols .= ", pb.batch_number, pb.expiration_date, u.full_name";

        $sql .= " GROUP BY {$groupCols}";
        $sql .= " ORDER BY sp.pulled_at DESC, sp.pullout_id DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPullout(int $pulloutId): ?array
    {
        $rows = $this->getPullouts(['pullout_id' => $pulloutId, 'limit' => 1]);
        return $rows[0] ?? null;
    }

    private function normalizeBatchFormRow(array $row): array
    {
        $rem = (int)($row['remaining_quantity'] ?? 0);
        $sealed = (int)($row['sealed_quantity'] ?? 0);
        $loose = (int)($row['loose_quantity'] ?? 0);
        if ($sealed === 0 && $loose === 0 && $rem > 0) {
            $loose = $rem;
        }
        $row['sealed_quantity'] = $sealed;
        $row['loose_quantity'] = $loose;
        return $row;
    }

    public function getBatchForPullout(int $batchId): ?array
    {
        $pCols = SalesWorkflowService::getTableColumns($this->pdo, 'products');
        $packSelect = isset($pCols['pack_size']) ? ', p.pack_size' : '';
        $stmt = $this->pdo->prepare(
            "SELECT pb.*, p.product_name, p.sku{$packSelect}, i.quantity_on_hand
             FROM product_batches pb
             JOIN products p ON pb.product_id = p.product_id
             LEFT JOIN inventory i ON p.product_id = i.product_id
             WHERE pb.batch_id = ?"
        );
        $stmt->execute([$batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeBatchFormRow($row) : null;
    }

    public function getEligibleBatches(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pb.*
             FROM product_batches pb
             WHERE pb.product_id = ? AND pb.remaining_quantity > 0
             ORDER BY
                CASE WHEN pb.expiration_date IS NOT NULL THEN 0 ELSE 1 END,
                pb.expiration_date ASC,
                pb.batch_id ASC"
        );
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row) => $this->normalizeBatchFormRow($row), $rows);
    }
}
