<?php

namespace App\Backup;

use App\Attention\Clock;
use App\Attention\SystemClock;
use App\Services\PhilippineTime;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Super Administrator-owned Scheduled Backup platform settings (#460).
 *
 * Schedule state lives in `platform_settings` so it survives a Database
 * Restore. Dates are stored as Asia/Manila local `Y-m-d H:i:s` strings.
 */
final class ScheduledBackupSettings
{
    public const WEEKDAY_KEY = 'backup_schedule_weekday';
    public const TIME_KEY = 'backup_schedule_time';
    public const DESTINATION_KEY = 'backup_destination_path';
    public const LAST_SUCCESS_KEY = 'backup_schedule_last_success_at';
    public const LAST_ATTEMPT_KEY = 'backup_schedule_last_attempt_at';
    public const LAST_OUTCOME_KEY = 'backup_schedule_last_outcome';
    public const LAST_MESSAGE_KEY = 'backup_schedule_last_message';
    public const LAST_BACKUP_ID_KEY = 'backup_schedule_last_backup_id';
    public const CATCHUP_CHECKED_KEY = 'backup_schedule_catchup_checked_at';

    public const DEFAULT_WEEKDAY = 0;
    public const DEFAULT_TIME = '23:00';
    public const CATCHUP_AGE_DAYS = 7;
    public const CATCHUP_THROTTLE_HOURS = 6;

    public const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    private Clock $clock;

    public function __construct(private PDO $pdo, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @return array{
     *     weekday: int, time: string, destination: string, destination_configured: string,
     *     last_success_at: ?string, last_attempt_at: ?string, last_outcome: string,
     *     last_message: string, last_backup_id: ?int, catchup_checked_at: ?string, next_run_at: string
     * }
     */
    public function get(): array
    {
        $weekday = $this->readInt(self::WEEKDAY_KEY, self::DEFAULT_WEEKDAY);
        if (!self::isValidWeekday($weekday)) {
            $weekday = self::DEFAULT_WEEKDAY;
        }
        $time = $this->readString(self::TIME_KEY, self::DEFAULT_TIME);
        if (!self::isValidTime($time)) {
            $time = self::DEFAULT_TIME;
        }
        $configured = $this->readString(self::DESTINATION_KEY, '');
        $destination = $configured;
        try {
            $destination = self::resolveDestination($configured);
        } catch (Throwable) {
            if ($configured === '') {
                $destination = '';
            }
        }
        $lastBackupId = $this->readString(self::LAST_BACKUP_ID_KEY, '');

        return [
            'weekday' => $weekday,
            'time' => $time,
            'destination' => $destination,
            'destination_configured' => $configured,
            'last_success_at' => $this->readString(self::LAST_SUCCESS_KEY, '') ?: null,
            'last_attempt_at' => $this->readString(self::LAST_ATTEMPT_KEY, '') ?: null,
            'last_outcome' => $this->readString(self::LAST_OUTCOME_KEY, ''),
            'last_message' => $this->readString(self::LAST_MESSAGE_KEY, ''),
            'last_backup_id' => $lastBackupId === '' ? null : (int)$lastBackupId,
            'catchup_checked_at' => $this->readString(self::CATCHUP_CHECKED_KEY, '') ?: null,
            'next_run_at' => $this->nextRunAt(),
        ];
    }

    /**
     * Persist the weekly weekday, local time, and Backup Destination.
     *
     * @param array{weekday?: mixed, time?: mixed, destination?: mixed} $input
     */
    public function update(string $actorRole, array $input, int $actorUserId): void
    {
        if ($actorRole !== 'super_admin') {
            throw new DomainException('Only the Super Administrator may change the Scheduled Backup settings.');
        }
        foreach (['weekday', 'time', 'destination'] as $key) {
            if (!array_key_exists($key, $input)) {
                throw new InvalidArgumentException('Choose the Scheduled Backup schedule and Backup Destination.');
            }
        }
        $weekday = $input['weekday'];
        if (!self::isValidWeekday($weekday)) {
            throw new InvalidArgumentException('Choose a weekday for the Scheduled Backup.');
        }
        $time = trim((string)$input['time']);
        if (!self::isValidTime($time)) {
            throw new InvalidArgumentException('Enter the Scheduled Backup time as HH:MM using the 24-hour clock.');
        }
        $destination = trim((string)$input['destination']);
        $storedDestination = '';
        if ($destination !== '') {
            $storedDestination = self::resolveDestination($destination);
        } else {
            // A blank destination means the private-store default; fail closed
            // now if that default cannot hold a package.
            self::resolveDestination('');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->put(self::WEEKDAY_KEY, (string)(int)$weekday, $actorUserId);
            $this->put(self::TIME_KEY, $time, $actorUserId);
            $this->put(self::DESTINATION_KEY, $storedDestination, $actorUserId);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** The next scheduled opportunity strictly after $now, in Asia/Manila local time. */
    public function nextRunAt(?DateTimeImmutable $now = null): string
    {
        $now = ($now ?? $this->clock->now())->setTimezone(self::zone());
        $weekday = $this->readInt(self::WEEKDAY_KEY, self::DEFAULT_WEEKDAY);
        if (!self::isValidWeekday($weekday)) {
            $weekday = self::DEFAULT_WEEKDAY;
        }
        $time = $this->readString(self::TIME_KEY, self::DEFAULT_TIME);
        if (!self::isValidTime($time)) {
            $time = self::DEFAULT_TIME;
        }
        return self::nextScheduledAt($weekday, $time, $now)->format('Y-m-d H:i:s');
    }

    public function recordOutcome(string $outcome, ?int $backupId, string $message, ?string $successAt = null): void
    {
        if (!in_array($outcome, ['completed', 'skipped', 'failed'], true)) {
            throw new InvalidArgumentException('Unsupported Scheduled Backup outcome: ' . $outcome);
        }
        $now = $this->localNow();
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->put(self::LAST_ATTEMPT_KEY, $now, null);
            $this->put(self::LAST_OUTCOME_KEY, $outcome, null);
            $this->put(self::LAST_MESSAGE_KEY, $message, null);
            $this->put(self::LAST_BACKUP_ID_KEY, $backupId === null ? '' : (string)$backupId, null);
            if ($outcome === 'completed') {
                $this->put(self::LAST_SUCCESS_KEY, $successAt ?? $now, null);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** Written before a catch-up attempt so an hourly tick cannot retry immediately. */
    public function markCatchUpChecked(string $at): void
    {
        $this->put(self::CATCHUP_CHECKED_KEY, $at, null);
    }

    public static function isValidWeekday(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 6;
        }
        return is_string($value) && preg_match('/^[0-6]$/D', $value) === 1;
    }

    public static function isValidTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value) === 1;
    }

    /** The most recent configured weekday/time at or before $now. */
    public static function lastScheduledAt(int $weekday, string $time, DateTimeImmutable $now): DateTimeImmutable
    {
        $now = $now->setTimezone(self::zone());
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $offset = (7 + (int)$now->format('w') - $weekday) % 7;
        $candidate = $now->setTime($hour, $minute, 0)->modify('-' . $offset . ' days');
        if ($candidate > $now) {
            $candidate = $candidate->modify('-7 days');
        }
        return $candidate;
    }

    /** The first configured weekday/time strictly after $now. */
    public static function nextScheduledAt(int $weekday, string $time, DateTimeImmutable $now): DateTimeImmutable
    {
        $now = $now->setTimezone(self::zone());
        $last = self::lastScheduledAt($weekday, $time, $now);
        return $last > $now ? $last : $last->modify('+7 days');
    }

    public static function isDue(?string $lastSuccessAt, int $weekday, string $time, DateTimeImmutable $now): bool
    {
        $last = self::parseLocal($lastSuccessAt);
        if ($last === null) {
            return true;
        }
        return $last < self::lastScheduledAt($weekday, $time, $now);
    }

    public static function isCatchUp(?string $lastSuccessAt, DateTimeImmutable $now): bool
    {
        $last = self::parseLocal($lastSuccessAt);
        if ($last === null) {
            return false;
        }
        return ($now->getTimestamp() - $last->getTimestamp()) >= self::CATCHUP_AGE_DAYS * 86400;
    }

    public static function catchUpThrottled(?string $checkedAt, DateTimeImmutable $now): bool
    {
        $checked = self::parseLocal($checkedAt);
        if ($checked === null) {
            return false;
        }
        return ($now->getTimestamp() - $checked->getTimestamp()) < self::CATCHUP_THROTTLE_HOURS * 3600;
    }

    /**
     * Resolve and validate the Backup Destination. Blank means the private
     * recovery store's `scheduled` folder. Always fails closed with an
     * actionable operator message.
     */
    public static function resolveDestination(string $configured): string
    {
        $configured = trim($configured);
        $default = rtrim(RecoveryStore::directory(), '/\\') . DIRECTORY_SEPARATOR . 'scheduled';
        $path = $configured !== '' ? $configured : $default;

        if ($configured !== '') {
            if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) !== 1
                || preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $path) === 1
                || is_link($path)) {
                throw new InvalidArgumentException('The Backup Destination must be an absolute path outside the application and web root.');
            }
            self::assertOutsidePublicRoots(self::normalize($path), 'The Backup Destination must be an absolute path outside the application and web root.');
        }
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new InvalidArgumentException('The Backup Destination could not be prepared. Choose an existing writable folder.');
        }

        $real = realpath($path);
        if ($real === false) {
            throw new InvalidArgumentException('The Backup Destination could not be prepared. Choose an existing writable folder.');
        }
        self::assertOutsidePublicRoots(self::normalize($real), 'The Backup Destination must be an absolute path outside the application and web root.');
        self::assertOutsideRecoveryStore(self::normalize($real));

        if (!is_writable($real)) {
            throw new InvalidArgumentException('The Backup Destination is not writable.');
        }
        $probe = @tempnam($real, 'rmdest');
        if ($probe === false) {
            throw new InvalidArgumentException('The Backup Destination is not writable.');
        }
        @unlink($probe);
        $free = @disk_free_space($real);
        if ($free === false || $free < 2 * StorePackageFormat::MAX_BYTES) {
            throw new InvalidArgumentException('The Backup Destination needs at least 96 MB of free disk space.');
        }

        return $real;
    }

    private static function assertOutsidePublicRoots(string $candidate, string $message): void
    {
        $project = dirname(__DIR__, 4);
        foreach ([$project, (string)($_SERVER['DOCUMENT_ROOT'] ?? '')] as $public) {
            $public = $public !== '' ? realpath($public) : false;
            if ($public === false) {
                continue;
            }
            $prefix = self::normalize($public);
            if ($candidate === $prefix || str_starts_with($candidate, rtrim($prefix, '/') . '/')) {
                throw new InvalidArgumentException($message);
            }
        }
    }

    private static function assertOutsideRecoveryStore(string $candidate): void
    {
        try {
            $store = realpath(RecoveryStore::directory());
        } catch (Throwable) {
            return;
        }
        if ($store === false) {
            return;
        }
        $store = self::normalize($store);
        if ($candidate === $store || str_starts_with($store . '/', rtrim($candidate, '/') . '/')) {
            throw new InvalidArgumentException('Choose a Backup Destination outside the private recovery store.');
        }
        if ($candidate === $store . '/downloads' || str_starts_with($candidate, $store . '/downloads/')) {
            throw new InvalidArgumentException('Choose a Backup Destination outside the private recovery store.');
        }
        if (preg_match('~(?:^|/)safety-[^/]*$~', $candidate) === 1) {
            throw new InvalidArgumentException('Choose a Backup Destination outside the private recovery store.');
        }
    }

    private static function normalize(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(PhilippineTime::ZONE);
    }

    private static function parseLocal(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            $parsed = new DateTimeImmutable($value, self::zone());
            return $parsed->setTimezone(self::zone());
        } catch (Throwable) {
            return null;
        }
    }

    private function localNow(): string
    {
        return $this->clock->now()->setTimezone(self::zone())->format('Y-m-d H:i:s');
    }

    private function readString(string $key, string $default): string
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? $default : (string)$value;
    }

    private function readInt(string $key, int $default): int
    {
        $value = $this->readString($key, '');
        return is_numeric($value) ? (int)$value : $default;
    }

    private function put(string $key, string $value, ?int $actorUserId): void
    {
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM platform_settings WHERE setting_key = ?');
        $exists->execute([$key]);
        if ((int)$exists->fetchColumn() > 0) {
            $statement = $this->pdo->prepare(
                'UPDATE platform_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?'
            );
            $statement->execute([$value, $actorUserId, $key]);
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)'
        );
        $statement->execute([$key, $value, $actorUserId]);
    }
}
