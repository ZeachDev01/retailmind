<?php
// app/Services/CashRefundService.php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use App\Store\StoreWriteGate;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

// FiscalPeriodGuardService predates the namespace and is still global, so it is
// referred to by its plain name rather than through a `use` for it.
require_once __DIR__ . '/../Audit/AuditRecordCategory.php';
require_once __DIR__ . '/../Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
require_once __DIR__ . '/CashierShiftService.php';
require_once __DIR__ . '/FiscalPeriodGuardService.php';

/**
 * Cash Refunds (ticket #92).
 *
 * A Cash Refund is an append-only record, never an edit. The sale it reverses
 * stays exactly as it was written — a completed sale is immutable (#25) — so
 * "how much of this sale has been given back" is a sum over this table rather
 * than a column on the sale. That is the whole reason a refund is a new row:
 * history is added to, never rewritten.
 *
 * Four rules carry the feature, and each of them is settled here rather than on
 * the page, because the page can be posted directly:
 *
 * 1. A refund is issued by a Cashier, in the active Cashier workspace, under
 *    that Cashier's own open and unlocked Cashier Shift. The shift is derived
 *    from the authenticated Cashier and row-locked, so a refund can never pay
 *    out of a drawer this Cashier does not own, a drawer that was locked for a
 *    break (#90), or a shift that closes mid-request. No supervisor approval
 *    is involved and none is required: individual identity, a structured
 *    reason, append-only records, scoped visibility, and a Protected Audit
 *    Record are what make the operation accountable.
 *
 * 2. Nothing may be over-refunded, including concurrently. The sale row is
 *    locked for the whole transaction and every remaining balance is read under
 *    that lock, so two refunds that pass validation at the same moment cannot
 *    both be told there is still one unit left. The per-line cap is the sold
 *    quantity less everything already refunded against it; the per-sale cap is
 *    what the customer actually paid less everything already refunded. The sale
 *    cap is the net rather than the shelf value, which is why a discounted sale
 *    cannot be refunded for more than it charged.
 *
 * 3. Every returned unit is classified Restockable or Damaged, and only a
 *    Restockable unit returns to available inventory. Damaged goods came back
 *    but are not for sale, so putting them back on the shelf would put unsellable
 *    stock into the number the Store plans on. The classification is required
 *    rather than defaulted, because defaulting it is the same as assuming every
 *    return is resellable. Either way the money left the drawer, so a Damaged
 *    refund still consumes the refundable balance and still reduces expected
 *    drawer cash — it is the stock, never the cash, that depends on condition.
 *
 * 4. The refund, its lines, its inventory effects, and its Protected Audit
 *    Record are one transaction. A refund that half-succeeds would hand back
 *    money without returning the goods, or return the goods without a record.
 *
 * A refund of a non-cash sale is settled on the original payment method and is
 * recorded like any other, but only a cash refund reduces expected drawer cash:
 * card and e-wallet money never entered the drawer, so it never leaves it.
 *
 * This ledger is distinct from the pre-existing supervisor-approved sale
 * reversal flow, which is left exactly as it is. The remaining-balance caps here
 * are computed from these refunds, so the two paths do not share a balance.
 */
class CashRefundService
{
    public const AUDIT_MODULE = 'Cash Refunds';
    public const AUDIT_ACTION = 'Cash refund issued';

    /** The only payment method that is settled out of the drawer. */
    public const CASH = 'cash';

    public const RESTOCKABLE = 'restockable';
    public const DAMAGED = 'damaged';

    /**
     * The two conditions a returned unit can be in.
     *
     * There is no third value and no default. A classification the Store did
     * not make is a classification the Inventory team would have to guess at
     * later, and guessing "resellable" is the expensive direction to guess in.
     */
    public const DISPOSITIONS = [
        self::RESTOCKABLE => 'Restockable',
        self::DAMAGED => 'Damaged',
    ];

    /**
     * The fixed initial set of reasons a sale can be refunded for.
     *
     * A free-text reason explains nothing nine months later, because every
     * Cashier writes it differently, and a refund report is only comparable when
     * the reasons are. 'other' carries the one case that genuinely needs words —
     * with a note, or the refund is refused, exactly as an unclassified held sale
     * discard is.
     */
    public const REFUND_REASONS = [
        'customer_return' => 'Customer return',
        'wrong_item' => 'Wrong item',
        'duplicate_sale' => 'Duplicate sale',
        'damaged_item' => 'Damaged item',
        'other' => 'Other',
    ];

    /** The note limit a refund reason shares with the rest of the Store's notes. */
    private const NOTE_MAX_LENGTH = 255;

    private RoleCapabilityPolicy $policy;
    private \FiscalPeriodGuardService $fiscalPeriodGuard;

    public function __construct(
        private PDO $pdo,
        private CashierShiftService $shifts,
        ?RoleCapabilityPolicy $policy = null
    ) {
        $this->policy = $policy ?? new RoleCapabilityPolicy();
        $this->fiscalPeriodGuard = new \FiscalPeriodGuardService($pdo);
    }

    /**
     * Issues a full or partial refund against a completed sale.
     *
     * Everything that can be refused is refused before the first write, and
     * everything that has to be read under a lock is read inside the
     * transaction that writes the refund. @see self::refund()
     *
     * @param array<int, array{quantity: int, disposition: string}> $items Refunded
     *        lines keyed by sale line id. Ids the sale does not carry are refused.
     * @return int The appended Cash Refund.
     */
    public function refund(
        int $cashierId,
        string $actorRole,
        int $saleId,
        array $items,
        string $reason,
        ?string $note = null
    ): int {
        $this->requireCashierWorkspace($actorRole);
        $reason = $this->requireReason($reason);
        $note = $note !== null ? trim($note) : '';
        if ($reason === 'other' && $note === '') {
            throw new DomainException('Add a note explaining this refund.');
        }
        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw new InvalidArgumentException('Keep the refund note under ' . self::NOTE_MAX_LENGTH . ' characters.');
        }
        $requested = $this->requireItems($items);
        // Read before the write gate so a refusal never begins a Store write.
        $this->requireOwnSale($cashierId, $saleId);
        $this->fiscalPeriodGuard->assertOpenForDate($this->saleDate($saleId), 'sales', 'cash refund');
        $this->fiscalPeriodGuard->assertOpenNow('cash_refunds', 'cash refund');
        $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');

        StoreWriteGate::begin($this->pdo);
        try {
            // Resolved and locked inside the transaction, so the drawer that
            // authorizes this refund is the drawer that is still open and
            // unlocked at commit.
            $shiftId = $this->requireOpenShiftId($cashierId);
            $sale = $this->lockOwnSale($cashierId, $saleId);
            $lines = $this->settleLines($sale, $requested);
            $amount = $this->settleAmount($sale, $lines);

            $this->pdo->prepare(
                'INSERT INTO cash_refunds
                    (sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $saleId,
                $shiftId,
                $cashierId,
                $amount,
                (string)$sale['payment_method'],
                $reason,
                $note !== '' ? $note : null,
            ]);
            $refundId = (int)$this->pdo->lastInsertId();

            foreach ($lines as $line) {
                $this->insertRefundLine($refundId, $line);
                if ($line['disposition'] === self::RESTOCKABLE) {
                    $this->restockReturnedUnit($line, $cashierId);
                }
            }

            $this->audit(
                $cashierId,
                $refundId,
                $shiftId,
                $saleId,
                (string)$sale['payment_method'],
                $amount,
                $reason,
                $note,
                $lines
            );

            $this->pdo->commit();

            return $refundId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * What is still refundable from a sale, in the money the customer paid.
     *
     * The net total the customer actually paid, less everything already refunded
     * against it. Shelf value would let a discounted sale be refunded for more
     * than it charged, so the cap is the amount that left the drawer.
     *
     * A sale that is not in the refund ledger at all is still fully refundable,
     * which is what makes every pre-deployment sale refundable rather than
     * accidentally unrefundable.
     */
    public function refundableAmount(int $saleId): float
    {
        $stmt = $this->pdo->prepare('SELECT total_amount FROM sales WHERE sale_id = ?');
        $stmt->execute([$saleId]);
        $paid = $stmt->fetchColumn();
        if ($paid === false) {
            return 0.0;
        }

        $refunded = $this->pdo->prepare('SELECT COALESCE(SUM(refund_amount), 0) FROM cash_refunds WHERE sale_id = ?');
        $refunded->execute([$saleId]);

        return round(max(0.0, (float)$paid - (float)$refunded->fetchColumn()), 2);
    }

    /**
     * How much of a sale's refundable balance is still cash.
     *
     * Zero for a sale that was not paid in cash. A refund settles on the
     * original payment method, so a card sale's balance is refundable and never
     * cash refundable — which is what keeps a card refund out of the drawer.
     */
    public function cashRefundableAmount(int $saleId): float
    {
        $stmt = $this->pdo->prepare('SELECT payment_method FROM sales WHERE sale_id = ?');
        $stmt->execute([$saleId]);
        if ((string)$stmt->fetchColumn() !== self::CASH) {
            return 0.0;
        }

        return $this->refundableAmount($saleId);
    }

    /** How many units of one sold line are still refundable. */
    public function refundableQuantity(int $saleItemId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT si.quantity,
                    COALESCE((SELECT SUM(cri.quantity)
                              FROM cash_refund_items cri
                              JOIN cash_refunds cr ON cr.refund_id = cri.refund_id
                              WHERE cri.sale_item_id = si.sale_item_id), 0) AS refunded_quantity
             FROM sale_items si
             WHERE si.sale_item_id = ?'
        );
        $stmt->execute([$saleItemId]);
        $line = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$line) {
            return 0;
        }

        return max(0, (int)$line['quantity'] - (int)$line['refunded_quantity']);
    }

    /**
     * One sale as a refund form needs it: the header, and every line with what
     * is left of it.
     *
     * Scoped to the Cashier who sold it, so a Cashier can only ever see a sale
     * they are allowed to refund. Returns null rather than a refusal so a page
     * can show "not found" without inventing an answer.
     *
     * @return array<string, mixed>|null
     */
    public function refundableSaleForCashier(int $cashierId, int $saleId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date
             FROM sales s
             WHERE s.sale_id = ? AND s.cashier_id = ?"
        );
        $stmt->execute([$saleId, $cashierId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale) {
            return null;
        }

        $linesStmt = $this->pdo->prepare(
            'SELECT si.sale_item_id, si.product_id, si.quantity, si.unit_price, si.subtotal,
                    p.sku, p.product_name
             FROM sale_items si
             JOIN products p ON p.product_id = si.product_id
             WHERE si.sale_id = ?
             ORDER BY si.sale_item_id'
        );
        $linesStmt->execute([$saleId]);

        $lines = [];
        foreach ($linesStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $line['refundable_quantity'] = $this->refundableQuantity((int)$line['sale_item_id']);
            $line['quantity'] = (int)$line['quantity'];
            $line['unit_price'] = (float)$line['unit_price'];
            $line['subtotal'] = (float)$line['subtotal'];
            $lines[] = $line;
        }

        $refunded = $this->pdo->prepare('SELECT COALESCE(SUM(refund_amount), 0) FROM cash_refunds WHERE sale_id = ?');
        $refunded->execute([$saleId]);

        return [
            'sale_id' => (int)$sale['sale_id'],
            'cashier_id' => (int)$sale['cashier_id'],
            'total_amount' => (float)$sale['total_amount'],
            'payment_method' => (string)$sale['payment_method'],
            'sale_date' => (string)$sale['sale_date'],
            'refunded_amount' => round((float)$refunded->fetchColumn(), 2),
            'refundable_amount' => $this->refundableAmount($saleId),
            'items' => $lines,
        ];
    }

    /**
     * The refunds one Cashier issued, newest first.
     *
     * Scoped by user id rather than by anything the request says, which is the
     * whole of the visibility rule for this history: a Cashier sees the refunds
     * under their own name and nobody else's. An Administrator's Store-wide
     * reporting is a separate concern and is not answered here.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentForCashier(int $cashierId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT cr.refund_id, cr.sale_id, cr.shift_id, cr.cashier_id, cr.refund_amount,
                    cr.payment_method, cr.reason, cr.note, cr.created_at,
                    COALESCE(SUM(cri.quantity), 0) AS quantity,
                    COALESCE(SUM(cri.subtotal), 0) AS refund_amount_total
             FROM cash_refunds cr
             LEFT JOIN cash_refund_items cri ON cri.refund_id = cr.refund_id
             WHERE cr.cashier_id = ?
             GROUP BY cr.refund_id, cr.sale_id, cr.shift_id, cr.cashier_id, cr.refund_amount,
                      cr.payment_method, cr.reason, cr.note, cr.created_at
             ORDER BY cr.created_at DESC, cr.refund_id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$cashierId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Refuses anything the Cashier workspace alone may do.
     *
     * The wording names the action rather than the rule, because a Cashier who
     * is trying to refund a sale is not looking for a lesson about workspaces.
     */
    private function requireCashierWorkspace(string $actorRole): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::OPERATE_POINT_OF_SALE)) {
            throw new DomainException(
                'Only the Cashier workspace can issue a refund. Switch to your Cashier workspace to carry on.'
            );
        }
    }

    /**
     * The open, unlocked Cashier Shift the refund is issued under.
     *
     * Read and row-locked inside the refund's own transaction, so the drawer
     * that authorizes it is the one still open and unlocked at commit. The lock
     * state is taken from that same locked row rather than a separate guard
     * query: a Register locked between a check and the refund would otherwise
     * let one payout through.
     */
    private function requireOpenShiftId(int $cashierId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT shift_id, locked_at
             FROM cashier_shifts
             WHERE cashier_id = ? AND status = 'open'
             ORDER BY opened_at DESC LIMIT 1" . $this->rowLock()
        );
        $stmt->execute([$cashierId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            throw new DomainException('Open a Cashier Shift before issuing a refund.');
        }
        if (!empty($shift['locked_at'])) {
            throw new DomainException(CashierShiftService::LOCKED_MESSAGE);
        }

        return (int)$shift['shift_id'];
    }

    /**
     * The sale being refunded, locked for the rest of the transaction.
     *
     * Two rules are settled by this one row. It is the Cashier's own sale, so a
     * Cashier cannot refund somebody else's transaction; and the lock is what
     * makes the remaining-balance caps safe under concurrency, because every
     * remaining quantity and the remaining amount are read while it is held.
     */
    private function lockOwnSale(int $cashierId, int $saleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sale_id, cashier_id, total_amount, payment_method, sale_date
             FROM sales
             WHERE sale_id = ? AND cashier_id = ?' . $this->rowLock()
        );
        $stmt->execute([$saleId, $cashierId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new DomainException('That sale was not found, or it was not one of your sales.');
        }

        return $sale;
    }

    /** Confirms the sale exists and is this Cashier's, before any write begins. */
    private function requireOwnSale(int $cashierId, int $saleId): void
    {
        $stmt = $this->pdo->prepare('SELECT sale_id FROM sales WHERE sale_id = ? AND cashier_id = ?');
        $stmt->execute([$saleId, $cashierId]);
        if ($stmt->fetchColumn() === false) {
            throw new DomainException('That sale was not found, or it was not one of your sales.');
        }
    }

    private function saleDate(int $saleId): string
    {
        $stmt = $this->pdo->prepare('SELECT sale_date FROM sales WHERE sale_id = ?');
        $stmt->execute([$saleId]);
        $saleDate = $stmt->fetchColumn();

        return is_string($saleDate) ? $saleDate : date('Y-m-d H:i:s');
    }

    /**
     * The fixed reason set, with the one note requirement attached to it.
     */
    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || !array_key_exists($reason, self::REFUND_REASONS)) {
            throw new DomainException('Choose a reason for this refund.');
        }

        return $reason;
    }

    /**
     * The requested lines, shaped and checked before anything is written.
     *
     * A quantity of zero is refused rather than skipped: it means the operator
     * chose a line and mis-entered the count, and quietly dropping it would
     * hand back less money than the screen said. An absent or unknown
     * disposition is refused for the same reason as an unknown one — a
     * classification the Cashier did not make is not made up here.
     *
     * @return array<int, array{quantity: int, disposition: string}>
     */
    private function requireItems(array $items): array
    {
        if ($items === []) {
            throw new DomainException('Select at least one item to refund.');
        }

        $requested = [];
        foreach ($items as $saleItemId => $line) {
            $saleItemId = (int)$saleItemId;
            $line = is_array($line) ? $line : [];
            $quantity = (int)($line['quantity'] ?? 0);
            $disposition = trim((string)($line['disposition'] ?? ''));

            if ($saleItemId <= 0 || $quantity <= 0) {
                throw new DomainException('Enter how many of each item are being returned.');
            }
            if (!array_key_exists($disposition, self::DISPOSITIONS)) {
                throw new DomainException(
                    'Mark every returned item as ' . self::DISPOSITIONS[self::RESTOCKABLE] . ' or '
                    . self::DISPOSITIONS[self::DAMAGED] . '.'
                );
            }

            $requested[$saleItemId] = [
                'sale_item_id' => $saleItemId,
                'quantity' => $quantity,
                'disposition' => $disposition,
            ];
        }

        return $requested;
    }

    /**
     * Matches the requested lines to the sale and prices them.
     *
     * The sold quantity and unit price are read from the sale, never from the
     * request, so a client cannot name a line of somebody else's sale or
     * negotiate a price. The already-refunded quantity per line is summed under
     * the sale lock, which is what stops two refunds spending the same last
     * unit.
     *
     * @param array<int, array{quantity: int, disposition: string}> $requested
     * @return array<int, array<string, mixed>>
     */
    private function settleLines(array $sale, array $requested): array
    {
        $saleItemIds = array_keys($requested);
        $placeholders = implode(',', array_fill(0, count($saleItemIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT si.sale_item_id, si.product_id, si.quantity AS sold_quantity, si.unit_price,
                    COALESCE(refunded.refunded_quantity, 0) AS refunded_quantity
             FROM sale_items si
             LEFT JOIN (
                 SELECT cri.sale_item_id, SUM(cri.quantity) AS refunded_quantity
                 FROM cash_refund_items cri
                 JOIN cash_refunds cr ON cr.refund_id = cri.refund_id
                 WHERE cri.sale_item_id IN ({$placeholders})
                 GROUP BY cri.sale_item_id
             ) refunded ON refunded.sale_item_id = si.sale_item_id
             WHERE si.sale_id = ? AND si.sale_item_id IN ({$placeholders})"
            . $this->rowLock()
        );
        $stmt->execute(array_merge($saleItemIds, [(int)$sale['sale_id']], $saleItemIds));
        $soldLines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $soldLines[(int)$line['sale_item_id']] = $line;
        }

        $settled = [];
        foreach ($requested as $saleItemId => $line) {
            if (!isset($soldLines[$saleItemId])) {
                throw new DomainException('One of the items you are returning is not on this sale.');
            }
            $sold = $soldLines[$saleItemId];
            $remaining = (int)$sold['sold_quantity'] - (int)$sold['refunded_quantity'];
            if ($line['quantity'] > $remaining) {
                throw new DomainException(
                    $remaining > 0
                        ? sprintf('Only %d of that item are still refundable.', $remaining)
                        : 'That item has already been fully refunded.'
                );
            }

            $unitPrice = (float)$sold['unit_price'];
            $settled[] = [
                'sale_item_id' => $saleItemId,
                'product_id' => (int)$sold['product_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $line['quantity'], 2),
                'disposition' => $line['disposition'],
                'already_refunded' => (int)$sold['refunded_quantity'],
            ];
        }

        return $settled;
    }

    /**
     * The money, capped by what the sale has left to give back.
     *
     * The cap is read under the sale lock held by lockOwnSale(), so a second
     * refund that validated a moment ago sees this one. Rounded to the cent
     * before the comparison, because the balance is money and a fraction of a
     * cent is not a smaller amount of it.
     */
    private function settleAmount(array $sale, array $settled): float
    {
        $amount = 0.0;
        foreach ($settled as $line) {
            $amount += (float)$line['subtotal'];
        }
        $amount = round($amount, 2);

        $refunded = $this->pdo->prepare('SELECT COALESCE(SUM(refund_amount), 0) FROM cash_refunds WHERE sale_id = ?');
        $refunded->execute([(int)$sale['sale_id']]);
        $remaining = round((float)$sale['total_amount'] - (float)$refunded->fetchColumn(), 2);

        if ($remaining <= 0.0) {
            throw new DomainException('This sale has already been fully refunded.');
        }
        if ($amount > $remaining + 0.001) {
            throw new DomainException(
                sprintf('This refund of %s is more than the %s still refundable on this sale.', number_format($amount, 2), number_format($remaining, 2))
            );
        }

        return $amount;
    }

    private function insertRefundLine(int $refundId, array $line): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO cash_refund_items
                    (refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $refundId,
                $line['sale_item_id'],
                $line['product_id'],
                $line['quantity'],
                $line['unit_price'],
                $line['subtotal'],
                $line['disposition'],
            ]);
        } catch (PDOException $exception) {
            // Two concurrent requests that both named the same line have lost the
            // race the sale lock was meant to settle. Say so in the operator's
            // words rather than surfacing a constraint name (ADR-0002), and let
            // the original error through if it was something else.
            if (str_contains($exception->getMessage(), 'uq_cash_refund_items_refund_line')) {
                throw new DomainException('One of the items you are returning was just refunded again. Check the remaining refundable amounts.');
            }
            throw $exception;
        }
    }

    /**
     * Puts a Restockable returned unit back into the Store.
     *
     * The unit goes back to the batch it was sold from, not just to the
     * inventory total, because the batch is what the point of sale depletes
     * first-expired-first-out: a unit that is on the shelf but not in any batch
     * is a unit the next sale of the same product cannot draw on.
     *
     * The inventory write is guarded on the row it expects to change, so a
     * product whose inventory record has gone missing fails the whole refund
     * rather than returning money for a unit nothing can ever sell again.
     */
    private function restockReturnedUnit(array $line, int $cashierId): void
    {
        $this->restoreSaleItemBatches(
            (int)$line['sale_item_id'],
            (int)$line['quantity'],
            (int)$line['already_refunded']
        );

        $restock = $this->pdo->prepare(
            'UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?'
        );
        $restock->execute([(int)$line['quantity'], (int)$line['product_id']]);
        if ($restock->rowCount() === 0) {
            throw new DomainException(
                "Product #{$line['product_id']} has no inventory record, so the return cannot be completed."
            );
        }

        $this->pdo->prepare(
            'UPDATE products SET quantity_sold = GREATEST(quantity_sold - ?, 0) WHERE product_id = ?'
        )->execute([(int)$line['quantity'], (int)$line['product_id']]);

        $this->pdo->prepare(
            "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by) VALUES (?, ?, 'return', ?)"
        )->execute([(int)$line['product_id'], (int)$line['quantity'], $cashierId]);
    }

    /**
     * Returns units to the batches a sold line was allocated from.
     *
     * A line may have been filled from several batches, so the allocations are
     * walked in the order they were sold and $alreadyRefunded is skipped over
     * first: the units earlier refunds gave back are the first ones to be
     * skipped, so each refund puts back a different set.
     */
    private function restoreSaleItemBatches(int $saleItemId, int $quantity, int $alreadyRefunded): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT batch_id, quantity
             FROM sale_item_batches
             WHERE sale_item_id = ?
             ORDER BY sale_item_batch_id ASC' . $this->rowLock()
        );
        $stmt->execute([$saleItemId]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $remainingToSkip = $alreadyRefunded;
        $remainingToRestore = $quantity;

        foreach ($allocations as $allocation) {
            if ($remainingToRestore <= 0) {
                break;
            }

            $allocated = (int)$allocation['quantity'];
            if ($remainingToSkip >= $allocated) {
                $remainingToSkip -= $allocated;
                continue;
            }

            $available = $allocated - $remainingToSkip;
            $remainingToSkip = 0;
            $restore = min($remainingToRestore, $available);

            $this->pdo->prepare('UPDATE product_batches SET remaining_quantity = remaining_quantity + ? WHERE batch_id = ?')
                ->execute([$restore, (int)$allocation['batch_id']]);

            $remainingToRestore -= $restore;
        }
    }

    /**
     * Writes the Protected Audit Record for one Cash Refund.
     *
     * Every piece an auditor needs is in the payload: who acted, which sale and
     * which Cashier Shift it was against, the reason stored with its label so a
     * later reader sees "Customer return" rather than a code they would have to
     * look up in code that may since have changed, the money, the payment method
     * it was settled on, and each returned line with its classification. The
     * refund is a sensitive non-sale action precisely because it moves money out
     * of a drawer, so it is recorded even though no supervisor approved it.
     */
    private function audit(
        int $cashierId,
        int $refundId,
        int $shiftId,
        int $saleId,
        string $paymentMethod,
        float $amount,
        string $reason,
        string $note,
        array $lines
    ): void {
        $payload = [
            'refund_id' => $refundId,
            'sale_id' => $saleId,
            'shift_id' => $shiftId,
            'cashier_id' => $cashierId,
            'payment_method' => $paymentMethod,
            'refund_amount' => round($amount, 2),
            'reason' => $reason,
            'reason_label' => self::REFUND_REASONS[$reason],
            'note' => $note !== '' ? $note : null,
            'lines' => array_map(
                static fn(array $line): array => [
                    'sale_item_id' => (int)$line['sale_item_id'],
                    'product_id' => (int)$line['product_id'],
                    'quantity' => (int)$line['quantity'],
                    'unit_price' => round((float)$line['unit_price'], 2),
                    'subtotal' => round((float)$line['subtotal'], 2),
                    'disposition' => (string)$line['disposition'],
                    'disposition_label' => self::DISPOSITIONS[$line['disposition']],
                ],
                $lines
            ),
        ];

        $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
        )->execute([
            $cashierId,
            self::AUDIT_ACTION,
            AuditRecordCategory::STORE_OPERATION,
            self::AUDIT_MODULE,
            $refundId,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'system',
        ]);
    }

    /**
     * The row lock that holds a sale, a Cashier Shift, or a batch allocation for
     * the rest of the transaction.
     *
     * Dialect-aware for the same reason SalesWorkflowService::rowLock() is: MySQL
     * takes a real row lock, which is what stops two requests spending the same
     * last refundable unit, and a driver without row locking serialises writers
     * with the transaction itself rather than failing the query.
     */
    private function rowLock(): string
    {
        return $this->isMysql() ? ' FOR UPDATE' : '';
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
