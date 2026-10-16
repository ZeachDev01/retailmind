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
    public const REFRESH_SECONDS = 300;
    public const RANGES = [7, 30, 90];

    private static array $memoryCache = [];

    private const ACTIONS = [
        ['label' => 'Store Staff', 'description' => 'Manage Cashiers and Inventory Managers', 'icon' => 'bi-people', 'destination' => 'components/user_manager/user_manager.php', 'capability' => RoleCapabilityPolicy::MANAGE_USERS],
        ['label' => 'Sales Exceptions', 'description' => 'Review reversals and unusual discounts', 'icon' => 'bi-receipt-cutoff', 'destination' => 'components/invoice/sales.php?tab=reversals', 'capability' => RoleCapabilityPolicy::MANAGE_SALE_REVERSALS],
        ['label' => 'Stock Issue Oversight', 'description' => 'Inspect Stock Issue history read-only', 'icon' => 'bi-check2-square', 'destination' => 'components/administrator/stock_issues.php', 'capability' => RoleCapabilityPolicy::STORE_OPERATIONS],
        ['label' => 'Fiscal Periods', 'description' => 'Govern Store accounting windows', 'icon' => 'bi-calendar-check', 'destination' => 'components/system_administrator/fiscal_periods.php', 'capability' => RoleCapabilityPolicy::STORE_OPERATIONS],
        ['label' => 'Forecast Review', 'description' => 'Review operational forecast performance', 'icon' => 'bi-graph-up-arrow', 'destination' => 'components/report/predictions.php', 'capability' => RoleCapabilityPolicy::VIEW_FORECASTS],
        ['label' => 'Store Settings', 'description' => 'Set operational attention thresholds', 'icon' => 'bi-sliders', 'destination' => 'components/administrator/store_settings.php', 'capability' => RoleCapabilityPolicy::STORE_OPERATIONS],
        ['label' => 'Database Backup', 'description' => 'Create and download a SQL Store copy', 'icon' => 'bi-database-check', 'destination' => 'components/administrator/database_backup.php', 'capability' => RoleCapabilityPolicy::MANAGE_DATABASE_BACKUP],
    ];

    public function __construct(
        private PDO $pdo,
        private Clock $clock,
        private RoleCapabilityPolicy $policy
    ) {
    }

    public static function invalidateCache(?string $role = null, ?int $days = null): void
    {
        if ($role !== null && $days !== null) {
            unset(self::$memoryCache["{$role}_{$days}"]);
            $file = self::cacheFilePath($role, $days);
            if (is_file($file)) {
                @unlink($file);
            }
            return;
        }

        if ($role !== null) {
            foreach (self::RANGES as $r) {
                unset(self::$memoryCache["{$role}_{$r}"]);
                $file = self::cacheFilePath($role, $r);
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            return;
        }

        self::$memoryCache = [];
        $prefix = substr(md5(__DIR__), 0, 8);
        $files = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'retailmind_' . $prefix . '_dashboard_*.json');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    private static function cacheFilePath(string $role, int $days): string
    {
        $prefix = substr(md5(__DIR__), 0, 8);
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'retailmind_' . $prefix . '_dashboard_' . $role . '_' . $days . '.json';
    }

    private static function isCacheValid(array $entry, int $nowTs, string $todayDatePh, \DateTimeZone $tz): bool
    {
        if (($nowTs - (int)($entry['timestamp'] ?? 0)) >= self::REFRESH_SECONDS) {
            return false;
        }
        $entryDatePh = (new DateTimeImmutable('@' . (int)$entry['timestamp']))->setTimezone($tz)->format('Y-m-d');
        if ($entryDatePh !== $todayDatePh) {
            return false;
        }
        $ns = $entry['data']['net_sales'] ?? null;
        return is_array($ns) && isset($ns['periods']['week'], $ns['periods']['month'], $ns['periods']['today']);
    }

    private static function getCached(string $role, int $days, DateTimeImmutable $now): ?array
    {
        $key = "{$role}_{$days}";
        $nowTs = $now->getTimestamp();
        $tz = new \DateTimeZone('Asia/Manila');
        $todayDatePh = $now->setTimezone($tz)->format('Y-m-d');

        if (isset(self::$memoryCache[$key]) && self::isCacheValid(self::$memoryCache[$key], $nowTs, $todayDatePh, $tz)) {
            return self::$memoryCache[$key]['data'];
        }
        $file = self::cacheFilePath($role, $days);
        if (is_file($file)) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $entry = json_decode($content, true);
                if (is_array($entry) && self::isCacheValid($entry, $nowTs, $todayDatePh, $tz)) {
                    self::$memoryCache[$key] = $entry;
                    return $entry['data'];
                }
            }
        }
        return null;
    }

    private static function setCached(string $role, int $days, DateTimeImmutable $now, array $data): void
    {
        $key = "{$role}_{$days}";
        $entry = [
            'timestamp' => $now->getTimestamp(),
            'data' => $data,
        ];
        self::$memoryCache[$key] = $entry;
        @file_put_contents(self::cacheFilePath($role, $days), json_encode($entry), LOCK_EX);
    }

    public function load(string $actorRole, int $days = 30): array
    {
        if ($actorRole !== 'admin' || !$this->policy->allows($actorRole, RoleCapabilityPolicy::STORE_OPERATIONS)) {
            throw new DomainException('The Store-operations dashboard is available only to the Administrator.');
        }

        $days = in_array($days, self::RANGES, true) ? $days : 30;
        $now = $this->clock->now();

        $cached = self::getCached($actorRole, $days, $now);
        if ($cached !== null) {
            return $cached;
        }

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
            $netSales = $this->netSales($now);

            $data = [
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
                'net_sales' => $netSales,
                'actions' => $this->permittedActions($actorRole),
                'freshness' => $this->freshness($now),
                'message' => $exceptions === [] ? 'No Store-operational exceptions currently require action.' : null,
            ];

            self::setCached($actorRole, $days, $now, $data);
            return $data;
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
                'net_sales' => null,
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
        $inventoryRisks = $this->count("SELECT COUNT(*) FROM inventory_adjustments WHERE status = 'pending'");
        $period = $this->row("SELECT period_name, start_date, end_date, status FROM fiscal_periods WHERE status = 'open' ORDER BY end_date ASC LIMIT 1");
        $periodStatus = $period === [] ? 'not_configured' : ((string)$period['end_date'] < $now->format('Y-m-d') ? 'overdue' : 'open');

        return [
            'sales_cash_exceptions' => $this->headlineItem('Sales & cash exceptions', $reversals + $cashVariances + $discounts, $reversals + $cashVariances + $discounts > 0 ? 'warning' : 'healthy', 'Unresolved reversals, cash variances, and unusual discounts.'),
            'inventory_risks' => $this->headlineItem('Pending Stock Issues', $inventoryRisks, $inventoryRisks > 0 ? 'warning' : 'healthy', 'Awaiting Inventory Manager review; Administrator oversight is read-only.'),
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

    private function netSales(DateTimeImmutable $now): array
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $nowPh = $now->setTimezone($tz);
        $asOfStr = $nowPh->format('Y-m-d H:i:s');

        // Today boundary: local midnight
        $todayStart = $nowPh->setTime(0, 0, 0);
        $todayStartStr = $todayStart->format('Y-m-d H:i:s');
        $currentHour = (int)$nowPh->format('G');

        // Week boundary: starts Monday 00:00:00
        $dayOfWeek = (int)$nowPh->format('N'); // 1 = Monday, 7 = Sunday
        $daysFromMonday = $dayOfWeek - 1;
        $weekStart = $nowPh->modify("-{$daysFromMonday} days")->setTime(0, 0, 0);
        $weekStartStr = $weekStart->format('Y-m-d H:i:s');

        // Month boundary: starts 1st of month 00:00:00
        $monthStart = $nowPh->setDate((int)$nowPh->format('Y'), (int)$nowPh->format('n'), 1)->setTime(0, 0, 0);
        $monthStartStr = $monthStart->format('Y-m-d H:i:s');
        $currentDayOfMonth = (int)$nowPh->format('j');

        $earliestStartStr = min($todayStartStr, $weekStartStr, $monthStartStr);

        $salesRows = $this->rows(
            'SELECT total_amount, sale_date FROM sales WHERE sale_date >= ? AND sale_date <= ?',
            [$earliestStartStr, $asOfStr]
        );
        $refundRows = $this->rows(
            'SELECT refund_amount, created_at FROM cash_refunds WHERE created_at >= ? AND created_at <= ?',
            [$earliestStartStr, $asOfStr]
        );

        // Buckets initialization
        $todayCents = [];
        for ($h = 0; $h <= $currentHour; $h++) {
            $todayCents[$h] = ['sales' => 0, 'refunds' => 0];
        }

        $weekCents = [];
        $weekDays = [];
        for ($d = 0; $d < $dayOfWeek; $d++) {
            $dDate = $weekStart->modify("+{$d} days");
            $weekDays[$d] = [
                'date' => $dDate->format('Y-m-d'),
                'day' => $dDate->format('D'),
                'label' => $dDate->format('D'),
            ];
            $weekCents[$d] = ['sales' => 0, 'refunds' => 0];
        }

        $monthCents = [];
        $monthDays = [];
        for ($d = 1; $d <= $currentDayOfMonth; $d++) {
            $dDate = $monthStart->modify('+' . ($d - 1) . ' days');
            $monthDays[$d] = [
                'date' => $dDate->format('Y-m-d'),
                'day' => $d,
                'label' => $dDate->format('M j'),
            ];
            $monthCents[$d] = ['sales' => 0, 'refunds' => 0];
        }

        // Aggregate sales
        foreach ($salesRows as $row) {
            $saleDt = (new DateTimeImmutable((string)$row['sale_date'], $tz))->setTimezone($tz);
            $saleStr = $saleDt->format('Y-m-d H:i:s');
            $amountCents = (int)round((float)$row['total_amount'] * 100);

            if ($saleStr >= $todayStartStr && $saleStr <= $asOfStr) {
                $h = (int)$saleDt->format('G');
                if ($h >= 0 && $h <= $currentHour) {
                    $todayCents[$h]['sales'] += $amountCents;
                }
            }
            if ($saleStr >= $weekStartStr && $saleStr <= $asOfStr) {
                $dIdx = (int)$saleDt->format('N') - 1;
                if ($dIdx >= 0 && $dIdx < $dayOfWeek) {
                    $weekCents[$dIdx]['sales'] += $amountCents;
                }
            }
            if ($saleStr >= $monthStartStr && $saleStr <= $asOfStr) {
                $dNum = (int)$saleDt->format('j');
                if ($dNum >= 1 && $dNum <= $currentDayOfMonth) {
                    $monthCents[$dNum]['sales'] += $amountCents;
                }
            }
        }

        // Aggregate refunds
        foreach ($refundRows as $row) {
            $refDt = (new DateTimeImmutable((string)$row['created_at'], $tz))->setTimezone($tz);
            $refStr = $refDt->format('Y-m-d H:i:s');
            $amountCents = (int)round((float)$row['refund_amount'] * 100);

            if ($refStr >= $todayStartStr && $refStr <= $asOfStr) {
                $h = (int)$refDt->format('G');
                if ($h >= 0 && $h <= $currentHour) {
                    $todayCents[$h]['refunds'] += $amountCents;
                }
            }
            if ($refStr >= $weekStartStr && $refStr <= $asOfStr) {
                $dIdx = (int)$refDt->format('N') - 1;
                if ($dIdx >= 0 && $dIdx < $dayOfWeek) {
                    $weekCents[$dIdx]['refunds'] += $amountCents;
                }
            }
            if ($refStr >= $monthStartStr && $refStr <= $asOfStr) {
                $dNum = (int)$refDt->format('j');
                if ($dNum >= 1 && $dNum <= $currentDayOfMonth) {
                    $monthCents[$dNum]['refunds'] += $amountCents;
                }
            }
        }

        // Assemble Today
        $todayPoints = [];
        $todaySales = 0;
        $todayRefunds = 0;
        for ($h = 0; $h <= $currentHour; $h++) {
            $salesCents = $todayCents[$h]['sales'];
            $refundsCents = $todayCents[$h]['refunds'];
            $todaySales += $salesCents;
            $todayRefunds += $refundsCents;
            $todayPoints[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'date' => $todayStart->format('Y-m-d'),
                'sales' => (float)($salesCents / 100),
                'refunds' => (float)($refundsCents / 100),
                'net_sales' => (float)(($salesCents - $refundsCents) / 100),
            ];
        }

        // Assemble Week
        $weekPoints = [];
        $weekSales = 0;
        $weekRefunds = 0;
        for ($d = 0; $d < $dayOfWeek; $d++) {
            $salesCents = $weekCents[$d]['sales'];
            $refundsCents = $weekCents[$d]['refunds'];
            $weekSales += $salesCents;
            $weekRefunds += $refundsCents;
            $weekPoints[] = [
                'day' => $weekDays[$d]['day'],
                'label' => $weekDays[$d]['label'],
                'date' => $weekDays[$d]['date'],
                'sales' => (float)($salesCents / 100),
                'refunds' => (float)($refundsCents / 100),
                'net_sales' => (float)(($salesCents - $refundsCents) / 100),
            ];
        }

        // Assemble Month
        $monthPoints = [];
        $monthSales = 0;
        $monthRefunds = 0;
        for ($d = 1; $d <= $currentDayOfMonth; $d++) {
            $salesCents = $monthCents[$d]['sales'];
            $refundsCents = $monthCents[$d]['refunds'];
            $monthSales += $salesCents;
            $monthRefunds += $refundsCents;
            $monthPoints[] = [
                'day' => $monthDays[$d]['day'],
                'label' => $monthDays[$d]['label'],
                'date' => $monthDays[$d]['date'],
                'sales' => (float)($salesCents / 100),
                'refunds' => (float)($refundsCents / 100),
                'net_sales' => (float)(($salesCents - $refundsCents) / 100),
            ];
        }

        $todayData = [
            'period' => 'today',
            'label' => "Today's Net Sales",
            'short_label' => 'Today',
            'interval_type' => 'hourly',
            'interval_label' => 'Hour',
            'status' => 'ongoing',
            'as_of' => $asOfStr,
            'timezone' => 'Asia/Manila',
            'total' => (float)(($todaySales - $todayRefunds) / 100),
            'sales_total' => (float)($todaySales / 100),
            'refunds_total' => (float)($todayRefunds / 100),
            'has_activity' => ($todaySales > 0 || $todayRefunds > 0),
            'points' => $todayPoints,
        ];

        $weekData = [
            'period' => 'week',
            'label' => "This Week's Net Sales",
            'short_label' => 'This Week',
            'interval_type' => 'daily',
            'interval_label' => 'Day',
            'status' => 'ongoing',
            'as_of' => $asOfStr,
            'timezone' => 'Asia/Manila',
            'total' => (float)(($weekSales - $weekRefunds) / 100),
            'sales_total' => (float)($weekSales / 100),
            'refunds_total' => (float)($weekRefunds / 100),
            'has_activity' => ($weekSales > 0 || $weekRefunds > 0),
            'points' => $weekPoints,
        ];

        $monthData = [
            'period' => 'month',
            'label' => "This Month's Net Sales",
            'short_label' => 'This Month',
            'interval_type' => 'daily',
            'interval_label' => 'Day',
            'status' => 'ongoing',
            'as_of' => $asOfStr,
            'timezone' => 'Asia/Manila',
            'total' => (float)(($monthSales - $monthRefunds) / 100),
            'sales_total' => (float)($monthSales / 100),
            'refunds_total' => (float)($monthRefunds / 100),
            'has_activity' => ($monthSales > 0 || $monthRefunds > 0),
            'points' => $monthPoints,
        ];

        return [
            'period' => 'today',
            'status' => 'ongoing',
            'as_of' => $asOfStr,
            'timezone' => 'Asia/Manila',
            'total' => $todayData['total'],
            'sales_total' => $todayData['sales_total'],
            'refunds_total' => $todayData['refunds_total'],
            'has_activity' => $todayData['has_activity'],
            'points' => $todayData['points'],
            'periods' => [
                'today' => $todayData,
                'week' => $weekData,
                'month' => $monthData,
            ],
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
            $items[] = $this->exception('warning', 'Sales reversal', ucfirst((string)$row['reversal_type']) . ' · ' . (string)$row['reason'], (string)$row['created_at'], 'components/invoice/sales.php?tab=reversals');
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
            $items[] = $this->exception('warning', 'Unusual discount', number_format((float)$row['discount_value'], 2) . '% discount on sale #' . (int)$row['sale_id'], (string)$row['sale_date'], 'components/invoice/sales.php?tab=transactions');
        }
        foreach ($this->rows(
            "SELECT ia.adjustment_id, ia.adjustment_qty, ia.adjustment_type, ia.reported_at, p.product_name
             FROM inventory_adjustments ia JOIN products p ON p.product_id = ia.product_id
             WHERE ia.status = 'pending' ORDER BY ia.reported_at ASC LIMIT 20"
        ) as $row) {
            $items[] = $this->exception('warning', 'Pending Stock Issue', (string)$row['product_name'] . ' · ' . (string)$row['adjustment_type'] . ' (' . (int)$row['adjustment_qty'] . ') · Awaiting Inventory Manager review', (string)$row['reported_at'], 'components/administrator/stock_issues.php');
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
        $jobRow = $this->row(
            "SELECT job_id FROM ml_jobs WHERE trigger_type = 'forecast_generation' AND status = 'succeeded' ORDER BY completed_at DESC, job_id DESC LIMIT 1"
        );
        $exceptions = [];
        $wape = null;
        $evaluatedProducts = 0;
        
        if ($jobRow) {
            $jobId = (int)$jobRow['job_id'];
            $rows = $this->rows(
                "SELECT p.product_name, df.readiness_level
                 FROM demand_forecasts df
                 JOIN products p ON p.product_id = df.product_id
                 WHERE df.job_id = ? AND df.readiness_level IN ('Low', 'Insufficient')
                 GROUP BY df.product_id, p.product_name, df.readiness_level",
                [$jobId]
            );
            
            foreach ($rows as $row) {
                $exceptions[] = [
                    'product' => (string)$row['product_name'],
                    'reorder_suggested' => false,
                    
                ];
            }
            
            $evaluations = $this->rows(
                "SELECT horizon_days, mae, rmse, wape FROM ml_model_evaluations ORDER BY evaluation_id DESC LIMIT 3"
            );
            $evalMetrics = [];
            foreach ($evaluations as $eval) {
                $evalMetrics[(int)$eval['horizon_days']] = [
                    'mae' => $eval['mae'] !== null ? (float)$eval['mae'] : null,
                    'rmse' => $eval['rmse'] !== null ? (float)$eval['rmse'] : null,
                    'wape' => $eval['wape'] !== null ? (float)$eval['wape'] : null,
                ];
            }
            if (!empty($evalMetrics)) {
                $evaluatedProducts = $this->count("SELECT COUNT(DISTINCT product_id) FROM demand_forecasts WHERE job_id = ?", [$jobId]);
            }
        }
        
        return [
            'evaluated_products' => $evaluatedProducts,
            'evaluations' => $evalMetrics ?? [],
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
                    ? RoleCapabilityPolicy::VIEW_FORECASTS
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
