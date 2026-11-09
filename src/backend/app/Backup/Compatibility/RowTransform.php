<?php

namespace App\Backup\Compatibility;

use RuntimeException;

/** Row-shape primitives shared by adapters. Surviving rows keep their order. */
final class RowTransform
{
    public static function keep(array $row, array $destinationColumns, array $defaults = []): array
    {
        $kept = [];
        foreach ($destinationColumns as $column) {
            $kept[$column] = $row[$column] ?? $defaults[$column] ?? null;
        }
        return $kept;
    }

    /** @param array<string,string> $map old column => new column */
    public static function rename(array $row, array $map): array
    {
        foreach ($map as $old => $new) {
            if (!array_key_exists($old, $row)) continue;
            if (!array_key_exists($new, $row) || $row[$new] === null) $row[$new] = $row[$old];
            unset($row[$old]);
        }
        return $row;
    }

    public static function defaults(array $rows, array $defaults): array
    {
        foreach ($rows as $index => $row) {
            foreach ($defaults as $column => $value) {
                if (!array_key_exists($column, $row) || $row[$column] === null) $rows[$index][$column] = $value;
            }
        }
        return $rows;
    }

    /** Unknown values fail closed; never invent a destination value. */
    public static function mapEnum(array $rows, string $column, array $valueMap, ?string $fallback = null): array
    {
        foreach ($rows as $index => $row) {
            if (!isset($row[$column])) continue;
            if (array_key_exists($row[$column], $valueMap)) {
                $rows[$index][$column] = $valueMap[$row[$column]];
            } elseif ($fallback !== null) {
                $rows[$index][$column] = $fallback;
            } else {
                throw new RuntimeException('The package contains an unsupported ' . $column . ' value.');
            }
        }
        return $rows;
    }

    public static function addRows(array $rows, string $table, array $rowsToAdd): array
    {
        $rows[$table] = array_merge($rows[$table] ?? [], array_values($rowsToAdd));
        return $rows;
    }

    public static function dropTable(array $rows, string $table): array
    {
        unset($rows[$table]);
        return $rows;
    }
}
