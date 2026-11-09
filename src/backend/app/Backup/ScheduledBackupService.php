<?php

namespace App\Backup;

use App\Attention\Clock;
use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use App\Services\PhilippineTime;
use App\Store\StoreWriteGate;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The weekly Scheduled Backup workflow (#460).
 *
 * Decides whether the configured week is due (or catch-up is owed), delegates
 * the encrypted capture to DatabaseBackupService, verifies the package, copies
 * it to the Backup Destination, and only then removes the previous automatic
 * copy. Manual backups, restores, and safety copies are never retention
 * targets.
 */
final class ScheduledBackupService
{
    public const OUTCOME_COMPLETED = 'completed';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_NOT_DUE = 'not_due';
    public const OUTCOME_THROTTLED = 'throttled';

    public const CATCHUP_INTERVAL_HOURS = 6;
    public const CATCHUP_AGE_DAYS = 7;

    public const STABLE_NAME_PATTERN = '/^retailmind-scheduled-\d{4}-\d{2}-\d{2}-\d{6}(?:-\d+)?\.rmpkg$/D';

    private ScheduledBackupSettings $settings;
    private ScheduledBackupRunner $backups;
    /** @var callable|null */
    private $packageVerifier;

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        ?ScheduledBackupSettings $settings = null,
        ?ScheduledBackupRunner $backups = null,
        private ?string $destinationOverride = null,
        ?callable $packageVerifier = null
    ) {
        $this->settings = $settings ?? new ScheduledBackupSettings($pdo, $clock);
        $this->backups = $backups ?? new DatabaseBackupService($pdo, new RoleCapabilityPolicy());
        $this->packageVerifier = $packageVerifier;
    }

    /** Hourly/CLI entry point: run the configured week or one catch-up. */
    public function runDue(bool $allowCatchUp = true): array
    {
        $now = $this->nowLocal();
        $state = $this->settings->get();
        if (ScheduledBackupSettings::isDue($state['last_success_at'], $state['weekday'], $state['time'], $now)) {
            return $this->attempt('scheduled', $now);
        }
        if ($allowCatchUp && ScheduledBackupSettings::isCatchUp($state['last_success_at'], $now)) {
            return $this->catchUpAt($now);
        }
        return $this->result(
            self::OUTCOME_NOT_DUE,
            'scheduled',
            'The Scheduled Backup is not due yet.',
            'not_due',
            $now
        );
    }

    /** In-app catch-up path: one attempt per throttle window. */
    public function runCatchup(): array
    {
        $now = $this->nowLocal();
        $state = $this->settings->get();
        if (ScheduledBackupSettings::catchUpThrottled($state['catchup_checked_at'], $now)) {
            return $this->result(
                self::OUTCOME_THROTTLED,
                'catchup',
                'A catch-up check ran recently. The Scheduled Backup will check again later.',
                'throttled',
                $now
            );
        }
        if (!ScheduledBackupSettings::isCatchUp($state['last_success_at'], $now)) {
            return $this->result(
                self::OUTCOME_NOT_DUE,
                'catchup',
                'The Scheduled Backup does not need a catch-up.',
                'not_due',
                $now
            );
        }
        return $this->catchUpAt($now);
    }

    /** Super Administrator "Run scheduled backup now". */
    public function runForced(int $actorUserId, string $actorRole): array
    {
        if ($actorRole !== 'super_admin') {
            throw new DomainException('Only the Super Administrator may run a Scheduled Backup now.');
        }
        $this->backups->authorizeActor($actorUserId, $actorRole);
        $this->logActivity(null, 'Scheduled Database Backup requested', $actorRole === 'super_admin' ? ['trigger' => 'forced'] : []);
        return $this->attempt('forced', $this->nowLocal());
    }

    private function catchUpAt(DateTimeImmutable $now): array
    {
        // Stamp before attempting so a crash or an hourly retry cannot spin.
        $this->settings->markCatchUpChecked($now->format('Y-m-d H:i:s'));
        return $this->attempt('catchup', $now);
    }

    private function attempt(string $trigger, DateTimeImmutable $now): array
    {
        $state = $this->settings->get();
        $filename = $this->stableFilename($now, (string)($this->destinationOverride ?? $state['destination']));

        // Skip, never throw, when the Store is busy or recovering.
        if (RecoveryStore::isPaused()) {
            return $this->skip('recovery_paused', $trigger, $now, $filename);
        }
        if ($this->anotherBackupActive()) {
            return $this->skip('backup_in_progress', $trigger, $now, $filename);
        }
        if ($this->mlJobsActive()) {
            return $this->skip('ml_jobs_active', $trigger, $now, $filename);
        }

        try {
            $destination = ScheduledBackupSettings::resolveDestination(
                $this->destinationOverride ?? $state['destination_configured']
            );
        } catch (Throwable $exception) {
            return $this->fail($exception, $trigger, $now, $filename);
        }

        $filename = $this->stableFilename($now, $destination);
        try {
            $capture = $this->backups->createForSystem($filename);
        } catch (Throwable $exception) {
            return $this->fail($exception, $trigger, $now, $filename);
        }
        if (($capture['status'] ?? '') === DatabaseBackupService::OUTCOME_IN_PROGRESS) {
            return $this->skip('backup_in_progress', $trigger, $now, $filename);
        }
        $tempPath = (string)($capture['path'] ?? '');
        $backupId = (int)($capture['backup_id'] ?? 0);
        if (($capture['status'] ?? '') !== DatabaseBackupService::OUTCOME_READY || $tempPath === '' || $backupId <= 0) {
            return $this->fail(new RuntimeException('The encrypted package was not produced.'), $trigger, $now, $filename);
        }

        $newPath = $destination . DIRECTORY_SEPARATOR . $filename;
        try {
            $fingerprint = $this->verifyPackage($tempPath);
            $this->publish($tempPath, $newPath);
            $this->backups->updateHistoryArtifact($backupId, $newPath, $fingerprint);
            $this->stampHistory($backupId, $trigger, $now->format('Y-m-d H:i:s'));
            $removed = $this->retention($destination, $backupId, $now->format('Y-m-d H:i:s'));
        } catch (Throwable $exception) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
            if (is_file($newPath)) {
                @unlink($newPath);
            }
            return $this->fail($exception, $trigger, $now, $filename);
        }

        $message = 'Scheduled Backup completed and verified. The previous automatic copy was replaced.';
        $this->settings->recordOutcome(self::OUTCOME_COMPLETED, $backupId, $message, $now->format('Y-m-d H:i:s'));
        $this->logActivity(null, 'Scheduled Database Backup created', [
            'filename' => $filename,
            'size' => (int)($capture['size'] ?? 0),
            'trigger' => $trigger,
        ]);

        return $this->result(
            self::OUTCOME_COMPLETED,
            $trigger,
            $message,
            null,
            $now,
            $backupId,
            $filename,
            (int)($capture['size'] ?? 0),
            $fingerprint,
            $removed
        );
    }

    private function skip(string $reason, string $trigger, DateTimeImmutable $now, string $filename): array
    {
        $message = match ($reason) {
            'recovery_paused' => 'Database recovery is in progress. The Scheduled Backup was skipped and will retry on the next run.',
            'backup_in_progress' => 'Another database backup is in progress. The Scheduled Backup was skipped and will retry on the next run.',
            default => 'Forecasting or background work is running. The Scheduled Backup was skipped and will retry on the next run.',
        };
        $backupId = $this->recordHistory($filename, 'skipped', $reason, $now);
        $this->settings->recordOutcome(self::OUTCOME_SKIPPED, $backupId, $message);
        $this->logActivity(null, 'Scheduled Database Backup skipped', [
            'reason' => $reason,
            'trigger' => $trigger,
        ]);
        return $this->result(self::OUTCOME_SKIPPED, $trigger, $message, $reason, $now, $backupId, $filename);
    }

    private function fail(Throwable $exception, string $trigger, DateTimeImmutable $now, string $filename): array
    {
        // The full detail goes to the Super Administrator-only history note and
        // the server log; the operator only sees this stable sentence.
        $message = 'The Scheduled Backup did not complete. The previous automatic copy was kept. Review Backup history and the Windows task.';
        $backupId = $this->recordHistory($filename, 'failed', $exception->getMessage(), $now);
        try {
            $this->settings->recordOutcome(self::OUTCOME_FAILED, $backupId, $message);
        } catch (Throwable $recordError) {
            error_log('Scheduled Backup outcome could not be recorded: ' . $recordError->getMessage());
        }
        $this->logActivity(null, 'Scheduled Database Backup failed', ['trigger' => $trigger]);
        return $this->result(self::OUTCOME_FAILED, $trigger, $message, 'failed', $now, $backupId, $filename);
    }

    private function recordHistory(string $filename, string $status, string $notes, DateTimeImmutable $now): int
    {
        return $this->backups->recordHistory(
            $filename,
            'scheduled',
            0,
            $status,
            null,
            $notes,
            $now->format('Y-m-d H:i:s'),
            StorePackageFormat::KEY_VERSION,
            null,
            'scheduled',
            null,
            null,
            'scheduled',
            $now->format('Y-m-d H:i:s')
        );
    }

    private function verifyPackage(string $path): string
    {
        $bytes = (string)file_get_contents($path);
        if ($this->packageVerifier !== null) {
            $result = ($this->packageVerifier)($path, $bytes);
            $fingerprint = is_array($result) ? (string)($result['key_fingerprint'] ?? '') : (string)$result;
        } else {
            $decoded = StorePackageFormat::decode($bytes, SqlBackupFormat::schema($this->pdo));
            $fingerprint = (string)($decoded['key_fingerprint'] ?? '');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new RuntimeException('The backup package did not prove its recovery key.');
        }
        return $fingerprint;
    }

    /** Move the verified package out of the private temp store, cross-volume safe. */
    private function publish(string $source, string $destination): void
    {
        if (@rename($source, $destination)) {
            @chmod($destination, 0600);
            return;
        }
        if (!@copy($source, $destination)) {
            throw new RuntimeException('The verified package could not be copied to the Backup Destination.');
        }
        $sourceHash = hash_file('sha256', $source);
        $destinationHash = hash_file('sha256', $destination);
        if ($sourceHash === false || $destinationHash === false || !hash_equals($sourceHash, $destinationHash)) {
            @unlink($destination);
            throw new RuntimeException('The copied package did not match its verified bytes.');
        }
        @unlink($source);
        @chmod($destination, 0600);
    }

    private function stampHistory(int $backupId, string $trigger, string $verifiedAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE backup_history SET trigger_source = ?, verified_at = ? WHERE backup_id = ?'
        );
        $statement->execute([$trigger, $verifiedAt, $backupId]);
    }

    /**
     * Remove only previous completed Scheduled Backup artifacts inside the
     * configured destination. Manual, restore, and safety copies are untouched.
     */
    private function retention(string $destination, int $currentBackupId, string $removedAt): ?string
    {
        $statement = $this->pdo->query(
            "SELECT backup_id, artifact_path FROM backup_history
              WHERE backup_type = 'scheduled' AND status = 'completed'
                AND artifact_path IS NOT NULL AND artifact_path <> '' AND removed_at IS NULL
              ORDER BY backup_id DESC"
        );
        $destinationReal = realpath($destination);
        $removed = null;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)$row['backup_id'] === $currentBackupId) {
                continue;
            }
            $path = (string)$row['artifact_path'];
            $name = basename($path);
            if (preg_match(self::STABLE_NAME_PATTERN, $name) !== 1) {
                continue;
            }
            $directory = realpath(dirname($path));
            if ($destinationReal === false || $directory === false
                || str_replace('\\', '/', $directory) !== str_replace('\\', '/', $destinationReal)) {
                continue;
            }
            if (is_file($path) && !@unlink($path)) {
                continue;
            }
            $update = $this->pdo->prepare('UPDATE backup_history SET removed_at = ? WHERE backup_id = ?');
            $update->execute([$removedAt, (int)$row['backup_id']]);
            $removed = $name;
        }
        return $removed;
    }

    private function stableFilename(DateTimeImmutable $now, string $destination): string
    {
        $base = 'retailmind-scheduled-' . $now->format('Y-m-d-His');
        $filename = $base . '.rmpkg';
        $suffix = 2;
        while (is_file($destination . DIRECTORY_SEPARATOR . $filename)) {
            $filename = $base . '-' . $suffix . '.rmpkg';
            $suffix++;
        }
        return $filename;
    }

    private function mlJobsActive(): bool
    {
        try {
            return (bool)$this->pdo->query("SELECT 1 FROM ml_jobs WHERE status IN ('queued','running') LIMIT 1")->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /** Another capture holds the single active slot, across MySQL and test drivers. */
    private function anotherBackupActive(): bool
    {
        try {
            if ($this->pdo->query('SELECT 1 FROM backup_operations WHERE active_slot = 1 LIMIT 1')->fetchColumn()) {
                return true;
            }
        } catch (Throwable) {
            // The coordination table may not be installed yet; capture refuses later.
        }
        try {
            return (bool)(StoreWriteGate::status($this->pdo)['paused'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    private function result(
        string $outcome,
        string $trigger,
        string $message,
        ?string $reason,
        DateTimeImmutable $now,
        ?int $backupId = null,
        ?string $filename = null,
        int $size = 0,
        ?string $keyFingerprint = null,
        ?string $retainedRemoved = null
    ): array {
        return [
            'outcome' => $outcome,
            'trigger' => $trigger,
            'message' => $message,
            'reason' => $reason,
            'due_at' => null,
            'next_run_at' => $this->settings->nextRunAt($now),
            'backup_id' => $backupId,
            'filename' => $filename,
            'size' => $size,
            'key_fingerprint' => $keyFingerprint,
            'retained_removed' => $retainedRemoved,
        ];
    }

    private function logActivity(?int $actorUserId, string $action, array $metadata): void
    {
        if (!function_exists('log_activity')) {
            return;
        }
        try {
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
        } catch (Throwable $exception) {
            error_log('Scheduled Backup activity log skipped: ' . $exception->getMessage());
        }
    }

    private function nowLocal(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone(PhilippineTime::ZONE));
    }
}
