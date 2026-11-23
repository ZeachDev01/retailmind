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
        // Model Retraining for Administrator/Super Administrator
        if ($triggerType === 'forecast_generation') {
            if (!in_array($actorRole, ['admin', 'super_admin'], true)) {
                throw new RuntimeException('Unauthorized: Only Administrator or Super Administrator can enqueue forecast generation.');
            }
        } elseif ($triggerType === 'model_retraining') {
            if (!in_array($actorRole, ['admin', 'super_admin'], true)) {
                throw new RuntimeException('Unauthorized: Only Administrator or Super Administrator can enqueue model retraining.');
            }
        } else {
            throw new RuntimeException('Unsupported job trigger type.');
        }

        return $this->enqueueJob($triggerType, $requesterId, $ipAddress, $cutoff, $modelVersion);
    }

    public function enqueueSystem(
        string $triggerType,
        ?string $cutoff = null,
        ?string $modelVersion = null
    ): int {
        return $this->enqueueJob($triggerType, null, null, $cutoff, $modelVersion);
    }

    private function withQueueTransaction(string $triggerType, callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        else $this->pdo->exec('SAVEPOINT ml_queue_request');
        try {
            if ($triggerType === 'model_retraining') {
                // A durable row lock also serializes callers already inside a transaction.
                if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $this->pdo->exec("INSERT OR IGNORE INTO platform_settings (setting_key,setting_value) VALUES ('ml_training_request_lock','1')");
                    $this->pdo->exec("UPDATE platform_settings SET setting_value=setting_value WHERE setting_key='ml_training_request_lock'");
                } else {
                    $this->pdo->exec("INSERT INTO platform_settings (setting_key,setting_value) VALUES ('ml_training_request_lock','1')
                        ON DUPLICATE KEY UPDATE setting_value=setting_value");
                }
                $locking = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $active = $this->pdo->query("SELECT job_id FROM ml_jobs WHERE trigger_type='model_retraining'
                    AND status IN ('queued','running') LIMIT 1" . $locking)->fetchColumn();
                if ($active !== false) throw new RuntimeException('A training job is already queued or running.');
            }
            $result = $operation();
            if ($ownsTransaction) $this->pdo->commit();
            else $this->pdo->exec('RELEASE SAVEPOINT ml_queue_request');
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ml_queue_request');
                $this->pdo->exec('RELEASE SAVEPOINT ml_queue_request');
            }
            throw $exception;
        }
    }

    private function enqueueJob(string $triggerType, ?int $requesterId, ?string $ipAddress, ?string $cutoff, ?string $modelVersion): int
    {
        return $this->withQueueTransaction($triggerType, function () use ($triggerType, $requesterId, $ipAddress, $cutoff, $modelVersion): int {
            if ($triggerType === 'model_retraining' && $requesterId !== null) {
                $cutoff ??= (new \DateTimeImmutable('yesterday', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
                $demoMetadata = $this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'")->fetchColumn();
                $localDemo = false;
                if ($demoMetadata !== false && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    $database = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
                    $localDemo = preg_match('/^retailmind_demo_[a-z0-9_]+$/D', $database)
                        && in_array(strtolower((string)env('APP_ENV')), ['development', 'test'], true)
                        && in_array((string)env('DB_HOST', '127.0.0.1'), ['localhost', '127.0.0.1', '::1'], true);
                    if (!$localDemo) throw new RuntimeException('Train imported Demo history in the isolated local Demo environment.');
                }
                if (!$localDemo && empty((new \App\Forecasting\DemandHistoryService())->getTrainingHistory($this->pdo, $cutoff)['history'])) {
                    throw new RuntimeException('No Product is ready for training. Review Data Readiness; Products need 140 accumulated usable days and supported recent inputs and chronological holdouts.');
                }
            }
            $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
            $hasCutoff = $this->hasCutoffColumn();
            $columns = $hasCutoff ? ', evidence_cutoff, model_version' : '';
            $values = $hasCutoff ? ', :cutoff, :model_version' : '';
            $parameters = ['trigger' => $triggerType, 'requester' => $requesterId];
            if ($hasCutoff) $parameters += ['cutoff' => $cutoff, 'model_version' => $modelVersion];
            $stmt = $this->pdo->prepare("INSERT INTO ml_jobs (trigger_type,status,requester_id,queued_at{$columns})
                VALUES (:trigger,'queued',:requester,{$now}{$values})");
            $stmt->execute($parameters);
            $jobId = (int)$this->pdo->lastInsertId();
            $action = $requesterId === null ? 'Enqueued Scheduled ML Job' : 'Enqueued ML Job';
            $module = 'Demand Forecasting';
            $log = $this->pdo->prepare("INSERT INTO activity_log (user_id,action,category,module,record_id,new_value,ip_address,created_at)
                VALUES (:user_id,:action,:category,:module,:record_id,:new_value,:ip,{$now})");
            $log->execute([
                'user_id' => $requesterId, 'action' => $action,
                'category' => AuditRecordCategory::classify($module, $action), 'module' => $module,
                'record_id' => $jobId, 'ip' => $ipAddress ?? '127.0.0.1',
                'new_value' => json_encode(['trigger_type' => $triggerType, 'status' => 'queued',
                    'evidence_cutoff' => $cutoff, 'origin' => $requesterId === null ? 'scheduler' : 'manual']),
            ]);
            return $jobId;
        });
    }

    /**
     * Retries an existing failed job without creating a duplicate logical job.
     * Enforces the 3-attempt ceiling.
     */
    public function retryJob(int $jobId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT job_id, trigger_type, status, attempts, diagnostic_reference
            FROM ml_jobs 
            WHERE job_id = :id
        ");
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            return false;
        }

        // Cannot retry if already succeeded or running under valid lease
        if ($job['status'] !== 'failed') {
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

        return $this->withQueueTransaction((string)$job['trigger_type'], function () use ($jobId): bool {
        $update = $this->pdo->prepare("
            UPDATE ml_jobs 
            SET status = 'queued', lease_expires_at = NULL 
            WHERE job_id = :id AND status = 'failed' AND attempts < 3
        ");
        $update->execute(['id' => $jobId]);
        return $update->rowCount() > 0;
        });
    }

    public function getRecentTrainingJobs(int $limit = 12): array
    {
        $limit = max(1, min(100, $limit));
        $ids = $this->pdo->query("SELECT job_id FROM ml_jobs WHERE trigger_type='model_retraining' ORDER BY job_id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_COLUMN);
        return array_map(fn($id): array => $this->getJobStatus((int)$id), $ids);
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
                    $cnt = 0;
                    $runs = $this->pdo->query("SELECT training_run_id,model_version,metrics_json FROM model_training_runs WHERE status='completed'");
                    foreach ($runs->fetchAll(PDO::FETCH_ASSOC) as $run) {
                        $evidence = json_decode((string)($run['metrics_json'] ?? ''), true);
                        if ((int)($evidence['metadata']['job_id'] ?? 0) !== $jobId) continue;
                        $cnt++;
                        $row['model_version'] = $run['model_version'];
                        $row['training_run_id'] = (int)$run['training_run_id'];
                        $row['trained_product_ids'] = array_map('intval', $evidence['metadata']['products'] ?? []);
                        $row['candidate_qualification'] = $evidence['metadata']['outcome'] ?? $evidence['outcome'] ?? 'incomplete';
                        break;
                    }
                    if ($cnt === 0) {
                        $isComplete = false;
                        $status = 'failed';
                        $row['status'] = 'failed';
                        $row['operator_message'] = 'Job failed: training run was not committed.';
                        $row['diagnostic_reference'] = 'UNCOMMITTED_RESULTS';
                    }
                } catch (\Throwable) {
                    $isComplete = false;
                    $status = 'failed';
                    $row['diagnostic_reference'] = 'UNCOMMITTED_RESULTS';
                }
            }
        }

        $row['status'] = $status;
        $row['cutoff'] = $row['evidence_cutoff'] ?? null;
        $row['model_version'] = $row['model_version'] ?? null;
        $row['attempts'] = (int)($row['attempts'] ?? 0);
        $row['safe_explanation'] = match ($status) {
            'failed' => 'Job did not complete. Review the diagnostic reference with the Administrator.',
            'succeeded' => 'Job completed. Candidate qualification and approval are separate.',
            'running' => 'The worker is processing this job.',
            default => 'Waiting for the background worker.',
        };
        $reference = (string)($row['diagnostic_reference'] ?? '');
        $row['diagnostic_reference'] = $reference !== '' && preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $reference)
            ? $reference : ($status === 'failed' ? 'JOB-' . $jobId : null);
        $row['committed_results_complete'] = $isComplete;

        return $row;
    }
}
