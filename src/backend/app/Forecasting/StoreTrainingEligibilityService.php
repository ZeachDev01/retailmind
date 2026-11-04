<?php
declare(strict_types=1);

namespace App\Forecasting;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

class StoreTrainingEligibilityService
{
    public const MINIMUM_USABLE_DAYS = 140;
    public const INPUT_WINDOW_DAYS = 28;
    public const EVALUATION_PERIOD_DAYS = 28;
    public const EVALUATION_PERIODS = 3;
    public const HOLDOUT_DAYS = self::EVALUATION_PERIOD_DAYS;
    public const NUM_HOLDOUTS = self::EVALUATION_PERIODS;
    public const EVALUATION_WINDOW_DAYS = self::EVALUATION_PERIOD_DAYS * self::EVALUATION_PERIODS;

    private DemandHistoryService $historyService;
    private DateTimeZone $timezone;

    public function __construct(?DemandHistoryService $historyService = null)
    {
        $this->historyService = $historyService ?? new DemandHistoryService();
        $this->timezone = new DateTimeZone('Asia/Manila');
    }

    /**
     * The single effective Training Eligibility policy. It is fixed: the pages
     * display these values and never accept replacements from settings.
     */
    public static function policy(): array
    {
        return [
            'minimum_usable_days' => self::MINIMUM_USABLE_DAYS,
            'input_window_days' => self::INPUT_WINDOW_DAYS,
            'evaluation_periods' => self::EVALUATION_PERIODS,
            'evaluation_period_days' => self::EVALUATION_PERIOD_DAYS,
            'evaluation_window_days' => self::EVALUATION_WINDOW_DAYS,
        ];
    }

    /**
     * Evaluates candidate training eligibility for a single product as of $throughDate.
     * Prerequisite checks:
     * 1. Accumulated usable days >= 140 (supported zero-sales count; stockout/closure/unknown do not reset)
     * 2. Supported recent feature window (28 calendar days through cutoff without stockout/unknown gaps)
     * 3. Complete chronological evaluation holdout coverage (three 28-calendar-day holdouts backed by training history)
     */
    public function evaluateProductEligibility(PDO $pdo, int $productId, string $throughDate, ?array $evidenceByDate = null): array
    {
        $cutoffDate = new DateTimeImmutable($throughDate, $this->timezone);

        if ($evidenceByDate === null) {
            $stmt = $pdo->prepare("SELECT MIN(sale_date) FROM (
                SELECT DATE(s.sale_date) AS sale_date FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE si.product_id = ? AND s.sale_date <= ?
                UNION ALL
                SELECT sale_date FROM forecast_sales_imports WHERE product_id = ? AND sale_date <= ?
            ) AS dates");
            $stmt->execute([$productId, $throughDate . ' 23:59:59', $productId, $throughDate]);
            $firstDateStr = $stmt->fetchColumn();

            if (!$firstDateStr) {
                return [
                    'product_id' => $productId,
                    'eligible' => false,
                    'status' => 'Insufficient Data',
                    'accumulated_usable_days' => 0,
                    'consecutive_usable_days' => 0,
                    'required_usable_days' => self::MINIMUM_USABLE_DAYS,
                    'usable_days_remaining' => self::MINIMUM_USABLE_DAYS,
                    'days_with_sales' => 0,
                    'genuine_zero_days' => 0,
                    'calendar_span_days' => 0,
                    'closed_days' => 0,
                    'stockout_days' => 0,
                    'missing_evidence_days' => 0,
                    'has_feature_window' => false,
                    'has_holdout_coverage' => false,
                    'reason' => "Needs at least " . self::MINIMUM_USABLE_DAYS . " accumulated usable days through {$throughDate} (has 0/" . self::MINIMUM_USABLE_DAYS . ")",
                    'blockers' => ["No sales or demand history found through {$throughDate}"],
                    'actions' => ["Import or record daily demand history through {$throughDate}, then recheck eligibility."],
                ];
            }

            $dailyEvidence = $this->historyService->getDailyEvidence($pdo, $productId, (string)$firstDateStr, $throughDate, $throughDate . ' 23:59:59');
            $evidenceByDate = [];
            foreach ($dailyEvidence as $row) {
                $evidenceByDate[$row['date']] = $row;
            }
        }

        // Classify every observed date once so usable days stay separate from
        // elapsed calendar span and days with sales.
        $usableDays = 0;
        $daysWithSales = 0;
        $genuineZeroDays = 0;
        $closedDays = 0;
        $stockoutDays = 0;
        $missingEvidenceDays = 0;
        $observedDates = [];
        foreach ($evidenceByDate as $date => $row) {
            $date = (string)$date;
            if ($date > $throughDate) {
                continue;
            }
            $observedDates[] = $date;
            if (($row['valid_sales_units'] ?? $row['recorded_sales_units'] ?? 0) > 0) {
                $daysWithSales++;
            }
            $classification = (string)($row['classification'] ?? 'missing_evidence');
            switch ($classification) {
                case 'valid_demand':
                    $usableDays++;
                    break;
                case 'genuine_zero':
                    $usableDays++;
                    $genuineZeroDays++;
                    break;
                case 'closed':
                    $closedDays++;
                    break;
                case 'stockout_censored':
                    $stockoutDays++;
                    break;
                default:
                    $missingEvidenceDays++;
                    break;
            }
        }
        sort($observedDates);
        $firstEvidenceDate = $observedDates[0] ?? null;
        $calendarSpanDays = $firstEvidenceDate === null
            ? 0
            : (int)(new DateTimeImmutable($firstEvidenceDate, $this->timezone))
                ->diff($cutoffDate)->days + 1;

        // 2. Supported recent feature window (28 calendar days: throughDate - 27 days .. throughDate)
        $hasFeatureWindow = true;
        $featureWindowReason = null;
        $featureWindowAction = null;
        $windowStart = $cutoffDate->modify('-' . (self::INPUT_WINDOW_DAYS - 1) . ' days');
        $cur = $windowStart;
        while ($cur <= $cutoffDate) {
            $dStr = $cur->format('Y-m-d');
            $row = $evidenceByDate[$dStr] ?? null;
            if ($row === null) {
                $hasFeatureWindow = false;
                $featureWindowReason = "Unsupported recent inputs in feature window through {$throughDate} (missing evidence on {$dStr})";
                $featureWindowAction = $this->inputWindowAction($windowStart->format('Y-m-d'), $throughDate, 'missing_evidence', $dStr);
                break;
            }
            $classification = (string)($row['classification'] ?? 'missing_evidence');
            if (in_array($classification, ['stockout_censored', 'missing_evidence'], true)) {
                $hasFeatureWindow = false;
                $featureWindowReason = "Unsupported recent inputs in feature window through {$throughDate} ({$classification} on {$dStr})";
                $featureWindowAction = $this->inputWindowAction($windowStart->format('Y-m-d'), $throughDate, $classification, $dStr);
                break;
            }
            $cur = $cur->modify('+1 day');
        }

        // 3. Three chronological 28-day holdout coverage
        // Holdout total span: 3 * 28 = 84 calendar days (throughDate - 83 days .. throughDate)
        // Plus training origin history before Holdout 1 (at least 28 days + origin)
        $hasHoldoutCoverage = true;
        $holdoutReason = null;
        $holdoutAction = null;
        $holdoutSpanStart = $cutoffDate->modify('-' . (self::EVALUATION_WINDOW_DAYS - 1) . ' days');
        $minRequiredStart = $cutoffDate->modify('-' . (self::EVALUATION_WINDOW_DAYS + self::EVALUATION_PERIOD_DAYS - 1) . ' days');

        if ($firstEvidenceDate === null || $firstEvidenceDate > $minRequiredStart->format('Y-m-d')) {
            $hasHoldoutCoverage = false;
            $holdoutReason = "Insufficient chronological evaluation coverage through {$throughDate} (needs at least 3 distinct 28-day holdouts backed by training history)";
            $holdoutAction = "Collect at least " . self::EVALUATION_PERIOD_DAYS . " supported training days before {$holdoutSpanStart->format('Y-m-d')}; three chronological " . self::EVALUATION_PERIOD_DAYS . "-day evaluation periods need earlier training history.";
        } else {
            // Check all 84 calendar days across the 3 holdouts
            $cur = $holdoutSpanStart;
            while ($cur <= $cutoffDate) {
                $dStr = $cur->format('Y-m-d');
                $row = $evidenceByDate[$dStr] ?? null;
                if ($row === null) {
                    $hasHoldoutCoverage = false;
                    $holdoutReason = "Insufficient chronological evaluation coverage through {$throughDate} (holdout contains missing evidence on {$dStr})";
                    $holdoutAction = $this->evaluationAction($holdoutSpanStart->format('Y-m-d'), $throughDate, 'missing_evidence', $dStr);
                    break;
                }
                $classification = (string)($row['classification'] ?? 'missing_evidence');
                if (in_array($classification, ['stockout_censored', 'missing_evidence'], true)) {
                    $hasHoldoutCoverage = false;
                    $holdoutReason = "Insufficient chronological evaluation coverage through {$throughDate} (holdout contains {$classification} on {$dStr})";
                    $holdoutAction = $this->evaluationAction($holdoutSpanStart->format('Y-m-d'), $throughDate, $classification, $dStr);
                    break;
                }
                $cur = $cur->modify('+1 day');
            }
        }

        // Decision logic
        $usableDaysPass = ($usableDays >= self::MINIMUM_USABLE_DAYS);
        $eligible = ($usableDaysPass && $hasFeatureWindow && $hasHoldoutCoverage);
        $usableDaysRemaining = max(0, self::MINIMUM_USABLE_DAYS - $usableDays);

        $blockers = [];
        $actions = [];
        if (!$usableDaysPass) {
            $blockers[] = "Needs at least " . self::MINIMUM_USABLE_DAYS . " accumulated usable days through {$throughDate} (has {$usableDays}/" . self::MINIMUM_USABLE_DAYS . ")";
            $remainingLabel = $usableDaysRemaining === 1 ? '1 more usable day' : "{$usableDaysRemaining} more usable days";
            $actions[] = "Collect {$remainingLabel}: a day counts when the Store is open and demand is observed, including genuine zero sales. Closures, stockouts, and unknown observations do not add usable days.";
        }
        if (!$hasFeatureWindow) {
            $blockers[] = $featureWindowReason;
            $actions[] = $featureWindowAction;
        }
        if (!$hasHoldoutCoverage) {
            $blockers[] = $holdoutReason;
            $actions[] = $holdoutAction;
        }

        $status = $eligible ? 'Ready for training' : 'Insufficient Data';
        $reason = $blockers ? implode('; ', $blockers) : null;

        return [
            'product_id' => $productId,
            'eligible' => $eligible,
            'status' => $status,
            'accumulated_usable_days' => $usableDays,
            'consecutive_usable_days' => $usableDays, // backward-compatibility alias
            'required_usable_days' => self::MINIMUM_USABLE_DAYS,
            'usable_days_remaining' => $usableDaysRemaining,
            'days_with_sales' => $daysWithSales,
            'genuine_zero_days' => $genuineZeroDays,
            'calendar_span_days' => $calendarSpanDays,
            'closed_days' => $closedDays,
            'stockout_days' => $stockoutDays,
            'missing_evidence_days' => $missingEvidenceDays,
            'has_feature_window' => $hasFeatureWindow,
            'has_holdout_coverage' => $hasHoldoutCoverage,
            'reason' => $reason,
            'blockers' => $blockers,
            'actions' => $actions,
        ];
    }

    private function inputWindowAction(string $start, string $end, string $classification, string $date): string
    {
        return "Verify sales records, Store Calendar closures, and stock availability for the unsupported dates in the recent "
            . self::INPUT_WINDOW_DAYS . "-day input window ({$start} to {$end}); blocked on {$classification} on {$date}.";
    }

    private function evaluationAction(string $start, string $end, string $classification, string $date): string
    {
        return "Provide supported observations for the three chronological " . self::EVALUATION_PERIOD_DAYS
            . "-day evaluation periods ({$start} to {$end}); blocked on {$classification} on {$date}.";
    }

    /**
     * Evaluates all active products.
     */
    public function evaluateAllProducts(PDO $pdo, string $throughDate, ?array $observedHistory = null): array
    {
        // Group observed history by product_id and date if provided
        $historyByProduct = [];
        if ($observedHistory !== null) {
            foreach ($observedHistory as $row) {
                $pid = (int)$row['product_id'];
                $historyByProduct[$pid][$row['date']] = $row;
            }
        }

        $activeProductIds = $pdo->query("SELECT product_id FROM products WHERE status = 'active' ORDER BY product_id")
            ->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        foreach ($activeProductIds as $id) {
            $pid = (int)$id;
            $evidence = $historyByProduct[$pid] ?? null;
            $results[] = $this->evaluateProductEligibility($pdo, $pid, $throughDate, $evidence);
        }

        return $results;
    }
}
