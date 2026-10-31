<?php

namespace App\Backup;

use RuntimeException;

/** Backup keys stay outside the database, application, and public web folder. */
final class BackupKeyStore
{
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

    public static function import(#[\SensitiveParameter] string $contents): void
    {
        $key = self::validate($contents);
        // Retain destination keys: importing another installation's key must not strand its backups.
        RecoveryStore::write('backup-key-' . hash('sha256', $key) . '.key', $key . "\n");
    }

    private static function validate(#[\SensitiveParameter] string $contents): string
    {
        $key = rtrim($contents, "\r\n");
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('Select a valid RetailMind recovery-key file.');
        return $key;
    }
}
