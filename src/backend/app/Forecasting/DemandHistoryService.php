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
        
        // Imported-only history has no Store evidence to reconstruct per day.
        // Keep the same classification below, and fall back as soon as Store evidence exists.
        $importOnly = false;
        $importedQuantities = [];
        try {
            $importOnly = (bool)$pdo->query('SELECT
                NOT EXISTS (SELECT 1 FROM sales)
                AND NOT EXISTS (SELECT 1 FROM stock_movements)
                AND NOT EXISTS (SELECT 1 FROM store_calendar)
                AND NOT EXISTS (SELECT 1 FROM store_weekly_schedule)
                AND NOT EXISTS (SELECT 1 FROM scheduled_demand_drivers)')->fetchColumn();
            if ($importOnly) {
                $imports = $pdo->prepare('SELECT sale_date, quantity FROM forecast_sales_imports
                    WHERE product_id = ? AND sale_date >= ? AND sale_date <= ?');
                $imports->execute([$productId, $startDate, $endDate]);
                $importedQuantities = $imports->fetchAll(PDO::FETCH_KEY_PAIR);
            }
        } catch (Throwable) {
            $importOnly = false;
        }

        $results = [];
        
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $startOfDay = $dateStr . ' 00:00:00';
            $endOfDay = $dateStr . ' 23:59:59';
            $effectiveEnd = min($endOfDay, $cutoff);
            
            // Check if the requested date is entirely in the future relative to the cutoff
            if ($startOfDay > $cutoff) {
                $futureDrivers = [];
                try {
                    $futureDrivers = $this->driverService->getEffectiveDrivers($pdo, $productId, $dateStr, $cutoff);
                } catch (Throwable) {
                }
                $futurePrice = null;
                try {
                    $futurePrice = $this->driverService->getEffectivePrice($pdo, $productId, $dateStr, $cutoff);
                } catch (Throwable) {
                }
                $futurePromo = null;
                try {
                    $futurePromo = $this->driverService->resolveIsOnPromotion(
                        $pdo,
                        $productId,
                        $dateStr,
                        $cutoff,
                        $futureDrivers,
                        $calStatus,
                        false
                    );
                } catch (Throwable) {
                }
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
                    'scheduled_drivers' => $futureDrivers,
                    'has_scheduled_driver' => !empty($futureDrivers),
                    'price' => $futurePrice,
                    'is_on_promotion' => $futurePromo,
                    'out_of_stock' => null,
                    'source' => null,
                    'recorded_sales_units' => 0,
                ];
                $current->modify('+1 day');
                continue;
            }

            // 1. Calendar Status at cutoff
            $calEvidence = null;
            if (!$importOnly && method_exists($this->calendar, 'getCalendarDateEvidenceAtCutoff')) {
                try {
                    $calEvidence = $this->calendar->getCalendarDateEvidenceAtCutoff($pdo, $dateStr, $cutoff);
                } catch (Throwable) {
                }
            }
            $calStatus = $importOnly ? null : ($calEvidence ? $calEvidence['status'] : $this->calendar->getCalendarDateStatusAtCutoff($pdo, $dateStr, $cutoff));
            $hasSaleOpening = $calEvidence
                ? (!empty($calEvidence['has_sale_override']) || ($calEvidence['source'] ?? null) === 'sale')
                : false;

            // 2. Store-level sales evidence at cutoff
            $storeSalesCount = 0;
            $firstStoreSale = null;
            if (!$importOnly) {
                try {
                    $storeSalesStmt = $pdo->prepare("
                        SELECT COUNT(*) AS cnt, MIN(sale_date) AS first_sale
                        FROM sales
                        WHERE sale_date >= ? AND sale_date <= ?
                    ");
                    $storeSalesStmt->execute([$startOfDay, $effectiveEnd]);
                    $storeSalesRow = $storeSalesStmt->fetch(PDO::FETCH_ASSOC);
                    $storeSalesCount = (int)($storeSalesRow['cnt'] ?? 0);
                    $firstStoreSale = $storeSalesRow['first_sale'] ?? null;
                } catch (Throwable) {
                }

            }

            // 3. Stock context reconstruction from audited movements at cutoff
            $allMovements = [];
            if (!$importOnly) {
                try {
                    $hasMovementId = isset($this->columns($pdo, 'stock_movements')['movement_id']);
                    $orderClause = $hasMovementId ? "ORDER BY moved_at ASC, movement_id ASC" : "ORDER BY moved_at ASC";
                    $movementsStmt = $pdo->prepare("
                        SELECT change_qty, moved_at
                        FROM stock_movements
                        WHERE product_id = ? AND moved_at <= ?
                        {$orderClause}
                    ");
                    $movementsStmt->execute([$productId, $effectiveEnd]);
                    $allMovements = $movementsStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable) {
                }

            }

            $hasStockEvidence = count($allMovements) > 0;
            $priorCount = 0;
            $priorBalance = 0;
            $dayMovements = [];

            foreach ($allMovements as $mov) {
                if ($mov['moved_at'] < $startOfDay) {
                    $priorCount++;
                    $priorBalance += (int)$mov['change_qty'];
                } else {
                    $dayMovements[] = $mov;
                }
            }

            $endOfDayStock = $priorBalance;
            foreach ($dayMovements as $mov) {
                $endOfDayStock += (int)$mov['change_qty'];
            }

            // 4. Observed Sales & Historical Attribution
            $sold = 0;
            $salesRecords = 0;
            if (!$importOnly) {
                try {
                    $soldStmt = $pdo->prepare("
                        SELECT COUNT(*) AS records, COALESCE(SUM(" . $this->saleUnitsExpression($pdo, 'si') . "), 0) AS units
                        FROM sale_items si
                        JOIN sales s ON si.sale_id = s.sale_id
                        WHERE si.product_id = ?
                          AND s.sale_date >= ? AND s.sale_date <= ?
                    ");
                    $soldStmt->execute([$productId, $startOfDay, $effectiveEnd]);
                    $sales = $soldStmt->fetch(PDO::FETCH_ASSOC);
                    $sold = (int)($sales['units'] ?? 0);
                    $salesRecords = (int)($sales['records'] ?? 0);
                } catch (Throwable) {
                }

            }

            $source = 'pos';
            $imported = false;
            if ($importOnly && $endOfDay <= $cutoff) {
                $imported = $importedQuantities[$dateStr] ?? false;
                if ($imported !== false) {
                    $sold = (int)$imported;
                    $source = 'import';
                }
            } elseif ($salesRecords === 0 && $endOfDay <= $cutoff) {
                try {
                    $importStmt = $pdo->prepare('SELECT quantity FROM forecast_sales_imports WHERE product_id = ? AND sale_date = ?
                        AND NOT EXISTS (SELECT 1 FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
                            WHERE si.product_id = ? AND s.sale_date >= ? AND s.sale_date <= ?)');
                    $importStmt->execute([$productId, $dateStr, $productId, $startOfDay, $endOfDay]);
                    $imported = $importStmt->fetchColumn();
                    if ($imported !== false) {
                        $sold = (int)$imported;
                        $source = 'import';
                    }
                } catch (Throwable) {
                }
            }

            // Approved sale reversals known by the cutoff, in Base Units
            $reversed = 0;
            if (!$importOnly) {
                try {
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
                } catch (Throwable) {
                }

            }

            // Cash refunds created before the cutoff, in Base Units
            $refunded = 0;
            if (!$importOnly) {
                try {
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
                } catch (Throwable) {
                }

            }

            $validSalesUnits = max(0, $sold - $reversed - $refunded);

            // 5. Scheduled Demand Drivers known by cutoff
            $effectiveDrivers = [];
            if (!$importOnly) {
                try {
                    $effectiveDrivers = $this->driverService->getEffectiveDrivers($pdo, $productId, $dateStr, $cutoff);
                } catch (Throwable) {
                }

            }

            // 6. Semantic Classification: distinguish Open, Closed, Stockout, Missing
            $hadIntradayStockout = false;
            $availabilityUnknown = false;
            $outOfStock = null;
            $classification = 'missing_evidence';

            $isClosed = in_array($calStatus, ['closed', 'holiday', 'exceptional_closure'], true);

            if ($isClosed) {
                $classification = 'closed';
                $outOfStock = null;
            } elseif ($imported !== false) {
                if ($hasStockEvidence) {
                    $running = $priorBalance;
                    if ($priorCount > 0 && $priorBalance <= 0) {
                        $replenishedEarly = false;
                        foreach ($dayMovements as $m) {
                            $running += (int)$m['change_qty'];
                            if ($running > 0 && substr($m['moved_at'], 11, 8) <= '08:00:00') {
                                $replenishedEarly = true;
                            }
                        }
                        if (!$replenishedEarly) {
                            $hadIntradayStockout = true;
                        }
                    }
                    $running = $priorBalance;
                    foreach ($dayMovements as $m) {
                        $running += (int)$m['change_qty'];
                        if ($running <= 0) {
                            $hadIntradayStockout = true;
                        }
                    }
                    if ($endOfDayStock <= 0) {
                        $hadIntradayStockout = true;
                    }

                    if ($hadIntradayStockout) {
                        $classification = 'stockout_censored';
                        $outOfStock = true;
                    } elseif ($validSalesUnits > 0) {
                        $classification = 'valid_demand';
                        $outOfStock = false;
                    } else {
                        $classification = 'genuine_zero';
                        $outOfStock = false;
                    }
                } else {
                    if ($validSalesUnits > 0) {
                        $classification = 'valid_demand';
                        $outOfStock = null;
                    } else {
                        // Imported zero without stock evidence: availability unproven
                        $classification = 'genuine_zero';
                        $outOfStock = null;
                    }
                }
            } else {
                $storeIsOpen = ($calStatus === 'open');
                $storeHasSalesOpening = $hasSaleOpening;
                if (!$storeIsOpen && $storeSalesCount > 0) {
                    $storeIsOpen = true;
                    $storeHasSalesOpening = true;
                }

                if (!$storeIsOpen) {
                    $classification = 'missing_evidence';
                    $outOfStock = null;
                } elseif (!$hasStockEvidence) {
                    if ($validSalesUnits > 0) {
                        $classification = 'valid_demand';
                        $outOfStock = null;
                    } else {
                        $classification = 'missing_evidence';
                        $outOfStock = null;
                    }
                } else {
                    $tradingStartTime = '08:00:00';
                    if ($firstStoreSale !== null && substr($firstStoreSale, 11, 8) < $tradingStartTime) {
                        $tradingStartTime = substr($firstStoreSale, 11, 8);
                    }

                    if ($priorCount === 0) {
                        if (!empty($dayMovements)) {
                            $firstMoveTime = substr($dayMovements[0]['moved_at'], 11, 8);
                            if ($firstMoveTime > $tradingStartTime) {
                                $availabilityUnknown = true;
                            }
                        } else {
                            $availabilityUnknown = true;
                        }
                    } elseif ($priorBalance <= 0) {
                        $replenishedBeforeTrading = false;
                        $earlyBal = $priorBalance;
                        foreach ($dayMovements as $m) {
                            $mTime = substr($m['moved_at'], 11, 8);
                            if ($mTime <= $tradingStartTime) {
                                $earlyBal += (int)$m['change_qty'];
                                if ($earlyBal > 0) {
                                    $replenishedBeforeTrading = true;
                                }
                            }
                        }
                        if (!$replenishedBeforeTrading) {
                            $hadIntradayStockout = true;
                        }
                    }

                    if ($storeHasSalesOpening && $validSalesUnits === 0) {
                        $availabilityUnknown = true;
                    }

                    $runningBal = $priorBalance;
                    foreach ($dayMovements as $m) {
                        $runningBal += (int)$m['change_qty'];
                        $mTime = substr($m['moved_at'], 11, 8);
                        if ($runningBal <= 0 && ($mTime >= $tradingStartTime || $priorBalance > 0)) {
                            $hadIntradayStockout = true;
                        }
                    }

                    if ($endOfDayStock <= 0) {
                        $hadIntradayStockout = true;
                    }

                    if ($availabilityUnknown) {
                        if ($hadIntradayStockout) {
                            $classification = 'stockout_censored';
                            $outOfStock = true;
                        } else {
                            $classification = 'missing_evidence';
                            $outOfStock = null;
                        }
                    } elseif ($hadIntradayStockout) {
                        $classification = 'stockout_censored';
                        $outOfStock = true;
                    } elseif ($validSalesUnits > 0) {
                        $classification = 'valid_demand';
                        $outOfStock = false;
                    } else {
                        $classification = 'genuine_zero';
                        $outOfStock = false;
                    }
                }
            }
            // Dated Price and Promotion Evidence
            $price = null;
            if (!$importOnly) {
                try {
                    $price = $this->driverService->getEffectivePrice($pdo, $productId, $dateStr, $effectiveEnd);
                } catch (Throwable) {
                }

            }

            $isOnPromotion = null;
            try {
                $isOnPromotion = $this->driverService->resolveIsOnPromotion(
                    $pdo,
                    $productId,
                    $dateStr,
                    $effectiveEnd,
                    $effectiveDrivers,
                    $calStatus,
                    $imported !== false
                );
            } catch (Throwable) {
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
                'has_scheduled_driver' => !empty($effectiveDrivers),
                'price' => $price,
                'is_on_promotion' => $isOnPromotion,
                'out_of_stock' => $outOfStock,
                'source' => $source,
                'recorded_sales_units' => $validSalesUnits,
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
        $driverRevisions = [];
        try {
            $driverStmt = $pdo->prepare('SELECT product_id, effective_date, DATE(scheduled_at) AS available_date'
                . ' FROM scheduled_demand_drivers'
                . ' WHERE scheduled_at <= ? AND DATE(scheduled_at) > effective_date');
            $driverStmt->execute([$throughDate . ' 23:59:59']);
            foreach ($driverStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $driverRevisions[(int)$row['product_id']][$row['effective_date']][] = $row['available_date'];
            }
        } catch (Throwable) {
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
                $cutoffs = array_unique(array_merge(
                    [$date],
                    $revisions[$product['product_id']][$date] ?? [],
                    $calendarRevisions[$date] ?? [],
                    $driverRevisions[(int)$product['product_id']][$date] ?? []
                ));
                sort($cutoffs);
                foreach ($cutoffs as $cutoff) {
                    $evidence = $this->getDailyEvidence($pdo, (int)$product['product_id'], $date, $date, $cutoff . ' 23:59:59')[0];
                    $known = in_array($evidence['classification'], ['valid_demand', 'genuine_zero'], true);
                    $history[] = [
                        'date' => $date,
                        'product_id' => (int)$product['product_id'],
                        'units_sold' => $known ? $evidence['valid_sales_units'] : null,
                        'available_date' => $cutoff,
                        'recorded_sales_units' => $evidence['valid_sales_units'],
                        'price' => $evidence['price'],
                        'is_on_promotion' => $evidence['is_on_promotion'],
                        'out_of_stock' => $evidence['out_of_stock'],
                        'classification' => $evidence['classification'],
                        'source' => $evidence['source'],
                    ];
                }
                $day->modify('+1 day');
            }
        }
        return $history;
    }

    /** Candidate training history filtered by truthful training eligibility (#365). */
    public function getTrainingHistory(PDO $pdo, string $throughDate): array
    {
        $history = $this->getObservedHistory($pdo, $throughDate);
        $eligibilityService = new StoreTrainingEligibilityService($this);
        $eligibility = $eligibilityService->evaluateAllProducts($pdo, $throughDate, $history);
        $eligiblePids = [];
        foreach ($eligibility as $item) {
            if (!empty($item['eligible'])) {
                $eligiblePids[(int)$item['product_id']] = true;
            }
        }
        $selected = array_values(array_filter($history, static fn(array $row): bool =>
            isset($eligiblePids[(int)$row['product_id']])));
        $dates = array_column($selected, 'date');
        $start = !empty($dates) ? min($dates) : null;
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
        
        // ADR-0005 Readiness Policy, from the shared band definition. This service
        // always qualifies a mature Product, so a usable count at or above the High
        // threshold still reads as High.
        $readiness = ForecastMetrics::readinessLevel($summary['usable_history_days'], true);
        $summary['readiness_level'] = $readiness['level'];
        $summary['limitation_marker'] = $readiness['limitation_marker'];
        
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
