<?php
declare(strict_types=1);

namespace App\Forecasting;

use PDO;

class DemandForecastService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getForecastAggregates(int $jobId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                product_id,
                MAX(forecast_origin) as forecast_origin,
                MAX(model_version) as model_version,
                MAX(readiness_level) as readiness_level,
                MAX(limitation_marker) as limitation_marker,
                SUM(CASE WHEN horizon_day <= 7 THEN predicted_units ELSE 0 END) as forecast_7_day,
                SUM(CASE WHEN horizon_day <= 14 THEN predicted_units ELSE 0 END) as forecast_14_day,
                SUM(CASE WHEN horizon_day <= 28 THEN predicted_units ELSE 0 END) as forecast_28_day
            FROM demand_forecasts
            WHERE job_id = :job_id
              AND EXISTS (SELECT 1 FROM ml_jobs j WHERE j.job_id = demand_forecasts.job_id
                          AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded')
            GROUP BY product_id
        ");
        $stmt->execute(['job_id' => $jobId]);
        
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Ensure types are correct
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
            SELECT f.model_version,
                   (SELECT t.metrics_json FROM model_training_runs t
                    WHERE t.model_version = f.model_version AND t.status = 'completed'
                    ORDER BY t.training_run_id DESC LIMIT 1) AS metrics_json
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
        $metrics = json_decode((string)($metadata['metrics_json'] ?? ''), true);
        return [
            'model_version' => $metadata['model_version'],
            'is_demo' => ($metrics['metadata']['dataset_kind'] ?? null) === 'demo',
        ];
    }

    public function getProductForecast(int $jobId, int $productId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                product_id,
                MAX(forecast_origin) as forecast_origin,
                MAX(model_version) as model_version,
                MAX(readiness_level) as readiness_level,
                MAX(limitation_marker) as limitation_marker,
                SUM(CASE WHEN horizon_day <= 7 THEN predicted_units ELSE 0 END) as forecast_7_day,
                SUM(CASE WHEN horizon_day <= 14 THEN predicted_units ELSE 0 END) as forecast_14_day,
                SUM(CASE WHEN horizon_day <= 28 THEN predicted_units ELSE 0 END) as forecast_28_day
            FROM demand_forecasts
            WHERE job_id = :job_id AND product_id = :product_id
              AND EXISTS (SELECT 1 FROM ml_jobs j WHERE j.job_id = demand_forecasts.job_id
                          AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded')
            GROUP BY product_id
        ");
        $stmt->execute(['job_id' => $jobId, 'product_id' => $productId]);
        
        $aggregates = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($aggregates) {
            $aggregates['product_id'] = (int)$aggregates['product_id'];
            $aggregates['forecast_7_day'] = (int)$aggregates['forecast_7_day'];
            $aggregates['forecast_14_day'] = (int)$aggregates['forecast_14_day'];
            $aggregates['forecast_28_day'] = (int)$aggregates['forecast_28_day'];
        }

        $dailyStmt = $this->pdo->prepare("
            SELECT 
                target_date,
                horizon_day,
                predicted_units,
                readiness_level,
                limitation_marker
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
            'aggregates' => $aggregates ?: null,
            'daily' => $daily
        ];
    }
}
