<?php

namespace App\Services;

use App\Core\Environment;
use PDO;
use RuntimeException;
use Throwable;

final class CashierLoginService
{
    public function __construct(private PDO $pdo) {}

    // Lock the account even before its first login row exists.
    private function locked(int $userId, callable $action): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT session_version, status FROM users WHERE user_id = ?'
                . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute([$userId]);
            $user = $lock->fetch(PDO::FETCH_ASSOC);
            $read = $this->pdo->prepare('SELECT * FROM cashier_login_sessions WHERE user_id = ?');
            $read->execute([$userId]);
            $result = $action($read->fetch(PDO::FETCH_ASSOC) ?: null, $user ?: []);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function active(?array $row, array $user): bool
    {
        return $row && ($user['status'] ?? '') === 'active'
            && (int)$row['session_version'] === (int)$user['session_version']
            && time() - (int)$row['last_activity'] < max(1, (int)Environment::get('SESSION_LIFETIME', 3600))
            && time() - (int)$row['authenticated_at'] < max(1, (int)Environment::get('SESSION_ABSOLUTE_LIFETIME', 43200));
    }

    public function request(int $userId, int $version, string $token): string
    {
        return $this->locked($userId, function (?array $row, array $user) use ($userId, $version, $token): string {
            if (($user['status'] ?? '') !== 'active' || (int)$user['session_version'] !== $version) {
                throw new RuntimeException('Account access changed. Please sign in again.');
            }
            $hash = hash('sha256', $token);
            if (!$this->active($row, $user)) {
                $this->pdo->prepare('DELETE FROM cashier_login_sessions WHERE user_id = ?')->execute([$userId]);
                $this->pdo->prepare('INSERT INTO cashier_login_sessions
                    (user_id, owner_hash, session_version, authenticated_at, last_activity) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$userId, $hash, $version, time(), time()]);
                return 'ready';
            }
            if ((int)($row['pending_until'] ?? 0) > time()) return 'busy';
            $this->pdo->prepare('UPDATE cashier_login_sessions SET pending_hash = ?, pending_until = ?, approved = 0 WHERE user_id = ?')
                ->execute([$hash, time() + 300, $userId]);
            return 'waiting';
        });
    }

    public function owns(int $userId, string $token, int $lastActivity): bool
    {
        $update = $this->pdo->prepare('UPDATE cashier_login_sessions SET last_activity = ? WHERE user_id = ? AND owner_hash = ?');
        $hash = hash('sha256', $token);
        $update->execute([$lastActivity, $userId, $hash]);
        $read = $this->pdo->prepare('SELECT owner_hash FROM cashier_login_sessions WHERE user_id = ?');
        $read->execute([$userId]);
        $owner = $read->fetchColumn();
        return is_string($owner) && hash_equals($owner, $hash);
    }

    public function pending(int $userId, string $token): ?string
    {
        $read = $this->pdo->prepare('SELECT pending_hash FROM cashier_login_sessions
            WHERE user_id = ? AND owner_hash = ? AND approved = 0 AND pending_until > ?');
        $read->execute([$userId, hash('sha256', $token), time()]);
        return $read->fetchColumn() ?: null;
    }

    public function decide(int $userId, string $token, string $request, bool $approve): bool
    {
        return $this->locked($userId, function (?array $row, array $user) use ($userId, $token, $request, $approve): bool {
            if (!$this->active($row, $user) || !hash_equals($row['owner_hash'], hash('sha256', $token))
                || !hash_equals((string)$row['pending_hash'], $request) || (int)$row['pending_until'] <= time()
                || (int)$row['approved'] !== 0) return false;
            if ($approve) {
                $this->pdo->prepare('UPDATE cashier_login_sessions SET owner_hash = pending_hash, approved = 1,
                    authenticated_at = ?, last_activity = ? WHERE user_id = ?')->execute([time(), time(), $userId]);
            } else {
                $this->pdo->prepare('UPDATE cashier_login_sessions SET pending_hash = NULL, pending_until = NULL WHERE user_id = ?')
                    ->execute([$userId]);
            }
            return true;
        });
    }

    public function claim(int $userId, int $version, string $token): string
    {
        return $this->locked($userId, function (?array $row, array $user) use ($userId, $version, $token): string {
            if (($user['status'] ?? '') !== 'active' || (int)$user['session_version'] !== $version) return 'expired';
            $hash = hash('sha256', $token);
            if (!$row || !hash_equals((string)$row['pending_hash'], $hash) || (int)$row['pending_until'] <= time()) return 'expired';
            // An offline old device cannot approve; its authoritative inactivity expiry releases access.
            if (!$this->active($row, $user) || (int)$row['approved'] === 1) {
                $this->pdo->prepare('UPDATE cashier_login_sessions SET owner_hash = ?, session_version = ?,
                    authenticated_at = ?, last_activity = ?, pending_hash = NULL, pending_until = NULL, approved = 0 WHERE user_id = ?')
                    ->execute([$hash, $version, time(), time(), $userId]);
                return 'ready';
            }
            return 'waiting';
        });
    }

    public function release(int $userId, string $token): void
    {
        $this->pdo->prepare('DELETE FROM cashier_login_sessions WHERE user_id = ? AND owner_hash = ?')
            ->execute([$userId, hash('sha256', $token)]);
    }
}
