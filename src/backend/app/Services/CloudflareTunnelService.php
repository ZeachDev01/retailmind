<?php

declare(strict_types=1);

namespace App\Services;

/**
 * CloudflareTunnelService
 *
 * Manages portable cloudflared daemon execution for supervisor remote access:
 * - Binary auto-provisioning with zero machine coupling
 * - Process lifecycle (start, background daemon launch, graceful stop)
 * - PID tracking and stale PID self-healing
 * - Dynamic public URL extraction from logs
 * - Health and connectivity checks
 */
class CloudflareTunnelService
{
    private string $rootDir;
    private string $binDir;
    private string $runDir;
    private string $binPath;
    private string $pidPath;
    private string $logPath;
    private string $urlPath;
    private string $configPath;
    private int $defaultPort;
    private string $defaultHost;

    /** @var callable|null */
    private $processLauncher = null;

    /** @var callable|null */
    private $processChecker = null;

    /** @var callable|null */
    private $processKiller = null;

    public function __construct(
        ?string $rootDir = null,
        ?string $binDir = null,
        ?string $runDir = null,
        int $defaultPort = 80,
        string $defaultHost = '127.0.0.1'
    ) {
        $this->rootDir = $rootDir ?: (realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
        $this->binDir = $binDir ?: ($this->rootDir . '/src/backend/storage/bin');
        $this->runDir = $runDir ?: ($this->rootDir . '/src/backend/storage/tunnel');

        $isWindows = PHP_OS_FAMILY === 'Windows';
        $binName = $isWindows ? 'cloudflared.exe' : 'cloudflared';
        $this->binPath = $this->binDir . '/' . $binName;
        $this->pidPath = $this->runDir . '/tunnel.pid';
        $this->logPath = $this->runDir . '/tunnel.log';
        $this->urlPath = $this->runDir . '/tunnel.url';
        $this->configPath = $this->runDir . '/tunnel_config.json';
        $this->defaultPort = $defaultPort;
        $this->defaultHost = $defaultHost;
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function getBinDir(): string
    {
        return $this->binDir;
    }

    public function getRunDir(): string
    {
        return $this->runDir;
    }

    public function getBinPath(): string
    {
        return $this->binPath;
    }

    public function getPidPath(): string
    {
        return $this->pidPath;
    }

    public function getLogPath(): string
    {
        return $this->logPath;
    }

    public function getUrlPath(): string
    {
        return $this->urlPath;
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function getToken(): ?string
    {
        if (file_exists($this->configPath)) {
            $raw = @file_get_contents($this->configPath);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data) && !empty($data['token']) && is_string($data['token'])) {
                    $token = trim($data['token']);
                    if ($token !== '') {
                        return $token;
                    }
                }
            }
        }

        $envToken = \App\Core\Environment::get('CLOUDFLARE_TUNNEL_TOKEN') ?: getenv('CLOUDFLARE_TUNNEL_TOKEN');
        if (is_string($envToken) && trim($envToken) !== '') {
            return trim($envToken);
        }

        return null;
    }

    public function getHostname(): ?string
    {
        if (file_exists($this->configPath)) {
            $raw = @file_get_contents($this->configPath);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data) && !empty($data['hostname']) && is_string($data['hostname'])) {
                    $host = trim($data['hostname']);
                    if ($host !== '') {
                        return $host;
                    }
                }
            }
        }

        $envHost = \App\Core\Environment::get('CLOUDFLARE_TUNNEL_HOSTNAME') ?: getenv('CLOUDFLARE_TUNNEL_HOSTNAME');
        if (is_string($envHost) && trim($envHost) !== '') {
            return trim($envHost);
        }

        return null;
    }

    public function isPermanent(): bool
    {
        $token = $this->getToken();
        return $token !== null && $token !== '';
    }

    public function getMaskedToken(): ?string
    {
        $token = $this->getToken();
        if ($token === null || $token === '') {
            return null;
        }

        $len = strlen($token);
        if ($len <= 8) {
            return '••••••••';
        }

        return substr($token, 0, 4) . '••••' . substr($token, -4);
    }

    public function setToken(?string $token, ?string $hostname = null): bool
    {
        $token = $token !== null ? trim($token) : '';
        if ($token === '') {
            return $this->clearToken();
        }

        if (!is_dir($this->runDir)) {
            if (!@mkdir($this->runDir, 0755, true) && !is_dir($this->runDir)) {
                return false;
            }
        }

        $config = [];
        if (file_exists($this->configPath)) {
            $raw = @file_get_contents($this->configPath);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $config = $decoded;
                }
            }
        }

        $config['token'] = $token;
        if ($hostname !== null) {
            $hostname = trim($hostname);
            if ($hostname !== '') {
                $config['hostname'] = $hostname;
            } else {
                unset($config['hostname']);
            }
        }
        $config['updated_at'] = date('c');

        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return @file_put_contents($this->configPath, $encoded) !== false;
    }

    public function clearToken(): bool
    {
        if (!file_exists($this->configPath)) {
            return true;
        }

        $raw = @file_get_contents($this->configPath);
        if ($raw === false || $raw === '') {
            @unlink($this->configPath);
            return true;
        }

        $config = json_decode($raw, true);
        if (!is_array($config)) {
            @unlink($this->configPath);
            return true;
        }

        unset($config['token']);
        $config['updated_at'] = date('c');

        // If only updated_at left or empty, remove file cleanly
        $keys = array_keys($config);
        if (empty($keys) || (count($keys) === 1 && isset($config['updated_at']))) {
            @unlink($this->configPath);
            return true;
        }

        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return @file_put_contents($this->configPath, $encoded) !== false;
    }

    public function setProcessLauncher(?callable $launcher): self
    {
        $this->processLauncher = $launcher;
        return $this;
    }

    public function setProcessChecker(?callable $checker): self
    {
        $this->processChecker = $checker;
        return $this;
    }

    public function setProcessKiller(?callable $killer): self
    {
        $this->processKiller = $killer;
        return $this;
    }

    public function isBinaryInstalled(): bool
    {
        return file_exists($this->binPath) && filesize($this->binPath) > 0;
    }

    public function ensureBinary(?string $downloadUrl = null): bool
    {
        if ($this->isBinaryInstalled()) {
            return true;
        }

        if (!is_dir($this->binDir)) {
            if (!@mkdir($this->binDir, 0755, true) && !is_dir($this->binDir)) {
                return false;
            }
        }

        if ($downloadUrl === null) {
            $downloadUrl = PHP_OS_FAMILY === 'Windows'
                ? 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe'
                : 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64';
        }

        $tempFile = $this->binPath . '.tmp.' . uniqid();
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 60,
                'follow_location' => 1,
                'user_agent' => 'RetailMind-CloudflareTunnel/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($downloadUrl, false, $ctx);
        if ($content === false || strlen($content) === 0) {
            // Attempt with curl if allow_url_fopen is disabled or network issue
            if (function_exists('curl_init')) {
                $ch = curl_init($downloadUrl);
                $fp = @fopen($tempFile, 'wb');
                if ($ch && $fp) {
                    curl_setopt_array($ch, [
                        CURLOPT_FILE => $fp,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT => 90,
                        CURLOPT_USERAGENT => 'RetailMind-CloudflareTunnel/1.0',
                    ]);
                    $success = curl_exec($ch);
                    curl_close($ch);
                    fclose($fp);
                    if ($success && filesize($tempFile) > 0) {
                        $renamed = @rename($tempFile, $this->binPath);
                        if ($renamed) {
                            @chmod($this->binPath, 0755);
                            return true;
                        }
                    }
                }
            }
            @unlink($tempFile);
            return false;
        }

        if (@file_put_contents($tempFile, $content) === false) {
            return false;
        }

        if (!@rename($tempFile, $this->binPath)) {
            @unlink($tempFile);
            return false;
        }

        @chmod($this->binPath, 0755);
        return true;
    }

    public function isLocalServerAvailable(?int $port = null, ?string $host = null, float $timeout = 0.8): bool
    {
        $port = $port ?? $this->defaultPort;
        $host = $host ?? $this->defaultHost;

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }

        // Also check localhost hostname if 127.0.0.1 failed
        if ($host === '127.0.0.1') {
            $fp2 = @fsockopen('localhost', $port, $errno, $errstr, $timeout);
            if (is_resource($fp2)) {
                fclose($fp2);
                return true;
            }
        }

        return false;
    }

    public function isPidRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if ($this->processChecker !== null) {
            return (bool)($this->processChecker)($pid);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL', $output);
            foreach ($output as $line) {
                if (str_contains($line, '"' . $pid . '"')) {
                    return true;
                }
            }
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $out = [];
        @exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $code);
        return $code === 0;
    }

    public function isRunning(): bool
    {
        $pid = $this->getPid();
        if ($pid === null) {
            return false;
        }

        if (!$this->isPidRunning($pid)) {
            $this->cleanState();
            return false;
        }

        return true;
    }

    public function getPid(): ?int
    {
        if (!file_exists($this->pidPath)) {
            return null;
        }

        $content = trim((string)@file_get_contents($this->pidPath));
        if ($content === '' || !is_numeric($content)) {
            return null;
        }

        $pid = (int)$content;
        return $pid > 0 ? $pid : null;
    }

    public function extractPublicUrl(?string $logContent = null): ?string
    {
        if ($logContent === null) {
            if (!file_exists($this->logPath)) {
                return null;
            }
            // Read tail of log file (up to 64KB)
            $size = @filesize($this->logPath) ?: 0;
            $fp = @fopen($this->logPath, 'rb');
            if (!$fp) {
                return null;
            }
            if ($size > 65536) {
                fseek($fp, -65536, SEEK_END);
            }
            $logContent = (string)fread($fp, 65536);
            fclose($fp);
        }

        if ($logContent === '') {
            return null;
        }

        // Match standard Quick Tunnel URL (https://*.trycloudflare.com)
        if (preg_match('/https:\/\/[a-zA-Z0-9.\-]+\.trycloudflare\.com/i', $logContent, $matches)) {
            return rtrim($matches[0], '.');
        }

        return null;
    }

    public function getUrl(): ?string
    {
        if (!$this->isRunning()) {
            return null;
        }

        if ($this->isPermanent()) {
            $hostname = $this->getHostname();
            if ($hostname !== null && $hostname !== '') {
                $fullUrl = str_starts_with($hostname, 'http') ? $hostname : 'https://' . $hostname;
                @file_put_contents($this->urlPath, $fullUrl);
                return $fullUrl;
            }
        }

        $url = $this->extractPublicUrl();
        if ($url !== null) {
            @file_put_contents($this->urlPath, $url);
            return $url;
        }

        if (file_exists($this->urlPath)) {
            $saved = trim((string)@file_get_contents($this->urlPath));
            if ($saved !== '') {
                return $saved;
            }
        }

        return null;
    }

    public function getStatus(): array
    {
        $running = $this->isRunning();
        $pid = $running ? $this->getPid() : null;
        $url = $running ? $this->getUrl() : null;

        $state = 'offline';
        if ($running) {
            $state = $url !== null ? 'online' : 'starting';
        }

        $isPerm = $this->isPermanent();

        return [
            'status' => $state,
            'online' => $running,
            'url' => $url,
            'pid' => $pid,
            'binary_installed' => $this->isBinaryInstalled(),
            'local_server_available' => $this->isLocalServerAvailable(),
            'mode' => $isPerm ? 'permanent' : 'quick',
            'token_configured' => $isPerm,
            'masked_token' => $this->getMaskedToken(),
            'hostname' => $this->getHostname(),
        ];
    }

    public function start(?int $port = null, int $waitUrlSeconds = 3): array
    {
        if ($this->isRunning()) {
            return $this->getStatus();
        }

        if (!is_dir($this->runDir)) {
            @mkdir($this->runDir, 0755, true);
        }

        if (!$this->isBinaryInstalled() && $this->processLauncher === null) {
            $installed = $this->ensureBinary();
            if (!$installed) {
                return [
                    'status' => 'offline',
                    'online' => false,
                    'url' => null,
                    'pid' => null,
                    'binary_installed' => false,
                    'local_server_available' => $this->isLocalServerAvailable($port),
                    'mode' => $this->isPermanent() ? 'permanent' : 'quick',
                    'token_configured' => $this->isPermanent(),
                    'masked_token' => $this->getMaskedToken(),
                    'hostname' => $this->getHostname(),
                    'error' => 'Failed to download portable cloudflared executable.',
                ];
            }
        }

        // Reset previous logs and url
        @file_put_contents($this->logPath, '');
        @unlink($this->urlPath);
        @unlink($this->pidPath);

        if ($this->isPermanent()) {
            $token = (string)$this->getToken();
            $args = [
                'run',
                '--token',
                $token,
                '--logfile',
                $this->logPath,
            ];
            $hostname = $this->getHostname();
            if ($hostname !== null && $hostname !== '') {
                $hostUrl = str_starts_with($hostname, 'http') ? $hostname : 'https://' . $hostname;
                @file_put_contents($this->urlPath, $hostUrl);
            }
        } else {
            $port = $port ?? $this->defaultPort;
            $localUrl = 'http://' . $this->defaultHost . ':' . $port;

            $args = [
                'tunnel',
                '--no-autoupdate',
                '--url',
                $localUrl,
                '--logfile',
                $this->logPath,
            ];
        }

        $pid = null;

        if ($this->processLauncher !== null) {
            $pid = (int)($this->processLauncher)($this->binPath, $args, $this->logPath, $this->pidPath);
        } else {
            $pid = $this->launchProcess($this->binPath, $args);
            if ($pid !== null && $pid > 0) {
                @file_put_contents($this->pidPath, (string)$pid);
            }
        }

        if ($pid === null || $pid <= 0) {
            return [
                'status' => 'offline',
                'online' => false,
                'url' => null,
                'pid' => null,
                'binary_installed' => $this->isBinaryInstalled(),
                'local_server_available' => $this->isLocalServerAvailable($port),
                'mode' => $this->isPermanent() ? 'permanent' : 'quick',
                'token_configured' => $this->isPermanent(),
                'masked_token' => $this->getMaskedToken(),
                'hostname' => $this->getHostname(),
                'error' => 'Failed to start tunnel daemon process.',
            ];
        }

        // Wait briefly for URL extraction if requested
        if ($waitUrlSeconds > 0) {
            $deadline = microtime(true) + $waitUrlSeconds;
            while (microtime(true) < $deadline) {
                if (!$this->isPidRunning($pid)) {
                    break;
                }
                $url = $this->getUrl();
                if ($url !== null) {
                    break;
                }
                usleep(250000); // 250ms
            }
        }

        return $this->getStatus();
    }

    public function stop(): array
    {
        $pid = $this->getPid();
        if ($pid !== null && $this->isPidRunning($pid)) {
            if ($this->processKiller !== null) {
                ($this->processKiller)($pid);
            } else {
                $this->killProcess($pid);
            }
        }

        $this->cleanState();
        return $this->getStatus();
    }

    private function launchProcess(string $binPath, array $args): ?int
    {
        $argString = implode(' ', array_map('escapeshellarg', $args));

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows PowerShell Start-Process detached launch
            $psBin = escapeshellarg($binPath);
            $psArgs = escapeshellarg(implode(' ', $args));
            $cmd = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '
                . '"$p = Start-Process -FilePath ' . $psBin . ' -ArgumentList ' . $psArgs . ' -PassThru -WindowStyle Hidden; $p.Id"';

            $output = [];
            @exec($cmd, $output, $exitCode);
            $pidStr = trim(implode('', $output));
            if ($exitCode === 0 && is_numeric($pidStr) && (int)$pidStr > 0) {
                return (int)$pidStr;
            }
            return null;
        }

        // Linux/macOS background launch
        $cmd = escapeshellcmd($binPath) . ' ' . $argString . ' > /dev/null 2>&1 & echo $!';
        $pidStr = trim((string)@shell_exec($cmd));
        if (is_numeric($pidStr) && (int)$pidStr > 0) {
            return (int)$pidStr;
        }

        return null;
    }

    private function killProcess(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /F /T /PID ' . $pid . ' 2>NUL', $output, $code);
            return $code === 0 || !$this->isPidRunning($pid);
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15); // SIGTERM
            usleep(200000);
            if ($this->isPidRunning($pid)) {
                @posix_kill($pid, 9); // SIGKILL
            }
            return true;
        }

        @exec('kill -9 ' . $pid . ' 2>/dev/null');
        return true;
    }

    private function cleanState(): void
    {
        @unlink($this->pidPath);
        @unlink($this->urlPath);
    }
}
