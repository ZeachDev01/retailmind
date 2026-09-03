<?php

namespace App\Database;

use PDO;
use RuntimeException;

final class Schema
{
    public static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $stmt->execute([$table, $index]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function foreignKeyExists(PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = \'FOREIGN KEY\''
        );
        $stmt->execute([$table, $constraint]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!self::tableExists($pdo, $table)) {
            throw new RuntimeException("Required table '{$table}' does not exist.");
        }
        if (!self::columnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    public static function addIndexIfMissing(PDO $pdo, string $table, string $index, string $columns): void
    {
        if (!self::indexExists($pdo, $table, $index)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        }
    }

    public static function foreignKeyRelationExists(PDO $pdo, string $table, string $column, string $referencedTable, string $referencedColumn): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.key_column_usage WHERE constraint_schema = DATABASE() AND table_name = ? AND column_name = ? AND referenced_table_name = ? AND referenced_column_name = ?'
        );
        $stmt->execute([$table, $column, $referencedTable, $referencedColumn]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function addForeignKeyIfMissing(
        PDO $pdo,
        string $table,
        string $constraint,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $onDelete = 'RESTRICT'
    ): void {
        if (!self::tableExists($pdo, $table) || !self::tableExists($pdo, $referencedTable)) {
            throw new RuntimeException("Cannot add {$constraint}: a required table is missing.");
        }
        if (self::foreignKeyExists($pdo, $table, $constraint)
            || self::foreignKeyRelationExists($pdo, $table, $column, $referencedTable, $referencedColumn)) {
            return;
        }

        $orphanSql = "SELECT COUNT(*) FROM `{$table}` child LEFT JOIN `{$referencedTable}` parent ON child.`{$column}` = parent.`{$referencedColumn}` WHERE child.`{$column}` IS NOT NULL AND parent.`{$referencedColumn}` IS NULL";
        if ((int)$pdo->query($orphanSql)->fetchColumn() > 0) {
            throw new RuntimeException("Cannot add {$constraint}: orphaned values exist in {$table}.{$column}.");
        }

        $allowedDeletes = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];
        $onDelete = strtoupper($onDelete);
        if (!in_array($onDelete, $allowedDeletes, true)) {
            throw new RuntimeException("Unsupported ON DELETE action: {$onDelete}");
        }

        $pdo->exec(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}` (`{$referencedColumn}`) ON DELETE {$onDelete}"
        );
    }
}
