<?php

namespace App\Attention;

use DateTimeImmutable;

final class AttentionRuleService
{
    private const SEVERITY_ORDER = ['critical' => 0, 'warning' => 1, 'info' => 2];

    private const PLATFORM_RULES = [
        ['key' => 'platform.security.failed-logins', 'signal' => 'failed_login_count', 'threshold' => 'failed_login_count', 'severity' => 'critical', 'category' => 'security', 'title' => 'Repeated failed sign-ins', 'destination' => 'components/system_administrator/audit_logs.php'],
        ['key' => 'platform.access.locked-accounts', 'signal' => 'locked_account_count', 'threshold' => 'locked_account_count', 'severity' => 'critical', 'category' => 'access', 'title' => 'Locked accounts require review', 'destination' => 'components/user_manager/user_manager.php'],
        ['key' => 'platform.backup.stale', 'signal' => 'backup_age_days', 'threshold' => 'backup_age_days', 'severity' => 'critical', 'category' => 'backup_recovery', 'title' => 'Latest backup is stale', 'destination' => 'components/system_administrator/backup_restore.php'],
        ['key' => 'platform.recovery.failures', 'signal' => 'recovery_failure_count', 'threshold' => 'recovery_failure_count', 'severity' => 'critical', 'category' => 'backup_recovery', 'title' => 'Recovery operation failed', 'destination' => 'components/system_administrator/backup_restore.php'],
        ['key' => 'platform.service.unhealthy', 'signal' => 'service_unhealthy_count', 'threshold' => 'service_unhealthy_count', 'severity' => 'critical', 'category' => 'system_health', 'title' => 'Platform service is unhealthy', 'destination' => 'components/system_administrator/system_health.php'],
        ['key' => 'platform.ml.stale-model', 'signal' => 'ml_model_age_days', 'threshold' => 'ml_model_age_days', 'severity' => 'warning', 'category' => 'ml_health', 'title' => 'Demand Forecast model is stale', 'destination' => 'components/system_administrator/ml_settings.php'],
        ['key' => 'platform.settings.changed', 'signal' => 'platform_setting_change_count', 'threshold' => 'platform_setting_change_count', 'severity' => 'info', 'category' => 'platform_setting', 'title' => 'Platform Settings changed', 'destination' => 'components/system_administrator/system_settings.php'],
        ['key' => 'platform.emergency-access.active', 'signal' => 'emergency_access_count', 'threshold' => 'emergency_access_count', 'severity' => 'critical', 'category' => 'emergency_access', 'title' => 'Emergency Access is active', 'destination' => 'components/system_administrator/emergency_access.php'],
        ['key' => 'platform.recovery-account.active', 'signal' => 'recovery_account_active_count', 'threshold' => 'recovery_account_active_count', 'severity' => 'critical', 'category' => 'recovery_account', 'title' => 'Recovery Account is unsealed', 'destination' => 'components/system_administrator/recovery_account.php'],
    ];

    private const STORE_RULES = [
        ['key' => 'store.sales.reversals', 'signal' => 'reversal_count', 'threshold' => 'reversal_count', 'severity' => 'warning', 'category' => 'sales', 'title' => 'Sales reversals require review', 'destination' => 'components/invoice/reversals.php'],
        ['key' => 'store.sales.unusual-discounts', 'signal' => 'max_discount_percent', 'threshold' => 'unusual_discount_percent', 'severity' => 'warning', 'category' => 'sales', 'title' => 'Unusual discounts require review', 'destination' => 'components/invoice/sales_history.php'],
        ['key' => 'store.cash.variance', 'signal' => 'cash_variance_amount', 'threshold' => 'cash_variance_amount', 'severity' => 'critical', 'category' => 'cash', 'title' => 'Cash variance exceeds tolerance', 'destination' => 'components/report/report_generation.php', 'absolute' => true],
        ['key' => 'store.approvals.overdue', 'signal' => 'oldest_approval_hours', 'threshold' => 'overdue_approval_hours', 'severity' => 'warning', 'category' => 'approvals', 'title' => 'Store approvals are overdue', 'destination' => 'components/inventory_management/replenishment_requests.php'],
        ['key' => 'store.inventory.escalated', 'signal' => 'inventory_escalated_count', 'threshold' => 'inventory_escalation_count', 'severity' => 'warning', 'category' => 'inventory', 'title' => 'Inventory risks were escalated', 'destination' => 'components/inventory_management/inventory_insights.php'],
        ['key' => 'store.inventory.overdue', 'signal' => 'oldest_inventory_risk_hours', 'threshold' => 'inventory_overdue_hours', 'severity' => 'warning', 'category' => 'inventory', 'title' => 'Inventory risk is overdue', 'destination' => 'components/inventory_management/inventory_insights.php'],
        ['key' => 'store.inventory.high-value', 'signal' => 'inventory_risk_value', 'threshold' => 'inventory_high_value_amount', 'severity' => 'critical', 'category' => 'inventory', 'title' => 'High-value inventory risk', 'destination' => 'components/inventory_management/inventory_insights.php'],
        ['key' => 'store.inventory.unresolved', 'signal' => 'oldest_unresolved_inventory_hours', 'threshold' => 'inventory_unresolved_hours', 'severity' => 'warning', 'category' => 'inventory', 'title' => 'Inventory risk remains unresolved', 'destination' => 'components/inventory_management/inventory_insights.php'],
        ['key' => 'store.fiscal-period.closing', 'signal' => 'fiscal_period_days_remaining', 'threshold' => 'fiscal_period_days_to_close', 'severity' => 'warning', 'category' => 'fiscal_period', 'title' => 'Fiscal Period is approaching closure', 'destination' => 'components/system_administrator/fiscal_periods.php', 'comparison' => 'at_most'],
        ['key' => 'store.fiscal-period.overdue', 'signal' => 'fiscal_period_overdue_count', 'fixed_threshold' => 1.0, 'severity' => 'critical', 'category' => 'fiscal_period', 'title' => 'Fiscal Period action is overdue', 'destination' => 'components/system_administrator/fiscal_periods.php'],
    ];

    public function __construct(private AttentionSettingsService $settings, private Clock $clock)
    {
    }

    public function evaluate(string $role, array $signals): array
    {
        $rules = $role === 'super_admin'
            ? self::PLATFORM_RULES
            : ($role === 'admin' ? self::STORE_RULES : []);
        $thresholds = $this->settings->thresholdsFor($role);
        $items = [];

        foreach ($rules as $rule) {
            if (!array_key_exists($rule['signal'], $signals)) {
                continue;
            }
            $signal = is_array($signals[$rule['signal']])
                ? $signals[$rule['signal']]
                : ['value' => $signals[$rule['signal']]];
            $value = (float)($signal['value'] ?? 0);
            if (!empty($rule['absolute'])) {
                $value = abs($value);
            }
            $threshold = (float)($rule['fixed_threshold'] ?? $thresholds[$rule['threshold']] ?? INF);
            $active = ($rule['comparison'] ?? 'at_least') === 'at_most'
                ? $value >= 0 && $value <= $threshold
                : $value > 0 && $value >= $threshold;
            if (!$active) {
                continue;
            }

            $detectedAt = $this->date($signal['detected_at'] ?? null);
            $item = [
                'key' => $rule['key'],
                'severity' => $rule['severity'],
                'category' => $rule['category'],
                'title' => $rule['title'],
                'explanation' => isset($signal['explanation'])
                    ? (string)$signal['explanation']
                    : $this->explanation($rule['title'], $value, $threshold),
                'detected_at' => $detectedAt->format('Y-m-d H:i:s'),
                'destination' => $rule['destination'],
            ];
            if (array_key_exists('count', $signal)) {
                $item['count'] = max(0, (int)$signal['count']);
            }
            $items[] = $item;
        }

        usort($items, static function (array $left, array $right): int {
            $severity = self::SEVERITY_ORDER[$left['severity']] <=> self::SEVERITY_ORDER[$right['severity']];
            if ($severity !== 0) {
                return $severity;
            }
            $detected = strcmp($left['detected_at'], $right['detected_at']);
            return $detected !== 0 ? $detected : strcmp($left['key'], $right['key']);
        });

        return $items;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        return is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : $this->clock->now();
    }

    private function explanation(string $title, float $value, float $threshold): string
    {
        return sprintf('%s. Observed %s; configured threshold %s.', $title, $this->number($value), $this->number($threshold));
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
