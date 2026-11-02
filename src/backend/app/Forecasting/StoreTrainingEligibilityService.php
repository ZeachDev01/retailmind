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
    public const HOLDOUT_DAYS = 28;
    public const NUM_HOLDOUTS = 3;

    private DemandHistoryService $historyService;
    private DateTimeZone $timezone;

    public function __construct(?DemandHistoryService $historyService = null)
    {
        $this->historyService = $historyService ?? new DemandHistoryService();
        $this->timezone = new DateTimeZone('Asia/Manila');
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
            )");
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
                    'has_feature_window' => false,
                    'has_holdout_coverage' => false,
                    'reason' => "Needs at least " . self::MINIMUM_USABLE_DAYS . " accumulated usable days through {$throughDate} (has 0/" . self::MINIMUM_USABLE_DAYS . ")",
                    'blockers' => ["No sales or demand history found through {$throughDate}"],
                ];
            }

            $dailyEvidence = $this->historyService->getDailyEvidence($pdo, $productId, (string)$firstDateStr, $throughDate, $throughDate . ' 23:59:59');
            $evidenceByDate = [];
            foreach ($dailyEvidence as $row) {
                $evidenceByDate[$row['date']] = $row;
            }
        }

        // 1. Accumulated usable days (valid_demand or genuine_zero)
        $accumulatedUsableDays = 0;
        foreach ($evidenceByDate as $date => $row) {
            if ($date <= $throughDate && in_array($row['classification'] ?? '', ['valid_demand', 'genuine_zero'], true)) {
                $accumulatedUsableDays++;
            }
        }

        // 2. Supported recent feature window (28 calendar days: throughDate - 27 days .. throughDate)
        $hasFeatureWindow = true;
        $featureWindowReason = null;
        $windowStart = $cutoffDate->modify('-27 days');
        $cur = $windowStart;
        while ($cur <= $cutoffDate) {
            $dStr = $cur->format('Y-m-d');
            $row = $evidenceByDate[$dStr] ?? null;
            if ($row === null) {
                $hasFeatureWindow = false;
                $featureWindowReason = "Unsupported recent inputs in feature window through {$throughDate} (missing evidence on {$dStr})";
                break;
            }
            $classification = (string)($row['classification'] ?? 'missing_evidence');
            if (in_array($classification, ['stockout_censored', 'missing_evidence'], true)) {
                $hasFeatureWindow = false;
                $featureWindowReason = "Unsupported recent inputs in feature window through {$throughDate} ({$classification} on {$dStr})";
                break;
            }
            $cur = $cur->modify('+1 day');
        }

        // 3. Three chronological 28-day holdout coverage
        // Holdout total span: 3 * 28 = 84 calendar days (throughDate - 83 days .. throughDate)
        // Plus training origin history before Holdout 1 (at least 28 days + origin)
        $hasHoldoutCoverage = true;
        $holdoutReason = null;
        $holdoutSpanStart = $cutoffDate->modify('-83 days');
        $minRequiredStart = $cutoffDate->modify('-111 days'); // 84 + 28 - 1 = 111 days

        // Check if there is history before the holdout period
        $datesWithEvidence = array_keys($evidenceByDate);
        sort($datesWithEvidence);
        $firstEvidenceDate = $datesWithEvidence[0] ?? null;

        if ($firstEvidenceDate === null || $firstEvidenceDate > $minRequiredStart->format('Y-m-d')) {
            $hasHoldoutCoverage = false;
            $holdoutReason = "Insufficient chronological evaluation coverage through {$throughDate} (needs at least 3 distinct 28-day holdouts backed by training history)";
        } else {
            // Check all 84 calendar days across the 3 holdouts
            $cur = $holdoutSpanStart;
            while ($cur <= $cutoffDate) {
                $dStr = $cur->format('Y-m-d');
                $row = $evidenceByDate[$dStr] ?? null;
                if ($row === null) {
                    $hasHoldoutCoverage = false;
                    $holdoutReason = "Insufficient chronological evaluation coverage through {$throughDate} (holdout contains missing evidence on {$dStr})";
                    break;
                }
                $classification = (string)($row['classification'] ?? 'missing_evidence');
                if (in_array($classification, ['stockout_censored', 'missing_evidence'], true)) {
                    $hasHoldoutCoverage = false;
                    $holdoutReason = "Insufficient chronological evaluation coverage through {$throughDate} (holdout contains {$classification} on {$dStr})";
                    break;
                }
                $cur = $cur->modify('+1 day');
            }
        }

        // Decision logic
        $usableDaysPass = ($accumulatedUsableDays >= self::MINIMUM_USABLE_DAYS);
        $eligible = ($usableDaysPass && $hasFeatureWindow && $hasHoldoutCoverage);

        $blockers = [];
        if (!$usableDaysPass) {
            $blockers[] = "Needs at least " . self::MINIMUM_USABLE_DAYS . " accumulated usable days through {$throughDate} (has {$accumulatedUsableDays}/" . self::MINIMUM_USABLE_DAYS . ")";
        }
        if (!$hasFeatureWindow) {
            $blockers[] = $featureWindowReason;
        }
        if (!$hasHoldoutCoverage) {
            $blockers[] = $holdoutReason;
        }

        $status = $eligible ? 'Ready for training' : 'Insufficient Data';
        $reason = $blockers ? implode('; ', $blockers) : null;

        return [
            'product_id' => $productId,
            'eligible' => $eligible,
            'status' => $status,
            'accumulated_usable_days' => $accumulatedUsableDays,
            'consecutive_usable_days' => $accumulatedUsableDays, // backward-compatibility alias
            'required_usable_days' => self::MINIMUM_USABLE_DAYS,
            'has_feature_window' => $hasFeatureWindow,
            'has_holdout_coverage' => $hasHoldoutCoverage,
            'reason' => $reason,
            'blockers' => $blockers,
        ];
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

