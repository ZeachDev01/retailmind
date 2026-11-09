<?php

declare(strict_types=1);

namespace App\GCash;

use InvalidArgumentException;
use PDO;

/** Single seam for GCash table columns across SQLite fixtures and MySQL. */
final class GCashSchema
{
    /** @return list<string> */
    public static function columns(PDO $pdo, string $table): array
    {
        if (!preg_match('/^[a-z_]+$/', $table)) {
            throw new InvalidArgumentException('Invalid GCash table name.');
        }
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $stmt = $pdo->query($sqlite ? "PRAGMA table_info({$table})" : "SHOW COLUMNS FROM {$table}");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), $sqlite ? 'name' : 'Field');
    }
}
