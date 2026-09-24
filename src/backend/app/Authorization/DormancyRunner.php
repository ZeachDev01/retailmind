<?php

namespace App\Authorization;

use App\Attention\Clock;
use App\Audit\AuditRecordCategory;
use App\Services\UserAccountNotifier;
use DateTimeImmutable;
use PDO;
use Throwable;

final class DormancyRunner
{
    public const SCOPE_ROLES = ['admin', 'inventory_manager', 'cashier'];
    public const ALERT_ROLES = ['super_admin', 'admin'];
    public const WARN_REFERENCE_TYPE = 'dormancy_warn';
    public const DISABLE_ACTION = 'Dormant Account disabled automatically by the Dormancy Policy';

    private DormancyPolicyService $policy;
    private UserAccountNotifier $notifier;

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        ?DormancyPolicyService $policy = null,
        ?UserAccountNotifier $notifier = null
    ) {
        $this->policy = $policy ?? new DormancyPolicyService($pdo);
        $this->notifier = $notifier ?? new UserAccountNotifier();
    }

    public function run(): array
    {
        $thresholds = $this->policy->thresholds();
        $now = $this->clock->now();
        $result = [
            'thresholds' => $thresholds,
            'warned' => [],
            'disabled' => [],
            'skipped_last_admin' => [],
        ];

        foreach ($this->activeInScopeAccounts() as $account) {
            $reference = $this->referenceTime($account);
            if ($reference === null) {
                continue;
            }
            $days = $this->daysDormant($reference, $now);
            if ($days < $thresholds['warn_days']) {
                continue;
            }

            if ($days >= $thresholds['disable_days']) {
                $userId = (int)$account['user_id'];
                if ($account['role_name'] === 'admin' && !$this->anotherActiveAdministrator($userId)) {
                    $result['skipped_last_admin'][] = $userId;
                    if ($this->warn($account, $days, $thresholds['disable_days'], $now)) {
                        $result['warned'][] = $userId;
                    }
                    continue;
                }
                $this->policyDisable($account, $days, $now);
                $result['disabled'][] = $userId;
                continue;
            }

            if ($this->warn($account, $days, $thresholds['disable_days'], $now)) {
                $result['warned'][] = (int)$account['user_id'];
            }
        }

        return $result;
    }

    private function activeInScopeAccounts(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::SCOPE_ROLES), '?'));
        $statement = $this->pdo->prepare(
            "SELECT u.user_id, u.full_name, u.username, u.email, u.status, u.session_version,
                    u.branch_id, u.is_recovery_account, u.last_login_at, u.created_at, r.role_name
             FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'active'
               AND u.is_recovery_account = 0
               AND r.role_name IN ({$placeholders})
             ORDER BY u.user_id"
        );
        $statement->execute(self::SCOPE_ROLES);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function referenceTime(array $account): ?DateTimeImmutable
    {
        foreach (['last_login_at', 'created_at'] as $column) {
            $value = trim((string)($account[$column] ?? ''));
            if ($value !== '') {
                return new DateTimeImmutable($value);
            }
        }
        return null;
    }

    private function daysDormant(DateTimeImmutable $reference, DateTimeImmutable $now): int
    {
        $elapsed = $now->getTimestamp() - $reference->getTimestamp();
        return $elapsed > 0 ? intdiv($elapsed, 86400) : 0;
    }

    private function anotherActiveAdministrator(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_name = 'admin'
               AND u.status = 'active'
               AND u.is_recovery_account = 0
               AND u.user_id <> ?"
        );
        $statement->execute([$userId]);
        return (int)$statement->fetchColumn() > 0;
    }

    private function warn(array $account, int $days, int $disableDays, DateTimeImmutable $now): bool
    {
        $targetId = (int)$account['user_id'];
        $episodeStart = $this->referenceTime($account);
        if ($episodeStart !== null && $this->alreadyWarned($targetId, $episodeStart)) {
            return false;
        }

        $title = 'Dormant Account warning: ' . (string)$account['full_name'];
        $message = (string)$account['full_name'] . ' has not signed in for ' . $days
            . ' days. Under the Dormancy Policy, the account is disabled automatically after '
            . $disableDays . ' days without a sign-in.';
        $stamp = $now->format('Y-m-d H:i:s');

        $insert = $this->pdo->prepare(
            'INSERT INTO notifications
                (user_id, type, title, message, reference_id, reference_type, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
        );
        foreach ($this->warningRecipients($targetId) as $recipientId) {
            $insert->execute([$recipientId, 'system', $title, $message, $targetId, self::WARN_REFERENCE_TYPE, $stamp]);
        }

        $this->notifier->notify(
            $account['email'] ?? null,
            'Sign in soon to keep your RetailMind account',
            'You have not signed in to RetailMind for ' . $days . ' days. Sign in soon to keep your access.'
                . ' If you do not sign in, your account will be disabled automatically after '
                . $disableDays . ' days without a sign-in.'
        );

        return true;
    }

    private function alreadyWarned(int $targetId, DateTimeImmutable $episodeStart): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM notifications
             WHERE reference_type = ? AND reference_id = ? AND created_at >= ?
             LIMIT 1'
        );
        $statement->execute([self::WARN_REFERENCE_TYPE, $targetId, $episodeStart->format('Y-m-d H:i:s')]);
        return $statement->fetchColumn() !== false;
    }

    private function warningRecipients(int $targetId): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::ALERT_ROLES), '?'));
        $statement = $this->pdo->prepare(
            "SELECT u.user_id FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'active'
               AND u.is_recovery_account = 0
               AND u.user_id <> ?
               AND r.role_name IN ({$placeholders})
             ORDER BY u.user_id"
        );
        $statement->execute(array_merge([$targetId], self::ALERT_ROLES));
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function policyDisable(array $account, int $days, DateTimeImmutable $now): void
    {
        $userId = (int)$account['user_id'];
        $disabledAt = $now->format('Y-m-d H:i:s');

        $this->transaction(function () use ($account, $userId, $days, $disabledAt): void {
            $before = $this->snapshot($account, 'active');
            $statement = $this->pdo->prepare(
                "UPDATE users SET status = 'disabled', disabled_at = ?, session_version = session_version + 1 WHERE user_id = ?"
            );
            $statement->execute([$disabledAt, $userId]);

            $after = $this->snapshot($account, 'disabled');
            $after['disabled_at'] = $disabledAt;
            $this->auditSystemDisable($userId, $days, $before, $after);

            $this->notifier->disabledNotice($account['email'] ?? null);
        });
    }

    private function snapshot(array $account, string $status): array
    {
        $branchId = $account['branch_id'] ?? null;
        return [
            'user_id' => (int)$account['user_id'],
            'username' => (string)$account['username'],
            'role' => (string)$account['role_name'],
            'status' => $status,
            'store_id' => $branchId !== null && $branchId !== '' ? (int)$branchId : null,
        ];
    }

    private function auditSystemDisable(int $userId, int $days, array $before, array $after): void
    {
        $payload = $after;
        $payload['actor'] = 'system';
        $payload['actor_role'] = 'system';
        $payload['disabled_by'] = 'dormancy_policy';
        $payload['dormant_days'] = $days;
        $category = in_array($after['role'], ['super_admin', 'admin'], true)
            ? AuditRecordCategory::SECURITY
            : AuditRecordCategory::STORE_OPERATION;

        $statement = $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            null,
            self::DISABLE_ACTION,
            $category,
            'User Access',
            $userId,
            json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'system',
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
