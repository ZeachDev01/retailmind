<?php
// Scheduled Backup execution contract (#460).
//
// Proves the scheduled-run seam with an injected clock, a fake package runner,
// and a temporary Backup Destination: verified completion, history binding,
// bounded retention, failure preservation, busy/recovery/ML skips, catch-up
// throttle, forced run authority, and no absolute paths in the returned view
// data. No MySQL and no real Store capture here.
$private = sys_get_temp_dir() . '/rm-sched-service-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
require_once __DIR__ . '/../bootstrap/app.php';
$_ENV['BACKUP_STORAGE_PATH'] = $private;

use App\Attention\Clock;
use App\Backup\RecoveryStore;
use App\Backup\ScheduledBackupRunner;
use App\Backup\ScheduledBackupService;
use App\Backup\ScheduledBackupSettings;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$throws = static function (callable $operation, string $exceptionClass, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert($exception instanceof $exceptionClass, $message . ' (received ' . $exception::class . ')');
    }
};

final class ScheduledServiceContractClock implements Clock
{
    public function __construct(public DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}

final class ScheduledServiceContractRunner implements ScheduledBackupRunner
{
    public const FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public bool $fail = false;
    public int $calls = 0;

    public function __construct(private PDO $pdo, private string $directory)
    {
    }

    public function createForSystem(?string $filename = null): array
    {
        $this->calls++;
        if ($this->fail) {
            throw new RuntimeException('fixture capture failure');
        }
        $filename = $filename ?? 'retailmind-scheduled-fixture.rmpkg';
        $path = $this->directory . '/' . bin2hex(random_bytes(24)) . '.rmpkg';
        file_put_contents($path, 'stub-package');
        $backupId = $this->recordHistory(
            $filename,
            'scheduled',
            12,
            'completed',
            null,
            'fixture capture',
            gmdate('Y-m-d H:i:s'),
            'RMSTORE2',
            'aes-256-gcm',
            'scheduled'
        );
        return [
            'status' => 'ready',
            'path' => $path,
            'backup_id' => $backupId,
            'filename' => $filename,
            'size' => 12,
            'key_fingerprint' => self::FINGERPRINT,
        ];
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
        ?string $actorRole = null,
        ?string $keyFingerprint = null,
        ?string $artifactPath = null,
        ?string $triggerSource = null,
        ?string $verifiedAt = null
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO backup_history
                (filename, backup_type, file_size, status, performed_by, notes, snapshot_at, envelope_version, cipher,
                 requested_by_role, key_fingerprint, artifact_path, trigger_source, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $filename, $type, $size, $status, $userId, $notes, $snapshotAt, $version, $cipher,
            $actorRole, $keyFingerprint, $artifactPath, $triggerSource, $verifiedAt,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateHistoryArtifact(int $backupId, string $path, ?string $fingerprint = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE backup_history SET artifact_path = ?, key_fingerprint = COALESCE(?, key_fingerprint) WHERE backup_id = ?'
        );
        $statement->execute([$path, $fingerprint, $backupId]);
    }

    public function authorizeActor(int $actorUserId, string $actorRole): array
    {
        return ['user_id' => $actorUserId, 'role_name' => $actorRole];
    }
}

$cleanup = static function (string $path) use (&$cleanup): void {
    if (is_dir($path)) {
        foreach (glob($path . '/*') ?: [] as $child) {
            $cleanup($child);
        }
        @rmdir($path);
    } elseif (is_file($path)) {
        @unlink($path);
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE platform_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_by INTEGER NULL,
        updated_at TEXT NULL
    )');
    $pdo->exec('CREATE TABLE backup_history (
        backup_id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL,
        backup_type TEXT NOT NULL DEFAULT "manual",
        file_size INTEGER NULL,
        status TEXT NOT NULL DEFAULT "completed",
        performed_by INTEGER NULL,
        notes TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        snapshot_at TEXT NULL,
        envelope_version TEXT NULL,
        cipher TEXT NULL,
        requested_by_role TEXT NULL,
        key_fingerprint TEXT NULL,
        artifact_path TEXT NULL,
        trigger_source TEXT NULL,
        verified_at TEXT NULL,
        removed_at TEXT NULL
    )');
    $pdo->exec('CREATE TABLE backup_operations (
        operation_key TEXT PRIMARY KEY,
        active_slot INTEGER NULL DEFAULT 1,
        state TEXT NOT NULL,
        requested_by INTEGER NULL,
        requested_by_role TEXT NULL,
        started_at TEXT NULL,
        heartbeat_at TEXT NULL,
        artifact_token TEXT NULL,
        artifact_path TEXT NULL,
        filename TEXT NULL,
        file_size INTEGER NULL,
        envelope_version TEXT NULL,
        cipher TEXT NULL,
        snapshot_at TEXT NULL,
        finished_at TEXT NULL,
        detail TEXT NULL
    )');
    $pdo->exec('CREATE TABLE store_write_gate (gate_key TEXT PRIMARY KEY, paused_at TEXT NULL, paused_by INTEGER NULL)');
    $pdo->exec("INSERT INTO store_write_gate (gate_key) VALUES ('store_writes')");
    $pdo->exec('CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL)');

    $destination = sys_get_temp_dir() . '/rm-sched-dest-' . bin2hex(random_bytes(6));
    mkdir($destination, 0700, true);
    $downloads = $private . '/downloads';
    mkdir($downloads, 0700, true);

    $manila = new DateTimeZone('Asia/Manila');
    $clock = new ScheduledServiceContractClock(new DateTimeImmutable('2026-11-08 23:05:00', $manila));
    $settings = new ScheduledBackupSettings($pdo, $clock);
    $runner = new ScheduledServiceContractRunner($pdo, $downloads);
    $verifier = static fn(string $path, string $bytes): string => ScheduledServiceContractRunner::FINGERPRINT;
    $service = new ScheduledBackupService($pdo, $clock, $settings, $runner, $destination, $verifier);

    $scheduledCount = static function () use ($pdo): int {
        return (int)$pdo->query("SELECT COUNT(*) FROM backup_history WHERE backup_type = 'scheduled'")->fetchColumn();
    };
    $completedCount = static function () use ($pdo): int {
        return (int)$pdo->query("SELECT COUNT(*) FROM backup_history WHERE backup_type = 'scheduled' AND status = 'completed'")->fetchColumn();
    };
    $filesIn = static function (string $directory): array {
        return array_values(array_filter(glob($directory . '/*.rmpkg') ?: [], 'is_file'));
    };

    // --- Completed run, retained artifact, history binding -----------------
    $first = $service->runDue();
    $assert(($first['outcome'] ?? '') === ScheduledBackupService::OUTCOME_COMPLETED, 'A due run should complete, got ' . var_export($first['outcome'] ?? null, true));
    $assert(($first['trigger'] ?? '') === 'scheduled', 'The due run trigger should be scheduled');
    $assert(isset($first['backup_id']) && (int)$first['backup_id'] > 0, 'A completed run should expose its backup id');
    $assert(!isset($first['path']) && !isset($first['artifact_path']), 'The returned view data must not expose private artifact paths');
    $assert(!str_contains((string)($first['message'] ?? ''), $destination), 'Operator messages must not expose the destination path');
    $assert(count($filesIn($destination)) === 1, 'Exactly one automatic package should be retained');
    $row = $pdo->query("SELECT * FROM backup_history WHERE backup_type = 'scheduled' ORDER BY backup_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $assert(($row['status'] ?? '') === 'completed', 'The completed run should record a completed scheduled row');
    $assert(($row['key_fingerprint'] ?? '') === ScheduledServiceContractRunner::FINGERPRINT, 'The completed row should record the verified key fingerprint');
    $assert(($row['trigger_source'] ?? '') === 'scheduled', 'The completed row should record its trigger source');
    $assert(($row['artifact_path'] ?? '') !== '' && is_file((string)$row['artifact_path']), 'The completed row should bind the retained artifact');
    $assert(str_replace('\\', '/', dirname((string)$row['artifact_path'])) === str_replace('\\', '/', (string)realpath($destination)), 'The retained artifact should live in the configured destination');
    $assert(preg_match('/^retailmind-scheduled-\d{4}-\d{2}-\d{2}-\d{6}\.rmpkg$/D', (string)$row['filename']) === 1, 'Automatic packages should use the stable local-timestamp filename');
    $state = $settings->get();
    $assert($state['last_outcome'] === 'completed', 'The settings should record the completed outcome');
    $assert($state['last_success_at'] === '2026-11-08 23:05:00', 'The settings should record the local success instant');
    $assert($state['last_attempt_at'] === '2026-11-08 23:05:00', 'The settings should record the local attempt instant');

    // --- Not due again in the same week -------------------------------------
    $again = $service->runDue();
    $assert(($again['outcome'] ?? '') === ScheduledBackupService::OUTCOME_NOT_DUE, 'A second tick in the same week should be not due');
    $assert($scheduledCount() === 1, 'A not-due tick should not record another scheduled row');

    // --- Next week's run replaces the previous automatic copy ---------------
    $firstPath = (string)$row['artifact_path'];
    $firstId = (int)$row['backup_id'];
    $clock->advance('+7 days');
    $second = $service->runDue();
    $assert(($second['outcome'] ?? '') === ScheduledBackupService::OUTCOME_COMPLETED, 'The next week should complete');
    $assert(!is_file($firstPath), 'Retention should remove the previous scheduled artifact');
    $assert(count($filesIn($destination)) === 1, 'Retention should keep exactly one automatic package');
    $removedAt = $pdo->query('SELECT removed_at FROM backup_history WHERE backup_id = ' . $firstId)->fetchColumn();
    $assert($removedAt !== null && $removedAt !== false && (string)$removedAt !== '', 'Retention should mark the replaced row as removed');
    $newRow = $pdo->query("SELECT * FROM backup_history WHERE status = 'completed' AND backup_type = 'scheduled' AND removed_at IS NULL ORDER BY backup_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $assert(is_file((string)$newRow['artifact_path']), 'The current package must survive its own retention pass');

    // --- Failure keeps the previous artifact --------------------------------
    $clock->advance('+7 days');
    $runner->fail = true;
    $failed = $service->runDue();
    $assert(($failed['outcome'] ?? '') === ScheduledBackupService::OUTCOME_FAILED, 'A capture failure should return failed');
    $assert(is_file((string)$newRow['artifact_path']), 'A failed run must keep the previous automatic artifact');
    $previousRemoved = $pdo->query('SELECT removed_at FROM backup_history WHERE backup_id = ' . (int)$newRow['backup_id'])->fetchColumn();
    $assert($previousRemoved === null || $previousRemoved === false || (string)$previousRemoved === '', 'A failed run must not mark the previous row removed');
    $failedRow = $pdo->query("SELECT * FROM backup_history WHERE status = 'failed' AND backup_type = 'scheduled' ORDER BY backup_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $assert(($failedRow['artifact_path'] ?? null) === null, 'A failed row should not claim an artifact');
    $assert(str_contains((string)$failedRow['notes'], 'fixture capture failure'), 'The Super Administrator-only history note should keep the failure detail');
    $assert($settings->get()['last_outcome'] === 'failed', 'The settings should record the failed outcome');
    $runner->fail = false;

    // --- Busy coordination skips, and retries later -------------------------
    $completedBefore = $completedCount();
    $pdo->exec("INSERT INTO backup_operations (operation_key, active_slot, state, started_at, heartbeat_at)
                VALUES ('busy-fixture', 1, 'capturing', '2026-11-29 23:05:00', '2026-11-29 23:05:00')");
    $clock->advance('+7 days');
    $busy = $service->runForced(1, 'super_admin');
    $assert(($busy['outcome'] ?? '') === ScheduledBackupService::OUTCOME_SKIPPED, 'A busy backup slot should skip');
    $assert(($busy['reason'] ?? '') === 'backup_in_progress', 'A busy backup slot should explain backup_in_progress');
    $assert(str_contains((string)($busy['message'] ?? ''), 'retry'), 'A skip message should say the run will retry');
    $assert($completedCount() === $completedBefore, 'A skipped run must not record a completed row');
    $skipRow = $pdo->query("SELECT * FROM backup_history WHERE status = 'skipped' ORDER BY backup_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $assert(($skipRow['backup_type'] ?? '') === 'scheduled', 'A skip should be recorded as a scheduled row');
    $assert($settings->get()['last_outcome'] === 'skipped', 'The settings should record the skipped outcome');
    $pdo->exec('DELETE FROM backup_operations');

    // --- ML jobs skip -------------------------------------------------------
    $pdo->exec("INSERT INTO ml_jobs (status) VALUES ('running')");
    $ml = $service->runForced(1, 'super_admin');
    $assert(($ml['outcome'] ?? '') === ScheduledBackupService::OUTCOME_SKIPPED, 'Active ML work should skip');
    $assert(($ml['reason'] ?? '') === 'ml_jobs_active', 'Active ML work should explain ml_jobs_active');
    $pdo->exec('DELETE FROM ml_jobs');

    // --- Recovery pause skips ----------------------------------------------
    file_put_contents(RecoveryStore::path('restore-state.json'), '{}');
    $paused = $service->runForced(1, 'super_admin');
    $assert(($paused['outcome'] ?? '') === ScheduledBackupService::OUTCOME_SKIPPED, 'Recovery should skip');
    $assert(($paused['reason'] ?? '') === 'recovery_paused', 'Recovery should explain recovery_paused');
    @unlink(RecoveryStore::path('restore-state.json'));

    // A tick that skipped does not advance the success instant, so it retries.
    $assert($settings->get()['last_success_at'] !== '2026-12-20 23:05:00', 'Skipped ticks must not advance the last success');

    // --- Catch-up runs once then throttles ---------------------------------
    $oldSuccess = $clock->now()->setTimezone($manila)->modify('-30 days')->format('Y-m-d H:i:s');
    $pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'backup_schedule_last_success_at'")->execute([$oldSuccess]);
    $catchup = $service->runCatchup();
    $assert(($catchup['outcome'] ?? '') === ScheduledBackupService::OUTCOME_COMPLETED, 'A catch-up should complete once');
    $assert(($catchup['trigger'] ?? '') === 'catchup', 'The catch-up trigger should be catchup');
    $throttled = $service->runCatchup();
    $assert(($throttled['outcome'] ?? '') === ScheduledBackupService::OUTCOME_THROTTLED, 'A second catch-up within the window should throttle');
    $assert($settings->get()['catchup_checked_at'] !== null, 'Catch-up should stamp the throttle before the attempt');

    // --- Forced run authority ----------------------------------------------
    $throws(
        static fn() => $service->runForced(2, 'admin'),
        DomainException::class,
        'An ordinary Administrator must not force a Scheduled Backup'
    );

    // --- Invalid destination fails closed ----------------------------------
    $invalidService = new ScheduledBackupService(
        $pdo,
        $clock,
        $settings,
        $runner,
        dirname(__DIR__, 3) . '/src/backend/storage/not-a-scheduled-destination',
        $verifier
    );
    $rejected = $invalidService->runForced(1, 'super_admin');
    $assert(($rejected['outcome'] ?? '') === ScheduledBackupService::OUTCOME_FAILED, 'An unsafe destination should fail the run');
    $assert(!str_contains((string)($rejected['message'] ?? ''), 'not-a-scheduled-destination'), 'The failure message must not echo the destination path');

    $cleanup($destination);
} catch (Throwable $exception) {
    $failures[] = 'Scheduled Backup service contract threw: ' . $exception->getMessage();
}

$cleanup($private);

if ($failures) {
    fwrite(STDERR, "Scheduled Backup service contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Scheduled Backup service contract: passed\n";
