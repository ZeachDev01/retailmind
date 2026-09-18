<?php

namespace App\Authorization;

use DateTimeImmutable;
use DateTimeZone;

final class SystemEmergencyAccessClock implements EmergencyAccessClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
