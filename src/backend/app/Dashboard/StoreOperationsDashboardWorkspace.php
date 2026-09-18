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

final class StoreOperationsDashboardWorkspace
{
    private const REFRESH_SECONDS = 300;
    private const RANGES = [7, 30, 90];

    private const ACTIONS = [
        ['label' => 'Store Staff', 'description' => 'Manage Cashiers and Inventory Managers', 'icon' => 'bi-people', 'destination' => 'components/user_manager/user_manager.php', 'capability' => RoleCapabilityPolicy::MANAGE_USERS],
        ['label' => 'Sales Exceptions', 'description' => 'Review reversals and unusual discounts', 'icon' => 'bi-receipt-cutoff', 'destination' => 'components/invoice/reversals.php', 'capability' => RoleCapabilityPolicy::MANAGE_SALE_REVERSALS],
        ['label' => 'Store Approvals', 'description' => 'Resolve pending replenishment decisions', 'icon' => 'bi-check2-square', 'destination' => 'components/inventory_management/replenishment_requests.php', 'capability' => RoleCapabilityPolicy::STORE_OPERATIONS],
        ['label' => 'Fiscal Periods', 'description' => 'Govern Store accounting windows', 'icon' => 'bi-calendar-check', 'destination' => 'components/system_administrator/fiscal_periods.php', 'capability' => RoleCapabilityPolicy::STORE_OPERATIONS],
        ['label' => 'Forecast Review', 'description' => 'Review operational forecast performance', 'icon' => 'bi-graph-up-arrow', 'destination' => 'components/report/predictions.php', 'capability' => RoleCapabilityPolicy::VIEW_STORE_REPORTS],
        ['label' => 'Store Settings', 'description' => 'Set operational attention thresholds', 'icon' => 'bi-sliders', 'destination' => 'components/administrator/store_settings.php', 'capability' => RoleCapabilityPolicy::STORE_OPERATIONS],
    ];

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private RoleCapabilityPolicy $policy
    ) {
    }

    public function load(string $actorRole, int $days = 30): array
    {
        if ($actorRole !== 'admin' || !$this->policy->allows($actorRole, RoleCapabilityPolicy::STORE_OPERATIONS)) {
            throw new DomainException('The Store-operations dashboard is available only to the Administrator.');
        }

        $days = in_array($days, self::RANGES, true) ? $days : 30;
        $now = $this->clock->now();
        try {
            $settings = new AttentionSettingsService($this->pdo, $this->clock);
            $thresholds = $settings->thresholdsFor($actorRole);
            $signals = (new DatabaseAttentionSignalSource($this->pdo, $this->clock))->store();
            $attention = array_values(array_filter(
                (new AttentionRuleService($settings, $this->clock))->evaluate($actorRole, $signals),
                fn($item): bool => $this->destinationIsPermitted($actorRole, $item->destination)
            ));
            $exceptions = $this->exceptions($now, $days, $thresholds);
            $headline = $this->headline($now, $days, $thresholds);

            return [
                'role' => $actorRole,
                'range_days' => $days,
                'state' => $exceptions === [] ? 'empty' : 'ready',
                'headline' => $headline,
                'attention' => array_map(static fn($item): array => $item->toArray(), $attention),
                'exceptions' => $exceptions,
                'comparison' => $this->comparison($now, $days, $thresholds),
                'staff' => $this->staff(),
                'shifts' => $this->shifts(),
                'forecast' => $this->forecast(),
                'inventory' => $this->inventory(),
                'actions' => $this->permittedActions($actorRole),
                'freshness' => $this->freshness($now),
                'message' => $exceptions === [] ? 'No Store-operational exceptions currently require action.' : null,
            ];
        } catch (Throwable $exception) {
            error_log('Store-operations dashboard load failed: ' . $exception->getMessage());
            return [
                'role' => $actorRole,
                'range_days' => $days,
                'state' => 'error',
                'headline' => [],
                'attention' => [],
                'exceptions' => [],
                'comparison' => [],
                'staff' => [],
                'shifts' => [],
                'forecast' => [],
                'inventory' => [],
                'actions' => $this->permittedActions($actorRole),
                'freshness' => $this->freshness($now),
                'message' => 'Current Store operations could not be loaded. Refresh to try again.',
            ];
        }
    }

    private function headline(DateTimeImmutable $now, int $days, array $thresholds): array
    {
        $start = $now->modify("-{$days} days")->format('Y-m-d H:i:s');
        $end = $now->format('Y-m-d H:i:s');
        $reversals = $this->count("SELECT COUNT(*) FROM sale_reversals WHERE status = 'pending'");
        $cashVariances = $this->count(
            "SELECT COUNT(*) FROM cashier_shifts WHERE status = 'closed' AND reviewed_at IS NULL AND cash_variance IS NOT NULL AND ABS(cash_variance) >= CAST(? AS DECIMAL(12,2))",
            [(float)$thresholds['cash_variance_amount']]
        );
        $discounts = $this->count(
            "SELECT COUNT(*) FROM sales WHERE discount_type = 'percentage' AND discount_value >= ? AND sale_date >= ? AND sale_date < ?",
            [(float)$thresholds['unusual_discount_percent'], $start, $end]
        );
        $approvals = $this->count("SELECT COUNT(*) FROM replenishment_requests WHERE status = 'pending'");
        $inventoryRisks = $this->count("SELECT COUNT(*) FROM inventory_adjustments WHERE status = 'pending'");
        $period = $this->row("SELECT period_name, start_date, end_date, status FROM fiscal_periods WHERE status = 'open' ORDER BY end_date ASC LIMIT 1");
        $periodStatus = $period === [] ? 'not_configured' : ((string)$period['end_date'] < $now->format('Y-m-d') ? 'overdue' : 'open');

        return [
            'sales_cash_exceptions' => $this->headlineItem('Sales & cash exceptions', $reversals + $cashVariances + $discounts, $reversals + $cashVariances + $discounts > 0 ? 'warning' : 'healthy', 'Unresolved reversals, cash variances, and unusual discounts.'),
            'pending_approvals' => $this->headlineItem('Pending approvals', $approvals, $approvals > 0 ? 'warning' : 'healthy', 'Replenishment requests awaiting an Administrator decision.'),
            'inventory_risks' => $this->headlineItem('Escalated inventory risks', $inventoryRisks, $inventoryRisks > 0 ? 'warning' : 'healthy', 'Inventory issues escalated for Administrator oversight.'),
            'fiscal_period' => [
                'label' => 'Current Fiscal Period',
                'value' => $period['period_name'] ?? 'Not configured',
                'status' => $periodStatus,
                'detail' => $period === [] ? 'No open Fiscal Period is recorded.' : 'Ends ' . (string)$period['end_date'] . '.',
            ],
        ];
    }

    private function comparison(DateTimeImmutable $now, int $days, array $thresholds): array
    {
        $currentStart = $now->modify("-{$days} days");
        $previousStart = $currentStart->modify("-{$days} days");
        $current = $this->periodMetrics($currentStart, $now, $thresholds);
        $previous = $this->periodMetrics($previousStart, $currentStart, $thresholds);

        return [
            'label' => 'Previous equivalent period',
            'current_period' => ['start' => $currentStart->format('Y-m-d'), 'end' => $now->format('Y-m-d')],
            'previous_period' => ['start' => $previousStart->format('Y-m-d'), 'end' => $currentStart->format('Y-m-d')],
            'sales' => $this->comparisonItem($current['sales'], $previous['sales']),
            'transactions' => $this->comparisonItem($current['transactions'], $previous['transactions']),
            'unusual_discounts' => $this->comparisonItem($current['unusual_discounts'], $previous['unusual_discounts']),
            'reversals' => $this->comparisonItem($current['reversals'], $previous['reversals']),
        ];
    }

    private function periodMetrics(DateTimeImmutable $start, DateTimeImmutable $end, array $thresholds): array
    {
        $startValue = $start->format('Y-m-d H:i:s');
        $endValue = $end->format('Y-m-d H:i:s');
        $sales = $this->row(
            'SELECT COALESCE(SUM(total_amount), 0) AS sales, COUNT(*) AS transactions FROM sales WHERE sale_date >= ? AND sale_date < ?',
            [$startValue, $endValue]
        );
        return [
            'sales' => (float)($sales['sales'] ?? 0),
            'transactions' => (int)($sales['transactions'] ?? 0),
            'unusual_discounts' => $this->count(
                "SELECT COUNT(*) FROM sales WHERE discount_type = 'percentage' AND discount_value >= ? AND sale_date >= ? AND sale_date < ?",
                [(float)$thresholds['unusual_discount_percent'], $startValue, $endValue]
            ),
            'reversals' => $this->count(
                'SELECT COUNT(*) FROM sale_reversals WHERE created_at >= ? AND created_at < ?',
                [$startValue, $endValue]
            ),
        ];
    }

    private function exceptions(DateTimeImmutable $now, int $days, array $thresholds): array
    {
        $start = $now->modify("-{$days} days")->format('Y-m-d H:i:s');
        $end = $now->format('Y-m-d H:i:s');
        $items = [];
        foreach ($this->rows(
            "SELECT sr.reversal_id, sr.reversal_type, sr.reason, sr.created_at, s.total_amount
             FROM sale_reversals sr JOIN sales s ON s.sale_id = sr.sale_id
             WHERE sr.status = 'pending' ORDER BY sr.created_at ASC LIMIT 20"
        ) as $row) {
            $items[] = $this->exception('warning', 'Sales reversal', ucfirst((string)$row['reversal_type']) . ' · ' . (string)$row['reason'], (string)$row['created_at'], 'components/invoice/reversals.php');
        }
        foreach ($this->rows(
            "SELECT cs.shift_id, cs.cash_variance, cs.closed_at, u.full_name
             FROM cashier_shifts cs JOIN users u ON u.user_id = cs.cashier_id
             WHERE cs.status = 'closed' AND cs.reviewed_at IS NULL AND cs.cash_variance IS NOT NULL AND ABS(cs.cash_variance) >= CAST(? AS DECIMAL(12,2))
             ORDER BY cs.closed_at ASC LIMIT 20",
            [(float)$thresholds['cash_variance_amount']]
        ) as $row) {
            $items[] = $this->exception('critical', 'Cash variance', (string)$row['full_name'] . ' · ' . number_format((float)$row['cash_variance'], 2), (string)$row['closed_at'], 'components/report/report_generation.php');
        }
        foreach ($this->rows(
            "SELECT sale_id, discount_value, discount_amount, sale_date FROM sales
             WHERE discount_type = 'percentage' AND discount_value >= ? AND sale_date >= ? AND sale_date < ?
             ORDER BY sale_date DESC LIMIT 20",
            [(float)$thresholds['unusual_discount_percent'], $start, $end]
        ) as $row) {
            $items[] = $this->exception('warning', 'Unusual discount', number_format((float)$row['discount_value'], 2) . '% discount on sale #' . (int)$row['sale_id'], (string)$row['sale_date'], 'components/invoice/sales_history.php');
        }
        foreach ($this->rows(
            "SELECT rr.request_id, rr.request_qty, rr.request_date, p.product_name
             FROM replenishment_requests rr JOIN products p ON p.product_id = rr.product_id
             WHERE rr.status = 'pending' ORDER BY rr.request_date ASC LIMIT 20"
        ) as $row) {
            $items[] = $this->exception('warning', 'Pending approval', (string)$row['product_name'] . ' · ' . (int)$row['request_qty'] . ' unit(s)', (string)$row['request_date'], 'components/inventory_management/replenishment_requests.php');
        }
        foreach ($this->rows(
            "SELECT ia.adjustment_id, ia.adjustment_qty, ia.adjustment_type, ia.reported_at, p.product_name
             FROM inventory_adjustments ia JOIN products p ON p.product_id = ia.product_id
             WHERE ia.status = 'pending' ORDER BY ia.reported_at ASC LIMIT 20"
        ) as $row) {
            $items[] = $this->exception('warning', 'Inventory escalation', (string)$row['product_name'] . ' · ' . (string)$row['adjustment_type'] . ' (' . (int)$row['adjustment_qty'] . ')', (string)$row['reported_at'], 'components/inventory_management/inventory_insights.php');
        }

        $severity = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($items, static fn(array $left, array $right): int => ($severity[$left['severity']] <=> $severity[$right['severity']]) ?: strcmp($left['detected_at'], $right['detected_at']));
        return array_slice($items, 0, 30);
    }

    private function staff(): array
    {
        $row = $this->row(
            "SELECT
                COALESCE(SUM(CASE WHEN r.role_name = 'cashier' AND u.status = 'active' THEN 1 ELSE 0 END), 0) AS active_cashiers,
                COALESCE(SUM(CASE WHEN r.role_name = 'inventory_manager' AND u.status = 'active' THEN 1 ELSE 0 END), 0) AS active_inventory_managers,
                COALESCE(SUM(CASE WHEN r.role_name IN ('cashier', 'inventory_manager') AND u.status = 'disabled' THEN 1 ELSE 0 END), 0) AS disabled_staff
             FROM users u JOIN roles r ON r.role_id = u.role_id"
        );
        return array_map('intval', $row);
    }

    private function shifts(): array
    {
        return [
            'open' => $this->count("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open'"),
            'unreviewed_variances' => $this->count("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'closed' AND reviewed_at IS NULL AND cash_variance IS NOT NULL AND ABS(cash_variance) > 0"),
        ];
    }

    private function forecast(): array
    {
        $rows = $this->rows(
            "SELECT sp.product_id, sp.forecast_value, sp.predicted_demand_next_30_days, sp.actual_demand,
                    sp.reorder_suggested, sp.confidence_score, p.product_name
             FROM stock_predictions sp JOIN products p ON p.product_id = sp.product_id
             WHERE sp.prediction_id = (
                SELECT MAX(sp2.prediction_id) FROM stock_predictions sp2 WHERE sp2.product_id = sp.product_id
             )"
        );
        $errors = [];
        $exceptions = [];
        foreach ($rows as $row) {
            $forecast = (float)($row['forecast_value'] ?? $row['predicted_demand_next_30_days'] ?? 0);
            $actual = $row['actual_demand'];
            if ($actual !== null && (float)$actual > 0) {
                $errors[] = abs($forecast - (float)$actual) / (float)$actual * 100;
            }
            if ((int)$row['reorder_suggested'] === 1 || (float)$row['confidence_score'] < 0.5) {
                $exceptions[] = [
                    'product' => (string)$row['product_name'],
                    'reorder_suggested' => (bool)$row['reorder_suggested'],
                    'confidence' => (float)$row['confidence_score'],
                ];
            }
        }
        $mape = $errors === [] ? null : array_sum($errors) / count($errors);
        return [
            'evaluated_products' => count($errors),
            'accuracy_percent' => $mape === null ? null : round(max(0, 100 - $mape), 1),
            'operational_exceptions' => count($exceptions),
            'exceptions' => $exceptions,
        ];
    }

    private function inventory(): array
    {
        return [
            'escalated' => $this->count("SELECT COUNT(*) FROM inventory_adjustments WHERE status = 'pending'"),
            'out_of_stock' => $this->count("SELECT COUNT(*) FROM products p JOIN inventory i ON i.product_id = p.product_id WHERE p.status = 'active' AND i.quantity_on_hand <= 0"),
            'low_stock' => $this->count("SELECT COUNT(*) FROM products p JOIN inventory i ON i.product_id = p.product_id WHERE p.status = 'active' AND i.quantity_on_hand > 0 AND i.quantity_on_hand <= p.reorder_level"),
        ];
    }

    private function permittedActions(string $role): array
    {
        return array_values(array_map(
            static function (array $action): array {
                unset($action['capability']);
                return $action;
            },
            array_filter(self::ACTIONS, fn(array $action): bool => $this->policy->allows($role, $action['capability']))
        ));
    }

    private function destinationIsPermitted(string $role, string $destination): bool
    {
        $capability = str_contains($destination, '/reversals.php')
            ? RoleCapabilityPolicy::MANAGE_SALE_REVERSALS
            : (str_contains($destination, '/sales_history.php')
                ? RoleCapabilityPolicy::VIEW_SALES_HISTORY
                : (str_contains($destination, '/report/')
                    ? RoleCapabilityPolicy::VIEW_STORE_REPORTS
                    : (str_contains($destination, '/inventory_insights.php')
                        ? RoleCapabilityPolicy::VIEW_INVENTORY
                        : RoleCapabilityPolicy::STORE_OPERATIONS)));
        return $this->policy->allows($role, $capability);
    }

    private function headlineItem(string $label, int $value, string $status, string $detail): array
    {
        return compact('label', 'value', 'status', 'detail');
    }

    private function comparisonItem(float|int $current, float|int $previous): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'change_percent' => (float)$previous === 0.0 ? null : round((($current - $previous) / abs($previous)) * 100, 1),
        ];
    }

    private function exception(string $severity, string $title, string $detail, string $detectedAt, string $destination): array
    {
        return ['severity' => $severity, 'title' => $title, 'detail' => $detail, 'detected_at' => $detectedAt, 'destination' => $destination];
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

    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
