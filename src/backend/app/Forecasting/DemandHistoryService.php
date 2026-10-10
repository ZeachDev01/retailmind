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

    public function __construct()
    {
        $this->timezone = new DateTimeZone('Asia/Manila');
        $this->calendar = new StoreCalendar();
        $this->driverService = new ScheduledDemandDriverService();
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
            // Sold units within the day up to the effective cutoff
            $soldStmt = $pdo->prepare("
                SELECT COALESCE(SUM(si.quantity), 0)
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.sale_id
                WHERE si.product_id = ?
                  AND s.sale_date >= ? AND s.sale_date <= ?
            ");
            $soldStmt->execute([$productId, $startOfDay, $effectiveEnd]);
            $sold = (int)$soldStmt->fetchColumn();
            
            // Approved sale reversals known by the cutoff
            $reversedStmt = $pdo->prepare("
                SELECT COALESCE(SUM(sri.quantity), 0)
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
            
            // Cash refunds created before the cutoff
            $refundedStmt = $pdo->prepare("
                SELECT COALESCE(SUM(cri.quantity), 0)
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
            if ($calStatus === null) {
                $classification = 'missing_evidence';
            } elseif ($calStatus === 'closed' || $calStatus === 'holiday' || $calStatus === 'exceptional_closure') {
                $classification = 'closed';
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
        $stmt = $pdo->prepare('SELECT si.product_id, MIN(DATE(s.sale_date)) AS first_date
            FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
            WHERE s.sale_date <= ? GROUP BY si.product_id ORDER BY si.product_id');
        $stmt->execute([$throughDate . ' 23:59:59']);
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
}
