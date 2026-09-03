<?php

namespace App\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private PDO $pdo,
        private string $migrationPath
    ) {
    }

    public function ensureRepository(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                migration_key VARCHAR(100) PRIMARY KEY,
                description VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    public function available(): array
    {
        $files = glob(rtrim($this->migrationPath, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];
        foreach ($files as $file) {
            $migration = require $file;
            if (!is_array($migration) || !isset($migration['key'], $migration['description'], $migration['up']) || !is_callable($migration['up'])) {
                throw new RuntimeException('Invalid migration file: ' . basename($file));
            }
            $migration['file'] = $file;
            $migrations[] = $migration;
        }
        return $migrations;
    }

    public function appliedKeys(): array
    {
        $this->ensureRepository();
        return array_map('strval', $this->pdo->query('SELECT migration_key FROM schema_migrations ORDER BY migration_key')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function pending(): array
    {
        $applied = array_flip($this->appliedKeys());
        return array_values(array_filter($this->available(), static fn(array $migration): bool => !isset($applied[$migration['key']])));
    }

    public function runPending(callable $output = null): int
    {
        $this->ensureRepository();
        $count = 0;
        foreach ($this->pending() as $migration) {
            $output && $output("Applying {$migration['key']}: {$migration['description']}");
            ($migration['up'])($this->pdo);
            $stmt = $this->pdo->prepare(
                'INSERT INTO schema_migrations (migration_key, description) VALUES (?, ?)'
            );
            $stmt->execute([$migration['key'], $migration['description']]);
            $count++;
        }
        return $count;
    }
}
