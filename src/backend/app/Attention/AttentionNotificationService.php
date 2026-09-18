<?php

namespace App\Attention;

use PDO;

final class AttentionNotificationService
{
    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    public function synchronize(int $userId, array $items): void
    {
        $currentKeys = [];
        foreach ($items as $item) {
            $key = (string)$item['key'];
            $currentKeys[] = $key;
            $fingerprint = hash('sha256', json_encode([
                $item['severity'],
                $item['count'] ?? null,
                $item['explanation'],
                $item['destination'],
            ], JSON_THROW_ON_ERROR));
            $state = $this->state($userId, $key);
            $shouldNotify = $state === null
                || !(bool)$state['is_active']
                || !hash_equals((string)$state['fingerprint'], $fingerprint);

            if ($shouldNotify) {
                $this->notify($userId, $item);
            }
            $this->activate($userId, $key, $fingerprint, (string)$item['detected_at'], $state);
        }

        $this->resolveMissing($userId, $currentKeys);
    }

    private function state(int $userId, string $key): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT fingerprint, is_active, first_detected_at FROM attention_states WHERE user_id = ? AND attention_key = ?'
        );
        $statement->execute([$userId, $key]);
        $state = $statement->fetch(PDO::FETCH_ASSOC);
        return $state ?: null;
    }

    private function notify(int $userId, array $item): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications
                (user_id, type, title, message, reference_id, reference_type, attention_key,
                 attention_severity, attention_count, attention_destination, is_read, created_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 0, ?)'
        );
        $statement->execute([
            $userId,
            'attention',
            $item['title'],
            $item['explanation'],
            'attention',
            $item['key'],
            $item['severity'],
            $item['count'] ?? null,
            $item['destination'],
            $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function activate(int $userId, string $key, string $fingerprint, string $detectedAt, ?array $state): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        if ($state !== null) {
            $firstDetected = (bool)$state['is_active'] ? (string)$state['first_detected_at'] : $detectedAt;
            $statement = $this->pdo->prepare(
                'UPDATE attention_states
                 SET fingerprint = ?, is_active = 1, first_detected_at = ?, last_detected_at = ?, resolved_at = NULL
                 WHERE user_id = ? AND attention_key = ?'
            );
            $statement->execute([$fingerprint, $firstDetected, $now, $userId, $key]);
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO attention_states
                (user_id, attention_key, fingerprint, is_active, first_detected_at, last_detected_at, resolved_at)
             VALUES (?, ?, ?, 1, ?, ?, NULL)'
        );
        $statement->execute([$userId, $key, $fingerprint, $detectedAt, $now]);
    }

    private function resolveMissing(int $userId, array $currentKeys): void
    {
        $statement = $this->pdo->prepare(
            'SELECT attention_key FROM attention_states WHERE user_id = ? AND is_active = 1'
        );
        $statement->execute([$userId]);
        $resolve = $this->pdo->prepare(
            'UPDATE attention_states SET is_active = 0, resolved_at = ? WHERE user_id = ? AND attention_key = ?'
        );
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (!in_array($key, $currentKeys, true)) {
                $resolve->execute([$now, $userId, $key]);
            }
        }
    }
}
