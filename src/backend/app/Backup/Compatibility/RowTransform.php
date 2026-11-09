<?php

namespace App\Backup\Compatibility;

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

}
