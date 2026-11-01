<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use App\Audit\AuditRecordCategory;

class MlJobService
{
    private PDO $pdo;
    private ?bool $hasCutoffColumn = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function hasCutoffColumn(): bool
    {
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

    public function enqueue(
        string $triggerType,
        int $requesterId,
        string $actorRole,
        ?string $ipAddress = null,
        ?string $cutoff = null,
        ?string $modelVersion = null
    ): int {
        // Enforce authorization rules:
        // Forecast Generation for Administrator/Super Administrator
        // Model Retraining for Super Administrator
        if ($triggerType === 'forecast_generation') {
            if (!in_array($actorRole, ['admin', 'super_admin'], true)) {
                throw new RuntimeException('Unauthorized: Only Administrator or Super Administrator can enqueue forecast generation.');
            }
        } elseif ($triggerType === 'model_retraining') {
            if ($actorRole !== 'super_admin') {
                throw new RuntimeException('Unauthorized: Only Super Administrator can enqueue model retraining.');
            }
        } else {
            throw new RuntimeException('Unsupported job trigger type.');
        }

        if ($this->hasCutoffColumn()) {
            $stmt = $this->pdo->prepare("
                INSERT INTO ml_jobs (trigger_type, status, requester_id, evidence_cutoff, model_version, queued_at)
                VALUES (:trigger, 'queued', :requester, :cutoff, :model_version, NOW())
            ");
            $stmt->execute([
                'trigger' => $triggerType,
                'requester' => $requesterId,
                'cutoff' => $cutoff,
                'model_version' => $modelVersion,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO ml_jobs (trigger_type, status, requester_id, queued_at)
                VALUES (:trigger, 'queued', :requester, NOW())
            ");
            $stmt->execute([
                'trigger' => $triggerType,
                'requester' => $requesterId,
            ]);
        }

        $jobId = (int)$this->pdo->lastInsertId();

        // Record Protected Audit Record
        $action = 'Enqueued ML Job';
        $module = 'Demand Forecasting';
        $category = AuditRecordCategory::classify($module, $action);
        
        $logStmt = $this->pdo->prepare("
            INSERT INTO activity_log (user_id, action, category, module, record_id, new_value, ip_address, created_at)
            VALUES (:user_id, :action, :category, :module, :record_id, :new_value, :ip, NOW())
        ");
        $logStmt->execute([
            'user_id' => $requesterId,
            'action' => $action,
            'category' => $category,
            'module' => $module,
            'record_id' => $jobId,
            'new_value' => json_encode(['trigger_type' => $triggerType, 'status' => 'queued', 'evidence_cutoff' => $cutoff]),
            'ip' => $ipAddress ?? '127.0.0.1'
        ]);

        return $jobId;
    }

    public function enqueueSystem(
        string $triggerType,
        ?string $cutoff = null,
        ?string $modelVersion = null
    ): int {
        if ($this->hasCutoffColumn()) {
            $stmt = $this->pdo->prepare("
                INSERT INTO ml_jobs (trigger_type, status, requester_id, evidence_cutoff, model_version, queued_at)
                VALUES (:trigger, 'queued', NULL, :cutoff, :model_version, NOW())
            ");
            $stmt->execute([
                'trigger' => $triggerType,
                'cutoff' => $cutoff,
                'model_version' => $modelVersion,
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO ml_jobs (trigger_type, status, requester_id, queued_at)
                VALUES (:trigger, 'queued', NULL, NOW())
            ");
            $stmt->execute([
                'trigger' => $triggerType,
            ]);
        }

        $jobId = (int)$this->pdo->lastInsertId();

        // Record Protected Audit Record for system scheduler
        $action = 'Enqueued Scheduled ML Job';
        $module = 'Demand Forecasting';
        $category = AuditRecordCategory::classify($module, $action);
        
        $logStmt = $this->pdo->prepare("
            INSERT INTO activity_log (user_id, action, category, module, record_id, new_value, ip_address, created_at)
            VALUES (NULL, :action, :category, :module, :record_id, :new_value, '127.0.0.1', NOW())
        ");
        $logStmt->execute([
            'action' => $action,
            'category' => $category,
            'module' => $module,
            'record_id' => $jobId,
            'new_value' => json_encode([
                'trigger_type' => $triggerType,
                'status' => 'queued',
                'origin' => 'scheduler',
                'evidence_cutoff' => $cutoff
            ]),
        ]);

        return $jobId;
    }

    /**
     * Retries an existing failed job without creating a duplicate logical job.
     * Enforces the 3-attempt ceiling.
     */
    public function retryJob(int $jobId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT job_id, status, attempts, diagnostic_reference 
            FROM ml_jobs 
            WHERE job_id = :id
        ");
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            return false;
        }

        // Cannot retry if already succeeded or running under valid lease
        if ($job['status'] === 'succeeded' || $job['status'] === 'queued') {
            return false;
        }

        // Max 3 total attempts
        if ((int)$job['attempts'] >= 3) {
            return false;
        }

        // Do not retry blocked conditions automatically
        if (str_starts_with((string)($job['diagnostic_reference'] ?? ''), 'BLOCKED:')) {
            return false;
        }

        $update = $this->pdo->prepare("
            UPDATE ml_jobs 
            SET status = 'queued', lease_expires_at = NULL 
            WHERE job_id = :id AND attempts < 3
        ");
        $update->execute(['id' => $jobId]);
        return $update->rowCount() > 0;
    }

    public function getJobStatus(int $jobId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM ml_jobs WHERE job_id = :id");
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException("Job not found.");
        }

        // Verify complete committed results if succeeded
        $isComplete = true;
        $status = (string)($row['status'] ?? 'queued');
        $trigger = (string)($row['trigger_type'] ?? '');
        if ($status === 'succeeded') {
            if ($trigger === 'forecast_generation') {
                try {
                    $cnt = (int)$this->pdo->query("SELECT COUNT(*) FROM demand_forecasts WHERE job_id = " . (int)$jobId)->fetchColumn();
                    if ($cnt === 0) {
                        $isComplete = false;
                        $status = 'failed';
                        $row['status'] = 'failed';
                        $row['operator_message'] = 'Job failed: results were not committed.';
                        $row['diagnostic_reference'] = 'UNCOMMITTED_RESULTS';
                    }
                } catch (\Throwable) {}
            } elseif ($trigger === 'model_retraining') {
                try {
                    $cnt = (int)$this->pdo->query("SELECT COUNT(*) FROM model_training_runs WHERE status = 'completed'")->fetchColumn();
                    if ($cnt === 0) {
                        $isComplete = false;
                        $status = 'failed';
                        $row['status'] = 'failed';
                        $row['operator_message'] = 'Job failed: training run was not committed.';
                        $row['diagnostic_reference'] = 'UNCOMMITTED_RESULTS';
                    }
                } catch (\Throwable) {}
            }
        }

        $row['status'] = $status;
        $row['cutoff'] = $row['evidence_cutoff'] ?? null;
        $row['model_version'] = $row['model_version'] ?? null;
        $row['attempts'] = (int)($row['attempts'] ?? 0);
        $row['safe_explanation'] = !empty($row['operator_message']) ? (string)$row['operator_message'] : null;
        $row['committed_results_complete'] = $isComplete;

        return $row;
    }
}
