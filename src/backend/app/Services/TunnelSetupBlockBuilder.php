<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Builds the paste-once PowerShell blocks shown in Platform Settings.
 *
 * Pure text construction: no filesystem, network, or process access. The card
 * supplies the resolved application root, local port, and saved tunnel values.
 */
final class TunnelSetupBlockBuilder
{
    public const SERVICE_NAME = 'RetailMindCloudflareTunnel';
    public const LEGACY_TASK_NAME = 'RetailMindQuickTunnel';

    /**
     * @param array{
     *     app_root: string,
     *     local_port: int,
     *     token?: ?string,
     *     hostname?: ?string,
     *     mode?: ?string
     * } $input
     */
    public function buildSetupBlock(array $input): string
    {
        $root = $this->appRoot($input);
        $port = $this->localPort($input);
        $token = trim((string)($input['token'] ?? ''));
        $hostname = trim((string)($input['hostname'] ?? ''));
        $mode = $this->mode($token, (string)($input['mode'] ?? ''));
        $healthPath = (string)($input['health_path'] ?? '/' . basename(str_replace('\\', '/', $root)) . '/src/frontend/remote_health.php');
        if (!preg_match('#^/[a-zA-Z0-9_./% -]+$#D', $healthPath) || str_contains($healthPath, '..')) {
            throw new \InvalidArgumentException('Invalid application health path.');
        }
        if ($mode === 'ngrok' && (strlen($token) > 512 || !preg_match('/^[a-zA-Z0-9_]+$/D', $token)
            || !preg_match('/^[a-zA-Z0-9-]+\.ngrok-free\.(app|dev)$/D', $hostname))) {
            throw new \InvalidArgumentException('Enter the ngrok authtoken and assigned ngrok-free.app or ngrok-free.dev hostname.');
        }

        $rootLiteral = $this->singleQuoted($root);
        $hostnameLiteral = $this->singleQuoted($hostname);
        $tokenLiteral = $this->singleQuoted($token);
        $serviceName = self::SERVICE_NAME;
        if ($mode === 'ngrok') {
            $serviceName = 'ngrok';
        }
        $legacyTaskName = self::LEGACY_TASK_NAME;

        $header = $mode === 'ngrok'
            ? "# RetailMind Remote Access setup - FREE ngrok assigned domain\n"
                . "# Paste into PowerShell as Administrator on the Store PC. Keep this token private.\n"
                . "# Free quota and browser warning apply. RetailMind sign-in is required.\n"
            : ($mode === 'permanent'
            ? "# RetailMind Remote Access setup - PERMANENT named Cloudflare Tunnel\n"
                . "# Paste this entire block into an elevated PowerShell on the Store PC.\n"
                . "# Safe to re-run: the service is stopped, deleted, and recreated.\n"
            : "# RetailMind Remote Access setup - INTERIM Quick Tunnel\n"
                . "# INTERIM: the public URL changes every time the service restarts.\n"
                . "# Cloudflare Access is not in front of this URL. RetailMind sign-in and\n"
                . "# each account's existing Workspace permissions still apply. Store Workspaces\n"
                . "# keep full tunnel capabilities; the Super Administrator stays local-only.\n"
                . "# Paste this entire block into an elevated PowerShell on the Store PC.\n"
                . "# Safe to re-run: the service is stopped, deleted, and recreated.\n");

        $installerArgs = "-Port $port -Mode " . ucfirst($mode) . ' -HealthPath ' . $this->singleQuoted($healthPath);
        if ($mode === 'permanent' || $mode === 'ngrok') {
            if ($hostname !== '') {
                $installerArgs .= " -Hostname $hostnameLiteral";
            }
            $installerArgs .= " -Token $tokenLiteral";
        }

        return $header . <<<POWERSHELL
& {
\$ErrorActionPreference = 'Stop'
\$serviceName = '{$serviceName}'
\$legacyTaskName = '{$legacyTaskName}'
\$appRoot = {$rootLiteral}
\$localPort = {$port}
\$installer = Join-Path \$appRoot 'src/backend/scripts/tunnel/service_setup.ps1'

# Pre-flight: elevated PowerShell
\$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not \$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Open PowerShell as Administrator, then paste this block again.' -ForegroundColor Red
    exit 1
}

# Pre-flight: 64-bit Windows and 64-bit PowerShell
if (-not [Environment]::Is64BitOperatingSystem -or -not [Environment]::Is64BitProcess) {
    Write-Host 'RetailMind Remote Access requires 64-bit Windows and 64-bit PowerShell.' -ForegroundColor Red
    exit 1
}

# Pre-flight: internet access
\$online = \$false
try {
    \$client = New-Object System.Net.Sockets.TcpClient
    \$online = \$client.ConnectAsync('1.1.1.1', 443).Wait(5000) -and \$client.Connected
    \$client.Close()
} catch {}
if (-not \$online) {
    Write-Host 'No internet access. Connect the Store PC to the internet, then paste this block again.' -ForegroundColor Red
    exit 1
}

# Pre-flight: local RetailMind origin
\$localUp = \$false
try {
    \$probe = New-Object System.Net.Sockets.TcpClient
    \$localUp = \$probe.ConnectAsync('127.0.0.1', \$localPort).Wait(3000) -and \$probe.Connected
    \$probe.Close()
} catch {}
if (-not \$localUp) {
    Write-Host "RetailMind is not reachable at http://127.0.0.1:\$localPort. Start Apache in XAMPP, then paste this block again." -ForegroundColor Red
    exit 1
}

# Report existing state instead of silently replacing it
if (Get-Service -Name \$serviceName -ErrorAction SilentlyContinue) {
    Write-Host "[INFO] Existing \$serviceName service found. It will be recreated." -ForegroundColor Yellow
}
if (Get-ScheduledTask -TaskName \$legacyTaskName -ErrorAction SilentlyContinue) {
    Write-Host "[INFO] Legacy Quick Tunnel scheduled task found. It will be removed." -ForegroundColor Yellow
}

# Install or repair the service through the existing RetailMind installer
& \$installer {$installerArgs}

# Print the resulting service state
sc.exe query \$serviceName
}
POWERSHELL;
    }

    /**
     * @param array{app_root: string, local_port?: int} $input
     */
    public function buildRemovalBlock(array $input): string
    {
        $root = $this->appRoot($input);
        $rootLiteral = $this->singleQuoted($root);
        $serviceName = self::SERVICE_NAME;
        $legacyTaskName = self::LEGACY_TASK_NAME;

        return <<<POWERSHELL
# RetailMind Remote Access removal - stops and deletes the tunnel service.
# Paste this entire block into an elevated PowerShell on the Store PC.
# The application, database, and backups are not deleted.

\$ErrorActionPreference = 'Stop'
\$serviceName = '{$serviceName}'
\$legacyTaskName = '{$legacyTaskName}'
\$appRoot = {$rootLiteral}
\$uninstaller = Join-Path \$appRoot 'scripts/tunnel/service_uninstall.ps1'

# Pre-flight: elevated PowerShell
\$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not \$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Open PowerShell as Administrator, then paste this block again.' -ForegroundColor Red
    exit 1
}

# Stop and delete the service, then remove the legacy scheduled task and state
& \$uninstaller -ServiceName \$serviceName -LegacyTaskName \$legacyTaskName

Write-Host 'Remote Access is removed. Local checkout and printing are unaffected.' -ForegroundColor Green
POWERSHELL;
    }

    public function mode(?string $token, string $requestedMode = ''): string
    {
        if (strtolower(trim($requestedMode)) === 'ngrok') {
            return 'ngrok';
        }
        if (trim((string)$token) === '') {
            return 'quick';
        }
        return strtolower(trim($requestedMode)) === 'quick' ? 'quick' : 'permanent';
    }

    private function appRoot(array $input): string
    {
        $root = rtrim(trim((string)($input['app_root'] ?? '')), "\\/");
        if ($root === '') {
            throw new \InvalidArgumentException('Application root is required.');
        }
        return $root;
    }

    private function localPort(array $input): int
    {
        $port = (int)($input['local_port'] ?? 80);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Local port must be between 1 and 65535.');
        }
        return $port;
    }

    private function singleQuoted(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
