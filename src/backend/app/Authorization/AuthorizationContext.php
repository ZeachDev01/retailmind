<?php

namespace App\Authorization;

final class AuthorizationContext
{
    private function __construct()
    {
    }

    public static function standard(): self
    {
        return new self();
    }
}
