<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use App\Audit\AuditRecordCategory;

class MlJobService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function enqueue(string $triggerType, int $requesterId, string $actorRole, ?string $ipAddress = null): int
    {
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

        $stmt = $this->pdo->prepare("
            INSERT INTO ml_jobs (trigger_type, status, requester_id, queued_at)
            VALUES (:trigger, 'queued', :requester, NOW())
        ");
        $stmt->execute([
            'trigger' => $triggerType,
            'requester' => $requesterId,
        ]);

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
            'new_value' => json_encode(['trigger_type' => $triggerType, 'status' => 'queued']),
            'ip' => $ipAddress ?? '127.0.0.1'
        ]);

        return $jobId;
    }

    public function getJobStatus(int $jobId): array
    {
        $stmt = $this->pdo->prepare("SELECT status, operator_message, queued_at, completed_at FROM ml_jobs WHERE job_id = :id");
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException("Job not found.");
        }

        return $row;
    }
}
