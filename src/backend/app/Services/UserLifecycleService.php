<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\AdministratorAccessMinimum;
use App\Authorization\RoleCapabilityPolicy;
use App\Store\StoreScope;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

final class UserLifecycleService
{
    public const DELETION_HISTORY_MESSAGE = 'This account has retained business or audit history and cannot be permanently deleted. Disable it instead where permitted.';
    private const DISPOSABLE_USER_TABLES = ['user_roles', 'password_reset_tokens', 'notification_preferences', 'notifications', 'attention_states'];
    private ?array $identityReferences = null;

    public function __construct(
        private PDO $pdo,
        private RoleCapabilityPolicy $policy,
        private StoreScope $storeScope,
        private ?UserAccountNotifier $notifier = null
    ) {
        $this->notifier = $notifier ?? new UserAccountNotifier();
    }

    public function get(int $userId, bool $lock = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.user_id, u.full_name, u.username, u.email, u.profile_image, u.password_hash,
                    u.status, u.disabled_at, u.session_version, u.must_change_password, u.is_recovery_account,
                    u.role_id, u.branch_id, r.role_name
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ?' . ($lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '')
        );
        $statement->execute([$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new InvalidArgumentException('User not found.');
        }
        $user['roles'] = $this->roleNamesForUser($userId, (string)$user['role_name'], $lock);
        return $user;
    }

    /**
     * Username shape rule (ticket #66): a `@` in the login box unambiguously
     * means Email Address, so new Usernames reject `@` and whitespace.
     * Letters, numbers, `.`, `_`, `-` keep working. Returns the easy inline
     * message when the value breaks the rule, or null when it is allowed.
     */
    public static function usernameShapeError(string $username): ?string
    {
        if (str_contains($username, '@') || preg_match('/\s/', $username) === 1) {
            return 'Usernames cannot contain @ or spaces. Use letters, numbers, dots, underscores, and hyphens.';
        }
        return null;
    }

    public function availability(string $actorRole, string $username, string $email): array
    {
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::MANAGE_USERS)) {
            throw new DomainException('Your account cannot check Store account availability.');
        }
        return [
            'username_taken' => $this->existsByColumn('username', $username),
            'email_taken' => $this->existsByColumn('email', $email),
        ];
    }

    private function existsByColumn(string $column, string $value): bool
    {
        if ($column !== 'username' && $column !== 'email') {
            throw new InvalidArgumentException('Unknown availability field.');
        }
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        $statement = $this->pdo->prepare("SELECT 1 FROM users WHERE LOWER(TRIM({$column})) = LOWER(?) LIMIT 1");
        $statement->execute([$value]);
        return $statement->fetchColumn() !== false;
    }

    public function create(int $actorId, string $actorRole, array $account): int
    {
        $targetRoles = $this->requireAssignableRoles($actorRole, $account['roles'] ?? ($account['role'] ?? ''));
        if ($actorRole === 'super_admin' && $targetRoles !== ['admin']) {
            throw new DomainException('Super Administrators may only create ordinary Administrator accounts.');
        }
        $targetRole = $targetRoles[0];
        $fullName = trim((string)($account['full_name'] ?? ''));
        $username = trim((string)($account['username'] ?? ''));
        $passwordHash = (string)($account['password_hash'] ?? '');
        if ($fullName === '' || $username === '' || $passwordHash === '') {
            throw new InvalidArgumentException('Full name, username, and password are required.');
        }
        if ($shapeError = self::usernameShapeError($username)) {
            throw new InvalidArgumentException($shapeError);
        }

        return $this->transaction(function () use ($actorId, $actorRole, $account, $targetRoles, $targetRole, $fullName, $username, $passwordHash): int {
            $roleId = $this->roleId($targetRole);
            $storeId = $this->storeScope->id();
            $statement = $this->pdo->prepare(
                'INSERT INTO users (full_name, username, email, profile_image, password_hash, role_id, branch_id, must_change_password)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $statement->execute([
                $fullName,
                $username,
                $this->nullableString($account['email'] ?? null),
                $this->nullableString($account['profile_image'] ?? null),
                $passwordHash,
                $roleId,
                $storeId,
            ]);
            $userId = (int)$this->pdo->lastInsertId();
            $this->syncUserRoles($userId, $targetRoles, $targetRole);
            $this->audit($actorId, $actorRole, 'User created', $userId, null, $this->auditSnapshot($this->get($userId)));
            return $userId;
        });
    }

    public function update(int $actorId, string $actorRole, int $userId, array $account): array
    {
        if ($actorRole === 'super_admin' && $actorId !== $userId) {
            throw new DomainException('Super Administrators cannot edit staff profiles, passwords, or role assignments.');
        }

        return $this->transaction(function () use ($actorId, $actorRole, $userId, $account): array {
            $minimum = new AdministratorAccessMinimum($this->pdo);
            $minimum->lock();
            $before = $this->get($userId, true);
            $this->requireManageable($actorId, $actorRole, $before, true);

            $targetRoles = $account['roles'] ?? ($account['role'] ?? $before['roles']);
            if (in_array('super_admin', array_merge([$before['role_name']], $before['roles']), true)) {
                $targetRole = 'super_admin';
                $optionalRoles = array_values(array_diff((array)$targetRoles, ['super_admin']));
                $targetRoles = array_merge(['super_admin'], $optionalRoles === []
                    ? [] : $this->requireAssignableRoles($actorRole, $optionalRoles));
            } else {
                $targetRoles = $this->requireAssignableRoles($actorRole, $targetRoles);
                $targetRole = $targetRoles[0];
                if ($actorId === $userId && $targetRoles !== $before['roles']) {
                    throw new DomainException('You cannot change your own role access.');
                }
            }
            if (!in_array('admin', $targetRoles, true)) {
                $minimum->requireRemovable($userId);
            }
            $fullName = trim((string)($account['full_name'] ?? ''));
            $username = trim((string)($account['username'] ?? ''));
            if ($fullName === '' || $username === '') {
                throw new InvalidArgumentException('Full name and username are required.');
            }
            if ($shapeError = self::usernameShapeError($username)) {
                throw new InvalidArgumentException($shapeError);
            }

            $parts = ['full_name = ?', 'username = ?', 'email = ?', 'role_id = ?', 'branch_id = ?'];
            $params = [
                $fullName,
                $username,
                $this->nullableString($account['email'] ?? null),
                $this->roleId($targetRole),
                $this->storeScope->id(),
            ];
            if (array_key_exists('profile_image', $account)) {
                $parts[] = 'profile_image = ?';
                $params[] = $this->nullableString($account['profile_image']);
            }
            if ($targetRole !== $before['role_name'] || $targetRoles !== $before['roles']) {
                $parts[] = 'session_version = session_version + 1';
            }
            $params[] = $userId;
            $statement = $this->pdo->prepare('UPDATE users SET ' . implode(', ', $parts) . ' WHERE user_id = ?');
            $statement->execute($params);
            $this->syncUserRoles($userId, $targetRoles, $targetRole);

            $after = $this->get($userId);
            $this->audit($actorId, $actorRole, 'User updated', $userId, $this->auditSnapshot($before), $this->auditSnapshot($after));
            return $after;
        });
    }

    public function setStatus(int $actorId, string $actorRole, int $userId, string $status): array
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new InvalidArgumentException('Invalid account status.');
        }
        if ($actorRole === 'super_admin' && $status === 'active') {
            throw new DomainException('Super Administrators cannot re-enable staff accounts.');
        }

        return $this->transaction(function () use ($actorId, $actorRole, $userId, $status): array {
            $minimum = new AdministratorAccessMinimum($this->pdo);
            $minimum->lock();
            $before = $this->get($userId, true);
            $this->requireManageable($actorId, $actorRole, $before, false);
            $changed = $before['status'] !== $status;
            if ($changed && $status === 'disabled') {
                $minimum->requireRemovable($userId);
            }
            if ($changed) {
                if ($status === 'disabled') {
                    $statement = $this->pdo->prepare(
                        'UPDATE users SET status = ?, disabled_at = CURRENT_TIMESTAMP, session_version = session_version + 1 WHERE user_id = ?'
                    );
                } else {
                    $statement = $this->pdo->prepare(
                        'UPDATE users SET status = ?, disabled_at = NULL, session_version = session_version + 1 WHERE user_id = ?'
                    );
                }
                $statement->execute([$status, $userId]);
            }
            $after = $this->get($userId);
            $after['pending_duties'] = $this->pendingDuties($userId);
            if ($changed) {
                if ($status === 'disabled') {
                    $this->notifier->disabledNotice($after['email'] ?? null);
                } else {
                    $this->notifier->enabledNotice($after['email'] ?? null);
                }
            }
            $action = $status === 'disabled' ? 'User disabled' : 'User enabled';
            $this->audit($actorId, $actorRole, $action, $userId, $this->auditSnapshot($before), $this->auditSnapshot($after));
            return $after;
        });
    }

    public function resetPassword(int $actorId, string $actorRole, int $userId, string $passwordHash): void
    {
        if ($passwordHash === '') {
            throw new InvalidArgumentException('A password is required.');
        }
        if ($actorRole === 'super_admin' && $actorId !== $userId) {
            throw new DomainException('Super Administrators cannot reset staff passwords.');
        }
        $this->transaction(function () use ($actorId, $actorRole, $userId, $passwordHash): void {
            $before = $this->get($userId);
            $this->requireManageable($actorId, $actorRole, $before, true);
            $statement = $this->pdo->prepare(
                'UPDATE users SET password_hash = ?, password_changed_at = CURRENT_TIMESTAMP,
                    must_change_password = 1, session_version = session_version + 1 WHERE user_id = ?'
            );
            $statement->execute([$passwordHash, $userId]);
            $this->audit($actorId, $actorRole, 'Password reset', $userId, null, [
                'target_role' => $before['role_name'],
                'roles' => $before['roles'],
                'sessions_revoked' => true,
                'password_change_required' => true,
            ]);
        });
    }

    public function revokeSessions(int $actorId, string $actorRole, int $userId): void
    {
        $this->transaction(function () use ($actorId, $actorRole, $userId): void {
            $target = $this->get($userId);
            $this->requireManageable($actorId, $actorRole, $target, false);
            $this->pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE user_id = ?')->execute([$userId]);
            $this->audit($actorId, $actorRole, 'Sessions revoked', $userId, null, [
                'target_role' => $target['role_name'],
                'roles' => $target['roles'],
                'sessions_revoked' => true,
            ]);
        });
    }

    public function pendingDuties(int $userId): array
    {
        $openShifts = [];
        $openGcash = [];
        $handoverLinks = [];

        try {
            $stmt = $this->pdo->prepare("SELECT shift_id, register_id, opened_at FROM cashier_shifts WHERE cashier_id = ? AND status = 'open'");
            $stmt->execute([$userId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $shiftId = (int)$row['shift_id'];
                $url = "components/administrator/shift_report.php?shift_id={$shiftId}";
                $openShifts[] = [
                    'shift_id' => $shiftId,
                    'register_id' => (int)($row['register_id'] ?? 1),
                    'opened_at' => $row['opened_at'],
                    'handover_url' => $url,
                ];
                $handoverLinks[] = [
                    'type' => 'shift',
                    'label' => "Open Shift #{$shiftId}",
                    'url' => $url,
                ];
            }
        } catch (Throwable) {
        }

        try {
            $stmt = $this->pdo->prepare("SELECT session_id, shift_id, opened_at FROM gcash_service_sessions WHERE cashier_id = ? AND status = 'open'");
            $stmt->execute([$userId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sessionId = (int)$row['session_id'];
                $url = "components/administrator/gcash_overview.php?session_id={$sessionId}";
                $openGcash[] = [
                    'session_id' => $sessionId,
                    'shift_id' => (int)$row['shift_id'],
                    'opened_at' => $row['opened_at'],
                    'handover_url' => $url,
                ];
                $handoverLinks[] = [
                    'type' => 'gcash',
                    'label' => "Open GCash Session #{$sessionId}",
                    'url' => $url,
                ];
            }
        } catch (Throwable) {
        }

        return [
            'has_pending_duties' => (!empty($openShifts) || !empty($openGcash)),
            'open_shifts' => $openShifts,
            'open_gcash_sessions' => $openGcash,
            'handover_links' => $handoverLinks,
        ];
    }

    public function deletionRestriction(int $actorId, string $actorRole, int $userId): ?string
    {
        try {
            if ($actorId === $userId) throw new DomainException('You cannot permanently delete your own account.');
            $target = $this->get($userId);
            $this->requireDeletionActor($actorId, $actorRole);
            $this->requireManageable($actorId, $actorRole, $target, false);
            (new AdministratorAccessMinimum($this->pdo))->requireRemovable($userId);
            return $this->hasRetainedHistory($target, false) ? self::DELETION_HISTORY_MESSAGE : null;
        } catch (DomainException $exception) {
            return $exception->getMessage();
        }
    }

    public function delete(int $actorId, string $actorRole, int $userId, bool $confirmed = false): void
    {
        if (!$confirmed) throw new DomainException('Confirm permanent account deletion first.');
        $outerTransaction = $this->pdo->inTransaction();
        if ($outerTransaction) $this->pdo->exec('SAVEPOINT user_deletion');
        try {
            $this->transaction(function () use ($actorId, $actorRole, $userId): void {
                $minimum = new AdministratorAccessMinimum($this->pdo);
                $minimum->lock();
                $this->requireDeletionActor($actorId, $actorRole, true);
                if ($actorId === $userId) throw new DomainException('You cannot permanently delete your own account.');
                $before = $this->get($userId, true);
                $this->requireManageable($actorId, $actorRole, $before, false);
                $minimum->requireRemovable($userId);
                if ($this->hasRetainedHistory($before, true)) throw new DomainException(self::DELETION_HISTORY_MESSAGE);
                foreach (self::DISPOSABLE_USER_TABLES as $table) {
                    $this->pdo->prepare("DELETE FROM `{$table}` WHERE user_id = ?")->execute([$userId]);
                }
                $this->pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
                $snapshot = $this->auditSnapshot($before);
                $snapshot['full_name'] = (string)$before['full_name'];
                $snapshot['email'] = $before['email'];
                $this->audit($actorId, $actorRole, 'User permanently deleted', $userId, $snapshot, ['sessions_revoked' => true]);
            });
            if ($outerTransaction) $this->pdo->exec('RELEASE SAVEPOINT user_deletion');
        } catch (Throwable $exception) {
            if ($outerTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT user_deletion');
            }
            throw $exception;
        }
    }

    private function requireDeletionActor(int $actorId, string $actorRole, bool $lock = false): void
    {
        $actor = $this->get($actorId, $lock);
        if ($actor['status'] !== 'active' || (bool)$actor['is_recovery_account']
            || !in_array($actorRole, array_merge([$actor['role_name']], $actor['roles']), true)
            || !$this->policy->allows($actorRole, RoleCapabilityPolicy::MANAGE_USERS)) {
            throw new DomainException('Your active Workspace cannot permanently delete accounts.');
        }
    }

    private function hasRetainedHistory(array $target, bool $lock): bool
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        if ($this->identityReferences === null) {
            // Include SET NULL and CASCADE relations: neither makes business history disposable.
            $this->identityReferences = $this->pdo->query("SELECT DISTINCT TABLE_NAME, COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'user_id'
                UNION SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND (
                    (TABLE_NAME = 'branches' AND COLUMN_NAME = 'created_by') OR
                    (TABLE_NAME = 'sales' AND COLUMN_NAME = 'discount_authorized_by') OR
                    (TABLE_NAME = 'inventory_adjustment_revisions' AND COLUMN_NAME = 'actor_id') OR
                    (TABLE_NAME = 'backup_operations' AND COLUMN_NAME = 'requested_by') OR
                    (TABLE_NAME = 'store_write_gate' AND COLUMN_NAME = 'paused_by'))")->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($this->identityReferences as $reference) {
            $table = $reference['TABLE_NAME'];
            if (in_array($table, self::DISPOSABLE_USER_TABLES, true)) continue;
            $table = str_replace('`', '``', $table);
            $column = str_replace('`', '``', $reference['COLUMN_NAME']);
            $statement = $this->pdo->prepare("SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1{$suffix}");
            $statement->execute([(int)$target['user_id']]);
            if ($statement->fetchColumn() !== false) return true;
        }
        // Audit payloads contain logical identities, including nested shift/GCash actors.
        $idPattern = '"([a-z_]*(user_id|cashier_id|actor_id)|created_by|updated_by|reported_by|approved_by|reviewed_by|recorded_by|requested_by|assigned_to|decided_by|received_by|counted_by|moved_by|pulled_by|performed_by|verified_by|closed_by|discarded_by|[a-z_]*authorized_by|waived_by|activated_by|rolled_back_by|suspended_by)"[[:space:]]*:[[:space:]]*"?'
            . (int)$target['user_id'] . '"?([,}[:space:]]|$)';
        $identityPatterns = [];
        foreach (array_filter([$target['username'], $target['email']]) as $identity) {
            $identityPatterns[] = '"(username|email)"[[:space:]]*:[[:space:]]*' . preg_quote(json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '~');
        }
        $pattern = $idPattern . '|"identity"[[:space:]]*:[[:space:]]*"user:' . (int)$target['user_id'] . '"|' . implode('|', $identityPatterns);
        $statement = $this->pdo->prepare("SELECT 1 FROM activity_log
            WHERE ((module IN ('User Access', 'Users', 'Recovery Account')
                    OR (module = 'Authentication' AND action IN ('Login success', 'Login failure'))) AND record_id = ?)
                OR previous_value REGEXP ? OR new_value REGEXP ? OR metadata REGEXP ? LIMIT 1{$suffix}");
        $statement->execute([(int)$target['user_id'], $pattern, $pattern, $pattern]);
        if ($statement->fetchColumn() !== false) return true;
        $statement = $this->pdo->prepare("SELECT 1 FROM platform_settings WHERE setting_value REGEXP ? LIMIT 1{$suffix}");
        $statement->execute([$pattern]);
        if ($statement->fetchColumn() !== false) return true;
        $statement = $this->pdo->prepare("SELECT 1 FROM inventory_adjustment_revisions WHERE old_values REGEXP ? OR new_values REGEXP ? LIMIT 1{$suffix}");
        $statement->execute([$pattern, $pattern]);
        if ($statement->fetchColumn() !== false) return true;
        $statement = $this->pdo->prepare("SELECT 1 FROM login_attempts WHERE LOWER(TRIM(username)) IN (LOWER(?), LOWER(?), ?, LOWER(?), LOWER(?)) LIMIT 1{$suffix}");
        $statement->execute([$target['username'], $target['email'], 'user:' . $target['user_id'], 'unknown:' . $target['username'], $target['email'] ? 'unknown:' . $target['email'] : null]);
        if ($statement->fetchColumn() !== false) return true;
        $statement = $this->pdo->prepare("SELECT 1 FROM email_delivery_log WHERE LOWER(TRIM(recipient)) = LOWER(?) LIMIT 1{$suffix}");
        $statement->execute([$target['email']]);
        if ($statement->fetchColumn() !== false) return true;
        if (!\App\Backup\RecoveryStore::isAvailable()) {
            throw new DomainException('Recovery audit history cannot be checked on this hosting plan. Permanent deletion is unavailable.');
        }
        foreach (['restore-log.jsonl', 'restore-state.json'] as $name) {
            $path = \App\Backup\RecoveryStore::path($name);
            if (!is_file($path)) continue;
            $handle = fopen($path, 'rb');
            if ($handle === false) throw new DomainException('Recovery audit history cannot be checked. Try again later.');
            try {
                while (($line = fgets($handle)) !== false) {
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if ((int)($record['actor_id'] ?? 0) === (int)$target['user_id']) return true;
                }
                if (!feof($handle)) throw new DomainException('Recovery audit history cannot be checked. Try again later.');
            } finally { fclose($handle); }
        }
        return false;
    }

    private function requireAssignableRole(string $actorRole, string $targetRole): string
    {
        if ($targetRole === '' || $targetRole === 'super_admin' || $targetRole === 'seller'
            || !$this->policy->allows($actorRole, RoleCapabilityPolicy::ASSIGN_ROLES, $targetRole)
        ) {
            throw new DomainException('Your account cannot assign the selected role.');
        }
        return $targetRole;
    }

    private function requireAssignableRoles(string $actorRole, mixed $targetRoles): array
    {
        $roles = is_array($targetRoles) ? $targetRoles : [$targetRoles];
        $roles = array_values(array_unique(array_filter(array_map(static fn($role): string => trim((string)$role), $roles))));
        if ($roles === []) {
            throw new DomainException('Please choose at least one role.');
        }
        foreach ($roles as $role) {
            $this->requireAssignableRole($actorRole, $role);
        }
        return $roles;
    }

    public function canManageAccount(int $actorId, string $actorRole, array $target): bool
    {
        try {
            $this->requireManageable($actorId, $actorRole, $target, true);
            return true;
        } catch (DomainException) {
            return false;
        }
    }

    public function canViewPicture(int $actorId, string $actorRole, array $target): bool
    {
        return !(bool)($target['is_recovery_account'] ?? false)
            && ($actorId === (int)$target['user_id'] || $this->canManageAccount($actorId, $actorRole, $target));
    }

    public function removePicture(int $actorId, string $actorRole, int $userId, ProfileImageStorage $storage): array
    {
        // Own the commit so storage can restore the old bytes if the commit fails.
        if ($this->pdo->inTransaction()) {
            throw new DomainException('Finish the current account change before removing its picture.');
        }
        $this->pdo->beginTransaction();
        try {
            if ((bool)$this->get($actorId)['is_recovery_account']) {
                throw new DomainException('Recovery Account picture management is unavailable.');
            }
            $before = $this->get($userId, true);
            $this->requireManageable($actorId, $actorRole, $before, true);
            $storage->remove($before['profile_image'], function () use ($actorId, $actorRole, $userId, $before): void {
                $this->pdo->prepare('UPDATE users SET profile_image = NULL WHERE user_id = ?')->execute([$userId]);
                $this->audit($actorId, $actorRole, 'User profile picture removal', $userId,
                    $this->auditSnapshot($before), $this->auditSnapshot($this->get($userId)));
            }, fn() => $this->pdo->commit());
            return $this->get($userId);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function requireManageable(int $actorId, string $actorRole, array $target, bool $allowProtectedSelf): void
    {
        if ((bool)($target['is_recovery_account'] ?? false)) {
            throw new DomainException('The Recovery Account can be managed only through the offline recovery procedure.');
        }
        $targetRoles = array_values(array_unique(array_merge([$target['role_name']], array_map('strval', $target['roles'] ?? []))));
        if ($actorId !== (int)$target['user_id'] && in_array('super_admin', $targetRoles, true)) {
            throw new DomainException('This privileged account is protected.');
        }
        if ($actorRole === 'super_admin' && $actorId !== (int)$target['user_id']) {
            if (!in_array('admin', $targetRoles, true)) {
                throw new DomainException('Super Administrators cannot manage staff accounts without Administrator access.');
            }
        }
        foreach ($targetRoles as $targetRole) {
            if ($actorRole === 'super_admin' && in_array('admin', $targetRoles, true) && $targetRole !== 'super_admin') {
                continue;
            }
            if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::MANAGE_USERS, $targetRole)) {
                throw new DomainException('Your account cannot manage this privileged user.');
            }
        }
        $targetRole = (string)$target['role_name'];
        if (in_array('super_admin', $targetRoles, true) && (!$allowProtectedSelf || $actorId !== (int)$target['user_id'])) {
            throw new DomainException('The Super Administrator account is protected.');
        }
        if (!$allowProtectedSelf && $actorId === (int)$target['user_id']) {
            throw new DomainException('You cannot revoke or disable your current session.');
        }
    }

    private function roleId(string $role): int
    {
        $statement = $this->pdo->prepare("SELECT role_id FROM roles WHERE role_name = ? AND role_name <> 'seller'");
        $statement->execute([$role]);
        $roleId = (int)$statement->fetchColumn();
        if ($roleId <= 0) {
            throw new InvalidArgumentException('Please choose a valid role.');
        }
        return $roleId;
    }

    private function roleNamesForUser(int $userId, string $fallbackRole, bool $lock = false): array
    {
        try {
            $statement = $this->pdo->prepare(
                "SELECT r.role_name
                 FROM user_roles ur
                 JOIN roles r ON r.role_id = ur.role_id
                 WHERE ur.user_id = ? AND r.role_name <> 'seller'
                 ORDER BY ur.is_primary DESC, r.role_id" . ($lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '')
            );
            $statement->execute([$userId]);
            $roles = array_values(array_unique(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN))));
            if ($roles !== []) {
                return $roles;
            }
        } catch (Throwable $exception) {
            if ($lock) throw $exception;
        }
        return [$fallbackRole];
    }

    private function syncUserRoles(int $userId, array $roles, string $primaryRole): void
    {
        $this->pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
        $statement = $this->pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (?, ?, ?)'
        );
        foreach ($roles as $role) {
            $statement->execute([$userId, $this->roleId($role), $role === $primaryRole ? 1 : 0]);
        }
    }

    private function audit(int $actorId, string $actorRole, string $action, int $recordId, ?array $before, ?array $after): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $payload = $after ?? [];
        $payload['actor_role'] = $actorRole;
        $targetRoles = array_merge(
            (array)($before['roles'] ?? []),
            [(string)($before['role'] ?? $before['target_role'] ?? '')],
            (array)($payload['roles'] ?? []),
            [(string)($payload['role'] ?? $payload['target_role'] ?? '')]
        );
        $category = in_array('super_admin', $targetRoles, true)
            ? AuditRecordCategory::SECURITY
            : AuditRecordCategory::STORE_OPERATION;
        $statement->execute([
            $actorId,
            $action,
            $category,
            'User Access',
            $recordId,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'system',
        ]);
    }

    private function auditSnapshot(array $user): array
    {
        return [
            'user_id' => (int)$user['user_id'],
            'username' => (string)$user['username'],
            'role' => (string)$user['role_name'],
            'roles' => array_values(array_map('strval', $user['roles'] ?? [$user['role_name']])),
            'status' => (string)$user['status'],
            'store_id' => $user['branch_id'] !== null ? (int)$user['branch_id'] : null,
        ];
    }

    private function nullableString($value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function transaction(callable $operation)
    {
        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($started) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
