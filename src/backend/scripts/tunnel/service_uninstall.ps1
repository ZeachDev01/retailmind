<#
.SYNOPSIS
    Single-command Windows Service uninstallation for persistent Cloudflare Tunnel.
.DESCRIPTION
    Stops and removes the RetailMind Cloudflare Tunnel Windows Service cleanly.
    Uses relative paths with zero machine coupling.
.PARAMETER ServiceName
    Windows Service identifier (default: RetailMindCloudflareTunnel).
.PARAMETER KeepConfig
    Preserve tunnel_config.json containing saved token.
#>

param (
    [string]$ServiceName = "RetailMindCloudflareTunnel",
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

Write-Host "[INFO] Stopping Windows Service '$ServiceName'..." -ForegroundColor Cyan
try {
    sc.exe stop $ServiceName | Out-Null
    Start-Sleep -Seconds 1
} catch {}

Write-Host "[INFO] Deleting Windows Service '$ServiceName'..." -ForegroundColor Cyan
try {
    $deleteOut = sc.exe delete $ServiceName
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "sc.exe delete: $deleteOut"
    }
} catch {
    Write-Warning "Service was not registered or could not be removed."
}

# Clean state
Remove-Item -Path $PidPath -Force -ErrorAction SilentlyContinue
Remove-Item -Path $UrlPath -Force -ErrorAction SilentlyContinue

if (-not $KeepConfig) {
    Remove-Item -Path $ConfigPath -Force -ErrorAction SilentlyContinue
}

Write-Host "`n========================================================" -ForegroundColor Green
Write-Host "  RETAILMIND TUNNEL SERVICE UNINSTALLED" -ForegroundColor Green
Write-Host "  Service $ServiceName stopped and removed." -ForegroundColor White
Write-Host "========================================================`n" -ForegroundColor Green
