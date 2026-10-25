<?php

namespace App\Backup;

use App\Authorization\RoleCapabilityPolicy;
use App\Services\UserLifecycleService;
use DomainException;
use PDO;

/** Read-only destination review, preservation closure, and security approval. */
final class IdentityReconciliationService
{
    private const PRESERVED_TABLES = [
        'recovery_accounts', 'platform_settings', 'attention_settings', 'ml_settings',
        'password_reset_tokens', 'notification_preferences', 'notifications',
        'attention_states', 'login_attempts', 'backup_history', 'email_delivery_log',
        'activity_log', 'store_record_origins'
    ];

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
        $destRows = [];
        foreach (self::PRESERVED_TABLES as $table) {
            try { $destRows[$table] = $this->pdo->query('SELECT * FROM ' . DatabaseSnapshotWriter::identifier($table))->fetchAll(PDO::FETCH_ASSOC); }
            catch (\PDOException) { $destRows[$table] = []; }
        }
        $context['destination_rows'] = $destRows;
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
        $context = $approved['context'] ?? [];
        if (!isset($context['destination_rows'])) {
            $context['destination_rows'] = array_intersect_key($checkpoint['rows'], array_flip(self::PRESERVED_TABLES));
        }
        if (!isset($context['memberships'])) {
            $context['memberships'] = $checkpoint['rows']['user_roles'] ?? [];
        }
        if (!isset($context['membership_columns'])) {
            $context['membership_columns'] = ['user_id', 'role_id', 'is_primary', 'assigned_at'];
        }
        if (!isset($context['recovery_user_ids'])) {
            $context['recovery_user_ids'] = array_column($checkpoint['rows']['recovery_accounts'] ?? [], 'user_id');
        }
        if (!isset($context['actor_columns'])) {
            $context['actor_columns'] = [];
        }
        if (!isset($context['reserved_files'])) {
            $context['reserved_files'] = StorePackageFormat::references([
                'users' => $checkpoint['rows']['users'] ?? [],
                'products' => $checkpoint['rows']['products'] ?? [],
            ]);
        }
        if (!isset($result['protected_user_ids'])) {
            $superRoleIds = [];
            foreach ($destinationRoles as $r) {
                if (($r['role_name'] ?? '') === 'super_admin') $superRoleIds[(int)$r['role_id']] = true;
            }
            $protected = [];
            foreach ($checkpoint['rows']['users'] ?? [] as $u) {
                $uid = (int)$u['user_id'];
                if (isset($superRoleIds[(int)($u['role_id'] ?? 0)]) || !empty($u['is_recovery_account'])) {
                    $protected[] = $uid;
                }
            }
            foreach ($context['memberships'] as $m) {
                if (isset($superRoleIds[(int)($m['role_id'] ?? 0)])) {
                    $protected[] = (int)$m['user_id'];
                }
            }
            foreach ($context['recovery_user_ids'] as $ruid) {
                $protected[] = (int)$ruid;
            }
            $result['protected_user_ids'] = array_values(array_unique($protected));
        }
        if (!isset($result['source_users'])) {
            $source = $package['rows'];
            $sourceRoles = array_column($source['roles'] ?? [], 'role_name', 'role_id');
            $sourceRecovery = array_fill_keys(array_column($source['recovery_accounts'] ?? [], 'user_id'), true);
            $result['source_users'] = [];
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
            }
        }
        $result = $this->project($package, $approved['decisions'], $result,
            array_column($checkpoint['rows']['users'], null, 'user_id'), array_column($destinationRoles, 'role_id', 'role_name'), $destinationRoles, $context);
        if ($result['identity_map'] !== $approved['identity_map'] || $result['file_map'] !== $approved['file_map']) {
            throw new DomainException('The approved identity or file mapping no longer matches the retained destination checkpoint.');
        }
        return $result;
    }

    public function destinationWatermark(): string
    {
        $users = $this->pdo->query('SELECT user_id, username, email, role_id, status, is_recovery_account FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
        $userRoles = $this->pdo->query('SELECT user_id, role_id, is_primary FROM user_roles ORDER BY user_id, role_id')->fetchAll(PDO::FETCH_ASSOC);
        $roles = $this->pdo->query('SELECT role_id, role_name FROM roles ORDER BY role_id')->fetchAll(PDO::FETCH_ASSOC);
        $recovery = $this->pdo->query('SELECT account_key, user_id FROM recovery_accounts ORDER BY account_key')->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256', json_encode([$users, $userRoles, $roles, $recovery], JSON_THROW_ON_ERROR));
    }

    public function approve(int $actorId, string $actorRole, #[\SensitiveParameter] string $loginPassword, #[\SensitiveParameter] array $package, array $decisions): array
    {
        $actor = $this->destinationActor($actorId);
        if ($actorRole !== 'super_admin' || $actor['status'] !== 'active' || !empty($actor['is_recovery_account']) || !$this->hasSuperAdminCapability($actorId)) {
            throw new DomainException('An active destination Super Administrator must approve identity reconciliation.');
        }
        if ($loginPassword === '' || !password_verify($loginPassword, (string)$actor['password_hash'])) {
            throw new DomainException('Your current destination login password is incorrect.');
        }
        $review = $this->review($actorId, $actorRole, $package, $decisions);
        if (!$review['ready']) {
            throw new DomainException(implode(' ', $review['errors']));
        }
        $approvalId = bin2hex(random_bytes(16));
        $evidence = [
            'approval_id' => $approvalId,
            'approved_by' => $actorId,
            'approved_by_role' => $actorRole,
            'approved_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'package_id' => $package['manifest']['package_id'],
            'origin_id' => $package['manifest']['origin_id'],
            'schema_hash' => $package['manifest']['schema'],
            'sql_sha256' => isset($package['sql']) ? hash('sha256', (string)$package['sql']) : null,
            'decisions' => $decisions,
            'identity_map' => $review['identity_map'],
            'file_map' => $review['file_map'],
            'active_administrators' => $review['active_administrators'],
            'administrator_designations' => array_values(array_map('intval', array_keys(array_filter($decisions, static fn(array $d): bool => !empty($d['administrator']))))),
            'resulting_access' => $review['resulting_access'],
            'preservation_closure' => $review['preservation_closure'],
            'destination_watermark' => $this->destinationWatermark(),
            'protected_user_ids' => $review['protected_user_ids'] ?? [],
            'source_users' => $review['source_users'] ?? [],
            'context' => $review['context'] ?? [],
            'stage' => 'reconciliation_approved',
        ];
        RecoveryStore::write('reconciliation-approval.json', json_encode($evidence, JSON_THROW_ON_ERROR));
        try {
            $stmt = $this->pdo->prepare("INSERT INTO activity_log (user_id, action, category, module, metadata) VALUES (?, 'Reconciliation approved', 'security', 'Platform Governance', ?)");
            $stmt->execute([$actorId, json_encode([
                'approval_id' => $approvalId,
                'package_id' => $package['manifest']['package_id'],
                'origin_id' => $package['manifest']['origin_id'],
                'active_administrators' => $review['active_administrators'],
            ], JSON_THROW_ON_ERROR)]);
        } catch (\PDOException) {}
        return ['approval' => $evidence, 'review' => $review];
    }

    public function recheck(int $actorId, string $actorRole, #[\SensitiveParameter] string $loginPassword, #[\SensitiveParameter] array $package, ?array $approval = null): array
    {
        $approval ??= $this->latestApproval();
        if ($approval === null) {
            throw new DomainException('No approved reconciliation preparation was found. Review and approve reconciliation first.');
        }
        $actor = $this->destinationActor($actorId);
        if ($actor['status'] !== 'active' || !empty($actor['is_recovery_account'])) {
            throw new DomainException('An active authorized destination account is required.');
        }
        if ($loginPassword === '' || !password_verify($loginPassword, (string)$actor['password_hash'])) {
            throw new DomainException('Your current destination login password is incorrect.');
        }
        $approver = $this->destinationActor((int)$approval['approved_by']);
        if ($approver['status'] !== 'active' || !empty($approver['is_recovery_account']) || !$this->hasSuperAdminCapability((int)$approval['approved_by'])) {
            $this->invalidateApproval();
            throw new DomainException('The Super Administrator who approved reconciliation no longer has required destination platform authority. Renewed reconciliation review is required.');
        }
        if (($package['manifest']['package_id'] ?? '') !== $approval['package_id']
            || ($package['manifest']['origin_id'] ?? '') !== $approval['origin_id']
            || ($package['manifest']['schema'] ?? '') !== $approval['schema_hash']
            || (isset($approval['sql_sha256']) && hash('sha256', (string)($package['sql'] ?? '')) !== $approval['sql_sha256'])) {
            $this->invalidateApproval();
            throw new DomainException('The package has changed since reconciliation was approved. Renewed reconciliation review is required.');
        }
        if (!hash_equals($approval['destination_watermark'], $this->destinationWatermark())) {
            $this->invalidateApproval();
            throw new DomainException('Destination security state or role configuration has changed since approval. Renewed reconciliation review is required.');
        }
        try {
            $rechecked = $this->review((int)$approval['approved_by'], 'super_admin', $package, $approval['decisions']);
        } catch (\Throwable) {
            $this->invalidateApproval();
            throw new DomainException('Reconciliation mappings are no longer valid against the current destination state. Renewed reconciliation review is required.');
        }
        if (!$rechecked['ready'] || $rechecked['identity_map'] !== $approval['identity_map'] || $rechecked['file_map'] !== $approval['file_map']) {
            $this->invalidateApproval();
            throw new DomainException('Reconciliation mappings are no longer valid against the current destination state. Renewed reconciliation review is required.');
        }
        return $rechecked;
    }

    public function latestApproval(): ?array
    {
        if (!RecoveryStore::isAvailable()) return null;
        $path = RecoveryStore::path('reconciliation-approval.json');
        if (!is_file($path)) return null;
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) && ($data['stage'] ?? '') === 'reconciliation_approved' ? $data : null;
    }

    public function invalidateApproval(): void
    {
        if (RecoveryStore::isAvailable() && is_file(RecoveryStore::path('reconciliation-approval.json'))) {
            unlink(RecoveryStore::path('reconciliation-approval.json'));
        }
    }

    private function destinationActor(int $actorId): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id, password_hash, status, is_recovery_account, role_id FROM users WHERE user_id=?');
        $stmt->execute([$actorId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$actor) throw new DomainException('Destination actor not found.');
        return $actor;
    }

    private function hasSuperAdminCapability(int $actorId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users u
            LEFT JOIN roles r ON r.role_id=u.role_id
            LEFT JOIN user_roles ur ON ur.user_id=u.user_id
            LEFT JOIN roles sr ON sr.role_id=ur.role_id
            WHERE u.user_id=? AND (r.role_name='super_admin' OR sr.role_name='super_admin')");
        $stmt->execute([$actorId]);
        return (bool)$stmt->fetchColumn();
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
        $result['rows']['roles'] = $destinationRoles;
        $result['rows']['user_roles'] = $memberships;

        $destRows = $context['destination_rows'] ?? [];

        // Destination security & configuration preservation (AC 2)
        $result['rows']['recovery_accounts'] = $destRows['recovery_accounts'] ?? [];
        $result['rows']['platform_settings'] = $destRows['platform_settings'] ?? [];
        $destPlatformAttention = array_values(array_filter($destRows['attention_settings'] ?? [], static fn(array $r): bool => ($r['setting_scope'] ?? '') === 'platform'));
        $pkgStoreAttention = array_values(array_filter($package['rows']['attention_settings'] ?? [], static fn(array $r): bool => ($r['setting_scope'] ?? '') === 'store'));
        $result['rows']['attention_settings'] = array_merge($destPlatformAttention, $pkgStoreAttention);
        $result['rows']['ml_settings'] = $destRows['ml_settings'] ?? [];
        $result['rows']['password_reset_tokens'] = array_values(array_filter($destRows['password_reset_tokens'] ?? [], static fn(array $r): bool => in_array((int)$r['user_id'], $result['protected_user_ids'], true)));

        $destProtectedPrefs = array_values(array_filter($destRows['notification_preferences'] ?? [], static fn(array $r): bool => in_array((int)$r['user_id'], $result['protected_user_ids'], true)));
        $destPrefIds = array_fill_keys(array_map('intval', array_column($destProtectedPrefs, 'pref_id')), true);
        $maxPrefId = max(array_merge([0], array_keys($destPrefIds)));
        $pkgPrefs = [];
        foreach ($package['rows']['notification_preferences'] ?? [] as $pref) {
            $srcUid = (int)$pref['user_id'];
            if (isset($result['identity_map'][$srcUid]) && !in_array($result['identity_map'][$srcUid], $result['protected_user_ids'], true)) {
                $pref['user_id'] = $result['identity_map'][$srcUid];
                $oldId = (int)$pref['pref_id'];
                if (isset($destPrefIds[$oldId])) $pref['pref_id'] = ++$maxPrefId;
                else $maxPrefId = max($maxPrefId, $oldId);
                $destPrefIds[(int)$pref['pref_id']] = true;
                $pkgPrefs[] = $pref;
            }
        }
        $result['rows']['notification_preferences'] = array_merge($destProtectedPrefs, $pkgPrefs);

        $destProtectedNotifs = array_values(array_filter($destRows['notifications'] ?? [], static fn(array $r): bool => in_array((int)$r['user_id'], $result['protected_user_ids'], true)));
        $destNotifIds = array_fill_keys(array_map('intval', array_column($destProtectedNotifs, 'notification_id')), true);
        $maxNotifId = max(array_merge([0], array_keys($destNotifIds)));
        $pkgNotifs = [];
        foreach ($package['rows']['notifications'] ?? [] as $notif) {
            $srcUid = (int)$notif['user_id'];
            if (isset($result['identity_map'][$srcUid])) {
                $notif['user_id'] = $result['identity_map'][$srcUid];
                $oldId = (int)$notif['notification_id'];
                if (isset($destNotifIds[$oldId])) $notif['notification_id'] = ++$maxNotifId;
                else $maxNotifId = max($maxNotifId, $oldId);
                $destNotifIds[(int)$notif['notification_id']] = true;
                $pkgNotifs[] = $notif;
            }
        }
        $result['rows']['notifications'] = array_merge($destProtectedNotifs, $pkgNotifs);

        $result['rows']['attention_states'] = array_values(array_filter($destRows['attention_states'] ?? [], static fn(array $r): bool => in_array((int)$r['user_id'], $result['protected_user_ids'], true)));
        $result['rows']['login_attempts'] = $destRows['login_attempts'] ?? [];

        // Backup & Email delivery evidence with collision-free IDs (AC 2, 3)
        $destBackup = $destRows['backup_history'] ?? [];
        $destBackupIds = array_fill_keys(array_map('intval', array_column($destBackup, 'backup_id')), true);
        $maxBackupId = max(array_merge([0], array_keys($destBackupIds)));
        $pkgBackup = [];
        foreach ($package['rows']['backup_history'] ?? [] as $hist) {
            $oldId = (int)$hist['backup_id'];
            if (isset($destBackupIds[$oldId])) $hist['backup_id'] = ++$maxBackupId;
            else $maxBackupId = max($maxBackupId, $oldId);
            if (isset($hist['performed_by'], $result['identity_map'][$hist['performed_by']])) {
                $hist['performed_by'] = $result['identity_map'][$hist['performed_by']];
            }
            $destBackupIds[(int)$hist['backup_id']] = true;
            $pkgBackup[] = $hist;
        }
        $result['rows']['backup_history'] = $pkgBackup;

        $destEmail = $destRows['email_delivery_log'] ?? [];
        $destEmailIds = array_fill_keys(array_map('intval', array_column($destEmail, 'email_log_id')), true);
        $maxEmailId = max(array_merge([0], array_keys($destEmailIds)));
        $pkgEmail = [];
        foreach ($package['rows']['email_delivery_log'] ?? [] as $log) {
            $oldId = (int)$log['email_log_id'];
            if (isset($destEmailIds[$oldId])) $log['email_log_id'] = ++$maxEmailId;
            else $maxEmailId = max($maxEmailId, $oldId);
            $destEmailIds[(int)$log['email_log_id']] = true;
            $pkgEmail[] = $log;
        }
        $result['rows']['email_delivery_log'] = $pkgEmail;

        // Classified activity log evidence preservation with collision-free IDs (AC 2, 3)
        $destActivity = array_values(array_filter($destRows['activity_log'] ?? [], static fn(array $r): bool =>
            in_array($r['category'] ?? '', ['security', 'recovery', 'platform_setting', 'recovery_account'], true)
            || in_array($r['module'] ?? '', ['User Access', 'Users', 'Recovery Account', 'Authentication', 'Platform Governance'], true)
        ));
        $destLogIds = array_fill_keys(array_map('intval', array_column($destActivity, 'log_id')), true);
        $maxLogId = max(array_merge([0], array_keys($destLogIds)));
        $pkgActivity = [];
        foreach ($result['rows']['activity_log'] ?? [] as $log) {
            $oldLogId = (int)$log['log_id'];
            if (isset($destLogIds[$oldLogId])) $log['log_id'] = ++$maxLogId;
            else $maxLogId = max($maxLogId, $oldLogId);
            $destLogIds[(int)$log['log_id']] = true;
            $pkgActivity[] = $log;
        }
        $result['rows']['activity_log'] = $pkgActivity;

        // Coordination state never restored
        $result['rows']['backup_operations'] = [];
        $result['rows']['store_write_gate'] = [];

        // Dependent actors and evidence closure (AC 2, 3)
        $referencedDestUsers = [];
        $scan = static function ($val) use (&$referencedDestUsers): void {
            if ($val !== null && ctype_digit((string)$val) && (int)$val > 0) $referencedDestUsers[(int)$val] = true;
        };
        foreach ($destPlatformAttention as $r) $scan($r['updated_by'] ?? null);
        foreach ($destRows['ml_settings'] ?? [] as $r) $scan($r['updated_by'] ?? null);
        foreach ($result['rows']['password_reset_tokens'] as $r) $scan($r['user_id'] ?? null);
        foreach ($destProtectedPrefs as $r) $scan($r['user_id'] ?? null);
        foreach ($destProtectedNotifs as $r) $scan($r['user_id'] ?? null);
        foreach ($result['rows']['attention_states'] as $r) $scan($r['user_id'] ?? null);
        foreach ($destBackup as $r) $scan($r['performed_by'] ?? null);
        foreach ($destActivity as $r) {
            $scan($r['user_id'] ?? null);
            if (in_array($r['module'] ?? '', ['User Access', 'Users', 'Recovery Account', 'Authentication', 'Platform Governance'], true)) {
                $scan($r['record_id'] ?? null);
            }
        }
        $evidenceActors = [];
        foreach (array_keys($referencedDestUsers) as $depId) {
            if (isset($destById[$depId]) && !isset($users[$depId])) {
                $dep = $destById[$depId];
                $dep['role_id'] = null;
                $dep['password_hash'] = '!';
                $dep['status'] = 'disabled';
                $dep['is_recovery_account'] = 0;
                $dep['session_version'] = 1;
                $dep['failed_login_attempts'] = 0;
                $dep['locked_until'] = null;
                $dep['last_login_at'] = null;
                $users[$depId] = $dep;
                $evidenceActors[$depId] = true;
                $result['provenance'][] = [
                    'origin_id' => 'destination',
                    'table_name' => 'users',
                    'source_key' => (string)$depId,
                    'local_key' => (string)$depId,
                    'original_details' => json_encode(['role' => 'evidence_only', 'user_id' => $depId], JSON_THROW_ON_ERROR),
                ];
            }
        }
        $result['rows']['users'] = array_values($users);

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
        $destOrigins = $destRows['store_record_origins'] ?? [];
        $existingOrigins = $package['rows']['store_record_origins'] ?? [];
        $existingRecords = [];
        foreach (array_merge($destOrigins, $existingOrigins) as $entry) $existingRecords[$entry['table_name']][$entry['local_key']] = true;
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
            $sourceRecords = $result['rows'][$table] ?? $records;
            foreach ($records as $index => $record) {
                $sourceKey = RecordProvenance::key(array_intersect_key($record, array_flip($keys[$table] ?? [])));
                $localRecord = $sourceRecords[$index] ?? $record;
                $localKey = RecordProvenance::key(array_intersect_key($localRecord, array_flip($keys[$table] ?? [])));
                if (isset($existingRecords[$table][$localKey])) continue;
                $result['provenance'][] = ['origin_id' => $origin, 'table_name' => $table, 'source_key' => $sourceKey, 'local_key' => $localKey, 'original_details' => null];
            }
        }
        foreach ($existingOrigins as &$entry) {
            if ($entry['table_name'] !== 'users') continue;
            if (!isset($result['identity_map'][$entry['local_key']])) throw new DomainException('The package contains an unresolved identity provenance record.');
            $entry['local_key'] = (string)$result['identity_map'][$entry['local_key']];
        }
        unset($entry);
        $allOrigins = [];
        foreach (array_merge($destOrigins, $existingOrigins, $result['provenance']) as $entry) {
            $allOrigins[$entry['origin_id'] . '|' . $entry['table_name'] . '|' . $entry['source_key']] = $entry;
        }
        $result['rows']['store_record_origins'] = array_values($allOrigins);
        $result['rows']['activity_log'] = array_merge($result['rows']['activity_log'], $destActivity);
        $result['rows']['backup_history'] = array_merge($result['rows']['backup_history'], $destBackup);
        $result['rows']['email_delivery_log'] = array_merge($result['rows']['email_delivery_log'], $destEmail);
        foreach ($result['rows']['users'] as $user) {
            if ($user['status'] !== 'active' || !empty($user['is_recovery_account']) || $user['role_id'] === null || in_array((int)$user['user_id'], $result['protected_user_ids'], true)) continue;
            $assigned = array_filter($memberships, static fn(array $membership): bool => (int)$membership['user_id'] === (int)$user['user_id']);
            if ($assigned !== []) {
                foreach ($assigned as $membership) if ((int)$membership['role_id'] === (int)($roles['admin'] ?? -1)) { $result['active_administrators']++; break; }
            } elseif ((int)$user['role_id'] === (int)($roles['admin'] ?? -1)) $result['active_administrators']++;
        }
        if ($result['active_administrators'] === 0) $result['errors'][] = 'The reviewed result needs an explicitly designated active ordinary Administrator. Prepare an eligible account or designate an imported ordinary person.';

        // Resulting access summary and destination preservation closure (AC 1, 2, 3)
        $rolesById = array_column($destinationRoles, 'role_name', 'role_id');
        $accessSummary = [];
        foreach ($result['rows']['users'] as $u) {
            $uid = (int)$u['user_id'];
            $hasAccess = $u['status'] === 'active' && $u['role_id'] !== null && $u['password_hash'] !== '!';
            $assigned = [];
            if ($hasAccess) {
                foreach ($memberships as $m) if ((int)$m['user_id'] === $uid && isset($rolesById[$m['role_id']])) $assigned[] = $rolesById[$m['role_id']];
                if ($assigned === [] && isset($rolesById[$u['role_id']])) $assigned[] = $rolesById[$u['role_id']];
            }
            $accessSummary[] = [
                'user_id' => $uid,
                'username' => $u['username'],
                'status' => $u['status'],
                'usable_access' => $hasAccess,
                'roles' => array_values(array_unique($assigned)),
                'classification' => in_array($uid, $result['protected_user_ids'], true)
                    ? 'protected'
                    : (isset($evidenceActors[$uid]) ? 'evidence_only' : (isset($destById[$uid]) ? 'destination_linked' : ($u['role_id'] === null ? 'historical' : 'imported_distinct'))),
            ];
        }
        $result['resulting_access'] = $accessSummary;
        $result['preservation_closure'] = [
            'protected_users' => array_values(array_filter($accessSummary, static fn($a) => $a['classification'] === 'protected')),
            'linked_users' => array_values(array_filter($accessSummary, static fn($a) => $a['classification'] === 'destination_linked')),
            'evidence_actors' => array_values(array_filter($accessSummary, static fn($a) => $a['classification'] === 'evidence_only')),
            'historical_actors' => array_values(array_filter($accessSummary, static fn($a) => $a['classification'] === 'historical')),
            'imported_users' => array_values(array_filter($accessSummary, static fn($a) => $a['classification'] === 'imported_distinct')),
            'counts' => [
                'recovery_accounts' => count($result['rows']['recovery_accounts']),
                'platform_settings' => count($result['rows']['platform_settings']),
                'platform_attention_settings' => count($destPlatformAttention),
                'ml_settings' => count($result['rows']['ml_settings']),
                'activity_log_evidence' => count($destActivity),
                'login_attempts' => count($result['rows']['login_attempts']),
                'password_reset_tokens' => count($result['rows']['password_reset_tokens']),
                'notification_preferences' => count($destProtectedPrefs),
                'notifications' => count($destProtectedNotifs),
                'attention_states' => count($result['rows']['attention_states']),
                'backup_history' => count($destBackup),
                'email_delivery_log' => count($destEmail),
            ],
        ];

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
