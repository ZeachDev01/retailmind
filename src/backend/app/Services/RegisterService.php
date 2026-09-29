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
        $statement = $this->pdo->prepare(
            'SELECT register_id, name, status, disabled_at, created_by, created_at, updated_at
             FROM registers
             WHERE register_id = ?'
        );
        $statement->execute([$registerId]);
        $register = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$register) {
            throw new InvalidArgumentException('Register not found.');
        }

        return [
            'register_id' => (int)$register['register_id'],
            'name' => (string)$register['name'],
            'status' => (string)$register['status'],
            'disabled_at' => $register['disabled_at'] !== null ? (string)$register['disabled_at'] : null,
            'created_by' => $register['created_by'] !== null ? (int)$register['created_by'] : null,
            'created_at' => (string)$register['created_at'],
            'updated_at' => (string)$register['updated_at'],
        ];
    }

    /** Every Register, including disabled ones, so history stays visible. */
    public function all(): array
    {
        return $this->fetch(
            'SELECT register_id, name, status, disabled_at, created_at FROM registers ORDER BY name, register_id'
        );
    }

    /** Only the Registers that may anchor a new Cashier Shift. */
    public function available(): array
    {
        return $this->fetch(
            "SELECT register_id, name, status, disabled_at, created_at
             FROM registers WHERE status = 'active' ORDER BY name, register_id"
        );
    }

    public function create(int $actorId, string $actorRole, string $name): int
    {
        $this->requireAuthority($actorRole, 'create Registers');
        $name = $this->requireName($name);

        return $this->transaction(function () use ($actorId, $actorRole, $name): int {
            $this->requireNameAvailable($name);
            $statement = $this->pdo->prepare('INSERT INTO registers (name, status, created_by) VALUES (?, ?, ?)');
            $statement->execute([$name, self::STATUS_ACTIVE, $actorId]);
            $registerId = (int)$this->pdo->lastInsertId();
            $this->audit($actorId, $actorRole, 'Register created', $registerId, null, $this->get($registerId));
            return $registerId;
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

    private function requireAuthority(string $actorRole, string $operation): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::MANAGE_REGISTERS)) {
            throw new DomainException("Your account cannot {$operation}.");
        }
    }

    private function fetch(string $sql): array
    {
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => [
            'register_id' => (int)$row['register_id'],
            'name' => (string)$row['name'],
            'status' => (string)$row['status'],
            'disabled_at' => $row['disabled_at'] !== null ? (string)$row['disabled_at'] : null,
            'created_at' => (string)$row['created_at'],
        ], $rows);
    }

    private function snapshot(array $register): array
    {
        return [
            'register_id' => (int)$register['register_id'],
            'name' => (string)$register['name'],
            'status' => (string)$register['status'],
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
