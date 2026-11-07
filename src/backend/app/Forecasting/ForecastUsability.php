<?php
declare(strict_types=1);

namespace App\Forecasting;

require_once __DIR__ . '/ForecastingSchedulerService.php';
require_once __DIR__ . '/StoreTrainingEligibilityService.php';

/**
 * Single source of truth for "is this Product's Demand Forecast usable?".
 *
 * Two pure decisions share one seam, so the Replenishment Queue, Forecast
 * Coverage, and the Forecast Readiness page stop re-encoding the same rules:
 *  - assess(): the buying-usability decision for a displayed Product. The caller
 *    passes the shared 28-day Forecast Horizon aggregate, the ml_jobs row, and a
 *    governance context; every database read stays in the caller.
 *  - readinessStatus(): the Forecast Readiness page ladder, including the
 *    sale-after-generation staleness rule.
 *
 * Decision support only: nothing here mutates Store data (ADR-0005, ADR-0022).
 */
final class ForecastUsability
{
    /**
     * Readiness bands a complete forecast must carry to back routine buying
     * guidance. Insufficient/incomplete evidence never counts as usable.
     */
    public const SUPPORTED_READINESS = ['Low', 'Medium', 'High'];

    /**
     * The buying-usability decision for one Product.
     *
     * @param array<string, mixed> $aggregate The shared 28-day Forecast Horizon
     *        aggregate for this Product ([] when no Forecast row exists).
     * @param array<string, mixed>|null $job The ml_jobs row for the displayed
     *        run (null when no run was requested).
     * @param array<string, mixed> $context observation (bool), suspended (bool),
     *        approved_model_version (string), is_demo_db (bool), expected_origin
     *        (string, Y-m-d), latest_usable_job_id (?int), job_id (?int),
     *        job_meta (?array from DemandForecastService::getJobMetadata).
     * @return array{usable: bool, status: string, exclusion_reason: ?string, exclusion_category: ?string, exclusion_action: ?string}
     */
    public static function assess(array $aggregate, ?array $job, array $context): array
    {
        $jobId = $context['job_id'] ?? null;
        $observation = (bool)($context['observation'] ?? false);
        $suspended = (bool)($context['suspended'] ?? false);
        $approvedVersion = (string)($context['approved_model_version'] ?? '');
        $origin = (string)($context['expected_origin'] ?? '');
        $latestJobId = $context['latest_usable_job_id'] ?? null;
        $jobMeta = $context['job_meta'] ?? null;
        $isDemoDb = (bool)($context['is_demo_db'] ?? false);

        $readiness = $aggregate['readiness_level'] ?? null;
        $hasForecast = $readiness !== null;

        $isOperationalSuccess = $job !== null
            && ($job['trigger_type'] ?? null) === 'forecast_generation'
            && ($job['status'] ?? null) === 'succeeded';
        $isJobFailed = $job !== null && ($job['status'] ?? null) === 'failed';
        $isJobObservation = $job !== null && ($job['trigger_type'] ?? null) === 'forecast_observation';
        $isSuperseded = $jobId !== null && $latestJobId !== null && (int)$jobId !== (int)$latestJobId;

        [$isIncompatibleModel, $incompatibilityReason] = self::incompatibility($jobMeta, $isDemoDb);

        $usable = $hasForecast
            && $isOperationalSuccess
            && !$observation
            && !$suspended
            && !$isSuperseded
            && !$isIncompatibleModel
            && !empty($aggregate['complete_forecast'])
            && in_array($readiness, self::SUPPORTED_READINESS, true)
            && ($aggregate['forecast_origin'] ?? null) === $origin
            && $approvedVersion !== ''
            && ($aggregate['model_version'] ?? null) === $approvedVersion;

        if ($usable) {
            return [
                'usable' => true,
                'status' => $readiness . ' readiness forecast',
                'exclusion_reason' => null,
                'exclusion_category' => null,
                'exclusion_action' => null,
            ];
        }

        $exclusionReason = 'Forecast unavailable';
        $exclusionCategory = 'evidence';
        $exclusionAction = 'Replenishment continues on stock rules';

        if ($observation || $isJobObservation) {
            $exclusionReason = 'Observation Mode: forecast is observation telemetry only';
            $exclusionCategory = 'restriction';
            $exclusionAction = 'Administrator: Complete candidate observation';
        } elseif ($suspended) {
            $exclusionReason = 'Recommendations Suspended by Administrator';
            $exclusionCategory = 'restriction';
            $exclusionAction = 'Administrator: Resume forecast recommendations';
        } elseif ($isJobFailed) {
            $exclusionReason = 'Failed forecast: run #' . $jobId;
            $exclusionCategory = 'recovery';
            $exclusionAction = ForecastingSchedulerService::canAutomaticallyRetry($job)
                ? 'Automatic retry scheduled; no action needed'
                : 'Super Administrator: Inspect blocked or exhausted recovery before retrying';
        } elseif ($approvedVersion === '') {
            $exclusionReason = 'Unapproved model: no approved Store model';
            $exclusionCategory = 'approval';
            $exclusionAction = 'Administrator: Review and approve candidate model in Model Review';
        } elseif ($isIncompatibleModel) {
            $exclusionReason = 'Incompatible model: ' . $incompatibilityReason;
            $exclusionCategory = 'recovery';
            $exclusionAction = 'Super Administrator: Verify model schema and database environment';
        } elseif ($isSuperseded) {
            $exclusionReason = 'Superseded forecast: run #' . $jobId;
            $exclusionCategory = 'freshness';
            $exclusionAction = 'Use latest forecast run';
        } elseif (!$hasForecast) {
            $exclusionReason = 'Forecast unavailable: no forecast generated for this product';
            $exclusionCategory = 'evidence';
            $exclusionAction = 'Accumulate sales history or wait for next scheduled forecast run';
        } elseif (($aggregate['forecast_origin'] ?? null) !== $origin) {
            $exclusionReason = 'Stale forecast: ' . $aggregate['forecast_origin'] . ' (expected ' . $origin . ')';
            $exclusionCategory = 'freshness';
            $exclusionAction = 'Wait for next scheduled forecast run at 01:00 Asia/Manila';
        } elseif (($aggregate['model_version'] ?? null) !== $approvedVersion) {
            $exclusionReason = 'Unapproved model: model ' . ($aggregate['model_version'] ?: 'none') . ' is not approved';
            $exclusionCategory = 'approval';
            $exclusionAction = 'Administrator: Review and approve candidate model in Model Review';
        } elseif (empty($aggregate['complete_forecast'])) {
            $exclusionReason = 'Incomplete forecast: missing full 28-day predictions';
            $exclusionCategory = 'evidence';
            $exclusionAction = 'Wait for next scheduled forecast run';
        } elseif (!in_array($readiness, self::SUPPORTED_READINESS, true)) {
            $exclusionReason = 'Insufficient history: readiness level is ' . ($readiness ?? 'Insufficient');
            $exclusionCategory = 'evidence';
            $exclusionAction = 'Accumulate more sales history; replenishment continues on stock rules';
        }

        return [
            'usable' => false,
            'status' => self::forecastStatus($aggregate, $hasForecast, $jobId, $observation, $suspended, $isJobObservation, $isJobFailed, $isSuperseded, $isIncompatibleModel, $incompatibilityReason, $approvedVersion, $origin, $readiness),
            'exclusion_reason' => $exclusionReason,
            'exclusion_category' => $exclusionCategory,
            'exclusion_action' => $exclusionAction,
        ];
    }

    /**
     * The Forecast Readiness page ladder.
     *
     * @param array<string, mixed> $eligibility StoreTrainingEligibilityService result ([] when unknown).
     * @param array<string, mixed>|null $aggregate The stored forecast for the Product, or null when none.
     * @return array{status: string, reason: string}
     */
    public static function readinessStatus(
        array $eligibility,
        ?array $aggregate,
        string $currentModelVersion,
        ?string $lastSaleAt,
        ?string $generatedAt
    ): array {
        $accumulatedUsable = (int)($eligibility['accumulated_usable_days'] ?? 0);

        if ($aggregate === null) {
            if (!empty($eligibility['eligible'])) {
                return [
                    'status' => 'Ready for training',
                    'reason' => 'Ready for training. Product meets all prerequisites (' . $accumulatedUsable
                        . ' usable days, supported feature window, and holdout coverage). Forecast Readiness bands are '
                        . 'separate evidence ratings; candidate qualification, observation, local Administrator approval, '
                        . 'and daily Forecast Generation remain separate steps.',
                ];
            }

            return [
                'status' => 'Insufficient Data',
                'reason' => !empty($eligibility['reason'])
                    ? (string)$eligibility['reason']
                    : sprintf('%d accumulated usable days available. At least %d accumulated usable days are required.',
                        $accumulatedUsable, StoreTrainingEligibilityService::MINIMUM_USABLE_DAYS),
            ];
        }

        if (($aggregate['model_version'] ?? '') !== $currentModelVersion) {
            return [
                'status' => 'Model Requires Retraining',
                'reason' => 'The stored forecast was created by an older model version.',
            ];
        }

        if ($lastSaleAt && $generatedAt && strtotime((string)$lastSaleAt) > strtotime((string)$generatedAt)) {
            return [
                'status' => 'Model Requires Retraining',
                'reason' => 'Sales history changed after the latest forecast.',
            ];
        }

        return [
            'status' => 'Forecast Generated',
            'reason' => 'Forecast available. Training Eligibility and Forecast Readiness evidence bands are separate from candidate qualification, observation, local Administrator approval, and daily Forecast Generation.',
        ];
    }

    /**
     * The per-Product display status ("High readiness forecast", "Stale
     * forecast: ...", "Forecast unavailable", ...) for the Replenishment Queue.
     *
     * @param array<string, mixed> $aggregate
     */
    private static function forecastStatus(
        array $aggregate,
        bool $hasForecast,
        ?int $jobId,
        bool $observation,
        bool $suspended,
        bool $isJobObservation,
        bool $isJobFailed,
        bool $isSuperseded,
        bool $isIncompatibleModel,
        string $incompatibilityReason,
        string $approvedVersion,
        string $origin,
        mixed $readiness
    ): string {
        if (!$hasForecast) {
            return 'Forecast unavailable';
        }

        $statusParts = [];
        if ($observation || $isJobObservation) {
            $statusParts[] = 'Observation Mode: forecast is observation telemetry only';
        }
        if ($suspended) {
            $statusParts[] = 'Recommendations Suspended by Administrator';
        }
        if (!$observation && !$suspended && !$isJobObservation) {
            if ($isJobFailed) {
                $statusParts[] = 'Failed forecast: run #' . $jobId;
            } elseif ($isSuperseded) {
                $statusParts[] = 'Superseded forecast: run #' . $jobId;
            } elseif (($aggregate['forecast_origin'] ?? null) !== $origin) {
                $statusParts[] = 'Stale forecast: ' . $aggregate['forecast_origin'];
            } elseif ($approvedVersion === '' || ($aggregate['model_version'] ?? null) !== $approvedVersion) {
                $statusParts[] = 'Unapproved model: ' . ($aggregate['model_version'] ?: 'none');
            } elseif ($isIncompatibleModel) {
                $statusParts[] = 'Incompatible model: ' . $incompatibilityReason;
            } elseif (empty($aggregate['complete_forecast'])) {
                $statusParts[] = 'Incomplete forecast';
            } else {
                $statusParts[] = $readiness . ' readiness forecast';
            }
        }

        return implode('; ', $statusParts);
    }

    /**
     * Demo/Store database and feature-schema compatibility of the run's model.
     *
     * @param array<string, mixed>|null $jobMeta
     * @return array{0: bool, 1: string}
     */
    private static function incompatibility(?array $jobMeta, bool $isDemoDb): array
    {
        if ($jobMeta === null) {
            return [false, ''];
        }
        if (!$isDemoDb && !empty($jobMeta['is_demo'])) {
            return [true, 'Demo model cannot be used in Store database'];
        }
        if ($isDemoDb && empty($jobMeta['is_demo']) && ($jobMeta['dataset_kind'] ?? null) === 'store') {
            return [true, 'Store model cannot be used in Demo database'];
        }
        if (!empty($jobMeta['feature_version']) && $jobMeta['feature_version'] !== '2.0.0') {
            return [true, 'Obsolete feature schema ' . $jobMeta['feature_version']];
        }

        return [false, ''];
    }
}
