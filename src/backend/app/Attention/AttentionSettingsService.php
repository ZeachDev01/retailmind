<?php

namespace App\Attention;

use DomainException;
use InvalidArgumentException;
use PDO;

final class AttentionSettingsService
{
    private const PLATFORM_DEFAULTS = [
        'failed_login_count' => 5.0,
        'locked_account_count' => 1.0,
        'backup_age_days' => 7.0,
        'recovery_failure_count' => 1.0,
        'service_unhealthy_count' => 1.0,
        'ml_model_age_days' => 7.0,
        'platform_setting_change_count' => 1.0,
        'emergency_access_count' => 1.0,
        'recovery_account_active_count' => 1.0,
        'store_reversal_count_max' => 10.0,
        'store_unusual_discount_percent_max' => 50.0,
        'store_cash_variance_amount_max' => 1000.0,
        'store_overdue_approval_hours_max' => 72.0,
        'store_inventory_overdue_hours_max' => 72.0,
        'store_inventory_high_value_amount_max' => 20000.0,
        'store_inventory_unresolved_hours_max' => 168.0,
    ];

    private const STORE_DEFAULTS = [
        'reversal_count' => 1.0,
        'unusual_discount_percent' => 20.0,
        'cash_variance_amount' => 100.0,
        'overdue_approval_hours' => 24.0,
        'inventory_escalation_count' => 1.0,
        'inventory_overdue_hours' => 24.0,
        'inventory_high_value_amount' => 5000.0,
        'inventory_unresolved_hours' => 72.0,
        'fiscal_period_days_to_close' => 3.0,
    ];

    private const STORE_LIMITS = [
        'reversal_count' => 'store_reversal_count_max',
        'unusual_discount_percent' => 'store_unusual_discount_percent_max',
        'cash_variance_amount' => 'store_cash_variance_amount_max',
        'overdue_approval_hours' => 'store_overdue_approval_hours_max',
        'inventory_overdue_hours' => 'store_inventory_overdue_hours_max',
        'inventory_high_value_amount' => 'store_inventory_high_value_amount_max',
        'inventory_unresolved_hours' => 'store_inventory_unresolved_hours_max',
    ];

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    public function updatePlatform(string $actorRole, array $settings, int $actorUserId): void
    {
        if ($actorRole !== 'super_admin') {
            throw new DomainException('Only the Super Administrator may govern Platform attention thresholds.');
        }
        $this->save('platform', $settings, self::PLATFORM_DEFAULTS, $actorUserId);
    }

    public function updateStore(string $actorRole, array $settings, int $actorUserId): void
    {
        if ($actorRole !== 'admin') {
            throw new DomainException('Only the Administrator may govern Store attention thresholds.');
        }
        $this->save('store', $settings, self::STORE_DEFAULTS, $actorUserId);
    }

    public function thresholdsFor(string $role): array
    {
        if ($role === 'super_admin') {
            return $this->load('platform', self::PLATFORM_DEFAULTS);
        }
        if ($role !== 'admin') {
            return [];
        }

        $platform = $this->load('platform', self::PLATFORM_DEFAULTS);
        $store = $this->load('store', self::STORE_DEFAULTS);
        foreach (self::STORE_LIMITS as $storeKey => $platformKey) {
            $store[$storeKey] = min($store[$storeKey], $platform[$platformKey]);
        }

        return $store;
    }

    public function platformSettings(): array
    {
        return $this->load('platform', self::PLATFORM_DEFAULTS);
    }

    public function storeSettings(): array
    {
        return $this->load('store', self::STORE_DEFAULTS);
    }

    private function save(string $scope, array $settings, array $allowed, int $actorUserId): void
    {
        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, $allowed)) {
                throw new InvalidArgumentException("Unsupported {$scope} attention threshold: {$key}");
            }
            if (!is_numeric($value) || (float)$value < 0) {
                throw new InvalidArgumentException("Attention threshold {$key} must be a non-negative number.");
            }
            $this->put($scope, (string)$key, (float)$value, $actorUserId);
        }
    }

    private function load(string $scope, array $defaults): array
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_key, setting_value FROM attention_settings WHERE setting_scope = ?'
        );
        $statement->execute([$scope]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['setting_key'], $defaults) && is_numeric($row['setting_value'])) {
                $defaults[$row['setting_key']] = (float)$row['setting_value'];
            }
        }
        return $defaults;
    }

    private function put(string $scope, string $key, float $value, int $actorUserId): void
    {
        $exists = $this->pdo->prepare(
            'SELECT COUNT(*) FROM attention_settings WHERE setting_scope = ? AND setting_key = ?'
        );
        $exists->execute([$scope, $key]);
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s');
        if ((int)$exists->fetchColumn() > 0) {
            $statement = $this->pdo->prepare(
                'UPDATE attention_settings SET setting_value = ?, updated_by = ?, updated_at = ? WHERE setting_scope = ? AND setting_key = ?'
            );
            $statement->execute([(string)$value, $actorUserId, $timestamp, $scope, $key]);
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO attention_settings (setting_scope, setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$scope, $key, (string)$value, $actorUserId, $timestamp]);
    }
}
