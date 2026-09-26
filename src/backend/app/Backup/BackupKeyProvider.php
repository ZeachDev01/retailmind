<?php

namespace App\Backup;

use RuntimeException;

/**
 * Server-held recovery key for encrypted Database Backups (#69).
 *
 * The key is strong random material configured outside the Store database and
 * outside source control (BACKUP_ENCRYPTION_KEY in the server .env), stored as
 * 64 hexadecimal characters or standard base64 of 32 bytes. It is
 * deliberately independent of every login password, so rotating or resetting a
 * staff password never invalidates an existing backup.
 *
 * Missing or unusable configuration fails closed: there is no plaintext
 * fallback, and the key is never logged, returned in a response, stored in the
 * exported database, or bundled with a backup file.
 */
final class BackupKeyProvider
{
    public const ENV_KEY = 'BACKUP_ENCRYPTION_KEY';
    public const MIN_BYTES = 32;

    private ?string $resolved = null;
    private bool $attempted = false;

    public function __construct(private $reader = null)
    {
    }

    public function isConfigured(): bool
    {
        return $this->tryKey() !== null;
    }

    /**
     * The 32 raw key bytes. Throws when the deployment has no usable key so a
     * backup fails closed instead of producing a readable copy.
     */
    public function key(): string
    {
        $key = $this->tryKey();
        if ($key === null) {
            throw new RuntimeException(
                'Encrypted Database Backup is not configured. Set ' . self::ENV_KEY
                . ' in the server environment to 32 random bytes before creating a backup.'
            );
        }
        return $key;
    }

    /**
     * Safe for operator-facing diagnostics: reports only whether key material
     * is present, never any part of the key.
     */
    public function statusLine(): string
    {
        return $this->isConfigured()
            ? 'Recovery key configured (held outside downloaded backup files).'
            : 'Recovery key is not configured. Encrypted backups cannot be created until '
                . self::ENV_KEY . ' is set on the server.';
    }

    private function tryKey(): ?string
    {
        if ($this->attempted) {
            return $this->resolved;
        }
        $this->attempted = true;

        $raw = trim((string)$this->read(self::ENV_KEY));
        if ($raw === '') {
            return $this->resolved = null;
        }

        $key = null;
        if (preg_match('/^[0-9a-fA-F]{64,128}$/', $raw) === 1) {
            $key = hex2bin(strlen($raw) > 64 ? substr($raw, 0, 64) : $raw);
        } elseif (preg_match('#^[A-Za-z0-9+/=_-]+$#', $raw) === 1) {
            $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
            if ($decoded !== false) {
                $key = $decoded;
            }
        }

        if (!is_string($key) || strlen($key) < self::MIN_BYTES) {
            error_log(self::ENV_KEY . ' is present but unusable; encrypted Database Backup is disabled.');
            return $this->resolved = null;
        }

        return $this->resolved = substr($key, 0, self::MIN_BYTES);
    }

    private function read(string $name): mixed
    {
        if (is_callable($this->reader)) {
            return ($this->reader)($name);
        }
        if (function_exists('env')) {
            return \App\Core\Environment::get($name);
        }
        return null;
    }
}
