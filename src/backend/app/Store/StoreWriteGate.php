<?php

namespace App\Store;

use PDO;
use PDOException;
use Throwable;

/**
 * Database-backed write coordination for Database Backup capture (#69).
 *
 * A capture opens a transaction and takes an exclusive row lock on the single
 * `store_write_gate` row before it reads anything. Every Store data mutation
 * starts its own transaction by locking that same row first:
 *
 * - Writers already holding the lock are admitted and finish completely; the
 *   capture then waits for them before it fixes its snapshot point, so a sale
 *   is never captured half-written.
 * - Once the capture holds the lock, a new writer's lock request fails
 *   immediately (a zero lock wait, never a queue) and the mutation is refused
 *   with a retryable StoreWritePausedException instead of being silently
 *   queued or replayed.
 * - The pause marker in the same row is observability only. The lock is the
 *   pause: a capture always holds the lock for as long as it needs it, so a
 *   writer that acquires the lock knows no capture is running. A marker left
 *   behind by a crash is therefore stale by definition and is cleared, which is
 *   what keeps a failure or a killed process from leaving a permanent pause.
 *
 * The visible "saving is paused" notice for active sessions is served from the
 * `backup_operations` table by the coordinating connection, because an
 * uncommitted row is invisible to other sessions.
 */
final class StoreWriteGate
{
    public const GATE_KEY = 'store_writes';
    public const CAPTURE_LOCK_WAIT_SECONDS = 30;
    public const DEFAULT_LOCK_WAIT_SECONDS = 50;

    /**
     * Begin a gated write transaction. Equivalent to PDO::beginTransaction()
     * except that the Store write gate is locked first and a capture in
     * progress is reported as a retryable pause instead of a lock timeout.
     */
    public static function begin(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            // Never nest: an already-open transaction is the caller's own scope.
            return;
        }

        if (self::driver($pdo) !== 'mysql') {
            self::ensureGateRow($pdo);
            $pdo->beginTransaction();
            return;
        }

        // Every lock request on the gate fails fast: a capture in progress must
        // produce an immediate retryable pause, never a queue or a stall.
        $previousWait = self::lockWaitSeconds($pdo);
        self::applyLockWait($pdo, 0);
        self::ensureGateRow($pdo);
        try {
            $pdo->beginTransaction();
        } catch (Throwable $exception) {
            self::applyLockWait($pdo, $previousWait);
            throw $exception;
        }

        try {
            $row = $pdo->query(
                'SELECT paused_at FROM store_write_gate WHERE gate_key = ' . self::quote(self::GATE_KEY) . ' FOR UPDATE'
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::applyLockWait($pdo, $previousWait);
            if (self::captureInProgress($pdo)) {
                throw new StoreWritePausedException(StoreWritePausedException::USER_MESSAGE, 0, $exception);
            }
            throw $exception;
        }
        self::applyLockWait($pdo, $previousWait);

        // The row lock, not the marker, is the pause. A capture always holds
        // the lock for as long as it needs it, so reaching this point means no
        // capture is running. Any marker left behind by an interrupted capture
        // is stale by definition and is cleared here, which is what keeps a
        // failure from leaving a permanent pause behind.
        if (is_array($row) && !empty($row['paused_at'])) {
            self::clearStalePauseMarker($pdo);
        }
    }

    /**
     * Run a Store write inside the gate even when the caller previously wrote
     * without an explicit transaction.
     */
    public static function run(PDO $pdo, callable $work)
    {
        self::begin($pdo);
        try {
            $result = $work($pdo);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Run one prepared Store write under the gate. Callers that already hold a
     * transaction keep it; callers that used to write in autocommit now get the
     * same pause guarantee without restructuring their code.
     */
    public static function execute(PDO $pdo, \PDOStatement $statement, array $parameters = []): bool
    {
        if ($pdo->inTransaction()) {
            return (bool)$statement->execute($parameters);
        }

        return (bool)self::run($pdo, static fn(): bool => $statement->execute($parameters));
    }

    /**
     * Read-only view of the coordination state for active sessions. Never
     * locks, so browsing and status checks keep working during a capture.
     */
    public static function status(PDO $pdo): array
    {
        $idle = [
            'paused' => false,
            'state' => 'idle',
            'message' => '',
            'started_at' => null,
        ];

        try {
            $table = self::operationTableExists($pdo);
        } catch (Throwable $exception) {
            error_log('Backup coordination status unavailable: ' . $exception->getMessage());
            return $idle;
        }
        if (!$table) {
            return $idle;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT operation_key, state, requested_by, started_at FROM backup_operations
                 WHERE active_slot = 1 ORDER BY started_at DESC LIMIT 1'
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            error_log('Backup coordination status unavailable: ' . $exception->getMessage());
            return $idle;
        }

        if (!is_array($row) || $row === []) {
            return $idle;
        }

        return [
            'paused' => true,
            'state' => (string)$row['state'],
            'message' => 'A database backup is in progress. Saving changes is temporarily paused.',
            'started_at' => (string)$row['started_at'],
        ];
    }

    public static function captureInProgress(PDO $pdo): bool
    {
        try {
            if (!self::operationTableExists($pdo)) {
                return false;
            }
            $stmt = $pdo->query(
                'SELECT COUNT(*) FROM backup_operations WHERE active_slot = 1 AND state = ' . self::quote('capturing')
            );
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $exception) {
            error_log('Backup coordination probe unavailable: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * The gate row is a single infrastructure row. Seeding it is idempotent so
     * a deployment that has not run the migration yet keeps serving writes; a
     * capture refuses to start without it (see BackupCoordinator).
     */
    public static function gateRowExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM store_write_gate WHERE gate_key = ?');
        $stmt->execute([self::GATE_KEY]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function lockWaitSeconds(PDO $pdo): int
    {
        if (self::driver($pdo) !== 'mysql') {
            return self::DEFAULT_LOCK_WAIT_SECONDS;
        }
        try {
            $value = $pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
            return max(0, (int)$value);
        } catch (Throwable) {
            return self::DEFAULT_LOCK_WAIT_SECONDS;
        }
    }

    public static function applyLockWait(PDO $pdo, int $seconds): void
    {
        if (self::driver($pdo) !== 'mysql') {
            return;
        }
        try {
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . max(0, $seconds));
        } catch (Throwable $exception) {
            error_log('Could not adjust the database lock wait: ' . $exception->getMessage());
        }
    }

    private static function ensureGateRow(PDO $pdo): void
    {
        if (self::driver($pdo) !== 'mysql') {
            return;
        }
        try {
            $stmt = $pdo->prepare('INSERT IGNORE INTO store_write_gate (gate_key) VALUES (?)');
            $stmt->execute([self::GATE_KEY]);
        } catch (Throwable $exception) {
            // Fail open for Store writes so a missing migration cannot take the
            // Store offline. A capture still fails closed on the same table.
            error_log('Store write gate row unavailable: ' . $exception->getMessage());
        }
    }

    private static function clearStalePauseMarker(PDO $pdo): void
    {
        try {
            $stmt = $pdo->prepare('UPDATE store_write_gate SET paused_at = NULL, paused_by = NULL WHERE gate_key = ?');
            $stmt->execute([self::GATE_KEY]);
        } catch (Throwable $exception) {
            error_log('Stale write pause marker could not be cleared: ' . $exception->getMessage());
        }
    }

    private static function operationTableExists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute(['backup_operations']);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function driver(PDO $pdo): string
    {
        return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
