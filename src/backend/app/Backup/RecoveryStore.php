<?php

namespace App\Backup;

use App\Core\Environment;
use RuntimeException;

/** Private filesystem state survives replacement (or loss) of database tables. */
final class RecoveryStore
{
    private static $requestLock = null;

    public static function directory(): string
    {
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
        return $real;
    }

    public static function path(string $name): string
    {
        return self::directory() . '/' . $name;
    }

    /** Hold a shared lease for the entire request, including existing writers. */
    public static function admitRequest(): bool
    {
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
        if (self::isPaused()) {
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
            throw new \DomainException('The Store is busy. Wait for current requests to finish, then try restoring again.');
        }
        return $handle;
    }

    public static function isPaused(): bool
    {
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
