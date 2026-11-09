<?php

namespace App\Database;

use PDO;

/** Destination migration history that must survive a full table replacement. */
final class MigrationLedger
{
    public function __construct(private PDO $pdo, private string $migrationPath) {}

    public function keys(): array
    {
        if (!Schema::tableExists($this->pdo, 'schema_migrations')) return [];
        return (new MigrationRunner($this->pdo, $this->migrationPath))->appliedKeys();
    }

    /** @return list<array{migration_key:string,description:string,applied_at:?string}> */
    public function snapshot(): array
    {
        if (!Schema::tableExists($this->pdo, 'schema_migrations')) return [];
        return $this->pdo->query('SELECT migration_key, description, applied_at FROM schema_migrations ORDER BY migration_key')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Replace the live ledger with the destination snapshot; never trust package rows. */
    public function reinstate(array $rows): void
    {
        if ($rows === []) return;
        (new MigrationRunner($this->pdo, $this->migrationPath))->ensureRepository();
        $this->pdo->exec('DELETE FROM schema_migrations');
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO schema_migrations (migration_key, description, applied_at) VALUES (?, ?, ?)'
        );
        foreach ($rows as $row) {
            $insert->execute([$row['migration_key'], $row['description'], $row['applied_at']]);
        }
    }
}
