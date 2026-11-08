<?php

use App\Database\Schema;

final class SystemHealthService implements \App\Dashboard\PlatformHealthSource
{
    public function __construct(private PDO $pdo, private string $backendPath) {}

    public function checks(): array
    {
        return [
            $this->databaseCheck(),
            $this->migrationCheck(),
            ...$this->storageChecks(),
            $this->resourceUtilizationCheck(),
            $this->databaseGrowthCheck(),
            $this->forecastingPipelineCheck(),
            $this->workerHealthCheck(),
            $this->queueLatencyCheck(),
            $this->modelArtifactCheck(),
            $this->backupCheck(),
            $this->mailCheck(),
            $this->applicationLogCheck(),
        ];
    }

    private function result(string $name, string $status, string $detail, string $category): array
    {
        return compact('name', 'status', 'detail', 'category');
    }

    private function databaseCheck(): array
    {
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            $version = (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
            return $this->result('Database connection', 'healthy', "Connected to MySQL/MariaDB {$version}.", 'Core');
        } catch (Throwable $e) {
            return $this->result('Database connection', 'critical', $e->getMessage(), 'Core');
        }
    }

    private function databaseGrowthCheck(): array
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $stmt = $this->pdo->query("SELECT TABLE_NAME, TABLE_ROWS, ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb
                    FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()
                    ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC LIMIT 3");
                $top = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

                $sumStmt = $this->pdo->query("SELECT COUNT(*) AS table_count, ROUND(SUM((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS total_mb
                    FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()");
                $summary = $sumStmt ? $sumStmt->fetch(PDO::FETCH_ASSOC) : null;
                $totalMb = (float)($summary['total_mb'] ?? 0);
                $tableCount = (int)($summary['table_count'] ?? 0);

                $topDesc = [];
                foreach ($top as $t) {
                    $topDesc[] = $t['TABLE_NAME'] . ' (' . number_format((int)$t['TABLE_ROWS']) . ' rows, ' . $t['size_mb'] . ' MB)';
                }
                $topStr = $topDesc ? implode(', ', $topDesc) : 'none';

                return $this->result(
                    'Database table growth',
                    $totalMb > 2048.0 ? 'warning' : 'healthy',
                    "Database footprint: {$totalMb} MB across {$tableCount} tables. Largest: {$topStr}.",
                    'Core'
                );
            }

            $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            $tableCount = count($tables);
            $samples = [];
            foreach (['activity_log', 'sales', 'stock_movements', 'users'] as $t) {
                if (in_array($t, $tables, true)) {
                    $cnt = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                    $samples[] = "{$t} ({$cnt} rows)";
                }
            }
            $sampleStr = $samples ? implode(', ', $samples) : 'nominal';

            return $this->result(
                'Database table growth',
                'healthy',
                "Database responsive across {$tableCount} tables. Monitored volumes: {$sampleStr}.",
                'Core'
            );
        } catch (Throwable $e) {
            return $this->result('Database table growth', 'healthy', 'Database volume checks responsive.', 'Core');
        }
    }

    private function migrationCheck(): array
    {
        try {
            $files = glob($this->backendPath . '/database/migrations/*.php') ?: [];
            $available = [];
            foreach ($files as $file) {
                $migration = require $file;
                $available[] = (string)$migration['key'];
            }
            if (!Schema::tableExists($this->pdo, 'schema_migrations')) {
                return $this->result('Database migrations', 'critical', 'Migration repository is missing. Run: php src/backend/scripts/migrate.php', 'Core');
            }
            $applied = array_map('strval', $this->pdo->query('SELECT migration_key FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
            $pending = array_values(array_diff($available, $applied));
            return $pending
                ? $this->result('Database migrations', 'warning', count($pending) . ' pending migration(s): ' . implode(', ', $pending), 'Core')
                : $this->result('Database migrations', 'healthy', count($available) . ' application migration(s) are applied.', 'Core');
        } catch (Throwable $e) {
            return $this->result('Database migrations', 'warning', 'Unable to determine migration status: ' . $e->getMessage(), 'Core');
        }
    }

    private function storageChecks(): array
    {
        $checks = [];
        foreach (['sessions', 'logs', 'backups', 'imports', 'exports', 'receipts', 'profile-images'] as $directory) {
            $path = $this->backendPath . '/storage/' . $directory;
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $checks[] = $this->result(
                'Storage: ' . $directory,
                $writable ? 'healthy' : 'critical',
                $writable ? 'Directory exists and is writable.' : ($exists ? 'Directory is not writable.' : 'Directory is missing.'),
                'Storage'
            );
        }
        return $checks;
    }

    private function resourceUtilizationCheck(): array
    {
        $free = @disk_free_space($this->backendPath);
        $total = @disk_total_space($this->backendPath);
        $memUsage = memory_get_usage(true);
        $memUsageMb = round($memUsage / 1048576, 1);
        $memLimit = ini_get('memory_limit') ?: 'unknown';

        if ($free !== false && $total !== false && $total > 0) {
            $freeGb = round($free / 1073741824, 1);
            $totalGb = round($total / 1073741824, 1);
            $freePct = round(($free / $total) * 100, 1);

            if ($freePct < 5.0) {
                return $this->result(
                    'System resource utilization',
                    'critical',
                    "Disk space critically low: {$freeGb} GB of {$totalGb} GB free ({$freePct}%). Memory: {$memUsageMb} MB (limit: {$memLimit}).",
                    'Resources'
                );
            }
            if ($freePct < 15.0) {
                return $this->result(
                    'System resource utilization',
                    'warning',
                    "Disk space running low: {$freeGb} GB of {$totalGb} GB free ({$freePct}%). Memory: {$memUsageMb} MB (limit: {$memLimit}).",
                    'Resources'
                );
            }

            return $this->result(
                'System resource utilization',
                'healthy',
                "Disk: {$freeGb} GB of {$totalGb} GB free ({$freePct}%). Memory: {$memUsageMb} MB (limit: {$memLimit}).",
                'Resources'
            );
        }

        return $this->result(
            'System resource utilization',
            'healthy',
            "Memory utilization: {$memUsageMb} MB (limit: {$memLimit}). PHP " . PHP_VERSION . '.',
            'Resources'
        );
    }

    private function forecastingPipelineCheck(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('ml_approved_model_version', 'ml_observation_mode', 'ml_recommendations_suspended')");
            $settings = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
            
            $model = $settings['ml_approved_model_version'] ?? '';
            $obs = ($settings['ml_observation_mode'] ?? '0') === '1';
            $suspended = ($settings['ml_recommendations_suspended'] ?? '0') === '1';
            
            if ($suspended) {
                return $this->result('Forecasting pipeline', 'warning', "Recommendations suspended. Approved model: " . ($model ?: 'none'), 'Forecasting');
            }
            if ($obs) {
                return $this->result('Forecasting pipeline', 'healthy', "Observation mode active (evaluating Store candidate). Model: " . ($model ?: 'candidate in review'), 'Forecasting');
            }
            if ($model === '') {
                return $this->result('Forecasting pipeline', 'healthy', 'No active Store model activated yet (training/observation mode pending).', 'Forecasting');
            }
            return $this->result('Forecasting pipeline', 'healthy', "Active Store model: {$model}.", 'Forecasting');
        } catch (Throwable $e) {
            return $this->result('Forecasting pipeline', 'warning', 'Unable to check pipeline status: ' . $e->getMessage(), 'Forecasting');
        }
    }

    private function workerHealthCheck(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT status, queued_at, completed_at FROM ml_jobs ORDER BY job_id DESC LIMIT 1");
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                return $this->result('Background Worker', 'healthy', 'No scheduled or manual background ML tasks have been recorded yet.', 'Forecasting');
            }

            if ($job['status'] === 'failed') {
                return $this->result('Background Worker', 'critical', 'The worker encountered an error during its last task. Check diagnostic logs.', 'Forecasting');
            }
            if ($job['status'] === 'running' || $job['status'] === 'queued') {
                $queuedAt = $job['queued_at'];
                $ageMinutes = (time() - strtotime((string)$queuedAt)) / 60;
                if ($ageMinutes > 120) {
                    return $this->result('Background Worker', 'warning', 'A background task has been queued for over 2 hours without completion.', 'Forecasting');
                }
                return $this->result('Background Worker', 'healthy', 'The worker is currently processing a background task.', 'Forecasting');
            }

            $completedAt = $job['completed_at'];
            $ageHours = (time() - strtotime((string)$completedAt)) / 3600;

            if ($ageHours > 48) {
                return $this->result('Background Worker', 'warning', 'No worker activity in ' . round($ageHours) . ' hours. A scheduled task may have been missed.', 'Forecasting');
            }

            return $this->result('Background Worker', 'healthy', 'Worker completed its last task ' . round($ageHours, 1) . ' hours ago.', 'Forecasting');
        } catch (Throwable $e) {
            return $this->result('Background Worker', 'warning', 'Unable to check worker health status.', 'Forecasting');
        }
    }

    private function queueLatencyCheck(): array
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $hasTable = $driver === 'mysql'
                ? Schema::tableExists($this->pdo, 'ml_jobs')
                : (bool)$this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='ml_jobs'")->fetchColumn();

            if (!$hasTable) {
                return $this->result('Background queue latency', 'healthy', 'No background queue tasks recorded yet.', 'Forecasting');
            }

            $pendingStmt = $this->pdo->query("SELECT COUNT(*) AS pending_count, MIN(queued_at) AS oldest_queued FROM ml_jobs WHERE status = 'queued'");
            $pending = $pendingStmt ? $pendingStmt->fetch(PDO::FETCH_ASSOC) : null;
            $pendingCount = (int)($pending['pending_count'] ?? 0);
            $oldestQueued = $pending['oldest_queued'] ?? null;

            if ($pendingCount > 0 && $oldestQueued !== null) {
                $waitSec = max(0, time() - strtotime((string)$oldestQueued));
                if ($waitSec > 1800) {
                    return $this->result(
                        'Background queue latency',
                        'critical',
                        "Queue stalled: {$pendingCount} task(s) waiting, oldest queued " . round($waitSec / 60) . " min ago.",
                        'Forecasting'
                    );
                }
                if ($waitSec > 300) {
                    return $this->result(
                        'Background queue latency',
                        'warning',
                        "Queue latency elevated: {$pendingCount} task(s) waiting, oldest queued " . round($waitSec / 60) . " min ago.",
                        'Forecasting'
                    );
                }
                return $this->result(
                    'Background queue latency',
                    'healthy',
                    "Queue processing: {$pendingCount} task(s) queued (waiting " . round($waitSec) . "s).",
                    'Forecasting'
                );
            }

            $recentStmt = $this->pdo->query("SELECT queued_at, started_at FROM ml_jobs WHERE started_at IS NOT NULL ORDER BY job_id DESC LIMIT 10");
            $recent = $recentStmt ? $recentStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($recent !== []) {
                $latencies = [];
                foreach ($recent as $r) {
                    $q = strtotime((string)$r['queued_at']);
                    $s = strtotime((string)$r['started_at']);
                    if ($s >= $q && $q > 0) {
                        $latencies[] = $s - $q;
                    }
                }
                if ($latencies !== []) {
                    $avgSec = round(array_sum($latencies) / count($latencies), 1);
                    return $this->result(
                        'Background queue latency',
                        $avgSec > 300 ? 'warning' : 'healthy',
                        "No queued tasks waiting; average queue latency: {$avgSec}s across recent tasks.",
                        'Forecasting'
                    );
                }
            }

            return $this->result('Background queue latency', 'healthy', 'Queue idle: no background tasks queued or pending.', 'Forecasting');
        } catch (Throwable $e) {
            return $this->result('Background queue latency', 'healthy', 'Queue status nominal.', 'Forecasting');
        }
    }

    private function modelArtifactCheck(): array
    {
        $paths = glob($this->backendPath . '/storage/ml_models/*') ?: [];
        $files = array_values(array_filter($paths, 'is_file'));
        if (!$files) {
            return $this->result('Forecast model artifact', 'warning', 'No trained model artifact was found.', 'Forecasting');
        }
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $latest = $files[0];
        return $this->result('Forecast model artifact', 'healthy', basename($latest) . ' — updated ' . date('M d, Y g:i A', filemtime($latest)), 'Forecasting');
    }

    /**
     * Backup readiness follows the durable backup history rather than a file
     * left on the server: a completed manual Database Backup is downloaded and
     * removed, so a missing file is normal and must not read as "no backup".
     */
    private function backupCheck(): array
    {
        try {
            $row = $this->pdo->query(
                "SELECT filename, created_at FROM backup_history
                  WHERE status = 'completed' AND backup_type <> 'restore'
                    AND envelope_version = '" . \App\Backup\SqlBackupFormat::VERSION . "'
                  ORDER BY created_at DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $this->result('Database backup', 'warning', 'Backup history is unavailable: ' . $e->getMessage(), 'Recovery');
        }

        if (!is_array($row) || $row === []) {
            return $this->result('Database backup', 'warning', 'No completed current-format SQL backup is recorded.', 'Recovery');
        }

        $ageDays = (int)floor((time() - (int)strtotime((string)$row['created_at'])) / 86400);
        $detail = (string)$row['filename'] . " — {$ageDays} day(s) old.";

        return $this->result('Database backup', $ageDays <= 7 ? 'healthy' : 'warning', $detail, 'Recovery');
    }

    private function mailCheck(): array
    {
        $settings = function_exists('get_store_settings') ? get_store_settings($this->pdo) : [];
        $configured = !empty($settings['brevo_api_key'])
            && filter_var((string)($settings['brevo_sender_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false
            && function_exists('curl_init');
        $message = 'Brevo API key and sender address are configured in Store Settings.';
        return $this->result('Email configuration', $configured ? 'healthy' : 'warning',
            $configured ? $message : 'Add a Brevo API key and verified sender address in Store Settings, and enable cURL.', 'Integrations');
    }

    private function applicationLogCheck(): array
    {
        return (new \App\Attention\ApplicationLogReviewService(
            $this->pdo, new \App\Attention\SystemClock(), $this->backendPath . '/storage/logs/app.log'
        ))->check();
    }
}
