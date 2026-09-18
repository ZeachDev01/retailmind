<?php

namespace App\Authorization;

use InvalidArgumentException;

final class AuthorizationContext
{
    private const STANDARD = 'standard';
    private const ACTIVE_EMERGENCY_ACCESS = 'active_emergency_access';
    private const EXPIRED_EMERGENCY_ACCESS = 'expired_emergency_access';

    private function __construct(
        private string $accessState,
        private ?string $emergencyReason
    ) {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD, null);
    }

    public static function emergencyAccess(string $reason): self
    {
        return new self(self::ACTIVE_EMERGENCY_ACCESS, self::requireReason($reason));
    }

    public static function expiredEmergencyAccess(string $reason): self
    {
        return new self(self::EXPIRED_EMERGENCY_ACCESS, self::requireReason($reason));
    }

    public function hasActiveEmergencyAccess(): bool
    {
        return $this->accessState === self::ACTIVE_EMERGENCY_ACCESS;
    }

    public function emergencyReason(): ?string
    {
        return $this->emergencyReason;
    }

    private static function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Emergency Access requires a reason.');
        }
        return $reason;
    }
}
