<#
.SYNOPSIS
    Portable Cloudflare Tunnel runner for RetailMind supervisor remote access.
.DESCRIPTION
    Zero machine coupling runner that checks local server availability,
    auto-fetches the portable cloudflared binary, and manages daemon execution.
.PARAMETER Action
    start, stop, status, restart, or fetch (default: start)
.PARAMETER Port
    Local web server port (default: 80)
.PARAMETER HostName
    Local web server host (default: 127.0.0.1)
.PARAMETER Foreground
    Run directly in current console instead of detached background process
#>

param (
    [ValidateSet("start", "stop", "status", "restart", "fetch")]
    [string]$Action = "start",
    [int]$Port = 80,
    [string]$HostName = "127.0.0.1",
    [switch]$Foreground
)

$ErrorActionPreference = "Stop"

# Zero machine coupling: resolve paths relative to script location
$ScriptDir = $PSScriptRoot
$RootDir = (Resolve-Path "$ScriptDir\..\..\..\..").Path
$BinDir = Join-Path $RootDir "src\backend\storage\bin"
$RunDir = Join-Path $RootDir "src\backend\storage\tunnel"
$BinPath = Join-Path $BinDir "cloudflared.exe"
$PidPath = Join-Path $RunDir "tunnel.pid"
$LogPath = Join-Path $RunDir "tunnel.log"
$UrlPath = Join-Path $RunDir "tunnel.url"

function Ensure-Directories {
    if (-not (Test-Path $BinDir)) { New-Item -ItemType Directory -Path $BinDir -Force | Out-Null }
    if (-not (Test-Path $RunDir)) { New-Item -ItemType Directory -Path $RunDir -Force | Out-Null }
}

function Test-WebServer {
    param([string]$TargetHost, [int]$TargetPort)
    try {
        $tcp = New-Object System.Net.Sockets.TcpClient
        $iar = $tcp.BeginConnect($TargetHost, $TargetPort, $null, $null)
        $wait = $iar.AsyncWaitHandle.WaitOne(1000, $false)
        if ($wait -and $tcp.Connected) {
            $tcp.EndConnect($iar)
            $tcp.Close()
            return $true
        }
        $tcp.Close()
        return $false
    } catch {
        return $false
    }
}

function Ensure-Cloudflared {
    Ensure-Directories
    if (Test-Path $BinPath) {
        $fileInfo = Get-Item $BinPath
        if ($fileInfo.Length -gt 1000000) {
            return $true
        }
    }

    Write-Host "[INFO] Fetching portable cloudflared executable into $BinDir..." -ForegroundColor Cyan
    $downloadUrl = "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe"
    $tempBin = "$BinPath.tmp.$([System.Guid]::NewGuid().ToString('N'))"

    try {
        [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12 -bor [System.Net.SecurityProtocolType]::Tls13
        $prevProgress = $ProgressPreference
        $ProgressPreference = 'SilentlyContinue'
        Invoke-WebRequest -Uri $downloadUrl -OutFile $tempBin -UseBasicParsing -TimeoutSec 120
        $ProgressPreference = $prevProgress

        if ((Test-Path $tempBin) -and ((Get-Item $tempBin).Length -gt 1000000)) {
            Move-Item -Path $tempBin -Destination $BinPath -Force
            Write-Host "[SUCCESS] Portable cloudflared binary ready: $BinPath" -ForegroundColor Green
            return $true
        } else {
            Remove-Item -Path $tempBin -Force -ErrorAction SilentlyContinue
            Write-Error "Downloaded binary was invalid or empty."
            return $false
        }
    } catch {
        Remove-Item -Path $tempBin -Force -ErrorAction SilentlyContinue
        Write-Error "Failed to download cloudflared: $_"
        return $false
    }
}

function Get-RunningPid {
    if (-not (Test-Path $PidPath)) { return $null }
    $raw = (Get-Content -Path $PidPath -Raw).Trim()
    if ($raw -match '^\d+$') {
        $id = [int]$raw
        $proc = Get-Process -Id $id -ErrorAction SilentlyContinue
        if ($proc) { return $id }
    }
    Remove-Item -Path $PidPath -Force -ErrorAction SilentlyContinue
    Remove-Item -Path $UrlPath -Force -ErrorAction SilentlyContinue
    return $null
}

function Extract-PublicUrl {
    if (-not (Test-Path $LogPath)) { return $null }
    $content = Get-Content -Path $LogPath -Raw -ErrorAction SilentlyContinue
    if ($content -match 'https:\/\/[a-zA-Z0-9.\-]+\.trycloudflare\.com') {
        $url = $matches[0].TrimEnd('.')
        Set-Content -Path $UrlPath -Value $url -Force -ErrorAction SilentlyContinue
        return $url
    }
    if (Test-Path $UrlPath) {
        return (Get-Content -Path $UrlPath -Raw -ErrorAction SilentlyContinue).Trim()
    }
    return $null
}

function Start-Tunnel {
    Ensure-Directories
    $activePid = Get-RunningPid
    if ($activePid) {
        $url = Extract-PublicUrl
        Write-Host "[STATUS] Tunnel is already running (PID: $activePid)." -ForegroundColor Yellow
        if ($url) {
            Write-Host "Active URL: $url" -ForegroundColor Green
        }
        return
    }

    $serverOk = Test-WebServer -TargetHost $HostName -TargetPort $Port
    if (-not $serverOk) {
        Write-Host "[WARN] Local server is not responding on http://${HostName}:${Port}." -ForegroundColor Yellow
        Write-Host "       Verify your web server (Apache/PHP) is active before accepting remote traffic." -ForegroundColor Gray
    }

    Ensure-Cloudflared | Out-Null
    if (-not (Test-Path $BinPath)) {
        Write-Error "Cannot start tunnel: cloudflared.exe binary is missing."
        return
    }

    # Reset log and URL files
    Set-Content -Path $LogPath -Value "" -Force
    Remove-Item -Path $UrlPath -Force -ErrorAction SilentlyContinue

    $localUrl = "http://${HostName}:${Port}"
    $argsList = @("tunnel", "--no-autoupdate", "--url", $localUrl, "--logfile", $LogPath)

    if ($Foreground) {
        Write-Host "[INFO] Starting cloudflared tunnel in foreground for $localUrl..." -ForegroundColor Cyan
        & $BinPath @argsList
        return
    }

    Write-Host "[INFO] Launching cloudflared tunnel daemon for $localUrl..." -ForegroundColor Cyan
    $proc = Start-Process -FilePath $BinPath -ArgumentList $argsList -PassThru -WindowStyle Hidden
    if (-not $proc -or -not $proc.Id) {
        Write-Error "Failed to launch cloudflared process."
        return
    }

    Set-Content -Path $PidPath -Value $proc.Id -Force
    Write-Host "[SUCCESS] Tunnel daemon launched with PID: $($proc.Id)" -ForegroundColor Green

    Write-Host "[INFO] Waiting for public tunnel URL..." -ForegroundColor Gray
    $foundUrl = $null
    for ($i = 0; $i -lt 15; $i++) {
        Start-Sleep -Milliseconds 600
        $foundUrl = Extract-PublicUrl
        if ($foundUrl) { break }
        if (-not (Get-Process -Id $proc.Id -ErrorAction SilentlyContinue)) {
            Write-Host "[ERROR] Tunnel process exited unexpectedly. Check $LogPath." -ForegroundColor Red
            break
        }
    }

    if ($foundUrl) {
        Write-Host "`n========================================================" -ForegroundColor Green
        Write-Host "  RETAILMIND REMOTE ACCESS IS ONLINE" -ForegroundColor Green
        Write-Host "  URL: $foundUrl" -ForegroundColor Cyan
        Write-Host "========================================================`n" -ForegroundColor Green
    } else {
        Write-Host "[INFO] Daemon is running. Public URL is still connecting. Check status in a few seconds." -ForegroundColor Yellow
    }
}

function Stop-Tunnel {
    $activePid = Get-RunningPid
    if ($activePid) {
        Write-Host "[INFO] Stopping tunnel daemon (PID: $activePid)..." -ForegroundColor Cyan
        Stop-Process -Id $activePid -Force -ErrorAction SilentlyContinue
    } else {
        Write-Host "[INFO] Tunnel daemon is not currently running." -ForegroundColor Yellow
    }

    Remove-Item -Path $PidPath -Force -ErrorAction SilentlyContinue
    Remove-Item -Path $UrlPath -Force -ErrorAction SilentlyContinue
    Write-Host "[SUCCESS] Tunnel stopped and state cleaned." -ForegroundColor Green
}

function Show-Status {
    $activePid = Get-RunningPid
    $url = Extract-PublicUrl
    $installed = (Test-Path $BinPath)
    $serverOk = Test-WebServer -TargetHost $HostName -TargetPort $Port

    Write-Host "`n--- Cloudflare Tunnel Status ---" -ForegroundColor Cyan
    Write-Host "Binary installed:  $installed ($BinPath)"
    Write-Host "Local web server:  $(if ($serverOk) { 'Available' } else { 'Unavailable' }) (http://${HostName}:${Port})"
    Write-Host "Tunnel state:      $(if ($activePid) { 'ONLINE (PID ' + $activePid + ')' } else { 'OFFLINE' })"
    if ($url) {
        Write-Host "Public URL:        $url" -ForegroundColor Green
    }
    Write-Host "Log file:          $LogPath`n"
}

switch ($Action) {
    "fetch" {
        Ensure-Cloudflared
    }
    "start" {
        Start-Tunnel
    }
    "stop" {
        Stop-Tunnel
    }
    "restart" {
        Stop-Tunnel
        Start-Sleep -Seconds 1
        Start-Tunnel
    }
    "status" {
        Show-Status
    }
}
