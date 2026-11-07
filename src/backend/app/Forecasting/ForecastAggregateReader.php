<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use Throwable;

/**
 * Single source of truth for the complete 28-day Forecast Horizon aggregate.
 *
 * Returns, per Product for a job, the 7/14/28-day sums plus Forecast Origin,
 * model version, readiness level, limitation marker, a completeness flag, and
 * the job id. The completeness rule and the probes for the optional
 * `target_date` / `limitation_marker` columns live only here.
 */
final class ForecastAggregateReader
{
    private PDO $pdo;
    private ?bool $hasTargetDate = null;
    private ?bool $hasLimitationMarker = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Per-Product aggregates for one job, keyed by product_id.
     *
     * @return array<int, array{
     *     product_id:int, forecast_origin:?string, model_version:?string,
     *     readiness_level:?string, limitation_marker:?string,
     *     forecast_7_day:int, forecast_14_day:int, forecast_28_day:int,
     *     complete_forecast:bool, job_id:int
     * }>
     */
    public function read(int $jobId, bool $requireSuccessfulJob = true): array
    {
        if ($jobId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare($this->aggregateQuery($requireSuccessfulJob));
        $stmt->execute(['job_id' => $jobId]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)$row['product_id'];
            $results[$productId] = [
                'product_id' => $productId,
                'forecast_origin' => $row['forecast_origin'],
                'model_version' => $row['model_version'],
                'readiness_level' => $row['readiness_level'],
                'limitation_marker' => $row['limitation_marker'],
                'forecast_7_day' => (int)$row['forecast_7_day'],
                'forecast_14_day' => (int)$row['forecast_14_day'],
                'forecast_28_day' => (int)$row['forecast_28_day'],
                'complete_forecast' => (bool)$row['complete_forecast'],
                'job_id' => $jobId,
            ];
        }

        return $results;
    }

    /**
     * Per-Product aggregates for the latest successful Forecast Generation job.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readLatestSuccessful(): array
    {
        $jobId = $this->latestSuccessfulJobId();
        return $jobId === null ? [] : $this->read($jobId);
    }

    /**
     * Aggregate predicted units within each Product's supplier lead time.
     *
     * @return array<int, int> product_id => units
     */
    public function readLeadTimeDemand(int $jobId): array
    {
        if ($jobId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT df.product_id AS product_id,
                   SUM(CASE WHEN df.horizon_day <= COALESCE(
                       (SELECT supplier_lead_time_days FROM products WHERE product_id = df.product_id LIMIT 1),
                       7
                   ) THEN df.predicted_units ELSE 0 END) AS forecast_lead_time
            FROM demand_forecasts df
            WHERE df.job_id = :job_id
            GROUP BY df.product_id
        ");
        $stmt->execute(['job_id' => $jobId]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[(int)$row['product_id']] = (int)$row['forecast_lead_time'];
        }

        return $results;
    }

    public function latestSuccessfulJobId(): ?int
    {
        $stmt = $this->pdo->query("
            SELECT job_id
            FROM ml_jobs
            WHERE trigger_type = 'forecast_generation' AND status = 'succeeded'
            ORDER BY completed_at DESC, job_id DESC
            LIMIT 1
        ");
        $jobId = $stmt->fetchColumn();

        return $jobId !== false ? (int)$jobId : null;
    }

    public function hasTargetDate(): bool
    {
        return $this->hasTargetDate ??= $this->hasColumn('target_date');
    }

    public function hasLimitationMarker(): bool
    {
        return $this->hasLimitationMarker ??= $this->hasColumn('limitation_marker');
    }

    private function aggregateQuery(bool $requireSuccessfulJob): string
    {
        $alias = 'df';
        $limitation = $this->hasLimitationMarker()
            ? "MAX({$alias}.limitation_marker) AS limitation_marker"
            : "NULL AS limitation_marker";
        $successfulOnly = $requireSuccessfulJob
            ? "AND EXISTS (SELECT 1 FROM ml_jobs j WHERE j.job_id = {$alias}.job_id
                           AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded')"
            : '';

        return "
            SELECT
                {$alias}.product_id AS product_id,
                MAX({$alias}.forecast_origin) AS forecast_origin,
                MAX({$alias}.model_version) AS model_version,
                MAX({$alias}.readiness_level) AS readiness_level,
                {$limitation},
                SUM(CASE WHEN {$alias}.horizon_day <= 7 THEN {$alias}.predicted_units ELSE 0 END) AS forecast_7_day,
                SUM(CASE WHEN {$alias}.horizon_day <= 14 THEN {$alias}.predicted_units ELSE 0 END) AS forecast_14_day,
                SUM(CASE WHEN {$alias}.horizon_day <= 28 THEN {$alias}.predicted_units ELSE 0 END) AS forecast_28_day,
                CASE WHEN {$this->completeExpression($alias)} THEN 1 ELSE 0 END AS complete_forecast
            FROM demand_forecasts {$alias}
            WHERE {$alias}.job_id = :job_id
              {$successfulOnly}
            GROUP BY {$alias}.product_id
        ";
    }

    /**
     * The complete 28-day Forecast Horizon rule: 28 rows, distinct horizon days
     * 1..28, a single Forecast Origin and model version, non-negative predictions.
     */
    private function completeExpression(string $alias): string
    {
        $targetDate = $this->hasTargetDate() ? "AND COUNT(DISTINCT {$alias}.target_date) = 28" : '';

        return "
            COUNT(*) = 28
            AND COUNT(DISTINCT {$alias}.horizon_day) = 28
            {$targetDate}
            AND MIN({$alias}.horizon_day) = 1
            AND MAX({$alias}.horizon_day) = 28
            AND MIN({$alias}.predicted_units) >= 0
            AND COUNT({$alias}.predicted_units) = 28
            AND COUNT(DISTINCT {$alias}.forecast_origin) = 1
            AND COUNT(DISTINCT {$alias}.model_version) = 1
            AND COUNT({$alias}.forecast_origin) = 28
            AND COUNT({$alias}.model_version) = 28
            AND COUNT({$alias}.readiness_level) = 28
        ";
    }

    private function hasColumn(string $column): bool
    {
        try {
            $this->pdo->query("SELECT {$column} FROM demand_forecasts LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
