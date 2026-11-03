<?php
declare(strict_types=1);

namespace App\Forecasting;

require_once __DIR__ . '/StoreModelGovernanceService.php';
require_once __DIR__ . '/CandidateEvaluationService.php';
require_once __DIR__ . '/ForecastingSchedulerService.php';

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

    public function getLatestJob(): ?array
    {
        try {
            $stmt = $this->pdo->query("
                SELECT *
                FROM ml_jobs
                WHERE trigger_type IN ('forecast_generation', 'forecast_observation')
                ORDER BY job_id DESC
                LIMIT 1
            ");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!$row) {
                return null;
            }
            $row['job_id'] = (int)$row['job_id'];
            if (isset($row['attempts'])) {
                $row['attempts'] = (int)$row['attempts'];
            }
            return $row;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function getLatestUsableJobId(?string $asOfDate = null): ?int
    {
        $today = new \DateTimeImmutable($asOfDate ?? 'today', new \DateTimeZone('Asia/Manila'));
        $acceptedOrigin = $today->modify('-1 day')->format('Y-m-d');

        $approvedVersion = '';
        $isObservation = false;
        $isSuspended = false;
        try {
            $evalService = new CandidateEvaluationService($this->pdo);
            $isObservation = $evalService->isObservationMode();
            $isSuspended = $evalService->isRecommendationsSuspended();
        } catch (Throwable $e) {}

        if ($isObservation || $isSuspended) {
            return null;
        }

        try {
            $approvedVersion = (string)($this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'")->fetchColumn() ?: '');
        } catch (Throwable $e) {}

        if ($approvedVersion === '') {
            return null;
        }

        $isDemoDb = false;
        try {
            $isDemoDb = (bool)($this->pdo->query("SELECT 1 FROM platform_settings WHERE setting_key = 'demo_import_metadata'")->fetchColumn() ?: false);
        } catch (Throwable $e) {}

        try {
            $stmt = $this->pdo->query("
                SELECT job_id, completed_at
                FROM ml_jobs
                WHERE trigger_type = 'forecast_generation' AND status = 'succeeded'
                ORDER BY completed_at DESC, job_id DESC
                LIMIT 10
            ");
            $jobs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            return null;
        }

        foreach ($jobs as $jobRow) {
            $jid = (int)$jobRow['job_id'];
            $meta = $this->getJobMetadata($jid);
            if (!$meta) {
                $stmt = $this->pdo->prepare("SELECT model_version FROM demand_forecasts WHERE job_id = ? LIMIT 1");
                $stmt->execute([$jid]);
                $mv = (string)$stmt->fetchColumn();
                if ($mv !== $approvedVersion) {
                    continue;
                }
            } else {
                if ($meta['model_version'] !== $approvedVersion) {
                    continue;
                }
                if (!$isDemoDb && !empty($meta['is_demo'])) {
                    continue;
                }
                if ($isDemoDb && empty($meta['is_demo']) && ($meta['dataset_kind'] ?? null) === 'store') {
                    continue;
                }
                if (!empty($meta['feature_version']) && $meta['feature_version'] !== '2.0.0') {
                    continue;
                }
            }

            foreach ((new ReplenishmentQueueService($this->pdo))->getQueue($jid, $asOfDate) as $product) {
                if (!empty($product['complete_forecast'])
                    && $product['forecast_origin'] === $acceptedOrigin
                    && $product['model_version'] === $approvedVersion
                    && in_array($product['readiness_level'], ['Low', 'Medium', 'High'], true)) {
                    return $jid;
                }
            }
        }

        return null;
    }

    public function getCoverage(
        ?int $jobId = null,
        ?string $asOfDate = null,
        ?string $search = null,
        ?string $reasonFilter = null,
        ?string $actorRole = 'admin'
    ): array {
        $today = new \DateTimeImmutable($asOfDate ?? 'today', new \DateTimeZone('Asia/Manila'));
        $acceptedOrigin = $today->modify('-1 day')->format('Y-m-d');

        $latestJob = $this->getLatestJob();
        $latestAttemptStatus = $latestJob ? (string)$latestJob['status'] : 'none';
        $isUpdating = in_array($latestAttemptStatus, ['queued', 'running', 'processing'], true);

        $usableJobId = $this->getLatestUsableJobId($asOfDate);
        $effectiveJobId = $jobId ?? $usableJobId ?? $this->getLatestSuccessfulJobId();

        $queueService = new ReplenishmentQueueService($this->pdo);
        $products = $queueService->getRecommendations($effectiveJobId, $asOfDate);

        $scopeCount = count($products);
        $forecastBackedCount = 0;
        $stockRuleCount = 0;

        foreach ($products as $p) {
            if (!empty($p['is_forecast_backed'])) {
                $forecastBackedCount++;
            } else {
                $stockRuleCount++;
            }
        }

        $isGuidanceUsable = ($forecastBackedCount > 0);

        $approvedVersion = '';
        $isObservation = false;
        $isSuspended = false;
        try {
            $evalService = new CandidateEvaluationService($this->pdo);
            $isObservation = $evalService->isObservationMode();
            $isSuspended = $evalService->isRecommendationsSuspended();
            $approvedVersion = (string)($this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'")->fetchColumn() ?: '');
        } catch (Throwable $e) {}

        if ($scopeCount === 0) {
            $storeStatus = 'empty';
        } elseif ($isObservation) {
            $storeStatus = 'observation_mode';
        } elseif ($isSuspended) {
            $storeStatus = 'suspended';
        } elseif ($approvedVersion === '') {
            $storeStatus = 'no_approved_model';
        } elseif ($forecastBackedCount === 0) {
            $hasStale = false;
            $hasUnapproved = false;
            $hasInsufficient = false;
            foreach ($products as $p) {
                $r = (string)($p['exclusion_reason'] ?? '');
                if (str_contains($r, 'Stale forecast')) {
                    $hasStale = true;
                } elseif (str_contains($r, 'Unapproved model')) {
                    $hasUnapproved = true;
                } elseif (str_contains($r, 'Insufficient')) {
                    $hasInsufficient = true;
                }
            }
            if ($hasStale) {
                $storeStatus = 'stale';
            } elseif ($hasUnapproved) {
                $storeStatus = 'unapproved_model';
            } elseif ($hasInsufficient) {
                $storeStatus = 'insufficient_history';
            } else {
                $storeStatus = 'stock_rules_only';
            }
        } elseif ($forecastBackedCount === $scopeCount) {
            $storeStatus = 'full';
        } else {
            $storeStatus = 'partial';
        }

        if ($scopeCount === 0) {
            $summaryText = 'No active products available in catalog.';
        } elseif ($latestAttemptStatus === 'failed') {
            $recoveryText = ForecastingSchedulerService::canAutomaticallyRetry($latestJob)
                ? 'Automatic retry scheduled; no action needed.'
                : 'Automatic recovery is blocked or exhausted. Super Administrator: inspect the failure before retrying.';
            if ($forecastBackedCount > 0) {
                $summaryText = sprintf(
                    'Forecast update failed. Preserving valid guidance for %d of %d products; %d use stock rules. %s',
                    $forecastBackedCount, $scopeCount, $stockRuleCount, $recoveryText
                );
            } else {
                $summaryText = sprintf(
                    'Forecast update failed. Buying suggestions are using stock rules for all %d products. %s',
                    $scopeCount, $recoveryText
                );
            }
        } elseif ($isUpdating) {
            if ($forecastBackedCount > 0) {
                $summaryText = sprintf(
                    'Forecasts available for %d of %d products; %d use stock rules. Background update in progress; existing guidance remains valid.',
                    $forecastBackedCount, $scopeCount, $stockRuleCount
                );
            } else {
                $summaryText = sprintf(
                    'Forecast generation in progress for all %d products. Buying suggestions use stock rules until run completes.',
                    $scopeCount
                );
            }
        } elseif ($forecastBackedCount === $scopeCount) {
            $summaryText = sprintf('Forecasts available for all %d products.', $scopeCount);
        } elseif ($forecastBackedCount > 0) {
            $summaryText = sprintf('Forecasts available for %d of %d products; %d use stock rules.', $forecastBackedCount, $scopeCount, $stockRuleCount);
        } elseif ($storeStatus === 'no_approved_model') {
            $summaryText = sprintf('No approved Store model. All %d products use stock rules.', $scopeCount);
        } elseif ($storeStatus === 'stale') {
            $summaryText = sprintf('Forecast is stale. All %d products use stock rules.', $scopeCount);
        } else {
            $summaryText = sprintf('Stock rules used for all %d products.', $scopeCount);
        }

        $excludedProducts = [];
        $exclusionReasons = [];
        foreach ($products as $p) {
            if (empty($p['is_forecast_backed'])) {
                $excludedProducts[] = $p;
                $reason = $p['exclusion_reason'] ?? 'Forecast unavailable';
                $exclusionReasons[$reason] = ($exclusionReasons[$reason] ?? 0) + 1;
            }
        }

        $filtered = null;
        $isFiltered = ($search !== null && trim($search) !== '') || ($reasonFilter !== null && trim($reasonFilter) !== '');
        if ($isFiltered) {
            $searchTerm = mb_strtolower(trim((string)$search));
            $filterReason = trim((string)$reasonFilter);

            $matched = array_values(array_filter($products, static function (array $p) use ($searchTerm, $filterReason): bool {
                if ($searchTerm !== '') {
                    $name = mb_strtolower((string)($p['product_name'] ?? ''));
                    $sku = mb_strtolower((string)($p['sku'] ?? ''));
                    $idStr = (string)($p['product_id'] ?? '');
                    if (!str_contains($name, $searchTerm) && !str_contains($sku, $searchTerm) && $idStr !== $searchTerm) {
                        return false;
                    }
                }
                if ($filterReason !== '') {
                    $reason = (string)($p['exclusion_reason'] ?? '');
                    if ($reason !== $filterReason && ($p['exclusion_category'] ?? '') !== $filterReason) {
                        return false;
                    }
                }
                return true;
            }));

            $fbCount = count(array_filter($matched, static fn($p) => !empty($p['is_forecast_backed'])));
            $filtered = [
                'is_filtered' => true,
                'count' => count($matched),
                'forecast_backed_count' => $fbCount,
                'stock_rule_count' => count($matched) - $fbCount,
                'products' => $matched,
            ];
        }

        $coverageResult = [
            'scope_count' => $scopeCount,
            'forecast_backed_count' => $forecastBackedCount,
            'stock_rule_count' => $stockRuleCount,
            'coverage_summary_text' => $summaryText,
            'is_guidance_usable' => $isGuidanceUsable,
            'store_status' => $storeStatus,
            'as_of_date' => $today->format('Y-m-d'),
            'accepted_origin' => $acceptedOrigin,
            'effective_job_id' => $effectiveJobId,
            'latest_job' => $latestJob,
            'latest_attempt_status' => $latestAttemptStatus,
            'is_updating' => $isUpdating,
            'approved_model_version' => $approvedVersion ?: null,
            'is_observation_mode' => $isObservation,
            'is_recommendations_suspended' => $isSuspended,
            'products' => $products,
            'excluded_products' => $excludedProducts,
            'exclusion_reasons' => $exclusionReasons,
            'filtered' => $filtered ?? [
                'is_filtered' => false,
                'count' => $scopeCount,
                'forecast_backed_count' => $forecastBackedCount,
                'stock_rule_count' => $stockRuleCount,
                'products' => $products,
            ],
        ];

        $coverageResult['operational_issues'] = $this->getOperationalIssues($coverageResult, $actorRole);
        $coverageResult['performance_summary'] = $this->getPerformanceSummary($coverageResult['approved_model_version'], $coverageResult);

        return $coverageResult;
    }

    public function getOperationalIssues(array $coverage, ?string $actorRole = 'admin'): array
    {
        $issues = [];
        $actorRole = $actorRole ?? 'admin';
        $latestJob = $coverage['latest_job'] ?? $this->getLatestJob();
        $isSuspended = !empty($coverage['is_recommendations_suspended']);
        $isObservation = !empty($coverage['is_observation_mode']);
        $isUpdating = !empty($coverage['is_updating']);
        $approvedVersion = (string)($coverage['approved_model_version'] ?? '');
        $urlHelper = static fn(string $path): string => function_exists('app_url') ? app_url($path) : $path;

        // -------------------------------------------------------------
        // Priority 1: Conditions Affecting Current Guidance
        // -------------------------------------------------------------

        // 1a. Guidance paused by Administrator
        if ($isSuspended) {
            $actionUrl = null;
            $actionLabel = 'Administrator action required to resume guidance';
            if ($actorRole === 'admin') {
                $actionUrl = $urlHelper('components/administrator/model_review.php');
                $actionLabel = 'Manage Guidance in Model Review';
            } elseif ($actorRole === 'super_admin') {
                $actionUrl = $urlHelper('components/report/forecast_analytics.php');
                $actionLabel = 'Manage Guidance in Governance';
            }
            $issues[] = [
                'priority' => 1,
                'severity' => 'warning',
                'category' => 'paused',
                'condition' => 'Forecasting guidance is paused by Administrator.',
                'effect' => 'All buying suggestions fall back to standard stock rules until resumed.',
                'responsible_role' => 'Administrator',
                'action_label' => $actionLabel,
                'action_url' => $actionUrl,
            ];
        }

        // 1b. Background update failed: Exhausted recovery vs Retry scheduled
        if ($latestJob && ($latestJob['status'] ?? '') === 'failed') {
            $attempts = (int)($latestJob['attempts'] ?? 1);
            $opMsg = (string)($latestJob['operator_message'] ?? '');
            $diagRef = (string)($latestJob['diagnostic_reference'] ?? '');
            $isExhausted = !ForecastingSchedulerService::canAutomaticallyRetry($latestJob);
            $isBlocked = str_starts_with($diagRef, 'BLOCKED:');

            if ($isExhausted) {
                $actionUrl = null;
                $actionLabel = 'Technical Support Required (Super Administrator)';
                if ($actorRole === 'super_admin') {
                    $actionUrl = $urlHelper('components/system_administrator/ml_settings.php');
                    $actionLabel = 'Inspect Worker Diagnostics';
                }
                $issues[] = [
                    'priority' => 1,
                    'severity' => 'critical',
                    'category' => $isBlocked ? 'blocked_recovery' : 'exhausted_recovery',
                    'condition' => $isBlocked ? 'Forecast update is blocked by missing prerequisites; automatic retry is unavailable.' : sprintf('Forecast update failed and automatic recovery is exhausted (%d attempts failed).', $attempts),
                    'effect' => 'Buying suggestions are using stock rules until the worker failure is resolved. Technical support required to inspect background worker logs.',
                    'responsible_role' => 'Super Administrator',
                    'action_label' => $actionLabel,
                    'action_url' => $actionUrl,
                ];
            } else {
                $issues[] = [
                    'priority' => 1,
                    'severity' => 'warning',
                    'category' => 'retry_scheduled',
                    'condition' => sprintf('Forecast update failed on attempt %d of 3.', $attempts),
                    'effect' => 'Buying suggestions are using stock rules' . (!empty($coverage['effective_job_id']) ? ' (earlier valid guidance remains active until retry)' : '') . '. Automatic retry scheduled; no action needed.',
                    'responsible_role' => 'Automatic System',
                    'action_label' => 'No action needed (Automatic retry scheduled)',
                    'action_url' => null,
                ];
            }
        }

        // 1c. Empty Store catalog
        if ((string)($coverage['store_status'] ?? '') === 'empty' || (int)($coverage['scope_count'] ?? 0) === 0) {
            $issues[] = [
                'priority' => 1,
                'severity' => 'info',
                'category' => 'empty_store',
                'condition' => 'No active replenishment products found in Store catalog.',
                'effect' => 'Demand forecasts cannot be generated without active products.',
                'responsible_role' => 'Administrator',
                'action_label' => 'Review Data Readiness',
                'action_url' => $urlHelper('components/report/data_readiness.php'),
            ];
        }

        // 1d. Stale forecast guidance (older than accepted origin, no update in progress and not failed)
        if ((string)($coverage['store_status'] ?? '') === 'stale' && !$isUpdating && (!$latestJob || (string)($latestJob['status'] ?? '') !== 'failed')) {
            $issues[] = [
                'priority' => 1,
                'severity' => 'warning',
                'category' => 'stale_guidance',
                'condition' => 'Saved forecasts are stale (results are older than the accepted latest completed Philippine day).',
                'effect' => 'All products are falling back to stock rules for replenishment suggestions.',
                'responsible_role' => 'Administrator',
                'action_label' => 'Inspect Forecast Exceptions',
                'action_url' => $urlHelper('components/report/forecast_exceptions.php'),
            ];
        }

        // 1e. No approved model
        if (($coverage['store_status'] ?? '') === 'no_approved_model' || $approvedVersion === '') {
            $issues[] = [
                'priority' => 1,
                'severity' => 'warning',
                'category' => 'no_approved_model',
                'condition' => 'No approved forecasting model selected for Store guidance.',
                'effect' => sprintf('All %d active products use standard stock rules for buying suggestions.', (int)($coverage['scope_count'] ?? 0)),
                'responsible_role' => 'Administrator',
                'action_label' => ($actorRole === 'super_admin' ? 'Configure & Train in Governance' : 'Check Data Readiness & Model Status'),
                'action_url' => ($actorRole === 'super_admin' ? $urlHelper('components/report/forecast_analytics.php') : ($actorRole === 'admin' ? $urlHelper('components/report/data_readiness.php') : null)),
            ];
        }

        // 1f. Partial coverage (some products excluded and using stock rules)
        if ((int)($coverage['forecast_backed_count'] ?? 0) > 0 && (int)($coverage['stock_rule_count'] ?? 0) > 0) {
            $actionUrl = $urlHelper('components/report/forecast_exceptions.php');
            $roleLabel = 'Administrator';
            if ($actorRole === 'inventory_manager') {
                $roleLabel = 'Inventory Manager';
            }
            $issues[] = [
                'priority' => 1,
                'severity' => 'info',
                'category' => 'partial_coverage',
                'condition' => sprintf('%d of %d active products are excluded from demand forecasting.', (int)$coverage['stock_rule_count'], (int)$coverage['scope_count']),
                'effect' => 'Excluded products use standard stock rules for replenishment suggestions.',
                'responsible_role' => $roleLabel,
                'action_label' => 'Inspect Excluded Products & Reasons',
                'action_url' => $actionUrl,
            ];
        }

        // 1g. All products using stock rules (no approved model was handled above; this covers ungenerated/insufficient forecasts)
        if ((int)($coverage['forecast_backed_count'] ?? 0) === 0 && (int)($coverage['scope_count'] ?? 0) > 0 && ($coverage['store_status'] ?? '') !== 'no_approved_model' && $approvedVersion !== '' && !$isSuspended && (!$latestJob || ($latestJob['status'] ?? '') !== 'failed')) {
            $issues[] = [
                'priority' => 1,
                'severity' => 'info',
                'category' => 'stock_rules_only',
                'condition' => sprintf('Demand forecasts unavailable for current guidance. All %d products use stock rules.', (int)($coverage['scope_count'] ?? 0)),
                'effect' => 'Buying suggestions fall back to standard min/max replenishment rules.',
                'responsible_role' => ($actorRole === 'inventory_manager' ? 'Inventory Manager' : 'Administrator'),
                'action_label' => 'Inspect Forecast Exceptions',
                'action_url' => $urlHelper('components/report/forecast_exceptions.php'),
            ];
        }

        // -------------------------------------------------------------
        // Priority 2: Available Reviews (Model Candidate Ready for Review)
        // -------------------------------------------------------------
        try {
            $stmt = $this->pdo->prepare("
                SELECT model_version FROM model_training_runs
                WHERE status = 'completed' AND model_version != ?
                ORDER BY training_run_id DESC
                LIMIT 1
            ");
            $stmt->execute([$approvedVersion]);
            $candidateVersion = $stmt->fetchColumn();

            if ($candidateVersion) {
                // Check if candidate passed evaluation gates
                $isQualified = false;
                try {
                    $evalService = new CandidateEvaluationService($this->pdo);
                    $quality = $evalService->evaluateCandidateQuality((string)$candidateVersion);
                    $isQualified = !empty($quality['passed']);
                } catch (Throwable) {}

                if (!$isQualified) {
                    $checkStmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM ml_model_evaluations
                        WHERE model_version = ? AND candidate_gate_outcome = 'passed'
                    ");
                    $checkStmt->execute([$candidateVersion]);
                    if ((int)$checkStmt->fetchColumn() >= 3) {
                        $isQualified = true;
                    }
                }

                if ($isQualified) {
                    // Check if already operationally reviewed
                    $revKey = 'ml_review_operational_' . $candidateVersion;
                    $isReviewed = (bool)($this->pdo->query("SELECT 1 FROM platform_settings WHERE setting_key = '" . $revKey . "'")->fetchColumn() ?: false);
                    if (!$isReviewed) {
                        $actionUrl = null;
                        $actionLabel = 'Administrator operational review required';
                        if ($actorRole === 'admin') {
                            $actionUrl = $urlHelper('components/administrator/model_review.php');
                            $actionLabel = 'Open Detailed Model Review';
                        } elseif ($actorRole === 'super_admin') {
                            $actionUrl = $urlHelper('components/report/forecast_analytics.php');
                            $actionLabel = 'View Candidate Governance';
                        }
                        $issues[] = [
                            'priority' => 2,
                            'severity' => 'warning',
                            'category' => 'candidate_review',
                            'condition' => sprintf("Qualified candidate model '%s' is ready for operational review.", $candidateVersion),
                            'effect' => 'Existing guidance continues unchanged until the new model is approved.',
                            'responsible_role' => 'Administrator',
                            'action_label' => $actionLabel,
                            'action_url' => $actionUrl,
                        ];
                    }
                }
            }
        } catch (Throwable) {}

        // -------------------------------------------------------------
        // Priority 3: Informational Progress
        // -------------------------------------------------------------

        // 3a. Candidate observation progress
        if ($isObservation) {
            $obsDays = 0;
            $obsReq = 28;
            $obsModel = $approvedVersion ?: 'Candidate';
            try {
                $evalService = new CandidateEvaluationService($this->pdo);
                $prog = $evalService->getCandidateObservationProgress($obsModel);
                $obsDays = (int)($prog['usable_observed_days'] ?? 0);
                $obsReq = (int)($prog['required_observed_days'] ?? 28);
            } catch (Throwable) {}

            $actionUrl = null;
            if ($actorRole === 'admin') {
                $actionUrl = $urlHelper('components/administrator/model_review.php');
            } elseif ($actorRole === 'super_admin') {
                $actionUrl = $urlHelper('components/report/forecast_analytics.php');
            }
            $issues[] = [
                'priority' => 3,
                'severity' => 'info',
                'category' => 'observation_mode',
                'condition' => sprintf("Model '%s' is in prospective observation (day %d of %d).", $obsModel, $obsDays, $obsReq),
                'effect' => 'Forecasts are being observed against live Store sales and are not approved for routine purchasing.',
                'responsible_role' => 'Automatic Observation',
                'action_label' => 'View Observation Details',
                'action_url' => $actionUrl,
            ];
        }

        // 3b. Background update in progress
        if ($isUpdating) {
            $issues[] = [
                'priority' => 3,
                'severity' => 'info',
                'category' => 'updating',
                'condition' => sprintf('Background forecast generation is in progress (Job #%s).', (string)($latestJob['job_id'] ?? 'active')),
                'effect' => (int)($coverage['forecast_backed_count'] ?? 0) > 0
                    ? 'Existing guidance remains valid until the run finishes.'
                    : 'Buying suggestions use stock rules until the run completes.',
                'responsible_role' => 'Background Worker',
                'action_label' => 'In Progress (No action needed)',
                'action_url' => null,
            ];
        }

        // -------------------------------------------------------------
        // Priority 4: Healthy No-Action State
        // -------------------------------------------------------------
        $hasPriority1Or2 = false;
        foreach ($issues as $iss) {
            if ($iss['priority'] <= 2 && !in_array($iss['category'], ['partial_coverage'], true)) {
                $hasPriority1Or2 = true;
                break;
            }
        }

        if (!$hasPriority1Or2 && !empty($coverage['is_guidance_usable']) && (int)($coverage['forecast_backed_count'] ?? 0) === (int)($coverage['scope_count'] ?? 0)) {
            return [[
                'priority' => 4,
                'severity' => 'healthy',
                'category' => 'healthy',
                'condition' => 'All operational checks passing.',
                'effect' => 'Demand forecasts are actively guiding buying suggestions for eligible products.',
                'responsible_role' => 'None (No action needed)',
                'action_label' => 'Healthy (No action needed)',
                'action_url' => null,
            ]];
        }

        usort($issues, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $issues;
    }

    public function getPerformanceSummary(?string $modelVersion = null, ?array $coverage = null): array
    {
        $targetModel = $modelVersion;
        if (empty($targetModel)) {
            $targetModel = (string)($coverage['approved_model_version'] ?? '');
        }
        if (empty($targetModel)) {
            try {
                $targetModel = (string)($this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'")->fetchColumn() ?: '');
            } catch (Throwable) {}
        }

        $standardNotes = 'Lower error is better (WAPE is weighted absolute percentage error, not an accuracy percentage). Overestimation and underestimation are reported separately. Stockouts leave unmet demand unknown and are excluded from proven overestimation.';

        if (empty($targetModel)) {
            return [
                'status' => 'still_collecting_evidence',
                'model_version' => null,
                'reason' => 'No approved Store model selected yet. Train and approve a model to begin collecting prediction evidence.',
                'horizons' => [],
                'notes' => $standardNotes,
            ];
        }

        $evidence = $this->getPredictionPerformanceEvidence(null, $targetModel);
        $horizons = [];
        if ($evidence['status'] === 'genuine_evidence') {
            foreach ($evidence['metrics_by_horizon'] as $horizon => $metrics) {
                $horizons[$horizon] = $metrics + ['horizon_days' => $horizon];
            }
        }
        return [
            'status' => $horizons ? 'available' : 'still_collecting_evidence',
            'model_version' => $targetModel,
            'reason' => $horizons ? null : $evidence['explanation'],
            'horizons' => $horizons,
            'notes' => $standardNotes,
            'model_association' => $evidence['model_association'],
            'product_count' => $evidence['product_count'],
            'usable_comparison_count' => $evidence['usable_comparison_count'],
            'date_range' => $evidence['date_range'],
        ];
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

    public function getPredictionPerformanceEvidence(?string $asOfDate = null, ?string $modelVersion = null): array
    {
        $timezone = new \DateTimeZone('Asia/Manila');
        if ($asOfDate === null) {
            $asOfDate = (new \DateTime('now', $timezone))->modify('-1 day')->format('Y-m-d');
        }

        // Determine settings
        $settings = [];
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('ml_approved_model_version', 'ml_observation_mode', 'demo_import_metadata')");
            $settings = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        } catch (\Throwable) {}

        $approvedModel = $settings['ml_approved_model_version'] ?? null;
        $isObservation = in_array($settings['ml_observation_mode'] ?? '0', ['1', 'true'], true);

        // Resolve model version to evaluate
        $modelAssociation = 'candidate';
        if ($modelVersion === null) {
            // Check usable job first
            $usableJobId = $this->getLatestUsableJobId($asOfDate);
            if ($usableJobId) {
                try {
                    $mStmt = $this->pdo->prepare("SELECT model_version FROM demand_forecasts WHERE job_id = ? LIMIT 1");
                    $mStmt->execute([$usableJobId]);
                    $modelVersion = $mStmt->fetchColumn() ?: null;
                } catch (\Throwable) {}
            }
            if (!$modelVersion) {
                $modelVersion = $approvedModel;
            }
            if (!$modelVersion) {
                // Check latest completed training run
                try {
                    $rStmt = $this->pdo->query("SELECT model_version FROM model_training_runs WHERE status = 'completed' ORDER BY training_run_id DESC LIMIT 1");
                    $modelVersion = $rStmt ? ($rStmt->fetchColumn() ?: null) : null;
                } catch (\Throwable) {}
            }
        }

        if (!$modelVersion) {
            $evalService = new CandidateEvaluationService($this->pdo);
            return $evalService->getPredictionPerformanceEvidence($this->pdo, 'none', $asOfDate, 'unavailable');
        }

        // Determine model association
        if ($approvedModel !== null && $modelVersion === $approvedModel) {
            $modelAssociation = $isObservation ? 'candidate' : 'current';
        } else {
            // Check if it is the latest candidate from training runs
            $latestCandidate = null;
            try {
                $rStmt = $this->pdo->query("SELECT model_version FROM model_training_runs WHERE status = 'completed' ORDER BY training_run_id DESC LIMIT 1");
                $latestCandidate = $rStmt ? ($rStmt->fetchColumn() ?: null) : null;
            } catch (\Throwable) {}

            if ($latestCandidate !== null && $modelVersion === $latestCandidate) {
                $modelAssociation = 'candidate';
            } else {
                $modelAssociation = 'previous';
            }
        }

        $evalService = new CandidateEvaluationService($this->pdo);
        return $evalService->getPredictionPerformanceEvidence($this->pdo, $modelVersion, $asOfDate, $modelAssociation);
    }
}
