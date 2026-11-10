<#
.SYNOPSIS
    Single-command Windows Service installation for RetailMind Remote Access.
.DESCRIPTION
    Installs cloudflared as a Windows Service configured for automatic boot
    startup (start= auto) and automatic crash recovery via sc.exe failure
    restart actions. Supports a permanent named tunnel when a Cloudflare token
    is available, and an interim Quick Tunnel when it is not. Uses relative
    paths with zero machine coupling.
.PARAMETER Token
    Cloudflare Zero Trust permanent tunnel token. If omitted, reads from
    storage\tunnel\tunnel_config.json or .env unless -Mode Quick is used.
.PARAMETER Hostname
    Public hostname for the permanent named tunnel, for example
    supervisor.mystore.com.
.PARAMETER Port
    Local RetailMind web server port (default: 80).
.PARAMETER Mode
    Permanent, Quick, or Auto. Defaults to Permanent so a tokenless manual run
    still fails safely; the generated Quick Tunnel block passes -Mode Quick.
.PARAMETER ServiceName
    Windows Service identifier (default: RetailMindCloudflareTunnel).
.PARAMETER DisplayName
    Display name for Windows Services manager (default: RetailMind Cloudflare
    Remote Access Tunnel).
.PARAMETER LegacyTaskName
    Legacy Quick Tunnel scheduled task to remove so two tunnels never fight
    over the same route (default: RetailMindQuickTunnel).
#>

param (
    [string]$Token,
    [string]$Hostname,
    [int]$Port = 80,
    [ValidateSet("Permanent", "Quick", "Auto")]
    [string]$Mode = "Permanent",
    [string]$ServiceName = "RetailMindCloudflareTunnel",
    [string]$DisplayName = "RetailMind Cloudflare Remote Access Tunnel",
    [string]$LegacyTaskName = "RetailMindQuickTunnel"
)

$ErrorActionPreference = "Stop"

# Zero machine coupling: resolve paths relative to script location
$ScriptDir = $PSScriptRoot
$RootDir = (Resolve-Path "$ScriptDir\..\..\..\..").Path
$BinDir = Join-Path $RootDir "src\backend\storage\bin"
$RunDir = Join-Path $RootDir "src\backend\storage\tunnel"
$BinPath = Join-Path $BinDir "cloudflared.exe"
$ConfigPath = Join-Path $RunDir "tunnel_config.json"
$LogPath = Join-Path $RunDir "tunnel.log"
$UrlPath = Join-Path $RunDir "tunnel.url"

# Elevation check
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Warning "Windows Service registration requires Administrator privileges."
    Write-Host "Please run this script from an elevated PowerShell console ('Run as Administrator')." -ForegroundColor Yellow
    exit 1
}

# Ensure directories exist
if (-not (Test-Path $BinDir)) { New-Item -ItemType Directory -Path $BinDir -Force | Out-Null }
if (-not (Test-Path $RunDir)) { New-Item -ItemType Directory -Path $RunDir -Force | Out-Null }

# Token resolution (Quick mode never reads a saved or environment token)
$activeToken = ""
if ($Mode -ne "Quick") {
    $activeToken = $Token
    if (-not $activeToken -and (Test-Path $ConfigPath)) {
        try {
            $cfg = Get-Content -Path $ConfigPath -Raw | ConvertFrom-Json
            if ($cfg.token) {
                $activeToken = [string]$cfg.token
            }
        } catch {}
    }
    if (-not $activeToken -and $env:CLOUDFLARE_TUNNEL_TOKEN) {
        $activeToken = $env:CLOUDFLARE_TUNNEL_TOKEN
    }
}

if ($Mode -eq "Permanent" -and -not $activeToken) {
    Write-Error "Cloudflare Tunnel token is required for permanent mode. Save a token in Platform Settings, then regenerate the setup block."
    exit 1
}
if ($Mode -eq "Quick") {
    Write-Host "[INFO] Quick Tunnel mode: the public URL changes on every restart and Cloudflare Access is not in front of it." -ForegroundColor Yellow
    # Keep the application status card accurate: Quick mode has no permanent token.
    if (Test-Path $ConfigPath) {
        try {
            $quickConfig = Get-Content -Path $ConfigPath -Raw | ConvertFrom-Json
            $remaining = @{ updated_at = (Get-Date).ToString("yyyy-MM-ddTHH:mm:ssK") }
            if ($quickConfig.hostname) { $remaining.hostname = [string]$quickConfig.hostname }
            $remaining | ConvertTo-Json | Set-Content -Path $ConfigPath -Force
        } catch {}
    }
}

# Persist token and hostname through the existing tunnel configuration path
if ($activeToken) {
    $configObj = @{}
    if (Test-Path $ConfigPath) {
        try {
            $existing = Get-Content -Path $ConfigPath -Raw | ConvertFrom-Json
            if ($existing.hostname) { $configObj.hostname = [string]$existing.hostname }
        } catch {}
    }
    $configObj.token = $activeToken
    if ($Hostname) { $configObj.hostname = $Hostname }
    $configObj.updated_at = (Get-Date).ToString("yyyy-MM-ddTHH:mm:ssK")
    $configObj | ConvertTo-Json | Set-Content -Path $ConfigPath -Force
}

# Ensure portable binary is fetched
if (-not (Test-Path $BinPath) -or ((Get-Item $BinPath).Length -lt 1000000)) {
    Write-Host "[INFO] Fetching cloudflared executable into $BinDir..." -ForegroundColor Cyan
    & "$ScriptDir\run_tunnel.ps1" -Action fetch
    if (-not (Test-Path $BinPath)) {
        Write-Error "Failed to provision cloudflared.exe."
        exit 1
    }
}

# Remove the legacy Quick Tunnel scheduled task and desktop shortcut so
# the Windows Service is the only tunnel on this PC.
if (Get-ScheduledTask -TaskName $LegacyTaskName -ErrorAction SilentlyContinue) {
    Write-Host "[INFO] Removing legacy Quick Tunnel scheduled task '$LegacyTaskName'..." -ForegroundColor Gray
    try { & schtasks.exe /Delete /TN $LegacyTaskName /F 2>$null | Out-Null } catch {}
    $legacyShortcut = Join-Path ([Environment]::GetFolderPath("Desktop")) "RetailMind_Remote_Link.bat"
    if (Test-Path $legacyShortcut) {
        Remove-Item -Path $legacyShortcut -Force -ErrorAction SilentlyContinue
    }
}

Write-Host "[INFO] Registering Windows Service '$ServiceName'..." -ForegroundColor Cyan

# Stop and remove existing service if present
$existingService = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($existingService) {
    Write-Host "[INFO] Stopping and updating existing service..." -ForegroundColor Gray
    try { sc.exe stop $ServiceName | Out-Null } catch {}
    Start-Sleep -Seconds 1
    try { sc.exe delete $ServiceName | Out-Null } catch {}
    Start-Sleep -Seconds 1
}

# Create the service command for the selected mode.
# Note: sc.exe requires spaces after '='.
if ($activeToken) {
    $binCmd = "`"$BinPath`" --no-autoupdate run --token $activeToken --logfile `"$LogPath`""
} else {
    $binCmd = "`"$BinPath`" --no-autoupdate tunnel --url http://127.0.0.1:$Port --logfile `"$LogPath`""
}

$createOutput = sc.exe create $ServiceName binPath= "$binCmd" start= auto DisplayName= "$DisplayName"
if ($LASTEXITCODE -ne 0) {
    Write-Error "Failed to create service: $createOutput"
    exit $LASTEXITCODE
}

# Configure failure actions: restart service after 5s, 10s, 20s; reset fail counter after 1 day (86400s)
sc.exe failure $ServiceName reset= 86400 actions= restart/5000/restart/10000/restart/20000 | Out-Null
sc.exe failureflag $ServiceName 1 | Out-Null

# Set description
sc.exe description $ServiceName "Enables secure persistent remote supervisor access for RetailMind POS via Cloudflare Tunnel." | Out-Null

# Start the newly installed service
Write-Host "[INFO] Starting $ServiceName service..." -ForegroundColor Cyan
$logOffset = 0
if (Test-Path $LogPath) {
    $logOffset = ([string](Get-Content -Path $LogPath -Raw -ErrorAction SilentlyContinue)).Length
}
sc.exe start $ServiceName | Out-Null

if ($activeToken) {
    $publicHost = $Hostname
    if (-not $publicHost -and (Test-Path $ConfigPath)) {
        try {
            $cfg = Get-Content -Path $ConfigPath -Raw | ConvertFrom-Json
            if ($cfg.hostname) { $publicHost = [string]$cfg.hostname }
        } catch {}
    }
    if ($publicHost) {
        $publicUrl = if ($publicHost -match '^https?://') { $publicHost } else { "https://$publicHost" }
        Write-Host "Public hostname: $publicUrl" -ForegroundColor Green
    }
} else {
    Write-Host "[INFO] Waiting for the interim Quick Tunnel URL..." -ForegroundColor Gray
    $publicUrl = $null
    for ($i = 0; $i -lt 15; $i++) {
        Start-Sleep -Milliseconds 600
        if (Test-Path $LogPath) {
            $content = [string](Get-Content -Path $LogPath -Raw -ErrorAction SilentlyContinue)
            if ($content.Length -gt $logOffset) {
                $newContent = $content.Substring($logOffset)
                $urlMatches = [regex]::Matches($newContent, 'https:\/\/[a-zA-Z0-9.\-]+\.trycloudflare\.com')
                if ($urlMatches.Count -gt 0) {
                    $publicUrl = $urlMatches[$urlMatches.Count - 1].Value.TrimEnd('.')
                    break
                }
            }
        }
    }
    if ($publicUrl) {
        Set-Content -Path $UrlPath -Value $publicUrl -Force -ErrorAction SilentlyContinue
        Write-Host "Public Quick Tunnel URL: $publicUrl" -ForegroundColor Green
        Write-Host "This URL changes on every restart. Cloudflare Access is not in front of it." -ForegroundColor Yellow
    } else {
        Write-Host "[INFO] Service is running. The Quick Tunnel URL is still connecting; check $LogPath in a few seconds." -ForegroundColor Yellow
    }
}

Write-Host "`n========================================================" -ForegroundColor Green
Write-Host "  RETAILMIND TUNNEL SERVICE INSTALLED SUCCESSFULLY" -ForegroundColor Green
Write-Host "  Service:     $ServiceName" -ForegroundColor White
Write-Host "  Mode:        $(if ($activeToken) { 'Permanent named tunnel' } else { 'Interim Quick Tunnel' })" -ForegroundColor White
Write-Host "  Startup:     Automatic (start= auto)" -ForegroundColor White
Write-Host "  Recovery:    Auto-restart on crash (5s / 10s / 20s)" -ForegroundColor White
Write-Host "========================================================`n" -ForegroundColor Green
