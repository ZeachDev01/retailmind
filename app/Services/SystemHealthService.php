<?php

use App\Database\Schema;

final class SystemHealthService
{
    public function __construct(private PDO $pdo, private string $rootPath)
    {
    }

    public function checks(): array
    {
        return [
            $this->databaseCheck(),
            $this->migrationCheck(),
            ...$this->storageChecks(),
            $this->mlServiceCheck(),
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
            $files = glob($this->rootPath . '/database/migrations/*.php') ?: [];
            $available = [];
            foreach ($files as $file) {
                $migration = require $file;
                $available[] = (string)$migration['key'];
            }
            if (!Schema::tableExists($this->pdo, 'schema_migrations')) {
                return $this->result('Database migrations', 'critical', 'Migration repository is missing. Run: php scripts/migrate.php', 'Core');
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
        foreach (['sessions', 'logs', 'backups', 'imports', 'exports', 'receipts'] as $directory) {
            $path = $this->rootPath . '/storage/' . $directory;
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

    private function mlServiceCheck(): array
    {
        $baseUrl = rtrim((string)env('ML_API_URL', 'http://127.0.0.1:5000'), '/');
        $url = $baseUrl . '/health';
        try {
            $body = false;
            if (function_exists('curl_init')) {
                $curl = curl_init($url);
                curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
                $body = curl_exec($curl);
                $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_error($curl);
                curl_close($curl);
                if ($body === false || $code < 200 || $code >= 300) {
                    throw new RuntimeException($error ?: "HTTP {$code}");
                }
            } else {
                $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
                $body = @file_get_contents($url, false, $context);
                if ($body === false) {
                    throw new RuntimeException('Unable to connect to the health endpoint.');
                }
            }
            $payload = json_decode((string)$body, true) ?: [];
            $modelState = !empty($payload['model_loaded']) ? 'Model is loaded.' : 'Service is online but no model is loaded.';
            return $this->result('Forecast API', !empty($payload['model_loaded']) ? 'healthy' : 'warning', $baseUrl . ' — ' . $modelState, 'Forecasting');
        } catch (Throwable $e) {
            return $this->result('Forecast API', 'warning', $baseUrl . ' is unavailable: ' . $e->getMessage(), 'Forecasting');
        }
    }

    private function modelArtifactCheck(): array
    {
        $paths = glob($this->rootPath . '/legacy/demandForcasting/models/*') ?: [];
        $files = array_values(array_filter($paths, 'is_file'));
        if (!$files) {
            return $this->result('Forecast model artifact', 'warning', 'No trained model artifact was found.', 'Forecasting');
        }
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $latest = $files[0];
        return $this->result('Forecast model artifact', 'healthy', basename($latest) . ' — updated ' . date('M d, Y g:i A', filemtime($latest)), 'Forecasting');
    }

    private function backupCheck(): array
    {
        $files = glob($this->rootPath . '/storage/backups/*') ?: [];
        $files = array_values(array_filter($files, static fn(string $file): bool => is_file($file) && basename($file) !== '.gitkeep'));
        if (!$files) {
            return $this->result('Database backup', 'warning', 'No completed backup file was found.', 'Recovery');
        }
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $latest = $files[0];
        $ageDays = (int)floor((time() - filemtime($latest)) / 86400);
        return $this->result('Database backup', $ageDays <= 7 ? 'healthy' : 'warning', basename($latest) . " — {$ageDays} day(s) old.", 'Recovery');
    }

    private function mailCheck(): array
    {
        $configured = (string)env('MAIL_HOST', '') !== '' && (string)env('MAIL_FROM_ADDRESS', '') !== '';
        return $this->result('Email configuration', $configured ? 'healthy' : 'warning', $configured ? 'SMTP host and sender address are configured.' : 'SMTP host or sender address is missing.', 'Integrations');
    }

    private function applicationLogCheck(): array
    {
        $path = $this->rootPath . '/storage/logs/app.log';
        if (!is_file($path) || filesize($path) === 0) {
            return $this->result('Application log', 'healthy', 'No logged application errors are currently stored.', 'Core');
        }
        $size = filesize($path);
        $modified = filemtime($path);
        $recent = $modified >= time() - 86400;
        return $this->result('Application log', $recent ? 'warning' : 'healthy', number_format($size / 1024, 1) . ' KB — last updated ' . date('M d, Y g:i A', $modified), 'Core');
    }
}
