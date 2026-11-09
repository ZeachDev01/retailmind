<?php

namespace App\Backup;

use App\Authorization\RoleCapabilityPolicy;
use App\Database\Schema;
use PDO;
use Throwable;

/**
 * Recommends saving the recovery key that encrypted the newest completed
 * backup, and records a per-account acknowledgement after a successful export.
 */
final class BackupKeyRecommendation
{
    private const ACKNOWLEDGEMENTS = 'user_backup_key_acknowledgements';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{fingerprint:string,short:string,snapshot_at:?string,filename:string}|null
     */
    public function recommendation(int $userId, string $role): ?array
    {
        if (!(new RoleCapabilityPolicy())->allows($role, RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP)) {
            return null;
        }
        if (!Schema::tableExists($this->pdo, 'backup_history')
            || !Schema::columnExists($this->pdo, 'backup_history', 'key_fingerprint')) {
            return null;
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT key_fingerprint, filename, snapshot_at, created_at
                   FROM backup_history
                  WHERE status = 'completed' AND key_fingerprint IS NOT NULL AND key_fingerprint <> ''
                  ORDER BY COALESCE(snapshot_at, created_at) DESC, backup_id DESC
                  LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($row) || $row === []) {
            return null;
        }
        $fingerprint = (string)($row['key_fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || $this->acknowledged($userId, $fingerprint)) {
            return null;
        }
        return [
            'fingerprint' => $fingerprint,
            'short' => substr($fingerprint, 0, 8),
            'snapshot_at' => $row['snapshot_at'] === null ? null : (string)$row['snapshot_at'],
            'filename' => (string)($row['filename'] ?? ''),
        ];
    }

    /** Acknowledgement is idempotent and must never break the download it follows. */
    public function acknowledge(int $userId, string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return;
        }
        try {
            if (!Schema::tableExists($this->pdo, self::ACKNOWLEDGEMENTS)) {
                return;
            }
            $statement = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'INSERT OR IGNORE INTO ' . self::ACKNOWLEDGEMENTS . ' (user_id, key_fingerprint, acknowledged_at) VALUES (?, ?, ?)'
                : 'INSERT IGNORE INTO ' . self::ACKNOWLEDGEMENTS . ' (user_id, key_fingerprint, acknowledged_at) VALUES (?, ?, ?)';
            $this->pdo->prepare($statement)->execute([$userId, $fingerprint, gmdate('Y-m-d H:i:s')]);
        } catch (Throwable) {
            // The download already succeeded; a lost acknowledgement only re-prompts.
        }
    }

    private function acknowledged(int $userId, string $fingerprint): bool
    {
        try {
            if (!Schema::tableExists($this->pdo, self::ACKNOWLEDGEMENTS)) {
                return false;
            }
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::ACKNOWLEDGEMENTS . ' WHERE user_id = ? AND key_fingerprint = ? LIMIT 1');
            $stmt->execute([$userId, $fingerprint]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
