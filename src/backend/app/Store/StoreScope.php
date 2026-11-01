<?php

namespace App\Store;

use PDO;
use RuntimeException;

/** Single-store scope for the one-store application. */
final class StoreScope
{
    public function __construct(private PDO $pdo)
    {
    }

    public function preflight(): array
    {
        return ['status' => 'ready', 'store_id' => 1];
    }

    public function id(): int
    {
        return 1;
    }

    public function productScope(string $alias = 'p'): array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new RuntimeException('Invalid product scope alias.');
        }
        return ['', []];
    }

    public function migrate(): int
    {
        return 1;
    }
}
