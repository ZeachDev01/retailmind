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
            // MySQL recalculates generated values from the restored source columns.
            $columns = array_values(array_filter($columns, static fn(array $column): bool =>
                !preg_match('/\b(VIRTUAL|STORED) GENERATED\b/i', (string)$column['Extra'])
            ));
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
        $ddl = preg_replace('/ CHARACTER SET ([a-z0-9]+)(?= COLLATE \1_)/i', '', $ddl);
        return (string)preg_replace('/ AUTO_INCREMENT=\d+/', '', trim($ddl));
    }

    /** Compare known SHOW CREATE differences without changing literals, constraints or the stored fingerprint. */
    private static function portableDdl(string $ddl): string
    {
        if (str_contains($ddl, "\x01") || str_contains($ddl, "\x02")) throw new RuntimeException('Invalid schema text.');
        $literals = [];
        $ddl = preg_replace_callback("/'(?:[^'\\\\]|\\\\.|'')*'/s", static function(array $match) use (&$literals): string {
            $key = "\x01" . count($literals) . "\x02";
            $literals[$key] = $match[0];
            return $key;
        }, self::normalizeDdl($ddl));
        preg_match('/ COLLATE=([a-z0-9_]+)$/D', $ddl, $collation);
        $lines = explode("\n", $ddl);
        foreach ($lines as &$line) {
            if (preg_match('/^  (`[^`]+`) longtext COLLATE utf8mb4_bin(.*?) CHECK \(json_valid\(\1\)\)(,?)$/D', $line, $json)) {
                $line = '  ' . $json[1] . ' json' . $json[2] . $json[3];
            }
            if (preg_match('/^  `[^`]+` /', $line)) {
                if (isset($collation[1])) $line = str_replace(' COLLATE ' . $collation[1], '', $line);
                if (!str_contains(strtolower($line), 'zerofill')) $line = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $line);
                if (preg_match('/^  `[^`]+` (?:tinyint|smallint|mediumint|int|bigint|decimal|float|double)\b/', $line)) {
                    $line = preg_replace_callback('/DEFAULT (\x01\d+\x02)/', static function(array $match) use ($literals): string {
                        $literal = $literals[$match[1]];
                        return preg_match("/^'-?\d+(?:\.\d+)?'$/D", $literal) ? 'DEFAULT ' . substr($literal, 1, -1) : $match[0];
                    }, $line);
                }
                if (preg_match('/^  `[^`]+` (?:tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob)\b/', $line) && !str_contains($line, 'NOT NULL')) {
                    $line = str_replace(' DEFAULT NULL', '', $line);
                }
                if (str_contains($line, 'GENERATED ALWAYS AS')) $line = preg_replace('/if\(\((`[^`]+` = _utf8mb4\x01\d+\x02)\),/', 'if($1,', $line);
            }
            $line = preg_replace('/\b(DEFAULT|ON UPDATE) current_timestamp(?:\(\))?/i', '$1 CURRENT_TIMESTAMP', $line);
            $line = preg_replace('/CHECK \(\((`[^`]+` (?:>=|<=|>|<|=) -?\d+(?:\.\d+)?)\)\)/', 'CHECK ($1)', $line);
            if (preg_match('/^  CONSTRAINT `[^`]+` FOREIGN KEY /', $line)) $line = str_replace(' ON DELETE RESTRICT', '', $line);
        }
        unset($line);
        return strtr(implode("\n", $lines), $literals);
    }

    /** The encrypted producer SQL is parsed with its original schema; replacement still uses destination DDL. */
    public static function compatibleSourceSchema(string $sql, array $destination): array
    {
        $statements = \split_sql_statements($sql);
        $index = 2;
        $source = $destination;
        foreach ($destination as $table => $definition) {
            $quoted = DatabaseSnapshotWriter::identifier($table);
            if (($statements[$index++] ?? '') !== 'DROP TABLE IF EXISTS ' . $quoted) throw new RuntimeException('The package has an incompatible table sequence.');
            $ddl = self::normalizeDdl($statements[$index++] ?? '');
            if (self::portableDdl($ddl) !== self::portableDdl($definition['ddl'])) throw new RuntimeException('The package has an incompatible database structure: ' . $table . '.');
            $source[$table]['ddl'] = $ddl;
            while (isset($statements[$index]) && str_starts_with($statements[$index], 'INSERT INTO ' . $quoted . ' ')) $index++;
        }
        self::statements($sql, $source);
        return $source;
    }

    /** Decode the validated data dialect without executing any source SQL. */
    public static function rows(string $sql, array $schema): array
    {
        self::statements($sql, $schema);
        $rows = array_fill_keys(array_keys($schema), []);
        $count = 0;
        foreach (\split_sql_statements($sql) as $statement) {
            if (!preg_match('/^INSERT INTO `([^`]+)` .* VALUES \((.*)\)$/sD', $statement, $match)) continue;
            if (++$count > 100000) throw new RuntimeException('The package exceeds the 100,000 record validation limit.');
            preg_match_all("/NULL|X'([a-f0-9]*)'/", $match[2], $values, PREG_SET_ORDER);
            $rows[$match[1]][] = array_combine($schema[$match[1]]['columns'], array_map(
                static fn(array $value): ?string => $value[0] === 'NULL' ? null : hex2bin($value[1]), $values
            ));
        }
        return $rows;
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
                // MySQL otherwise coerces X'31' to integer 49 instead of decoded text "1".
                $statements[$index] = $prefix . preg_replace("/X'([a-f0-9]*)'/", "CONCAT(X'$1')", $values);
                $index++;
            }
        }
        if ($index !== count($statements)) {
            throw new RuntimeException('The backup contains unexpected tables or statements.');
        }
        return $statements;
    }
}
