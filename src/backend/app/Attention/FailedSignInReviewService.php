<?php

namespace App\Attention;

use App\Audit\AuditRecordCategory;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

final class FailedSignInReviewService
{
    public const ACTION = 'Failed sign-ins reviewed';
    public const RESULTS = [
        'user_mistake' => 'Confirmed user mistake',
        'suspected_unauthorized' => 'Suspected unauthorized attempt',
        'unconfirmed' => 'Unable to confirm',
    ];

    private const PENDING = "la.was_successful = 0 AND la.attempted_at >= ?
        AND la.attempt_id > COALESCE((SELECT MAX(record_id) FROM activity_log
            WHERE category = 'security' AND action = 'Failed sign-ins reviewed'), 0)";

    public function __construct(private PDO $pdo, private Clock $clock) {}

    public function pendingCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts la WHERE ' . self::PENDING);
        $statement->execute([$this->cutoff()]);
        return (int)$statement->fetchColumn();
    }

    public function pendingAttempts(string $actorRole, ?int $throughId = null): array
    {
        $this->requireReviewer($actorRole);
        $sql = "SELECT la.attempt_id, la.username AS identity, la.ip_address, la.attempted_at,
                       CASE WHEN u.is_recovery_account = 1 THEN 'Recovery Account'
                            ELSE COALESCE(u.username, CASE WHEN la.username LIKE 'unknown:%'
                                THEN SUBSTR(la.username, 9) ELSE la.username END) END AS username
                FROM login_attempts la
                LEFT JOIN users u ON la.username LIKE 'user:%'
                    AND u.user_id = CAST(SUBSTR(la.username, 6) AS DECIMAL(20,0))
                WHERE " . self::PENDING;
        $params = [$this->cutoff()];
        if ($throughId !== null) {
            $sql .= ' AND la.attempt_id <= ?';
            $params[] = $throughId;
        }
        // shortcut: review the oldest 100 attempts per batch; add pagination if reviews need larger batches.
        $statement = $this->pdo->prepare($sql . ' ORDER BY la.attempt_id LIMIT 100');
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clearReviewed(string $actorRole, int $actorId, int $throughId, string $result, string $note, string $ipAddress): void
    {
        $this->requireReviewer($actorRole);
        $note = trim($note);
        if (!isset(self::RESULTS[$result]) || $note === '' || mb_strlen($note, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('Choose a review result and enter a note of up to 1,000 characters.');
        }
        if ($throughId < 1) {
            throw new InvalidArgumentException('Reload the page and review the listed attempts before clearing.');
        }

        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }
        try {
            $attempts = $this->pendingAttempts($actorRole, $throughId);
            if ($attempts === [] || (int)$attempts[array_key_last($attempts)]['attempt_id'] !== $throughId) {
                throw new DomainException('The listed attempts have changed. Reload the page and review them again.');
            }
            $statement = $this->pdo->prepare(
                'INSERT INTO activity_log (user_id, action, category, module, record_id, new_value, metadata, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $actorId, self::ACTION, AuditRecordCategory::SECURITY, 'Authentication', $throughId,
                json_encode([
                    'result' => self::RESULTS[$result], 'note' => $note,
                    'reviewed_count' => count($attempts),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode(['attempts' => $attempts], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $ipAddress, $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
            $statement = $this->pdo->prepare(
                "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND attention_key = 'platform.security.failed-logins'"
            );
            $statement->execute([$actorId]);
            $statement = $this->pdo->prepare(
                "UPDATE attention_states SET is_active = 0, resolved_at = ?
                 WHERE user_id = ? AND attention_key = 'platform.security.failed-logins' AND is_active = 1"
            );
            $statement->execute([$this->clock->now()->format('Y-m-d H:i:s'), $actorId]);
            if ($started) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function requireReviewer(string $actorRole): void
    {
        if ($actorRole !== 'super_admin') {
            throw new DomainException('Only the Super Administrator may review and clear failed sign-ins.');
        }
    }

    private function cutoff(): string
    {
        return $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s');
    }
}
