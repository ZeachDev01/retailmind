<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use DateTime;
use DateTimeZone;
use App\Store\StoreCalendar;
use App\Store\ScheduledDemandDriverService;
use Throwable;

class DemandHistoryService
{
    private DateTimeZone $timezone;
    private StoreCalendar $calendar;
    private ScheduledDemandDriverService $driverService;
    /**
     * Keyed by the connection itself so a destroyed and replaced PDO cannot
     * inherit another connection's columns.
     *
     * @var \WeakMap<PDO, array<string, array<string, true>>>
     */
    private \WeakMap $columnCache;

    public function __construct()
    {
        $this->timezone = new DateTimeZone('Asia/Manila');
        $this->calendar = new StoreCalendar();
        $this->driverService = new ScheduledDemandDriverService();
        $this->columnCache = new \WeakMap();
    }

    /**
     * Prepares cutoff-reproducible daily demand evidence for a single Store product.
     * Enforces Philippine business dates, audit cutoffs, and genuine zero vs stockout distinctions.
     */
    public function getDailyEvidence(PDO $pdo, int $productId, string $startDate, string $endDate, string $cutoff): array
    {
        $current = new DateTime($startDate, $this->timezone);
        $end = new DateTime($endDate, $this->timezone);
        
        $results = [];
        
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $startOfDay = $dateStr . ' 00:00:00';
            $endOfDay = $dateStr . ' 23:59:59';
            $effectiveEnd = min($endOfDay, $cutoff);
            
            // Check if the requested date is entirely in the future relative to the cutoff
            if ($startOfDay > $cutoff) {
                $results[] = [
                    'date' => $dateStr,
                    'valid_sales_units' => 0,
                    'observed_gross_sales' => 0,
                    'reversed_units' => 0,
                    'refunded_units' => 0,
                    'calendar_status' => null,
                    'end_of_day_stock' => 0,
                    'stock_evidence_known' => false,
                    'classification' => 'future_unobserved',
                    'scheduled_drivers' => [],
                    'has_scheduled_driver' => false
                ];
                $current->modify('+1 day');
                continue;
            }

            // 1. Calendar Status at cutoff
            $calStatus = $this->calendar->getCalendarDateStatusAtCutoff($pdo, $dateStr, $cutoff);
            
            // 2. Stock context reconstruction from audited movements at cutoff
            $stockMovementsStmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt, COALESCE(SUM(change_qty), 0) AS total_qty
                FROM stock_movements
                WHERE product_id = ? AND moved_at <= ?
            ");
            $stockMovementsStmt->execute([$productId, $effectiveEnd]);
            $stockRow = $stockMovementsStmt->fetch(PDO::FETCH_ASSOC);
            $hasStockEvidence = ((int)($stockRow['cnt'] ?? 0)) > 0;
            $endOfDayStock = (int)($stockRow['total_qty'] ?? 0);
            
            // 3. Observed Sales & Historical Attribution
            // Sold units within the day up to the effective cutoff, in Base Units.
            $soldStmt = $pdo->prepare("
                SELECT COUNT(*) AS records, COALESCE(SUM(" . $this->saleUnitsExpression($pdo, 'si') . "), 0) AS units
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.sale_id
                WHERE si.product_id = ?
                  AND s.sale_date >= ? AND s.sale_date <= ?
            ");
            $soldStmt->execute([$productId, $startOfDay, $effectiveEnd]);
            $sales = $soldStmt->fetch(PDO::FETCH_ASSOC);
            $sold = (int)$sales['units'];
            $imported = false;
            if ((int)$sales['records'] === 0 && $endOfDay <= $cutoff) {
                // Imported totals certify historical observations, not their upload date.
                $importStmt = $pdo->prepare('SELECT quantity FROM forecast_sales_imports WHERE product_id = ? AND sale_date = ?
                    AND NOT EXISTS (SELECT 1 FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
                        WHERE si.product_id = ? AND s.sale_date >= ? AND s.sale_date <= ?)');
                $importStmt->execute([$productId, $dateStr, $productId, $startOfDay, $endOfDay]);
                $imported = $importStmt->fetchColumn();
                if ($imported !== false) $sold = (int)$imported;
            }
            
            // Approved sale reversals known by the cutoff, in Base Units
            $reversedStmt = $pdo->prepare("
                SELECT COALESCE(SUM(" . $this->reversalUnitsExpression($pdo, 'sri', 'si') . "), 0)
                FROM sale_reversal_items sri
                JOIN sale_reversals sr ON sri.reversal_id = sr.reversal_id
                JOIN sale_items si ON sri.sale_item_id = si.sale_item_id
                JOIN sales s ON si.sale_id = s.sale_id
                WHERE sri.product_id = ?
                  AND s.sale_date >= ? AND s.sale_date <= ?
                  AND sr.status = 'approved'
                  AND sr.approved_at <= ?
            ");
            $reversedStmt->execute([$productId, $startOfDay, $effectiveEnd, $cutoff]);
            $reversed = (int)$reversedStmt->fetchColumn();
            
            // Cash refunds created before the cutoff, in Base Units
            $refundedStmt = $pdo->prepare("
                SELECT COALESCE(SUM(" . $this->refundUnitsExpression($pdo, 'cri', 'si') . "), 0)
                FROM cash_refund_items cri
                JOIN cash_refunds cr ON cri.refund_id = cr.refund_id
                JOIN sale_items si ON cri.sale_item_id = si.sale_item_id
                JOIN sales s ON si.sale_id = s.sale_id
                WHERE cri.product_id = ?
                  AND s.sale_date >= ? AND s.sale_date <= ?
                  AND cr.created_at <= ?
            ");
            $refundedStmt->execute([$productId, $startOfDay, $effectiveEnd, $cutoff]);
            $refunded = (int)$refundedStmt->fetchColumn();
            
            $validSalesUnits = max(0, $sold - $reversed - $refunded);
            
            // 4. Scheduled Demand Drivers known by cutoff
            $effectiveDrivers = [];
            try {
                $effectiveDrivers = $this->driverService->getEffectiveDrivers($pdo, $productId, $dateStr, $cutoff);
            } catch (Throwable $e) {
                // Degrade gracefully if drivers table not initialized in minimal tests
            }
            
            // 5. Semantic Classification: distinguish Open, Closed, Stockout, Missing
            $classification = 'missing_evidence';
            if ($calStatus === 'closed' || $calStatus === 'holiday' || $calStatus === 'exceptional_closure') {
                $classification = 'closed';
            } elseif ($imported !== false) {
                if ($validSalesUnits > 0) {
                    $classification = 'valid_demand';
                } elseif ($hasStockEvidence && $endOfDayStock <= 0) {
                    $classification = 'stockout_censored';
                } else {
                    $classification = 'genuine_zero';
                }
            } elseif ($calStatus === 'open') {
                if ($validSalesUnits > 0) {
                    $classification = 'valid_demand';
                } elseif ($hasStockEvidence && $endOfDayStock > 0) {
                    $classification = 'genuine_zero';
                } elseif ($hasStockEvidence && $endOfDayStock <= 0) {
                    $classification = 'stockout_censored';
                } else {
                    // Store open but no stock tracking evidence exists: unknown evidence
                    $classification = 'missing_evidence';
                }
            }
            
            $results[] = [
                'date' => $dateStr,
                'valid_sales_units' => $validSalesUnits,
                'observed_gross_sales' => $sold,
                'reversed_units' => $reversed,
                'refunded_units' => $refunded,
                'calendar_status' => $calStatus,
                'end_of_day_stock' => $endOfDayStock,
                'stock_evidence_known' => $hasStockEvidence,
                'classification' => $classification,
                'scheduled_drivers' => $effectiveDrivers,
                'has_scheduled_driver' => !empty($effectiveDrivers)
            ];
            
            $current->modify('+1 day');
        }
        
        return $results;
    }

    /** Versioned daily evidence makes corrections available only to later forecast origins. */
    public function getObservedHistory(PDO $pdo, string $throughDate): array
    {
        $corrections = $pdo->prepare("SELECT si.product_id, DATE(s.sale_date) AS date, DATE(cr.created_at) AS available_date
            FROM cash_refund_items cri JOIN cash_refunds cr ON cr.refund_id = cri.refund_id
            JOIN sale_items si ON si.sale_item_id = cri.sale_item_id JOIN sales s ON s.sale_id = si.sale_id
            WHERE cr.created_at <= ? AND DATE(cr.created_at) > DATE(s.sale_date)
            UNION
            SELECT si.product_id, DATE(s.sale_date), DATE(sr.approved_at)
            FROM sale_reversal_items sri JOIN sale_reversals sr ON sr.reversal_id = sri.reversal_id
            JOIN sale_items si ON si.sale_item_id = sri.sale_item_id JOIN sales s ON s.sale_id = si.sale_id
            WHERE sr.status = 'approved' AND sr.approved_at <= ? AND DATE(sr.approved_at) > DATE(s.sale_date)");
        $corrections->execute([$throughDate . ' 23:59:59', $throughDate . ' 23:59:59']);
        $revisions = [];
        foreach ($corrections->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $revisions[$row['product_id']][$row['date']][] = $row['available_date'];
        }
        $calendar = $pdo->prepare('SELECT calendar_date, DATE(recorded_at) AS available_date FROM store_calendar
            WHERE recorded_at <= ? AND DATE(recorded_at) > calendar_date');
        $calendar->execute([$throughDate . ' 23:59:59']);
        $calendarRevisions = [];
        foreach ($calendar->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $calendarRevisions[$row['calendar_date']][] = $row['available_date'];
        }
        $stmt = $pdo->prepare('SELECT product_id, MIN(sale_date) AS first_date FROM (
            SELECT si.product_id, DATE(s.sale_date) AS sale_date
            FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE s.sale_date <= ?
            UNION ALL
            SELECT product_id, sale_date FROM forecast_sales_imports WHERE sale_date <= ?
            ) history GROUP BY product_id ORDER BY product_id');
        $stmt->execute([$throughDate . ' 23:59:59', $throughDate]);
        $history = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $day = new DateTime($product['first_date'], $this->timezone);
            $end = new DateTime($throughDate, $this->timezone);
            while ($day <= $end) {
                $date = $day->format('Y-m-d');
                $cutoffs = array_unique(array_merge([$date], $revisions[$product['product_id']][$date] ?? [], $calendarRevisions[$date] ?? []));
                sort($cutoffs);
                foreach ($cutoffs as $cutoff) {
                    $evidence = $this->getDailyEvidence($pdo, (int)$product['product_id'], $date, $date, $cutoff . ' 23:59:59')[0];
                    $known = in_array($evidence['classification'], ['valid_demand', 'genuine_zero'], true);
                    $history[] = ['date' => $date, 'product_id' => (int)$product['product_id'],
                        'units_sold' => $known ? $evidence['valid_sales_units'] : null, 'available_date' => $cutoff];
                }
                $day->modify('+1 day');
            }
        }
        return $history;
    }

    /** Use a common observed suffix so one immature product cannot fail the shared backtest. */
    public function getTrainingHistory(PDO $pdo, string $throughDate): array
    {
        // shortcut: closures break consecutive windows; add masked-window support if regular closures prevent training.
        $history = $this->getObservedHistory($pdo, $throughDate);
        $days = [];
        foreach ($history as $row) {
            $id = $row['product_id'];
            $date = $row['date'];
            $days[$id][$date] = ($days[$id][$date] ?? true) && $row['units_sold'] !== null;
        }
        $eligibility = [];
        $starts = [];
        foreach ($pdo->query("SELECT product_id FROM products WHERE status = 'active' ORDER BY product_id")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $day = new \DateTimeImmutable($throughDate, $this->timezone);
            $count = 0;
            while (!empty($days[$id][$day->format('Y-m-d')])) {
                $count++;
                $day = $day->modify('-1 day');
            }
            $eligible = $count >= 140;
            $eligibility[] = ['product_id' => (int)$id, 'eligible' => $eligible,
                'consecutive_usable_days' => $count,
                'reason' => $eligible ? null : 'Needs 140 consecutive usable days through ' . $throughDate];
            if ($eligible) {
                $starts[(int)$id] = $day->modify('+1 day')->format('Y-m-d');
            }
        }
        $start = $starts ? max($starts) : null;
        $selected = array_values(array_filter($history, static fn(array $row): bool =>
            isset($starts[$row['product_id']]) && $row['date'] >= $start));
        return ['history' => $selected, 'eligibility' => $eligibility, 'training_start' => $start,
            'through_date' => $throughDate];
    }

    /**
     * Summarizes demand history readiness according to ADR-0005.
     */
    public function getReadinessSummary(PDO $pdo, int $productId, string $startDate, string $endDate, string $cutoff): array
    {
        $evidence = $this->getDailyEvidence($pdo, $productId, $startDate, $endDate, $cutoff);
        
        $summary = [
            'usable_history_days' => 0,
            'total_calendar_days' => count($evidence),
            'readiness_level' => 'Insufficient',
            'limitation_marker' => 'Usable history under 56 days (minimum required for reliable forecasting)',
            'excluded_days' => [
                'closed' => 0,
                'missing_evidence' => 0,
                'stockout_censored' => 0,
                'future_unobserved' => 0
            ],
            'included_days' => [
                'valid_demand' => 0,
                'genuine_zero' => 0
            ]
        ];
        
        foreach ($evidence as $day) {
            $class = $day['classification'];
            if (in_array($class, ['valid_demand', 'genuine_zero'], true)) {
                $summary['usable_history_days']++;
                $summary['included_days'][$class]++;
            } else {
                if (!isset($summary['excluded_days'][$class])) {
                    $summary['excluded_days'][$class] = 0;
                }
                $summary['excluded_days'][$class]++;
            }
        }
        
        // ADR-0005 Readiness Policy:
        $usable = $summary['usable_history_days'];
        if ($usable < 56) {
            $summary['readiness_level'] = 'Insufficient';
            $summary['limitation_marker'] = 'Usable history under 56 days (minimum required for reliable forecasting)';
        } elseif ($usable < 180) {
            $summary['readiness_level'] = 'Low';
            $summary['limitation_marker'] = 'Early history (56-179 days)';
        } elseif ($usable < 365) {
            $summary['readiness_level'] = 'Medium';
            $summary['limitation_marker'] = 'Standard history (180-364 days)';
        } else {
            $summary['readiness_level'] = 'High';
            $summary['limitation_marker'] = null;
        }
        
        return $summary;
    }

    /** Sale quantities in Base Units; a Sales Pack line contributes its contents. */
    private function saleUnitsExpression(PDO $pdo, string $alias): string
    {
        $columns = $this->columns($pdo, 'sale_items');
        if (!isset($columns['selling_form'], $columns['pack_size'])) {
            return "{$alias}.quantity";
        }

        return "(CASE WHEN {$alias}.selling_form = 'pack' AND {$alias}.pack_size IS NOT NULL"
            . " THEN {$alias}.quantity * {$alias}.pack_size ELSE {$alias}.quantity END)";
    }

    /** Reversal quantities in Base Units, read from the reversal line but converted by the sold item's form. */
    private function reversalUnitsExpression(PDO $pdo, string $alias, string $saleAlias): string
    {
        $columns = $this->columns($pdo, 'sale_items');
        if (!isset($columns['selling_form'], $columns['pack_size'])) {
            return "{$alias}.quantity";
        }

        return "(CASE WHEN {$saleAlias}.selling_form = 'pack' AND {$saleAlias}.pack_size IS NOT NULL"
            . " THEN {$alias}.quantity * {$saleAlias}.pack_size ELSE {$alias}.quantity END)";
    }

    /** Refund quantities in Base Units, preferring the refund line's historical form. */
    private function refundUnitsExpression(PDO $pdo, string $alias, string $saleAlias): string
    {
        $refundColumns = $this->columns($pdo, 'cash_refund_items');
        $saleColumns = $this->columns($pdo, 'sale_items');

        $formExpr = isset($refundColumns['selling_form'])
            ? "{$alias}.selling_form"
            : (isset($saleColumns['selling_form']) ? "{$saleAlias}.selling_form" : "'piece'");
        $packExpr = isset($refundColumns['pack_size'])
            ? "{$alias}.pack_size"
            : (isset($saleColumns['pack_size']) ? "{$saleAlias}.pack_size" : 'NULL');

        return "(CASE WHEN {$formExpr} = 'pack' AND {$packExpr} IS NOT NULL"
            . " THEN {$alias}.quantity * {$packExpr} ELSE {$alias}.quantity END)";
    }

    /** @return array<string, true> */
    private function columns(PDO $pdo, string $table): array
    {
        $cached = $this->columnCache[$pdo] ?? [];
        if (isset($cached[$table])) {
            return $cached[$table];
        }

        $columns = [];
        $probed = true;
        try {
            if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $columns[(string)$column['name']] = true;
                }
            } else {
                foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $columns[(string)$column['Field']] = true;
                }
            }
        } catch (Throwable) {
            // A failed probe must not be cached: treating it as "no columns"
            // would disable pack conversion for the life of this connection.
            $probed = false;
        }

        if (!$probed) {
            return [];
        }

        $cached[$table] = $columns;
        $this->columnCache[$pdo] = $cached;
        return $columns;
    }
}
