<?php

namespace App\Authorization;

use InvalidArgumentException;

final class AuthorizationContext
{
    private const STANDARD = 'standard';
    private const ACTIVE_EMERGENCY_ACCESS = 'active_emergency_access';

    private function __construct(
        private string $accessState,
        private ?int $emergencyAccessSessionId,
        private ?int $emergencyAccessActorId,
        private ?string $emergencyReason
    ) {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD, null, null, null);
    }

    public static function emergencyAccess(int $sessionId, int $actorUserId, string $reason): self
    {
        if ($sessionId <= 0 || $actorUserId <= 0) {
            throw new InvalidArgumentException('Emergency Access requires valid session and actor identifiers.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Emergency Access requires a reason.');
        }
        return new self(self::ACTIVE_EMERGENCY_ACCESS, $sessionId, $actorUserId, $reason);
    }

    public function hasActiveEmergencyAccess(?int $actorUserId = null): bool
    {
        return $this->accessState === self::ACTIVE_EMERGENCY_ACCESS
            && $actorUserId !== null
            && $this->emergencyAccessActorId === $actorUserId;
    }

    public function emergencyAccessSessionId(): ?int
    {
        return $this->emergencyAccessSessionId;
    }

    public function emergencyReason(): ?string
    {
        return $this->emergencyReason;
    }
}
