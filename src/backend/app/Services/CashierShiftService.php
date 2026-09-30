<?php
// app/Services/CashierShiftService.php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../Audit/AuditRecordCategory.php';
require_once __DIR__ . '/../Authorization/RoleCapabilityPolicy.php';

/**
 * Cashier Shifts (ticket #88).
 *
 * A Cashier Shift is an exclusively owned drawer session. Opening one binds the
 * authenticated Cashier — in the active Cashier workspace, never an
 * administrative one — to one Register and a confirmed non-negative opening
 * float. Two rules hold at all times: a Cashier owns at most one open shift and
 * a Register belongs to at most one open shift.
 *
 * Application validation gives the operator a readable reason, and the database
 * carries the same two rules as unique constraints, so two requests that pass
 * validation at the same moment cannot both win. Closed history is never
 * constrained.
 *
 * A shift can also be locked (#90). Locking is a break, not an ending: the
 * shift stays open, the Register stays claimed, and the drawer is not
 * reconciled. The lock is a column on the shift rather than a session flag,
 * because the session can end without the Cashier choosing to and a Register
 * must not reopen itself because a cookie expired. Resuming takes the owning
 * Cashier's own account password — the credential they already have — so no
 * separate PIN is introduced and a different Cashier can neither unlock nor
 * claim the Register.
 */
class CashierShiftService
{
    public const AUDIT_MODULE = 'Cashier Shifts';
    public const AUDIT_ACTION = 'Cashier Shift opened';
    public const AUDIT_ACTION_LOCKED = 'Cashier Shift locked';
    public const AUDIT_ACTION_UNLOCKED = 'Cashier Shift unlocked';
    public const AUDIT_ACTION_UNLOCK_REFUSED = 'Register unlock refused';

    /**
     * The one message a locked Register produces (#90).
     *
     * It is a constant so the point of sale, checkout, and held sales all refuse
     * with the same words: a Cashier should never be told the Register is merely
     * unavailable when the real answer is that they locked it and can unlock it
     * again with their own password.
     */
    public const LOCKED_MESSAGE = 'This Register is locked. Unlock it with your account password to resume your Cashier Shift.';

    private RoleCapabilityPolicy $policy;

    public function __construct(private PDO $pdo, ?RoleCapabilityPolicy $policy = null)
    {
        $this->policy = $policy ?? new RoleCapabilityPolicy();
    }

    public function getOpenShift(int $cashierId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cs.*, u.full_name, r.name AS register_name
             FROM cashier_shifts cs
             JOIN users u ON u.user_id = cs.cashier_id
             LEFT JOIN registers r ON r.register_id = cs.register_id
             WHERE cs.cashier_id = ? AND cs.status = 'open'
             ORDER BY cs.opened_at DESC LIMIT 1"
        );
        $stmt->execute([$cashierId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * The Registers the opening flow may offer: enabled, and not already
     * anchoring somebody else's open shift.
     */
    public function availableRegisters(): array
    {
        $rows = $this->pdo->query(
            "SELECT r.register_id, r.name
             FROM registers r
             WHERE r.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM cashier_shifts cs
                   WHERE cs.register_id = r.register_id AND cs.status = 'open'
               )
             ORDER BY r.name, r.register_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => [
            'register_id' => (int)$row['register_id'],
            'name' => (string)$row['name'],
        ], $rows);
    }

    /** The display name a Cashier picks from, or null for an unknown Register. */
    public function registerName(int $registerId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM registers WHERE register_id = ?');
        $stmt->execute([$registerId]);
        $name = $stmt->fetchColumn();

        return $name === false ? null : (string)$name;
    }

    private function registerHasOpenShift(int $registerId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM cashier_shifts WHERE register_id = ? AND status = 'open' LIMIT 1"
        );
        $stmt->execute([$registerId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Opens an exclusively owned Cashier Shift on a Register. Only the Cashier
     * workspace may open a shift, and only for the Cashier who is signed in:
     * a shared shift is exactly what the glossary rules out.
     */
    public function openShift(int $actorId, string $actorRole, int $registerId, float $openingFloat): int
    {
        $this->requireCashierWorkspace($actorRole);
        if ($openingFloat < 0) {
            throw new InvalidArgumentException('The opening float cannot be negative.');
        }
        if ($this->getOpenShift($actorId) !== null) {
            throw new DomainException('You already have an open Cashier Shift. Close it before opening another.');
        }
        $register = $this->requireAvailableRegister($registerId);

        return $this->transaction(function () use ($actorId, $actorRole, $register, $openingFloat): int {
            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, ?, ?)'
                );
                $stmt->execute([$actorId, $register['register_id'], $openingFloat]);
            } catch (PDOException $exception) {
                // Both requests passed validation and raced here. The database
                // picked the winner, so the loser is told which side to change
                // instead of surfacing a raw database error to the operator.
                throw $this->lostRace($exception) ?? $exception;
            }
            $shiftId = (int)$this->pdo->lastInsertId();
            $this->auditShiftOpened($actorId, $actorRole, $shiftId, $register, $openingFloat);

            return $shiftId;
        });
    }

    /**
     * Refuses anything the Cashier workspace alone may do.
     *
     * $refusal carries the wording for the specific action, because telling a
     * Cashier who is trying to lock a Register that only the Cashier workspace
     * can "open a Cashier Shift" sends them to the wrong page.
     */
    private function requireCashierWorkspace(string $actorRole, ?string $refusal = null): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::OPERATE_POINT_OF_SALE)) {
            throw new DomainException($refusal ?? 'Only the Cashier workspace can open a Cashier Shift. Switch to your Cashier workspace to open your register.');
        }
    }

    /**
     * Whether the Cashier's open Register is currently locked (#90).
     *
     * A Cashier with no open shift is not locked. The missing-shift gate is the
     * one that has to speak for that case, so this must not report a lock the
     * Cashier can do nothing about.
     */
    public function isRegisterLocked(int $cashierId): bool
    {
        $shift = $this->getOpenShift($cashierId);

        return $shift !== null && !empty($shift['locked_at']);
    }

    /**
     * Locks the point of sale for a break without closing the Cashier Shift.
     *
     * The drawer stays the Cashier's, the Register stays claimed, and nothing is
     * reconciled: a break is not an end of shift. The lock is stored on the shift
     * so it survives the session that set it.
     *
     * @return int The secured Cashier Shift.
     */
    public function lockRegister(int $actorId, string $actorRole): int
    {
        $this->requireCashierWorkspace(
            $actorRole,
            'Only the Cashier workspace can lock a Register. Switch to your Cashier workspace to secure your register.'
        );
        $shift = $this->requireOpenShift($actorId, 'lock this Register');
        if (!empty($shift['locked_at'])) {
            throw new DomainException('This Register is already locked.');
        }

        $this->setRegisterLock((int)$shift['shift_id'], true);
        // Re-read so the Protected Audit Record carries the time the lock was
        // actually taken, not the moment before it.
        $shift = $this->getOpenShift($actorId) ?? $shift;
        $this->auditShiftEvent($actorId, self::AUDIT_ACTION_LOCKED, (int)$shift['shift_id'], $shift, [
            'locked_at' => $shift['locked_at'],
        ]);

        return (int)$shift['shift_id'];
    }

    /**
     * Resumes the locked Register with the owning Cashier's normal password.
     *
     * The account password is the credential, exactly as at sign-in, so no
     * separate PIN or second credential is introduced: there is no weaker secret
     * to talk somebody into at the till. Only the Cashier who owns the shift is
     * ever asked, so another Cashier can neither unlock nor claim it.
     *
     * @return int The resumed Cashier Shift.
     */
    public function unlockRegister(int $actorId, string $actorRole, string $password): int
    {
        $this->requireCashierWorkspace(
            $actorRole,
            'Only the Cashier workspace can unlock a Register. Switch to your Cashier workspace to resume your shift.'
        );
        $shift = $this->requireOpenShift($actorId, 'unlock this Register');
        if (empty($shift['locked_at'])) {
            throw new DomainException('This Register is not locked.');
        }

        if (!$this->verifyCashierPassword($actorId, $password)) {
            $this->auditShiftEvent(
                $actorId,
                self::AUDIT_ACTION_UNLOCK_REFUSED,
                (int)$shift['shift_id'],
                $shift,
                [],
                AuditRecordCategory::SECURITY
            );
            // Deliberately the same refusal for an unknown account, a wrong
            // password, and a blank one, so unlocking reveals nothing about
            // which credential was close.
            throw new DomainException('That password is not correct.');
        }

        $this->setRegisterLock((int)$shift['shift_id'], false);
        // The record keeps when the Register was locked, which is the only moment
        // an unlock happens, so a reader can see how long the break was.
        $this->auditShiftEvent($actorId, self::AUDIT_ACTION_UNLOCKED, (int)$shift['shift_id'], $shift, [
            'locked_at' => $shift['locked_at'],
        ]);

        return (int)$shift['shift_id'];
    }

    /**
     * Refuses point-of-sale work on a locked Register.
     *
     * This is the page-level guard. Checkout settles the same rule from the
     * row-locked read that decides its attribution, because a shift locked
     * between a check and the sale would otherwise slip a transaction through.
     */
    public function requireUnlockedRegister(int $cashierId): void
    {
        if ($this->isRegisterLocked($cashierId)) {
            throw new DomainException(self::LOCKED_MESSAGE);
        }
    }

    private function requireOpenShift(int $cashierId, string $action): array
    {
        $shift = $this->getOpenShift($cashierId);
        if ($shift === null) {
            throw new DomainException('Open a Cashier Shift before you can ' . $action . '.');
        }

        return $shift;
    }

    /**
     * Stamps or clears the lock on an open Cashier Shift.
     *
     * NOW() is the database's clock rather than a bound value so the lock time
     * is the same clock the rest of the shift is written on, and NULL is the
     * only two states the column has.
     */
    private function setRegisterLock(int $shiftId, bool $locked): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE cashier_shifts
             SET locked_at = " . ($locked ? 'NOW()' : 'NULL') . "
             WHERE shift_id = ? AND status = 'open'"
        );
        $stmt->execute([$shiftId]);
        if ($stmt->rowCount() === 0) {
            throw new DomainException('That Cashier Shift is no longer open.');
        }
    }

    /**
     * Checks the Cashier's own account password, the same credential and the
     * same active-account rule that sign-in accepts.
     *
     * The Store has no account lockout policy to honour here — `locked_until`
     * and the login-attempt counters are not wired into sign-in either — so the
     * lock's protection is the same thing sign-in's is: the Cashier's own
     * password, and a Protected Audit Record of every attempt against it.
     */
    private function verifyCashierPassword(int $cashierId, string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT password_hash FROM users WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$cashierId]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) && $hash !== '' && password_verify($password, $hash);
    }

    private function requireAvailableRegister(int $registerId): array
    {
        if ($registerId <= 0) {
            throw new InvalidArgumentException('Choose a Register to open this Cashier Shift on.');
        }
        $stmt = $this->pdo->prepare('SELECT register_id, name, status FROM registers WHERE register_id = ?');
        $stmt->execute([$registerId]);
        $register = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$register) {
            throw new InvalidArgumentException('That Register no longer exists. Choose another Register.');
        }
        if ((string)$register['status'] !== 'active') {
            throw new DomainException('That Register is disabled and cannot start a new Cashier Shift.');
        }
        if ($this->registerHasOpenShift($registerId)) {
            throw new DomainException('That Register already has an open Cashier Shift. Choose another Register.');
        }

        return [
            'register_id' => (int)$register['register_id'],
            'name' => (string)$register['name'],
        ];
    }

    /**
     * Translates a lost exclusivity race into the same readable refusal the
     * pre-checks produce, and returns null for any other database failure so
     * the original error is never hidden.
     */
    private function lostRace(PDOException $exception): ?DomainException
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'uq_cashier_shifts_open_register')
            || str_contains($message, 'cashier_shifts.register_id')) {
            return new DomainException('That Register was just taken by another Cashier Shift. Choose another Register.');
        }
        if (str_contains($message, 'uq_cashier_shifts_open_cashier')
            || str_contains($message, 'cashier_shifts.cashier_id')) {
            return new DomainException('You already have an open Cashier Shift. Close it before opening another.');
        }

        return null;
    }

    private function auditShiftOpened(
        int $actorId,
        string $actorRole,
        int $shiftId,
        array $register,
        float $openingFloat
    ): void {
        $this->auditShiftEvent(
            $actorId,
            self::AUDIT_ACTION,
            $shiftId,
            [
                'register_id' => (int)$register['register_id'],
                'register_name' => (string)$register['name'],
            ],
            [
                'opening_float' => round($openingFloat, 2),
                'actor_role' => $actorRole,
            ]
        );
    }

    /**
     * Writes a Protected Audit Record for one Cashier Shift event.
     *
     * $registerIdentity is whatever already names the Register for this event:
     * a shift row, which getOpenShift() joins with r.name AS register_name, or a
     * bare [register_id, register_name] pair for an opening that has no row yet.
     * Every event records the Register as the operator knows it, so a record can
     * be read on its own months later without re-deriving which till it
     * concerns. $details carries whatever else that event knows and an opening
     * does not, so a record never claims a lock time it did not have.
     */
    private function auditShiftEvent(
        int $actorId,
        string $action,
        int $shiftId,
        array $registerIdentity,
        array $details = [],
        string $category = AuditRecordCategory::STORE_OPERATION
    ): void {
        $payload = array_merge($details, [
            'shift_id' => $shiftId,
            'cashier_id' => $actorId,
            'register_id' => isset($registerIdentity['register_id']) ? (int)$registerIdentity['register_id'] : null,
            'register_name' => $registerIdentity['register_name'] ?? null,
        ]);

        $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
        )->execute([
            $actorId,
            $action,
            $category,
            self::AUDIT_MODULE,
            $shiftId,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'system',
        ]);
    }

    private function transaction(callable $operation)
    {
        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($started) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Records a pay-in or pay-out against an open Cashier Shift's drawer.
     *
     * The lock is enforced here rather than on the page (#90): a drawer movement
     * is a financial record written under the Cashier's name, so posting one on
     * a Register its owner locked would be exactly the act the lock forbids. An
     * Administrator acting deliberately on somebody else's drawer is not the
     * threat the lock addresses, so their movement is not refused.
     */
    public function addDrawerMovement(int $cashierId, string $type, float $amount, string $reason, ?int $recordedBy = null): int
    {
        if (!in_array($type, ['pay_in', 'pay_out'], true)) {
            throw new RuntimeException('Invalid drawer movement type.');
        }
        if ($amount <= 0 || trim($reason) === '') {
            throw new RuntimeException('Amount and reason are required.');
        }
        $actorId = $recordedBy ?? $cashierId;
        if ($actorId === $cashierId) {
            $this->requireUnlockedRegister($cashierId);
        }
        $shift = $this->getOpenShift($cashierId);
        if (!$shift) {
            throw new RuntimeException('No open shift was found.');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO cash_drawer_movements (shift_id, movement_type, amount, reason, recorded_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([(int)$shift['shift_id'], $type, $amount, trim($reason), $actorId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function calculateShift(int $shiftId): array
    {
        // The Register travels with the summary on purpose: closeShift() returns
        // this array and the page writes it into the closing Protected Audit
        // Record, so the record names the Register the drawer was reconciled on.
        $stmt = $this->pdo->prepare(
            "SELECT cs.*, u.full_name, r.name AS register_name
             FROM cashier_shifts cs
             JOIN users u ON u.user_id = cs.cashier_id
             LEFT JOIN registers r ON r.register_id = cs.register_id
             WHERE cs.shift_id = ?"
        );
        $stmt->execute([$shiftId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$shift) {
            throw new RuntimeException('Shift not found.');
        }

        $salesStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS sale_count,
                    COALESCE(SUM(total_amount),0) AS total_sales,
                    COALESCE(SUM(CASE WHEN payment_method='cash' THEN total_amount ELSE 0 END),0) AS cash_sales,
                    COALESCE(SUM(CASE WHEN payment_method='card' THEN total_amount ELSE 0 END),0) AS card_sales,
                    COALESCE(SUM(CASE WHEN payment_method='ewallet' THEN total_amount ELSE 0 END),0) AS ewallet_sales,
                    COALESCE(SUM(discount_amount),0) AS discounts
             FROM sales WHERE shift_id = ?"
        );
        $salesStmt->execute([$shiftId]);
        $sales = $salesStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $moveStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN movement_type='pay_in' THEN amount ELSE 0 END),0) AS pay_in,
                    COALESCE(SUM(CASE WHEN movement_type='pay_out' THEN amount ELSE 0 END),0) AS pay_out
             FROM cash_drawer_movements WHERE shift_id = ?"
        );
        $moveStmt->execute([$shiftId]);
        $movements = $moveStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $refundStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(sr.refund_amount),0)
             FROM sale_reversals sr JOIN sales s ON s.sale_id = sr.sale_id
             WHERE s.shift_id = ? AND sr.status='approved' AND sr.settlement_method='cash'"
        );
        $refundStmt->execute([$shiftId]);
        $approvedReversalCash = (float)$refundStmt->fetchColumn();

        // Ticket #92. An append-only Cash Refund takes cash out of the drawer of
        // the Cashier Shift that *issued* it, which is not necessarily the shift
        // the sale ran under: a refund is paid out of the drawer in front of the
        // Cashier holding the money now, and that is the drawer whose expected
        // cash has to fall. Joining through the refunded sale's own shift would
        // charge the refund to a drawer that was already handed back.
        //
        // Only cash is subtracted. A refund settled on the original card or
        // e-wallet method never entered a drawer, so it cannot come out of one.
        $cashRefundStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(cr.refund_amount),0)
             FROM cash_refunds cr
             WHERE cr.shift_id = ? AND cr.payment_method = 'cash'"
        );
        $cashRefundStmt->execute([$shiftId]);
        $cashRefunds = (float)$cashRefundStmt->fetchColumn();

        $expected = (float)$shift['opening_cash'] + (float)($sales['cash_sales'] ?? 0)
            + (float)($movements['pay_in'] ?? 0) - (float)($movements['pay_out'] ?? 0)
            - $cashRefunds - $approvedReversalCash;

        return array_merge($shift, $sales, $movements, [
            'cash_refunds' => round($cashRefunds, 2),
            'calculated_expected_cash' => round($expected, 2),
        ]);
    }

    /**
     * The held sales on one shift that are still owed a decision (#91).
     *
     * The shift is the drawer, so a held sale that belongs to one has to be settled
     * before the drawer is handed back — otherwise it is left on a till that no
     * longer belongs to anybody. 'resumed' counts, because a Cashier who resumed
     * a cart and walked away has not settled anything.
     *
     * One query and one row shape, because the closure invariant and the closing
     * form that explains it have to agree about what is in the way. Exposed so
     * that form can name the held sales rather than the Cashier being told only
     * that there are some.
     */
    public function unresolvedHeldSales(int $shiftId): array
    {
        $placeholders = implode(',', array_fill(0, count(HeldSaleService::UNRESOLVED_STATUSES), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT held_sale_id, reference_no, customer_label, status, item_count, total_amount, created_at, expires_at
             FROM held_sales
             WHERE shift_id = ? AND status IN ({$placeholders})
             ORDER BY created_at, held_sale_id"
        );
        $stmt->execute(array_merge([$shiftId], HeldSaleService::UNRESOLVED_STATUSES));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
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
        }, $rows);
    }

    /**
     * Closes a Cashier Shift and reconciles the counted cash against it.
     *
     * $reviewedBy is non-null when an Administrator closes somebody else's
     * abandoned shift, and null when the owner closes their own. That
     * distinction is also the lock boundary (#90): a Register locked on a break
     * must not be reconciled by whoever is standing at the till, but an
     * Administrator deliberately intervening on an abandoned shift is exactly
     * how a locked Register is ever released, so it is never refused.
     */
    public function closeShift(int $cashierId, float $actualCash, string $notes, ?int $reviewedBy = null): array
    {
        if ($reviewedBy === null) {
            $this->requireUnlockedRegister($cashierId);
        }
        $shift = $this->getOpenShift($cashierId);
        if (!$shift) {
            throw new RuntimeException('No open shift was found.');
        }
        if ($actualCash < 0) {
            throw new RuntimeException('Actual cash cannot be negative.');
        }
        $this->requireNoUnresolvedHeldSales((int)$shift['shift_id']);
        $summary = $this->calculateShift((int)$shift['shift_id']);
        $expected = (float)$summary['calculated_expected_cash'];
        $variance = round($actualCash - $expected, 2);
        $stmt = $this->pdo->prepare(
            "UPDATE cashier_shifts
             SET status='closed', closed_at=NOW(), expected_cash=?, actual_cash=?, cash_variance=?, closing_notes=?,
                 reviewed_by=?, reviewed_at=CASE WHEN ? IS NULL THEN NULL ELSE NOW() END
             WHERE shift_id=? AND status='open'"
        );
        $stmt->execute([$expected, $actualCash, $variance, trim($notes) ?: null, $reviewedBy, $reviewedBy, (int)$shift['shift_id']]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('The shift was already closed.');
        }
        return $this->calculateShift((int)$shift['shift_id']);
    }

    /**
     * Refuses a closure while the shift still has a held sale on it (#91).
     *
     * The refusal is settled here rather than on the closing form, because the
     * form can be posted directly and because an Administrator closing somebody
     * else's abandoned shift is exactly the moment an overlooked cart would
     * otherwise be waved through. A held sale is not an exception to the closure:
     * it is the last piece of that shift's work.
     */
    private function requireNoUnresolvedHeldSales(int $shiftId): void
    {
        // The same read the closing form renders, so what is refused and what is
        // shown can never disagree about what is in the way.
        $unresolved = $this->unresolvedHeldSales($shiftId);
        if ($unresolved === []) {
            return;
        }

        $named = array_map(
            static fn(array $row): string => sprintf(
                '%s (%d item(s), ₱%s)',
                $row['reference_no'],
                (int)$row['item_count'],
                number_format((float)$row['total_amount'], 2)
            ),
            $unresolved
        );
        $count = count($named);

        throw new DomainException(
            sprintf(
                'This shift still has %d held sale%s: %s. Complete or discard %s before closing the shift.',
                $count,
                $count === 1 ? '' : 's',
                implode(', ', $named),
                $count === 1 ? 'it' : 'them'
            )
        );
    }

    public function recentShifts(?int $cashierId = null, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT cs.*, u.full_name, r.name AS register_name
                FROM cashier_shifts cs
                JOIN users u ON u.user_id=cs.cashier_id
                LEFT JOIN registers r ON r.register_id = cs.register_id";
        $params = [];
        if ($cashierId !== null) {
            $sql .= ' WHERE cs.cashier_id = ?';
            $params[] = $cashierId;
        }
        $sql .= " ORDER BY cs.opened_at DESC LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function drawerMovements(int $shiftId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cdm.*, u.full_name FROM cash_drawer_movements cdm
             JOIN users u ON u.user_id=cdm.recorded_by WHERE cdm.shift_id=? ORDER BY cdm.created_at DESC"
        );
        $stmt->execute([$shiftId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
