<#
.SYNOPSIS
    Single-command Windows Service uninstallation for RetailMind Remote Access.
.DESCRIPTION
    Stops and removes the RetailMind Cloudflare Tunnel Windows Service and the
    legacy Quick Tunnel scheduled task cleanly. Uses relative paths with zero
    machine coupling. The application, database, and backups are not deleted.
.PARAMETER ServiceName
    Windows Service identifier (default: RetailMindCloudflareTunnel).
.PARAMETER LegacyTaskName
    Legacy Quick Tunnel scheduled task to remove (default: RetailMindQuickTunnel).
.PARAMETER KeepConfig
    Preserve tunnel_config.json containing the saved token.
#>

param (
    [string]$ServiceName = "RetailMindCloudflareTunnel",
    [string]$LegacyTaskName = "RetailMindQuickTunnel",
    [switch]$KeepConfig
)

$ErrorActionPreference = "Stop"

# Zero machine coupling: resolve paths relative to script location
$ScriptDir = $PSScriptRoot
$RootDir = (Resolve-Path "$ScriptDir\..\..\..\..").Path
$RunDir = Join-Path $RootDir "src\backend\storage\tunnel"
$PidPath = Join-Path $RunDir "tunnel.pid"
$UrlPath = Join-Path $RunDir "tunnel.url"
$ConfigPath = Join-Path $RunDir "tunnel_config.json"

# Elevation check
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Warning "Windows Service removal requires Administrator privileges."
    Write-Host "Please run this script from an elevated PowerShell console ('Run as Administrator')." -ForegroundColor Yellow
    exit 1
}

$BinDir = Join-Path $RootDir 'src\backend\storage\bin'
foreach ($name in @($ServiceName, 'ngrok')) {
    $registered = Get-CimInstance Win32_Service -Filter "Name='$name'"
    if ($registered -and $registered.PathName.Contains($BinDir)) {
        Stop-Service -Name $name -Force -ErrorAction Stop
        sc.exe delete $name | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Could not remove the $name service." }
    } elseif ($registered -and $name -eq $ServiceName) {
        throw 'The registered Cloudflare service belongs to another installation; it was left unchanged.'
    }
}

Write-Host '[INFO] RetailMind tunnel services removed.' -ForegroundColor Cyan

Write-Host "[INFO] Removing legacy Quick Tunnel scheduled task '$LegacyTaskName'..." -ForegroundColor Cyan
if (Get-ScheduledTask -TaskName $LegacyTaskName -ErrorAction SilentlyContinue) {
    try { & schtasks.exe /Delete /TN $LegacyTaskName /F 2>$null | Out-Null } catch {}
    $legacyShortcut = Join-Path ([Environment]::GetFolderPath("Desktop")) "RetailMind_Remote_Link.bat"
    if (Test-Path $legacyShortcut) {
        Remove-Item -Path $legacyShortcut -Force -ErrorAction SilentlyContinue
    }
}

# Clean state
Remove-Item -Path $PidPath -Force -ErrorAction SilentlyContinue
Remove-Item -Path $UrlPath -Force -ErrorAction SilentlyContinue
foreach ($name in @('active_tunnel.json', 'health.json', 'ngrok.yml', 'cloudflare.token')) {
    Remove-Item -LiteralPath (Join-Path $RunDir $name) -Force -ErrorAction SilentlyContinue
}

if (-not $KeepConfig) {
    Remove-Item -Path $ConfigPath -Force -ErrorAction SilentlyContinue
}

Write-Host "`n========================================================" -ForegroundColor Green
Write-Host "  RETAILMIND TUNNEL SERVICE UNINSTALLED" -ForegroundColor Green
Write-Host "  Service $ServiceName stopped and removed." -ForegroundColor White
Write-Host "  Legacy Quick Tunnel task removed." -ForegroundColor White
Write-Host "========================================================`n" -ForegroundColor Green
