<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use App\Backup\BackupCoordinator;
use App\Backup\DatabaseSnapshotWriter;
use App\Backup\SqlBackupFormat;
use App\Backup\RecoveryStore;
use App\Store\StoreWriteGate;
use DomainException;
use PDO;
use Throwable;

/**
 * The single shared Database Backup workflow behind both the Administrator and
 * the Super Administrator (#69). One workflow, one exporter, one history: the
 * two roles differ only in what the role capability policy lets them do.
 */
final class DatabaseBackupService
{
    public const OUTCOME_READY = 'ready';
    public const OUTCOME_IN_PROGRESS = 'already_in_progress';
    public const HISTORY_LIMIT = 30;

    public const IN_PROGRESS_LINE = 'A database backup is already being prepared. Wait for it to finish before starting another one.';

    public function __construct(
        private PDO $pdo,
        private RoleCapabilityPolicy $policy,
        private ?string $artifactDirectory = null,
        private ?PDO $coordinationConnection = null
    ) {
    }

    public function coordinator(): BackupCoordinator
    {
        return new BackupCoordinator($this->pdo, $this->artifactDirectory, $this->coordinationConnection);
    }

    /**
     * Create one consistent, unencrypted SQL Database Backup and leave a private
     * temporary artifact ready for the requester's download.
     */
    public function create(int $actorUserId, string $actorRole): array
    {
        $this->authorize($actorRole);
        return $this->capture($actorUserId, $actorRole);
    }

    /**
     * The existing scheduled script shares the same capture, coordination, and
     * SQL format instead of keeping a second exporter.
     */
    public function createForSystem(): array
    {
        return $this->capture(null, null);
    }

    private function capture(?int $actorUserId, ?string $actorRole): array
    {
        if (RecoveryStore::isPaused()) {
            throw new DomainException('Database recovery is in progress. Finish recovery before creating another backup.');
        }
        $coordinator = $this->coordinator();

        $operationKey = $coordinator->claim((int)$actorUserId, (string)($actorRole ?? 'scheduled'));
        if ($operationKey === null) {
            return [
                'status' => self::OUTCOME_IN_PROGRESS,
                'message' => self::IN_PROGRESS_LINE,
            ];
        }

        $token = bin2hex(random_bytes(24));
        $filename = self::downloadFilename();
        $artifactPath = $coordinator->artifactDirectory() . '/' . $token . '.sql';
        $snapshotAt = gmdate('Y-m-d H:i:s');

        try {
            $coordinator->beginCapture((int)$actorUserId);
            $capture = (new DatabaseSnapshotWriter($this->pdo, static function () use ($coordinator, $operationKey): void {
                $coordinator->heartbeat($operationKey);
            }))->write($artifactPath);
            $snapshotAt = gmdate('Y-m-d H:i:s');
            $coordinator->releaseCapture();
        } catch (Throwable $exception) {
            $coordinator->abortCapture();
            $coordinator->discard($artifactPath);
            $coordinator->fail($operationKey, $exception->getMessage());
            throw $exception;
        }

        $coordinator->markCaptured(
            $operationKey,
            $token,
            $artifactPath,
            $filename,
            (int)$capture['size'],
            SqlBackupFormat::VERSION,
            'none',
            $snapshotAt
        );
        $coordinator->complete($operationKey);

        $backupId = $this->recordHistory(
            $filename,
            $actorUserId === null ? 'scheduled' : 'manual',
            (int)$capture['size'],
            'completed',
            $actorUserId,
            sprintf(
                'SQL Database Backup created. %d table(s), %d record(s).',
                (int)$capture['tables'],
                (int)$capture['rows']
            ),
            $snapshotAt,
            SqlBackupFormat::VERSION,
            'none',
            $actorRole
        );

        return [
            'status' => self::OUTCOME_READY,
            'message' => 'Your SQL backup is ready to download.',
            'backup_id' => $backupId,
            'filename' => $filename,
            'token' => $token,
            'path' => $artifactPath,
            'size' => (int)$capture['size'],
            'snapshot_at' => $snapshotAt,
            'tables' => (int)$capture['tables'],
            'rows' => (int)$capture['rows'],
            'cipher' => 'none',
        ];
    }

    /**
     * Resolve a private temporary download to a real path. The reference is
     * bound to the requester that created it, so a leaked or guessed token
     * never exposes Store data to another account.
     */
    public function resolveDownload(string $token, int $actorUserId, string $actorRole): array
    {
        $this->authorize($actorRole);
        $token = trim($token);
        if ($token === '' || preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
            throw new DomainException('That backup download is no longer available. Create a new backup to download it again.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT operation_key, artifact_path, artifact_token, filename, file_size, requested_by
               FROM backup_operations
              WHERE artifact_token = ? AND state = 'completed' AND active_slot IS NULL
              ORDER BY started_at DESC LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row === []) {
            throw new DomainException('That backup download is no longer available. Create a new backup to download it again.');
        }
        if ((int)$row['requested_by'] !== $actorUserId) {
            throw new DomainException('That backup download belongs to another requester. Create your own backup to download it.');
        }

        $path = (string)$row['artifact_path'];
        $expectedPath = $this->coordinator()->artifactDirectory() . '/' . $token . '.sql';
        if (!is_file($path) || realpath($path) !== realpath($expectedPath)) {
            throw new DomainException('That backup download is no longer available. Create a new backup to download it again.');
        }

        return [
            'path' => $path,
            'filename' => (string)$row['filename'],
            'size' => (int)$row['file_size'],
            'operation_key' => (string)$row['operation_key'],
        ];
    }

    /**
     * Remove a delivered artifact. Called only after the response has been
     * streamed, so cleanup can never delete a file mid-download.
     */
    public function discardAfterDelivery(string $path): void
    {
        $this->coordinator()->discard($path);
    }

    /**
     * Limited backup history. The Administrator sees only Store-operational
     * backup metadata with safe messages; restore rows and the restricted
     * recovery detail stay with the Super Administrator.
     */
    public function history(string $actorRole, int $limit = self::HISTORY_LIMIT): array
    {
        $this->authorize($actorRole);
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT bh.backup_id, bh.filename, bh.backup_type, bh.file_size, bh.status, bh.created_at,
                       bh.snapshot_at, bh.requested_by_role, bh.envelope_version, bh.cipher, bh.notes, u.full_name
                  FROM backup_history bh
                  LEFT JOIN users u ON u.user_id = bh.performed_by';
        $parameters = [];
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::PLATFORM_GOVERNANCE)) {
            $sql .= ' WHERE bh.backup_type = ?';
            $parameters[] = 'manual';
        }
        $sql .= ' ORDER BY bh.created_at DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);
        $isSuperAdministrator = $this->policy->allows($actorRole, RoleCapabilityPolicy::PLATFORM_GOVERNANCE);

        return array_map(static function (array $row) use ($isSuperAdministrator): array {
            $isOperationalCreate = (string)$row['backup_type'] === 'manual';
            return [
                'backup_id' => (int)$row['backup_id'],
                'filename' => (string)$row['filename'],
                'backup_type' => (string)$row['backup_type'],
                'file_size' => (int)($row['file_size'] ?? 0),
                'status' => (string)$row['status'],
                'created_at' => (string)$row['created_at'],
                'snapshot_at' => $row['snapshot_at'] === null ? null : (string)$row['snapshot_at'],
                'requested_by' => (string)($row['full_name'] ?? 'Scheduled task'),
                'requested_by_role' => (string)($row['requested_by_role'] ?? ''),
                'format' => $row['envelope_version'] === SqlBackupFormat::VERSION
                    ? 'SQL (unencrypted)'
                    : ($row['envelope_version'] === null ? 'Plain SQL (legacy)' : 'Encrypted (legacy)'),
                // Restricted recovery detail stays with the Super Administrator,
                // and a completed row never claims the file was retained on the
                // owner's device.
                'notes' => $isSuperAdministrator || !$isOperationalCreate
                    ? (string)($row['notes'] ?? '')
                    : self::administratorNote($row),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function status(): array
    {
        return StoreWriteGate::status($this->pdo);
    }

    public function recordHistory(
        string $filename,
        string $type,
        int $size,
        string $status,
        ?int $userId,
        ?string $notes = null,
        ?string $snapshotAt = null,
        ?string $version = null,
        ?string $cipher = null,
        ?string $actorRole = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO backup_history
                (filename, backup_type, file_size, status, performed_by, notes, snapshot_at, envelope_version, cipher, requested_by_role)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$filename, $type, $size, $status, $userId, $notes, $snapshotAt, $version, $cipher, $actorRole]);
        return (int)$this->pdo->lastInsertId();
    }

    public function logActivity(int $actorUserId, string $action, array $metadata = []): void
    {
        if (!function_exists('log_activity')) {
            return;
        }
        log_activity(
            $this->pdo,
            $actorUserId,
            $action,
            'Backup & Restore',
            null,
            null,
            null,
            null,
            AuditRecordCategory::classify('Backup & Restore', $action),
            $metadata
        );
    }

    public function authorize(string $actorRole): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP)) {
            throw new DomainException('Your role cannot create or download a Database Backup.');
        }
    }

    public static function downloadFilename(): string
    {
        return 'retailmind-backup-' . date('m-d-Y-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
    }

    /**
     * Bounded cleanup for interrupted requests. Safe to call at any time: it
     * only removes artifacts that are old enough that no live delivery could
     * still be streaming them.
     */
    public function cleanupStrandedArtifacts(int $olderThanSeconds = 3600): int
    {
        return $this->coordinator()->discardStrandedArtifacts($olderThanSeconds);
    }

    private static function administratorNote(array $row): string
    {
        if ((string)$row['status'] !== 'completed') {
            return 'The backup was not created. Ask your Super Administrator for the stored failure details.';
        }
        return 'Backup created on the server. This does not confirm the file was saved on your device.';
    }
}
