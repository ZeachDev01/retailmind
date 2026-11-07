<?php
declare(strict_types=1);

namespace App\Forecasting;

use RuntimeException;

/**
 * Shared pure helpers for forecast error metrics and ADR-0005 readiness bands.
 *
 * Band thresholds and limitation text live in config/forecast_readiness_bands.json
 * so the PHP services and the Python worker share one definition. The JSON is the
 * single source of truth; no band literal belongs in the callers.
 */
final class ForecastMetrics
{
    private const BANDS_PATH = '/config/forecast_readiness_bands.json';

    /** @var array<string, mixed>|null */
    private static ?array $bands = null;

    /**
     * Mean absolute error over paired actual/predicted values.
     *
     * @param array<int|string, int|float> $actuals
     * @param array<int|string, int|float> $predictions
     */
    public static function mae(array $actuals, array $predictions): ?float
    {
        $paired = self::pairedErrors($actuals, $predictions);
        if ($paired === null) {
            return null;
        }
        [$absErrors, , $count] = $paired;
        return array_sum($absErrors) / $count;
    }

    /**
     * Root mean squared error over paired actual/predicted values.
     *
     * @param array<int|string, int|float> $actuals
     * @param array<int|string, int|float> $predictions
     */
    public static function rmse(array $actuals, array $predictions): ?float
    {
        $paired = self::pairedErrors($actuals, $predictions);
        if ($paired === null) {
            return null;
        }
        [, $sqErrors, $count] = $paired;
        return sqrt(array_sum($sqErrors) / $count);
    }

    /**
     * Weighted absolute percentage error as a fraction (0.15 = 15%).
     *
     * Returns null when the actual total is zero so callers can distinguish
     * "no demand to score" from a genuine zero error.
     *
     * @param array<int|string, int|float> $actuals
     * @param array<int|string, int|float> $predictions
     */
    public static function wape(array $actuals, array $predictions): ?float
    {
        $paired = self::pairedErrors($actuals, $predictions);
        if ($paired === null) {
            return null;
        }
        [$absErrors] = $paired;
        $sumActual = array_sum($actuals);
        return $sumActual > 0 ? array_sum($absErrors) / $sumActual : null;
    }

    /**
     * ADR-0005 readiness band for a usable-history day count.
     *
     * `High` additionally requires qualification when the shared definition asks
     * for it. PHP's DemandHistoryService always qualifies, while the Python worker
     * only qualifies a passed model outcome, so both behaviours are expressible.
     *
     * @return array{level: string, limitation_marker: ?string}
     */
    public static function readinessLevel(int $usableHistoryDays, bool $qualified = true): array
    {
        $bands = self::bands();
        $thresholds = $bands['thresholds'];

        if ($usableHistoryDays < $thresholds['low']) {
            $key = 'insufficient';
        } elseif ($usableHistoryDays < $thresholds['medium']) {
            $key = 'low';
        } elseif ($usableHistoryDays < $thresholds['high']) {
            $key = 'medium';
        } elseif ($qualified || empty($bands['high_requires_qualification'])) {
            $key = 'high';
        } else {
            $key = 'medium';
        }

        return [
            'level' => $bands['bands'][$key]['level'],
            'limitation_marker' => $bands['bands'][$key]['limitation_marker'],
        ];
    }

    /**
     * @param array<int|string, int|float> $actuals
     * @param array<int|string, int|float> $predictions
     * @return array{0: list<float>, 1: list<float>, 2: int}|null
     */
    private static function pairedErrors(array $actuals, array $predictions): ?array
    {
        $count = count($actuals);
        if ($count === 0 || $count !== count($predictions)) {
            return null;
        }

        $actuals = array_values($actuals);
        $predictions = array_values($predictions);
        $absErrors = [];
        $sqErrors = [];
        for ($i = 0; $i < $count; $i++) {
            $diff = (float)$predictions[$i] - (float)$actuals[$i];
            $absErrors[] = abs($diff);
            $sqErrors[] = $diff * $diff;
        }

        return [$absErrors, $sqErrors, $count];
    }

    /** @return array<string, mixed> */
    private static function bands(): array
    {
        if (self::$bands === null) {
            $path = dirname(__DIR__, 2) . self::BANDS_PATH;
            $raw = @file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException("Forecast readiness bands are unavailable: {$path}");
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || !isset($decoded['thresholds'], $decoded['bands'])) {
                throw new RuntimeException("Forecast readiness bands are malformed: {$path}");
            }
            self::$bands = $decoded;
        }

        return self::$bands;
    }
}
