<?php

namespace App\Backup;

use PDO;
use RuntimeException;

/** A deliberately small SQL dialect: exact compatible DDL, hex literals, NULL. */
final class SqlBackupFormat
{
    public const VERSION = 'RMSQL2';
    public const HEADER = "-- RetailMind database backup\n-- Format: RMSQL2\n";

    public static function schema(PDO $pdo): array
    {
        $schema = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) {
            $table = (string)$row[0];
            $quoted = DatabaseSnapshotWriter::identifier($table);
            $ddl = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
            $columns = $pdo->query('SHOW COLUMNS FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if (str_contains((string)$column['Extra'], 'GENERATED')) {
                    throw new RuntimeException('Generated columns are not supported by this backup format.');
                }
            }
            $schema[$table] = [
                'ddl' => self::normalizeDdl((string)$ddl[1]),
                'columns' => array_column($columns, 'Field'),
            ];
        }
        ksort($schema, SORT_STRING);
        return $schema;
    }

    public static function normalizeDdl(string $ddl): string
    {
        return (string)preg_replace('/ AUTO_INCREMENT=\d+/', '', trim($ddl));
    }

    /** Validate the complete file before allowing any destructive statement. */
    public static function statements(string $sql, array $schema): array
    {
        if (!str_starts_with($sql, self::HEADER)) {
            throw new RuntimeException('Select a current RetailMind SQL backup, not a legacy or arbitrary SQL file.');
        }
        if (!preg_match('/\n-- SHA256: ([a-f0-9]{64})\n\z/', $sql, $match, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('The backup is incomplete.');
        }
        $body = substr($sql, 0, $match[0][1]);
        if (!hash_equals($match[1][0], hash('sha256', $body))) {
            throw new RuntimeException('The backup is damaged or was edited.');
        }
        if (!$schema) {
            throw new RuntimeException('No compatible database schema is available.');
        }
        $statements = \split_sql_statements($body);
        if (array_shift($statements) !== 'SET FOREIGN_KEY_CHECKS=0'
            || array_shift($statements) !== "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'"
            || array_pop($statements) !== 'SET FOREIGN_KEY_CHECKS=1') {
            throw new RuntimeException('The backup format is not supported.');
        }
        $index = 0;
        foreach ($schema as $table => $definition) {
            $quoted = DatabaseSnapshotWriter::identifier($table);
            if (($statements[$index++] ?? '') !== 'DROP TABLE IF EXISTS ' . $quoted
                || self::normalizeDdl($statements[$index++] ?? '') !== $definition['ddl']) {
                throw new RuntimeException('The backup is incompatible with this database structure.');
            }
            $columns = implode(', ', array_map([DatabaseSnapshotWriter::class, 'identifier'], $definition['columns']));
            $prefix = "INSERT INTO {$quoted} ({$columns}) VALUES (";
            while (isset($statements[$index]) && str_starts_with($statements[$index], $prefix)) {
                $values = substr($statements[$index], strlen($prefix));
                $literal = "(?:NULL|X'(?:[a-f0-9]{2})*')";
                $pattern = '/\\A' . $literal . '(?:, ' . $literal . '){' . (count($definition['columns']) - 1) . '}\\)\\z/D';
                if (!preg_match($pattern, $values)) {
                    throw new RuntimeException('The backup contains unsupported data statements.');
                }
                $index++;
            }
        }
        if ($index !== count($statements)) {
            throw new RuntimeException('The backup contains unexpected tables or statements.');
        }
        return $statements;
    }
}
