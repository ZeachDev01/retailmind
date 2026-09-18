<?php

namespace App\Attention;

use PDO;
use Throwable;

final class DatabaseAttentionSignalSource
{
    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    public function platform(array $healthChecks = []): array
    {
        $dayAgo = $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s');
        $failedLogins = $this->scalar(
            "SELECT COUNT(*) FROM login_attempts WHERE was_successful = 0 AND attempted_at >= ?",
            [$dayAgo]
        );
        $lockedAccounts = $this->scalar(
            "SELECT COUNT(*) FROM users WHERE status = 'active' AND locked_until > ?",
            [$this->now()]
        );
        $latestBackup = $this->row(
            "SELECT created_at FROM backup_history WHERE status = 'completed' AND backup_type <> 'restore' ORDER BY created_at DESC LIMIT 1"
        );
        $backupAge = $this->ageDays($latestBackup['created_at'] ?? null);
        $recoveryFailures = $this->scalar(
            "SELECT COUNT(*) FROM backup_history WHERE status = 'failed' AND created_at >= ?",
            [$dayAgo]
        );
        $latestModel = $this->row(
            "SELECT completed_at FROM model_training_runs WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1"
        );
        $modelAge = $this->ageDays($latestModel['completed_at'] ?? null);
        $platformChanges = $this->scalar(
            "SELECT COUNT(*) FROM activity_log WHERE category = 'platform_setting' AND created_at >= ?",
            [$dayAgo]
        );
        $emergencyAccess = $this->scalar(
            "SELECT COUNT(*) FROM emergency_access_sessions WHERE status = 'active' AND expires_at > ?",
            [$this->now()]
        );
        $recoveryAccount = $this->scalar(
            "SELECT COUNT(*) FROM recovery_accounts ra JOIN users u ON u.user_id = ra.user_id WHERE u.status = 'active' AND ra.sealed_at IS NULL"
        );

        $serviceFailures = 0;
        $mlFailures = 0;
        foreach ($healthChecks as $check) {
            if (($check['status'] ?? 'healthy') === 'healthy') {
                continue;
            }
            if (($check['category'] ?? '') === 'Forecasting') {
                $mlFailures++;
            } else {
                $serviceFailures++;
            }
        }

        return [
            'failed_login_count' => $this->signal($failedLogins, (int)$failedLogins),
            'locked_account_count' => $this->signal($lockedAccounts, (int)$lockedAccounts),
            'backup_age_days' => $this->signal($backupAge),
            'recovery_failure_count' => $this->signal($recoveryFailures, (int)$recoveryFailures),
            'service_unhealthy_count' => $this->signal($serviceFailures, $serviceFailures),
            'ml_model_age_days' => $this->signal(max($modelAge, $mlFailures > 0 ? 9999 : 0)),
            'platform_setting_change_count' => $this->signal($platformChanges, (int)$platformChanges),
            'emergency_access_count' => $this->signal($emergencyAccess, (int)$emergencyAccess),
            'recovery_account_active_count' => $this->signal($recoveryAccount, (int)$recoveryAccount),
        ];
    }

    public function store(): array
    {
        $now = $this->clock->now();
        $reversals = $this->scalar("SELECT COUNT(*) FROM sale_reversals WHERE status = 'pending'");
        $discount = $this->row(
            "SELECT COALESCE(MAX(discount_value), 0) AS value, COUNT(*) AS item_count
             FROM sales WHERE discount_type = 'percentage' AND sale_date >= ?",
            [$now->modify('-24 hours')->format('Y-m-d H:i:s')]
        );
        $cash = $this->row(
            "SELECT COALESCE(MAX(ABS(cash_variance)), 0) AS value, COUNT(*) AS item_count
             FROM cashier_shifts WHERE status = 'closed' AND reviewed_at IS NULL AND cash_variance IS NOT NULL"
        );
        $approvals = $this->row(
            "SELECT MIN(request_date) AS oldest_at, COUNT(*) AS item_count
             FROM replenishment_requests WHERE status = 'pending'"
        );
        $inventory = $this->row(
            "SELECT MIN(ia.reported_at) AS oldest_at,
                    COUNT(*) AS item_count,
                    COALESCE(MAX(ABS(ia.adjustment_qty) * p.cost_price), 0) AS risk_value
             FROM inventory_adjustments ia JOIN products p ON p.product_id = ia.product_id
             WHERE ia.status = 'pending'"
        );
        $fiscal = $this->row(
            "SELECT MIN(end_date) AS next_end FROM fiscal_periods WHERE status = 'open' AND end_date >= ?",
            [$now->format('Y-m-d')]
        );
        $fiscalOverdue = $this->scalar(
            "SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open' AND end_date < ?",
            [$now->format('Y-m-d')]
        );
        $approvalHours = $this->ageHours($approvals['oldest_at'] ?? null);
        $inventoryHours = $this->ageHours($inventory['oldest_at'] ?? null);
        $fiscalDays = isset($fiscal['next_end'])
            ? max(0, (int)$now->setTime(0, 0)->diff(new \DateTimeImmutable((string)$fiscal['next_end']))->format('%a'))
            : 9999;

        return [
            'reversal_count' => $this->signal($reversals, (int)$reversals),
            'max_discount_percent' => $this->signal((float)($discount['value'] ?? 0)),
            'cash_variance_amount' => $this->signal((float)($cash['value'] ?? 0)),
            'oldest_approval_hours' => $this->signal($approvalHours),
            // A pending inventory adjustment is an explicit escalation from routine
            // inventory execution into the Administrator approval workflow.
            'inventory_escalated_count' => $this->signal((int)($inventory['item_count'] ?? 0), (int)($inventory['item_count'] ?? 0)),
            'oldest_inventory_risk_hours' => $this->signal($inventoryHours),
            'inventory_risk_value' => $this->signal((float)($inventory['risk_value'] ?? 0)),
            'oldest_unresolved_inventory_hours' => $this->signal($inventoryHours),
            'fiscal_period_days_remaining' => $this->signal($fiscalDays),
            'fiscal_period_overdue_count' => $this->signal($fiscalOverdue, (int)$fiscalOverdue),
        ];
    }

    private function signal(float|int $value, ?int $count = null): array
    {
        $signal = [
            'value' => $value,
            'detected_at' => $this->now(),
        ];
        if ($count !== null) {
            $signal['count'] = max(0, $count);
        }
        return $signal;
    }

    private function scalar(string $sql, array $parameters = []): float
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            return (float)$statement->fetchColumn();
        } catch (Throwable $exception) {
            error_log('Attention signal query skipped: ' . $exception->getMessage());
            return 0.0;
        }
    }

    private function row(string $sql, array $parameters = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            error_log('Attention signal query skipped: ' . $exception->getMessage());
            return [];
        }
    }

    private function ageDays(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 9999;
        }
        try {
            return max(0, (int)(new \DateTimeImmutable($value))->diff($this->clock->now())->format('%a'));
        } catch (Throwable) {
            return 9999;
        }
    }

    private function ageHours(mixed $value): float
    {
        if (!is_string($value) || $value === '') {
            return 0.0;
        }
        try {
            $seconds = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($value))->getTimestamp();
            return max(0.0, $seconds / 3600);
        } catch (Throwable) {
            return 0.0;
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
