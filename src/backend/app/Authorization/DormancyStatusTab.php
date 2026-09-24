<?php

namespace App\Authorization;

use DateTimeImmutable;
use PDO;

/**
 * Manage Account Status tab dormancy visibility (ticket #64).
 *
 * Supplies the plain facts the Administrator sees without date math: last
 * login, the computed automatic-deactivation date for active in-scope
 * accounts under the current Dormancy Policy, and the plain
 * automatic-disable explanation line for accounts the Dormancy Policy
 * disabled. The status dropdown itself stays Active/Disabled only.
 */
final class DormancyStatusTab
{
    /** Plain help line rendered on the Manage Account Status tab. */
    public const HELP_LINE = 'Dormant Accounts are disabled automatically after the Dormancy Policy period. History is kept and active sessions are revoked.';

    private DormancyPolicyService $policy;

    public function __construct(private PDO $pdo, ?DormancyPolicyService $policy = null)
    {
        $this->policy = $policy ?? new DormancyPolicyService($pdo);
    }

    /**
     * Status tab facts for one account row.
     *
     * @param array<string, mixed> $user user row with status, role_name,
     *                                   last_login_at, created_at, disabled_at,
     *                                   and is_recovery_account
     * @return array{
     *     in_scope: bool,
     *     last_login: string,
     *     auto_disable_date: ?string,
     *     policy_disabled_line: ?string
     * }
     */
    public function view(array $user): array
    {
        $inScope = $this->inScope($user);
        $autoDisableDate = null;
        if (($user['status'] ?? '') === 'active' && $inScope) {
            $reference = $this->referenceTime($user);
            if ($reference !== null) {
                $autoDisableDate = $reference
                    ->modify('+' . $this->policy->disableDays() . ' days')
                    ->format('m-d-y');
            }
        }

        return [
            'in_scope' => $inScope,
            'last_login' => $this->lastLoginLabel($user),
            'auto_disable_date' => $autoDisableDate,
            'policy_disabled_line' => $this->policyDisabledLine($user),
        ];
    }

    private function inScope(array $user): bool
    {
        if ((int)($user['is_recovery_account'] ?? 0) !== 0) {
            return false;
        }
        return in_array((string)($user['role_name'] ?? ''), DormancyRunner::SCOPE_ROLES, true);
    }

    private function lastLoginLabel(array $user): string
    {
        $value = trim((string)($user['last_login_at'] ?? ''));
        if ($value === '') {
            return 'Never';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('m-d-y h:i A', $timestamp);
    }

    private function referenceTime(array $user): ?DateTimeImmutable
    {
        foreach (['last_login_at', 'created_at'] as $column) {
            $value = trim((string)($user[$column] ?? ''));
            if ($value !== '') {
                return new DateTimeImmutable($value);
            }
        }
        return null;
    }

    /**
     * Plain automatic-disable line when the latest status change was the
     * Dormancy Policy; null for active accounts and manual disables.
     */
    private function policyDisabledLine(array $user): ?string
    {
        if (($user['status'] ?? '') !== 'disabled') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT action, new_value FROM activity_log
             WHERE record_id = ? AND action IN (?, ?, ?)
             ORDER BY log_id DESC LIMIT 1'
        );
        $statement->execute([
            (int)($user['user_id'] ?? 0),
            DormancyRunner::DISABLE_ACTION,
            'User disabled',
            'User deactivation',
        ]);
        $latest = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$latest || (string)$latest['action'] !== DormancyRunner::DISABLE_ACTION) {
            return null;
        }

        return 'Disabled automatically — no login for ' . $this->dormantDays($user, (string)($latest['new_value'] ?? '')) . ' days (policy)';
    }

    private function dormantDays(array $user, string $auditPayload): int
    {
        $payload = json_decode($auditPayload, true);
        if (is_array($payload) && isset($payload['dormant_days']) && is_numeric($payload['dormant_days'])) {
            return max(0, (int)$payload['dormant_days']);
        }

        $reference = $this->referenceTime($user);
        $disabledAt = trim((string)($user['disabled_at'] ?? ''));
        if ($reference !== null && $disabledAt !== '') {
            $timestamp = strtotime($disabledAt);
            if ($timestamp !== false) {
                return max(0, intdiv($timestamp - $reference->getTimestamp(), 86400));
            }
        }

        return $this->policy->disableDays();
    }
}
