<?php
// app/Services/InventoryCountService.php

require_once __DIR__ . '/NotificationService.php';
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

    public function recordCount(array $data, int $userId): int
    {
        $productId = (int)($data['product_id'] ?? 0);
        $physicalQty = (int)($data['physical_quantity'] ?? -1);
        $reason = trim((string)($data['discrepancy_reason'] ?? ''));

        if ($productId <= 0) {
            throw new RuntimeException('Product is required.');
        }
        if ($physicalQty < 0) {
            throw new RuntimeException('Physically counted quantity cannot be negative.');
        }
        if ($reason === '') {
            throw new RuntimeException('Reason for discrepancy is required.');
        }

        $this->fiscalPeriodGuard->assertOpenNow('inventory_counts', 'inventory count');

        $this->pdo->beginTransaction();
        try {
            [$scopeSql, $scopeParams] = $this->productScope();
            $stmt = $this->pdo->prepare(
                "SELECT i.quantity_on_hand
                 FROM inventory i
                 JOIN products p ON p.product_id = i.product_id
                 WHERE i.product_id = ?{$scopeSql}
                 FOR UPDATE"
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
                    discrepancy_reason, counted_by, counted_at, status
                 ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'pending')"
            );
            $stmt->execute([
                $productId,
                $systemQty,
                $physicalQty,
                $differenceQty,
                $reason,
                $userId,
            ]);

            $countId = (int)$this->pdo->lastInsertId();
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

        $this->pdo->beginTransaction();
        try {
            [$scopeSql, $scopeParams] = $this->productScope();
            $stmt = $this->pdo->prepare(
                "SELECT ic.*
                 FROM inventory_counts ic
                 JOIN products p ON p.product_id = ic.product_id
                 WHERE ic.count_id = ?{$scopeSql}
                 FOR UPDATE"
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
                 WHERE product_id = ?
                 FOR UPDATE"
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

            $this->pdo->prepare(
                "UPDATE inventory
                 SET quantity_on_hand = ?
                 WHERE product_id = ?"
            )->execute([(int)$count['physical_quantity'], $productId]);

            if ($differenceQty !== 0) {
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
                    approved_user.full_name AS approved_by_name
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

    private function productScope(): array
    {
        if (function_exists('branch_scope')) {
            return branch_scope('p');
        }

        return (new App\Store\StoreScope($this->pdo))->productScope('p');
    }
}
