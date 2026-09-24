<?php

namespace App\Authorization;

use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

final class DormancyPolicyService
{
    public const DISABLE_DAYS_KEY = 'dormancy_disable_days';
    public const WARN_DAYS_KEY = 'dormancy_warn_days';
    public const DEFAULT_DISABLE_DAYS = 45;
    public const DEFAULT_WARN_DAYS = 30;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Dormancy Policy thresholds with cast-at-read integer semantics.
     *
     * @return array{disable_days: int, warn_days: int}
     */
    public function thresholds(): array
    {
        return [
            'disable_days' => $this->readInt(self::DISABLE_DAYS_KEY, self::DEFAULT_DISABLE_DAYS),
            'warn_days' => $this->readInt(self::WARN_DAYS_KEY, self::DEFAULT_WARN_DAYS),
        ];
    }

    public function disableDays(): int
    {
        return $this->thresholds()['disable_days'];
    }

    public function warnDays(): int
    {
        return $this->thresholds()['warn_days'];
    }

    /**
     * Persist Dormancy Policy thresholds for the Super Administrator only.
     *
     * @param array{disable_days?: mixed, warn_days?: mixed} $settings
     */
    public function update(string $actorRole, array $settings, int $actorUserId): void
    {
        if ($actorRole !== 'super_admin') {
            throw new DomainException('Only the Super Administrator may change the Dormancy Policy.');
        }

        $disableDays = $this->parseDays($settings, 'disable_days', 'Disable after');
        $warnDays = $this->parseDays($settings, 'warn_days', 'Warn after');
        if ($warnDays >= $disableDays) {
            throw new InvalidArgumentException('Warn after must be fewer days than Disable after.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->put(self::DISABLE_DAYS_KEY, $disableDays, $actorUserId);
            $this->put(self::WARN_DAYS_KEY, $warnDays, $actorUserId);
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

    private function parseDays(array $settings, string $key, string $label): int
    {
        if (!array_key_exists($key, $settings)) {
            throw new InvalidArgumentException("{$label} days is required.");
        }
        $value = $settings[$key];
        if (is_bool($value) || !is_numeric($value) || (float)(int)$value !== (float)$value) {
            throw new InvalidArgumentException("{$label} days must be a whole number.");
        }
        $days = (int)$value;
        if ($days < 0) {
            throw new InvalidArgumentException("{$label} days cannot be negative.");
        }
        return $days;
    }

    private function readInt(string $key, int $default): int
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM platform_settings WHERE setting_key = ?'
        );
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        if ($value === false || $value === null || !is_numeric($value)) {
            return $default;
        }
        return (int)$value;
    }

    private function put(string $key, int $value, int $actorUserId): void
    {
        $exists = $this->pdo->prepare(
            'SELECT COUNT(*) FROM platform_settings WHERE setting_key = ?'
        );
        $exists->execute([$key]);
        if ((int)$exists->fetchColumn() > 0) {
            $statement = $this->pdo->prepare(
                'UPDATE platform_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?'
            );
            $statement->execute([(string)$value, $actorUserId, $key]);
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO platform_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)'
        );
        $statement->execute([$key, (string)$value, $actorUserId]);
    }
}
