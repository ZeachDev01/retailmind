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
}
