<?php

namespace App\Authorization;

use DateTimeImmutable;

interface EmergencyAccessClock
{
    public function now(): DateTimeImmutable;
}
