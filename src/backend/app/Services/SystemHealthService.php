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
            $this->forecastingPipelineCheck(),
            $this->workerHealthCheck(),
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
        $mailer = strtolower((string)env('MAIL_MAILER', 'smtp'));
        $configured = $mailer === 'brevo'
            ? (string)env('BREVO_API_KEY', '') !== '' && (string)env('MAIL_FROM_ADDRESS', '') !== ''
            : (string)env('MAIL_HOST', '') !== '' && (string)env('MAIL_FROM_ADDRESS', '') !== '';
        $message = $mailer === 'brevo'
            ? 'Brevo API key and sender address are configured.'
            : 'SMTP host and sender address are configured.';
        return $this->result('Email configuration', $configured ? 'healthy' : 'warning', $configured ? $message : 'Email provider or sender configuration is missing.', 'Integrations');
    }

    private function applicationLogCheck(): array
    {
        return (new \App\Attention\ApplicationLogReviewService(
            $this->pdo, new \App\Attention\SystemClock(), $this->backendPath . '/storage/logs/app.log'
        ))->check();
    }
}
