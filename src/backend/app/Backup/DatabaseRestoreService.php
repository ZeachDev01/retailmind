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
        private DatabaseBackupService $backups,
        private ?string $uploadDirectory = null
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
    public function readPackage(int $actorUserId, string $actorRole, string $sourcePath, string $originalName,
        #[\SensitiveParameter] string $loginPassword, #[\SensitiveParameter] string $backupPassphrase): array
    {
        $this->authorize($actorRole);
        return $this->readAuthorizedPackage($actorUserId, $actorRole, $sourcePath, $originalName, $loginPassword, $backupPassphrase);
    }

    private function readAuthorizedPackage(int $actorUserId, string $actorRole, string $sourcePath, string $originalName,
        #[\SensitiveParameter] string $loginPassword, #[\SensitiveParameter] string $backupPassphrase): array
    {
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
        return $package;
    }

    public function preflight(int $actorUserId, string $actorRole, string $sourcePath, string $originalName,
        #[\SensitiveParameter] string $loginPassword, #[\SensitiveParameter] string $backupPassphrase): array
    {
        $package = $this->readPackage($actorUserId, $actorRole, $sourcePath, $originalName, $loginPassword, $backupPassphrase);
        $schema = SqlBackupFormat::schema($this->pdo);
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

    public function startIncoming(int $actorUserId, string $actorRole, string $sourcePath, string $originalName,
        #[\SensitiveParameter] string $loginPassword, #[\SensitiveParameter] string $incomingPassphrase,
        #[\SensitiveParameter] string $safetyPassphrase): array
    {
        $this->backups->authorizeActor($actorUserId, $actorRole);
        $lock = RecoveryStore::exclusive();
        try {
            if (RecoveryStore::isPaused()) throw new DomainException('Finish the active recovery operation first.');
            $package = $this->readAuthorizedPackage($actorUserId, $actorRole, $sourcePath, $originalName, $loginPassword, $incomingPassphrase);
            $reconciliation = new IdentityReconciliationService($this->pdo, $this->policy);
            $reconciliation->recheck($actorUserId, $actorRole, $loginPassword, $package);
            $approval = $reconciliation->latestApproval();
            $safety = new SafetyCopyService($this->pdo, $this->policy, null, $this->uploadDirectory);
            $metadata = $safety->create(bin2hex(random_bytes(16)), $actorUserId, $actorRole, $loginPassword, $safetyPassphrase, $lock);
            $reconciliation->recheck($actorUserId, $actorRole, $loginPassword, $package, $approval);
            RecoveryAuthService::preparePreRestoreState($this->pdo, bin2hex(random_bytes(16)), $actorUserId,
                $actorRole, $loginPassword, $approval, $metadata);
        } finally {
            fclose($lock);
        }
        return $this->restartIncoming($actorUserId, $actorRole, $sourcePath, $incomingPassphrase, $safetyPassphrase);
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

    public function recoverSafety(
        int $actorId,
        string $actorRole,
        string $safetyOperationId,
        #[\SensitiveParameter] string $safetyPassphrase
    ): array {
        $this->assertRecoveryOperator($actorId, $actorRole);
        $lock = RecoveryStore::exclusive();
        try {
            $safetyService = new SafetyCopyService($this->pdo, $this->policy, null, $this->uploadDirectory);
            $verified = $safetyService->verify($safetyOperationId, $safetyPassphrase);
            $metadata = $verified['metadata'];
            $package = $verified['package'];
            $schema = $metadata['schema'];

            RecoveryStore::transitionStage(RecoveryStore::STAGE_DESTRUCTIVE_START);
            RecoveryStore::transitionStage(RecoveryStore::STAGE_DATABASE_REPLACEMENT);

            $executed = $this->replaceTablesFromSql($package['sql'], $schema);

            RecoveryStore::transitionStage(RecoveryStore::STAGE_UPLOADS);
            $uploaded = $this->restoreUploads($package['files']);

            RecoveryStore::transitionStage(RecoveryStore::STAGE_AUTOMATED_VERIFICATION);
            $this->verifyPostRestoreIntegrity($schema, $package['files']);
            $checks = $this->runVerificationChecks($package['files']);

            RecoveryStore::transitionStage(RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING, [
                'mode' => 'safety_recovery',
                'safety_operation_id' => $safetyOperationId,
                'statements' => $executed,
                'files' => $uploaded,
            ]);
            $this->recordReviewChecks($checks['checks']);
            RecoveryStore::log($actorId, 'safety-' . $safetyOperationId, 'recovered_safety', $executed);

            return [
                'success' => true,
                'mode' => 'safety_recovery',
                'statements' => $executed,
                'files' => $uploaded,
                'stage' => RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING,
                'message' => 'Safety recovery completed. Automated verification passed. Pending operator review.',
            ];
        } catch (Throwable $e) {
            try {
                RecoveryStore::transitionStage(RecoveryStore::STAGE_RECOVERY_REQUIRED, ['error' => $e->getMessage()]);
                RecoveryStore::log($actorId, 'safety-' . $safetyOperationId, 'safety_recovery_failed: ' . $e->getMessage());
            } catch (Throwable) {}
            throw $e;
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
    }

    public function restartIncoming(
        int $actorId,
        string $actorRole,
        string $packagePath,
        #[\SensitiveParameter] string $incomingPassphrase,
        #[\SensitiveParameter] string $safetyPassphrase
    ): array {
        $this->assertRecoveryOperator($actorId, $actorRole);
        $lock = RecoveryStore::exclusive();
        try {
            if (!is_file($packagePath) || filesize($packagePath) > StorePackageFormat::MAX_BYTES) {
                throw new DomainException('Select a valid encrypted Store package (.rmpkg), no larger than 48 MB.');
            }
            $state = RecoveryStore::state();
            $safetyOpId = (string)($state['safety_operation_id'] ?? '');
            if ($safetyOpId === '') {
                throw new DomainException('No safety operation bound to active recovery.');
            }

            $reconciliation = new IdentityReconciliationService($this->pdo, $this->policy);
            $approval = $reconciliation->latestApproval();
            if ($approval === null) {
                throw new DomainException('No approved reconciliation preparation was found. Reconciliation approval is required.');
            }
            if (($state['package_id'] ?? '') !== $approval['package_id']
                || ($state['plan_id'] ?? '') !== ($approval['approval_id'] ?? $approval['approval_hash'] ?? hash('sha256', json_encode($approval['decisions'] ?? [])))) {
                throw new DomainException('The reconciliation approval does not belong to this recovery operation.');
            }

            $safetyService = new SafetyCopyService($this->pdo, $this->policy, null, $this->uploadDirectory);
            $verifiedSafety = $safetyService->verify($safetyOpId, $safetyPassphrase);
            $safetyCheckpoint = $verifiedSafety['package'];
            $schema = $verifiedSafety['metadata']['schema'];

            $pkgBytes = file_get_contents($packagePath);
            if ($pkgBytes === false) {
                throw new DomainException('The incoming package could not be read.');
            }
            $incomingPackage = StorePackageFormat::decode($pkgBytes, $incomingPassphrase, $schema);

            if (($incomingPackage['manifest']['package_id'] ?? '') !== $approval['package_id']
                || ($incomingPackage['manifest']['origin_id'] ?? '') !== $approval['origin_id']
                || ($incomingPackage['manifest']['schema'] ?? '') !== $approval['schema_hash']
                || (isset($approval['sql_sha256']) && hash('sha256', (string)$incomingPackage['sql']) !== $approval['sql_sha256'])) {
                throw new DomainException('The incoming package does not match the approved reconciliation plan.');
            }

            $projected = $reconciliation->replay($incomingPackage, $safetyCheckpoint, $approval);
            if (!$projected['ready'] || !empty($projected['errors'])) {
                throw new DomainException('Reconciliation replay failed against destination checkpoint: ' . implode(' ', $projected['errors']));
            }

            RecoveryStore::transitionStage(RecoveryStore::STAGE_DESTRUCTIVE_START);
            RecoveryStore::transitionStage(RecoveryStore::STAGE_DATABASE_REPLACEMENT);

            $executed = $this->replaceTablesFromRows($projected['rows'], $schema, $projected['provenance'] ?? []);
            // Models bind to the previous Store data and require fresh destination approval.
            $this->pdo->exec("UPDATE platform_settings SET setting_value='' WHERE setting_key IN ('ml_approved_model_version','ml_previous_approved_model_version','ml_store_activation_record')");
            $this->pdo->exec("INSERT INTO platform_settings (setting_key,setting_value) VALUES ('ml_observation_mode','1'),('ml_recommendations_suspended','1') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");

            RecoveryStore::transitionStage(RecoveryStore::STAGE_UPLOADS);
            $uploaded = $this->restoreUploads($incomingPackage['files'], $projected['file_map'] ?? []);

            $expectedFiles = $safetyCheckpoint['files'];
            foreach ($incomingPackage['files'] as $path => $content) {
                $expectedFiles[$projected['file_map'][$path] ?? $path] = $content;
            }

            RecoveryStore::transitionStage(RecoveryStore::STAGE_AUTOMATED_VERIFICATION);
            $this->verifyPostRestoreIntegrity($schema, $expectedFiles);
            $checks = $this->runVerificationChecks($expectedFiles);

            RecoveryStore::transitionStage(RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING, [
                'mode' => 'incoming_restart',
                'package_id' => $approval['package_id'],
                'statements' => $executed,
                'files' => $uploaded,
            ]);
            $this->recordReviewChecks($checks['checks']);
            RecoveryStore::log($actorId, 'incoming-' . $approval['package_id'], 'restarted_incoming', $executed);

            return [
                'success' => true,
                'mode' => 'incoming_restart',
                'statements' => $executed,
                'files' => $uploaded,
                'stage' => RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING,
                'message' => 'Incoming replacement restarted successfully. Automated verification passed. Pending operator review.',
            ];
        } catch (Throwable $e) {
            try {
                RecoveryStore::transitionStage(RecoveryStore::STAGE_RECOVERY_REQUIRED, ['error' => $e->getMessage()]);
                RecoveryStore::log($actorId, 'incoming-restart', 'incoming_restart_failed: ' . $e->getMessage());
            } catch (Throwable) {}
            throw $e;
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
    }

    private function replaceTablesFromSql(string $sql, array $schema): int
    {
        $statements = SqlBackupFormat::statements($sql, $schema);
        $oldSqlMode = (string)$this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->exec("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
        $executed = 0;
        try {
            foreach ($statements as $statement) {
                $this->pdo->exec($statement);
                $executed++;
            }
            $this->resetTransientState();
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            if ($oldSqlMode !== '') {
                $this->pdo->exec('SET SESSION sql_mode=' . $this->pdo->quote($oldSqlMode));
            }
        }
        return $executed;
    }

    private function replaceTablesFromRows(array $tableRows, array $schema, array $provenance = []): int
    {
        $oldSqlMode = (string)$this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->exec("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
        $executed = 0;
        try {
            foreach ($schema as $table => $definition) {
                $quoted = DatabaseSnapshotWriter::identifier($table);
                $this->pdo->exec("DROP TABLE IF EXISTS {$quoted}");
                $this->pdo->exec($definition['ddl']);
                $executed += 2;

                $records = $tableRows[$table] ?? [];
                if ($records === []) {
                    continue;
                }
                $columns = $definition['columns'];
                $colSql = implode(', ', array_map([DatabaseSnapshotWriter::class, 'identifier'], $columns));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $insert = $this->pdo->prepare("INSERT INTO {$quoted} ({$colSql}) VALUES ({$placeholders})");

                foreach ($records as $record) {
                    $params = [];
                    foreach ($columns as $col) {
                        $params[] = $record[$col] ?? null;
                    }
                    $insert->execute($params);
                    $executed++;
                }
            }

            if (!empty($provenance) && \App\Database\Schema::tableExists($this->pdo, 'store_record_origins')) {
                $provInsert = $this->pdo->prepare(
                    "INSERT IGNORE INTO store_record_origins (origin_id, table_name, source_key, local_key, original_details) VALUES (?, ?, ?, ?, ?)"
                );
                foreach ($provenance as $prov) {
                    $provInsert->execute([
                        $prov['origin_id'],
                        $prov['table_name'],
                        $prov['source_key'],
                        $prov['local_key'],
                        $prov['original_details'],
                    ]);
                    $executed++;
                }
            }

            $this->resetTransientState();
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            if ($oldSqlMode !== '') {
                $this->pdo->exec('SET SESSION sql_mode=' . $this->pdo->quote($oldSqlMode));
            }
        }
        return $executed;
    }

    private function restoreUploads(array $files, array $fileMap = []): int
    {
        $storage = $this->uploadDirectory ?? dirname(__DIR__, 2) . '/storage';
        if (is_link($storage)) {
            throw new DomainException('Upload storage uses linked path.');
        }
        $count = 0;
        foreach ($files as $sourcePath => $content) {
            $targetSubPath = $fileMap[$sourcePath] ?? $sourcePath;
            if (!preg_match('~^(?:images|profile-images)/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|gif|webp)$~D', $targetSubPath)) {
                throw new DomainException('Unsafe uploaded file target: ' . $targetSubPath);
            }
            $fullPath = $storage . '/' . $targetSubPath;
            $dir = dirname($fullPath);
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new DomainException('Failed to create upload destination directory: ' . $dir);
            }
            if (file_put_contents($fullPath, $content) === false) {
                throw new DomainException('Failed to restore uploaded file: ' . $targetSubPath);
            }
            $count++;
        }
        return $count;
    }

    public function verifyPostRestoreIntegrity(array $schema, array $expectedFiles = []): array
    {
        $keys = $this->pdo->query("SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);

        $constraints = [];
        foreach ($keys as $key) {
            $constraints[$key['TABLE_NAME'] . '.' . $key['CONSTRAINT_NAME']][] = $key;
        }

        foreach ($constraints as $name => $parts) {
            $childTable = DatabaseSnapshotWriter::identifier($parts[0]['TABLE_NAME']);
            $parentTable = DatabaseSnapshotWriter::identifier($parts[0]['REFERENCED_TABLE_NAME']);
            $joinClauses = [];
            $whereNulls = [];
            $childNotNulls = [];

            foreach ($parts as $p) {
                $childCol = DatabaseSnapshotWriter::identifier($p['COLUMN_NAME']);
                $parentCol = DatabaseSnapshotWriter::identifier($p['REFERENCED_COLUMN_NAME']);
                $joinClauses[] = "c.{$childCol} = p.{$parentCol}";
                $whereNulls[] = "p.{$parentCol} IS NULL";
                $childNotNulls[] = "c.{$childCol} IS NOT NULL";
            }

            $joinSql = implode(' AND ', $joinClauses);
            $nullSql = implode(' AND ', $whereNulls);
            $notNullSql = implode(' AND ', $childNotNulls);

            $orphanQuery = "SELECT 1 FROM {$childTable} c LEFT JOIN {$parentTable} p ON {$joinSql} WHERE {$notNullSql} AND ({$nullSql}) LIMIT 1";
            if ($this->pdo->query($orphanQuery)->fetchColumn()) {
                throw new DomainException('Foreign key integrity violation in constraint: ' . $name);
            }
        }

        $verification = $this->runVerificationChecks($expectedFiles);
        if (!$verification['all_passed']) {
            foreach (['attribution', 'stock', 'receipt', 'upload'] as $key) {
                $check = $verification['checks'][$key] ?? null;
                if ($check && ($check['status'] ?? '') !== 'passed') {
                    throw new DomainException($check['details'] ?? 'Integrity verification failed.');
                }
            }
            throw new DomainException('Integrity verification failed.');
        }

        return ['valid' => true];
    }

    private function resetTransientState(): void
    {
        if (\App\Database\Schema::tableExists($this->pdo, 'backup_operations')) {
            $this->pdo->exec('DELETE FROM backup_operations');
        }
        if (\App\Database\Schema::tableExists($this->pdo, 'store_write_gate')) {
            $this->pdo->exec("INSERT IGNORE INTO store_write_gate (gate_key) VALUES ('store_writes')");
            $this->pdo->exec("UPDATE store_write_gate SET paused_at = NULL, paused_by = NULL WHERE gate_key = 'store_writes'");
        }
        if (\App\Database\Schema::tableExists($this->pdo, 'ml_jobs')) {
            $this->pdo->exec("UPDATE ml_jobs SET status = 'failed', operator_message = 'Invalidated by database restore' WHERE status IN ('queued', 'running')");
        }
        if (\App\Database\Schema::tableExists($this->pdo, 'model_training_runs')) {
            $this->pdo->exec("UPDATE model_training_runs SET status = 'failed' WHERE status IN ('queued', 'training')");
        }
    }

    private function assertRecoveryOperator(int $actorId, string $actorRole): void
    {
        if (!in_array($actorRole, ['admin', 'super_admin'], true)) {
            throw new DomainException('Operator is not authorized for this recovery operation.');
        }
        if (!RecoveryStore::isPaused()) {
            throw new DomainException('No active recovery operation.');
        }
        $state = RecoveryStore::state();
        $operators = $state['authorized_operators'] ?? [];
        $found = false;
        foreach ($operators as $op) {
            if ((int)$op['user_id'] === $actorId && ($op['role'] ?? '') === $actorRole) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new DomainException('Operator is not authorized for this recovery operation.');
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

    public function runVerificationChecks(?array $expectedFiles = []): array
    {
        $now = gmdate('c');
        $checks = [];

        // 1. Stock check
        $negInv = false;
        $invCount = 0;
        if (\App\Database\Schema::tableExists($this->pdo, 'inventory')) {
            $negInv = (bool)$this->pdo->query("SELECT 1 FROM inventory WHERE quantity_on_hand < 0 LIMIT 1")->fetchColumn();
            $invCount = (int)$this->pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
        }
        $negBatch = false;
        $batchCount = 0;
        if (\App\Database\Schema::tableExists($this->pdo, 'product_batches')) {
            $negBatch = (bool)$this->pdo->query("SELECT 1 FROM product_batches WHERE remaining_quantity < 0 OR quantity < 0 LIMIT 1")->fetchColumn();
            $batchCount = (int)$this->pdo->query("SELECT COUNT(*) FROM product_batches")->fetchColumn();
        }
        $stockPassed = !$negInv && !$negBatch;
        $checks['stock'] = [
            'status' => $stockPassed ? 'passed' : 'failed',
            'checked_at' => $now,
            'details' => $stockPassed
                ? "Zero negative stock across {$invCount} inventory items and {$batchCount} product batches."
                : "Negative inventory or batch stock detected.",
            'items_count' => $invCount,
            'batches_count' => $batchCount,
        ];

        // 2. Receipt check
        $saleMismatch = false;
        $salesCount = 0;
        if (\App\Database\Schema::tableExists($this->pdo, 'sales') && \App\Database\Schema::tableExists($this->pdo, 'sale_items')) {
            $discount = \App\Database\Schema::columnExists($this->pdo, 'sales', 'discount_amount') ? 'COALESCE(s.discount_amount, 0)' : '0';
            $saleMismatch = (bool)$this->pdo->query(
                "SELECT 1 FROM sales s
                 LEFT JOIN (SELECT sale_id, SUM(subtotal) AS item_sum FROM sale_items GROUP BY sale_id) si ON si.sale_id = s.sale_id
                 WHERE ABS(s.total_amount + {$discount} - COALESCE(si.item_sum, 0)) > 0.05
                 LIMIT 1"
            )->fetchColumn();
            $salesCount = (int)$this->pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
        }
        $refundMismatch = false;
        if (\App\Database\Schema::tableExists($this->pdo, 'cash_refunds') && \App\Database\Schema::tableExists($this->pdo, 'cash_refund_items')) {
            $refundMismatch = (bool)$this->pdo->query(
                "SELECT 1 FROM cash_refunds r
                 LEFT JOIN (SELECT refund_id, SUM(subtotal) AS item_sum FROM cash_refund_items GROUP BY refund_id) ri ON ri.refund_id = r.refund_id
                 WHERE ABS(r.refund_amount - COALESCE(ri.item_sum, 0)) > 0.05
                 LIMIT 1"
            )->fetchColumn();
        }
        $receiptPassed = !$saleMismatch && !$refundMismatch;
        $checks['receipt'] = [
            'status' => $receiptPassed ? 'passed' : 'failed',
            'checked_at' => $now,
            'details' => $receiptPassed
                ? "All {$salesCount} sales receipts and refund item subtotals match recorded totals."
                : "Discrepancy detected between receipt total and item subtotals.",
            'sales_count' => $salesCount,
        ];

        // 3. Upload check
        $storage = $this->uploadDirectory ?? dirname(__DIR__, 2) . '/storage';
        $uploadPassed = true;
        $uploadCount = 0;
        $missingCount = 0;
        if (!empty($expectedFiles)) {
            foreach ($expectedFiles as $relPath => $content) {
                $filePath = $storage . '/' . $relPath;
                $uploadCount++;
                if (!is_file($filePath) || (is_string($content) && hash_file('sha256', $filePath) !== hash('sha256', $content))) {
                    $uploadPassed = false;
                    $missingCount++;
                }
            }
        } else {
            foreach (['images', 'profile-images'] as $sub) {
                $dir = $storage . '/' . $sub;
                if (is_dir($dir)) {
                    $uploadCount += count(glob($dir . '/*.*') ?: []);
                }
            }
        }
        $checks['upload'] = [
            'status' => $uploadPassed ? 'passed' : 'failed',
            'checked_at' => $now,
            'details' => $uploadPassed
                ? "{$uploadCount} verified upload assets present on filesystem."
                : "Missing or corrupted upload files ({$missingCount} failed).",
            'files_count' => $uploadCount,
        ];

        // 4. Attribution check
        $superAdminExists = (bool)$this->pdo->query(
            "SELECT 1 FROM users u
             LEFT JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN user_roles ur ON ur.user_id = u.user_id
             LEFT JOIN roles sr ON sr.role_id = ur.role_id
             WHERE (r.role_name = 'super_admin' OR sr.role_name = 'super_admin')
               AND u.status = 'active'
               AND u.is_recovery_account = 0
             LIMIT 1"
        )->fetchColumn();
        $unresolvedAttribution = false;
        if (\App\Database\Schema::tableExists($this->pdo, 'branches')) {
            $unresolvedAttribution = $unresolvedAttribution || (bool)$this->pdo->query("SELECT 1 FROM branches b LEFT JOIN users u ON b.created_by = u.user_id WHERE b.created_by IS NOT NULL AND u.user_id IS NULL LIMIT 1")->fetchColumn();
        }
        if (\App\Database\Schema::tableExists($this->pdo, 'sales')) {
            $unresolvedAttribution = $unresolvedAttribution || (bool)$this->pdo->query("SELECT 1 FROM sales s LEFT JOIN users u ON s.cashier_id = u.user_id WHERE s.cashier_id IS NOT NULL AND u.user_id IS NULL LIMIT 1")->fetchColumn();
        }
        $attributionPassed = $superAdminExists && !$unresolvedAttribution;
        $checks['attribution'] = [
            'status' => $attributionPassed ? 'passed' : 'failed',
            'checked_at' => $now,
            'details' => $attributionPassed
                ? "Active destination authority present and all actor attributions resolve."
                : "Missing active destination Super Administrator or dangling actor foreign key.",
        ];

        // 5. Read-only Sign-in Preview
        $activeUsers = [];
        if (\App\Database\Schema::tableExists($this->pdo, 'users')) {
            $activeUsers = $this->pdo->query(
                "SELECT u.user_id, u.username, COALESCE(r.role_name, 'user') AS role_name, u.status
                 FROM users u
                 LEFT JOIN roles r ON r.role_id = u.role_id
                 WHERE u.status = 'active'
                 ORDER BY u.user_id ASC LIMIT 10"
            )->fetchAll(\PDO::FETCH_ASSOC);
        }
        $checks['signin_preview'] = [
            'status' => 'passed',
            'checked_at' => $now,
            'details' => count($activeUsers) . " active user accounts previewed.",
            'accounts' => $activeUsers,
        ];

        // 6. Summary Report Check
        $metrics = [];
        if (\App\Database\Schema::tableExists($this->pdo, 'sales')) {
            $row = $this->pdo->query("SELECT COUNT(*) AS total_sales, COALESCE(SUM(total_amount), 0) AS total_revenue FROM sales")->fetch(\PDO::FETCH_ASSOC);
            $metrics['sales_count'] = (int)($row['total_sales'] ?? 0);
            $metrics['total_revenue'] = (float)($row['total_revenue'] ?? 0);
        }
        if (\App\Database\Schema::tableExists($this->pdo, 'products')) {
            $metrics['products_count'] = (int)$this->pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        }
        $checks['report'] = [
            'status' => 'passed',
            'checked_at' => $now,
            'details' => "Summary financial and inventory metrics computed.",
            'metrics' => $metrics,
        ];

        $allPassed = $checks['stock']['status'] === 'passed'
            && $checks['receipt']['status'] === 'passed'
            && $checks['upload']['status'] === 'passed'
            && $checks['attribution']['status'] === 'passed';

        return [
            'all_passed' => $allPassed,
            'checked_at' => $now,
            'checks' => $checks,
        ];
    }

    public function recordReviewChecks(array $checks): void
    {
        RecoveryStore::recordReviewChecks(['automated' => $checks]);
    }

    public function confirmChecklistItem(int $actorId, string $actorRole, string $checkKey, bool $confirmed = true): void
    {
        $this->assertRecoveryOperator($actorId, $actorRole);
        $validKeys = ['stock', 'receipt', 'upload', 'attribution'];
        if (!in_array($checkKey, $validKeys, true)) {
            throw new \DomainException("Invalid review checklist key: {$checkKey}");
        }
        RecoveryStore::recordReviewChecks([
            'human_confirmations' => [
                $checkKey => [
                    'confirmed' => $confirmed,
                    'confirmed_by' => $actorId,
                    'confirmed_by_role' => $actorRole,
                    'confirmed_at' => gmdate('c'),
                ]
            ]
        ]);
    }

    public function validateReopeningReadiness(): array
    {
        if (!RecoveryStore::isPaused()) {
            return [
                'ready' => false,
                'missing' => ['No active recovery operation.'],
                'guidance' => 'No active recovery operation is pending.',
            ];
        }
        $state = RecoveryStore::state();
        if (($state['stage'] ?? '') !== RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING) {
            return [
                'ready' => false,
                'missing' => ['Operation stage must be operator_review_pending (currently ' . ($state['stage'] ?? 'none') . ').'],
                'guidance' => 'Reopening requires operation stage to be operator_review_pending.',
            ];
        }

        $missing = [];
        $requiredKeys = ['stock', 'receipt', 'upload', 'attribution'];

        $automated = $state['review_checks']['automated'] ?? [];
        foreach ($requiredKeys as $key) {
            if (!isset($automated[$key]) || ($automated[$key]['status'] ?? '') !== 'passed') {
                $missing[] = "Automated check '{$key}' has not passed.";
            } else {
                $chkTime = strtotime((string)($automated[$key]['checked_at'] ?? ''));
                if ($chkTime === false || (time() - $chkTime) > 900) {
                    $missing[] = "Automated check '{$key}' is stale (> 15 min old). Re-run verification checks.";
                }
            }
        }

        $human = $state['review_checks']['human_confirmations'] ?? [];
        foreach ($requiredKeys as $key) {
            if (empty($human[$key]['confirmed'])) {
                $missing[] = "Human confirmation for '{$key}' is missing.";
            }
        }

        if (!empty($missing)) {
            return [
                'ready' => false,
                'missing' => $missing,
                'guidance' => 'Reopening blocked by missing or unconfirmed review checks: ' . implode(' ', $missing),
            ];
        }

        return [
            'ready' => true,
            'missing' => [],
            'guidance' => 'All automated checks and human checklist confirmations verified. Store is ready to reopen.',
        ];
    }

    public function workflowGuidance(?array $state = null): array
    {
        $state ??= (RecoveryStore::isAvailable() ? RecoveryStore::state() : null);
        $isPaused = RecoveryStore::isAvailable() && RecoveryStore::isPaused();

        if (!$isPaused) {
            $approval = (new IdentityReconciliationService($this->pdo, $this->policy))->latestApproval();
            if ($approval === null) {
                return [
                    'stage' => 'technical_approval_waiting',
                    'stage_label' => 'Waiting for technical approval',
                    'next_permitted_action' => 'Super Administrator must review and approve Identity Reconciliation in platform governance workspace before replacement can proceed.',
                    'ready' => false,
                    'missing' => ['Identity Reconciliation has not been approved by a Super Administrator.'],
                ];
            }

            return [
                'stage' => 'ready_to_restore',
                'stage_label' => 'Ready to restore',
                'next_permitted_action' => 'Authenticated Administrator or Super Administrator may initiate encrypted replacement or restart incoming replacement with verified passphrases.',
                'ready' => true,
                'missing' => [],
            ];
        }

        $currentStage = $state['stage'] ?? '';

        if ($currentStage === RecoveryStore::STAGE_RECOVERY_REQUIRED) {
            return [
                'stage' => 'actionable_failure',
                'stage_label' => 'Actionable failure (Recovery required)',
                'next_permitted_action' => 'Execute an alternative recovery action: Complete Safety Recovery (rollback to verified destination safety copy) or Restart Approved Incoming Replacement.',
                'ready' => false,
                'missing' => ['Recovery operation encountered a failure.'],
            ];
        }

        if ($currentStage === RecoveryStore::STAGE_SAFETY_VERIFIED) {
            return [
                'stage' => 'ready_to_restore',
                'stage_label' => 'Ready to restore',
                'next_permitted_action' => 'Authenticated Administrator or Super Administrator may initiate encrypted replacement or restart incoming replacement with verified passphrases.',
                'ready' => true,
                'missing' => [],
            ];
        }

        if ($currentStage === RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING) {
            $readiness = $this->validateReopeningReadiness();
            if (!$readiness['ready']) {
                return [
                    'stage' => 'verification_needed',
                    'stage_label' => 'Verification needed',
                    'next_permitted_action' => 'Run automated verification checks and confirm all human checklist items (stock, receipt, upload, attribution) before reopening.',
                    'ready' => false,
                    'missing' => $readiness['missing'],
                ];
            }

            return [
                'stage' => 'ready_to_reopen',
                'stage_label' => 'Ready to reopen',
                'next_permitted_action' => 'Authorized operator must confirm single active Store and re-enter destination password to explicitly reopen Store access.',
                'ready' => true,
                'missing' => [],
            ];
        }

        if ($currentStage === RecoveryStore::STAGE_REOPENED_COMPLETED) {
            return [
                'stage' => 'reopened_completed',
                'stage_label' => 'Reopening completed',
                'next_permitted_action' => 'Store is online. Ordinary requests admitted.',
                'ready' => true,
                'missing' => [],
            ];
        }

        return [
            'stage' => $currentStage ?: 'idle',
            'stage_label' => ucwords(str_replace('_', ' ', $currentStage ?: 'idle')),
            'next_permitted_action' => 'Wait for stage completion. Automated recovery process is running.',
            'ready' => false,
            'missing' => [],
        ];
    }

    public function takeover(
        int $actorId,
        string $actorRole,
        #[\SensitiveParameter] string $actorPassword
    ): array {
        if (!RecoveryStore::isPaused()) {
            throw new \DomainException('No active recovery operation.');
        }

        $state = RecoveryStore::state();
        $operators = $state['authorized_operators'] ?? [];
        $matched = null;
        foreach ($operators as $op) {
            if ((int)$op['user_id'] === $actorId) {
                $matched = $op;
                break;
            }
        }

        if (!$matched || !in_array($matched['role'] ?? '', ['admin', 'super_admin'], true)) {
            throw new \DomainException('Operator is not authorized for this recovery operation.');
        }

        if ($actorPassword === '' || !password_verify($actorPassword, (string)$matched['password_hash'])) {
            throw new \DomainException('Operator password verification failed. Fresh authentication required to take over recovery.');
        }

        $isTakeover = empty($matched['is_initiator']);
        $takeoverInfo = [
            'taken_over' => $isTakeover,
            'taken_over_by' => $actorId,
            'taken_over_by_username' => (string)$matched['username'],
            'taken_over_by_role' => (string)$matched['role'],
            'original_initiator_id' => (int)($state['actor_id'] ?? 0),
            'taken_over_at' => gmdate('c'),
        ];

        RecoveryStore::write('recovery-takeover.json', json_encode($takeoverInfo, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        RecoveryStore::log($actorId, 'takeover-' . ($state['operation_id'] ?? ''), 'recovery_takeover_recorded', 0);

        return [
            'success' => true,
            'is_takeover' => $isTakeover,
            'operator' => $matched,
            'message' => $isTakeover
                ? "Recovery taken over by authorized {$matched['role']} {$matched['username']}."
                : 'Initiating administrator active.',
        ];
    }

    public function reopenStore(
        int $actorId,
        string $actorRole,
        #[\SensitiveParameter] string $actorPassword,
        string $operationId,
        ?string $notes = null
    ): array {
        if (!RecoveryStore::isPaused()) {
            throw new \DomainException('No active recovery operation.');
        }

        $state = RecoveryStore::state();
        if (($state['operation_id'] ?? '') !== $operationId) {
            throw new \DomainException('Recovery operation mismatch.');
        }

        if (($state['stage'] ?? '') !== RecoveryStore::STAGE_OPERATOR_REVIEW_PENDING) {
            throw new \DomainException('Store can only be reopened from operator_review_pending stage.');
        }

        $operators = $state['authorized_operators'] ?? [];
        $matched = null;
        foreach ($operators as $op) {
            if ((int)$op['user_id'] === $actorId) {
                $matched = $op;
                break;
            }
        }

        if (!$matched) {
            throw new \DomainException('Operator is not authorized for this recovery operation.');
        }

        if (!in_array($matched['role'] ?? '', ['admin', 'super_admin'], true)) {
            throw new \DomainException('Operator role is not authorized to reopen the Store.');
        }

        if ($actorPassword === '' || !password_verify($actorPassword, (string)$matched['password_hash'])) {
            throw new \DomainException('Operator password verification failed. Fresh authentication required for reopening.');
        }

        $readiness = $this->validateReopeningReadiness();
        if (!$readiness['ready']) {
            throw new \DomainException($readiness['guidance']);
        }

        $pathCompleted = 'safety_recovery';
        foreach (array_reverse($state['stages'] ?? []) as $st) {
            if (($st['mode'] ?? '') === 'incoming_restart' || ($st['mode'] ?? '') === 'restart_incoming') {
                $pathCompleted = 'restart_incoming';
                break;
            }
            if (($st['mode'] ?? '') === 'safety_recovery') {
                $pathCompleted = 'safety_recovery';
                break;
            }
        }

        $newEpoch = bin2hex(random_bytes(32));
        RecoveryStore::write('session-epoch', $newEpoch);

        if (\App\Database\Schema::tableExists($this->pdo, 'store_write_gate')) {
            $this->pdo->exec("UPDATE store_write_gate SET paused_at = NULL, paused_by = NULL WHERE gate_key = 'store_writes'");
        }

        if (\App\Database\Schema::tableExists($this->pdo, 'ml_jobs')) {
            $this->pdo->exec("UPDATE ml_jobs SET status = 'failed', operator_message = 'Invalidated by database restore' WHERE status IN ('queued', 'running')");
        }
        if (\App\Database\Schema::tableExists($this->pdo, 'model_training_runs')) {
            $this->pdo->exec("UPDATE model_training_runs SET status = 'failed' WHERE status IN ('queued', 'training')");
        }

        $isTakeover = empty($matched['is_initiator']);
        $takeoverData = $isTakeover ? [
            'taken_over' => true,
            'original_initiator_id' => (int)($state['actor_id'] ?? 0),
            'taken_over_by' => (int)$matched['user_id'],
            'taken_over_by_username' => (string)$matched['username'],
            'taken_over_by_role' => (string)$matched['role'],
            'taken_over_at' => gmdate('c'),
        ] : null;

        $reopenedData = [
            'operation_id' => $operationId,
            'stage' => RecoveryStore::STAGE_REOPENED_COMPLETED,
            'reopened_at' => gmdate('c'),
            'reopened_by' => [
                'user_id' => (int)$matched['user_id'],
                'username' => (string)$matched['username'],
                'role' => (string)$matched['role'],
                'is_initiator' => (bool)($matched['is_initiator'] ?? false),
            ],
            'takeover' => $takeoverData,
            'path_completed' => $pathCompleted,
            'verified_checks' => $state['review_checks'] ?? [],
            'session_epoch' => $newEpoch,
            'safety_operation_id' => (string)($state['safety_operation_id'] ?? ''),
            'single_active_store_confirmed' => true,
            'notes' => $notes,
        ];
        RecoveryStore::write('recovery-reopened.json', json_encode($reopenedData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        RecoveryStore::log($actorId, 'reopen-' . $operationId, $isTakeover ? 'takeover_reopened_completed' : 'reopened_completed', 0);
        RecoveryStore::transitionStage(RecoveryStore::STAGE_REOPENED_COMPLETED);

        if (\App\Database\Schema::tableExists($this->pdo, 'activity_log')) {
            try {
                $action = $isTakeover ? 'Recovery store reopened (takeover)' : 'Recovery store reopened';
                $stmt = $this->pdo->prepare("INSERT INTO activity_log (user_id, action, category, module, metadata) VALUES (?, ?, 'security', 'Database Restore', ?)");
                $stmt->execute([
                    $actorId,
                    $action,
                    json_encode($reopenedData, JSON_THROW_ON_ERROR),
                ]);
            } catch (\PDOException) {}
        }

        return [
            'success' => true,
            'reopened_record' => $reopenedData,
            'message' => 'Store successfully reopened. Access resumed.',
        ];
    }

}
