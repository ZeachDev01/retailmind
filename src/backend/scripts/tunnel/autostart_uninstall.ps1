<#
.SYNOPSIS
    Uninstalls the automatic background startup task for RetailMind Quick Tunnel.
#>

param (
    [string]$TaskName = "RetailMindQuickTunnel"
)

$ErrorActionPreference = "Stop"

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Warning "Removing automatic Windows startup requires Administrator privileges."
    Write-Host "Please right-click and 'Run as Administrator'." -ForegroundColor Yellow
    exit 1
}

Write-Host "[INFO] Removing scheduled task '$TaskName'..." -ForegroundColor Cyan
try {
    & schtasks.exe /Delete /TN $TaskName /F 2>$null | Out-Null
    Write-Host "[SUCCESS] Scheduled task removed." -ForegroundColor Green
} catch {
    Write-Host "[INFO] Task was not found or already removed." -ForegroundColor Yellow
}

$desktopPath = [Environment]::GetFolderPath("Desktop")
if (Test-Path $desktopPath) {
    $desktopBat = Join-Path $desktopPath "RetailMind_Remote_Link.bat"
    if (Test-Path $desktopBat) {
        Remove-Item -Path $desktopBat -Force -ErrorAction SilentlyContinue
        Write-Host "[INFO] Removed Desktop shortcut." -ForegroundColor Gray
    }
}

Write-Host "[SUCCESS] Quick Tunnel autostart successfully removed." -ForegroundColor Green
