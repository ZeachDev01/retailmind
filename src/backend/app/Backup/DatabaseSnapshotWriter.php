<?php

namespace App\Backup;

use PDO;
use RuntimeException;

/** Consistent, complete base-table dump; unsupported database objects fail closed. */
final class DatabaseSnapshotWriter
{
    public const HEADER_MARKER = 'RetailMind database backup';
    private $heartbeat;

    public function __construct(private PDO $pdo, ?callable $heartbeat = null)
    {
        $this->heartbeat = $heartbeat ?? static function (): void {};
    }

    public function write(string $destinationPath): array
    {
        $this->assertSupportedObjects();
        $schema = SqlBackupFormat::schema($this->pdo);
        if (!$schema) {
            throw new RuntimeException('The database has no tables to back up.');
        }
        $directory = dirname($destinationPath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The private backup directory could not be prepared.');
        }
        $handle = fopen($destinationPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The backup file could not be opened.');
        }
        chmod($destinationPath, 0600);
        $hash = hash_init('sha256');
        $rows = 0;
        try {
            $write = static function (string $text) use ($handle, $hash): void {
                if (fwrite($handle, $text) !== strlen($text)) {
                    throw new RuntimeException('The backup file could not be written completely.');
                }
                hash_update($hash, $text);
            };
            $write(SqlBackupFormat::HEADER);
            $write('-- Snapshot: ' . gmdate('m-d-Y H:i:s') . " UTC\n");
            $write("SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
            foreach ($schema as $table => $definition) {
                $quoted = self::identifier($table);
                $ddl = $this->pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
                $write("DROP TABLE IF EXISTS {$quoted};\n{$ddl[1]};\n");
                $columns = $definition['columns'];
                $columnSql = implode(', ', array_map([self::class, 'identifier'], $columns));
                $statement = $this->pdo->query("SELECT {$columnSql} FROM {$quoted}");
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $values = [];
                    foreach ($columns as $column) {
                        $values[] = $row[$column] === null ? 'NULL' : "X'" . bin2hex((string)$row[$column]) . "'";
                    }
                    $write("INSERT INTO {$quoted} ({$columnSql}) VALUES (" . implode(', ', $values) . ");\n");
                    if (++$rows % 500 === 0) {
                        ($this->heartbeat)();
                    }
                }
                ($this->heartbeat)();
            }
            $write('SET FOREIGN_KEY_CHECKS=1;');
            if (SqlBackupFormat::schema($this->pdo) !== $schema) {
                throw new RuntimeException('The database structure changed during backup. Try again.');
            }
            $trailer = "\n-- SHA256: " . hash_final($hash) . "\n";
            if (fwrite($handle, $trailer) !== strlen($trailer) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('The backup could not be saved completely.');
            }
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $destinationPath);
        return ['path' => $destinationPath, 'size' => (int)filesize($destinationPath), 'tables' => count($schema), 'rows' => $rows];
    }

    public function schemaFingerprint(): string
    {
        return hash('sha256', json_encode(SqlBackupFormat::schema($this->pdo), JSON_THROW_ON_ERROR));
    }

    public static function identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function assertSupportedObjects(): void
    {
        foreach (["SHOW FULL TABLES WHERE Table_type = 'VIEW'", 'SHOW TRIGGERS',
            'SHOW PROCEDURE STATUS WHERE Db = DATABASE()', 'SHOW FUNCTION STATUS WHERE Db = DATABASE()', 'SHOW EVENTS'] as $sql) {
            if ($this->pdo->query($sql)->fetch() !== false) {
                throw new RuntimeException('This SQL backup format supports base tables only; remove custom database programs or use an offline database dump.');
            }
        }
    }
}
