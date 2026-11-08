<#
.SYNOPSIS
    Single-command Windows Service installation for persistent Cloudflare Tunnel.
.DESCRIPTION
    Installs cloudflared as a Windows Service configured for automatic boot startup
    (start= auto) and automatic crash recovery via sc.exe failure restart actions.
    Uses relative paths with zero machine coupling.
.PARAMETER Token
    Cloudflare Zero Trust permanent tunnel token. If omitted, reads from storage\tunnel\tunnel_config.json or .env.
.PARAMETER ServiceName
    Windows Service identifier (default: RetailMindCloudflareTunnel).
.PARAMETER DisplayName
    Display name for Windows Services manager (default: RetailMind Cloudflare Remote Access Tunnel).
#>

param (
    [string]$Token,
    [string]$ServiceName = "RetailMindCloudflareTunnel",
    [string]$DisplayName = "RetailMind Cloudflare Remote Access Tunnel"
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

# Token resolution
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

if (-not $activeToken) {
    Write-Error "Cloudflare Tunnel token is required. Pass -Token <TOKEN> or configure it in Store Configuration."
    exit 1
}

# If token provided via parameter, persist to tunnel_config.json
if ($Token) {
    $configObj = @{
        token = $Token
        updated_at = (Get-Date).ToString("yyyy-MM-ddTHH:mm:ssK")
    }
    if (Test-Path $ConfigPath) {
        try {
            $existing = Get-Content -Path $ConfigPath -Raw | ConvertFrom-Json
            if ($existing.hostname) { $configObj.hostname = $existing.hostname }
        } catch {}
    }
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

# Create service with sc.exe with automatic boot startup (start= auto)
# Note: sc.exe requires spaces after '='
$binCmd = "`"$BinPath`" --no-autoupdate run --token $activeToken"
$createOutput = sc.exe create $ServiceName binPath= "$binCmd" start= auto DisplayName= "$DisplayName"
if ($LASTEXITCODE -ne 0) {
    Write-Error "Failed to create service: $createOutput"
    exit $LASTEXITCODE
}

# Configure failure actions: restart service after 5s, 10s, 20s; reset fail counter after 1 day (86400s)
sc.exe failure $ServiceName reset= 86400 actions= restart/5000/restart/10000/restart/20000 | Out-Null
sc.exe failureflag $ServiceName 1 | Out-Null

# Set description
sc.exe description $ServiceName "Enables secure persistent remote supervisor access for RetailMind POS via Cloudflare Named Tunnel." | Out-Null

# Start the newly installed service
Write-Host "[INFO] Starting $ServiceName service..." -ForegroundColor Cyan
sc.exe start $ServiceName | Out-Null

Write-Host "`n========================================================" -ForegroundColor Green
Write-Host "  RETAILMIND TUNNEL SERVICE INSTALLED SUCCESSFULLY" -ForegroundColor Green
Write-Host "  Service:     $ServiceName" -ForegroundColor White
Write-Host "  Startup:     Automatic (start= auto)" -ForegroundColor White
Write-Host "  Recovery:    Auto-restart on crash (5s / 10s / 20s)" -ForegroundColor White
Write-Host "========================================================`n" -ForegroundColor Green
