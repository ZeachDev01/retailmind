<?php

namespace App\Forecasting;

use PDO;
use DateTime;
use DateTimeZone;
use App\Services\MlJobService;

class ForecastingSchedulerService {
    private PDO $pdo;
    private ?bool $hasCutoffColumn = null;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public static function canAutomaticallyRetry(array $job): bool {
        $diagnostic = (string)($job['diagnostic_reference'] ?? '');
        return ($job['status'] ?? '') === 'failed'
            && (int)($job['attempts'] ?? 0) < 3
            && !str_starts_with($diagnostic, 'BLOCKED:')
            && !str_contains($diagnostic, 'EXHAUSTED');
    }

    private function hasCutoffColumn(): bool {
        if ($this->hasCutoffColumn !== null) {
            return $this->hasCutoffColumn;
        }
        try {
            $stmt = $this->pdo->query("SELECT evidence_cutoff FROM ml_jobs LIMIT 0");
            $this->hasCutoffColumn = ($stmt !== false);
        } catch (\Throwable) {
            $this->hasCutoffColumn = false;
        }
        return $this->hasCutoffColumn;
    }

    /**
     * Recovers expired worker leases and enforces the 3-attempt ceiling.
     */
    public function recoverExpiredLeases(DateTime $now = null): int {
        $nowStr = $now ? $now->format('Y-m-d H:i:s') : (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        try {
            // Fail jobs with expired lease that reached or exceeded 3 attempts
            $stmt1 = $this->pdo->prepare("
                UPDATE ml_jobs 
                SET status = 'failed', 
                    completed_at = :now,
                    operator_message = 'Job failed: lease expired and maximum retries (3) exceeded.',
                    diagnostic_reference = 'LEASE_TIMEOUT_EXHAUSTED'
                WHERE status = 'running' 
                  AND lease_expires_at IS NOT NULL 
                  AND lease_expires_at <= :now 
                  AND attempts >= 3
            ");
            $stmt1->execute(['now' => $nowStr]);

            // Re-queue expired lease jobs under 3 attempts for retry on later tick
            $stmt2 = $this->pdo->prepare("
                UPDATE ml_jobs 
                SET status = 'queued', 
                    lease_expires_at = NULL 
                WHERE status = 'running' 
                  AND lease_expires_at IS NOT NULL 
                  AND lease_expires_at <= :now 
                  AND attempts < 3
            ");
            $stmt2->execute(['now' => $nowStr]);

            return $stmt1->rowCount() + $stmt2->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }
    
    public function runScheduler(DateTime $now = null): array {
        if ($now === null) {
            $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
        }
        $now = clone $now;
        $now->setTimezone(new DateTimeZone('Asia/Manila'));
        
        $targetCutoff = (clone $now)->modify('-1 day')->format('Y-m-d');
        $result = [
            'forecast_jobs_queued' => 0,
            'retrain_jobs_queued' => 0,
            'observation_jobs_queued' => 0,
            'training_eligibility' => [],
            'cutoff' => $targetCutoff,
        ];

        // 1. Recover expired worker leases before scheduling
        $this->recoverExpiredLeases($now);
        
        // Effective settings resolution & recording (01:00 daily, Sunday 01:00 retrain Asia/Manila)
        try {
            $effectiveSchedule = $this->recordEffectiveSchedule();
        } catch (\Throwable $e) {
            $this->preserveLocalDiagnostic('Database connection error in scheduler: ' . $e->getMessage(), 'DB_UNAVAILABLE');
            return $result;
        }

        $dailyTime = $effectiveSchedule['daily_forecast_time'];
        $weeklyDay = $effectiveSchedule['weekly_retrain_day'];
        $weeklyTime = $effectiveSchedule['weekly_retrain_time'];
        $result['effective_schedule'] = $effectiveSchedule;
        
        $currentDate = $now->format('Y-m-d');
        $currentTime = $now->format('H:i');
        $currentDayName = $now->format('l'); // 'Sunday', 'Monday', etc.
        
        $jobService = new MlJobService($this->pdo);

        // 2. Daily Forecast Generation Scheduling & Catch-up
        $this->handleForecastGeneration(
            $jobService,
            $now,
            $targetCutoff,
            $currentDate,
            $currentTime,
            $dailyTime,
            $result
        );

        // 3. Candidate Observation Forecasting & Catch-up (#367)
        $this->handleCandidateObservation(
            $jobService,
            $now,
            $targetCutoff,
            $currentDate,
            $currentTime,
            $dailyTime,
            $result
        );

        // 4. Candidate Model Retraining Scheduling & Catch-up
        $this->handleModelRetraining(
            $jobService,
            $now,
            $targetCutoff,
            $currentDate,
            $currentTime,
            $currentDayName,
            $dailyTime,
            $weeklyDay,
            $weeklyTime,
            $result
        );
        
        return $result;
    }

    private function handleForecastGeneration(
        MlJobService $jobService,
        DateTime $now,
        string $targetCutoff,
        string $currentDate,
        string $currentTime,
        string $dailyTime,
        array &$result
    ): void {
        $nowStr = $now->format('Y-m-d H:i:s');
        $latestSuccessfulCutoff = $this->getLatestSuccessfulForecastCutoff();

        // Check if daily forecast is due:
        // (a) Scheduled time reached on normal day
        // (b) Catch-up after downtime: if last successful forecast is 2+ days older than targetCutoff,
        //     the store missed daily work while powered off. Queue ONE current forecast at startup.
        $isScheduledDue = ($currentTime >= $dailyTime);
        $isDowntimeCatchup = false;

        if ($latestSuccessfulCutoff !== null) {
            if ($latestSuccessfulCutoff < $targetCutoff) {
                $daysGap = (int)round((strtotime($targetCutoff) - strtotime($latestSuccessfulCutoff)) / 86400);
                if ($daysGap >= 2) {
                    $isDowntimeCatchup = true;
                }
            }
        }

        if (!$isScheduledDue && !$isDowntimeCatchup) {
            return;
        }

        // Check existing jobs for (forecast_generation, $targetCutoff)
        $existingJob = $this->findJob('forecast_generation', $targetCutoff, $currentDate);

        if ($existingJob) {
            $status = (string)($existingJob['status'] ?? '');
            if ($status === 'succeeded' || $status === 'queued') {
                return;
            }
            if ($status === 'running') {
                $leaseExpires = $existingJob['lease_expires_at'] ?? null;
                if ($leaseExpires && $leaseExpires > $nowStr) {
                    // Valid lease active; never race it
                    return;
                }
            }
            if ($status === 'failed') {
                if (!self::canAutomaticallyRetry($existingJob)) return;
                // Retry the SAME logical job on a later tick rather than a busy loop
                $jobService->retryJob((int)$existingJob['job_id']);
                return;
            }
        }

        // No existing job for this logical run: check prerequisites before queueing
        $approvedModel = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'")->fetchColumn();
        if (empty($approvedModel)) {
            // Treat missing approval as explicit blocked condition
            $this->recordBlockedJob('forecast_generation', $targetCutoff, 'No approved forecasting model version configured.', 'BLOCKED:MISSING_APPROVED_MODEL');
            return;
        }

        $jobService->enqueueSystem('forecast_generation', $targetCutoff, (string)$approvedModel);
        $result['forecast_jobs_queued']++;
    }

    private function handleCandidateObservation(
        MlJobService $jobService,
        DateTime $now,
        string $targetCutoff,
        string $currentDate,
        string $currentTime,
        string $dailyTime,
        array &$result
    ): void {
        $nowStr = $now->format('Y-m-d H:i:s');
        $candidate = $this->getCandidateUnderObservation();
        if ($candidate === null) {
            return;
        }

        $latestSuccessfulCutoff = $this->getLatestSuccessfulObservationCutoff($candidate);

        $isScheduledDue = ($currentTime >= $dailyTime);
        $isDowntimeCatchup = false;

        if ($latestSuccessfulCutoff !== null) {
            if ($latestSuccessfulCutoff < $targetCutoff) {
                $daysGap = (int)round((strtotime($targetCutoff) - strtotime($latestSuccessfulCutoff)) / 86400);
                if ($daysGap >= 2) {
                    $isDowntimeCatchup = true;
                }
            }
        }

        if (!$isScheduledDue && !$isDowntimeCatchup) {
            return;
        }

        // Check existing job for (forecast_observation, $candidate, $targetCutoff)
        $existingJob = $this->findObservationJob($candidate, $targetCutoff, $currentDate);

        if ($existingJob) {
            $status = (string)($existingJob['status'] ?? '');
            if ($status === 'succeeded' || $status === 'queued') {
                return;
            }
            if ($status === 'running') {
                $leaseExpires = $existingJob['lease_expires_at'] ?? null;
                if ($leaseExpires && $leaseExpires > $nowStr) {
                    return;
                }
            }
            if ($status === 'failed') {
                if (!self::canAutomaticallyRetry($existingJob)) return;
                $jobService->retryJob((int)$existingJob['job_id']);
                return;
            }
        }

        // Enqueue candidate observation forecast (non-operational)
        $jobService->enqueueSystem('forecast_observation', $targetCutoff, $candidate);
        $result['observation_jobs_queued']++;
    }

    public function getCandidateUnderObservation(): ?string {
        $approvedModel = (string)($this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'")->fetchColumn() ?: '');

        // 1. Check explicit platform setting
        $obsCandidate = (string)($this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key IN ('ml_observation_candidate_version', 'ml_candidate_under_observation') ORDER BY setting_key DESC LIMIT 1")->fetchColumn() ?: '');

        if ($obsCandidate !== '') {
            // Check if review finished:
            // (a) Is it approved?
            if ($approvedModel !== '' && $obsCandidate === $approvedModel) {
                $this->setCandidateUnderObservation('');
                $obsCandidate = '';
            } elseif ($this->isCandidateRejected($obsCandidate)) {
                // (b) Is it rejected?
                $this->setCandidateUnderObservation('');
                $obsCandidate = '';
            } else {
                return $obsCandidate;
            }
        }

        // 2. If no candidate currently locked under observation, pick earliest completed Store candidate whose review is unfinished
        try {
            $hasModelVersionCol = false;
            try {
                $check = $this->pdo->query("SELECT model_version FROM model_training_runs LIMIT 0");
                $hasModelVersionCol = ($check !== false);
            } catch (\Throwable) {}

            if ($hasModelVersionCol) {
                $stmt = $this->pdo->query("
                    SELECT model_version, metrics_json FROM model_training_runs 
                    WHERE status = 'completed' 
                      AND (model_version IS NULL OR (model_version NOT LIKE '%demo%' AND model_version NOT LIKE '%kaggle%'))
                    ORDER BY training_run_id ASC
                ");
            } else {
                $stmt = $this->pdo->query("
                    SELECT NULL as model_version, metrics_json FROM model_training_runs 
                    WHERE status = 'completed'
                    ORDER BY training_run_id ASC
                ");
            }

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ver = (string)($row['model_version'] ?? '');
                if ($ver === '') {
                    $meta = json_decode((string)($row['metrics_json'] ?? ''), true);
                    $ver = (string)($meta['model_version'] ?? $meta['metadata']['model_version'] ?? '');
                }
                if ($ver === '' || str_contains($ver, 'demo') || str_contains($ver, 'kaggle')) {
                    continue;
                }
                if ($approvedModel !== '' && $ver === $approvedModel) {
                    continue;
                }
                if ($this->isCandidateRejected($ver)) {
                    continue;
                }
                // Lock this candidate into platform_settings so subsequent weekly retraining runs cannot replace it
                $this->setCandidateUnderObservation($ver);
                return $ver;
            }
        } catch (\Throwable) {}

        return null;
    }

    public function isCandidateRejected(string $version): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key IN (?, ?)");
            $stmt->execute(["ml_review_technical_{$version}", "ml_review_operational_{$version}"]);
            while ($val = $stmt->fetchColumn()) {
                $dec = json_decode((string)$val, true)['decision'] ?? null;
                if ($dec === 'rejected') {
                    return true;
                }
            }
        } catch (\Throwable) {}
        return false;
    }

    public function setCandidateUnderObservation(?string $version): void {
        try {
            $val = (string)($version ?? '');
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM platform_settings WHERE setting_key = 'ml_observation_candidate_version'");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() > 0) {
                $up = $this->pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_observation_candidate_version'");
                $up->execute([$val]);
            } else {
                $ins = $this->pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_observation_candidate_version', ?)");
                $ins->execute([$val]);
            }
        } catch (\Throwable) {}
    }

    public function findObservationJob(string $candidateVersion, string $cutoff, string $currentDate): ?array {
        if ($this->hasCutoffColumn()) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM ml_jobs 
                WHERE trigger_type = 'forecast_observation' 
                  AND (model_version = :version OR model_version IS NULL)
                  AND (evidence_cutoff = :cutoff OR (evidence_cutoff IS NULL AND DATE(queued_at) = :today))
                ORDER BY job_id DESC LIMIT 1
            ");
            $stmt->execute(['version' => $candidateVersion, 'cutoff' => $cutoff, 'today' => $currentDate]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT * FROM ml_jobs 
                WHERE trigger_type = 'forecast_observation' 
                  AND (model_version = :version OR model_version IS NULL)
                  AND DATE(queued_at) = :today
                ORDER BY job_id DESC LIMIT 1
            ");
            $stmt->execute(['version' => $candidateVersion, 'today' => $currentDate]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getLatestSuccessfulObservationCutoff(string $candidateVersion): ?string {
        try {
            $stmt = $this->pdo->prepare("
                SELECT forecast_origin FROM demand_forecasts 
                WHERE model_version = ?
                ORDER BY forecast_origin DESC LIMIT 1
            ");
            $stmt->execute([$candidateVersion]);
            $origin = $stmt->fetchColumn();
            if ($origin !== false && !empty($origin)) {
                return (string)$origin;
            }
            if ($this->hasCutoffColumn()) {
                $stmt2 = $this->pdo->prepare("
                    SELECT evidence_cutoff FROM ml_jobs 
                    WHERE trigger_type = 'forecast_observation' 
                      AND model_version = ? 
                      AND status = 'succeeded' 
                      AND evidence_cutoff IS NOT NULL 
                    ORDER BY evidence_cutoff DESC LIMIT 1
                ");
                $stmt2->execute([$candidateVersion]);
                $cutoff = $stmt2->fetchColumn();
                if ($cutoff !== false && !empty($cutoff)) {
                    return (string)$cutoff;
                }
            }
        } catch (\Throwable) {}
        return null;
    }

    private function handleModelRetraining(
        MlJobService $jobService,
        DateTime $now,
        string $targetCutoff,
        string $currentDate,
        string $currentTime,
        string $currentDayName,
        string $dailyTime,
        string $weeklyDay,
        string $weeklyTime,
        array &$result
    ): void {
        $latestTraining = $this->pdo->query("SELECT metrics_json FROM model_training_runs
            WHERE status = 'completed' ORDER BY training_run_id DESC LIMIT 1")->fetchColumn();
        
        $firstTrainingDue = $latestTraining === false && $currentTime >= $dailyTime;
        $weeklyTrainingDue = strcasecmp($currentDayName, $weeklyDay) === 0 && $currentTime >= $weeklyTime;
        
        // Missed weekly training catch-up after downtime (e.g. computer was off over weekend/weeks)
        $downtimeRetrainDue = false;
        if ($latestTraining !== false && !$weeklyTrainingDue) {
            $meta = json_decode((string)$latestTraining, true)['metadata'] ?? [];
            $lastEvidence = $meta['data_window_end'] ?? null;
            if ($lastEvidence !== null) {
                $daysSinceLastTraining = (int)round((strtotime($targetCutoff) - strtotime($lastEvidence)) / 86400);
                if ($daysSinceLastTraining >= 7) {
                    $downtimeRetrainDue = true;
                }
            }
        }

        if (!$firstTrainingDue && !$weeklyTrainingDue && !$downtimeRetrainDue) {
            return;
        }

        // Active or pending retraining blocks duplicates
        $trainingPending = (bool)$this->pdo->query("SELECT 1 FROM ml_jobs
            WHERE trigger_type = 'model_retraining' AND status IN ('queued', 'running') LIMIT 1")->fetchColumn();
        if ($trainingPending) {
            return;
        }

        // Check if retraining was already attempted/completed today or for this cutoff
        $existingRetrain = $this->findJob('model_retraining', $targetCutoff, $currentDate);
        if ($existingRetrain) {
            // Manual training that day prevents an automatic retry storm
            if (!empty($existingRetrain['requester_id'])) {
                return;
            }
            $status = (string)($existingRetrain['status'] ?? '');
            if ($status === 'succeeded' || $status === 'queued' || $status === 'running') {
                return;
            }
            if ($status === 'failed') {
                if (!self::canAutomaticallyRetry($existingRetrain)) return;
                // Retry eligible temporary failure on later tick
                $jobService->retryJob((int)$existingRetrain['job_id']);
                return;
            }
        }

        $isDemo = (bool)$this->pdo->query("SELECT 1 FROM platform_settings WHERE setting_key = 'demo_import_metadata'")->fetchColumn();
        $lastEvidence = json_decode((string)$latestTraining, true)['metadata']['data_window_end'] ?? null;
        $hasNewHistory = $lastEvidence === null || $lastEvidence < $targetCutoff;

        if (!$isDemo && $hasNewHistory) {
            $history = (new DemandHistoryService())->getTrainingHistory($this->pdo, $targetCutoff);
            $result['training_eligibility'] = $history['eligibility'];
        }

        // Demo history is frozen; only its existing weekly schedule or downtime catch-up applies.
        if (($isDemo && ($weeklyTrainingDue || $downtimeRetrainDue)) || (!$isDemo && $hasNewHistory && !empty($history['history']))) {
            $jobService->enqueueSystem('model_retraining', $targetCutoff);
            $result['retrain_jobs_queued']++;
        }
    }

    private function findJob(string $triggerType, string $cutoff, string $currentDate): ?array {
        if ($this->hasCutoffColumn()) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM ml_jobs 
                WHERE trigger_type = :trigger 
                  AND (evidence_cutoff = :cutoff OR (evidence_cutoff IS NULL AND DATE(queued_at) = :today))
                ORDER BY job_id DESC LIMIT 1
            ");
            $stmt->execute(['trigger' => $triggerType, 'cutoff' => $cutoff, 'today' => $currentDate]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT * FROM ml_jobs 
                WHERE trigger_type = :trigger 
                  AND DATE(queued_at) = :today
                ORDER BY job_id DESC LIMIT 1
            ");
            $stmt->execute(['trigger' => $triggerType, 'today' => $currentDate]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getLatestSuccessfulForecastCutoff(): ?string {
        try {
            // Check demand_forecasts first
            $origin = $this->pdo->query("
                SELECT forecast_origin FROM demand_forecasts 
                ORDER BY forecast_origin DESC LIMIT 1
            ")->fetchColumn();
            if ($origin !== false && !empty($origin)) {
                return (string)$origin;
            }
            // Check ml_jobs
            if ($this->hasCutoffColumn()) {
                $cutoff = $this->pdo->query("
                    SELECT evidence_cutoff FROM ml_jobs 
                    WHERE trigger_type = 'forecast_generation' AND status = 'succeeded' AND evidence_cutoff IS NOT NULL 
                    ORDER BY evidence_cutoff DESC LIMIT 1
                ")->fetchColumn();
                if ($cutoff !== false && !empty($cutoff)) {
                    return (string)$cutoff;
                }
            }
        } catch (\Throwable) {}
        return null;
    }

    private function recordBlockedJob(string $triggerType, string $cutoff, string $message, string $diagRef): void {
        try {
            if ($this->hasCutoffColumn()) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO ml_jobs (trigger_type, status, requester_id, evidence_cutoff, attempts, operator_message, diagnostic_reference, queued_at, completed_at)
                    VALUES (:trigger, 'failed', NULL, :cutoff, 1, :msg, :diag, NOW(), NOW())
                ");
                $stmt->execute(['trigger' => $triggerType, 'cutoff' => $cutoff, 'msg' => $message, 'diag' => $diagRef]);
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO ml_jobs (trigger_type, status, requester_id, attempts, operator_message, diagnostic_reference, queued_at, completed_at)
                    VALUES (:trigger, 'failed', NULL, 1, :msg, :diag, NOW(), NOW())
                ");
                $stmt->execute(['trigger' => $triggerType, 'msg' => $message, 'diag' => $diagRef]);
            }
        } catch (\Throwable) {}
    }

    public static function recordLocalDiagnostic(string $message, string $ref, ?string $storageDir = null): void {
        $logDir = $storageDir ?: (dirname(__DIR__, 2) . '/storage/logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/forecasting_diagnostics.json';
        $entry = [
            'timestamp' => (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
            'error' => $message,
            'diagnostic_reference' => $ref,
        ];
        $entries = [];
        if (file_exists($logFile)) {
            $content = @file_get_contents($logFile);
            $entries = json_decode((string)$content, true) ?: [];
        }
        $entries[] = $entry;
        if (count($entries) > 20) {
            $entries = array_slice($entries, -20);
        }
        @file_put_contents($logFile, json_encode($entries, JSON_PRETTY_PRINT));
    }

    private function preserveLocalDiagnostic(string $message, string $ref): void {
        self::recordLocalDiagnostic($message, $ref);
    }

    /**
     * Resolves the effective operational forecasting schedule.
     * Accepted policy is 01:00 daily generation and Sunday 01:00 retraining in Asia/Manila.
     * Corrects conflicting fallback/default (such as legacy 23:00 default) while
     * preserving explicitly selected operational schedules.
     */
    public function getEffectiveSchedule(): array {
        $dailyTime = '01:00';
        $weeklyDay = 'Sunday';
        $weeklyTime = '01:00';
        $timezone = 'Asia/Manila';
        $isExplicit = false;

        try {
            // 1. Check platform_settings first
            $stmt = $this->pdo->query("SELECT * FROM platform_settings WHERE setting_key IN ('schedule_daily_forecast_time', 'schedule_weekly_retrain_day', 'schedule_weekly_retrain_time')");
            $platformSettings = [];
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $platformSettings[$row['setting_key']] = $row;
                }
            }

            // 2. Check ml_settings fallback
            $mlSettings = [];
            try {
                $stmt2 = $this->pdo->query("SELECT * FROM ml_settings WHERE setting_key IN ('schedule_daily_forecast_time', 'schedule_weekly_retrain_day', 'schedule_weekly_retrain_time')");
                if ($stmt2) {
                    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                        $mlSettings[$row['setting_key']] = $row;
                    }
                }
            } catch (\Throwable) {}

            // Resolve daily forecast time
            $dailyRow = $platformSettings['schedule_daily_forecast_time'] ?? $mlSettings['schedule_daily_forecast_time'] ?? null;
            if ($dailyRow !== null && !empty($dailyRow['setting_value'])) {
                $val = trim((string)$dailyRow['setting_value']);
                $hasOperatorUpdate = !empty($dailyRow['updated_by']);

                // If value is 23:00 without explicit operator update, it is the legacy conflicting default
                if ($val === '23:00' && !$hasOperatorUpdate) {
                    $dailyTime = '01:00';
                    $isExplicit = false;
                } else {
                    $dailyTime = $val;
                    $isExplicit = true;
                }
            }

            // Resolve weekly retrain day
            $dayRow = $platformSettings['schedule_weekly_retrain_day'] ?? $mlSettings['schedule_weekly_retrain_day'] ?? null;
            if ($dayRow !== null && !empty($dayRow['setting_value'])) {
                $weeklyDay = ucfirst(strtolower(trim((string)$dayRow['setting_value'])));
                if (!empty($dayRow['updated_by']) || $weeklyDay !== 'Sunday') {
                    $isExplicit = true;
                }
            }

            // Resolve weekly retrain time
            $timeRow = $platformSettings['schedule_weekly_retrain_time'] ?? $mlSettings['schedule_weekly_retrain_time'] ?? null;
            if ($timeRow !== null && !empty($timeRow['setting_value'])) {
                $val = trim((string)$timeRow['setting_value']);
                if (!empty($timeRow['updated_by']) || $val !== '01:00') {
                    $weeklyTime = $val;
                    $isExplicit = true;
                }
            }
        } catch (\Throwable) {}

        return [
            'daily_forecast_time' => $dailyTime,
            'weekly_retrain_day' => $weeklyDay,
            'weekly_retrain_time' => $weeklyTime,
            'timezone' => $timezone,
            'is_explicit' => $isExplicit,
            'source' => $isExplicit ? 'explicit_operational' : 'default_policy',
        ];
    }

    public function recordEffectiveSchedule(?array $schedule = null): array {
        $effective = $schedule ?? $this->getEffectiveSchedule();
        $nowStr = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $record = array_merge($effective, ['recorded_at' => $nowStr]);

        try {
            $json = json_encode($record, JSON_UNESCAPED_SLASHES);
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM platform_settings WHERE setting_key = 'ml_effective_schedule'");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() > 0) {
                $up = $this->pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'ml_effective_schedule'");
                $up->execute([$json]);
            } else {
                $ins = $this->pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('ml_effective_schedule', ?)");
                $ins->execute([$json]);
            }
        } catch (\Throwable) {}

        return $effective;
    }
}
