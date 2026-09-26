<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use App\Backup\BackupKeyProvider;
use App\Backup\EncryptedBackupEnvelope;
use App\Backup\DatabaseSnapshotWriter;
use DomainException;
use PDO;
use Throwable;

/**
 * Super Administrator-only Database Restore adapted to the encrypted format
 * this feature produces (#69).
 *
 * Order matters and is deliberate: the file is verified, authenticated, and
 * decrypted to a private short-lived temporary file *before* any database
 * change happens, so a wrong key, a damaged file, or tampered content is
 * rejected while the Store database is still untouched.
 *
 * MySQL DDL is not transactionally rollback-safe. This service therefore does
 * not claim an all-or-nothing restore: it preserves the current Protected
 * Audit Records, reports the statements that actually ran, and relies on the
 * documented recovery procedure in docs/BACKUP_RECOVERY.md if a restore stops
 * part way.
 */
final class DatabaseRestoreService
{
    private const STAGE_TABLE = 'restore_audit_evidence_stage';

    public function __construct(
        private PDO $pdo,
        private RoleCapabilityPolicy $policy,
        private DatabaseBackupService $backups,
        private ?string $scratchDirectory = null
    ) {
    }

    public function authorize(string $actorRole): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::PLATFORM_GOVERNANCE)) {
            throw new DomainException('Only the Super Administrator may perform a Database Restore.');
        }
    }

    /**
     * The largest file this deployment can actually accept, derived from the
     * PHP upload limits rather than assumed, so an unsupported size is
     * reported before a restore is claimed to be possible.
     */
    public function supportedUploadBytes(): int
    {
        $upload = self::iniBytes((string)ini_get('upload_max_filesize'), 2 * 1024 * 1024);
        $post = self::iniBytes((string)ini_get('post_max_size'), 8 * 1024 * 1024);
        return (int)min($upload, $post);
    }

    public function supportedSizeLine(): string
    {
        $megabytes = (int)floor($this->supportedUploadBytes() / 1048576);
        return "Encrypted backups up to {$megabytes} MB can be restored with this server's upload limit. "
            . 'A larger backup needs the upload limit raised before it can be restored.';
    }

    /**
     * Decrypt and restore one backup. Returns the honest outcome for the audit
     * trail; throws before any change when the input cannot be trusted.
     */
    public function restore(int $actorUserId, string $actorRole, string $sourcePath, string $originalName): array
    {
        $this->authorize($actorRole);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $isEncrypted = $extension === EncryptedBackupEnvelope::EXTENSION
            || EncryptedBackupEnvelope::looksEncrypted($sourcePath);
        if ($extension !== EncryptedBackupEnvelope::EXTENSION && !$isEncrypted) {
            throw new DomainException('Select a RetailMind encrypted backup file (.rmbak) to restore.');
        }

        $scratch = $this->scratchFile();
        try {
            $plaintext = (new EncryptedBackupEnvelope(new BackupKeyProvider()))->openFile($sourcePath);
            if ($plaintext === '') {
                throw new DomainException('The backup file does not contain any recoverable records.');
            }
            if (file_put_contents($scratch, $plaintext, LOCK_EX) === false) {
                throw new DomainException('The backup could not be staged for restore.');
            }
            unset($plaintext);
        } catch (DomainException $exception) {
            @unlink($scratch);
            throw $exception;
        } catch (Throwable $exception) {
            @unlink($scratch);
            throw new DomainException(
                'The backup file could not be verified. It may be damaged, altered, or the recovery key does not match.',
                0,
                $exception
            );
        }

        try {
            $preserved = $this->preserveAuditRecords();
            $executed = $this->execute($scratch);
            $reconciled = $this->reconcileAuditRecords();

            $this->backups->recordHistory(
                basename($originalName),
                'restore',
                (int)filesize($sourcePath),
                'completed',
                $actorUserId,
                sprintf(
                    'Database Restore completed: %d SQL statement(s). %d Protected Audit Record(s) preserved and %d reconciled.',
                    $executed,
                    $preserved,
                    $reconciled
                ),
                gmdate('Y-m-d H:i:s'),
                (string)EncryptedBackupEnvelope::VERSION,
                EncryptedBackupEnvelope::CIPHER,
                $actorRole
            );
            $this->audit($actorUserId, 'Database restore', [
                'filename' => basename($originalName),
                'statements' => $executed,
                'preserved_audit_records' => $preserved,
                'reconciled_audit_records' => $reconciled,
            ]);

            return [
                'statements' => $executed,
                'preserved' => $preserved,
                'reconciled' => $reconciled,
                'message' => sprintf(
                    'Restore completed. %d SQL statement(s) were executed and %d Protected Audit Record(s) that postdate the snapshot were preserved. Sign in again if your session was replaced.',
                    $executed,
                    $reconciled
                ),
            ];
        } catch (Throwable $exception) {
            $this->backups->recordHistory(
                basename($originalName),
                'restore',
                (int)filesize($sourcePath),
                'failed',
                $actorUserId,
                'Database Restore stopped before completion: ' . $exception->getMessage()
            );
            $this->audit($actorUserId, 'Database restore', [
                'filename' => basename($originalName),
                'outcome' => 'incomplete',
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            // The decrypted intermediate is private and short lived.
            @unlink($scratch);
        }
    }

    /**
     * Copy the current Protected Audit Records aside before destructive
     * statements run, so evidence that postdates the snapshot is not silently
     * erased by restoring older data.
     *
     * The copy is staged under a name the snapshot cannot drop, because a
     * backup taken after an earlier recovery does contain the evidence table
     * itself and would otherwise overwrite it mid-restore.
     */
    private function preserveAuditRecords(): int
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::STAGE_TABLE);
        $this->pdo->exec('CREATE TABLE ' . self::STAGE_TABLE . ' LIKE activity_log');
        foreach ($this->foreignKeyNames(self::STAGE_TABLE) as $constraint) {
            $this->pdo->exec('ALTER TABLE ' . self::STAGE_TABLE . ' DROP FOREIGN KEY ' . DatabaseSnapshotWriter::identifier($constraint));
        }
        $this->pdo->exec('INSERT INTO ' . self::STAGE_TABLE . ' SELECT * FROM activity_log');
        return (int)$this->pdo->query('SELECT COUNT(*) FROM ' . self::STAGE_TABLE)->fetchColumn();
    }

    /**
     * Re-apply the preserved evidence that the restored snapshot did not
     * contain, so the Protected Audit Record invariant survives recovery, then
     * leave the reconciled evidence in place as the permanent recovery record.
     */
    private function reconcileAuditRecords(): int
    {
        $columns = [];
        foreach ($this->pdo->query('SHOW COLUMNS FROM activity_log')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = DatabaseSnapshotWriter::identifier((string)$row['Field']);
        }
        $columnList = implode(', ', $columns);

        $restored = 0;
        foreach ($this->pdo->query('SELECT * FROM ' . self::STAGE_TABLE . ' ORDER BY log_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $logId = (int)($row['log_id'] ?? 0);
            if ($logId <= 0) {
                continue;
            }
            $exists = (int)$this->pdo->query('SELECT COUNT(*) FROM activity_log WHERE log_id = ' . $logId)->fetchColumn();
            if ($exists > 0) {
                continue;
            }
            $values = array_values($row);
            $this->pdo->exec(
                "INSERT INTO activity_log ({$columnList}) VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ')',
                $values
            );
            $restored++;
        }

        $this->pdo->exec('DROP TABLE IF EXISTS restore_preserved_activity');
        $this->pdo->exec('CREATE TABLE restore_preserved_activity LIKE ' . self::STAGE_TABLE);
        $this->pdo->exec('INSERT INTO restore_preserved_activity SELECT * FROM ' . self::STAGE_TABLE);
        $this->pdo->exec('DROP TABLE ' . self::STAGE_TABLE);

        return $restored;
    }

    /** @return list<string> */
    private function foreignKeyNames(string $table): array
    {
        $names = [];
        $row = $this->pdo->query('SHOW CREATE TABLE ' . DatabaseSnapshotWriter::identifier($table))->fetch(PDO::FETCH_NUM);
        if (is_array($row) && isset($row[1])) {
            preg_match_all('/CONSTRAINT\s+`([^`]+)`\s+FOREIGN KEY/i', (string)$row[1], $matches);
            $names = $matches[1] ?? [];
        }
        return $names;
    }

    private function execute(string $scratch): int
    {
        if (!function_exists('restore_database_backup')) {
            throw new DomainException('The restore module is unavailable on this server.');
        }
        return (int)restore_database_backup($this->pdo, $scratch);
    }

    private function audit(int $actorUserId, string $action, array $metadata): void
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
            AuditRecordCategory::RECOVERY,
            $metadata
        );
    }

    private function scratchFile(): string
    {
        $directory = $this->scratchDirectory
            ?? (($GLOBALS['app']['storage_path'] ?? dirname(__DIR__, 2) . '/storage') . '/backups');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new DomainException('The temporary restore directory could not be prepared.');
        }
        return $directory . '/restore-' . bin2hex(random_bytes(16)) . '.tmp';
    }

    private static function iniBytes(string $value, int $fallback): int
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int)$value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
