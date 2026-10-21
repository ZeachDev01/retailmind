<?php

namespace App\Attention;

use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

final class AttentionNotificationService
{
    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /** @param AttentionItem[] $items */
    public function synchronize(int $userId, array $items, bool $createInApp = true, string $scope = ''): AttentionRefreshResult
    {
        if ($this->pdo->inTransaction()) {
            return $this->synchronizeInTransaction($userId, $items, $createInApp, $scope);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $result = $this->synchronizeInTransaction($userId, $items, $createInApp, $scope);
                $this->pdo->commit();
                return $result;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($attempt === 0 && $this->isConstraintConflict($exception)) {
                    continue;
                }
                throw $exception;
            }
        }

        throw new PDOException('Unable to synchronize attention state.');
    }

    /** @param AttentionItem[] $items */
    private function synchronizeInTransaction(int $userId, array $items, bool $createInApp, string $scope): AttentionRefreshResult
    {
        $currentKeys = [];
        $dashboardItems = [];
        $newItems = [];

        foreach ($items as $item) {
            $currentKeys[] = $item->key;
            $state = $this->state($userId, $item->key);
            $dashboardItem = $state !== null && (bool)$state['is_active']
                ? $item->withDetectedAt(new DateTimeImmutable((string)$state['first_detected_at']))
                : $item;
            $fingerprint = $dashboardItem->fingerprint();
            $shouldNotify = $state === null
                || !(bool)$state['is_active']
                || $dashboardItem->notificationLevel() > (int)$state['notification_level'];

            if ($shouldNotify) {
                $this->notify($userId, $dashboardItem, $createInApp || $item->required);
                $newItems[] = $dashboardItem;
            }
            $this->activate($userId, $dashboardItem, $fingerprint, $state);
            $dashboardItems[] = $dashboardItem;
        }

        $this->resolveMissing($userId, $currentKeys, $scope);
        return new AttentionRefreshResult($dashboardItems, $newItems);
    }

    private function state(int $userId, string $key): ?array
    {
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT fingerprint, notification_level, is_active, first_detected_at FROM attention_states WHERE user_id = ? AND attention_key = ?' . $lock
        );
        $statement->execute([$userId, $key]);
        $state = $statement->fetch(PDO::FETCH_ASSOC);
        return $state ?: null;
    }

    private function notify(int $userId, AttentionItem $item, bool $inApp): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications
                (user_id, type, title, message, reference_id, reference_type, attention_key,
                 attention_severity, attention_count, attention_destination, is_read, created_at, workspace, inapp_enabled, email_pending)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 1)'
        );
        $statement->execute([
            $userId,
            $item->notificationType,
            $item->title,
            $item->explanation,
            $item->referenceId,
            $item->referenceType,
            $item->key,
            $item->severity,
            $item->count,
            $item->destination,
            $this->clock->now()->format('Y-m-d H:i:s'),
            self::workspace($item->key),
            (int)$inApp,
        ]);
    }

    private function activate(int $userId, AttentionItem $item, string $fingerprint, ?array $state): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $detectedAt = $item->detectedAt->format('Y-m-d H:i:s');
        if ($state !== null) {
            $firstDetected = (bool)$state['is_active'] ? (string)$state['first_detected_at'] : $detectedAt;
            // Level memory only increases, so a condition that de-escalates and rises again re-alerts,
            // while an unchanged peak never repeats an alert.
            $statement = $this->pdo->prepare(
                'UPDATE attention_states
                 SET fingerprint = ?, is_active = 1, first_detected_at = ?, last_detected_at = ?, resolved_at = NULL,
                     notification_level = CASE WHEN notification_level > ? THEN notification_level ELSE ? END
                 WHERE user_id = ? AND attention_key = ?'
            );
            $statement->execute([$fingerprint, $firstDetected, $now, $item->notificationLevel(),
                $item->notificationLevel(), $userId, $item->key]);
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO attention_states
                (user_id, attention_key, fingerprint, notification_level, is_active, first_detected_at, last_detected_at, resolved_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, NULL)'
        );
        $statement->execute([$userId, $item->key, $fingerprint, $item->notificationLevel(), $detectedAt, $now]);
    }

    private function resolveMissing(int $userId, array $currentKeys, string $scope): void
    {
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT attention_key FROM attention_states WHERE user_id = ? AND is_active = 1 AND attention_key LIKE ?' . $lock
        );
        $statement->execute([$userId, $scope . '%']);
        $resolve = $this->pdo->prepare(
            'UPDATE attention_states SET is_active = 0, resolved_at = ? WHERE user_id = ? AND attention_key = ?'
        );
        // Resolved conditions are no longer new or escalated, so their queued daily email is dropped.
        $unsend = $this->pdo->prepare(
            'UPDATE notifications SET email_pending = 0 WHERE user_id = ? AND attention_key = ? AND email_pending = 1'
        );
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (!in_array($key, $currentKeys, true)) {
                $resolve->execute([$now, $userId, $key]);
                $unsend->execute([$userId, $key]);
            }
        }
    }

    public static function workspace(string $key): string
    {
        return match (explode('.', $key)[0]) {
            'platform', 'platform-training' => 'super_admin',
            'store', 'oversight' => 'admin',
            'inventory' => 'inventory_manager',
            'cashier' => 'cashier',
            default => throw new \InvalidArgumentException('Unknown notification condition scope.'),
        };
    }

    private function isConstraintConflict(Throwable $exception): bool
    {
        return $exception instanceof PDOException
            && (($exception->errorInfo[0] ?? null) === '23000' || (string)$exception->getCode() === '23000');
    }
}
