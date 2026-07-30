<?php
// app/Services/SalesWorkflowService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/FiscalPeriodGuardService.php';

class SalesWorkflowService
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

    public function checkout(array $cart, int $userId, string $paymentMethod, array $paymentDetails = []): array
    {
        $cleanCart = $this->sanitizeCart($cart);
        if (!$cleanCart) {
            throw new RuntimeException('Your cart is empty or invalid.');
        }

        $this->assertCheckoutFiscalPeriodsOpen();

        $this->pdo->beginTransaction();
        try {
            $sale = $this->buildSalePayload($cleanCart);
            $payment = $this->resolvePayment($paymentMethod, $paymentDetails, $sale['total']);
            $saleId = $this->insertSale($userId, $sale['total'], $paymentMethod, $payment);

            foreach ($sale['items'] as $item) {
                $this->recordSaleItem($saleId, $item, $userId);
            }

            $this->pdo->commit();
            $this->notificationService->checkAndNotifyLowStock();
            $this->notificationService->checkAndNotifyExpiringStock();
            return ['sale_id' => $saleId];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getActiveProducts(): array
    {
        return $this->pdo->query(
            "SELECT p.product_id, p.sku, p.barcode, p.product_name, p.unit_price,
                    COALESCE(p.reorder_level, 0) AS reorder_level,
                    COALESCE(p.safety_stock, 0) AS safety_stock,
                    LEAST(
                        i.quantity_on_hand,
                        COALESCE(batch_available.sellable_quantity, i.quantity_on_hand)
                    ) AS quantity_on_hand,
                    batch_available.next_expiration_date
             FROM products p JOIN inventory i ON p.product_id = i.product_id
             LEFT JOIN (
                 SELECT product_id,
                        SUM(CASE WHEN expiration_date IS NULL OR expiration_date >= CURDATE() THEN remaining_quantity ELSE 0 END) AS sellable_quantity,
                        MIN(CASE WHEN expiration_date IS NULL OR expiration_date >= CURDATE() THEN expiration_date ELSE NULL END) AS next_expiration_date
                 FROM product_batches
                 WHERE remaining_quantity > 0
                 GROUP BY product_id
             ) batch_available ON batch_available.product_id = p.product_id
             WHERE i.quantity_on_hand > 0
               AND COALESCE(batch_available.sellable_quantity, i.quantity_on_hand) > 0
             ORDER BY p.product_name"
        )->fetchAll();
    }

    private function sanitizeCart(array $cart): array
    {
        $cleanCart = [];
        foreach ($cart as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['qty'] ?? 0);
            if ($productId > 0 && $qty > 0) {
                $cleanCart[] = ['product_id' => $productId, 'qty' => $qty];
            }
        }

        return $cleanCart;
    }

    private function assertCheckoutFiscalPeriodsOpen(): void
    {
        $this->fiscalPeriodGuard->assertOpenNow('sales', 'sale');
        $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');
    }

    private function buildSalePayload(array $cleanCart): array
    {
        $total = 0;
        $items = [];

        foreach ($cleanCart as $item) {
            $product = $this->lockProductForCheckout($item['product_id']);
            if (!$product) {
                throw new RuntimeException("Product #{$item['product_id']} no longer exists.");
            }
            if ($product['quantity_on_hand'] < $item['qty']) {
                throw new RuntimeException("Not enough stock for product #{$item['product_id']} (only {$product['quantity_on_hand']} left).");
            }

            $batchPlan = $this->buildFefoBatchPlan($item['product_id'], $item['qty']);
            $unitPrice = (float)$product['unit_price'];
            $subtotal = $unitPrice * $item['qty'];
            $total += $subtotal;

            $items[] = [
                'product_id' => $item['product_id'],
                'quantity' => $item['qty'],
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'batch_plan' => $batchPlan,
            ];
        }

        return ['total' => $total, 'items' => $items];
    }

    private function lockProductForCheckout(int $productId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.unit_price, i.quantity_on_hand
             FROM products p JOIN inventory i ON p.product_id = i.product_id
             WHERE p.product_id = ? FOR UPDATE"
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        return $product ?: null;
    }

    private function resolvePayment(string $paymentMethod, array $paymentDetails, float $total): array
    {
        $cashReceived = $paymentMethod === 'cash' ? (float)($paymentDetails['cash_received'] ?? 0) : null;
        $changeDue = $paymentMethod === 'cash' ? max(0, $cashReceived - $total) : null;
        $paymentReference = $paymentMethod !== 'cash' ? trim((string)($paymentDetails['payment_reference'] ?? '')) : null;

        if ($paymentMethod === 'cash' && $cashReceived < $total) {
            throw new RuntimeException('Cash received is less than the sale total.');
        }
        if ($paymentMethod !== 'cash' && $paymentReference === '') {
            throw new RuntimeException('Payment reference is required for card or e-wallet payments.');
        }

        return [
            'cash_received' => $cashReceived,
            'change_due' => $changeDue,
            'payment_reference' => $paymentReference,
        ];
    }

    private function insertSale(int $userId, float $total, string $paymentMethod, array $payment): int
    {
        $saleColumns = $this->getSalesColumns();
        $columns = ['cashier_id', 'total_amount', 'payment_method'];
        $placeholders = ['?', '?', '?'];
        $values = [$userId, $total, $paymentMethod];

        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'cash_received', $payment['cash_received']);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'change_due', $payment['change_due']);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'payment_reference', $payment['payment_reference']);

        $stmt = $this->pdo->prepare(
            "INSERT INTO sales (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
        );
        $stmt->execute($values);

        return (int)$this->pdo->lastInsertId();
    }

    private function appendSaleColumnIfAvailable(
        array $availableColumns,
        array &$columns,
        array &$placeholders,
        array &$values,
        string $column,
        $value
    ): void {
        if (!isset($availableColumns[$column])) {
            return;
        }

        $columns[] = $column;
        $placeholders[] = '?';
        $values[] = $value;
    }

    private function recordSaleItem(int $saleId, array $item, int $userId): void
    {
        $this->pdo->prepare(
            "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $saleId,
            $item['product_id'],
            $item['quantity'],
            $item['unit_price'],
            $item['subtotal'],
        ]);
        $saleItemId = (int)$this->pdo->lastInsertId();

        foreach ($item['batch_plan'] as $batchAllocation) {
            $this->depleteBatchAllocation($batchAllocation, $item['product_id']);
            $this->recordSaleItemBatch($saleItemId, $batchAllocation);
        }

        $this->decrementInventory($item['product_id'], $item['quantity']);

        $this->pdo->prepare(
            "UPDATE products SET quantity_sold = quantity_sold + ? WHERE product_id = ?"
        )->execute([$item['quantity'], $item['product_id']]);

        $this->pdo->prepare(
            "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by) VALUES (?, ?, 'sale', ?)"
        )->execute([$item['product_id'], -$item['quantity'], $userId]);
    }

    private function depleteBatchAllocation(array $batchAllocation, int $productId): void
    {
        $batchUpdate = $this->pdo->prepare(
            "UPDATE product_batches
             SET remaining_quantity = remaining_quantity - ?
             WHERE batch_id = ? AND remaining_quantity >= ?"
        );
        $batchUpdate->execute([
            $batchAllocation['quantity'],
            $batchAllocation['batch_id'],
            $batchAllocation['quantity'],
        ]);

        if ($batchUpdate->rowCount() === 0) {
            throw new RuntimeException("Batch stock changed for product #{$productId} - please retry checkout.");
        }
    }

    private function recordSaleItemBatch(int $saleItemId, array $batchAllocation): void
    {
        $this->pdo->prepare(
            "INSERT INTO sale_item_batches (sale_item_id, batch_id, quantity)
             VALUES (?, ?, ?)"
        )->execute([$saleItemId, $batchAllocation['batch_id'], $batchAllocation['quantity']]);
    }

    private function decrementInventory(int $productId, int $quantity): void
    {
        $updateStmt = $this->pdo->prepare(
            "UPDATE inventory SET quantity_on_hand = quantity_on_hand - ?
             WHERE product_id = ? AND quantity_on_hand >= ?"
        );
        $updateStmt->execute([$quantity, $productId, $quantity]);

        if ($updateStmt->rowCount() === 0) {
            throw new RuntimeException("Stock changed for product #{$productId} â€” please retry checkout.");
        }
    }

    private function getSalesColumns(): array
    {
        static $columns = null;
        if ($columns !== null) {
            return $columns;
        }

        $columns = [];
        foreach ($this->pdo->query('SHOW COLUMNS FROM sales')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['Field']] = true;
        }

        return $columns;
    }

    private function buildFefoBatchPlan(int $productId, int $quantity): array
    {
        $batchCountStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM product_batches WHERE product_id = ? AND remaining_quantity > 0"
        );
        $batchCountStmt->execute([$productId]);
        if ((int)$batchCountStmt->fetchColumn() === 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT batch_id, remaining_quantity, expiration_date
             FROM product_batches
             WHERE product_id = ?
               AND remaining_quantity > 0
               AND (expiration_date IS NULL OR expiration_date >= CURDATE())
             ORDER BY
               CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END,
               expiration_date ASC,
               date_received ASC,
               batch_id ASC
             FOR UPDATE"
        );
        $stmt->execute([$productId]);

        $remaining = $quantity;
        $plan = [];
        foreach ($stmt->fetchAll() as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int)$batch['remaining_quantity']);
            if ($take <= 0) {
                continue;
            }

            $plan[] = [
                'batch_id' => (int)$batch['batch_id'],
                'quantity' => $take,
            ];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new RuntimeException("Not enough non-expired batch stock for product #{$productId}.");
        }

        return $plan;
    }
}
