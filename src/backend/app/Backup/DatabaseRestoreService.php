<?php

namespace App\Backup;

use App\Authorization\RoleCapabilityPolicy;
use DomainException;
use PDO;
use Throwable;

/** Full replacement, with recovery state that cannot be erased by the import. */
final class DatabaseRestoreService
{
    public function __construct(
        private PDO $pdo,
        private RoleCapabilityPolicy $policy,
        private DatabaseBackupService $backups
    ) {
    }

    public function authorize(string $actorRole): void
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::PLATFORM_GOVERNANCE)) {
            throw new DomainException('Only the Super Administrator may perform a Database Restore.');
        }
    }

    public function supportedUploadBytes(): int
    {
        return min(StorePackageFormat::MAX_BYTES, self::iniBytes((string)ini_get('upload_max_filesize')), self::iniBytes((string)ini_get('post_max_size')));
    }

    public function supportedSizeLine(): string
    {
        return 'Encrypted Store packages (.rmpkg) up to ' . (int)floor($this->supportedUploadBytes() / 1048576)
            . ' MB can be uploaded. Larger backups require the server upload limits to be raised.';
    }

    /** Read-only: successful validation never grants replacement authority. */
    public function preflight(int $actorUserId, string $actorRole, string $sourcePath, string $originalName,
        #[\SensitiveParameter] string $loginPassword, #[\SensitiveParameter] string $backupPassphrase): array
    {
        $this->authorize($actorRole);
        $actor = $this->backups->authorizeActor($actorUserId, $actorRole);
        if ($loginPassword === '' || !password_verify($loginPassword, (string)$actor['password_hash'])) {
            throw new DomainException('Your current destination login password is incorrect.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'rmpkg' || !is_file($sourcePath)
            || filesize($sourcePath) > $this->supportedUploadBytes()) {
            throw new DomainException('Select an encrypted Store package (.rmpkg) within the displayed upload limit.');
        }
        $schema = SqlBackupFormat::schema($this->pdo);
        try {
            $package = StorePackageFormat::decode((string)file_get_contents($sourcePath), $backupPassphrase, $schema);
        } catch (\RuntimeException $exception) {
            throw new DomainException($exception->getMessage(), 0, $exception);
        }
        $this->validateReferences($package['rows']);
        $conflicts = [];
        foreach (['user_id' => 'id', 'username' => 'username', 'email' => 'email'] as $column => $kind) {
            if (!in_array($column, $schema['users']['columns'] ?? [], true)) continue;
            $lookup = $this->pdo->prepare('SELECT user_id FROM users WHERE ' . DatabaseSnapshotWriter::identifier($column) . '=?');
            foreach ($package['rows']['users'] ?? [] as $source) {
                if (!isset($source[$column]) || trim((string)$source[$column]) === '') continue;
                $lookup->execute([$source[$column]]);
                foreach ($lookup->fetchAll(PDO::FETCH_COLUMN) as $targetId) {
                    $conflicts[] = ['kind' => $kind, 'source_id' => (int)$source['user_id'], 'destination_id' => (int)$targetId,
                        'message' => 'Source user ' . (int)$source['user_id'] . ' conflicts with destination user ' . (int)$targetId
                            . ' by ' . $kind . '. Super Administrator identity preparation is required before replacement.'];
                }
            }
        }
        return ['valid' => $conflicts === [], 'message' => $conflicts ? 'Resolve the listed identity conflicts before retrying.' : 'Package validated. No records or uploads were replaced.',
            'conflicts' => $conflicts, 'files' => count($package['files']), 'bytes' => $package['bytes'],
            'snapshot_at' => $package['manifest']['snapshot_at'], 'replacement_authorized' => false];
    }

    /** Validate actual declared keys; no source SQL is executed against the destination. */
    public function validateReferences(array $rows): void
    {
        $oldMode = (string)$this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->pdo->exec('SET SESSION sql_mode=' . $this->pdo->quote($oldMode . ',STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO'));
        try {
            foreach ($rows as $table => $records) {
                $temporary = DatabaseSnapshotWriter::identifier('rm_validate_' . bin2hex(random_bytes(12)));
                $quoted = DatabaseSnapshotWriter::identifier($table);
                $this->pdo->exec("CREATE TEMPORARY TABLE {$temporary} LIKE {$quoted}");
                try {
                    if (!$records) continue;
                    $columns = array_keys($records[0]);
                    $columnSql = implode(', ', array_map([DatabaseSnapshotWriter::class, 'identifier'], $columns));
                    $insert = $this->pdo->prepare("INSERT INTO {$temporary} ({$columnSql}) VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ')');
                    foreach ($records as $record) $insert->execute(array_values($record));
                    // Compare references using MySQL's normalized values, including numeric columns.
                    $rows[$table] = $this->pdo->query("SELECT {$columnSql} FROM {$temporary}")->fetchAll(PDO::FETCH_ASSOC);
                } catch (\PDOException $exception) {
                    throw new DomainException('The package contains incompatible values or duplicate keys in ' . $table . '.', 0, $exception);
                } finally { $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$temporary}"); }
            }
        } finally { $this->pdo->exec('SET SESSION sql_mode=' . $this->pdo->quote($oldMode)); }
        $keys = $this->pdo->query("SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $constraints = [];
        foreach ($keys as $key) $constraints[$key['TABLE_NAME'] . '.' . $key['CONSTRAINT_NAME']][] = $key;
        foreach ($constraints as $parts) {
            $targets = [];
            foreach ($rows[$parts[0]['REFERENCED_TABLE_NAME']] ?? [] as $row) {
                $targets[serialize(array_map(static fn(array $part) => $row[$part['REFERENCED_COLUMN_NAME']], $parts))] = true;
            }
            foreach ($rows[$parts[0]['TABLE_NAME']] ?? [] as $row) {
                $values = array_map(static fn(array $part) => $row[$part['COLUMN_NAME']], $parts);
                if (!in_array(null, $values, true) && !isset($targets[serialize($values)])) {
                    throw new DomainException('The package contains an invalid reference in ' . $parts[0]['TABLE_NAME'] . '.');
                }
            }
        }
        // These historical actor columns deliberately have no database foreign key.
        $users = array_fill_keys(array_map(static fn(array $user): string => (string)$user['user_id'], $rows['users'] ?? []), true);
        foreach (['branches' => ['created_by'], 'sales' => ['discount_authorized_by']] as $table => $columns) {
            foreach ($rows[$table] ?? [] as $row) foreach ($columns as $column) {
                if (isset($row[$column]) && !isset($users[(string)$row[$column]])) {
                    throw new DomainException('The package contains an unresolved historical actor in ' . $table . '.' . $column . '.');
                }
            }
        }
    }

    public function restore(int $actorUserId, string $actorRole, string $sourcePath, string $originalName, #[\SensitiveParameter] string $password): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new DomainException('Encrypted replacement is not enabled yet. Use read-only package validation.');
        }
        return $this->replace($actorUserId, $actorRole, $sourcePath, $originalName, $password, false);
    }

    /** Recovery remains usable even if the users table was only partly imported. */
    public function recover(string $sourcePath, #[\SensitiveParameter] string $password): array
    {
        if (PHP_SAPI !== 'cli' || !RecoveryStore::isPaused()) {
            throw new DomainException('Offline recovery is available only for an incomplete restore.');
        }
        $state = RecoveryStore::state();
        return $this->replace((int)$state['actor_id'], 'super_admin', $sourcePath, basename($sourcePath), $password, true);
    }

    private function replace(int $actorUserId, string $actorRole, string $sourcePath, string $originalName, string $password, bool $recovery): array
    {
        $this->authorize($actorRole);
        $lock = RecoveryStore::exclusive();
        $destructive = false;
        $pausedHere = false;
        $executed = 0;
        $oldSqlMode = null;
        try {
            $state = RecoveryStore::state();
            if ($state && !$recovery) {
                throw new DomainException('Finish the incomplete restore with the offline recovery command first.');
            }
            if ($recovery) {
                $hash = (string)($state['password_hash'] ?? '');
                $schema = $state['schema'] ?? [];
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT u.password_hash, u.status, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?"
                );
                $stmt->execute([$actorUserId]);
                $actor = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$actor || $actor['status'] !== 'active' || !$this->policy->allows((string)$actor['role_name'], RoleCapabilityPolicy::PLATFORM_GOVERNANCE)) {
                    throw new DomainException('An active Super Administrator account is required.');
                }
                $hash = (string)$actor['password_hash'];
                $schema = [];
            }
            if ($password === '' || !password_verify($password, $hash)) {
                throw new DomainException('The Super Administrator password is incorrect.');
            }
            if ($recovery && empty($state['replacement_started'])) {
                RecoveryStore::log($actorUserId, $originalName, 'cancelled_before_replacement');
                RecoveryStore::resume();
                return ['statements' => 0, 'message' => 'The interrupted attempt had not replaced any tables. Store access has resumed.'];
            }
            if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'sql') {
                throw new DomainException('Select a RetailMind SQL backup (.sql). Encrypted backups are no longer accepted.');
            }
            if (!$recovery) {
                $schema = SqlBackupFormat::schema($this->pdo);
            }
            $sql = file_get_contents($sourcePath);
            if ($sql === false) {
                throw new DomainException('The backup could not be read.');
            }
            $statements = SqlBackupFormat::statements($sql, $schema);
            unset($sql);
            RecoveryStore::log($actorUserId, $originalName, $recovery ? 'recovery_attempt' : 'attempt');
            if (!$recovery) {
                // Fail closed on crashes, including a crash while capturing safety.
                RecoveryStore::write('restore-state.json', json_encode([
                    'actor_id' => $actorUserId, 'password_hash' => $hash, 'schema' => $schema, 'replacement_started' => false,
                ], JSON_THROW_ON_ERROR));
                $pausedHere = true;
                $this->captureSafety($actorUserId);
            }
            $oldSqlMode = (string)$this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            RecoveryStore::write('session-epoch', bin2hex(random_bytes(32)));
            // This record is persisted BEFORE DDL. A crash now leaves the Store
            // blocked and the pre-restore credentials available to offline recovery.
            RecoveryStore::log($actorUserId, $originalName, 'replacement_started');
            RecoveryStore::write('restore-state.json', json_encode([
                'actor_id' => $actorUserId, 'password_hash' => $hash, 'schema' => $schema, 'replacement_started' => true,
            ], JSON_THROW_ON_ERROR));
            $destructive = true;
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $this->pdo->exec("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
            foreach ($statements as $statement) {
                $this->pdo->exec($statement);
                $executed++;
            }
            // These are transient coordination records, never restored jobs or
            // download permissions. The external restore log replaces DB history.
            $this->pdo->exec('DELETE FROM backup_operations');
            $this->pdo->exec("INSERT IGNORE INTO store_write_gate (gate_key) VALUES ('store_writes')");
            $this->pdo->exec('UPDATE store_write_gate SET paused_at = NULL, paused_by = NULL');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            RecoveryStore::log($actorUserId, $originalName, 'completed', $executed);
            RecoveryStore::resume();
            return ['statements' => $executed, 'message' => 'Restore completed. Everyone must sign in again using credentials from the restored backup.'];
        } catch (Throwable $exception) {
            error_log('Database restore: ' . $exception->getMessage());
            try {
                RecoveryStore::log($actorUserId, $originalName, $destructive ? 'incomplete' : 'rejected', $executed);
            } catch (Throwable $logFailure) {
                error_log('Restore log failure: ' . $logFailure->getMessage());
            }
            if (!$destructive && $pausedHere && !$recovery) {
                RecoveryStore::resume();
            }
            throw $exception;
        } finally {
            try {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                if ($oldSqlMode !== null) {
                    $this->pdo->exec('SET SQL_MODE=' . $this->pdo->quote($oldSqlMode));
                }
            } catch (Throwable $cleanupFailure) {
                error_log('Restore connection cleanup: ' . $cleanupFailure->getMessage());
            }
            fclose($lock);
        }
    }

    private function captureSafety(int $actorId): void
    {
        $coordinator = $this->backups->coordinator();
        $temporary = RecoveryStore::path('safety.capture.tmp');
        try {
            $coordinator->beginCapture($actorId);
            (new DatabaseSnapshotWriter($this->pdo))->write($temporary);
            $coordinator->releaseCapture();
            // Verify the entire safety copy before publishing it or touching data.
            SqlBackupFormat::statements((string)file_get_contents($temporary), SqlBackupFormat::schema($this->pdo));
            if (!rename($temporary, RecoveryStore::path('latest-safety.sql'))) {
                throw new DomainException('The safety backup could not be retained. Nothing was replaced.');
            }
        } catch (Throwable $exception) {
            $coordinator->abortCapture();
            throw $exception;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function iniBytes(string $value): int
    {
        $number = (int)trim($value);
        if ($number <= 0) {
            return PHP_INT_MAX;
        }
        return $number * match (strtolower(substr(trim($value), -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1,
        };
    }
}
