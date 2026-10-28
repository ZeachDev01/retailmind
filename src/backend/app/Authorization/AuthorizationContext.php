<?php

namespace App\Authorization;

final class AuthorizationContext
{
    private bool $isRemote;

    private function __construct(bool $isRemote = false)
    {
        $this->isRemote = $isRemote;
    }

    public static function standard(bool $isRemote = false): self
    {
        return new self($isRemote);
    }

    public static function remote(): self
    {
        return new self(true);
    }

    public function isRemote(): bool
    {
        return $this->isRemote;
    }
}
