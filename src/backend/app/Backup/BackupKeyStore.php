<?php

namespace App\Backup;

use DomainException;
use RuntimeException;
use Throwable;

/** Backup keys stay outside the database, application, and public web folder. */
final class BackupKeyStore
{
    private const KEYRING_MANIFEST = 'backup-keyring.json';
    private const KEYRING_LOCK = 'backup-keyring.lock';
    private const KEY_FILE_PATTERN = '/^backup-key-([a-f0-9]{64})\.key$/D';

    public static function current(): string
    {
        $lock = fopen(RecoveryStore::path('backup-key.lock'), 'c');
        if ($lock === false) throw new RuntimeException('Backup key storage is unavailable.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Backup key storage is busy.');
            $path = RecoveryStore::path('backup-encryption.key');
            if (!is_file($path)) RecoveryStore::write('backup-encryption.key', bin2hex(random_bytes(32)) . "\n");
            return self::validate((string)file_get_contents($path));
        } finally { fclose($lock); }
    }

    public static function currentFingerprint(): string
    {
        return hash('sha256', self::current());
    }

    /** Read the primary key identity without creating one. */
    public static function primaryFingerprint(): ?string
    {
        $path = RecoveryStore::path('backup-encryption.key');
        if (!is_file($path)) return null;
        try {
            return hash('sha256', self::validate((string)file_get_contents($path)));
        } catch (RuntimeException) {
            return null;
        }
    }

    public static function find(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new RuntimeException('Invalid backup key reference.');
        $primary = RecoveryStore::path('backup-encryption.key');
        if (is_file($primary)) {
            $key = self::validate((string)file_get_contents($primary));
            if (hash_equals($id, hash('sha256', $key))) return $key;
        }
        $path = RecoveryStore::path('backup-key-' . $id . '.key');
        if (!is_file($path)) throw new RuntimeException('Import the recovery-key file for this backup under Recovery key, then try again.');
        $key = self::validate((string)file_get_contents($path));
        if (!hash_equals($id, hash('sha256', $key))) throw new RuntimeException('The stored backup key is damaged.');
        return $key;
    }

    public static function hasAvailableKey(): bool
    {
        if (!RecoveryStore::isAvailable()) return false;
        $paths = array_merge(
            [RecoveryStore::path('backup-encryption.key')],
            glob(RecoveryStore::path('backup-key-*.key')) ?: []
        );
        foreach ($paths as $path) {
            if (is_file($path) && preg_match('/^[a-f0-9]{64}$/D', trim((string)file_get_contents($path))) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Import stays additive and immediate: the destination's primary key is
     * never replaced, so existing packages are not stranded.
     *
     * @return array{fingerprint:string,status:'added'|'present'}
     */
    public static function import(#[\SensitiveParameter] string $contents): array
    {
        $key = self::validate($contents);
        $fingerprint = hash('sha256', $key);

        return self::withKeyringLock(static function () use ($key, $fingerprint): array {
            $present = self::primaryFingerprint() === $fingerprint
                || is_file(RecoveryStore::path('backup-key-' . $fingerprint . '.key'));
            if (!$present) {
                RecoveryStore::write('backup-key-' . $fingerprint . '.key', $key . "\n");
            }
            $entries = self::reconcile(self::readManifest());
            if (!isset($entries[$fingerprint])) {
                $now = gmdate('c');
                $entries[$fingerprint] = self::entry($fingerprint, 'imported', $now, $now, null);
            }
            self::writeManifest($entries);
            return ['fingerprint' => $fingerprint, 'status' => $present ? 'present' : 'added'];
        });
    }

    /**
     * Reconcile the metadata registry against the key files. The manifest is a
     * cache: a deleted, stale, or corrupt manifest never loses a key.
     *
     * @return list<array{fingerprint:string,kind:string,created_at:string,imported_at:?string,archived_at:?string,resolvable:bool}>
     */
    public static function list(): array
    {
        $entries = self::reconcile(self::readManifest());
        $rank = ['primary' => 0, 'imported' => 1, 'archived' => 2];
        usort($entries, static function (array $left, array $right) use ($rank): int {
            $order = ($rank[$left['kind']] ?? 3) <=> ($rank[$right['kind']] ?? 3);
            return $order !== 0 ? $order : strcmp((string)$right['created_at'], (string)$left['created_at']);
        });
        return array_values($entries);
    }

    /**
     * Make an imported key the Primary Recovery Key. The previous primary is
     * archived first, so a crash can never strand packages made with it.
     *
     * @return array{primary:string,archived:?string}
     */
    public static function promote(string $fingerprint): array
    {
        self::assertReference($fingerprint);
        return self::withKeyringLock(static function () use ($fingerprint): array {
            if (self::primaryFingerprint() === $fingerprint) {
                return ['primary' => $fingerprint, 'archived' => null];
            }
            $path = RecoveryStore::path('backup-key-' . $fingerprint . '.key');
            if (!is_file($path)) {
                throw new DomainException('That recovery key is not in the Backup Keyring. Import it first.');
            }
            $new = self::validate((string)file_get_contents($path));
            if (!hash_equals($fingerprint, hash('sha256', $new))) {
                throw new RuntimeException('The stored backup key is damaged.');
            }

            $oldFingerprint = self::primaryFingerprint();
            $old = null;
            $primaryPath = RecoveryStore::path('backup-encryption.key');
            if (is_file($primaryPath)) {
                $old = self::validate((string)file_get_contents($primaryPath));
                $oldFingerprint = hash('sha256', $old);
            }
            if ($old !== null && !hash_equals($oldFingerprint, $fingerprint)) {
                RecoveryStore::write('backup-key-' . $oldFingerprint . '.key', $old . "\n");
            }
            RecoveryStore::write('backup-encryption.key', $new . "\n");

            $entries = self::reconcile(self::readManifest());
            $now = gmdate('c');
            $entries[$fingerprint] = array_replace(
                self::entry($fingerprint, 'primary', $now, null, null),
                array_intersect_key($entries[$fingerprint] ?? [], ['created_at' => true, 'imported_at' => true]),
                ['kind' => 'primary', 'archived_at' => null]
            );
            if ($oldFingerprint !== null && $oldFingerprint !== $fingerprint) {
                $entries[$oldFingerprint] = array_replace(
                    self::entry($oldFingerprint, 'archived', $now, null, $now),
                    array_intersect_key($entries[$oldFingerprint] ?? [], ['created_at' => true, 'imported_at' => true]),
                    ['kind' => 'archived', 'archived_at' => $now]
                );
            }
            self::writeManifest($entries);
            return ['primary' => $fingerprint, 'archived' => $oldFingerprint];
        });
    }

    /** Archive a key without removing its material, so its packages stay readable. */
    public static function archive(string $fingerprint): void
    {
        self::assertReference($fingerprint);
        self::withKeyringLock(static function () use ($fingerprint): void {
            if (self::primaryFingerprint() === $fingerprint) {
                throw new DomainException('The Primary Recovery Key cannot be archived. Promote another key first.');
            }
            $path = RecoveryStore::path('backup-key-' . $fingerprint . '.key');
            if (!is_file($path)) {
                throw new DomainException('That recovery key is not in the Backup Keyring.');
            }
            self::verifyMaterial($fingerprint, (string)file_get_contents($path));
            $entries = self::reconcile(self::readManifest());
            $now = gmdate('c');
            $entries[$fingerprint] = array_replace(
                $entries[$fingerprint] ?? self::entry($fingerprint, 'archived', $now, null, $now),
                ['kind' => 'archived', 'archived_at' => $now]
            );
            self::writeManifest($entries);
        });
    }

    /** Delete a non-primary key after proving the file really holds it. */
    public static function delete(string $fingerprint): void
    {
        self::assertReference($fingerprint);
        self::withKeyringLock(static function () use ($fingerprint): void {
            if (self::primaryFingerprint() === $fingerprint) {
                throw new DomainException('The Primary Recovery Key cannot be deleted. Promote another key first.');
            }
            $path = RecoveryStore::path('backup-key-' . $fingerprint . '.key');
            if (!is_file($path)) {
                throw new DomainException('That recovery key is not in the Backup Keyring.');
            }
            self::verifyMaterial($fingerprint, (string)file_get_contents($path));
            if (!unlink($path)) {
                throw new RuntimeException('The recovery key could not be removed.');
            }
            $entries = self::reconcile(self::readManifest());
            unset($entries[$fingerprint]);
            self::writeManifest($entries);
        });
    }

    private static function validate(#[\SensitiveParameter] string $contents): string
    {
        $key = rtrim($contents, "\r\n");
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('Select a valid RetailMind recovery-key file.');
        return $key;
    }

    private static function assertReference(string $fingerprint): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            throw new RuntimeException('Invalid backup key reference.');
        }
    }

    private static function verifyMaterial(string $fingerprint, string $contents): void
    {
        $key = self::validate($contents);
        if (!hash_equals($fingerprint, hash('sha256', $key))) {
            throw new RuntimeException('The stored backup key is damaged.');
        }
    }

    private static function withKeyringLock(callable $operation): mixed
    {
        if (!RecoveryStore::isAvailable()) {
            throw new RuntimeException('Database Backup and Restore are unavailable on this hosting plan.');
        }
        $lock = fopen(RecoveryStore::path(self::KEYRING_LOCK), 'c');
        if ($lock === false) throw new RuntimeException('Backup key storage is unavailable.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Backup key storage is busy.');
            return $operation();
        } finally {
            fclose($lock);
        }
    }

    /** @return array<string,array> fingerprint => metadata, sorted by fingerprint */
    private static function readManifest(): array
    {
        $path = RecoveryStore::path(self::KEYRING_MANIFEST);
        if (!is_file($path)) return [];
        try {
            $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        if (!is_array($data) || !is_array($data['keys'] ?? null)) return [];
        $entries = [];
        foreach ($data['keys'] as $fingerprint => $entry) {
            if (!is_string($fingerprint) || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint) || !is_array($entry)) continue;
            $kind = in_array($entry['kind'] ?? null, ['primary', 'imported', 'archived'], true) ? (string)$entry['kind'] : 'imported';
            $entries[$fingerprint] = self::entry(
                $fingerprint,
                $kind,
                self::timestamp($entry['created_at'] ?? null),
                self::timestamp($entry['imported_at'] ?? null),
                self::timestamp($entry['archived_at'] ?? null)
            );
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }

    /** @param array<string,array> $known */
    private static function reconcile(array $known): array
    {
        $entries = [];
        $primary = self::primaryFingerprint();
        foreach (glob(RecoveryStore::path('backup-key-*.key')) ?: [] as $path) {
            if (is_link($path) || !preg_match(self::KEY_FILE_PATTERN, basename($path), $match)) continue;
            $fingerprint = $match[1];
            if (!is_file($path)) continue;
            try {
                self::verifyMaterial($fingerprint, (string)file_get_contents($path));
            } catch (RuntimeException) {
                continue;
            }
            $entry = $known[$fingerprint] ?? self::fileEntry($fingerprint, $path);
            $entry['fingerprint'] = $fingerprint;
            if ($primary === $fingerprint) {
                $entry['kind'] = 'primary';
                $entry['archived_at'] = null;
            } elseif ($entry['kind'] === 'primary') {
                $entry['kind'] = 'imported';
            }
            $entries[$fingerprint] = $entry;
        }
        if ($primary !== null) {
            $entry = $known[$primary] ?? self::fileEntry($primary, RecoveryStore::path('backup-encryption.key'));
            $entry['fingerprint'] = $primary;
            $entry['kind'] = 'primary';
            $entry['archived_at'] = null;
            $entries[$primary] = $entry;
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }

    private static function fileEntry(string $fingerprint, string $path): array
    {
        $seen = gmdate('c', (int)(@filemtime($path) ?: time()));
        return self::entry($fingerprint, 'imported', $seen, $seen, null);
    }

    private static function entry(string $fingerprint, string $kind, ?string $createdAt, ?string $importedAt, ?string $archivedAt): array
    {
        return [
            'fingerprint' => $fingerprint,
            'kind' => $kind,
            'created_at' => $createdAt ?? gmdate('c'),
            'imported_at' => $importedAt,
            'archived_at' => $archivedAt,
            'resolvable' => true,
        ];
    }

    /** @param array<string,array> $entries */
    private static function writeManifest(array $entries): void
    {
        ksort($entries, SORT_STRING);
        $keys = [];
        foreach ($entries as $fingerprint => $entry) {
            $keys[$fingerprint] = [
                'kind' => (string)$entry['kind'],
                'created_at' => (string)$entry['created_at'],
                'imported_at' => $entry['imported_at'] === null ? null : (string)$entry['imported_at'],
                'archived_at' => $entry['archived_at'] === null ? null : (string)$entry['archived_at'],
            ];
        }
        RecoveryStore::write(
            self::KEYRING_MANIFEST,
            json_encode(['version' => 1, 'keys' => $keys], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
        );
    }

    private static function timestamp(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
