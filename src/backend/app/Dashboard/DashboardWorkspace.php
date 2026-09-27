<?php

namespace App\Dashboard;

use App\Attention\AttentionRuleService;
use App\Attention\AttentionSettingsService;
use App\Attention\Clock;
use App\Attention\DatabaseAttentionSignalSource;
use App\Authorization\RoleCapabilityPolicy;
use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

final class DashboardWorkspace
{
    private const REFRESH_SECONDS = 300;

    private const ACTIONS = [
        ['label' => 'Users & Access', 'description' => 'Manage Staff and privileged accounts', 'icon' => 'bi-shield-lock', 'destination' => 'components/user_manager/user_manager.php', 'capability' => RoleCapabilityPolicy::MANAGE_USERS],
        ['label' => 'System Health', 'description' => 'Review service checks and failures', 'icon' => 'bi-heart-pulse', 'destination' => 'components/system_administrator/system_health.php', 'capability' => RoleCapabilityPolicy::PLATFORM_GOVERNANCE],
        ['label' => 'Backup & Restore', 'description' => 'Create a Database Backup or restore the database', 'icon' => 'bi-database-check', 'destination' => 'components/system_administrator/backup_restore.php', 'capability' => RoleCapabilityPolicy::PLATFORM_GOVERNANCE],
        ['label' => 'Demand Forecast', 'description' => 'Review model status and technical controls', 'icon' => 'bi-cpu', 'destination' => 'components/system_administrator/ml_settings.php', 'capability' => RoleCapabilityPolicy::PLATFORM_GOVERNANCE],
        ['label' => 'Platform Settings', 'description' => 'Manage security and recovery safeguards', 'icon' => 'bi-gear', 'destination' => 'components/system_administrator/system_settings.php', 'capability' => RoleCapabilityPolicy::PLATFORM_GOVERNANCE],
        ['label' => 'Protected Audit Records', 'description' => 'Review recorded security and platform actions', 'icon' => 'bi-clock-history', 'destination' => 'components/system_administrator/audit_logs.php', 'capability' => RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT],
    ];

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private PlatformHealthSource $health,
        private RoleCapabilityPolicy $policy
    ) {
    }

    public function load(string $actorRole, int $days = 30): array
    {
        if ($actorRole !== 'super_admin') {
            throw new DomainException('The requested dashboard workspace is not available for this role.');
        }

        $now = $this->clock->now();
        try {
            $healthChecks = $this->health->checks();
            $platform = $this->platformState($now, $healthChecks);
            $attention = array_values(array_filter(
                (new AttentionRuleService(
                    new AttentionSettingsService($this->pdo, $this->clock),
                    $this->clock
                ))->evaluate($actorRole, $platform['signals']),
                fn($item): bool => $this->destinationIsPermitted($actorRole, $item->destination)
            ));

            $attentionItems = array_map(
                static fn($item): array => $item->toArray(),
                $attention
            );
            $criticalCount = count(array_filter(
                $attentionItems,
                static fn(array $item): bool => $item['severity'] === 'critical'
            ));

            return [
                'role' => $actorRole,
                'range_days' => in_array($days, [7, 30, 90], true) ? $days : 30,
                'state' => $attentionItems === [] ? 'empty' : 'ready',
                'headline' => [
                    'critical_attention' => [
                        'label' => 'Critical attention',
                        'value' => $criticalCount,
                        'status' => $criticalCount > 0 ? 'critical' : 'healthy',
                        'detail' => $criticalCount > 0
                            ? $criticalCount . ' critical ' . ($criticalCount === 1 ? 'condition requires' : 'conditions require') . ' review.'
                            : 'No critical conditions detected.',
                    ],
                    'latest_backup' => $platform['backup'],
                    'platform_health' => $platform['platform_health'],
                    'ml_health' => $platform['ml_health'],
                    'privileged_accounts' => $platform['privileged_accounts'],
                ],
                'attention' => $attentionItems,
                'access' => $platform['access'],
                'continuity' => $this->continuity($now),
                'actions' => $this->permittedActions($actorRole),
                'freshness' => $this->freshness($now),
                'message' => $attentionItems === [] ? 'All monitored platform conditions are below their configured thresholds.' : null,
            ];
        } catch (Throwable $exception) {
            error_log('Dashboard workspace load failed: ' . $exception->getMessage());
            return [
                'role' => $actorRole,
                'range_days' => in_array($days, [7, 30, 90], true) ? $days : 30,
                'state' => 'error',
                'headline' => [],
                'attention' => [],
                'access' => [],
                'continuity' => [],
                'actions' => $this->permittedActions($actorRole),
                'freshness' => $this->freshness($now),
                'message' => 'Current platform status could not be loaded. Refresh to try again.',
            ];
        }
    }

    private function platformState(DateTimeImmutable $now, array $healthChecks): array
    {
        $nowValue = $now->format('Y-m-d H:i:s');
        $signals = (new DatabaseAttentionSignalSource($this->pdo, $this->clock))->platform($healthChecks);
        $backup = $this->row("SELECT created_at FROM backup_history WHERE status = 'completed' AND backup_type <> 'restore' ORDER BY created_at DESC LIMIT 1");
        $emergency = $this->count("SELECT COUNT(*) FROM emergency_access_sessions WHERE status = 'active' AND expires_at > ?", [$nowValue]);
        $recovery = $this->row(
            "SELECT ra.sealed_at, ra.last_used_at, u.status
             FROM recovery_accounts ra JOIN users u ON u.user_id = ra.user_id
             WHERE u.is_recovery_account = 1 LIMIT 1"
        );
        $unsealedRecovery = $recovery !== [] && $recovery['status'] === 'active' && $recovery['sealed_at'] === null ? 1 : 0;
        $backupAge = $backup === [] ? 9999 : $this->ageDays((string)$backup['created_at'], $now);
        $modelAge = (int)($signals['ml_model_age_days']['value'] ?? 9999);
        $privilegedAnomalies = $this->count(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE r.role_name IN ('super_admin', 'admin') AND u.status = 'active' AND u.locked_until > ?",
            [$nowValue]
        ) + $unsealedRecovery + $emergency;

        return [
            'signals' => $signals,
            'backup' => [
                'label' => 'Latest successful backup',
                'value' => $backup === [] ? 'Not available' : (string)$backup['created_at'],
                'status' => $backupAge > 7 ? 'critical' : 'healthy',
                'detail' => $backup === []
                    ? 'No successful Database Backup is recorded.'
                    : $backupAge . ' ' . ($backupAge === 1 ? 'day' : 'days') . ' old.',
            ],
            'platform_health' => $this->healthHeadline('Platform health', $healthChecks, false),
            'ml_health' => $this->healthHeadline('Demand Forecast health', $healthChecks, true, $modelAge),
            'privileged_accounts' => [
                'label' => 'Active access risks',
                'value' => $privilegedAnomalies,
                'status' => $privilegedAnomalies > 0 ? 'critical' : 'healthy',
                'detail' => $privilegedAnomalies > 0 ? 'Locked, elevated, or unsealed privileged access requires review.' : 'No active, locked, or unsealed privileged access detected.',
            ],
            'access' => [
                'emergency' => [
                    'label' => 'Emergency Access',
                    'status' => $emergency > 0 ? 'active' : 'inactive',
                    'detail' => $emergency > 0
                        ? $emergency . ' active reason-bound ' . ($emergency === 1 ? 'session.' : 'sessions.')
                        : 'No active Emergency Access sessions.',
                    'destination' => 'components/system_administrator/emergency_access.php',
                ],
                'recovery' => [
                    'label' => 'Recovery Account',
                    'status' => $recovery === [] ? 'not_configured' : ($unsealedRecovery > 0 ? 'unsealed' : 'sealed'),
                    'detail' => $recovery === []
                        ? 'No Recovery Account is configured.'
                        : ($unsealedRecovery > 0 ? 'The Recovery Account is currently unsealed.' : 'The Recovery Account is sealed.'),
                    'last_used_at' => $recovery['last_used_at'] ?? null,
                    'destination' => 'components/system_administrator/recovery_account.php',
                ],
            ],
        ];
    }

    private function continuity(DateTimeImmutable $now): array
    {
        $dayAgo = $now->modify('-24 hours')->format('Y-m-d H:i:s');
        $sales = $this->count('SELECT COUNT(*) FROM sales WHERE sale_date >= ?', [$dayAgo]);
        $shifts = $this->count("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open'");
        $outOfStock = $this->count(
            "SELECT COUNT(*) FROM products p JOIN inventory i ON i.product_id = p.product_id
             WHERE p.status = 'active' AND i.quantity_on_hand <= 0"
        );
        $unresolvedControls = $this->count("SELECT COUNT(*) FROM sale_reversals WHERE status = 'pending'")
            + $this->count("SELECT COUNT(*) FROM inventory_adjustments WHERE status = 'pending'")
            + $this->count(
                "SELECT COUNT(*) FROM cashier_shifts
                 WHERE status = 'closed' AND reviewed_at IS NULL AND cash_variance IS NOT NULL AND ABS(cash_variance) > 0"
            );

        return [
            'sales_flowing' => ['label' => 'Sales activity', 'ok' => $sales > 0, 'value' => $sales, 'detail' => $sales > 0 ? $sales . ' ' . ($sales === 1 ? 'sale was' : 'sales were') . ' recorded in the last 24 hours.' : 'No sales were recorded in the last 24 hours.'],
            'active_shifts' => ['label' => 'Cashier coverage', 'ok' => $shifts > 0, 'value' => $shifts, 'detail' => $shifts > 0 ? $shifts . ' Cashier ' . ($shifts === 1 ? 'shift is' : 'shifts are') . ' open.' : 'No Cashier shift is open.'],
            'severe_inventory_disruption' => ['label' => 'Out-of-stock products', 'ok' => $outOfStock === 0, 'value' => $outOfStock, 'detail' => $outOfStock === 0 ? 'No active products are out of stock.' : $outOfStock . ' active ' . ($outOfStock === 1 ? 'product is' : 'products are') . ' out of stock.'],
            'critical_controls' => ['label' => 'Pending operational reviews', 'ok' => $unresolvedControls === 0, 'value' => $unresolvedControls, 'detail' => $unresolvedControls === 0 ? 'No operational items are awaiting review.' : $unresolvedControls . ' operational ' . ($unresolvedControls === 1 ? 'item requires' : 'items require') . ' review.'],
        ];
    }

    private function permittedActions(string $role): array
    {
        return array_values(array_map(
            static function (array $action): array {
                unset($action['capability']);
                return $action;
            },
            array_filter(
                self::ACTIONS,
                fn(array $action): bool => $this->policy->allows($role, $action['capability'])
            )
        ));
    }

    private function destinationIsPermitted(string $role, string $destination): bool
    {
        $capability = str_contains($destination, '/user_manager/')
            ? RoleCapabilityPolicy::MANAGE_USERS
            : (str_ends_with($destination, '/audit_logs.php')
                ? RoleCapabilityPolicy::VIEW_PLATFORM_AUDIT
                : RoleCapabilityPolicy::PLATFORM_GOVERNANCE);
        return $this->policy->allows($role, $capability);
    }

    private function healthHeadline(string $label, array $checks, bool $forecasting, ?int $ageDays = null): array
    {
        $relevant = array_values(array_filter(
            $checks,
            static fn(array $check): bool => (($check['category'] ?? '') === 'Forecasting') === $forecasting
        ));
        $statuses = array_column($relevant, 'status');
        $status = in_array('critical', $statuses, true)
            ? 'critical'
            : (in_array('warning', $statuses, true) ? 'warning' : ($relevant === [] ? 'warning' : 'healthy'));
        if ($ageDays !== null && $ageDays > 7 && $status === 'healthy') {
            $status = 'warning';
        }
        return [
            'label' => $label,
            'value' => $status === 'healthy' ? 'Healthy' : ($status === 'critical' ? 'Critical' : 'Needs attention'),
            'status' => $status,
            'detail' => count(array_filter($relevant, static fn(array $check): bool => ($check['status'] ?? 'healthy') !== 'healthy'))
                . ' of ' . count($relevant) . ' checks require attention.',
        ];
    }

    private function freshness(DateTimeImmutable $now): array
    {
        return [
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'stale_after' => $now->modify('+' . self::REFRESH_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
            'refresh_seconds' => self::REFRESH_SECONDS,
            'is_stale' => false,
        ];
    }

    private function ageDays(string $date, DateTimeImmutable $now): int
    {
        return max(0, (int)(new DateTimeImmutable($date))->diff($now)->format('%a'));
    }

    private function count(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return (int)$statement->fetchColumn();
    }

    private function row(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
