<?php

namespace App\Attention;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
