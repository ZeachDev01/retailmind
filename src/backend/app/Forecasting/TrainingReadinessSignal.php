<?php
declare(strict_types=1);

namespace App\Forecasting;

require_once __DIR__ . '/StoreTrainingEligibilityService.php';

/**
 * Deep module for the Training Readiness notification decision (write path).
 *
 * Classifies the store-wide readiness state into one signal: fixable history
 * gaps deserve an actionable warning, a pure cold start is only a status, and
 * the first eligible Product is the moment worth announcing.
 */
final class TrainingReadinessSignal
{
    /** @param array<int, array<string, mixed>> $readiness ForecastReadinessQuery rows. */
    public static function signal(array $readiness, int $eligibleCount): array
    {
        $required = StoreTrainingEligibilityService::MINIMUM_USABLE_DAYS;

        if ($eligibleCount > 0) {
            return [
                'key' => 'ready',
                'severity' => 'info',
                'required' => false,
                'count' => $eligibleCount,
                'title' => 'Store training is ready',
                'message' => ($eligibleCount === 1 ? '1 Product now meets' : "{$eligibleCount} Products now meet")
                    . " Training Eligibility ({$required} usable history days plus supported windows)."
                    . ' Start training on the ML settings page.',
            ];
        }

        $bestUsableDays = 0;
        $bestSpanDays = 0;
        $productsWithGaps = 0;
        foreach ($readiness as $row) {
            $bestUsableDays = max($bestUsableDays, (int)($row['accumulated_usable_days'] ?? 0));
            $bestSpanDays = max($bestSpanDays, (int)($row['calendar_history_days'] ?? 0));
            if (self::hasFixableHistoryGaps($row)) {
                $productsWithGaps++;
            }
        }

        if ($productsWithGaps > 0) {
            return [
                'key' => 'readiness',
                'severity' => 'warning',
                'required' => true,
                'count' => count($readiness),
                'title' => 'Store training is blocked by missing history',
                'message' => "No Product is ready for training yet. {$productsWithGaps} of " . count($readiness)
                    . ' Products have missing or unsupported demand-history days that can be fixed now: verify sales'
                    . ' records, Store Calendar closures, and stock availability, or import history for the affected dates.',
            ];
        }

        // Earliest readiness needs both the usable-day total and the calendar
        // span the three 28-day holdouts sit on (84 + 28 days of history).
        $spanFloor = StoreTrainingEligibilityService::EVALUATION_WINDOW_DAYS
            + StoreTrainingEligibilityService::EVALUATION_PERIOD_DAYS;
        $daysToWait = max(0, $required - $bestUsableDays, $spanFloor - $bestSpanDays);
        $throughDate = date('Y-m-d', strtotime('-1 day'));
        $projectedDate = (new \DateTimeImmutable($throughDate))
            ->modify("+{$daysToWait} days")
            ->format('M j, Y');

        return [
            'key' => 'coldstart',
            'severity' => 'info',
            'required' => false,
            'count' => count($readiness),
            'title' => 'Store training is not ready yet',
            'message' => "Cold start in progress: the most advanced Product has {$bestUsableDays}/{$required} usable"
                . " history days through {$throughDate}. Training becomes possible around {$projectedDate} at the"
                . ' earliest if daily demand recording continues; importing past demand history can shorten this.',
        ];
    }

    /** Missing evidence is a recorded-data hole a backfill can still close. */
    private static function hasFixableHistoryGaps(array $row): bool
    {
        return (int)($row['missing_evidence_days'] ?? 0) > 0;
    }
}
