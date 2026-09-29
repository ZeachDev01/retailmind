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
 */
class CashierShiftService
{
    public const AUDIT_MODULE = 'Cashier Shifts';
    public const AUDIT_ACTION = 'Cashier Shift opened';

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

    private function requireCashierWorkspace(string $actorRole): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::OPERATE_POINT_OF_SALE)) {
            throw new DomainException(
                'Only the Cashier workspace can open a Cashier Shift. Switch to your Cashier workspace to open your register.'
            );
        }
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
        $payload = [
            'shift_id' => $shiftId,
            'cashier_id' => $actorId,
            'register_id' => (int)$register['register_id'],
            'register_name' => (string)$register['name'],
            'opening_float' => round($openingFloat, 2),
            'actor_role' => $actorRole,
        ];

        $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
        )->execute([
            $actorId,
            self::AUDIT_ACTION,
            AuditRecordCategory::STORE_OPERATION,
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

    public function addDrawerMovement(int $cashierId, string $type, float $amount, string $reason, ?int $recordedBy = null): int
    {
        if (!in_array($type, ['pay_in', 'pay_out'], true)) {
            throw new RuntimeException('Invalid drawer movement type.');
        }
        if ($amount <= 0 || trim($reason) === '') {
            throw new RuntimeException('Amount and reason are required.');
        }
        $shift = $this->getOpenShift($cashierId);
        if (!$shift) {
            throw new RuntimeException('No open shift was found.');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO cash_drawer_movements (shift_id, movement_type, amount, reason, recorded_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([(int)$shift['shift_id'], $type, $amount, trim($reason), $recordedBy ?? $cashierId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function calculateShift(int $shiftId): array
    {
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
        $cashRefunds = (float)$refundStmt->fetchColumn();

        $expected = (float)$shift['opening_cash'] + (float)($sales['cash_sales'] ?? 0)
            + (float)($movements['pay_in'] ?? 0) - (float)($movements['pay_out'] ?? 0) - $cashRefunds;

        return array_merge($shift, $sales, $movements, [
            'cash_refunds' => $cashRefunds,
            'calculated_expected_cash' => round($expected, 2),
        ]);
    }

    public function closeShift(int $cashierId, float $actualCash, string $notes, ?int $reviewedBy = null): array
    {
        $shift = $this->getOpenShift($cashierId);
        if (!$shift) {
            throw new RuntimeException('No open shift was found.');
        }
        if ($actualCash < 0) {
            throw new RuntimeException('Actual cash cannot be negative.');
        }
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
