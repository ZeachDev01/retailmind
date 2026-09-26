<?php

namespace App\Backup;

use PDO;
use RuntimeException;

/**
 * Writes a point-in-time SQL capture of the installed Store database.
 *
 * The capture is expected to run inside the caller's capture transaction while
 * that connection holds the Store write gate, so every read observes the same
 * committed state and no Store mutation can commit halfway through the dump.
 *
 * It captures every base table plus any view, trigger, routine, and event the
 * installation actually has, because a base-table-only export is not enough to
 * rebuild an arbitrary installed database. It never performs DDL on the source,
 * and it verifies the schema fingerprint before and after the walk so a schema
 * change that slipped in fails the capture instead of producing a file that
 * cannot be restored.
 */
final class DatabaseSnapshotWriter
{
    public const HEADER_MARKER = 'RetailMind database backup';

    /** @var callable():void */
    private $heartbeat;

    public function __construct(private PDO $pdo, ?callable $heartbeat = null)
    {
        $this->heartbeat = $heartbeat ?? static function (): void {
        };
    }

    public function write(string $destinationPath): array
    {
        $directory = dirname($destinationPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The temporary backup directory could not be prepared.');
        }

        $fingerprintBefore = $this->schemaFingerprint();
        $handle = fopen($destinationPath, 'wb');
        if (!$handle) {
            throw new RuntimeException('The temporary backup file could not be opened.');
        }

        $tables = 0;
        $rows = 0;
        try {
            $write = static function (string $value) use ($handle): void {
                if (fwrite($handle, $value) === false) {
                    throw new RuntimeException('The backup file could not be written.');
                }
            };

            $write('-- ' . self::HEADER_MARKER . "\n");
            $write('-- Snapshot: ' . gmdate('c') . "\n");
            $write("-- Format: encrypted envelope RMBAK1 applied on download\n");
            $write("SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($this->baseTables() as $table) {
                $quoted = self::identifier($table);
                $create = $this->pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);
                if (!$create) {
                    continue;
                }
                $write("\n-- Table {$table}\nDROP TABLE IF EXISTS {$quoted};\n{$create[1]};\n");

                $statement = $this->pdo->query("SELECT * FROM {$quoted}", PDO::FETCH_ASSOC);
                $columns = null;
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if ($columns === null) {
                        $columns = array_keys($row);
                    }
                    $values = [];
                    foreach ($columns as $column) {
                        $value = $row[$column];
                        $values[] = $value === null ? 'NULL' : $this->pdo->quote((string)$value);
                    }
                    $columnSql = implode(', ', array_map([self::class, 'identifier'], $columns));
                    $write("INSERT INTO {$quoted} ({$columnSql}) VALUES (" . implode(', ', $values) . ");\n");
                    $rows++;
                    if ($rows % 500 === 0) {
                        ($this->heartbeat)();
                    }
                }
                $tables++;
                ($this->heartbeat)();
            }

            $write("\nSET FOREIGN_KEY_CHECKS=1;\n");

            foreach ($this->views() as $view) {
                $definition = $this->pdo->query('SHOW CREATE VIEW ' . self::identifier($view))->fetch(PDO::FETCH_NUM);
                if (!$definition) {
                    continue;
                }
                $write("\n-- View {$view}\nDROP VIEW IF EXISTS " . self::identifier($view) . ";\n"
                    . str_replace('DEFINER=', '/* DEFINER= */', (string)$definition[1]) . ";\n");
            }

            foreach ($this->routines('trigger') as $trigger) {
                $row = $this->pdo->query('SHOW CREATE TRIGGER ' . self::identifier($trigger))->fetch(PDO::FETCH_NUM);
                $definition = $row[2] ?? null;
                if (!is_string($definition) || $definition === '') {
                    continue;
                }
                $write("\n-- Trigger {$trigger}\nDROP TRIGGER IF EXISTS " . self::identifier($trigger) . ";\n"
                    . str_replace('DEFINER=', '/* DEFINER= */', $definition) . ";\n");
            }

            foreach (['PROCEDURE', 'FUNCTION', 'EVENT'] as $type) {
                foreach ($this->routines(strtolower($type)) as $name) {
                    $statement = 'SHOW CREATE ' . $type . ' ' . self::identifier($name);
                    $row = $this->pdo->query($statement)->fetch(PDO::FETCH_NUM);
                    $definition = $row[2] ?? null;
                    if (!is_string($definition) || $definition === '') {
                        continue;
                    }
                    $write("\n-- " . ucfirst(strtolower($type)) . " {$name}\nDROP " . $type . ' IF EXISTS '
                        . self::identifier($name) . ";\n"
                        . str_replace('DEFINER=', '/* DEFINER= */', $definition) . ";\n");
                }
            }

            ($this->heartbeat)();
        } finally {
            fclose($handle);
        }

        if ($this->schemaFingerprint() !== $fingerprintBefore) {
            @unlink($destinationPath);
            throw new RuntimeException('The database structure changed while the backup was being captured, so no consistent backup could be produced. Try again.');
        }

        $size = (int)filesize($destinationPath);
        if ($size <= 0) {
            @unlink($destinationPath);
            throw new RuntimeException('The captured backup was empty.');
        }

        return [
            'path' => $destinationPath,
            'size' => $size,
            'tables' => $tables,
            'rows' => $rows,
        ];
    }

    public function schemaFingerprint(): string
    {
        $parts = [];
        foreach ($this->baseTables() as $table) {
            $row = $this->pdo->query('SHOW CREATE TABLE ' . self::identifier($table))->fetch(PDO::FETCH_NUM);
            $parts[] = $table . ':' . hash('sha256', (string)($row[1] ?? ''));
        }
        foreach ($this->views() as $view) {
            $parts[] = 'view:' . $view;
        }
        foreach (['trigger', 'procedure', 'function', 'event'] as $type) {
            foreach ($this->routines($type) as $name) {
                $parts[] = $type . ':' . $name;
            }
        }
        return hash('sha256', implode('|', $parts));
    }

    public static function identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /** @return list<string> */
    private function baseTables(): array
    {
        $tables = [];
        foreach ($this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) {
            $tables[] = (string)$row[0];
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    /** @return list<string> */
    private function views(): array
    {
        $views = [];
        foreach ($this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM) as $row) {
            $views[] = (string)$row[0];
        }
        sort($views, SORT_STRING);
        return $views;
    }

    /**
     * Stored-program names, queried in the form the server actually accepts
     * (MariaDB and MySQL spell these listings differently).
     *
     * @return list<string>
     */
    private function routines(string $type): array
    {
        $listings = [
            'trigger' => ['SHOW TRIGGERS', 'Trigger'],
            'procedure' => ['SHOW PROCEDURE STATUS WHERE Db = DATABASE()', 'Name'],
            'function' => ['SHOW FUNCTION STATUS WHERE Db = DATABASE()', 'Name'],
            'event' => ['SHOW EVENTS', 'Name'],
        ];
        if (!isset($listings[$type])) {
            return [];
        }
        [$statement, $column] = $listings[$type];
        $names = [];
        foreach ($this->pdo->query($statement)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[] = (string)($row[$column] ?? '');
        }
        $names = array_values(array_filter($names, static fn(string $name): bool => $name !== ''));
        sort($names, SORT_STRING);
        return $names;
    }
}
