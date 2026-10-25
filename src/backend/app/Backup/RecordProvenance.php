<?php

namespace App\Backup;

require_once __DIR__ . '/../Database/Schema.php';

use PDO;

/** Resolve embedded source identities in the record's installation context. */
final class RecordProvenance
{
    private bool $available;

    public function __construct(private PDO $pdo)
    {
        $this->available = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            && \App\Database\Schema::tableExists($pdo, 'store_record_origins');
    }

    public static function key(array $values): string
    {
        return json_encode(array_map(static fn($value): string => (string)$value, $values), JSON_THROW_ON_ERROR);
    }

    public function actor(string $table, array $key, ?int $sourceActorId = null, ?int $localActorId = null): ?array
    {
        if (!$this->available) return null;
        $statement = $this->pdo->prepare("SELECT person.origin_id,person.source_key,person.local_key,person.original_details
            FROM store_record_origins record_origin JOIN store_record_origins person
                ON person.origin_id=record_origin.origin_id AND person.table_name='users'
            WHERE record_origin.table_name=? AND record_origin.local_key=? AND person." . ($sourceActorId !== null ? 'source_key' : 'local_key') . '=?');
        $statement->execute([$table, self::key($key), (string)($sourceActorId ?? $localActorId)]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) return null;
        $row = $rows[0];
        return ['origin_id' => $row['origin_id'], 'source_id' => (int)$row['source_key'], 'local_id' => (int)$row['local_key'],
            'original' => $row['original_details'] === null ? null : json_decode($row['original_details'], true, 512, JSON_THROW_ON_ERROR)];
    }
}
