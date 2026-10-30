<?php

namespace App\Backup;

use RuntimeException;

/** Bounded, uncompressed authenticated Store package; never extracts archive paths. */
final class StorePackageFormat
{
    public const VERSION = 'RMSTORE1';
    public const CIPHER = 'aes-256-gcm';
    public const ITERATIONS = 600000;
    public const MAX_BYTES = 48 * 1024 * 1024;
    public const MAX_CONTENT_BYTES = 32 * 1024 * 1024;
    public const MAX_FILES = 2000;
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    private const HEADER = "RMSTORE1\n";

    public static function assertRuntime(): void
    {
        if (PHP_VERSION_ID < 80200 || !extension_loaded('openssl') || !extension_loaded('fileinfo')
            || !in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('Store packages require PHP 8.2+, OpenSSL AES-256-GCM, hash, and fileinfo.');
        }
        $memory = (string)ini_get('memory_limit');
        $bytes = (int)$memory * match (strtolower(substr($memory, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        if ($bytes !== -1 && $bytes < 512 * 1024 * 1024 && ini_set('memory_limit', '512M') === false) {
            throw new RuntimeException('Store packages require a PHP memory limit of at least 512 MB.');
        }
    }

    public static function assertPassphrase(#[\SensitiveParameter] string $passphrase): void
    {
        if (strlen($passphrase) < 16 || strlen($passphrase) > 1024) {
            throw new RuntimeException('Use a separate backup passphrase between 16 and 1024 bytes. Keep an off-server recovery copy.');
        }
    }

    public static function encode(#[\SensitiveParameter] string $sql, array $files, array $schema, #[\SensitiveParameter] string $passphrase, string $origin = '', string $databaseVersion = ''): string
    {
        self::assertRuntime();
        self::assertPassphrase($passphrase);
        SqlBackupFormat::statements($sql, $schema);
        $members = [['path' => 'database.sql', 'size' => strlen($sql), 'content' => base64_encode($sql)]];
        foreach ($files as $path => $content) {
            self::assertImage($path, $content);
            $members[] = ['path' => $path, 'size' => strlen($content), 'content' => base64_encode($content)];
        }
        $manifest = ['format' => self::VERSION, 'sql_format' => SqlBackupFormat::VERSION,
            'schema' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)),
            'package_id' => bin2hex(random_bytes(16)), 'origin_id' => $origin ?: bin2hex(random_bytes(16)),
            'snapshot_at' => gmdate('Y-m-d H:i:s'), 'php' => PHP_VERSION, 'openssl' => OPENSSL_VERSION_TEXT, 'database' => $databaseVersion,
            'members' => $members];
        self::validateManifest($manifest, $schema);
        $plain = json_encode($manifest, JSON_THROW_ON_ERROR);
        if (strlen($plain) > self::MAX_BYTES - 1024) throw new RuntimeException('The Store package exceeds the 48 MB package limit.');
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $prefix = self::HEADER . $salt . $iv;
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        try {
            $ciphertext = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $prefix, 16);
            if ($ciphertext === false) throw new RuntimeException('The Store package could not be encrypted.');
            return $prefix . $tag . $ciphertext;
        } finally { unset($key, $plain); }
    }

    public static function decode(string $package, #[\SensitiveParameter] string $passphrase, array $schema): array
    {
        self::assertRuntime();
        self::assertPassphrase($passphrase);
        $offset = strlen(self::HEADER);
        if (strlen($package) > self::MAX_BYTES || strlen($package) < $offset + 45 || !str_starts_with($package, self::HEADER)) {
            throw new RuntimeException('Select a complete encrypted RetailMind Store package (.rmpkg), no larger than 48 MB.');
        }
        $salt = substr($package, $offset, 16); $iv = substr($package, $offset + 16, 12);
        $prefix = substr($package, 0, $offset + 28);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        try {
            $plain = openssl_decrypt(substr($package, $offset + 44), self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, substr($package, $offset + 28, 16), $prefix);
            if ($plain === false) throw new RuntimeException('The backup passphrase is incorrect, or the package is damaged.');
            $manifest = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) throw new RuntimeException('Invalid Store package manifest.');
            $result = self::validateManifest($manifest, $schema);
            $result['rows'] = SqlBackupFormat::rows($result['sql'], $result['source_schema']);
            self::validateReferences($result['rows'], $result['files']);
            return $result;
        } finally { unset($key, $plain); }
    }

    private static function validateManifest(array $manifest, array $schema): array
    {
        if (($manifest['format'] ?? '') !== self::VERSION || ($manifest['sql_format'] ?? '') !== SqlBackupFormat::VERSION
            || !preg_match('/^[a-f0-9]{64}$/D', (string)($manifest['schema'] ?? ''))
            || !preg_match('/^[a-f0-9]{32}$/D', (string)($manifest['origin_id'] ?? ''))
            || !preg_match('/^[a-f0-9]{32}$/D', (string)($manifest['package_id'] ?? ''))
            || !is_string($manifest['snapshot_at'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $manifest['snapshot_at'])
            || !is_array($manifest['members'] ?? null) || !array_is_list($manifest['members'])
            || count($manifest['members']) > self::MAX_FILES + 1) {
            throw new RuntimeException('The package is incompatible or its manifest is invalid.');
        }
        $seen = []; $files = []; $total = 0; $sql = null;
        foreach ($manifest['members'] as $member) {
            if (!is_array($member) || count($member) !== 3 || !is_string($member['path'] ?? null) || !is_string($member['content'] ?? null)
                || !is_int($member['size'] ?? null) || $member['size'] < 0 || $member['size'] > self::MAX_CONTENT_BYTES
                || isset($seen[$member['path']])) throw new RuntimeException('The package contains duplicate or invalid members.');
            $seen[$member['path']] = true;
            $total += $member['size'];
            if ($total > self::MAX_CONTENT_BYTES || strlen($member['content']) !== 4 * (int)ceil($member['size'] / 3)) {
                throw new RuntimeException('The package exceeds the 32 MB content limit or declares an invalid size.');
            }
            $content = base64_decode($member['content'], true);
            if ($content === false || strlen($content) !== $member['size'] || base64_encode($content) !== $member['content']) {
                throw new RuntimeException('A package member is incomplete.');
            }
            if ($member['path'] === 'database.sql') { $sql = $content; }
            else { self::assertImage($member['path'], $content); $files[$member['path']] = $content; }
        }
        if ($sql === null) throw new RuntimeException('The package is missing its database snapshot.');
        $source = SqlBackupFormat::compatibleSourceSchema($sql, $schema);
        if (!hash_equals($manifest['schema'], hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('The package schema fingerprint does not match its captured SQL.');
        }
        return ['sql' => $sql, 'source_schema' => $source, 'files' => $files, 'bytes' => $total, 'manifest' => array_diff_key($manifest, ['members' => true])];
    }

    public static function assertImage(string $path, string $content): void
    {
        if (!preg_match('~^(?:images|profile-images)/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|gif|webp)$~D', $path)
            || strlen($content) > self::MAX_IMAGE_BYTES || $content === '') throw new RuntimeException('The package contains an unsafe or oversized upload.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        if ($mime !== $allowed[$extension] || @getimagesizefromstring($content) === false) throw new RuntimeException('A package upload is not a supported image.');
    }

    public static function references(array $rows): array
    {
        $references = [];
        foreach ($rows['products'] ?? [] as $row) {
            $value = (string)($row['product_image'] ?? '');
            if ($value === '') continue;
            if (!str_starts_with($value, 'storage/images/')) throw new RuntimeException('A Product has an unsupported image reference.');
            $references[] = substr($value, strlen('storage/'));
        }
        foreach ($rows['users'] ?? [] as $row) {
            $value = (string)($row['profile_image'] ?? '');
            if ($value !== '') $references[] = 'profile-images/' . $value;
        }
        foreach ($references as $path) {
            if (!preg_match('~^(?:images|profile-images)/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|gif|webp)$~D', $path)) throw new RuntimeException('An uploaded image reference is unsafe.');
        }
        return array_values(array_unique($references));
    }

    private static function validateReferences(array $rows, array $files): void
    {
        $references = self::references($rows);
        foreach ($references as $path) if (!array_key_exists($path, $files)) throw new RuntimeException('The package is missing required upload: ' . $path);
        foreach ($files as $path => $_) if (!in_array($path, $references, true)) throw new RuntimeException('The package contains an unreferenced upload.');
    }
}
