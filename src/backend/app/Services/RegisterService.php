<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Register administration (ticket #87).
 *
 * A Register is the named physical till that anchors drawer accountability. Its
 * identity (`register_id`) is stable for the life of the record: renaming changes
 * only the display name, so references held by earlier operational records keep
 * resolving. A disabled Register is withdrawn from new shifts but stays visible
 * in history, and a Register that any operational record still points at is
 * never deleted.
 */
final class RegisterService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const AUDIT_MODULE = 'Registers';

    /**
     * Tables that can hold a Register reference. Each is probed for the column
     * before it is queried, so the reference guard is correct while the shift
     * linkage is still being introduced (ticket #88) and after it lands.
     */
    private const REFERENCING_TABLES = ['cashier_shifts', 'sales', 'held_sales'];

    public function __construct(
        private PDO $pdo,
        private RoleCapabilityPolicy $policy
    ) {
    }

    public function get(int $registerId): array
    {
        $hasTerminal = $this->hasColumn('registers', 'terminal_identifier');
        $hasLimit = $this->hasColumn('registers', 'transaction_limit');

        $cols = 'register_id, name, status, paper_width_mm, disabled_at, created_by, created_at, updated_at';
        if ($hasTerminal) {
            $cols .= ', terminal_identifier';
        }
        if ($hasLimit) {
            $cols .= ', transaction_limit';
        }

        $statement = $this->pdo->prepare("SELECT {$cols} FROM registers WHERE register_id = ?");
        $statement->execute([$registerId]);
        $register = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$register) {
            throw new InvalidArgumentException('Register not found.');
        }

        return [
            'register_id' => (int)$register['register_id'],
            'name' => (string)$register['name'],
            'status' => (string)$register['status'],
            'paper_width_mm' => (int)$register['paper_width_mm'],
            'terminal_identifier' => isset($register['terminal_identifier']) && $register['terminal_identifier'] !== null ? (string)$register['terminal_identifier'] : null,
            'transaction_limit' => isset($register['transaction_limit']) && $register['transaction_limit'] !== null ? (float)$register['transaction_limit'] : null,
            'disabled_at' => $register['disabled_at'] !== null ? (string)$register['disabled_at'] : null,
            'created_by' => $register['created_by'] !== null ? (int)$register['created_by'] : null,
            'created_at' => (string)$register['created_at'],
            'updated_at' => (string)$register['updated_at'],
        ];
    }

    /** Every Register, including disabled ones, so history stays visible. */
    public function all(): array
    {
        $hasTerminal = $this->hasColumn('registers', 'terminal_identifier');
        $hasLimit = $this->hasColumn('registers', 'transaction_limit');
        $cols = 'register_id, name, status, paper_width_mm, disabled_at, created_at';
        if ($hasTerminal) {
            $cols .= ', terminal_identifier';
        }
        if ($hasLimit) {
            $cols .= ', transaction_limit';
        }

        return $this->fetch("SELECT {$cols} FROM registers ORDER BY name, register_id");
    }

    /** Only the Registers that may anchor a new Cashier Shift. */
    public function available(): array
    {
        $hasTerminal = $this->hasColumn('registers', 'terminal_identifier');
        $hasLimit = $this->hasColumn('registers', 'transaction_limit');
        $cols = 'register_id, name, status, paper_width_mm, disabled_at, created_at';
        if ($hasTerminal) {
            $cols .= ', terminal_identifier';
        }
        if ($hasLimit) {
            $cols .= ', transaction_limit';
        }

        return $this->fetch(
            "SELECT {$cols} FROM registers WHERE status = 'active' ORDER BY name, register_id"
        );
    }

    public function create(
        int $actorId,
        string $actorRole,
        string $name,
        mixed $paperWidthMm = 80,
        ?string $terminalIdentifier = null,
        mixed $transactionLimit = null
    ): int {
        $this->requireAuthority($actorRole, 'create Registers');
        $name = $this->requireName($name);
        $paperWidthMm = $this->requirePaperWidth($paperWidthMm);

        $terminal = null;
        if ($terminalIdentifier !== null) {
            $terminal = trim($terminalIdentifier);
            if ($terminal === '') {
                $terminal = null;
            } elseif (mb_strlen($terminal) > 64) {
                throw new InvalidArgumentException('Terminal identifier must be 64 characters or fewer.');
            }
        }

        $limitVal = null;
        if ($transactionLimit !== null && $transactionLimit !== '') {
            if (!is_numeric($transactionLimit) || (float)$transactionLimit < 0) {
                throw new InvalidArgumentException('Transaction limit must be a non-negative number.');
            }
            $limitVal = round((float)$transactionLimit, 2);
        }

        return $this->transaction(function () use ($actorId, $actorRole, $name, $paperWidthMm, $terminal, $limitVal): int {
            $this->requireNameAvailable($name);
            if ($terminal !== null) {
                $this->requireTerminalAvailable($terminal);
            }

            $hasTerminal = $this->hasColumn('registers', 'terminal_identifier');
            $hasLimit = $this->hasColumn('registers', 'transaction_limit');

            if ($hasTerminal && $hasLimit) {
                $statement = $this->pdo->prepare(
                    'INSERT INTO registers (name, status, created_by, paper_width_mm, terminal_identifier, transaction_limit) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([$name, self::STATUS_ACTIVE, $actorId, $paperWidthMm, $terminal, $limitVal]);
            } else {
                $statement = $this->pdo->prepare('INSERT INTO registers (name, status, created_by, paper_width_mm) VALUES (?, ?, ?, ?)');
                $statement->execute([$name, self::STATUS_ACTIVE, $actorId, $paperWidthMm]);
            }

            $registerId = (int)$this->pdo->lastInsertId();
            $this->audit($actorId, $actorRole, 'Register created', $registerId, null, $this->get($registerId));
            return $registerId;
        });
    }

    public function assignTerminal(int $actorId, string $actorRole, int $registerId, ?string $terminalIdentifier): array
    {
        $this->requireAuthority($actorRole, 'assign Register terminals');

        $terminal = null;
        if ($terminalIdentifier !== null) {
            $terminal = trim($terminalIdentifier);
            if ($terminal === '') {
                $terminal = null;
            } elseif (mb_strlen($terminal) > 64) {
                throw new InvalidArgumentException('Terminal identifier must be 64 characters or fewer.');
            }
        }

        return $this->transaction(function () use ($actorId, $actorRole, $registerId, $terminal): array {
            $before = $this->get($registerId);
            if ($terminal !== null) {
                $this->requireTerminalAvailable($terminal, $registerId);
            }

            if ($this->hasColumn('registers', 'terminal_identifier')) {
                $stmt = $this->pdo->prepare('UPDATE registers SET terminal_identifier = ? WHERE register_id = ?');
                $stmt->execute([$terminal, $registerId]);
            }

            $after = $this->get($registerId);
            $this->audit(
                $actorId,
                $actorRole,
                'Register terminal assigned',
                $registerId,
                $this->snapshot($before),
                $this->snapshot($after)
            );
            return $after;
        });
    }

    public function setTransactionLimit(int $actorId, string $actorRole, int $registerId, mixed $limit): array
    {
        $this->requireAuthority($actorRole, 'set Register transaction limits');

        $limitVal = null;
        if ($limit !== null && $limit !== '') {
            if (!is_numeric($limit) || (float)$limit < 0) {
                throw new InvalidArgumentException('Transaction limit must be a non-negative number.');
            }
            $limitVal = round((float)$limit, 2);
        }

        return $this->transaction(function () use ($actorId, $actorRole, $registerId, $limitVal): array {
            $before = $this->get($registerId);

            if ($this->hasColumn('registers', 'transaction_limit')) {
                $stmt = $this->pdo->prepare('UPDATE registers SET transaction_limit = ? WHERE register_id = ?');
                $stmt->execute([$limitVal, $registerId]);
            }

            $after = $this->get($registerId);
            $this->audit(
                $actorId,
                $actorRole,
                'Register transaction limit updated',
                $registerId,
                $this->snapshot($before),
                $this->snapshot($after)
            );
            return $after;
        });
    }

    /**
     * Renames the display name only. The stable identity is never reassigned, so
     * operational records recorded before the rename keep their attribution.
     */
    public function rename(int $actorId, string $actorRole, int $registerId, string $name): array
    {
        $this->requireAuthority($actorRole, 'rename Registers');
        $name = $this->requireName($name);

        return $this->transaction(function () use ($actorId, $actorRole, $registerId, $name): array {
            $before = $this->get($registerId);
            $this->requireNameAvailable($name, $registerId);
            $this->pdo->prepare('UPDATE registers SET name = ? WHERE register_id = ?')->execute([$name, $registerId]);
            $after = $this->get($registerId);
            $this->audit(
                $actorId,
                $actorRole,
                'Register renamed',
                $registerId,
                $this->snapshot($before),
                $this->snapshot($after)
            );
            return $after;
        });
    }

    /**
     * Disabling withdraws a Register from new shifts without touching its
     * history; re-enabling returns it to availability.
     */
    public function setStatus(int $actorId, string $actorRole, int $registerId, string $status): array
    {
        $this->requireAuthority($actorRole, 'change Register availability');
        $status = $status === self::STATUS_DISABLED ? self::STATUS_DISABLED : self::STATUS_ACTIVE;

        return $this->transaction(function () use ($actorId, $actorRole, $registerId, $status): array {
            $before = $this->get($registerId);
            $this->pdo->prepare(
                $status === self::STATUS_DISABLED
                    ? 'UPDATE registers SET status = ?, disabled_at = CURRENT_TIMESTAMP WHERE register_id = ?'
                    : 'UPDATE registers SET status = ?, disabled_at = NULL WHERE register_id = ?'
            )->execute([$status, $registerId]);
            $after = $this->get($registerId);
            $action = $status === self::STATUS_DISABLED ? 'Register disabled' : 'Register enabled';
            $this->audit($actorId, $actorRole, $action, $registerId, $this->snapshot($before), $this->snapshot($after));
            return $after;
        });
    }

    /**
     * Deletes a Register only while nothing points at it. A referenced Register
     * is refused outright so operational history can never lose its anchor.
     */
    public function delete(int $actorId, string $actorRole, int $registerId): void
    {
        $this->requireAuthority($actorRole, 'delete Registers');

        $this->transaction(function () use ($actorId, $actorRole, $registerId): void {
            $before = $this->snapshot($this->get($registerId));
            if ($this->references($registerId) > 0) {
                throw new DomainException(
                    'This Register is still used by operational records. Disable it instead of deleting it.'
                );
            }
            $this->pdo->prepare('DELETE FROM registers WHERE register_id = ?')->execute([$registerId]);
            $this->audit($actorId, $actorRole, 'Register deleted', $registerId, $before, null);
        });
    }

    /** How many operational rows still point at this Register. */
    public function references(int $registerId): int
    {
        $total = 0;
        foreach (self::REFERENCING_TABLES as $table) {
            if (!$this->hasColumn($table, 'register_id')) {
                continue;
            }
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE register_id = ?");
            $statement->execute([$registerId]);
            $total += (int)$statement->fetchColumn();
        }

        return $total;
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $statement = $this->pdo->prepare("SELECT `{$column}` FROM `{$table}` LIMIT 0");
            $statement->execute();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function setPaperWidth(int $actorId, string $actorRole, int $registerId, mixed $paperWidthMm): array
    {
        $this->requireAuthority($actorRole, 'change Register paper width');
        $paperWidthMm = $this->requirePaperWidth($paperWidthMm);
        return $this->transaction(function () use ($actorId, $actorRole, $registerId, $paperWidthMm): array {
            $before = $this->get($registerId);
            $this->pdo->prepare('UPDATE registers SET paper_width_mm = ? WHERE register_id = ?')
                ->execute([$paperWidthMm, $registerId]);
            $after = $this->get($registerId);
            $this->audit($actorId, $actorRole, 'Register paper width changed', $registerId,
                $this->snapshot($before), $this->snapshot($after));
            return $after;
        });
    }

    private function requirePaperWidth(mixed $paperWidthMm): int
    {
        if (!in_array($paperWidthMm, [80, 58, '80', '58'], true)) {
            throw new InvalidArgumentException('Choose 80 mm or 58 mm paper for this Register.');
        }
        return (int)$paperWidthMm;
    }

    private function requireName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Register name is required.');
        }
        if (mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Register name must be 100 characters or fewer.');
        }

        return $name;
    }

    private function requireNameAvailable(string $name, ?int $ignoreRegisterId = null): void
    {
        $statement = $this->pdo->prepare(
            'SELECT register_id FROM registers WHERE LOWER(name) = LOWER(?)'
            . ($ignoreRegisterId !== null ? ' AND register_id <> ?' : '')
            . ' LIMIT 1'
        );
        $statement->execute($ignoreRegisterId !== null ? [$name, $ignoreRegisterId] : [$name]);
        if ($statement->fetchColumn() !== false) {
            throw new InvalidArgumentException('Another Register already uses that name.');
        }
    }

    private function requireTerminalAvailable(string $terminal, ?int $ignoreRegisterId = null): void
    {
        if (!$this->hasColumn('registers', 'terminal_identifier')) {
            return;
        }
        $statement = $this->pdo->prepare(
            'SELECT register_id FROM registers WHERE LOWER(terminal_identifier) = LOWER(?)'
            . ($ignoreRegisterId !== null ? ' AND register_id <> ?' : '')
            . ' LIMIT 1'
        );
        $statement->execute($ignoreRegisterId !== null ? [$terminal, $ignoreRegisterId] : [$terminal]);
        if ($statement->fetchColumn() !== false) {
            throw new InvalidArgumentException('Another Register already uses that terminal identifier.');
        }
    }

    private function requireAuthority(string $actorRole, string $operation): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::MANAGE_REGISTERS)) {
            throw new DomainException("Your account cannot {$operation}.");
        }
    }

    private function fetch(string $sql): array
    {
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $openShifts = [];
        if ($this->hasColumn('cashier_shifts', 'register_id') && $this->hasColumn('cashier_shifts', 'status')) {
            $hasCashier = $this->hasColumn('cashier_shifts', 'cashier_id');
            $cashierCol = $hasCashier ? ', cashier_id' : '';
            try {
                $shiftRows = $this->pdo->query(
                    "SELECT register_id, shift_id{$cashierCol} FROM cashier_shifts WHERE status = 'open' AND register_id IS NOT NULL"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($shiftRows as $sr) {
                    $openShifts[(int)$sr['register_id']] = [
                        'shift_id' => (int)$sr['shift_id'],
                        'cashier_id' => isset($sr['cashier_id']) ? (int)$sr['cashier_id'] : null,
                    ];
                }
            } catch (Throwable) {}
        }

        return array_map(static function (array $row) use ($openShifts): array {
            $regId = (int)$row['register_id'];
            $activeShift = $openShifts[$regId] ?? null;

            return [
                'register_id' => $regId,
                'name' => (string)$row['name'],
                'status' => (string)$row['status'],
                'paper_width_mm' => (int)$row['paper_width_mm'],
                'terminal_identifier' => isset($row['terminal_identifier']) && $row['terminal_identifier'] !== null ? (string)$row['terminal_identifier'] : null,
                'transaction_limit' => isset($row['transaction_limit']) && $row['transaction_limit'] !== null ? (float)$row['transaction_limit'] : null,
                'disabled_at' => $row['disabled_at'] !== null ? (string)$row['disabled_at'] : null,
                'created_at' => (string)$row['created_at'],
                'is_in_use' => $activeShift !== null,
                'open_shift_id' => $activeShift['shift_id'] ?? null,
                'open_shift_cashier_id' => $activeShift['cashier_id'] ?? null,
            ];
        }, $rows);
    }

    private function snapshot(array $register): array
    {
        return [
            'register_id' => (int)$register['register_id'],
            'name' => (string)$register['name'],
            'status' => (string)$register['status'],
            'paper_width_mm' => (int)$register['paper_width_mm'],
            'terminal_identifier' => $register['terminal_identifier'] ?? null,
            'transaction_limit' => $register['transaction_limit'] ?? null,
        ];
    }

    private function audit(int $actorId, string $actorRole, string $action, int $registerId, ?array $before, ?array $after): void
    {
        $payload = $after ?? $before ?? [];
        $payload['actor_role'] = $actorRole;

        $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $actorId,
            $action,
            AuditRecordCategory::STORE_OPERATION,
            self::AUDIT_MODULE,
            $registerId,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
}
