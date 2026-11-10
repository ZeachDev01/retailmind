<?php

namespace App\Backup\Compatibility;

use App\Backup\Compatibility\Adapters\PreBranchRetirementAdapter;
use App\Backup\SqlBackupFormat;
use RuntimeException;

/** Fail-closed classification and the single adapter chain used by restore. */
final class RestoreCompatibilityRegistry
{
    public const CURRENT = 'current';
    public const UPGRADABLE = 'upgradable';
    public const UNSUPPORTED = 'unsupported';

    /** @var list<SchemaAdapter> */
    private array $adapters;

    /**
     * @param list<SchemaAdapter>|null $adapters ordered oldest => newest
     * @param list<string>|null $availableMigrationKeys known migration keys for this installation
     */
    public function __construct(?array $adapters = null, private ?array $availableMigrationKeys = null)
    {
        $this->adapters = $adapters ?? [new PreBranchRetirementAdapter()];
    }

    /**
     * @param array $manifest decoded manifest (migrations/key fingerprint optional)
     * @param array<string,array{ddl:string,columns:list<string>}> $source
     * @param array<string,array{ddl:string,columns:list<string>}> $destination
     * @param list<string> $applied destination migration keys
     */
    public function classify(array $manifest, array $source, array $destination, array $applied): CompatibilityReport
    {
        $packageKeys = array_values(array_unique(array_map('strval', $manifest['migrations'] ?? [])));
        $applied = array_values(array_unique(array_map('strval', $applied)));
        $diff = SqlBackupFormat::structureDiff($source, $destination);
        $base = ['adapter' => null, 'label' => 'Current structure', 'missing_migrations' => [], 'newer_format' => false,
            'migrations' => $packageKeys, 'changes' => $diff];

        if ($this->availableMigrationKeys !== null) {
            $newer = array_values(array_diff($packageKeys, $this->availableMigrationKeys));
            if ($newer !== []) {
                return new CompatibilityReport(array_replace($base, ['status' => self::UNSUPPORTED, 'newer_format' => true,
                    'missing_migrations' => $newer, 'label' => 'Newer RetailMind structure',
                    'message' => 'This backup was created by a newer RetailMind version. Apply Database Updates before restoring. Newer migrations: ' . implode(', ', $newer) . '.']));
            }
            $expected = array_values(array_diff($packageKeys, $applied));
            if ($expected !== []) {
                return new CompatibilityReport(array_replace($base, ['status' => self::UNSUPPORTED, 'missing_migrations' => $expected,
                    'label' => 'Database updates required',
                    'message' => 'This installation is missing database updates the backup expects: ' . implode(', ', $expected) . '. Apply Database Updates, then review the backup again.']));
            }
        }

        if (array_filter($diff, static fn(array $items): bool => $items !== []) === []) {
            return new CompatibilityReport(array_replace($base, ['status' => self::CURRENT,
                'message' => 'This backup matches the current database structure.']));
        }

        // A backup taken before this installation gained tables or columns needs
        // no adapter: the current structure is kept and the new columns restore blank.
        if ($diff['tables_removed'] === [] && $diff['columns_removed'] === []
            && $diff['changed'] === [] && $diff['enums'] === []) {
            return new CompatibilityReport(array_replace($base, ['status' => self::UPGRADABLE,
                'label' => 'Older structure (safe additions)', 'message' => $this->additiveMessage($diff)]));
        }

        $selected = null;
        foreach ($this->adapters as $adapter) {
            if (!$adapter->supports($source, $packageKeys)) continue;
            if ($selected !== null) throw new RuntimeException('The restore compatibility registry is ambiguous; no structure was changed.');
            $selected = $adapter;
        }
        if ($selected === null) {
            return new CompatibilityReport(array_replace($base, ['status' => self::UNSUPPORTED,
                'label' => 'Unsupported structure', 'message' => $this->unsupportedMessage($diff)]));
        }

        $uncovered = $this->uncovered($diff, $selected);
        if ($uncovered !== []) {
            return new CompatibilityReport(array_replace($base, ['status' => self::UNSUPPORTED,
                'label' => 'Unsupported structure',
                'message' => 'The package has structure this installation does not understand: ' . implode(', ', $uncovered) . '. Apply Database Updates or restore it on a newer RetailMind installation.']));
        }

        return new CompatibilityReport(array_replace($base, ['status' => self::UPGRADABLE, 'adapter' => $selected::key(),
            'label' => $selected::label(), 'message' => $this->upgradeMessage($selected, $diff)]));
    }

    public function upgrade(array $rows, CompatibilityReport|array $report, array $source, array $destination): array
    {
        $report = $report instanceof CompatibilityReport ? $report->toArray() : $report;
        if (($report['status'] ?? '') !== self::UPGRADABLE) return $rows;
        if (($report['adapter'] ?? null) === null) return $rows;
        foreach ($this->adapters as $adapter) {
            if ($adapter::key() === ($report['adapter'] ?? null)) return $adapter->upgrade($rows, $source, $destination);
        }
        throw new RuntimeException('The selected restore compatibility adapter is unavailable.');
    }

    /** Source-only tables/columns and opaque DDL must be explained; destination additions are safe. */
    private function uncovered(array $diff, SchemaAdapter $adapter): array
    {
        $declared = $adapter->declaredChanges();
        $uncovered = [];
        foreach (['tables_removed', 'columns_removed'] as $key) {
            foreach ($diff[$key] as $item) {
                if (!in_array($item, $declared[$key] ?? [], true)) $uncovered[] = $item;
            }
        }
        foreach ($diff['changed'] as $table => $reasons) {
            foreach ($reasons as $reason) {
                if (!in_array($reason, $declared['changed'][$table] ?? [], true)) $uncovered[] = $table . ' (' . $reason . ')';
            }
        }
        foreach ($diff['enums'] as $column => $sets) {
            if (($declared['enums'][$column] ?? null) !== $sets) $uncovered[] = $column;
        }
        return $uncovered;
    }

    private function additiveMessage(array $diff): string
    {
        $parts = [];
        if ($diff['tables_added'] !== []) $parts[] = 'new tables ' . implode(', ', $diff['tables_added']);
        if ($diff['columns_added'] !== []) $parts[] = 'new columns ' . implode(', ', $diff['columns_added']);
        return 'This backup is older than this installation and can be restored. The current structure is kept; '
            . implode(' and ', $parts) . ' restore blank.';
    }

    private function unsupportedMessage(array $diff): string
    {
        $details = array_merge($diff['tables_removed'], $diff['columns_removed'], array_keys($diff['changed']), array_keys($diff['enums']));
        $details = array_values(array_unique($details));
        $detail = $details === [] ? '' : ' Structure this installation does not understand: ' . implode(', ', $details) . '.';
        return 'This backup uses a structure with no registered upgrade. Apply Database Updates or restore it on a newer RetailMind installation.' . $detail;
    }

    private function upgradeMessage(SchemaAdapter $adapter, array $diff): string
    {
        $parts = [];
        if ($diff['tables_removed'] !== []) $parts[] = 'retires ' . implode(', ', $diff['tables_removed']);
        if ($diff['columns_removed'] !== []) $parts[] = 'updates ' . implode(', ', $diff['columns_removed']);
        if ($diff['tables_added'] !== []) $parts[] = 'adds ' . implode(', ', $diff['tables_added']);
        if ($diff['columns_added'] !== []) $parts[] = 'fills ' . implode(', ', $diff['columns_added']);
        $changes = $parts === [] ? 'This package is compatible with the current structure.' : ucfirst(implode('; ', $parts)) . '.';
        return $adapter::label() . '. This backup can be upgraded automatically. ' . $changes;
    }
}
