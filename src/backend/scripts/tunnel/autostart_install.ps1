<#
.SYNOPSIS
    Installs an automatic background startup task for RetailMind Quick Tunnel (Option 1).
.DESCRIPTION
    Creates a Windows Scheduled Task (RetailMindQuickTunnel) that automatically
    launches the Cloudflare Quick Tunnel on Windows boot/logon without needing a paid domain.
    Also creates a "RetailMind Remote Link" shortcut on the Desktop.
#>

param (
    [string]$TaskName = "RetailMindQuickTunnel"
)

$ErrorActionPreference = "Stop"

$ScriptDir = $PSScriptRoot
$RootDir = (Resolve-Path "$ScriptDir\..\..\..\..").Path
$RunTunnelBat = Join-Path $RootDir "scripts\tunnel\run_tunnel.bat"

# Check Administrator privileges
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Warning "Setting up automatic Windows startup requires Administrator privileges."
    Write-Host "Please right-click and 'Run as Administrator'." -ForegroundColor Yellow
    exit 1
}

Write-Host "[INFO] Configuring automatic startup for RetailMind Quick Tunnel..." -ForegroundColor Cyan

# Remove old task if exists
try {
    & schtasks.exe /Delete /TN $TaskName /F 2>$null | Out-Null
} catch {}

# Register scheduled task to run at user logon with Highest privileges
$taskCmd = "cmd.exe /c `"$RunTunnelBat`" start"
$proc = Start-Process -FilePath "schtasks.exe" -ArgumentList @("/Create", "/TN", $TaskName, "/TR", "`"$taskCmd`"", "/SC", "ONLOGON", "/RL", "HIGHEST", "/F") -Wait -PassThru -NoNewWindow

if ($proc.ExitCode -ne 0) {
    Write-Error "Failed to register scheduled task with schtasks.exe (Exit code: $($proc.ExitCode))."
    exit $proc.ExitCode
}

# Create a Desktop helper shortcut to easily see and copy the link anytime
$desktopPath = [Environment]::GetFolderPath("Desktop")
if (Test-Path $desktopPath) {
    $desktopBat = Join-Path $desktopPath "RetailMind_Remote_Link.bat"
    $batScript = @"
@echo off
title RetailMind Remote Link
cd /d "$RootDir"
cls
call "$RunTunnelBat" status
echo.
echo ========================================================
echo  Tip: You can also copy the link inside RetailMind in
echo  Administrator -> Store Settings -> Remote Access.
echo ========================================================
echo.
pause
"@
    Set-Content -Path $desktopBat -Value $batScript -Force
    Write-Host "[INFO] Desktop shortcut created: $desktopBat" -ForegroundColor Green
}

# Start the tunnel now so it is active immediately
Write-Host "[INFO] Starting Quick Tunnel now..." -ForegroundColor Cyan
& "$RunTunnelBat" start

Write-Host "`n========================================================" -ForegroundColor Green
Write-Host "  QUICK TUNNEL AUTOSTART INSTALLED SUCCESSFULLY" -ForegroundColor Green
Write-Host "  * Mode: 100% Free Quick Tunnel (No domain needed)" -ForegroundColor White
Write-Host "  * Startup: Automatic on Windows logon" -ForegroundColor White
Write-Host "  * Link Checker: 'RetailMind_Remote_Link' on Desktop" -ForegroundColor White
Write-Host "========================================================`n" -ForegroundColor Green
