<?php

namespace App\Backup;

use App\Store\StoreWriteGate;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Coordinates one Database Backup operation at a time across every user and
 * request (#69).
 *
 * Two records are used on purpose:
 *
 * - `backup_operations` holds the visible operation state. Its UNIQUE index on
 *   `active_slot` makes "at most one active operation" a database fact, so two
 *   overlapping requests can never both capture. A finished operation clears
 *   `active_slot` to NULL, which a UNIQUE index permits many times.
 * - `store_write_gate` is the row lock that actually pauses Store writes. It is
 *   locked by the capture transaction and unlocked by COMMIT or ROLLBACK, so a
 *   crash cannot leave a stale permanent pause behind.
 *
 * The operation record is written on a separate coordination connection so its
 * state and heartbeats stay visible to active sessions while the capture
 * transaction on the primary connection is still open.
 */
final class BackupCoordinator
{
    public const STATE_CAPTURING = 'capturing';
    public const STALE_AFTER_SECONDS = 300;

    private ?PDO $coordinationConnection = null;
    private int $previousLockWait = StoreWriteGate::DEFAULT_LOCK_WAIT_SECONDS;

    public function __construct(
        private PDO $pdo,
        private ?string $artifactDirectory = null,
        ?PDO $coordination = null
    ) {
        $this->coordinationConnection = $coordination;
    }

    public function artifactDirectory(): string
    {
        return $this->artifactDirectory
            ?? (RecoveryStore::directory() . '/downloads');
    }

    public function isOperationActive(): bool
    {
        return $this->activeOperation() !== [];
    }

    public function activeOperation(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT operation_key, state, requested_by, requested_by_role, started_at, heartbeat_at
                 FROM backup_operations WHERE active_slot = 1 LIMIT 1'
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (Throwable $exception) {
            error_log('Backup operation lookup failed: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * Claim the single active slot. Returns the new operation key, or null when
     * another request already owns the active operation.
     */
    public function claim(int $requestedBy, string $requestedByRole): ?string
    {
        $this->releaseAbandonedOperation();
        $key = bin2hex(random_bytes(16));
        try {
            $stmt = $this->coordination()->prepare(
                'INSERT INTO backup_operations (operation_key, active_slot, state, requested_by, requested_by_role, started_at, heartbeat_at)
                 VALUES (?, 1, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$key, self::STATE_CAPTURING, $requestedBy, $requestedByRole]);
            return $key;
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception)) {
                return null;
            }
            throw $exception;
        }
    }

    public function heartbeat(string $operationKey): void
    {
        try {
            $stmt = $this->coordination()->prepare('UPDATE backup_operations SET heartbeat_at = NOW() WHERE operation_key = ? AND active_slot = 1');
            $stmt->execute([$operationKey]);
        } catch (Throwable $exception) {
            error_log('Backup heartbeat failed: ' . $exception->getMessage());
        }
    }

    public function markCaptured(string $operationKey, string $token, string $path, string $filename, int $size, string $version, string $cipher, string $snapshotAt): void
    {
        $stmt = $this->coordination()->prepare(
            'UPDATE backup_operations
                SET artifact_token = ?, artifact_path = ?, filename = ?, file_size = ?, envelope_version = ?, cipher = ?, snapshot_at = ?
              WHERE operation_key = ?'
        );
        $stmt->execute([$token, $path, $filename, $size, $version, $cipher, $snapshotAt, $operationKey]);
    }

    public function complete(string $operationKey): void
    {
        $stmt = $this->coordination()->prepare(
            "UPDATE backup_operations SET state = 'completed', active_slot = NULL, finished_at = NOW() WHERE operation_key = ?"
        );
        $stmt->execute([$operationKey]);
    }

    public function fail(string $operationKey, string $detail): void
    {
        try {
            $stmt = $this->coordination()->prepare(
                "UPDATE backup_operations SET state = 'failed', active_slot = NULL, finished_at = NOW(), detail = ? WHERE operation_key = ?"
            );
            $stmt->execute([substr($detail, 0, 2000), $operationKey]);
        } catch (Throwable $exception) {
            error_log('Could not record the failed backup operation: ' . $exception->getMessage());
        }
    }

    /**
     * A capture that dies mid-flight can leave its operation row behind. The
     * write pause itself cannot survive (the capture transaction rolled back),
     * so the leftover row is only released when its heartbeat is older than the
     * stale window, which an actively capturing operation refreshes as it walks
     * the tables.
     */
    public function releaseAbandonedOperation(): void
    {
        try {
            $stmt = $this->coordination()->query(
                "UPDATE backup_operations
                    SET state = 'abandoned', active_slot = NULL, finished_at = NOW(),
                        detail = 'Released after an interrupted capture.'
                  WHERE active_slot = 1
                    AND (heartbeat_at IS NULL OR heartbeat_at < (NOW() - INTERVAL " . self::STALE_AFTER_SECONDS . " SECOND))"
            );
            $stmt->execute();
        } catch (Throwable $exception) {
            error_log('Abandoned backup operation check failed: ' . $exception->getMessage());
        }
    }

    /**
     * Open the capture transaction, drain already-admitted writers, and mark
     * the write pause. The returned closure must be run before commit().
     */
    public function beginCapture(int $requestedBy): void
    {
        if (!StoreWriteGate::gateRowExists($this->pdo)) {
            throw new RuntimeException(
                'Database Backup coordination is not installed on this server, so no consistent snapshot can be guaranteed. Ask your Super Administrator to apply the pending database migration.'
            );
        }
        $this->previousLockWait = StoreWriteGate::lockWaitSeconds($this->pdo);
        StoreWriteGate::applyLockWait($this->pdo, StoreWriteGate::CAPTURE_LOCK_WAIT_SECONDS);
        try {
            $this->pdo->beginTransaction();
        } catch (Throwable $exception) {
            StoreWriteGate::applyLockWait($this->pdo, $this->previousLockWait);
            throw $exception;
        }
        $this->pdo->exec(
            'UPDATE store_write_gate SET paused_at = NOW(), paused_by = ' . (int)$requestedBy
            . ' WHERE gate_key = ' . $this->pdo->quote(StoreWriteGate::GATE_KEY)
        );
    }

    public function releaseCapture(): void
    {
        if ($this->pdo->inTransaction()) {
            // Committing releases the gate row lock, so saving resumes as soon
            // as the snapshot no longer needs the pause, independently of how
            // long the browser takes to receive the download.
            $this->pdo->commit();
        }
        $this->clearPauseMarker();
        StoreWriteGate::applyLockWait($this->pdo, $this->previousLockWait);
    }

    public function abortCapture(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->clearPauseMarker();
        StoreWriteGate::applyLockWait($this->pdo, $this->previousLockWait);
    }

    private function clearPauseMarker(): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE store_write_gate SET paused_at = NULL, paused_by = NULL WHERE gate_key = ?'
            )->execute([StoreWriteGate::GATE_KEY]);
        } catch (Throwable $exception) {
            error_log('Backup pause marker could not be cleared: ' . $exception->getMessage());
        }
    }

    /**
     * Remove temporary artifacts. Only ever called once delivery finished or
     * failed, so a file that is still streaming is never deleted.
     */
    public function discard(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    public function discardStrandedArtifacts(int $olderThanSeconds = 3600): int
    {
        $removed = 0;
        foreach ($this->artifactFiles() as $file) {
            if ((time() - (int)filemtime($file)) < $olderThanSeconds) {
                continue;
            }
            @unlink($file);
            $removed++;
        }
        return $removed;
    }

    /** @return list<string> */
    public function artifactFiles(): array
    {
        $files = glob($this->artifactDirectory() . '/*') ?: [];
        return array_values(array_filter($files, static function (string $file): bool {
            $name = basename($file);
            return is_file($file)
                && $name !== '.gitkeep'
                && preg_match('/^[a-f0-9]{48}\.sql$/', $name) === 1;
        }));
    }

    private function coordination(): PDO
    {
        if ($this->coordinationConnection instanceof PDO) {
            return $this->coordinationConnection;
        }

        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );
        $this->coordinationConnection = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $this->coordinationConnection;
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        $sqlState = (string)($exception->errorInfo[0] ?? $exception->getCode());
        return $sqlState === '23000' || str_contains($exception->getMessage(), 'Duplicate entry');
    }
}
