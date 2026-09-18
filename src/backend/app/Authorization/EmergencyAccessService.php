<?php

namespace App\Authorization;

use App\Audit\AuditRecordCategory;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

final class EmergencyAccessService
{
    public const DEFAULT_DURATION_MINUTES = 15;
    public const MAX_DURATION_MINUTES = 60;

    public function __construct(
        private PDO $pdo,
        private EmergencyAccessClock $clock = new SystemEmergencyAccessClock()
    ) {
    }

    public function activate(int $actorUserId, string $actorRole, string $reason): array
    {
        $this->requireSuperAdministrator($actorUserId, $actorRole);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Emergency Access requires a reason.');
        }
        return $this->transactional(function () use ($actorUserId, $reason): array {
            if ($this->activeSession($actorUserId) !== null) {
                throw new DomainException('Emergency Access is already active for this account.');
            }

            $now = $this->now();
            $duration = $this->configuredDurationMinutes();
            $expiresAt = $now->modify("+{$duration} minutes");
            $statement = $this->pdo->prepare(
                'INSERT INTO emergency_access_sessions
                 (actor_user_id, reason, activated_at, expires_at, duration_minutes, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $actorUserId,
                $reason,
                $this->format($now),
                $this->format($expiresAt),
                $duration,
                'active',
            ]);

            $sessionId = (int)$this->pdo->lastInsertId();
            $this->recordLifecycle($actorUserId, $sessionId, 'Emergency Access activated', [
                'reason' => $reason,
                'expires_at' => $this->format($expiresAt),
                'duration_minutes' => $duration,
            ]);
            return $this->find($sessionId);
        });
    }

    public function status(int $actorUserId): ?array
    {
        if ($actorUserId <= 0) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT session_id, actor_user_id, reason, activated_at, expires_at, duration_minutes,
                    status, revoked_at, revoked_by
             FROM emergency_access_sessions
             WHERE actor_user_id = ?
             ORDER BY session_id DESC LIMIT 1'
        );
        $statement->execute([$actorUserId]);
        $session = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            return null;
        }
        $session = $this->normalize($session);
        if ($session['status'] === 'active' && $this->isExpired($session)) {
            $update = $this->pdo->prepare(
                "UPDATE emergency_access_sessions SET status = 'expired' WHERE session_id = ? AND status = 'active'"
            );
            $update->execute([$session['session_id']]);
            $session['status'] = 'expired';
        }
        return $session;
    }

    public function revoke(int $actorUserId, string $actorRole): array
    {
        $this->requireSuperAdministrator($actorUserId, $actorRole);
        return $this->transactional(function () use ($actorUserId): array {
            $session = $this->activeSession($actorUserId);
            if ($session === null) {
                throw new DomainException('There is no active Emergency Access session to revoke.');
            }

            $revokedAt = $this->format($this->now());
            $statement = $this->pdo->prepare(
                "UPDATE emergency_access_sessions
                 SET status = 'revoked', revoked_at = ?, revoked_by = ?
                 WHERE session_id = ? AND status = 'active'"
            );
            $statement->execute([$revokedAt, $actorUserId, $session['session_id']]);
            $this->recordLifecycle($actorUserId, $session['session_id'], 'Emergency Access revoked', [
                'revoked_at' => $revokedAt,
            ]);
            return $this->find($session['session_id']);
        });
    }

    public function authorizationContext(int $actorUserId, string $actorRole): AuthorizationContext
    {
        if ($actorUserId <= 0 || $actorRole !== 'super_admin') {
            return AuthorizationContext::standard();
        }
        $session = $this->activeSession($actorUserId);
        return $session === null ? AuthorizationContext::standard() : AuthorizationContext::emergencyAccess(
            $session['session_id'],
            $actorUserId,
            $session['reason']
        );
    }

    public function requireOperationalMutation(int $actorUserId, string $actorRole, string $capability): array
    {
        $context = $this->authorizationContext($actorUserId, $actorRole);
        $policy = new RoleCapabilityPolicy();
        if (!$policy->allows($actorRole, $capability, null, $context, $actorUserId)
            || $context->emergencyAccessSessionId() === null) {
            throw new DomainException('Active Emergency Access is required for this Store operation.');
        }
        return ['emergency_access_session_id' => $context->emergencyAccessSessionId()];
    }

    private function activeSession(int $actorUserId): ?array
    {
        $session = $this->status($actorUserId);
        return $session !== null && $session['status'] === 'active' ? $session : null;
    }

    private function find(int $sessionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT session_id, actor_user_id, reason, activated_at, expires_at, duration_minutes,
                    status, revoked_at, revoked_by
             FROM emergency_access_sessions WHERE session_id = ?'
        );
        $statement->execute([$sessionId]);
        $session = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new DomainException('Emergency Access session was not found.');
        }
        return $this->normalize($session);
    }

    private function configuredDurationMinutes(): int
    {
        $statement = $this->pdo->prepare(
            "SELECT setting_value FROM platform_settings WHERE setting_key = 'emergency_access_duration_minutes'"
        );
        $statement->execute();
        $configured = (int)($statement->fetchColumn() ?: self::DEFAULT_DURATION_MINUTES);
        return min(self::MAX_DURATION_MINUTES, max(1, $configured));
    }

    private function recordLifecycle(int $actorUserId, int $sessionId, string $action, array $details): void
    {
        $metadata = ['emergency_access_session_id' => $sessionId] + $details;
        $statement = $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, metadata, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $actorUserId,
            $action,
            AuditRecordCategory::SECURITY,
            'Emergency Access',
            $sessionId,
            json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->format($this->now()),
        ]);
    }

    private function requireSuperAdministrator(int $actorUserId, string $actorRole): void
    {
        if ($actorUserId <= 0) {
            throw new DomainException('Authentication is required to manage Emergency Access.');
        }
        if ($actorRole !== 'super_admin') {
            throw new DomainException('Only a Super Administrator may manage Emergency Access.');
        }
    }

    private function normalize(array $session): array
    {
        foreach (['session_id', 'actor_user_id', 'duration_minutes'] as $field) {
            $session[$field] = (int)$session[$field];
        }
        $session['revoked_by'] = $session['revoked_by'] === null ? null : (int)$session['revoked_by'];
        return $session;
    }

    private function isExpired(array $session): bool
    {
        $expiry = new DateTimeImmutable($session['expires_at'], new DateTimeZone('UTC'));
        return $expiry <= $this->now();
    }

    private function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
