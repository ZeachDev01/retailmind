<?php

namespace App\Backup;

use App\Authorization\RoleCapabilityPolicy;
use App\Services\UserLifecycleService;
use DomainException;
use PDO;

/** Read-only destination review. A projection is never replacement authority. */
final class IdentityReconciliationService
{
    public function __construct(private PDO $pdo, private RoleCapabilityPolicy $policy) {}

    public function protectedUserIds(): array
    {
        return array_map('intval', $this->pdo->query("SELECT DISTINCT u.user_id FROM users u
            LEFT JOIN roles r ON r.role_id=u.role_id
            LEFT JOIN user_roles ur ON ur.user_id=u.user_id
            LEFT JOIN roles secondary_role ON secondary_role.role_id=ur.role_id
            LEFT JOIN recovery_accounts recovery ON recovery.user_id=u.user_id
            WHERE r.role_name='super_admin' OR secondary_role.role_name='super_admin'
                OR u.is_recovery_account=1 OR recovery.user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function review(int $actorId, string $actorRole, #[\SensitiveParameter] array $package, array $decisions = []): array
    {
        $actor = $this->pdo->prepare("SELECT u.status FROM users u WHERE u.user_id=? AND u.role_id IS NOT NULL
            AND u.is_recovery_account=0 AND NOT EXISTS (SELECT 1 FROM recovery_accounts recovery WHERE recovery.user_id=u.user_id)
            AND (EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=u.user_id AND r.role_name='super_admin')
                OR (NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.user_id)
                    AND EXISTS (SELECT 1 FROM roles r WHERE r.role_id=u.role_id AND r.role_name='super_admin')))");
        $actor->execute([$actorId]);
        if ($actorRole !== 'super_admin' || $actor->fetchColumn() !== 'active') {
            throw new DomainException('An active destination Super Administrator must review identity reconciliation.');
        }
        $validation = new DatabaseRestoreService($this->pdo, $this->policy, new DatabaseBackupService($this->pdo, $this->policy));
        $validation->validateReferences($package['rows']);
        $this->validateEmbeddedActors($package['rows']);
        $source = $package['rows'];
        $destination = $this->pdo->query('SELECT * FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
        $sourceRoles = array_column($source['roles'] ?? [], 'role_name', 'role_id');
        $destinationRoles = $this->pdo->query('SELECT * FROM roles ORDER BY role_id')->fetchAll(PDO::FETCH_ASSOC);
        $roles = array_column($destinationRoles, 'role_id', 'role_name');
        $destById = array_column($destination, null, 'user_id');
        $sourceRecovery = array_fill_keys(array_column($source['recovery_accounts'] ?? [], 'user_id'), true);
        $result = ['source_users' => [], 'destination_users' => [], 'conflicts' => [], 'identity_map' => [],
            'file_map' => [], 'rows' => $source, 'provenance' => [], 'errors' => [], 'ready' => false,
            'active_administrators' => 0, 'replacement_authorized' => false, 'protected_user_ids' => $this->protectedUserIds()];
        foreach ($destination as $user) $result['destination_users'][] = $this->displayUser($user, $this->destinationRoleNames((int)$user['user_id']));
        foreach ($source['users'] ?? [] as $user) {
            $id = (int)$user['user_id'];
            $names = isset($sourceRoles[$user['role_id'] ?? '']) ? [$sourceRoles[$user['role_id']]] : [];
            $assignedNames = [];
            foreach ($source['user_roles'] ?? [] as $membership) {
                if ((int)$membership['user_id'] === $id && isset($sourceRoles[$membership['role_id']])) $assignedNames[] = $sourceRoles[$membership['role_id']];
            }
            $effectiveNames = $assignedNames ?: $names;
            $names = array_merge($names, $assignedNames);
            $names = array_values(array_unique($names));
            $ordinary = array_values(array_intersect(array_values(array_unique($effectiveNames)), ['admin', 'inventory_manager', 'cashier']));
            $historical = $ordinary === [] || !empty($user['is_recovery_account']) || isset($sourceRecovery[$id]) || $user['role_id'] === null;
            $result['source_users'][] = $this->displayUser($user, $names) + ['historical' => $historical, 'ordinary_roles' => $ordinary];
            foreach (['user_id' => 'numeric ID', 'username' => 'username', 'email' => 'email'] as $column => $kind) {
                if (!isset($user[$column]) || trim((string)$user[$column]) === '') continue;
                $lookup = $this->pdo->prepare('SELECT user_id FROM users WHERE ' . DatabaseSnapshotWriter::identifier($column) . '=?');
                $lookup->execute([$user[$column]]);
                foreach ($lookup->fetchAll(PDO::FETCH_COLUMN) as $target) $result['conflicts'][] = ['source_id' => $id, 'destination_id' => (int)$target, 'kind' => $kind];
            }
        }
        if ($decisions === []) {
            $result['errors'][] = 'Choose an explicit resolution for each operational source person.';
            return $result;
        }
        $context = [
            'memberships' => $this->pdo->query('SELECT * FROM user_roles ORDER BY user_id,role_id')->fetchAll(PDO::FETCH_ASSOC),
            'membership_columns' => array_column($this->pdo->query('SHOW COLUMNS FROM user_roles')->fetchAll(PDO::FETCH_ASSOC), 'Field'),
            'recovery_user_ids' => $this->pdo->query('SELECT user_id FROM recovery_accounts')->fetchAll(PDO::FETCH_COLUMN),
            'actor_columns' => $this->pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users' AND REFERENCED_COLUMN_NAME='user_id'")->fetchAll(PDO::FETCH_ASSOC),
            'primary_keys' => $this->pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='PRIMARY' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC),
            'reserved_files' => StorePackageFormat::references(['users' => $destination, 'products' => $this->pdo->query('SELECT product_image FROM products')->fetchAll(PDO::FETCH_ASSOC)]),
        ];
        $result['context'] = $context;
        $result['decisions'] = $decisions;
        $projected = $this->project($package, $decisions, $result, $destById, $roles, $destinationRoles, $context);
        if ($projected['errors'] === []) $this->validateUniqueUsers(array_values(array_column($projected['rows']['users'], null, 'user_id') + $destById));
        if ($projected['errors'] === []) $validation->validateReferences($projected['rows']);
        return $projected;
    }

    /** Reconstruct only an integrity-bound reviewed plan against the original safety snapshot. No live-table queries. */
    public function replay(#[\SensitiveParameter] array $package, #[\SensitiveParameter] array $checkpoint, array $approved): array
    {
        $destinationRoles = $checkpoint['rows']['roles'];
        usort($destinationRoles, static fn(array $left, array $right): int => (int)$left['role_id'] <=> (int)$right['role_id']);
        $result = array_replace($approved, ['identity_map' => [], 'file_map' => [], 'provenance' => [], 'errors' => [], 'ready' => false, 'active_administrators' => 0]);
        $result = $this->project($package, $approved['decisions'], $result,
            array_column($checkpoint['rows']['users'], null, 'user_id'), array_column($destinationRoles, 'role_id', 'role_name'), $destinationRoles, $approved['context']);
        if ($result['identity_map'] !== $approved['identity_map'] || $result['file_map'] !== $approved['file_map']) {
            throw new DomainException('The approved identity or file mapping no longer matches the retained destination checkpoint.');
        }
        return $result;
    }

    private function displayUser(array $user, array $roles): array
    {
        return array_intersect_key($user, array_flip(['user_id', 'full_name', 'username', 'email', 'status', 'profile_image'])) + ['roles' => $roles];
    }

    private function destinationRoleNames(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT r.role_name FROM roles r WHERE r.role_id=(SELECT role_id FROM users WHERE user_id=?)
            OR r.role_id IN (SELECT role_id FROM user_roles WHERE user_id=?) ORDER BY r.role_id');
        $statement->execute([$id, $id]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function project(array $package, array $decisions, array $result, array $destById, array $roles, array $destinationRoles, array $context): array
    {
        $origin = (string)$package['manifest']['origin_id'];
        $nextId = max(array_merge([0], array_map('intval', array_keys($destById))));
        $users = array_intersect_key($destById, array_flip($result['protected_user_ids']));
        $destinationMemberships = $context['memberships'];
        $memberships = array_values(array_filter($destinationMemberships, static fn(array $row): bool => isset($users[$row['user_id']])));
        $linked = [];
        foreach ($result['source_users'] as $person) {
            $id = (int)$person['user_id']; $decision = $decisions[$id] ?? [];
            if (!$person['historical'] && ($decision['action'] ?? '') === 'link') {
                $targetId = (int)($decision['destination_id'] ?? 0); $target = $destById[$targetId] ?? null;
                if (!$target || !empty($target['is_recovery_account']) || in_array($targetId, array_map('intval', $context['recovery_user_ids']), true) || $target['role_id'] === null || isset($linked[$targetId])) {
                    $result['errors'][] = "Source person {$id}: choose one ordinary destination account that is not already linked.";
                    continue;
                }
                $linked[$targetId] = true; $result['identity_map'][$id] = $targetId;
                $users[$targetId] = $target;
                foreach ($destinationMemberships as $membership) {
                    if ((int)$membership['user_id'] === $targetId && !in_array($targetId, $result['protected_user_ids'], true)) $memberships[] = $membership;
                }
                continue;
            }
            if (!$person['historical'] && ($decision['action'] ?? '') !== 'distinct') {
                $result['errors'][] = "Source person {$id}: explicitly link the same person or choose distinct login details.";
                continue;
            }
            if ($person['historical'] && !in_array($decision['action'] ?? 'historical', ['historical'], true)) {
                $result['errors'][] = "Source person {$id}: privileged-only and Recovery identities must remain Historical Actors.";
                continue;
            }
            $record = current(array_filter($package['rows']['users'], static fn(array $row): bool => (int)$row['user_id'] === $id));
            $record['user_id'] = ++$nextId;
            if ($person['historical']) {
                $record['username'] = 'rm_h_' . $origin . '_' . $id;
                $record['email'] = null; $record['password_hash'] = '!'; $record['role_id'] = null;
                $record['status'] = 'disabled'; $record['is_recovery_account'] = 0;
                $record['must_change_password'] = 0; $record['session_version'] = 1;
                $record['failed_login_attempts'] = 0; $record['locked_until'] = null;
                $record['last_login_at'] = null;
            } else {
                $record['username'] = trim((string)($decision['username'] ?? ''));
                if (!array_key_exists('email', $decision)) $result['errors'][] = "Source person {$id}: explicitly keep, change or clear the optional email.";
                $record['email'] = trim((string)($decision['email'] ?? '')) ?: null;
                if ($record['username'] === '' || UserLifecycleService::usernameShapeError($record['username']) !== null) $result['errors'][] = "Source person {$id}: choose a valid unused username.";
                if ($record['email'] !== null && !filter_var($record['email'], FILTER_VALIDATE_EMAIL)) $result['errors'][] = "Source person {$id}: choose a valid email or explicitly clear it.";
                $ordinary = $person['ordinary_roles'];
                if (!empty($decision['administrator']) && !in_array('admin', $ordinary, true)) $ordinary[] = 'admin';
                foreach ($ordinary as $name) {
                    if (!isset($roles[$name]) || !$this->policy->allows('super_admin', RoleCapabilityPolicy::ASSIGN_ROLES, $name)) {
                        $result['errors'][] = "Source person {$id}: a destination fixed role template is unavailable.";
                        continue;
                    }
                    $membership = ['user_id' => $record['user_id'], 'role_id' => (int)$roles[$name], 'is_primary' => (int)($name === $ordinary[0])];
                    if (in_array('assigned_at', $context['membership_columns'], true)) $membership['assigned_at'] = $record['created_at'];
                    $memberships[] = $membership;
                }
                $record['role_id'] = (int)($roles[$ordinary[0]] ?? 0);
                $record['is_recovery_account'] = 0; $record['session_version'] = 1;
                $record['failed_login_attempts'] = 0; $record['locked_until'] = null; $record['last_login_at'] = null;
            }
            $users[$record['user_id']] = $record;
            $result['identity_map'][$id] = $record['user_id'];
        }
        if ($result['errors']) return $result;
        $result['rows'] = $this->mapActorReferences($package['rows'], $result['identity_map'], $context['actor_columns']);
        $result['rows']['users'] = array_values($users);
        $result['rows']['roles'] = $destinationRoles;
        $result['rows']['user_roles'] = $memberships;
        // These source records must never grant reset, review or recovery authority.
        foreach (['password_reset_tokens', 'recovery_accounts', 'login_attempts', 'attention_states', 'backup_operations', 'store_write_gate'] as $table) $result['rows'][$table] = [];
        $reservedFiles = array_fill_keys(array_map('strtolower', $context['reserved_files']), true);
        foreach ($package['files'] as $path => $content) {
            $base = dirname($path) . '/rm_' . $origin . '_' . substr(hash('sha256', $path . $content), 0, 24);
            $candidate = $base . '.' . pathinfo($path, PATHINFO_EXTENSION); $suffix = 0;
            while (isset($reservedFiles[strtolower($candidate)])) $candidate = $base . '_' . ++$suffix . '.' . pathinfo($path, PATHINFO_EXTENSION);
            $result['file_map'][$path] = $candidate;
            $reservedFiles[strtolower($candidate)] = true;
        }
        foreach ($result['rows']['users'] as &$user) {
            if (isset($destById[$user['user_id']])) continue;
            if (!empty($user['profile_image'])) $user['profile_image'] = basename($result['file_map']['profile-images/' . basename($user['profile_image'])] ?? $user['profile_image']);
        }
        unset($user);
        foreach ($result['rows']['products'] ?? [] as $index => $product) {
            if (!empty($product['product_image'])) {
                $path = 'images/' . basename($product['product_image']);
                $result['rows']['products'][$index]['product_image'] = 'storage/' . ($result['file_map'][$path] ?? $path);
            }
        }
        $existingOrigins = $package['rows']['store_record_origins'] ?? [];
        $existingRecords = [];
        foreach ($existingOrigins as $entry) $existingRecords[$entry['table_name']][$entry['local_key']] = true;
        foreach ($result['source_users'] as $person) {
            $id = (int)$person['user_id'];
            $result['provenance'][] = ['origin_id' => $origin, 'table_name' => 'users', 'source_key' => (string)$id,
                'local_key' => (string)$result['identity_map'][$id], 'original_details' => json_encode($person, JSON_THROW_ON_ERROR)];
        }
        $primaryKeys = $context['primary_keys'];
        $keys = [];
        foreach ($primaryKeys as $key) $keys[$key['TABLE_NAME']][] = $key['COLUMN_NAME'];
        foreach ($package['rows'] as $table => $records) {
            if ($table === 'users' || $table === 'store_record_origins') continue;
            foreach ($records as $record) {
                $key = RecordProvenance::key(array_intersect_key($record, array_flip($keys[$table] ?? [])));
                if (isset($existingRecords[$table][$key])) continue;
                $result['provenance'][] = ['origin_id' => $origin, 'table_name' => $table, 'source_key' => $key, 'local_key' => $key, 'original_details' => null];
            }
        }
        foreach ($existingOrigins as &$entry) {
            if ($entry['table_name'] !== 'users') continue;
            if (!isset($result['identity_map'][$entry['local_key']])) throw new DomainException('The package contains an unresolved identity provenance record.');
            $entry['local_key'] = (string)$result['identity_map'][$entry['local_key']];
        }
        unset($entry);
        $result['rows']['store_record_origins'] = array_merge($existingOrigins, $result['provenance']);
        foreach ($result['rows']['users'] as $user) {
            if ($user['status'] !== 'active' || !empty($user['is_recovery_account']) || $user['role_id'] === null || in_array((int)$user['user_id'], $result['protected_user_ids'], true)) continue;
            $assigned = array_filter($memberships, static fn(array $membership): bool => (int)$membership['user_id'] === (int)$user['user_id']);
            if ($assigned !== []) {
                foreach ($assigned as $membership) if ((int)$membership['role_id'] === (int)($roles['admin'] ?? -1)) { $result['active_administrators']++; break; }
            } elseif ((int)$user['role_id'] === (int)($roles['admin'] ?? -1)) $result['active_administrators']++;
        }
        if ($result['active_administrators'] === 0) $result['errors'][] = 'The reviewed result needs an explicitly designated active ordinary Administrator. Prepare an eligible account or designate an imported ordinary person.';
        $result['ready'] = $result['errors'] === [];
        return $result;
    }

    private function validateUniqueUsers(array $users): void
    {
        $oldMode = (string)$this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->pdo->exec('SET SESSION sql_mode=' . $this->pdo->quote($oldMode . ',STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO'));
        $table = DatabaseSnapshotWriter::identifier('rm_identity_' . bin2hex(random_bytes(8)));
        try {
            $this->pdo->exec("CREATE TEMPORARY TABLE {$table} LIKE users");
            foreach ($users as $user) {
                $columns = implode(',', array_map([DatabaseSnapshotWriter::class, 'identifier'], array_keys($user)));
                $statement = $this->pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES (" . implode(',', array_fill(0, count($user), '?')) . ')');
                $statement->execute(array_values($user));
            }
        } catch (\PDOException $e) {
            throw new DomainException('Choose unused usernames and emails within the destination field limits. Matching values use the destination database collation.', 0, $e);
        } finally {
            $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$table}");
            $this->pdo->exec('SET SESSION sql_mode=' . $this->pdo->quote($oldMode));
        }
    }

    private function validateEmbeddedActors(array $rows): void
    {
        $users = array_fill_keys(array_column($rows['users'] ?? [], 'user_id'), true);
        $primaryKeys = $this->pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='PRIMARY' ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $keys = [];
        foreach ($primaryKeys as $key) $keys[$key['TABLE_NAME']][] = $key['COLUMN_NAME'];
        $recordOrigins = []; $originUsers = [];
        foreach ($rows['store_record_origins'] ?? [] as $entry) {
            if ($entry['table_name'] === 'users') {
                if (isset($users[$entry['local_key']])) $originUsers[$entry['origin_id']][$entry['source_key']] = true;
            } else {
                if (isset($recordOrigins[$entry['table_name']][$entry['local_key']]) && $recordOrigins[$entry['table_name']][$entry['local_key']] !== $entry['origin_id']) throw new DomainException('A historical record has ambiguous installation provenance.');
                $recordOrigins[$entry['table_name']][$entry['local_key']] = $entry['origin_id'];
            }
        }
        $visit = static function (array $payload, array $allowed) use (&$visit): void {
            foreach ($payload as $key => $value) {
                if (is_array($value)) { $visit($value, $allowed); continue; }
                if ($value !== null && preg_match('/^(?:user_id|actor_id|cashier_id|incoming_cashier_id|outgoing_cashier_id|target_user_id|.+_by)$/D', (string)$key)
                    && ctype_digit((string)$value) && (int)$value > 0 && !isset($allowed[$value])) {
                    throw new DomainException('The package contains an unresolved embedded historical actor.');
                }
            }
        };
        foreach ($rows as $table => $records) foreach ($records as $record) {
            $recordKey = RecordProvenance::key(array_intersect_key($record, array_flip($keys[$table] ?? [])));
            $originId = $recordOrigins[$table][$recordKey] ?? null;
            $allowed = $originId === null ? $users : ($originUsers[$originId] ?? []);
            foreach ($record as $column => $value) {
                if (!is_string($value) || !in_array($column, ['previous_value', 'new_value', 'metadata', 'old_values', 'new_values', 'receipt_details_json', 'details_json', 'setting_value'], true)) continue;
                $decoded = json_decode($value, true);
                if (is_array($decoded)) $visit($decoded, $allowed);
            }
        }
    }

    private function mapActorReferences(array $rows, array $map, array $references): array
    {
        foreach (['branches' => ['created_by'], 'sales' => ['discount_authorized_by'], 'inventory_adjustment_revisions' => ['actor_id']] as $table => $columns) foreach ($columns as $column) $references[] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $column];
        $unique = [];
        foreach ($references as $reference) $unique[$reference['TABLE_NAME'] . '.' . $reference['COLUMN_NAME']] = $reference;
        foreach ($unique as $reference) {
            $table = $reference['TABLE_NAME']; $column = $reference['COLUMN_NAME'];
            foreach ($rows[$table] ?? [] as $index => $row) {
                if (!isset($row[$column])) continue;
                if (!isset($map[$row[$column]])) throw new DomainException("Unresolved historical actor in {$table}.{$column}.");
                $rows[$table][$index][$column] = $map[$row[$column]];
            }
        }
        foreach ($rows['activity_log'] ?? [] as $index => $record) {
            if (in_array($record['module'] ?? '', ['User Access', 'Users', 'Recovery Account', 'Authentication'], true) && !empty($record['record_id'])) {
                if (!isset($map[$record['record_id']])) throw new DomainException('Unresolved audit account subject.');
                $rows['activity_log'][$index]['record_id'] = $map[$record['record_id']];
            }
        }
        return $rows;
    }
}
