<?php
// app/Services/SalesWorkflowService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/PhilippineTime.php';
require_once __DIR__ . '/BatchAllocationEngine.php';
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
require_once __DIR__ . '/BatchInventoryLedger.php';
require_once __DIR__ . '/../Receipts/ReceiptEngine.php';
require_once __DIR__ . '/StockConflictException.php';
require_once __DIR__ . '/../Dashboard/StoreOperationsDashboardWorkspace.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;
use App\Services\CashierShiftService;
use App\Services\HeldSaleService;
use App\Services\BatchAllocationEngine;
use App\Services\BatchInventoryLedger;
use App\Receipts\ReceiptEngine;

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
    public const QUOTE_CHANGED = 'Prices, stock or promotions changed. Review the final quote again before recording payment.';
    private PDO $pdo;
    private NotificationService $notificationService;
    private FiscalPeriodGuardService $fiscalPeriodGuard;
    private RoleCapabilityPolicy $policy;
    private HeldSaleService $heldSales;
    private BatchInventoryLedger $ledger;

    public function __construct(PDO $pdo, ?RoleCapabilityPolicy $policy = null, ?NotificationService $notifications = null)
    {
        $this->pdo = $pdo;
        $this->notificationService = $notifications ?? new NotificationService($pdo);
        $this->fiscalPeriodGuard = new FiscalPeriodGuardService($pdo);
        $this->policy = $policy ?? new RoleCapabilityPolicy();
        $this->heldSales = new HeldSaleService($pdo, new CashierShiftService($pdo, $this->policy));
        $this->ledger = new BatchInventoryLedger($pdo, $this->fiscalPeriodGuard);
    }

    /**
     * @param string $actorRole The active workspace, never the account's stored role.
     */
    public function checkout(array $cart, int $userId, string $actorRole, string $paymentMethod, array $paymentDetails = []): array
    {
        // A competing Store writer may fail fast at the write gate. Retry that
        // race with the same identity; a backup pause remains an explicit refusal.
        for ($retry = 0; ; $retry++) {
            try {
                return $this->checkoutOnce($cart, $userId, $actorRole, $paymentMethod, $paymentDetails);
            } catch (PDOException $e) {
                if ($retry >= 20 || !in_array((int)($e->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) {
                    throw $e;
                }
                usleep(100000);
            }
        }
    }

    private function checkoutOnce(array $cart, int $userId, string $actorRole, string $paymentMethod, array $paymentDetails): array
    {
        $cleanCart = $this->sanitizeCart($cart);
        if (!$cleanCart) {
            throw new RuntimeException('Your cart is empty or invalid.');
        }

        // Settle the workspace before touching the database: a Cashier who
        // cannot sell is refused without beginning any Store write.
        $this->requireCashierWorkspace($actorRole);

        $this->selectedPromotionId($paymentDetails);
        $attempt = $this->attemptIdentity($paymentDetails);
        $fingerprint = $this->attemptFingerprint($cleanCart, $paymentMethod, $paymentDetails);
        // Recovery remains available after a shift or fiscal period closes.
        $saved = $attempt === null ? null : $this->recoverAttempt($attempt, $userId, $actorRole, $fingerprint);
        if ($saved !== null) {
            return $saved;
        }

        StoreWriteGate::begin($this->pdo);
        try {
            $saved = $attempt === null ? null : $this->recoverAttempt($attempt, $userId, $actorRole, $fingerprint);
            if ($saved !== null) {
                $this->pdo->commit();
                return $saved;
            }
            $this->assertCheckoutFiscalPeriodsOpen();
            // Resolved and locked inside the transaction so the shift that
            // authorizes this sale is the shift that is still open at commit.
            $attribution = $this->resolveSaleAttribution($userId);
            // Ticket #91: a cart that was held on this shift and is now being
            // paid for is completed by this checkout, on the same shift that
            // authorized both. Settled against the same locked attribution as
            // the sale, so a held sale cannot be completed onto a drawer that is
            // not the one it was held under.
            $resumedHeldSale = $this->resolveResumedHeldSale($userId, $attribution, $paymentDetails);
            $quote = $this->buildQuote($cleanCart, $userId, $attribution, $paymentDetails);
            $this->assertRegisterTransactionLimit($attribution, (float)$quote['total']);
            // Browser requests always carry the server-held reviewed hash. Legacy
            // internal callers keep their interface, while reviewed callers fail closed.
            if (($attempt !== null || array_key_exists('reviewed_quote', $paymentDetails))
                && !hash_equals($quote['state_hash'], (string)($paymentDetails['reviewed_quote'] ?? ''))) {
                throw new DomainException(self::QUOTE_CHANGED);
            }
            $sale = $quote['sale'];
            $discount = $quote['discount'];
            $netTotal = $quote['total'];
            $payment = $this->resolvePayment($paymentMethod, $paymentDetails, $netTotal);
            $saleId = $this->insertSale($userId, $attribution, $sale['total'], $netTotal, $paymentMethod, $payment, $discount);
            $this->fulfillSaleItems($saleId, $sale, $userId);

            // The customer copy is committed with the sale and inventory work.
            (new ReceiptEngine($this->pdo))->preserveSale($saleId);

            if ($resumedHeldSale !== null) {
                // Inside the transaction, so the held sale and the sale that
                // settles it commit or roll back together. A checkout that fails
                // leaves the held sale unresolved and still owed a decision.
                $this->heldSales->markCompleted((int)$resumedHeldSale['held_sale_id'], $saleId);
            }

            $result = ['sale_id' => $saleId, 'total' => $netTotal, 'discount_amount' => $discount['discount_amount']];
            if ($attempt !== null) {
                $this->pdo->prepare('INSERT INTO checkout_attempts (cashier_id, attempt_id, request_hash, sale_id, result_json) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$userId, $attempt, $fingerprint, $saleId, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
            }
            $this->pdo->commit();
            StoreOperationsDashboardWorkspace::invalidateCache();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        foreach (['checkAndNotifyLowStock', 'checkAndNotifyExpiringStock'] as $notification) {
            try {
                $this->notificationService->$notification();
            } catch (Throwable $e) {
                error_log("Sale {$saleId} committed; {$notification} notification failed: " . $e->getMessage());
            }
        }
        return $result;
    }

    public function recoverAttempt(string $attempt, int $userId, string $actorRole, ?string $fingerprint = null): ?array
    {
        $this->requireCashierWorkspace($actorRole);
        $cleanAttempt = strtolower(str_replace('-', '', $attempt));
        $this->attemptIdentity(['checkout_attempt' => $cleanAttempt]);
        $statement = $this->pdo->prepare('SELECT request_hash, result_json FROM checkout_attempts WHERE cashier_id = ? AND attempt_id = ?');
        $statement->execute([$userId, $cleanAttempt]);
        $saved = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$saved) {
            return null;
        }
        $result = json_decode($saved['result_json'], true, 512, JSON_THROW_ON_ERROR);
        if ($fingerprint !== null && !empty($result['cancelled'])) {
            throw new DomainException('This checkout was cancelled. Return to Point of Sale before starting another sale.');
        }
        if ($fingerprint !== null && !hash_equals($saved['request_hash'], $fingerprint)) {
            throw new DomainException('This checkout attempt belongs to a different cart or payment. Recover its saved receipt before starting another sale.');
        }
        return $result;
    }

    public function cancelAttempt(string $attempt, int $userId, string $actorRole): array
    {
        $this->requireCashierWorkspace($actorRole);
        $attempt = strtolower(str_replace('-', '', $attempt));
        $this->attemptIdentity(['checkout_attempt' => $attempt]);
        StoreWriteGate::begin($this->pdo);
        try {
            $saved = $this->recoverAttempt($attempt, $userId, $actorRole);
            if ($saved !== null) {
                $this->pdo->commit();
                return $saved;
            }
            $this->resolveSaleAttribution($userId);
            // Keep a tombstone under the checkout write gate so a delayed POST cannot create a sale.
            $this->pdo->prepare('INSERT INTO checkout_attempts (cashier_id, attempt_id, request_hash, sale_id, result_json) VALUES (?, ?, ?, NULL, ?)')
                ->execute([$userId, $attempt, str_repeat('0', 64), '{"cancelled":true}']);
            $this->pdo->prepare("INSERT INTO activity_log (user_id, action, category, module, metadata) VALUES (?, 'Cancelled unsuccessful checkout; confirmed no outstanding payment', 'store_operation', 'sales', ?)")
                ->execute([$userId, json_encode(['checkout_attempt' => $attempt], JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
            return ['cancelled' => true];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** The quote is read under the same locks as checkout and makes no sale. */
    public function reviewQuote(array $cart, int $userId, string $actorRole, array $details = []): array
    {
        $this->requireCashierWorkspace($actorRole);
        $cart = $this->sanitizeCart($cart);
        if (!$cart) throw new DomainException('Your cart is empty or invalid.');
        StoreWriteGate::begin($this->pdo);
        try {
            $this->assertCheckoutFiscalPeriodsOpen();
            $shiftId = $this->resolveSaleAttribution($userId);
            $this->resolveResumedHeldSale($userId, $shiftId, $details);
            return $this->buildQuote($cart, $userId, $shiftId, $details);
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        }
    }

    private function selectedPromotionId(array $details): int
    {
        foreach (['discount_type', 'discount_value', 'discount_reason', 'discount_amount', 'discount_authorized_by', 'discount_approver_username', 'discount_approver_password'] as $field) {
            if (array_key_exists($field, $details)) throw new DomainException('Custom discounts are unavailable. Select an eligible promotion.');
        }
        $selected = $details['promotion_id'] ?? '';
        if ($selected !== '' && $selected !== null && (filter_var($selected, FILTER_VALIDATE_INT) === false || (int)$selected < 1)) {
            throw new DomainException('Choose a valid promotion.');
        }
        return (int)$selected;
    }

    private function buildQuote(array $cart, int $userId, int $shiftId, array $details): array
    {
        $sale = $this->buildSalePayload($cart);
        $selected = $this->selectedPromotionId($details);
        $discount = $this->resolvePromotion($sale['items'], $sale['total'], $selected);
        $total = round(max(0, $sale['total'] - $discount['discount_amount']), 2);
        $inputs = [
            'payment_method' => (string)($details['payment_method'] ?? ''),
            'promotion_id' => $details['promotion_id'] ?? '',
            'held_sale_id' => (int)($details['held_sale_id'] ?? 0),
        ];
        return ['sale' => $sale, 'discount' => $discount, 'total' => $total,
            'eligible_promotions' => $discount['eligible_promotions'],
            'state_hash' => hash('sha256', json_encode([$userId, $shiftId, $inputs, $sale, $discount, $discount['eligible_promotions']], JSON_THROW_ON_ERROR))];
    }

    private function attemptIdentity(array $details): ?string
    {
        // Non-browser service callers retain their existing checkout interface.
        if (!array_key_exists('checkout_attempt', $details) && !array_key_exists('checkout_attempt_id', $details)) {
            return null;
        }
        $raw = (string)($details['checkout_attempt_id'] ?? $details['checkout_attempt'] ?? '');
        $attempt = strtolower(str_replace('-', '', $raw));
        if (!preg_match('/^[a-f0-9]{32}$/D', $attempt)) {
            throw new DomainException('A valid checkout attempt is required. Reload the point of sale.');
        }
        return $attempt;
    }

    private function attemptFingerprint(array $cart, string $method, array $details): string
    {
        $quantities = [];
        foreach ($cart as $item) {
            $key = $item['product_id'] . ':' . ($item['selling_form'] ?? 'piece');
            $quantities[$key] = ($quantities[$key] ?? 0) + $item['qty'];
        }
        ksort($quantities);
        // Authorization passwords are never persisted or fingerprinted.
        $payment = [
            'cash_received' => $method === 'cash' ? (float)($details['cash_received'] ?? 0) : null,
            'payment_reference' => $method === 'cash' ? null : trim((string)($details['payment_reference'] ?? '')),
            'promotion_id' => $details['promotion_id'] ?? '',
            'held_sale_id' => (int)($details['held_sale_id'] ?? 0),
        ];
        return hash('sha256', json_encode([$quantities, $method, $payment], JSON_THROW_ON_ERROR));
    }

    public function getActiveProducts(): array
    {
        $todayManila = $this->todayManila();
        $stmt = $this->pdo->prepare(
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
                        SUM(CASE WHEN expiration_date IS NULL OR expiration_date >= ? THEN remaining_quantity ELSE 0 END) AS sellable_quantity,
                        MIN(CASE WHEN expiration_date IS NULL OR expiration_date >= ? THEN expiration_date ELSE NULL END) AS next_expiration_date
                 FROM product_batches
                 WHERE remaining_quantity > 0
                 GROUP BY product_id
             ) batch_available ON batch_available.product_id = p.product_id
             WHERE p.status = 'active'
               AND i.quantity_on_hand > 0
               AND COALESCE(batch_available.sellable_quantity, i.quantity_on_hand) > 0
             ORDER BY p.product_name"
        );
        $stmt->execute([$todayManila, $todayManila]);
        return $stmt->fetchAll();
    }

    private function sanitizeCart(array $cart): array
    {
        $quantities = [];
        foreach ($cart as $item) {
            if (!is_array($item)) {
                throw new DomainException('Your cart is empty or invalid.');
            }
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $sellingForm = (string)($item['selling_form'] ?? 'piece');
            if ($sellingForm === '') {
                $sellingForm = 'piece';
            }
            if ($sellingForm !== 'piece' && $sellingForm !== 'pack') {
                throw new DomainException('Cart item selling form must be piece or pack.');
            }
            $rawQty = $item['qty'] ?? null;
            $isPositiveInt = (is_int($rawQty) && $rawQty > 0) || (is_string($rawQty) && preg_match('/^[1-9]\d*$/D', $rawQty));
            if (!$isPositiveInt) {
                throw new DomainException('Cart item quantity must be a positive whole number.');
            }
            $qty = (int)$rawQty;
            $key = $productId . ':' . $sellingForm;
            if (!isset($quantities[$key])) {
                $quantities[$key] = [
                    'product_id' => $productId,
                    'selling_form' => $sellingForm,
                    'qty' => 0,
                ];
            }
            $quantities[$key]['qty'] += $qty;
        }

        ksort($quantities);
        return array_values($quantities);
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
        $conflicts = [];

        $itemsByProduct = [];
        foreach ($cleanCart as $item) {
            $itemsByProduct[$item['product_id']][] = $item;
        }

        $productDemands = [];
        $productContext = [];

        foreach ($itemsByProduct as $productId => $productItems) {
            $product = $this->lockProductForCheckout($productId);
            if (!$product) {
                foreach ($productItems as $item) {
                    $conflicts[] = [
                        'product_id' => $productId,
                        'product_name' => "Product #{$productId}",
                        'requested_qty' => $item['qty'],
                        'available_stock' => 0,
                    ];
                }
                continue;
            }

            $packSize = isset($product['pack_size']) && $product['pack_size'] !== null ? (int)$product['pack_size'] : null;
            $packPrice = isset($product['pack_price']) && $product['pack_price'] !== null ? (float)$product['pack_price'] : null;
            $hasPackConfig = $packSize !== null && $packSize > 1 && $packPrice !== null && $packPrice > 0;

            $packDemand = 0;
            $pieceDemand = 0;
            foreach ($productItems as $item) {
                if ($item['selling_form'] === 'pack') {
                    $packDemand += $item['qty'];
                } else {
                    $pieceDemand += $item['qty'];
                }
            }

            $productCost = isset($product['cost_price']) && $product['cost_price'] !== null && $product['cost_price'] !== ''
                ? (float)$product['cost_price']
                : null;

            $productDemands[$productId] = [
                'product_id' => $productId,
                'product_name' => (string)($product['product_name'] ?? "product #{$productId}"),
                'pack_demand' => $packDemand,
                'piece_demand' => $pieceDemand,
                'pack_size' => $packSize,
                'cost_price' => $productCost,
                'has_pack_config' => $hasPackConfig,
                'quantity_on_hand' => (int)($product['quantity_on_hand'] ?? 0),
            ];

            $productContext[$productId] = [
                'product' => $product,
                'pack_size' => $packSize,
                'pack_price' => $packPrice,
                'has_pack_config' => $hasPackConfig,
                'items' => $productItems,
            ];
        }

        $cartPlan = (new BatchAllocationEngine($this->pdo))->planCartDemands($productDemands);
        if (!empty($cartPlan['conflicts'])) {
            foreach ($cartPlan['conflicts'] as $c) {
                $conflicts[] = $c;
            }
        }

        if (!empty($conflicts)) {
            throw new StockConflictException($conflicts);
        }

        $productPlans = $cartPlan['product_plans'];
        $byProduct = $cartPlan['by_product'];

        foreach ($productContext as $productId => $ctx) {
            $product = $ctx['product'];
            $packSize = $ctx['pack_size'];
            $packPrice = $ctx['pack_price'];
            $hasPackConfig = $ctx['has_pack_config'];
            $planResult = $byProduct[$productId];

            $packBatchPlan = $planResult['pack_batch_plan'];
            $pieceBatchPlan = $planResult['piece_batch_plan'];

            foreach ($ctx['items'] as $item) {
                $isPack = $item['selling_form'] === 'pack';
                $unitPrice = $isPack ? (float)$packPrice : (float)$product['unit_price'];
                $subtotal = $unitPrice * $item['qty'];
                $baseUnitQty = $isPack ? ($item['qty'] * $packSize) : $item['qty'];
                $total += $subtotal;

                $batchPlan = $isPack ? $packBatchPlan : $pieceBatchPlan;

                $items[] = [
                    'product_id' => $productId,
                    'selling_form' => $item['selling_form'],
                    'quantity' => $item['qty'],
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'base_unit_quantity' => $baseUnitQty,
                    'pack_size' => $isPack ? $packSize : ($hasPackConfig ? $packSize : null),
                    'batch_plan' => $batchPlan,
                    'product_plan' => $productPlans[$productId] ?? null,
                    'category_id' => (int)($product['category_id'] ?? 0),
                    'product_name' => (string)($product['product_name'] ?? ''),
                    'available_stock' => (int)$product['quantity_on_hand'],
                    'batch_state' => $planResult['batch_state'] ?? [],
                ];
            }
        }

        return ['total' => $total, 'items' => $items, 'plans' => $productPlans];
    }

    public static function planCombinedDemand(
        PDO $pdo,
        int $productId,
        string $productName,
        int $packDemand,
        int $pieceDemand,
        ?int $packSize,
        ?float $productCost = null,
        bool $hasPackConfig = false,
        int $quantityOnHand = 0
    ): array {
        return (new BatchAllocationEngine($pdo))->planCombinedDemand(
            $productId,
            $productName,
            $packDemand,
            $pieceDemand,
            $packSize,
            $productCost,
            $hasPackConfig,
            $quantityOnHand
        );
    }

    public static function resolveBatchCost(array $batch, ?float $productCost): array
    {
        return BatchAllocationEngine::resolveBatchCost($batch, $productCost);
    }

    private function lockProductForCheckout(int $productId): ?array
    {
        $prodCols = self::getTableColumns($this->pdo, 'products');
        $costSelect = isset($prodCols['cost_price']) ? ', p.cost_price' : '';
        $packSelect = isset($prodCols['pack_size']) ? ', p.pack_size, p.pack_barcode, p.pack_price' : '';
        $stmt = $this->pdo->prepare(
            "SELECT p.unit_price, p.category_id, p.status, p.product_name, i.quantity_on_hand{$costSelect}{$packSelect}
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

    private function assertRegisterTransactionLimit(int $shiftId, float $amount): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT r.transaction_limit, r.name
                 FROM cashier_shifts cs
                 JOIN registers r ON r.register_id = cs.register_id
                 WHERE cs.shift_id = ?'
            );
            $stmt->execute([$shiftId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['transaction_limit'] !== null && (float)$row['transaction_limit'] > 0) {
                $limit = (float)$row['transaction_limit'];
                if ($amount > $limit) {
                    throw new DomainException(sprintf(
                        'Sale amount (₱%s) exceeds the register transaction limit of ₱%s for %s.',
                        number_format($amount, 2),
                        number_format($limit, 2),
                        $row['name'] ?? 'this register'
                    ));
                }
            }
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable) {
            // Register or column not present
        }
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

    private function resolvePromotion(array $items, float $grossTotal, int $selected): array
    {
        $default = [
            'discount_type' => 'none', 'discount_value' => 0.0, 'discount_amount' => 0.0,
            'discount_reason' => null, 'discount_authorized_by' => null,
            'promotion_id' => null, 'promotion_name' => null,
            'eligible_promotions' => [],
        ];
        try {
            $promotions = $this->pdo->query(
                "SELECT * FROM promotions WHERE status='active' AND starts_at<=NOW() AND ends_at>=NOW() ORDER BY promotion_id" . $this->rowLock()
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Older isolated schemas predate promotions. A query/connection
            // failure must never silently remove a discount from a reviewed sale.
            if ((int)($e->errorInfo[1] ?? 0) !== 1146 && !str_contains($e->getMessage(), 'no such table: promotions')) throw $e;
            if ($selected) throw new DomainException('The selected promotion is no longer eligible. Select another promotion or No promotion and review the updated total.');
            return $default;
        }
        $selection = $default;
        $eligiblePromotions = [];
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
            $eligiblePromotions[] = $promotion + ['applied_amount' => $amount];
            if ((int)$promotion['promotion_id'] === $selected) {
                $selection = [
                    'discount_type' => (string)$promotion['discount_type'],
                    'discount_value' => $value,
                    'discount_amount' => $amount,
                    'discount_reason' => 'Promotion: ' . $promotion['promotion_name'],
                    'discount_authorized_by' => null,
                    'promotion_id' => (int)$promotion['promotion_id'],
                    'promotion_name' => (string)$promotion['promotion_name'],
                ];
            }
        }
        if ($selected && $selection['promotion_id'] === null) throw new DomainException('The selected promotion is no longer eligible. Select another promotion or No promotion and review the updated total.');
        $selection['eligible_promotions'] = $eligiblePromotions;
        return $selection;
    }

    private function resolvePayment(string $paymentMethod, array $paymentDetails, float $total): array
    {
        if (!in_array($paymentMethod, ['cash', 'card', 'ewallet'], true)) {
            throw new DomainException('Choose cash, card or e-wallet payment.');
        }
        $cashReceived = $paymentMethod === 'cash' ? (float)($paymentDetails['cash_received'] ?? 0) : null;
        if ($paymentMethod === 'cash' && (!is_finite($cashReceived) || $cashReceived < 0 || !is_numeric($paymentDetails['cash_received'] ?? null))) {
            throw new DomainException('Enter a valid cash amount.');
        }
        $cashReceived = $cashReceived === null ? null : round($cashReceived, 2);
        $changeDue = $paymentMethod === 'cash' ? round(max(0, $cashReceived - $total), 2) : null;
        $paymentReference = $paymentMethod !== 'cash' ? trim((string)($paymentDetails['payment_reference'] ?? '')) : null;

        if ($paymentMethod === 'cash' && $cashReceived < $total) {
            throw new RuntimeException('Cash received is less than the sale total.');
        }
        if ($paymentMethod !== 'cash' && $paymentReference === '') {
            throw new RuntimeException('Payment reference is required for card or e-wallet payments.');
        }
        if ($paymentMethod === 'ewallet') {
            $isGCash = !empty($paymentDetails['is_gcash'])
                || !empty($paymentDetails['gcash'])
                || (isset($paymentDetails['ewallet_provider']) && strtolower((string)$paymentDetails['ewallet_provider']) === 'gcash')
                || preg_match('/^[0-9\s-]+$/', (string)$paymentReference)
                || (is_string($paymentReference) && strlen($paymentReference) > 0 && !preg_match('/^[A-Za-z]+-[0-9]+$/', $paymentReference));
            if ($isGCash) {
                $normalizedRef = preg_replace('/[\s-]+/u', '', (string)$paymentReference);
                $gcashConfig = is_file(__DIR__ . '/../../config/gcash.php') ? (require __DIR__ . '/../../config/gcash.php') : ['min_length' => 13, 'max_length' => 13];
                $min = (int)($gcashConfig['min_length'] ?? 13);
                $max = (int)($gcashConfig['max_length'] ?? 13);
                if (!preg_match('/\A[0-9]{' . $min . ',' . $max . '}\z/', $normalizedRef)) {
                    throw new DomainException('Enter a valid ' . ($min === $max ? $min . '-digit' : "{$min}-{$max} digit") . ' Philippine GCash reference number.');
                }
                $paymentReference = $normalizedRef;
            }
        }
        if ($paymentMethod !== 'cash'
            && (array_key_exists('checkout_attempt', $paymentDetails) || array_key_exists('reviewed_quote', $paymentDetails) || array_key_exists('payment_verified', $paymentDetails))
            && ($paymentDetails['payment_verified'] ?? false) !== true) {
            throw new DomainException('Verify the external payment before recording it.');
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

    private function applyPackOpenings(array $productPlans, int $saleId, int $userId): void
    {
        $this->ledger->applyPackOpenings($productPlans, $saleId, $userId);
    }

    /**
     * Authoritative sale item persistence and inventory fulfillment across the deep ledger seam.
     */
    private function fulfillSaleItems(int $saleId, array $sale, int $userId): void
    {
        $this->ledger->applyPackOpenings($sale['plans'] ?? [], $saleId, $userId);
        foreach ($sale['items'] as $item) {
            $this->recordSaleItem($saleId, $item, $userId);
        }
    }

    private function recordSaleItem(int $saleId, array $item, int $userId): void
    {
        $saleItemCols = self::getTableColumns($this->pdo, 'sale_items');
        $columns = ['sale_id', 'product_id', 'quantity', 'unit_price', 'subtotal'];
        $placeholders = ['?', '?', '?', '?', '?'];
        $values = [
            $saleId,
            $item['product_id'],
            $item['quantity'],
            $item['unit_price'],
            $item['subtotal'],
        ];

        $sellingForm = $item['selling_form'] ?? 'piece';
        $packSize = isset($item['pack_size']) && (int)$item['pack_size'] > 0 ? (int)$item['pack_size'] : null;

        if (isset($saleItemCols['selling_form'])) {
            $columns[] = 'selling_form';
            $placeholders[] = '?';
            $values[] = $sellingForm;
        }
        if (isset($saleItemCols['pack_size'])) {
            $columns[] = 'pack_size';
            $placeholders[] = '?';
            $values[] = $packSize;
        }

        $this->pdo->prepare(
            "INSERT INTO sale_items (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
        )->execute($values);
        $saleItemId = (int)$this->pdo->lastInsertId();

        $this->ledger->depleteSaleItem($saleId, $saleItemId, $item, $userId);
    }

    public static function getTableColumns(PDO $pdo, string $table): array
    {
        static $cache = [];
        $key = spl_object_hash($pdo) . ':' . $table;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $cols = [];
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $rawCols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rawCols as $col) {
                    $cols[$col['Field']] = true;
                }
            } else {
                $rawCols = $pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rawCols as $col) {
                    $cols[$col['name']] = true;
                }
            }
        } catch (Throwable) {
            $cols = [];
        }

        return $cache[$key] = $cols;
    }

    private function getSalesColumns(): array
    {
        return self::getTableColumns($this->pdo, 'sales');
    }

    private function buildFefoBatchPlan(int $productId, int $quantity, ?float $productCost = null): array
    {
        $batchCountStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM product_batches WHERE product_id = ? AND remaining_quantity > 0"
        );
        $batchCountStmt->execute([$productId]);
        if ((int)$batchCountStmt->fetchColumn() === 0) {
            return [];
        }

        if ($productCost === null) {
            try {
                $prodCols = self::getTableColumns($this->pdo, 'products');
                if (isset($prodCols['cost_price'])) {
                    $pStmt = $this->pdo->prepare("SELECT cost_price FROM products WHERE product_id = ?");
                    $pStmt->execute([$productId]);
                    $val = $pStmt->fetchColumn();
                    if ($val !== false && $val !== null && $val !== '') {
                        $productCost = (float)$val;
                    }
                }
            } catch (Throwable) {
                // Table might not exist in some unit tests
            }
        }

        $pbCols = self::getTableColumns($this->pdo, 'product_batches');
        $srCols = self::getTableColumns($this->pdo, 'stock_receiving');

        $selectCols = ['pb.batch_id', 'pb.remaining_quantity', 'pb.expiration_date'];
        if (isset($pbCols['cost_price'])) {
            $selectCols[] = 'pb.cost_price AS batch_cost_price';
        } else {
            $selectCols[] = 'NULL AS batch_cost_price';
        }

        $hasSrJoin = false;
        if (isset($pbCols['receiving_id']) && !empty($srCols)) {
            $hasSrJoin = true;
            $selectCols[] = isset($srCols['cost_price']) ? 'sr.cost_price AS receiving_cost_price' : 'NULL AS receiving_cost_price';
            $selectCols[] = isset($srCols['total_cost']) ? 'sr.total_cost AS receiving_total_cost' : 'NULL AS receiving_total_cost';
            $selectCols[] = isset($srCols['accepted_qty']) ? 'sr.accepted_qty AS receiving_accepted_qty' : 'NULL AS receiving_accepted_qty';
            $selectCols[] = isset($srCols['received_qty']) ? 'sr.received_qty AS receiving_received_qty' : 'NULL AS receiving_received_qty';
        } else {
            $selectCols[] = 'NULL AS receiving_cost_price';
            $selectCols[] = 'NULL AS receiving_total_cost';
            $selectCols[] = 'NULL AS receiving_accepted_qty';
            $selectCols[] = 'NULL AS receiving_received_qty';
        }

        $todayManila = $this->todayManila();
        $sql = "SELECT " . implode(', ', $selectCols) . "
                FROM product_batches pb ";
        if ($hasSrJoin) {
            $sql .= "LEFT JOIN stock_receiving sr ON sr.receiving_id = pb.receiving_id ";
        }
        $sql .= "WHERE pb.product_id = ?
                   AND pb.remaining_quantity > 0
                   AND (pb.expiration_date IS NULL OR pb.expiration_date >= ?)
                 ORDER BY
                   CASE WHEN pb.expiration_date IS NULL THEN 1 ELSE 0 END,
                   pb.expiration_date ASC,
                   pb.date_received ASC,
                   pb.batch_id ASC" . $this->rowLock();

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productId, $todayManila]);

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

            $unitCost = null;
            $costStatus = 'missing';
            $costSource = null;

            if ($batch['batch_cost_price'] !== null && $batch['batch_cost_price'] !== '') {
                $unitCost = round((float)$batch['batch_cost_price'], 2);
                $costStatus = 'recorded';
                $costSource = 'batch';
            } elseif ($batch['receiving_cost_price'] !== null && $batch['receiving_cost_price'] !== '') {
                $unitCost = round((float)$batch['receiving_cost_price'], 2);
                $costStatus = 'recorded';
                $costSource = 'receiving';
            } elseif ($batch['receiving_total_cost'] !== null && (float)$batch['receiving_total_cost'] > 0) {
                $acceptedQty = (int)($batch['receiving_accepted_qty'] ?: ($batch['receiving_received_qty'] ?: 0));
                if ($acceptedQty > 0) {
                    $unitCost = round((float)$batch['receiving_total_cost'] / $acceptedQty, 2);
                    $costStatus = 'recorded';
                    $costSource = 'receiving';
                }
            } elseif ($productCost !== null && $productCost > 0) {
                $unitCost = round((float)$productCost, 2);
                $costStatus = 'estimated';
                $costSource = 'product';
            } else {
                $unitCost = null;
                $costStatus = 'missing';
                $costSource = null;
            }

            $plan[] = [
                'batch_id' => (int)$batch['batch_id'],
                'quantity' => $take,
                'available_quantity' => (int)$batch['remaining_quantity'],
                'expiration_date' => $batch['expiration_date'],
                'unit_cost' => $unitCost,
                'cost_price' => $unitCost,
                'cost_status' => $costStatus,
                'cost_source' => $costSource,
                'cost_provenance' => $costSource ?? $costStatus,
            ];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $productStmt = $this->pdo->prepare('SELECT product_name FROM products WHERE product_id = ?');
            $productStmt->execute([$productId]);
            $productName = $productStmt->fetchColumn() ?: "product #{$productId}";
            $available = $quantity - $remaining;
            throw new StockConflictException([[
                'product_id' => $productId,
                'product_name' => (string)$productName,
                'requested_qty' => $quantity,
                'available_stock' => max(0, $available),
            ]]);
        }

        return $plan;
    }

    private function todayManila(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(\App\Services\PhilippineTime::ZONE)))->format('Y-m-d');
    }
}
