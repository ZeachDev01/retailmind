<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RecoveryAccountService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function publicStatus(): array
    {
        $account = $this->account();
        if ($account === null) {
            return ['status' => 'not_configured', 'last_used_at' => null];
        }

        return [
            'status' => $account['status'] === 'active' ? 'active' : 'sealed',
            'last_used_at' => $account['last_used_at'],
        ];
    }

    public function provision(string $username, string $passwordHash, string $activationSecret): int
    {
        $username = trim($username);
        $this->requireCredentials($passwordHash, $activationSecret);
        if ($username === '') {
            throw new InvalidArgumentException('A Recovery Account username is required.');
        }
        if ($this->account() !== null) {
            throw new DomainException('The Recovery Account is already provisioned.');
        }

        return $this->transaction(function () use ($username, $passwordHash, $activationSecret): int {
            $role = $this->pdo->query("SELECT role_id FROM roles WHERE role_name = 'super_admin'")->fetchColumn();
            if (!$role) {
                throw new DomainException('The Super Administrator role is unavailable.');
            }

            $statement = $this->pdo->prepare(
                "INSERT INTO users
                    (full_name, username, email, password_hash, role_id, status, must_change_password, branch_id, is_recovery_account)
                 VALUES ('Recovery Account', ?, NULL, ?, ?, 'disabled', 0, NULL, 1)"
            );
            $statement->execute([$username, $passwordHash, (int)$role]);
            $userId = (int)$this->pdo->lastInsertId();

            $statement = $this->pdo->prepare(
                'INSERT INTO recovery_accounts (account_key, user_id, activation_secret_hash, sealed_at)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $statement->execute(['primary', $userId, password_hash($activationSecret, PASSWORD_DEFAULT)]);
            $this->audit($userId, 'Recovery Account provisioned');

            return $userId;
        });
    }

    public function activate(string $activationSecret): void
    {
        $this->transaction(function () use ($activationSecret): void {
            $account = $this->requireSecret($activationSecret);
            if ($account['status'] === 'active') {
                throw new DomainException('The Recovery Account is already active.');
            }

            $this->pdo->prepare(
                "UPDATE users SET status = 'active', failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?"
            )->execute([(int)$account['user_id']]);
            $this->pdo->prepare(
                'UPDATE recovery_accounts SET activated_at = CURRENT_TIMESTAMP, sealed_at = NULL WHERE user_id = ?'
            )->execute([(int)$account['user_id']]);
            $this->audit((int)$account['user_id'], 'Recovery Account activated');
        });
    }

    public function rotateCredentials(string $activationSecret, string $passwordHash, string $newActivationSecret): void
    {
        $this->requireCredentials($passwordHash, $newActivationSecret);
        if (hash_equals($activationSecret, $newActivationSecret)) {
            throw new InvalidArgumentException('The new activation credential must be different.');
        }

        $this->transaction(function () use ($activationSecret, $passwordHash, $newActivationSecret): void {
            $account = $this->requireSecret($activationSecret);
            if ($account['status'] !== 'active') {
                throw new DomainException('Activate the Recovery Account before rotating credentials.');
            }

            $this->pdo->prepare(
                'UPDATE users SET password_hash = ?, password_changed_at = CURRENT_TIMESTAMP,
                    session_version = session_version + 1, failed_login_attempts = 0, locked_until = NULL
                 WHERE user_id = ?'
            )->execute([$passwordHash, (int)$account['user_id']]);
            $this->pdo->prepare(
                'UPDATE recovery_accounts SET activation_secret_hash = ?, credentials_rotated_at = CURRENT_TIMESTAMP
                 WHERE user_id = ?'
            )->execute([password_hash($newActivationSecret, PASSWORD_DEFAULT), (int)$account['user_id']]);
            $this->audit((int)$account['user_id'], 'Recovery Account credentials rotated');
        });
    }

    public function recordUse(int $userId, ?string $ipAddress = null): void
    {
        $account = $this->account();
        if ($account === null || (int)$account['user_id'] !== $userId || $account['status'] !== 'active') {
            throw new DomainException('The active Recovery Account was not found.');
        }

        $this->transaction(function () use ($userId, $ipAddress): void {
            $this->pdo->prepare('UPDATE recovery_accounts SET last_used_at = CURRENT_TIMESTAMP WHERE user_id = ?')
                ->execute([$userId]);
            $this->audit($userId, 'Recovery Account used', false, $ipAddress ?? 'system');
        });
    }

    public function reseal(string $activationSecret): void
    {
        $this->transaction(function () use ($activationSecret): void {
            $account = $this->requireSecret($activationSecret);
            if ($account['status'] !== 'active') {
                throw new DomainException('The Recovery Account is already sealed.');
            }

            $this->pdo->prepare(
                "UPDATE users SET status = 'disabled', session_version = session_version + 1,
                    failed_login_attempts = 0, locked_until = NULL WHERE user_id = ?"
            )->execute([(int)$account['user_id']]);
            $this->pdo->prepare(
                'UPDATE recovery_accounts SET activated_at = NULL, sealed_at = CURRENT_TIMESTAMP WHERE user_id = ?'
            )->execute([(int)$account['user_id']]);
            $this->audit((int)$account['user_id'], 'Recovery Account resealed');
        });
    }

    private function account(): ?array
    {
        $statement = $this->pdo->query(
            "SELECT u.user_id, u.status, ra.activation_secret_hash, ra.last_used_at
             FROM users u
             JOIN recovery_accounts ra ON ra.user_id = u.user_id
             WHERE ra.account_key = 'primary' AND u.is_recovery_account = 1
             LIMIT 1"
        );
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        return $account ?: null;
    }

    private function requireSecret(string $activationSecret): array
    {
        $account = $this->account();
        if ($account === null) {
            throw new DomainException('The Recovery Account is not provisioned.');
        }
        if (!password_verify($activationSecret, (string)$account['activation_secret_hash'])) {
            throw new DomainException('The Recovery Account activation credential is invalid.');
        }
        return $account;
    }

    private function requireCredentials(string $passwordHash, string $activationSecret): void
    {
        if (trim($passwordHash) === '') {
            throw new InvalidArgumentException('A Recovery Account login credential is required.');
        }
        if (strlen($activationSecret) < 20) {
            throw new InvalidArgumentException('The activation credential must contain at least 20 characters.');
        }
    }

    private function audit(
        int $userId,
        string $action,
        bool $offlineProcedure = true,
        string $ipAddress = 'offline-cli'
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, metadata, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            $action,
            AuditRecordCategory::RECOVERY_ACCOUNT,
            'Recovery Account',
            $userId,
            json_encode(['offline_procedure' => $offlineProcedure], JSON_UNESCAPED_SLASHES),
            $ipAddress,
        ]);
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
