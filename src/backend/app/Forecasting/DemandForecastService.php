<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class DemandForecastService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Persist complete operational forecast records transactionally.
     * All 28 daily records per product must be complete, distinct, ordered, nonnegative,
     * and belong to a valid operational run; partial writes roll back and throw an exception.
     *
     * @param int $jobId
     * @param array<int, array<string, mixed>> $records
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function persistForecasts(int $jobId, array $records): void
    {
        if ($jobId <= 0) {
            throw new InvalidArgumentException("Invalid job ID: {$jobId}");
        }

        if (empty($records)) {
            throw new InvalidArgumentException("No forecast records provided for job {$jobId}");
        }

        // Verify job exists
        $jobStmt = $this->pdo->prepare("SELECT trigger_type, status FROM ml_jobs WHERE job_id = ?");
        $jobStmt->execute([$jobId]);
        $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            throw new RuntimeException("Job {$jobId} not found");
        }

        // Group records by product
        $byProduct = [];
        foreach ($records as $index => $rec) {
            $pid = (int)($rec['product_id'] ?? 0);
            if ($pid <= 0) {
                throw new InvalidArgumentException("Record at index {$index} missing valid product_id");
            }
            $byProduct[$pid][] = $rec;
        }

        // Validate each product's 28 daily records
        foreach ($byProduct as $pid => $prods) {
            if (count($prods) !== 28) {
                throw new InvalidArgumentException("Product {$pid} must have exactly 28 daily records, got " . count($prods));
            }

            $horizons = [];
            $dates = [];
            $origin = null;
            $modelVersion = null;
            $readiness = null;

            foreach ($prods as $pRec) {
                $h = (int)($pRec['horizon_day'] ?? 0);
                if ($h < 1 || $h > 28) {
                    throw new InvalidArgumentException("Product {$pid} horizon_day must be between 1 and 28, got {$h}");
                }
                if (isset($horizons[$h])) {
                    throw new InvalidArgumentException("Product {$pid} duplicate horizon_day {$h}");
                }
                $horizons[$h] = true;

                $targetDate = (string)($pRec['target_date'] ?? '');
                if ($targetDate === '') {
                    throw new InvalidArgumentException("Product {$pid} target_date cannot be empty");
                }
                if (isset($dates[$targetDate])) {
                    throw new InvalidArgumentException("Product {$pid} duplicate target_date {$targetDate}");
                }
                $dates[$targetDate] = true;

                $predicted = $pRec['predicted_units'] ?? null;
                if ($predicted === null || (int)$predicted < 0) {
                    throw new InvalidArgumentException("Product {$pid} predicted_units must be nonnegative integer, got " . var_export($predicted, true));
                }

                $recOrigin = (string)($pRec['forecast_origin'] ?? '');
                if ($recOrigin === '') {
                    throw new InvalidArgumentException("Product {$pid} forecast_origin cannot be empty");
                }
                if ($origin === null) {
                    $origin = $recOrigin;
                } elseif ($origin !== $recOrigin) {
                    throw new InvalidArgumentException("Product {$pid} inconsistent forecast_origin");
                }

                $recModel = (string)($pRec['model_version'] ?? '');
                if ($recModel === '') {
                    throw new InvalidArgumentException("Product {$pid} model_version cannot be empty");
                }
                if ($modelVersion === null) {
                    $modelVersion = $recModel;
                } elseif ($modelVersion !== $recModel) {
                    throw new InvalidArgumentException("Product {$pid} inconsistent model_version");
                }

                $recReadiness = (string)($pRec['readiness_level'] ?? '');
                if (!in_array($recReadiness, ['Insufficient', 'Low', 'Medium', 'High'], true)) {
                    throw new InvalidArgumentException("Product {$pid} invalid readiness_level: {$recReadiness}");
                }
                if ($readiness === null) {
                    $readiness = $recReadiness;
                } elseif ($readiness !== $recReadiness) {
                    throw new InvalidArgumentException("Product {$pid} inconsistent readiness_level");
                }
            }

            // Ensure all horizons 1..28 are present
            for ($d = 1; $d <= 28; $d++) {
                if (!isset($horizons[$d])) {
                    throw new InvalidArgumentException("Product {$pid} missing horizon_day {$d}");
                }
            }
        }

        // Transactionally insert
        $ownsTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownsTx = true;
        }

        try {
            $hasTargetDate = $this->hasColumn('demand_forecasts', 'target_date');
            $hasLimitation = $this->hasColumn('demand_forecasts', 'limitation_marker');

            if ($hasTargetDate && $hasLimitation) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO demand_forecasts
                    (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level, limitation_marker)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($records as $r) {
                    $stmt->execute([
                        $jobId,
                        (int)$r['product_id'],
                        $r['forecast_origin'],
                        $r['target_date'],
                        (int)$r['horizon_day'],
                        max(0, (int)$r['predicted_units']),
                        $r['model_version'],
                        $r['readiness_level'],
                        $r['limitation_marker'] ?? null,
                    ]);
                }
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO demand_forecasts
                    (job_id, product_id, forecast_origin, horizon_day, predicted_units, model_version, readiness_level)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($records as $r) {
                    $stmt->execute([
                        $jobId,
                        (int)$r['product_id'],
                        $r['forecast_origin'],
                        (int)$r['horizon_day'],
                        max(0, (int)$r['predicted_units']),
                        $r['model_version'],
                        $r['readiness_level'],
                    ]);
                }
            }

            if ($ownsTx) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getForecastAggregates(int $jobId): array
    {
        $hasTargetDate = $this->hasColumn('demand_forecasts', 'target_date');
        $targetDateCheck = $hasTargetDate ? 'AND COUNT(DISTINCT target_date) = 28' : '';
        $limitationSelect = $this->hasColumn('demand_forecasts', 'limitation_marker') ? 'MAX(limitation_marker) as limitation_marker,' : 'NULL as limitation_marker,';

        $stmt = $this->pdo->prepare("
            SELECT 
                product_id,
                MAX(forecast_origin) as forecast_origin,
                MAX(model_version) as model_version,
                MAX(readiness_level) as readiness_level,
                {$limitationSelect}
                SUM(CASE WHEN horizon_day <= 7 THEN predicted_units ELSE 0 END) as forecast_7_day,
                SUM(CASE WHEN horizon_day <= 14 THEN predicted_units ELSE 0 END) as forecast_14_day,
                SUM(CASE WHEN horizon_day <= 28 THEN predicted_units ELSE 0 END) as forecast_28_day
            FROM demand_forecasts
            WHERE job_id = :job_id
              AND EXISTS (SELECT 1 FROM ml_jobs j WHERE j.job_id = demand_forecasts.job_id
                          AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded')
            GROUP BY product_id
            HAVING COUNT(*) = 28
               AND COUNT(DISTINCT horizon_day) = 28
               {$targetDateCheck}
               AND MIN(horizon_day) = 1
               AND MAX(horizon_day) = 28
               AND MIN(predicted_units) >= 0
               AND COUNT(predicted_units) = 28
               AND COUNT(DISTINCT forecast_origin) = 1
               AND COUNT(DISTINCT model_version) = 1
               AND COUNT(forecast_origin) = 28
               AND COUNT(model_version) = 28
               AND COUNT(readiness_level) = 28
        ");
        $stmt->execute(['job_id' => $jobId]);
        
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['product_id'] = (int)$row['product_id'];
            $row['forecast_7_day'] = (int)$row['forecast_7_day'];
            $row['forecast_14_day'] = (int)$row['forecast_14_day'];
            $row['forecast_28_day'] = (int)$row['forecast_28_day'];
            $results[] = $row;
        }
        
        return $results;
    }
    
    public function getLatestSuccessfulJobId(): ?int
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

    public function getJobMetadata(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT f.model_version
            FROM demand_forecasts f
            JOIN ml_jobs j ON j.job_id = f.job_id
            WHERE f.job_id = ? AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded'
            ORDER BY f.forecast_id
            LIMIT 1
        ");
        $stmt->execute([$jobId]);
        $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$metadata) {
            return null;
        }
        // Legacy training and forecast tables can use different collations.
        $stmt = $this->pdo->prepare("SELECT metrics_json FROM model_training_runs
            WHERE model_version = ? AND status = 'completed'
            ORDER BY training_run_id DESC LIMIT 1");
        $stmt->execute([$metadata['model_version']]);
        $metrics = json_decode((string)($stmt->fetchColumn() ?: ''), true);
        return [
            'model_version' => $metadata['model_version'],
            'is_demo' => ($metrics['metadata']['dataset_kind'] ?? null) === 'demo',
            'dataset_kind' => $metrics['metadata']['dataset_kind'] ?? null,
            'feature_version' => $metrics['metadata']['feature_version'] ?? null,
            'source_label' => $metrics['metadata']['source']['source_label'] ?? 'S001',
        ];
    }

    public function getProductForecast(int $jobId, int $productId): array
    {
        $hasTargetDate = $this->hasColumn('demand_forecasts', 'target_date');
        $targetDateCheck = $hasTargetDate ? 'AND COUNT(DISTINCT target_date) = 28' : '';
        $limitationSelect = $this->hasColumn('demand_forecasts', 'limitation_marker') ? 'MAX(limitation_marker) as limitation_marker,' : 'NULL as limitation_marker,';

        $stmt = $this->pdo->prepare("
            SELECT 
                product_id,
                MAX(forecast_origin) as forecast_origin,
                MAX(model_version) as model_version,
                MAX(readiness_level) as readiness_level,
                {$limitationSelect}
                SUM(CASE WHEN horizon_day <= 7 THEN predicted_units ELSE 0 END) as forecast_7_day,
                SUM(CASE WHEN horizon_day <= 14 THEN predicted_units ELSE 0 END) as forecast_14_day,
                SUM(CASE WHEN horizon_day <= 28 THEN predicted_units ELSE 0 END) as forecast_28_day
            FROM demand_forecasts
            WHERE job_id = :job_id AND product_id = :product_id
              AND EXISTS (SELECT 1 FROM ml_jobs j WHERE j.job_id = demand_forecasts.job_id
                          AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded')
            GROUP BY product_id
            HAVING COUNT(*) = 28
               AND COUNT(DISTINCT horizon_day) = 28
               {$targetDateCheck}
               AND MIN(horizon_day) = 1
               AND MAX(horizon_day) = 28
               AND MIN(predicted_units) >= 0
               AND COUNT(predicted_units) = 28
               AND COUNT(DISTINCT forecast_origin) = 1
               AND COUNT(DISTINCT model_version) = 1
               AND COUNT(forecast_origin) = 28
               AND COUNT(model_version) = 28
               AND COUNT(readiness_level) = 28
        ");
        $stmt->execute(['job_id' => $jobId, 'product_id' => $productId]);
        
        $aggregates = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($aggregates) {
            $aggregates['product_id'] = (int)$aggregates['product_id'];
            $aggregates['forecast_7_day'] = (int)$aggregates['forecast_7_day'];
            $aggregates['forecast_14_day'] = (int)$aggregates['forecast_14_day'];
            $aggregates['forecast_28_day'] = (int)$aggregates['forecast_28_day'];
        } else {
            return [
                'aggregates' => null,
                'daily' => []
            ];
        }

        $dailyTarget = $hasTargetDate ? 'target_date,' : '';
        $dailyLimit = $this->hasColumn('demand_forecasts', 'limitation_marker') ? 'limitation_marker' : 'NULL as limitation_marker';

        $dailyStmt = $this->pdo->prepare("
            SELECT 
                {$dailyTarget}
                horizon_day,
                predicted_units,
                readiness_level,
                {$dailyLimit}
            FROM demand_forecasts
            WHERE job_id = :job_id AND product_id = :product_id
              AND EXISTS (SELECT 1 FROM ml_jobs j WHERE j.job_id = demand_forecasts.job_id
                          AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded')
            ORDER BY horizon_day ASC
        ");
        $dailyStmt->execute(['job_id' => $jobId, 'product_id' => $productId]);
        
        $daily = [];
        while ($row = $dailyStmt->fetch(PDO::FETCH_ASSOC)) {
            $row['horizon_day'] = (int)$row['horizon_day'];
            $row['predicted_units'] = (int)$row['predicted_units'];
            $daily[] = $row;
        }

        return [
            'aggregates' => $aggregates,
            'daily' => $daily
        ];
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $this->pdo->query("SELECT {$column} FROM {$table} LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
