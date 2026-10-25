<?php

namespace App\Backup;

use App\Core\Environment;
use DomainException;
use RuntimeException;

/** Private filesystem state survives replacement (or loss) of database tables. */
final class RecoveryStore
{
    public const STAGE_SAFETY_VERIFIED = 'safety_verified';
    public const STAGE_DESTRUCTIVE_START = 'destructive_start';
    public const STAGE_DATABASE_REPLACEMENT = 'database_replacement';
    public const STAGE_UPLOADS = 'uploads';
    public const STAGE_AUTOMATED_VERIFICATION = 'automated_verification';
    public const STAGE_OPERATOR_REVIEW_PENDING = 'operator_review_pending';
    public const STAGE_RECOVERY_REQUIRED = 'recovery_required';
    public const STAGE_REOPENED_COMPLETED = 'reopened_completed';

    public const VALID_STAGES = [
        self::STAGE_SAFETY_VERIFIED,
        self::STAGE_DESTRUCTIVE_START,
        self::STAGE_DATABASE_REPLACEMENT,
        self::STAGE_UPLOADS,
        self::STAGE_AUTOMATED_VERIFICATION,
        self::STAGE_OPERATOR_REVIEW_PENDING,
        self::STAGE_RECOVERY_REQUIRED,
        self::STAGE_REOPENED_COMPLETED,
    ];

    public const THROTTLE_MAX_ATTEMPTS = 5;
    public const THROTTLE_WINDOW_SECONDS = 900;

    private static $requestLock = null;
    private static ?string $availableKey = null;
    private static ?bool $availableCache = null;
    private static ?string $directoryKey = null;
    private static ?string $directoryCache = null;

    /** Shared hosts can serve the Store while disabling recovery operations. */
    public static function isAvailable(): bool
    {
        $cacheKey = (string)ini_get('open_basedir') . '|' . (string)Environment::get('BACKUP_STORAGE_PATH', '');
        if (self::$availableKey === $cacheKey && self::$availableCache !== null) {
            return self::$availableCache;
        }

        self::$availableKey = $cacheKey;
        if (trim((string)Environment::get('BACKUP_STORAGE_PATH', '')) !== '') {
            return self::$availableCache = true;
        }
        $restricted = (string)ini_get('open_basedir');
        if ($restricted === '') {
            return self::$availableCache = true;
        }
        $project = dirname(__DIR__, 4);
        $default = dirname($project, 2) . '/retailmind-private-' . substr(hash('sha256', $project), 0, 16);
        $candidate = str_replace('\\', '/', $default);
        foreach (explode(PATH_SEPARATOR, $restricted) as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '.') {
                $allowed = getcwd();
            }
            $allowed = rtrim(str_replace('\\', '/', (string)$allowed), '/');
            if ($allowed !== '' && ($candidate === $allowed || str_starts_with($candidate, $allowed . '/'))) {
                return self::$availableCache = true;
            }
        }
        return self::$availableCache = false;
    }

    public static function directory(): string
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('Database Backup and Restore are unavailable on this hosting plan.');
        }
        $cacheKey = (string)ini_get('open_basedir') . '|' . (string)Environment::get('BACKUP_STORAGE_PATH', '');
        if (self::$directoryKey === $cacheKey && self::$directoryCache !== null) {
            return self::$directoryCache;
        }

        $project = dirname(__DIR__, 4);
        $configured = trim((string)Environment::get('BACKUP_STORAGE_PATH', ''));
        $path = $configured !== '' ? $configured : dirname($project, 2) . '/retailmind-private-' . substr(hash('sha256', $project), 0, 16);
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Private backup storage could not be prepared.');
        }
        $real = realpath($path);
        if ($real === false) {
            throw new RuntimeException('Private backup storage is unavailable.');
        }
        foreach ([$project, (string)($_SERVER['DOCUMENT_ROOT'] ?? '')] as $public) {
            $public = $public !== '' ? realpath($public) : false;
            if ($public !== false) {
                $prefix = strtolower(str_replace('\\', '/', $public));
                $candidate = strtolower(str_replace('\\', '/', $real));
                if ($candidate === $prefix || str_starts_with($candidate, rtrim($prefix, '/') . '/')) {
                    throw new RuntimeException('BACKUP_STORAGE_PATH must be outside the application and web document root.');
                }
            }
        }
        self::$directoryKey = $cacheKey;
        return self::$directoryCache = $real;
    }

    public static function path(string $name): string
    {
        return self::directory() . '/' . $name;
    }

    /** Hold a shared lease for the entire request, including existing writers. */
    public static function admitRequest(): bool
    {
        if (!self::isAvailable()) {
            return true;
        }
        if (self::isPaused()) {
            if (is_resource(self::$requestLock)) {
                fclose(self::$requestLock);
                self::$requestLock = null;
            }
            return false;
        }
        if (is_resource(self::$requestLock)) {
            return true;
        }
        $handle = fopen(self::path('requests.lock'), 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Recovery coordination is unavailable.');
        }
        if (!flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        self::$requestLock = $handle;
        return true;
    }

    public static function exclusive()
    {
        // Release our request lease before requesting exclusive access. Never
        // wait while holding it: two simultaneous restores must not deadlock.
        if (is_resource(self::$requestLock)) {
            fclose(self::$requestLock);
            self::$requestLock = null;
        }
        $handle = fopen(self::path('requests.lock'), 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new DomainException('The Store is busy. Wait for current requests to finish, then try again.');
        }
        return $handle;
    }

    public static function isPaused(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }
        clearstatcache(true, self::path('restore-state.json'));
        return is_file(self::path('restore-state.json'));
    }

    public static function state(): array
    {
        if (!self::isPaused()) {
            return [];
        }
        $state = json_decode((string)file_get_contents(self::path('restore-state.json')), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new RuntimeException('Recovery state is unreadable.');
        }
        return $state;
    }

    public static function recordOperation(array $state): void
    {
        if (!in_array($state['stage'] ?? '', self::VALID_STAGES, true)) {
            throw new DomainException('Invalid durable stage for recovery operation: ' . ($state['stage'] ?? ''));
        }
        if (empty($state['session_epoch'])) {
            $state['session_epoch'] = bin2hex(random_bytes(32));
        }
        self::write('session-epoch', $state['session_epoch']);
        self::write('restore-state.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        self::log((int)($state['actor_id'] ?? 0), 'operation-' . ($state['operation_id'] ?? 'init'), (string)$state['stage']);
    }

    public static function transitionStage(string $stage, array $extra = []): array
    {
        if (!self::isPaused()) {
            throw new DomainException('No active recovery operation to transition.');
        }
        if (!in_array($stage, self::VALID_STAGES, true)) {
            throw new DomainException('Invalid durable stage: ' . $stage);
        }
        $state = self::state();
        if ($stage === self::STAGE_DESTRUCTIVE_START) {
            $state['replacement_started'] = true;
        }
        $state['stage'] = $stage;
        $state['stages'][] = array_merge(['stage' => $stage, 'time' => gmdate('c')], $extra);
        $state['updated_at'] = gmdate('c');

        if ($stage === self::STAGE_REOPENED_COMPLETED) {
            self::log((int)($state['actor_id'] ?? 0), 'operation-' . ($state['operation_id'] ?? 'done'), 'completed');
            self::resume();
            return $state;
        }

        self::write('restore-state.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        self::log((int)($state['actor_id'] ?? 0), 'operation-' . ($state['operation_id'] ?? 'state'), $stage);
        return $state;
    }

    public static function currentStage(): ?string
    {
        if (!self::isPaused()) {
            return null;
        }
        $state = self::state();
        return isset($state['stage']) ? (string)$state['stage'] : null;
    }

    public static function isReplacementStarted(): bool
    {
        if (!self::isPaused()) {
            return false;
        }
        $state = self::state();
        return (bool)($state['replacement_started'] ?? false);
    }

    public static function recordReviewChecks(array $checks): void
    {
        if (!self::isPaused()) {
            throw new DomainException('No active recovery operation.');
        }
        $state = self::state();
        $state['review_checks'] = array_replace_recursive($state['review_checks'] ?? [], $checks);
        $state['updated_at'] = gmdate('c');
        self::write('restore-state.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public static function reopenedRecord(): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }
        $path = self::path('recovery-reopened.json');
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    public static function checkThrottle(string $key): array
    {
        $path = self::path('recovery-throttle.json');
        if (!is_file($path)) {
            return ['throttled' => false, 'attempts' => 0];
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key])) {
            return ['throttled' => false, 'attempts' => 0];
        }
        $now = time();
        $valid = array_values(array_filter($data[$key], static fn(int $time): bool => ($now - $time) < self::THROTTLE_WINDOW_SECONDS));
        if (count($valid) >= self::THROTTLE_MAX_ATTEMPTS) {
            $oldest = min($valid);
            $retryAfter = max(1, self::THROTTLE_WINDOW_SECONDS - ($now - $oldest));
            return ['throttled' => true, 'retry_after' => $retryAfter, 'attempts' => count($valid)];
        }
        return ['throttled' => false, 'attempts' => count($valid)];
    }

    public static function recordThrottleFailure(string $key): int
    {
        $path = self::path('recovery-throttle.json');
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $now = time();
        $history = isset($data[$key]) && is_array($data[$key])
            ? array_values(array_filter($data[$key], static fn(int $time): bool => ($now - $time) < self::THROTTLE_WINDOW_SECONDS))
            : [];
        $history[] = $now;
        $data[$key] = $history;
        self::write('recovery-throttle.json', json_encode($data, JSON_THROW_ON_ERROR));
        return count($history);
    }

    public static function clearThrottle(string $key): void
    {
        $path = self::path('recovery-throttle.json');
        if (!is_file($path)) {
            return;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (is_array($data) && isset($data[$key])) {
            unset($data[$key]);
            self::write('recovery-throttle.json', json_encode($data, JSON_THROW_ON_ERROR));
        }
    }

    public static function write(string $name, string $contents): void
    {
        $path = self::path($name);
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            self::persist($temporary, $contents, false);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Private recovery state could not be published.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function log(int $actorId, string $filename, string $outcome, int $statements = 0): void
    {
        $line = json_encode([
            'time' => gmdate('m-d-Y H:i:s') . ' UTC', 'actor_id' => $actorId,
            'filename' => basename($filename), 'outcome' => $outcome, 'statements' => $statements,
        ], JSON_THROW_ON_ERROR) . "\n";
        self::persist(self::path('restore-log.jsonl'), $line, true);
    }

    /** @return list<array> */
    public static function readLog(): array
    {
        $path = self::path('restore-log.jsonl');
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $entries[] = $data;
            }
        }
        return $entries;
    }

    private static function persist(string $path, string $contents, bool $append): void
    {
        $handle = fopen($path, $append ? 'ab' : 'wb');
        if ($handle === false) {
            throw new RuntimeException('Private recovery state could not be opened.');
        }
        try {
            chmod($path, 0600);
            if (!flock($handle, LOCK_EX) || fwrite($handle, $contents) !== strlen($contents)
                || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Private recovery state could not be saved completely.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function epoch(): string
    {
        if (!self::isAvailable()) {
            return 'initial';
        }
        $path = self::path('session-epoch');
        if (!is_file($path)) {
            return 'initial';
        }
        $epoch = file_get_contents($path);
        if ($epoch === false || trim($epoch) === '') {
            throw new RuntimeException('Session recovery state is unavailable.');
        }
        return trim($epoch);
    }

    public static function resume(): void
    {
        if (self::isPaused() && !unlink(self::path('restore-state.json'))) {
            throw new RuntimeException('Recovery finished but Store access could not be resumed.');
        }
    }
}
