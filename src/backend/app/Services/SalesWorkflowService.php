<?php
// app/Services/SalesWorkflowService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
use App\Store\StoreWriteGate;
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../Authorization/RoleCapabilityPolicy.php';
// Ticket #90: the locked-Register message is shared with the page, with the
// Cashier Shift page, and with held sales, so a Cashier is never told a Register
// is merely unavailable when the real answer is that they locked it.
require_once __DIR__ . '/CashierShiftService.php';
// Ticket #91: a resumed held sale is completed by the checkout that pays for it,
// so the cart and the sale it became are settled in one transaction.
require_once __DIR__ . '/HeldSaleService.php';
require_once __DIR__ . '/ReceiptDetailsService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\CashierShiftService;
use App\Services\HeldSaleService;
use App\Services\ReceiptDetailsService;

/**
 * Sale creation, and the Cashier Shift attribution every sale carries (#89).
 *
 * A sale is written by a Cashier, in the active Cashier workspace, under that
 * Cashier's own open Cashier Shift. The workspace — not the account's stored
 * role — decides who may sell, so an Administrator who also holds the Cashier
 * role must switch workspaces (#86). The shift is resolved and locked inside
 * the same transaction that writes the sale, so a Cashier Shift cannot be closed
 * or reassigned between attribution and commit.
 *
 * The Register is never accepted from the caller and is not stored on the sale.
 * It is determinable by joining the sale to the Cashier Shift the Cashier
 * opened, which is what makes it determinable rather than supplied.
 *
 * A shift that is locked for a break authorizes nothing (#90): the lock is
 * settled from the same row-locked read that picks the attribution, so a
 * Register cannot be locked between the check and the sale.
 *
 * A cart the Cashier held on this shift and is now paying for is completed by
 * this same checkout (#91), on the same shift, in the same transaction. A
 * checkout that names no held sale is an ordinary sale and completes none.
 *
 * An ordinary sale is recorded once, as the sale itself. It is deliberately not
 * mirrored into Protected Audit Records: the sales ledger is already
 * authoritative, and a second copy of every sale would be a redundant record
 * that drifts from the one operators actually reconcile.
 */
class SalesWorkflowService
{
    private PDO $pdo;
    private NotificationService $notificationService;
    private FiscalPeriodGuardService $fiscalPeriodGuard;
    private RoleCapabilityPolicy $policy;
    private HeldSaleService $heldSales;

    public function __construct(PDO $pdo, ?RoleCapabilityPolicy $policy = null)
    {
        $this->pdo = $pdo;
        $this->notificationService = new NotificationService($pdo);
        $this->fiscalPeriodGuard = new FiscalPeriodGuardService($pdo);
        $this->policy = $policy ?? new RoleCapabilityPolicy();
        $this->heldSales = new HeldSaleService($pdo, new CashierShiftService($pdo, $this->policy));
    }

    /**
     * @param string $actorRole The active workspace, never the account's stored role.
     */
    public function checkout(array $cart, int $userId, string $actorRole, string $paymentMethod, array $paymentDetails = []): array
    {
        $cleanCart = $this->sanitizeCart($cart);
        if (!$cleanCart) {
            throw new RuntimeException('Your cart is empty or invalid.');
        }

        // Settle the workspace before touching the database: a Cashier who
        // cannot sell is refused without beginning any Store write.
        $this->requireCashierWorkspace($actorRole);

        $this->assertCheckoutFiscalPeriodsOpen();

        StoreWriteGate::begin($this->pdo);
        try {
            // Resolved and locked inside the transaction so the shift that
            // authorizes this sale is the shift that is still open at commit.
            $attribution = $this->resolveSaleAttribution($userId);
            // Ticket #91: a cart that was held on this shift and is now being
            // paid for is completed by this checkout, on the same shift that
            // authorized both. Settled against the same locked attribution as
            // the sale, so a held sale cannot be completed onto a drawer that is
            // not the one it was held under.
            $resumedHeldSale = $this->resolveResumedHeldSale($userId, $attribution, $paymentDetails);
            $sale = $this->buildSalePayload($cleanCart);
            $manualDiscount = $this->resolveDiscount($userId, $sale['total'], $paymentDetails);
            $automaticPromotion = $this->resolveAutomaticPromotion($sale['items'], $sale['total']);
            $discount = $automaticPromotion['discount_amount'] > $manualDiscount['discount_amount'] ? $automaticPromotion : $manualDiscount;
            $netTotal = max(0, $sale['total'] - $discount['discount_amount']);
            $payment = $this->resolvePayment($paymentMethod, $paymentDetails, $netTotal);
            $saleId = $this->insertSale($userId, $attribution, $sale['total'], $netTotal, $paymentMethod, $payment, $discount);

            foreach ($sale['items'] as $item) {
                $this->recordSaleItem($saleId, $item, $userId);
            }

            // The customer copy is committed with the sale and inventory work.
            (new ReceiptDetailsService($this->pdo))->preserveSale($saleId);

            if ($resumedHeldSale !== null) {
                // Inside the transaction, so the held sale and the sale that
                // settles it commit or roll back together. A checkout that fails
                // leaves the held sale unresolved and still owed a decision.
                $this->heldSales->markCompleted((int)$resumedHeldSale['held_sale_id'], $saleId);
            }

            $this->pdo->commit();
            $this->notificationService->checkAndNotifyLowStock();
            $this->notificationService->checkAndNotifyExpiringStock();
            return ['sale_id' => $saleId, 'total' => $netTotal, 'discount_amount' => $discount['discount_amount']];
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
             WHERE p.status = 'active'
               AND i.quantity_on_hand > 0
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
                'category_id' => (int)($product['category_id'] ?? 0),
            ];
        }

        return ['total' => $total, 'items' => $items];
    }

    private function lockProductForCheckout(int $productId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.unit_price, p.category_id, p.status, i.quantity_on_hand
             FROM products p JOIN inventory i ON p.product_id = i.product_id
             WHERE p.product_id = ? AND p.status = 'active'" . $this->rowLock()
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        return $product ?: null;
    }


    /**
     * Refuses a checkout made outside the active Cashier workspace.
     *
     * The active workspace is the authority (#86), so an Administrator who also
     * holds the Cashier role must switch to the Cashier workspace to sell. An
     * administrative workspace never borrows the Cashier's drawer authority.
     */
    private function requireCashierWorkspace(string $actorRole): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::OPERATE_POINT_OF_SALE)) {
            throw new DomainException(
                'Only the Cashier workspace can sell. Switch to your Cashier workspace to check out.'
            );
        }
    }

    /**
     * The Cashier Shift this sale is attributed to.
     *
     * Read from the database and never taken from the request, so a client
     * cannot sell against somebody else's drawer. The Register is deliberately
     * not returned: it is determinable by joining the sale to this shift, so
     * there is no second copy to drift and no value a client could supply.
     *
     * The row is locked for the rest of the transaction, so a Cashier Shift that
     * is closed or replaced by a competing request cannot slip between
     * attribution and commit. The lock state is read from that same locked row
     * rather than from a separate guard query: a Register locked between a
     * check and the sale would otherwise let one transaction through.
     */
    private function resolveSaleAttribution(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT cs.shift_id, cs.locked_at
             FROM cashier_shifts cs
             WHERE cs.cashier_id = ? AND cs.status = 'open'
             ORDER BY cs.opened_at DESC LIMIT 1" . $this->rowLock()
        );
        $stmt->execute([$userId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            throw new DomainException('Open a Cashier Shift before processing sales.');
        }

        // Ticket #90: a locked Register is on a break, not finished. Selling
        // through it would put a transaction under a Cashier who is not at the
        // till, so the lock is settled here rather than only on the page.
        if (!empty($shift['locked_at'])) {
            throw new DomainException(CashierShiftService::LOCKED_MESSAGE);
        }

        return (int)$shift['shift_id'];
    }

    /**
     * The resumed held sale this checkout is completing, if it names one.
     *
     * $paymentDetails may carry `held_sale_id` when the Cashier resumed a held
     * sale and is now paying for it (#91). The id comes from the request, so it
     * is settled against two things that are not: the authenticated Cashier, and
     * the Cashier Shift that has already been locked as this sale's attribution.
     * A checkout that names no held sale completes none, which is the normal
     * case.
     *
     * @return array|null The locked held sale row, or null for an ordinary sale.
     */
    private function resolveResumedHeldSale(int $userId, int $shiftId, array $paymentDetails): ?array
    {
        $heldSaleId = (int)($paymentDetails['held_sale_id'] ?? 0);
        if ($heldSaleId <= 0) {
            return null;
        }

        return $this->heldSales->assertCompletable($userId, $heldSaleId, $shiftId);
    }

    /**
     * The row lock that holds the shift for the rest of the transaction.
     *
     * MySQL takes a real row lock, which is what stops a Cashier Shift being
     * closed or replaced between attribution and commit. A driver without row
     * locking serialises writers with the transaction itself, so the clause is
     * omitted there rather than failing the query — the same dialect split
     * StoreWriteGate makes for the Store write gate.
     */
    private function rowLock(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function resolveDiscount(int $userId, float $grossTotal, array $details): array
    {
        $type = (string)($details['discount_type'] ?? 'none');
        if (!in_array($type, ['none', 'percentage', 'fixed'], true)) {
            $type = 'none';
        }
        $value = max(0, (float)($details['discount_value'] ?? 0));
        $reason = trim((string)($details['discount_reason'] ?? ''));
        $amount = 0.0;
        if ($type === 'percentage') {
            if ($value > 100) {
                throw new RuntimeException('Percentage discount cannot exceed 100%.');
            }
            $amount = round($grossTotal * ($value / 100), 2);
        } elseif ($type === 'fixed') {
            $amount = round(min($value, $grossTotal), 2);
        }

        if ($amount <= 0) {
            return [
                'discount_type' => 'none',
                'discount_value' => 0,
                'discount_amount' => 0,
                'discount_reason' => null,
                'discount_authorized_by' => null,
                'promotion_id' => null,
                'promotion_name' => null,
            ];
        }
        if ($reason === '') {
            throw new RuntimeException('A discount reason is required.');
        }

        $authorizedBy = $userId;
        $threshold = $grossTotal * 0.10;
        if ($amount > $threshold) {
            $authorizedBy = $this->authorizeSupervisor(
                trim((string)($details['discount_approver_username'] ?? '')),
                (string)($details['discount_approver_password'] ?? '')
            );
        }

        return [
            'discount_type' => $type,
            'discount_value' => $value,
            'discount_amount' => $amount,
            'discount_reason' => $reason,
            'discount_authorized_by' => $authorizedBy,
            'promotion_id' => null,
            'promotion_name' => null,
        ];
    }

    private function resolveAutomaticPromotion(array $items, float $grossTotal): array
    {
        $default = [
            'discount_type' => 'none', 'discount_value' => 0.0, 'discount_amount' => 0.0,
            'discount_reason' => null, 'discount_authorized_by' => null,
            'promotion_id' => null, 'promotion_name' => null,
        ];
        try {
            $promotions = $this->pdo->query(
                "SELECT * FROM promotions WHERE status='active' AND starts_at<=NOW() AND ends_at>=NOW() ORDER BY discount_value DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $default;
        }
        $best = $default;
        foreach ($promotions as $promotion) {
            $eligibleSubtotal = 0.0;
            $eligibleQuantity = 0;
            foreach ($items as $item) {
                $eligible = $promotion['scope'] === 'all'
                    || ($promotion['scope'] === 'product' && (int)$promotion['product_id'] === (int)$item['product_id'])
                    || ($promotion['scope'] === 'category' && (int)$promotion['category_id'] === (int)$item['category_id']);
                if ($eligible) {
                    $eligibleSubtotal += (float)$item['subtotal'];
                    $eligibleQuantity += (int)$item['quantity'];
                }
            }
            if ($eligibleSubtotal <= 0 || $eligibleQuantity < max(1, (int)$promotion['minimum_quantity'])) {
                continue;
            }
            $value = max(0, (float)$promotion['discount_value']);
            $amount = $promotion['discount_type'] === 'percentage'
                ? round($eligibleSubtotal * min(100, $value) / 100, 2)
                : round(min($eligibleSubtotal, $value), 2);
            $amount = min($amount, $grossTotal);
            if ($amount > (float)$best['discount_amount']) {
                $best = [
                    'discount_type' => (string)$promotion['discount_type'],
                    'discount_value' => $value,
                    'discount_amount' => $amount,
                    'discount_reason' => 'Automatic promotion: ' . $promotion['promotion_name'],
                    'discount_authorized_by' => null,
                    'promotion_id' => (int)$promotion['promotion_id'],
                    'promotion_name' => (string)$promotion['promotion_name'],
                ];
            }
        }
        return $best;
    }

    private function authorizeSupervisor(string $username, string $password): int
    {
        if ($username === '' || $password === '') {
            throw new RuntimeException('A supervisor login is required for discounts above 10%.');
        }
        $stmt = $this->pdo->prepare(
            "SELECT u.user_id, u.password_hash
             FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE u.username = ? AND u.status = 'active' AND r.role_name IN ('admin','super_admin')
             LIMIT 1"
        );
        $stmt->execute([$username]);
        $supervisor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$supervisor || !password_verify($password, (string)$supervisor['password_hash'])) {
            throw new RuntimeException('Supervisor authorization failed.');
        }
        return (int)$supervisor['user_id'];
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

    /**
     * Writes the sale with the Cashier and Cashier Shift it ran under.
     *
     * The Register is not stored on the sale at all: it is determinable by
     * joining the sale to its shift, so there is no second copy to drift and no
     * column a client could populate.
     */
    private function insertSale(
        int $userId,
        int $shiftId,
        float $grossTotal,
        float $netTotal,
        string $paymentMethod,
        array $payment,
        array $discount
    ): int {
        $saleColumns = $this->getSalesColumns();
        $columns = ['cashier_id', 'total_amount', 'payment_method'];
        $placeholders = ['?', '?', '?'];
        $values = [$userId, $netTotal, $paymentMethod];

        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'shift_id', $shiftId);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'gross_amount', $grossTotal);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'discount_type', $discount['discount_type']);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'discount_value', $discount['discount_value']);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'discount_amount', $discount['discount_amount']);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'discount_reason', $discount['discount_reason']);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'discount_authorized_by', $discount['discount_authorized_by']);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'promotion_id', $discount['promotion_id']);
        $this->appendSaleColumnIfAvailable($saleColumns, $columns, $placeholders, $values, 'promotion_name', $discount['promotion_name']);
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
            throw new RuntimeException("Stock changed for product #{$productId} — please retry checkout.");
        }
    }

    private function getSalesColumns(): array
    {
        static $columns = null;
        if ($columns !== null) {
            return $columns;
        }

        $columns = [];
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            foreach ($this->pdo->query('SHOW COLUMNS FROM sales')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columns[$column['Field']] = true;
            }

            return $columns;
        }

        foreach ($this->pdo->query('PRAGMA table_info(sales)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['name']] = true;
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
             " . $this->rowLock()
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
