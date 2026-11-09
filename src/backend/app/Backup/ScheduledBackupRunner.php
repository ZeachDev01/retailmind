<?php

namespace App\Backup;

/**
 * The narrow package-capture seam the Scheduled Backup service needs.
 *
 * DatabaseBackupService is the production implementation; contract tests
 * substitute a deterministic fake so scheduling, retention, and failure
 * preservation can be proved without MySQL or a real Store capture.
 */
interface ScheduledBackupRunner
{
    public function createForSystem(?string $filename = null): array;

    public function recordScheduledHistory(string $filename, string $status, ?string $notes, ?string $when = null): int;

    public function markHistoryFailed(int $backupId, string $notes, ?string $triggerSource = null): void;

    public function updateHistoryArtifact(int $backupId, string $path, ?string $fingerprint = null): void;
}
