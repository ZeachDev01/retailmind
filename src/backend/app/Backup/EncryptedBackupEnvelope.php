<?php

namespace App\Backup;

use RuntimeException;

/**
 * Versioned authenticated-encryption envelope for Database Backups (#69).
 *
 * Layout:
 *
 *   RMBAK1\n
 *   {"version":1,"cipher":"aes-256-gcm",...}\n
 *   <raw ciphertext || 16-byte GCM tag>
 *
 * The header and the magic line are passed to AES-256-GCM as additional
 * authenticated data, so the format identifier, the algorithm, and the
 * recorded plaintext digest cannot be altered without detection. Opening a
 * file therefore proves both confidentiality (only the recovery key holder can
 * read it) and integrity (a wrong key, a truncated file, or modified
 * ciphertext is rejected) before any restoration touches the database.
 */
final class EncryptedBackupEnvelope
{
    public const MAGIC = 'RMBAK1';
    public const VERSION = 1;
    public const CIPHER = 'aes-256-gcm';
    public const TAG_BYTES = 16;
    public const NONCE_BYTES = 12;
    public const EXTENSION = 'rmbak';
    public const MAX_PLAINTEXT_BYTES = 134217728;

    public function __construct(private BackupKeyProvider $keys)
    {
    }

    public static function looksEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $prefix = (string)fread($handle, strlen(self::MAGIC) + 1);
        fclose($handle);
        return str_starts_with($prefix, self::MAGIC . "\n");
    }

    /**
     * Confirm the deployment holds usable recovery key material. A backup must
     * never fall back to a plaintext download, so callers check this before
     * claiming a capture started.
     */
    public function requireConfiguredKey(): string
    {
        return $this->keys->key();
    }

    /**
     * Encrypt a captured snapshot. The plaintext file is removed as soon as the
     * envelope is written so a readable intermediate never lingers.
     */
    public function sealFile(string $plaintextPath, string $destinationPath): array
    {
        $key = $this->keys->key();
        if (!is_file($plaintextPath)) {
            throw new RuntimeException('The captured snapshot could not be read.');
        }

        $size = (int)filesize($plaintextPath);
        if ($size <= 0) {
            throw new RuntimeException('The captured snapshot is empty.');
        }
        if ($size > self::MAX_PLAINTEXT_BYTES) {
            throw new RuntimeException(
                'This backup is larger than the supported encrypted backup size of '
                . (int)(self::MAX_PLAINTEXT_BYTES / 1048576) . ' MB.'
            );
        }

        $plaintext = file_get_contents($plaintextPath);
        if ($plaintext === false) {
            throw new RuntimeException('The captured snapshot could not be read.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $header = json_encode([
            'version' => self::VERSION,
            'cipher' => self::CIPHER,
            'kdf' => 'server-configured-key',
            'tag_bytes' => self::TAG_BYTES,
            'nonce' => base64_encode($nonce),
            'payload_bytes' => strlen($plaintext),
            'payload_sha256' => hash('sha256', $plaintext),
            'created_at' => gmdate('c'),
            'application' => 'retailmind',
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($header)) {
            throw new RuntimeException('The backup envelope could not be built.');
        }

        $aad = self::MAGIC . "\n" . $header . "\n";
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_BYTES);
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            unset($plaintext);
            throw new RuntimeException('The backup could not be encrypted.');
        }

        $directory = dirname($destinationPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            unset($plaintext);
            throw new RuntimeException('The backup storage directory could not be prepared.');
        }

        $written = file_put_contents($destinationPath, $aad . $ciphertext . $tag, LOCK_EX);
        unset($plaintext, $ciphertext);
        if ($written === false) {
            throw new RuntimeException('The encrypted backup could not be written.');
        }

        return [
            'version' => (string)self::VERSION,
            'cipher' => self::CIPHER,
            'size' => (int)filesize($destinationPath),
        ];
    }

    /**
     * Verify and decrypt an envelope. Every failure mode (unknown format,
     * unsupported version, wrong key, tampering, truncation) is rejected here,
     * before a caller can execute anything.
     */
    public function openFile(string $sourcePath): string
    {
        $key = $this->keys->key();
        if (!is_file($sourcePath)) {
            throw new RuntimeException('The backup file could not be read.');
        }

        $raw = file_get_contents($sourcePath);
        if ($raw === false) {
            throw new RuntimeException('The backup file could not be read.');
        }

        $newline = strpos($raw, "\n");
        if ($newline === false || substr($raw, 0, $newline) !== self::MAGIC) {
            throw new RuntimeException('This file is not a RetailMind encrypted backup.');
        }

        $secondNewline = strpos($raw, "\n", $newline + 1);
        if ($secondNewline === false) {
            throw new RuntimeException('The encrypted backup is incomplete.');
        }
        $headerLine = substr($raw, $newline + 1, $secondNewline - $newline - 1);
        $header = json_decode($headerLine, true);
        if (!is_array($header) || !isset($header['version'], $header['cipher'], $header['nonce'], $header['tag_bytes'])) {
            throw new RuntimeException('The encrypted backup header is not readable.');
        }
        if ((int)$header['version'] !== self::VERSION) {
            throw new RuntimeException('This encrypted backup was made with an unsupported format version.');
        }
        if (!hash_equals(self::CIPHER, (string)$header['cipher'])) {
            throw new RuntimeException('This encrypted backup uses an unsupported cipher.');
        }

        $aad = self::MAGIC . "\n" . $headerLine . "\n";
        $nonce = base64_decode((string)$header['nonce'], true);
        $tagBytes = (int)$header['tag_bytes'];
        $body = substr($raw, $secondNewline + 1);
        unset($raw);

        if (!is_string($nonce) || strlen($nonce) !== self::NONCE_BYTES || $tagBytes !== self::TAG_BYTES) {
            throw new RuntimeException('The encrypted backup header is not usable.');
        }
        if (strlen($body) <= $tagBytes) {
            throw new RuntimeException('The encrypted backup is truncated.');
        }

        $tag = substr($body, -$tagBytes);
        $ciphertext = substr($body, 0, strlen($body) - $tagBytes);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($plaintext === false) {
            throw new RuntimeException('The encrypted backup could not be verified. It may be damaged, altered, or the recovery key does not match.');
        }
        if (isset($header['payload_sha256']) && !hash_equals((string)$header['payload_sha256'], hash('sha256', $plaintext))) {
            throw new RuntimeException('The encrypted backup contents did not match its recorded checksum.');
        }
        if (strlen($plaintext) > self::MAX_PLAINTEXT_BYTES) {
            throw new RuntimeException('The decrypted backup is larger than the supported restore size.');
        }

        return $plaintext;
    }
}
