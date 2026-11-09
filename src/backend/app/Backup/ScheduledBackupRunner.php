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
        ?string $actorRole = null,
        ?string $keyFingerprint = null,
        ?string $artifactPath = null,
        ?string $triggerSource = null,
        ?string $verifiedAt = null
    ): int;

    public function updateHistoryArtifact(int $backupId, string $path, ?string $fingerprint = null): void;

    public function authorizeActor(int $actorUserId, string $actorRole): array;
}
