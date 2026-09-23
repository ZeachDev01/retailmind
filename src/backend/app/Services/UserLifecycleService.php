<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use App\Store\StoreScope;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

final class UserLifecycleService
{
    public function __construct(
        private PDO $pdo,
        private RoleCapabilityPolicy $policy,
        private StoreScope $storeScope
    ) {
    }

    public function get(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.user_id, u.full_name, u.username, u.email, u.profile_image, u.password_hash,
                    u.status, u.session_version, u.must_change_password, u.is_recovery_account,
                    u.role_id, u.branch_id, r.role_name
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ?'
        );
        $statement->execute([$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new InvalidArgumentException('User not found.');
        }
        return $user;
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
        $targetRole = $this->requireAssignableRole($actorRole, (string)($account['role'] ?? ''));
        $fullName = trim((string)($account['full_name'] ?? ''));
        $username = trim((string)($account['username'] ?? ''));
        $passwordHash = (string)($account['password_hash'] ?? '');
        if ($fullName === '' || $username === '' || $passwordHash === '') {
            throw new InvalidArgumentException('Full name, username, and password are required.');
        }

        return $this->transaction(function () use ($actorId, $actorRole, $account, $targetRole, $fullName, $username, $passwordHash): int {
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
            $this->audit($actorId, $actorRole, 'User created', $userId, null, $this->auditSnapshot($this->get($userId)));
            return $userId;
        });
    }

    public function update(int $actorId, string $actorRole, int $userId, array $account): array
    {
        return $this->transaction(function () use ($actorId, $actorRole, $userId, $account): array {
            $before = $this->get($userId);
            $this->requireManageable($actorId, $actorRole, $before, true);

            $targetRole = (string)($account['role'] ?? $before['role_name']);
            if ($before['role_name'] === 'super_admin') {
                $targetRole = 'super_admin';
            } else {
                $targetRole = $this->requireAssignableRole($actorRole, $targetRole);
            }
            $fullName = trim((string)($account['full_name'] ?? ''));
            $username = trim((string)($account['username'] ?? ''));
            if ($fullName === '' || $username === '') {
                throw new InvalidArgumentException('Full name and username are required.');
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
            if ($targetRole !== $before['role_name']) {
                $parts[] = 'session_version = session_version + 1';
            }
            $params[] = $userId;
            $statement = $this->pdo->prepare('UPDATE users SET ' . implode(', ', $parts) . ' WHERE user_id = ?');
            $statement->execute($params);

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

        return $this->transaction(function () use ($actorId, $actorRole, $userId, $status): array {
            $before = $this->get($userId);
            $this->requireManageable($actorId, $actorRole, $before, false);
            if ($before['status'] !== $status) {
                $statement = $this->pdo->prepare('UPDATE users SET status = ?, session_version = session_version + 1 WHERE user_id = ?');
                $statement->execute([$status, $userId]);
            }
            $after = $this->get($userId);
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
                'sessions_revoked' => true,
            ]);
        });
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

    private function requireManageable(int $actorId, string $actorRole, array $target, bool $allowProtectedSelf): void
    {
        if ((bool)($target['is_recovery_account'] ?? false)) {
            throw new DomainException('The Recovery Account can be managed only through the offline recovery procedure.');
        }
        $targetRole = (string)$target['role_name'];
        if (!$this->policy->allows($actorRole, RoleCapabilityPolicy::MANAGE_USERS, $targetRole)) {
            throw new DomainException('Your account cannot manage this privileged user.');
        }
        if ($targetRole === 'super_admin' && (!$allowProtectedSelf || $actorId !== (int)$target['user_id'])) {
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

    private function audit(int $actorId, string $actorRole, string $action, int $recordId, ?array $before, ?array $after): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $payload = $after ?? [];
        $payload['actor_role'] = $actorRole;
        $roles = [
            (string)($before['role'] ?? $before['target_role'] ?? ''),
            (string)($payload['role'] ?? $payload['target_role'] ?? ''),
        ];
        $category = array_intersect($roles, ['super_admin', 'admin']) !== []
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
