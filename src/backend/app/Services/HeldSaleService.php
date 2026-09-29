<?php
// app/Services/HeldSaleService.php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

require_once __DIR__ . '/../Audit/AuditRecordCategory.php';
require_once __DIR__ . '/../Authorization/RoleCapabilityPolicy.php';

/**
 * Held Sales (ticket #91).
 *
 * A held sale is a suspended cart, and suspended work rather than floating
 * work: it belongs to the Cashier who held it and to the Cashier
 * Shift that was open at the time. Three consequences follow, and they are the
 * whole of this service.
 *
 * Holding requires the authenticated Cashier's own open shift, and the shift is
 * derived from that Cashier rather than read from the request, so there is no
 * way to hold a cart against somebody else's drawer. The same derivation makes
 * listing and resuming safe by construction: a Cashier only ever sees the rows
 * their own user id matches.
 *
 * A held sale stays unresolved from the moment it is held until it becomes one of
 * two things — a sale, or an explained abandonment. Resuming does not resolve it,
 * because a Cashier who resumes a cart and then walks away has not finished
 * anything, and a shift must not close over a cart that is still held at the
 * till. That is why the closure invariant in CashierShiftService counts 'held'
 * and 'resumed' alike.
 *
 * Discarding is deliberate: a reason from a fixed set, a note when the reason is
 * Other, a Protected Audit Record naming both the actor and the cart, and no cash
 * effect whatsoever. Nothing about a discard touches the drawer, because a cart
 * that was never paid for has no money to give back.
 */
class HeldSaleService
{
    public const AUDIT_MODULE = 'Held Sales';
    public const AUDIT_ACTION_DISCARDED = 'Held sale discarded';

    /**
     * The statuses that mean "this held sale is still owed a decision".
     *
     * 'resumed' is in here on purpose. Resuming hands the cart back to the till,
     * it does not finish it; only a completed sale or an explained discard does.
     */
    public const UNRESOLVED_STATUSES = ['held', 'resumed'];

    /** The one unresolved status a checkout may settle. See assertCompletable(). */
    public const RESUMED_STATUS = 'resumed';

    /**
     * The fixed initial set of reasons a cart can be discarded for.
     *
     * A free-text reason explains nothing nine months later, because every Cashier
     * writes it differently. A fixed set is comparable, and 'other' carries the
     * one case that genuinely needs words — with a note, or the discard is
     * refused, exactly as an unclassified refund is.
     */
    public const DISCARD_REASONS = [
        'customer_cancelled' => 'Customer cancelled the purchase',
        'wrong_item' => 'Wrong item added to the cart',
        'duplicate_sale' => 'Duplicate of a sale already completed',
        'items_unavailable' => 'Items no longer available',
        'entered_in_error' => 'Held in error',
        'other' => 'Other',
    ];

    private RoleCapabilityPolicy $policy;

    public function __construct(
        private PDO $pdo,
        private CashierShiftService $shifts,
        ?RoleCapabilityPolicy $policy = null
    ) {
        $this->policy = $policy ?? new RoleCapabilityPolicy();
    }

    /**
     * The unresolved held sales one Cashier owns, newest first.
     *
     * Scoped by user id rather than by anything the request says, which is the
     * whole of the visibility rule: a Cashier sees their own suspended work and
     * nobody else's.
     */
    public function openForCashier(int $cashierId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::UNRESOLVED_STATUSES), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT held_sale_id, reference_no, customer_label, status, item_count, total_amount, created_at, expires_at
             FROM held_sales
             WHERE cashier_id = ? AND status IN ({$placeholders})
             ORDER BY created_at DESC, held_sale_id DESC"
        );
        $stmt->execute(array_merge([$cashierId], self::UNRESOLVED_STATUSES));

        return array_map([$this, 'shapeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Holds a cart under the authenticated Cashier's own open Cashier Shift.
     *
     * The cart is re-derived from the database rather than trusted: prices,
     * names, and stock come from the products table, and a quantity is clamped to
     * what is actually on hand. A client that posts a fabricated price holds a
     * cart that will not be the one it is charged at.
     *
     * @return array{held_sale_id: int, reference_no: string}
     */
    public function hold(int $cashierId, string $actorRole, array $cart, ?string $customerLabel = null): array
    {
        $this->requireCashierWorkspace($actorRole, 'hold a sale');
        // The shift is read from the Cashier, never from the request, so a cart
        // cannot be held against a drawer this Cashier does not own.
        $shift = $this->requireHoldingShift($cashierId);

        $rehydrated = $this->rehydrateCart($cart);
        if ($rehydrated === []) {
            throw new RuntimeException('No active products can be held.');
        }

        $itemCount = 0;
        $total = 0.0;
        foreach ($rehydrated as $line) {
            $itemCount += (int)$line['qty'];
            $total += (float)$line['price'] * (int)$line['qty'];
        }

        $reference = 'H' . date('ymdHis') . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
        $this->pdo->prepare(
            'INSERT INTO held_sales
                (cashier_id, shift_id, reference_no, customer_label, cart_json, item_count, total_amount, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $cashierId,
            (int)$shift['shift_id'],
            $reference,
            $customerLabel !== null && trim($customerLabel) !== '' ? trim($customerLabel) : null,
            json_encode($rehydrated, JSON_UNESCAPED_UNICODE),
            $itemCount,
            $total,
            $this->expiry(),
        ]);

        return [
            'held_sale_id' => (int)$this->pdo->lastInsertId(),
            'reference_no' => $reference,
        ];
    }

    /**
     * Hands a held cart back to the till, keeping the Cashier Shift it belongs to.
     *
     * Resuming is repeatable on purpose. A Cashier who reloads the page, or whose
     * browser drops the cart, needs the same cart back rather than an error —
     * and because a resumed cart is still unresolved, nothing is lost by handing
     * it back twice.
     *
     * @return array{held_sale_id: int, shift_id: int, cart: array}
     */
    public function resume(int $cashierId, string $actorRole, int $heldSaleId): array
    {
        $this->requireCashierWorkspace($actorRole, 'resume a held sale');
        $heldSale = $this->requireOwnUnresolved($cashierId, $heldSaleId);

        if ((string)$heldSale['status'] !== 'resumed') {
            $this->pdo->prepare(
                "UPDATE held_sales SET status = 'resumed' WHERE held_sale_id = ? AND status = 'held'"
            )->execute([$heldSaleId]);
        }

        return [
            'held_sale_id' => (int)$heldSale['held_sale_id'],
            'shift_id' => (int)$heldSale['shift_id'],
            'cart' => json_decode((string)$heldSale['cart_json'], true) ?: [],
        ];
    }

    /**
     * Abandons a held cart, with a reason, for good.
     *
     * A discard is a sensitive non-sale action, so it is recorded: a Cashier
     * silently dropping a cart is exactly the kind of thing a Store owner asks
     * about later, and without a record there is no way to answer. It has no cash
     * effect, because a cart that was never paid for has no money in it to give
     * back — which is also why nothing here touches the drawer or the shift's
     * expected cash.
     */
    public function discard(int $cashierId, string $actorRole, int $heldSaleId, string $reason, ?string $note = null): void
    {
        $this->requireCashierWorkspace($actorRole, 'discard a held sale');
        $reason = $this->requireDiscardReason($reason);
        $note = $note !== null ? trim($note) : '';
        if ($reason === 'other' && $note === '') {
            throw new DomainException('Add a note explaining why this held sale was discarded.');
        }
        if (mb_strlen($note) > 255) {
            throw new InvalidArgumentException('Keep the discard note under 255 characters.');
        }

        $heldSale = $this->requireOwnUnresolved($cashierId, $heldSaleId);

        $this->pdo->prepare(
            "UPDATE held_sales
             SET status = 'discarded', resolved_at = NOW(), discard_reason = ?, discard_note = ?, discarded_by = ?
             WHERE held_sale_id = ?"
        )->execute([
            $reason,
            $note !== '' ? $note : null,
            $cashierId,
            (int)$heldSale['held_sale_id'],
        ]);

        $this->audit($cashierId, (int)$heldSale['held_sale_id'], (int)$heldSale['shift_id'], [
            'reference_no' => (string)$heldSale['reference_no'],
            'discard_reason' => $reason,
            'discard_reason_label' => self::DISCARD_REASONS[$reason],
            'discard_note' => $note !== '' ? $note : null,
            'item_count' => (int)$heldSale['item_count'],
            'total_amount' => round((float)$heldSale['total_amount'], 2),
        ]);
    }

    /**
     * Settles that a resumed held sale may be completed by this checkout.
     *
     * $shiftId is the shift checkout already resolved for the sale, and the held
     * sale must belong to that same shift. Checking it here — row-locked, inside
     * the sale's own transaction — is what makes a resumed held sale keep its
     * attribution all the way through: it is completed on the drawer it was held
     * under, or not at all.
     *
     * Only a *resumed* held sale qualifies. A cart that is still merely 'held' is
     * not on the till, and there is no honest checkout that settles it: a Cashier
     * who pays for a cart has resumed it first, so accepting the other status
     * would only ever let a hand-crafted request mark a cart finished without its
     * contents ever being sold.
     *
     * @return array The locked held sale row.
     */
    public function assertCompletable(int $cashierId, int $heldSaleId, int $shiftId): array
    {
        $heldSale = $this->requireOwnUnresolved($cashierId, $heldSaleId, true, [self::RESUMED_STATUS]);
        if ((int)$heldSale['shift_id'] !== $shiftId) {
            throw new DomainException('That held sale belongs to a different Cashier Shift and cannot be completed here.');
        }

        return $heldSale;
    }

    /**
     * Records that a resumed held sale has been settled by a sale.
     *
     * Called inside the checkout transaction, so the held sale and the sale that
     * settles it commit or roll back together. The link records that this sale is
     * the one that finished the held sale, on this Cashier Shift — it is not a
     * claim that the two carts match item for item, because a Cashier is free to
     * change a cart at the till and the sale is built from the cart they actually
     * paid for.
     */
    public function markCompleted(int $heldSaleId, int $saleId): void
    {
        $this->pdo->prepare(
            "UPDATE held_sales SET status = 'completed', resolved_at = NOW(), sale_id = ? WHERE held_sale_id = ?"
        )->execute([$saleId, $heldSaleId]);
    }

    /**
     * Expires held sales that belong to no open Cashier Shift.
     *
     * A held sale whose shift is still open is not expired by the passage of time.
     * It is the Cashier's to resolve, and the shift is held open until they do, so
     * quietly retiring one would be the single path around both the discard reason
     * and the closure invariant. What this does clear is the residue nobody is
     * waiting on: held sales recorded before this deployment, which carry no shift,
     * and those whose shift closed before the invariant existed. Those are orphans
     * in the only sense the word is used for here — it belongs to no drawer.
     *
     * @return int How many were expired.
     */
    public function sweepExpiredOrphans(): int
    {
        $placeholders = implode(',', array_fill(0, count(self::UNRESOLVED_STATUSES), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE held_sales
             SET status = 'expired', resolved_at = NOW()
             WHERE status IN ({$placeholders})
               AND expires_at IS NOT NULL
               AND expires_at < NOW()
               AND (shift_id IS NULL OR NOT EXISTS (
                    SELECT 1 FROM cashier_shifts cs
                    WHERE cs.shift_id = held_sales.shift_id AND cs.status = 'open'
               ))"
        );
        $stmt->execute(self::UNRESOLVED_STATUSES);

        return $stmt->rowCount();
    }

    /**
     * The open shift a cart may be held on, with the lock settled.
     *
     * Both refusals live in the service rather than on the page, because the
     * point of sale is not the only thing that can reach this: a locked Register
     * is a break, and holding a cart on a Register its owner locked is exactly
     * the act the lock forbids.
     */
    private function requireHoldingShift(int $cashierId): array
    {
        $this->shifts->requireUnlockedRegister($cashierId);
        $shift = $this->shifts->getOpenShift($cashierId);
        if ($shift === null) {
            throw new DomainException('Open a Cashier Shift before holding a sale.');
        }

        return $shift;
    }

    /**
     * Loads a held sale that is this Cashier's and in one of $statuses.
     *
     * One query carries every half of the rule, so there is no window in which a
     * held sale could be read as the Cashier's and then act as somebody else's.
     * The refusal deliberately does not say which half failed: a Cashier learns
     * that the held sale is not theirs to act on, not whether it exists at all.
     */
    private function requireOwnUnresolved(
        int $cashierId,
        int $heldSaleId,
        bool $lock = false,
        array $statuses = self::UNRESOLVED_STATUSES
    ): array {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM held_sales
             WHERE held_sale_id = ? AND cashier_id = ? AND status IN ({$placeholders})"
            . ($lock ? $this->rowLock() : '')
        );
        $stmt->execute(array_merge([$heldSaleId, $cashierId], $statuses));
        $heldSale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$heldSale) {
            throw new DomainException('Held sale not found or already resolved.');
        }

        return $heldSale;
    }

    /**
     * When a held sale stops being worth listing by age: a day after it is held.
     *
     * The expiry is measured from the database's own clock rather than PHP's, so
     * the sweep and the insert can never disagree about what "yesterday" means.
     * It is computed here rather than written as `DATE_ADD(NOW(), INTERVAL 24
     * HOUR)` because that expression is MySQL syntax and the Store's drivers are
     * not all MySQL — the same dialect split SalesWorkflowService::rowLock()
     * makes.
     */
    private function expiry(): string
    {
        $now = $this->pdo
            ->query($this->isMysql() ? 'SELECT NOW()' : "SELECT datetime('now')")
            ->fetchColumn();

        return date('Y-m-d H:i:s', strtotime((string)$now . ' +24 hours'));
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /**
     * The row lock that holds a cart for the rest of the transaction.
     *
     * Dialect-aware for the same reason SalesWorkflowService::rowLock() is: MySQL
     * takes a real row lock, which is what stops two requests completing the same
     * cart at once, and a driver without row locking serialises writers with the
     * transaction itself rather than failing the query.
     */
    private function rowLock(): string
    {
        return $this->isMysql() ? ' FOR UPDATE' : '';
    }

    private function requireDiscardReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || !array_key_exists($reason, self::DISCARD_REASONS)) {
            throw new DomainException('Choose a reason for discarding this held sale.');
        }

        return $reason;
    }

    /**
     * Refuses anything the Cashier workspace alone may do.
     *
     * The wording names the action rather than the rule, because a Cashier who is
     * trying to discard a cart is not looking for a lesson about workspaces.
     */
    private function requireCashierWorkspace(string $actorRole, string $action): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::OPERATE_POINT_OF_SALE)) {
            throw new DomainException(
                "Only the Cashier workspace can {$action}. Switch to your Cashier workspace to carry on."
            );
        }
    }

    /**
     * Rebuilds a requested cart from what the Store actually stocks and charges.
     *
     * A held cart is only as good as its contents when it is resumed, so nothing
     * the client sent is believed: names, prices, and availability are read back
     * from the products table, and a quantity above what is on hand is clamped.
     */
    private function rehydrateCart(array $cart): array
    {
        $quantities = [];
        foreach ($cart as $productId => $line) {
            $productId = (int)$productId;
            $quantity = (int)(is_array($line) ? ($line['qty'] ?? 0) : 0);
            if ($productId > 0 && $quantity > 0) {
                $quantities[$productId] = $quantity;
            }
        }
        if ($quantities === []) {
            throw new RuntimeException('Cart is empty.');
        }

        $ids = array_keys($quantities);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT p.product_id, p.product_name, p.sku, p.barcode, p.unit_price,
                    p.reorder_level, p.safety_stock, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON i.product_id = p.product_id
             WHERE p.status = 'active' AND p.product_id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $stocked = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $stocked[(int)$product['product_id']] = $product;
        }

        $rehydrated = [];
        foreach ($quantities as $productId => $quantity) {
            if (!isset($stocked[$productId])) {
                continue;
            }
            $product = $stocked[$productId];
            $quantity = min($quantity, (int)$product['quantity_on_hand']);
            if ($quantity < 1) {
                continue;
            }
            $rehydrated[$productId] = [
                'name' => (string)$product['product_name'],
                'price' => (float)$product['unit_price'],
                'qty' => $quantity,
                'stock' => (int)$product['quantity_on_hand'],
                'reorder_level' => (int)$product['reorder_level'],
                'safety_stock' => (int)$product['safety_stock'],
                'sku' => $product['sku'],
                'barcode' => $product['barcode'],
            ];
        }

        return $rehydrated;
    }

    /** Casts a listed cart to the types the point of sale expects, never from the database's strings. */
    private function shapeRow(array $row): array
    {
        return [
            'id' => (int)$row['held_sale_id'],
            'held_sale_id' => (int)$row['held_sale_id'],
            'reference_no' => (string)$row['reference_no'],
            'customer_label' => $row['customer_label'],
            'status' => (string)$row['status'],
            'item_count' => (int)$row['item_count'],
            'total_amount' => (float)$row['total_amount'],
            'created_at' => $row['created_at'],
            'expires_at' => $row['expires_at'],
        ];
    }

    /**
     * Writes the Protected Audit Record for a discarded cart.
     *
     * Both halves an auditor needs are in the payload: who acted and what they
     * acted on. The Cashier Shift is included so the discard is readable next to
     * the drawer it was held under, and the reason is stored with its label so a
     * later reader sees "Customer cancelled the purchase" rather than a code they
     * would have to look up in code that may since have changed.
     */
    private function audit(int $cashierId, int $heldSaleId, int $shiftId, array $details): void
    {
        $payload = array_merge($details, [
            'held_sale_id' => $heldSaleId,
            'cashier_id' => $cashierId,
            'shift_id' => $shiftId,
        ]);

        $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
        )->execute([
            $cashierId,
            self::AUDIT_ACTION_DISCARDED,
            AuditRecordCategory::STORE_OPERATION,
            self::AUDIT_MODULE,
            $heldSaleId,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'system',
        ]);
    }
}
