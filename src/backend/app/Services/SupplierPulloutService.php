<?php
// app/Services/SupplierPulloutService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/PhilippineTime.php';
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

    public function recordPullout(array $data, int $userId): array
    {
        $this->assertManagerRole($userId);

        $productId = (int)($data['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new InvalidArgumentException('Valid product is required.');
        }

        $batchId = (int)($data['batch_id'] ?? 0);
        if ($batchId <= 0) {
            throw new InvalidArgumentException('Valid batch is required.');
        }

        if (!isset($data['quantity']) || $data['quantity'] === '') {
            throw new InvalidArgumentException('Pullout quantity is required.');
        }
        $rawQty = $data['quantity'];
        if (!is_numeric($rawQty)) {
            throw new InvalidArgumentException('Pullout quantity must be a positive whole number.');
        }
        if (is_float($rawQty) || (is_string($rawQty) && str_contains($rawQty, '.'))) {
            throw new InvalidArgumentException('Fractional quantities are not allowed. Provide a positive whole number.');
        }
        $quantity = (int)$rawQty;
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Pullout quantity must be greater than zero.');
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
            $scopeStmt = $this->pdo->prepare("SELECT product_id FROM products p WHERE p.product_id = ?{$scopeSql}");
            $scopeStmt->execute(array_merge([$productId], $scopeParams));
            if (!$scopeStmt->fetch()) {
                throw new RuntimeException("Product #{$productId} not found or inaccessible in current store scope.");
            }

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

            $remainingQty = (int)$batch['remaining_quantity'];
            if ($quantity > $remainingQty) {
                throw new RuntimeException("Requested pullout quantity ({$quantity}) exceeds batch remaining balance ({$remainingQty}).");
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

            $updateBatch = $this->pdo->prepare(
                "UPDATE product_batches SET remaining_quantity = remaining_quantity - ? WHERE batch_id = ?"
            );
            $updateBatch->execute([$quantity, $batchId]);

            $updateInv = $this->pdo->prepare(
                "UPDATE inventory SET quantity_on_hand = quantity_on_hand - ? WHERE product_id = ?"
            );
            $updateInv->execute([$quantity, $productId]);

            $pulloutStmt = $this->pdo->prepare(
                "INSERT INTO supplier_pullouts (product_id, batch_id, quantity, reason, supplier, pulled_by, pulled_at, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $pulloutStmt->execute([$productId, $batchId, $quantity, $reason, $supplier, $userId, $pulledAt, $notes]);
            $pulloutId = (int)$this->pdo->lastInsertId();

            $movementStmt = $this->pdo->prepare(
                "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by, pullout_id, moved_at)
                 VALUES (?, ?, 'supplier_pullout', ?, ?, ?)"
            );
            $movementStmt->execute([$productId, -$quantity, $userId, $pulloutId, $pulledAt]);
            $movementId = (int)$this->pdo->lastInsertId();

            $this->pdo->commit();

            try {
                $this->notificationService->checkAndNotifyLowStock();
            } catch (Throwable) {
                // Non-critical background alert
            }

            return [
                'pullout_id' => $pulloutId,
                'movement_id' => $movementId,
                'product_id' => $productId,
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'remaining_batch_qty' => $remainingQty - $quantity,
                'new_on_hand' => $currentOnHand - $quantity,
                'reason' => $reason,
                'supplier' => $supplier,
                'pulled_by' => $userId,
                'pulled_at' => $pulledAt,
                'notes' => $notes,
            ];
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
        $sql = "SELECT sp.pullout_id, sp.product_id, sp.batch_id, sp.quantity, sp.reason,
                       sp.supplier, sp.pulled_by, sp.pulled_at, sp.notes,
                       p.product_name, p.sku,
                       pb.batch_number, pb.expiration_date,
                       COALESCE(SUM(sr.accepted_qty), 0) AS total_replaced_qty,
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

        $sql .= " GROUP BY sp.pullout_id, sp.product_id, sp.batch_id, sp.quantity, sp.reason,
                           sp.supplier, sp.pulled_by, sp.pulled_at, sp.notes,
                           p.product_name, p.sku, pb.batch_number, pb.expiration_date, u.full_name";
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

    public function getBatchForPullout(int $batchId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pb.*, p.product_name, p.sku, i.quantity_on_hand
             FROM product_batches pb
             JOIN products p ON pb.product_id = p.product_id
             LEFT JOIN inventory i ON p.product_id = i.product_id
             WHERE pb.batch_id = ?"
        );
        $stmt->execute([$batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
