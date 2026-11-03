<?php

namespace App\Attention;

use PDO;
use Throwable;

final class DatabaseAttentionSignalSource
{
    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    public function platform(array $healthChecks = []): array
    {
        $dayAgo = $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s');
        try {
            $failedLogins = (new FailedSignInReviewService($this->pdo, $this->clock))->pendingCount();
        } catch (Throwable $exception) {
            error_log('Attention signal query skipped: ' . $exception->getMessage());
            $failedLogins = 0;
        }
        $lockedAccounts = $this->scalar(
            "SELECT COUNT(*) FROM users WHERE status = 'active' AND locked_until > ?",
            [$this->now()]
        );
        $latestBackup = $this->row(
            "SELECT created_at FROM backup_history WHERE status = 'completed' AND backup_type <> 'restore' ORDER BY created_at DESC LIMIT 1"
        );
        $backupAge = $this->ageDays($latestBackup['created_at'] ?? null);
        $recoveryFailures = $this->scalar(
            "SELECT COUNT(*) FROM backup_history WHERE status = 'failed' AND created_at >= ?",
            [$dayAgo]
        );
        $latestModel = $this->row(
            "SELECT completed_at FROM model_training_runs WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1"
        );
        $modelAge = $this->ageDays($latestModel['completed_at'] ?? null);
        $platformChanges = $this->scalar(
            "SELECT COUNT(*) FROM activity_log WHERE category = 'platform_setting' AND created_at >= ?",
            [$dayAgo]
        );
        $recoveryAccount = $this->scalar(
            "SELECT COUNT(*) FROM recovery_accounts ra JOIN users u ON u.user_id = ra.user_id WHERE u.status = 'active' AND ra.sealed_at IS NULL"
        );

        $serviceFailures = 0;
        $mlFailures = 0;
        foreach ($healthChecks as $check) {
            if (($check['status'] ?? 'healthy') === 'healthy') {
                continue;
            }
            if (($check['category'] ?? '') === 'Forecasting') {
                $mlFailures++;
            } else {
                $serviceFailures++;
            }
        }

        return [
            'failed_login_count' => $this->signal($failedLogins, (int)$failedLogins),
            'locked_account_count' => $this->signal($lockedAccounts, (int)$lockedAccounts),
            'backup_age_days' => $this->signal($backupAge),
            'recovery_failure_count' => $this->signal($recoveryFailures, (int)$recoveryFailures),
            'service_unhealthy_count' => $this->signal($serviceFailures, $serviceFailures),
            'ml_model_age_days' => $this->signal(max($modelAge, $mlFailures > 0 ? 9999 : 0)),
            'platform_setting_change_count' => $this->signal($platformChanges, (int)$platformChanges),
            'recovery_account_active_count' => $this->signal($recoveryAccount, (int)$recoveryAccount),
        ];
    }

    public function store(): array
    {
        $now = $this->clock->now();
        $cash = $this->row(
            "SELECT COALESCE(MAX(ABS(cash_variance)), 0) AS value, COUNT(*) AS item_count
             FROM cashier_shifts WHERE status = 'closed' AND reviewed_at IS NULL AND cash_variance IS NOT NULL"
        );
        $inventory = $this->row(
            "SELECT MIN(ia.reported_at) AS oldest_at,
                    COUNT(*) AS item_count,
                    COALESCE(MAX(ABS(ia.adjustment_qty) * p.cost_price), 0) AS risk_value
             FROM inventory_adjustments ia JOIN products p ON p.product_id = ia.product_id
             WHERE ia.status = 'pending'"
        );
        $stockIssues = $this->rows("SELECT ia.adjustment_id, ia.reported_at,
            ABS(ia.adjustment_qty) * p.cost_price AS risk_value
            FROM inventory_adjustments ia JOIN products p ON p.product_id = ia.product_id WHERE ia.status = 'pending'");
        foreach ($stockIssues as &$issue) $issue['age_hours'] = $this->ageHours($issue['reported_at']);
        unset($issue);
        $fiscal = $this->row(
            "SELECT MIN(end_date) AS next_end FROM fiscal_periods WHERE status = 'open' AND end_date >= ?",
            [$now->format('Y-m-d')]
        );
        $fiscalOverdue = $this->scalar(
            "SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open' AND end_date < ?",
            [$now->format('Y-m-d')]
        );
        $forecast = $this->forecastingSignals();
        $inventoryHours = $this->ageHours($inventory['oldest_at'] ?? null);
        $fiscalDays = isset($fiscal['next_end'])
            ? max(0, (int)$now->setTime(0, 0)->diff(new \DateTimeImmutable((string)$fiscal['next_end']))->format('%a'))
            : 9999;

        return [
            'cash_variance_amount' => $this->signal((float)($cash['value'] ?? 0)),
            // Stock Issues remain Inventory Manager decisions; administrators inspect them read-only.
            'stock_issues' => $stockIssues,
            'inventory_escalated_count' => $this->signal((int)($inventory['item_count'] ?? 0), (int)($inventory['item_count'] ?? 0)),
            'oldest_inventory_risk_hours' => $this->signal($inventoryHours),
            'inventory_risk_value' => $this->signal((float)($inventory['risk_value'] ?? 0)),
            'oldest_unresolved_inventory_hours' => $this->signal($inventoryHours),
            'fiscal_period_days_remaining' => $this->signal($fiscalDays),
            'fiscal_period_overdue_count' => $this->signal($fiscalOverdue, (int)$fiscalOverdue),
            'forecasting_candidate_review' => $forecast['forecasting_candidate_review'],
            'forecasting_failure' => $forecast['forecasting_failure'],
        ];
    }

    private function forecastingSignals(): array
    {
        $candidateValue = 0;
        $candidateExplanation = null;
        $candidateDetectedAt = null;

        $failureValue = 0;
        $failureExplanation = null;
        $failureDetectedAt = null;

        try {
            $candidateRun = $this->row(
                "SELECT model_version, completed_at FROM model_training_runs WHERE status = 'completed' ORDER BY training_run_id DESC LIMIT 1"
            );
            $candidateVersion = $candidateRun['model_version'] ?? null;
            if (!$candidateVersion) {
                $candidateVersion = $this->scalar(
                    "SELECT model_version FROM ml_model_evaluations ORDER BY evaluation_id DESC LIMIT 1"
                );
            }
            if ($candidateVersion) {
                $lower = strtolower((string)$candidateVersion);
                $isDemo = str_contains($lower, 'demo') || str_contains($lower, 'kaggle');
                $appRow = $this->row("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'");
                $approved = (string)($appRow['setting_value'] ?? '');
                $opRow = $this->row("SELECT setting_value FROM platform_settings WHERE setting_key = ?", ["ml_review_operational_{$candidateVersion}"]);
                $opReviewRaw = $opRow['setting_value'] ?? null;
                $opAccepted = false;
                if ($opReviewRaw) {
                    $opData = json_decode((string)$opReviewRaw, true);
                    if (($opData['decision'] ?? '') === 'accepted') {
                        $opAccepted = true;
                    }
                }
                if (!$isDemo && $approved !== (string)$candidateVersion && !$opAccepted) {
                    $evalService = new \App\Forecasting\CandidateEvaluationService($this->pdo);
                    $quality = $evalService->evaluateCandidateQuality((string)$candidateVersion);
                    if (!empty($quality['passed'])) {
                        $candidateValue = 1;
                        $candidateExplanation = "Candidate model version {$candidateVersion} has passed quality gates and is awaiting local Administrator review.";
                        $candidateDetectedAt = $candidateRun['completed_at'] ?? $this->now();
                    }
                }
            }
        } catch (\Throwable) {}

        try {
            $latestJob = $this->row(
                "SELECT job_id, trigger_type, status, attempts, operator_message, queued_at, completed_at FROM ml_jobs WHERE trigger_type IN ('forecast_generation', 'model_retraining') ORDER BY job_id DESC LIMIT 1"
            );
            if ($latestJob && ($latestJob['status'] ?? '') === 'failed') {
                $failureValue = 1;
                $msg = !empty($latestJob['operator_message'])
                    ? (string)$latestJob['operator_message']
                    : 'Demand forecast generation failed. Inspect forecast exceptions or generate a new forecast.';
                $failureExplanation = $msg;
                $failureDetectedAt = $latestJob['completed_at'] ?? $latestJob['queued_at'] ?? $this->now();
            }

            // Check for persistent background inactivity (queued jobs older than 2 hours without worker pickup)
            if ($failureValue === 0) {
                $twoHoursAgo = (clone $this->clock->now())->modify('-2 hours')->format('Y-m-d H:i:s');
                $stalledJob = $this->row(
                    "SELECT job_id, trigger_type, queued_at FROM ml_jobs WHERE status = 'queued' AND queued_at <= ? ORDER BY job_id DESC LIMIT 1",
                    [$twoHoursAgo]
                );
                if ($stalledJob) {
                    $failureValue = 1;
                    $failureExplanation = 'Forecasting background worker is inactive or stalled. Scheduled jobs are not being processed.';
                    $failureDetectedAt = $stalledJob['queued_at'] ?? $this->now();
                }
            }

            // Check for preserved local diagnostics during database outage
            $logFile = dirname(__DIR__, 2) . '/storage/logs/forecasting_diagnostics.json';
            if (file_exists($logFile) && $failureValue === 0) {
                $content = @file_get_contents($logFile);
                $diagEntries = json_decode((string)$content, true) ?: [];
                if (!empty($diagEntries)) {
                    $lastDiag = end($diagEntries);
                    $diagTime = $lastDiag['timestamp'] ?? '';
                    $latestSuccessRow = $this->row("SELECT completed_at FROM ml_jobs WHERE status = 'succeeded' ORDER BY job_id DESC LIMIT 1");
                    $latestSuccessTime = $latestSuccessRow['completed_at'] ?? '';
                    if ($diagTime > $latestSuccessTime) {
                        $failureValue = 1;
                        $failureExplanation = 'Forecasting outage recorded: ' . ($lastDiag['error'] ?? 'Local database outage');
                        $failureDetectedAt = $diagTime;
                    }
                }
            }
        } catch (\Throwable) {}

        $candSignal = $this->signal($candidateValue, $candidateValue);
        if ($candidateExplanation !== null) {
            $candSignal['explanation'] = $candidateExplanation;
        }
        if ($candidateDetectedAt !== null) {
            $candSignal['detected_at'] = $candidateDetectedAt;
        }

        $failSignal = $this->signal($failureValue, $failureValue);
        if ($failureExplanation !== null) {
            $failSignal['explanation'] = $failureExplanation;
        }
        if ($failureDetectedAt !== null) {
            $failSignal['detected_at'] = $failureDetectedAt;
        }

        return [
            'forecasting_candidate_review' => $candSignal,
            'forecasting_failure' => $failSignal,
        ];
    }

    private function signal(float|int $value, ?int $count = null): array
    {
        $signal = [
            'value' => $value,
            'detected_at' => $this->now(),
        ];
        if ($count !== null) {
            $signal['count'] = max(0, $count);
        }
        return $signal;
    }

    private function scalar(string $sql, array $parameters = []): float
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            return (float)$statement->fetchColumn();
        } catch (Throwable $exception) {
            error_log('Attention signal query skipped: ' . $exception->getMessage());
            return 0.0;
        }
    }

    private function row(string $sql, array $parameters = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $exception) {
            error_log('Attention signal query skipped: ' . $exception->getMessage());
            return [];
        }
    }

    private function rows(string $sql): array
    {
        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            error_log('Attention signal query skipped: ' . $exception->getMessage());
            return [];
        }
    }

    private function ageDays(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 9999;
        }
        try {
            return max(0, (int)(new \DateTimeImmutable($value))->diff($this->clock->now())->format('%a'));
        } catch (Throwable) {
            return 9999;
        }
    }

    private function ageHours(mixed $value): float
    {
        if (!is_string($value) || $value === '') {
            return 0.0;
        }
        try {
            $seconds = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($value))->getTimestamp();
            return max(0.0, $seconds / 3600);
        } catch (Throwable) {
            return 0.0;
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
