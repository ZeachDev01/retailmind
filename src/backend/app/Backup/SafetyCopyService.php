<?php

namespace App\Backup;

use App\Authorization\RoleCapabilityPolicy;
use DomainException;
use PDO;
use Throwable;

/** Complete destination fallback, retained independently of replaced tables and downloads. */
final class SafetyCopyService
{
    public function __construct(
        private PDO $pdo,
        private RoleCapabilityPolicy $policy,
        private ?PDO $coordination = null,
        private ?string $uploadDirectory = null
    ) {
    }

    public function create(string $operationId, int $actorId, string $role,
        #[\SensitiveParameter] string $loginPassword, #[\SensitiveParameter] string $safetyPassphrase, $requestLease = null): array
    {
        $directory = self::directory($operationId);
        $backups = new DatabaseBackupService($this->pdo, $this->policy, $directory, $this->coordination, $this->uploadDirectory);
        $actor = $backups->authorizeActor($actorId, $role);
        if ($loginPassword === '' || !password_verify($loginPassword, (string)$actor['password_hash'])) {
            throw new DomainException('Your current destination login password is incorrect.');
        }
        StorePackageFormat::assertPassphrase($safetyPassphrase);
        $lease = $requestLease ?? RecoveryStore::exclusive();
        $created = null;
        try {
            if ($requestLease !== null && (!is_resource($requestLease) || get_resource_type($requestLease) !== 'stream'
                || realpath((string)(stream_get_meta_data($requestLease)['uri'] ?? '')) !== realpath(RecoveryStore::path('requests.lock'))
                || !flock($requestLease, LOCK_EX | LOCK_NB))) {
                throw new DomainException('The existing Store write pause is invalid.');
            }
            if (is_file($directory . '/metadata.json')) {
                $verified = $this->verify($operationId, $safetyPassphrase);
                if ($verified['metadata']['requested_by'] !== $actorId) throw new DomainException('That safety copy belongs to another requester.');
                return $verified['metadata'];
            }
            if (file_exists($directory . '/safety.rmpkg')) {
                throw new DomainException('Safety capture was interrupted before verification. Keep the artifact and prepare a new operation; nothing was replaced.');
            }
            $created = $backups->create($actorId, $role, $safetyPassphrase, $lease);
            if ($created['status'] !== DatabaseBackupService::OUTCOME_READY) throw new DomainException(DatabaseBackupService::IN_PROGRESS_LINE);
            $schema = SqlBackupFormat::schema($this->pdo);
            $bytes = file_get_contents($created['path']);
            if ($bytes === false) throw new DomainException('The safety artifact could not be read for full verification.');
            $package = StorePackageFormat::decode($bytes, $safetyPassphrase, $schema);
            (new DatabaseRestoreService($this->pdo, $this->policy, $backups))->validateReferences($package['rows']);
            $metadata = [
                'operation_id' => $operationId, 'stage' => 'safety_verified', 'requested_by' => $actorId, 'requested_by_role' => $role,
                'package_id' => $package['manifest']['package_id'], 'origin_id' => $package['manifest']['origin_id'],
                'schema' => $schema, 'schema_hash' => $package['manifest']['schema'], 'sha256' => hash('sha256', $bytes),
                'sql_sha256' => hash('sha256', $package['sql']), 'file_sha256' => array_map(static fn(string $content): string => hash('sha256', $content), $package['files']),
                'size' => strlen($bytes), 'snapshot_at' => $package['manifest']['snapshot_at'],
                'filename' => 'retailmind-safety-' . $operationId . '.rmpkg',
            ];
            if (!rename($created['path'], $directory . '/safety.rmpkg')) throw new DomainException('The verified safety copy could not be retained. Nothing was replaced.');
            RecoveryStore::write('safety-' . $operationId . '/metadata.json', json_encode($metadata, JSON_THROW_ON_ERROR));
            return $metadata;
        } catch (Throwable $exception) {
            if ($created !== null && !is_file($directory . '/metadata.json')) {
                $backups->coordinator()->discard((string)($created['path'] ?? ''));
                $backups->coordinator()->discard($directory . '/safety.rmpkg');
            }
            throw $exception;
        } finally {
            if ($requestLease === null && is_resource($lease)) fclose($lease);
        }
    }

    /** Uses the original checkpoint schema, including when application tables are missing. */
    public function verify(string $operationId, #[\SensitiveParameter] string $safetyPassphrase): array
    {
        $metadata = $this->metadata($operationId);
        $path = self::directory($operationId) . '/safety.rmpkg';
        $this->assertArtifact($path, $metadata);
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new DomainException('The original safety copy could not be read. Keep Store operations closed.');
        $package = StorePackageFormat::decode($bytes, $safetyPassphrase, $metadata['schema']);
        if ($package['manifest']['package_id'] !== $metadata['package_id'] || $package['manifest']['origin_id'] !== $metadata['origin_id']
            || $package['manifest']['schema'] !== $metadata['schema_hash'] || hash('sha256', $package['sql']) !== $metadata['sql_sha256']
            || array_map(static fn(string $content): string => hash('sha256', $content), $package['files']) !== $metadata['file_sha256']) {
            throw new DomainException('The original safety copy does not match its protected checkpoint. Keep Store operations closed.');
        }
        return ['metadata' => $metadata, 'package' => $package];
    }

    public function resolveDownload(string $operationId, int $actorId, string $role): array
    {
        (new DatabaseBackupService($this->pdo, $this->policy))->authorizeActor($actorId, $role);
        $metadata = $this->metadata($operationId);
        if ($metadata['requested_by'] !== $actorId) throw new DomainException('That safety copy belongs to another requester.');
        $path = self::directory($operationId) . '/safety.rmpkg';
        $this->assertArtifact($path, $metadata);
        return ['path' => $path, 'filename' => $metadata['filename'], 'size' => $metadata['size'], 'operation_key' => $operationId];
    }

    private function metadata(string $operationId): array
    {
        $path = self::directory($operationId) . '/metadata.json';
        if (!is_file($path) || is_link($path)) throw new DomainException('The original verified safety checkpoint is missing. Keep Store operations closed.');
        $metadata = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || ($metadata['operation_id'] ?? '') !== $operationId || ($metadata['stage'] ?? '') !== 'safety_verified'
            || !is_int($metadata['requested_by'] ?? null) || !is_array($metadata['schema'] ?? null)
            || !is_int($metadata['size'] ?? null) || $metadata['size'] < 1 || $metadata['size'] > StorePackageFormat::MAX_BYTES
            || !preg_match('/^[a-f0-9]{64}$/D', (string)($metadata['sha256'] ?? ''))
            || !is_array($metadata['file_sha256'] ?? null) || !isset($metadata['package_id'], $metadata['origin_id'], $metadata['schema_hash'], $metadata['sql_sha256'])
            || ($metadata['filename'] ?? '') !== 'retailmind-safety-' . $operationId . '.rmpkg') {
            throw new DomainException('The original safety checkpoint is invalid. Keep Store operations closed.');
        }
        return $metadata;
    }

    private function assertArtifact(string $path, array $metadata): void
    {
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path) || filesize($path) !== $metadata['size'] || hash_file('sha256', $path) !== $metadata['sha256']) {
            throw new DomainException('The original safety copy is missing or changed. Keep Store operations closed.');
        }
    }

    private static function directory(string $operationId): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $operationId)) throw new DomainException('Select a valid safety operation.');
        $directory = RecoveryStore::path('safety-' . $operationId);
        if (is_link($directory)) throw new DomainException('The private safety directory is unsafe.');
        return $directory;
    }
}
