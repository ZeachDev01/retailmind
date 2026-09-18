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
        $failedLogins = $this->scalar(
            "SELECT COUNT(*) FROM login_attempts WHERE was_successful = 0 AND attempted_at >= DATE_SUB(?, INTERVAL 24 HOUR)",
            [$this->now()]
        );
        $lockedAccounts = $this->scalar(
            "SELECT COUNT(*) FROM users WHERE status = 'active' AND locked_until > ?",
            [$this->now()]
        );
        $backupAge = $this->scalar(
            "SELECT COALESCE(DATEDIFF(?, MAX(created_at)), 9999) FROM backup_history WHERE status = 'completed' AND backup_type <> 'restore'",
            [$this->now()]
        );
        $recoveryFailures = $this->scalar(
            "SELECT COUNT(*) FROM backup_history WHERE status = 'failed' AND created_at >= DATE_SUB(?, INTERVAL 24 HOUR)",
            [$this->now()]
        );
        $modelAge = $this->scalar(
            "SELECT COALESCE(DATEDIFF(?, MAX(completed_at)), 9999) FROM model_training_runs WHERE status = 'completed'",
            [$this->now()]
        );
        $platformChanges = $this->scalar(
            "SELECT COUNT(*) FROM activity_log WHERE category = 'platform_setting' AND created_at >= DATE_SUB(?, INTERVAL 24 HOUR)",
            [$this->now()]
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
        $reversals = $this->scalar("SELECT COUNT(*) FROM sale_reversals WHERE status = 'pending'");
        $discount = $this->row(
            "SELECT COALESCE(MAX(discount_value), 0) AS value, COUNT(*) AS item_count
             FROM sales WHERE discount_type = 'percentage' AND sale_date >= DATE_SUB(?, INTERVAL 24 HOUR)",
            [$this->now()]
        );
        $cash = $this->row(
            "SELECT COALESCE(MAX(ABS(cash_variance)), 0) AS value, COUNT(*) AS item_count
             FROM cashier_shifts WHERE status = 'closed' AND reviewed_at IS NULL AND cash_variance IS NOT NULL"
        );
        $approvals = $this->row(
            "SELECT COALESCE(TIMESTAMPDIFF(HOUR, MIN(request_date), ?), 0) AS value, COUNT(*) AS item_count
             FROM replenishment_requests WHERE status = 'pending'",
            [$this->now()]
        );
        $inventory = $this->row(
            "SELECT COALESCE(TIMESTAMPDIFF(HOUR, MIN(ia.reported_at), ?), 0) AS value,
                    COUNT(*) AS item_count,
                    COALESCE(MAX(ABS(ia.adjustment_qty) * p.cost_price), 0) AS risk_value
             FROM inventory_adjustments ia JOIN products p ON p.product_id = ia.product_id
             WHERE ia.status = 'pending'",
            [$this->now()]
        );
        $fiscalDays = $this->scalar(
            "SELECT COALESCE(MIN(DATEDIFF(end_date, DATE(?))), 9999) FROM fiscal_periods WHERE status = 'open' AND end_date >= DATE(?)",
            [$this->now(), $this->now()]
        );
        $fiscalOverdue = $this->scalar(
            "SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open' AND end_date < DATE(?)",
            [$this->now()]
        );

        return [
            'reversal_count' => $this->signal($reversals, (int)$reversals),
            'max_discount_percent' => $this->signal((float)($discount['value'] ?? 0)),
            'cash_variance_amount' => $this->signal((float)($cash['value'] ?? 0)),
            'oldest_approval_hours' => $this->signal((float)($approvals['value'] ?? 0)),
            'oldest_inventory_risk_hours' => $this->signal((float)($inventory['value'] ?? 0)),
            'inventory_risk_value' => $this->signal((float)($inventory['risk_value'] ?? 0)),
            'oldest_unresolved_inventory_hours' => $this->signal((float)($inventory['value'] ?? 0)),
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

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
