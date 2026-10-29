<?php

namespace App\Forecasting;

use PDO;
use DateTime;
use App\Services\MlJobService;

class ForecastingSchedulerService {
    private PDO $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    public function runScheduler(DateTime $now = null): array {
        if ($now === null) {
            $now = new DateTime('now', new \DateTimeZone('Asia/Manila'));
        }
        $now = clone $now;
        $now->setTimezone(new \DateTimeZone('Asia/Manila'));
        
        $result = [
            'forecast_jobs_queued' => 0,
            'retrain_jobs_queued' => 0,
            'training_eligibility' => []
        ];
        
        // Default settings if missing
        $dailyTime = '23:00';
        $weeklyDay = 'Sunday';
        $weeklyTime = '01:00';
        
        // Fetch from platform_settings
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('schedule_daily_forecast_time', 'schedule_weekly_retrain_day', 'schedule_weekly_retrain_time')");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        if (!empty($settings['schedule_daily_forecast_time'])) {
            $dailyTime = $settings['schedule_daily_forecast_time'];
        }
        if (!empty($settings['schedule_weekly_retrain_day'])) {
            $weeklyDay = $settings['schedule_weekly_retrain_day'];
        }
        if (!empty($settings['schedule_weekly_retrain_time'])) {
            $weeklyTime = $settings['schedule_weekly_retrain_time'];
        }
        
        $currentDate = $now->format('Y-m-d');
        $currentTime = $now->format('H:i');
        $currentDayName = $now->format('l'); // 'Sunday', 'Monday', etc.
        
        $jobService = new MlJobService($this->pdo);
        
        // Check if daily forecast should be queued
        if ($currentTime >= $dailyTime) {
            // Did we already queue a forecast generation today?
            $alreadyQueued = $this->checkIfJobQueuedToday('forecast_generation', $currentDate);
            if (!$alreadyQueued) {
                // system job -> no requester
                $jobService->enqueueSystem('forecast_generation');
                $result['forecast_jobs_queued']++;
            }
        }
        
        $latestTraining = $this->pdo->query("SELECT metrics_json FROM model_training_runs
            WHERE status = 'completed' ORDER BY training_run_id DESC LIMIT 1")->fetchColumn();
        $firstTrainingDue = $latestTraining === false && $currentTime >= $dailyTime;
        $weeklyTrainingDue = strcasecmp($currentDayName, $weeklyDay) === 0 && $currentTime >= $weeklyTime;
        if ($firstTrainingDue || $weeklyTrainingDue) {
            $alreadyQueued = $this->checkIfJobQueuedToday('model_retraining', $currentDate);
            $trainingPending = (bool)$this->pdo->query("SELECT 1 FROM ml_jobs
                WHERE trigger_type = 'model_retraining' AND status IN ('queued', 'running') LIMIT 1")->fetchColumn();
            if (!$alreadyQueued && !$trainingPending) {
                $isDemo = (bool)$this->pdo->query("SELECT 1 FROM platform_settings WHERE setting_key = 'demo_import_metadata'")->fetchColumn();
                $cutoff = (clone $now)->modify('-1 day')->format('Y-m-d');
                $lastEvidence = json_decode((string)$latestTraining, true)['metadata']['data_window_end'] ?? null;
                $hasNewHistory = $lastEvidence === null || $lastEvidence < $cutoff;
                if (!$isDemo && $hasNewHistory) {
                    $history = (new DemandHistoryService())->getTrainingHistory($this->pdo,
                        $cutoff);
                    $result['training_eligibility'] = $history['eligibility'];
                }
                // Demo history is frozen; only its existing weekly schedule applies.
                if (($isDemo && $weeklyTrainingDue) || (!$isDemo && $hasNewHistory && $history['history'])) {
                    $jobService->enqueueSystem('model_retraining');
                    $result['retrain_jobs_queued']++;
                }
            }
        }
        
        return $result;
    }
    
    private function checkIfJobQueuedToday(string $triggerType, string $dateStr): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM ml_jobs 
            WHERE trigger_type = ? 
            AND DATE(queued_at) = ? 
        ");
        $stmt->execute([$triggerType, $dateStr]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
