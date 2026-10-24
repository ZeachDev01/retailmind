<?php

namespace App\Authorization;

use DomainException;
use LogicException;
use PDO;

final class AdministratorAccessMinimum
{
    public const MESSAGE = 'Keep at least one active ordinary account with Administrator access. Enable or assign another Administrator first.';

    public function __construct(private PDO $pdo)
    {
    }

    public function lock(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Administrator access changes require a transaction.');
        }
        // One stable mutex across different targets, including automatic dormancy.
        $this->pdo->query("SELECT role_id FROM roles WHERE role_name = 'admin'" . $this->rowLock())->fetchColumn();
    }

    public function lastAdministratorId(): ?int
    {
        // Locking reads see committed changes even after waiting in a repeatable-read transaction.
        $rows = $this->pdo->query(
            "SELECT u.user_id, r.role_name, ar.role_name AS assigned_role
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN user_roles ur ON ur.user_id = u.user_id
             LEFT JOIN roles ar ON ar.role_id = ur.role_id
             WHERE u.status = 'active' AND u.is_recovery_account = 0
             ORDER BY u.user_id" . ($this->pdo->inTransaction() ? $this->rowLock() : '')
        )->fetchAll(PDO::FETCH_ASSOC);
        $roles = [];
        foreach ($rows as $row) {
            $roles[(int)$row['user_id']][] = $row['role_name'];
            $roles[(int)$row['user_id']][] = $row['assigned_role'];
        }
        $ids = array_keys(array_filter($roles, static fn(array $assigned): bool =>
            in_array('admin', $assigned, true) && !in_array('super_admin', $assigned, true)));
        return count($ids) === 1 ? $ids[0] : null;
    }

    public function requireRemovable(int $userId): void
    {
        if ($this->lastAdministratorId() === $userId) {
            throw new DomainException(self::MESSAGE);
        }
    }

    private function rowLock(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
