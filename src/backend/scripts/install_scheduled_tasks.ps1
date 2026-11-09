<#
.SYNOPSIS
    One-click setup for RetailMind Windows Scheduled Tasks and daily background services.
.DESCRIPTION
    Installs and configures:
    1. RetailMind ML Scheduler (runs every 5 minutes for background forecasting & model jobs)
    2. RetailMind Daily Notifications (runs daily at 3:00 AM for the Daily Stock Brief & expiry alerts)
    3. RetailMind Weekly Backup (checks hourly and creates the week's verified Scheduled Backup)
    4. Enables store email alerts in the database
#>

[CmdletBinding(SupportsShouldProcess)]
param(
    [string]$PhpPath,
    [string]$PythonPath,
    [string]$ProjectRoot,
    [string]$ServiceAccount = 'NT AUTHORITY\SYSTEM',
    [switch]$TestRun,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "   RetailMind Windows Background Services Installer     " -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host ""

# 1. Resolve project root
if ($ProjectRoot) {
    $projectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
} else {
    $projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
}

Write-Host "[1/5] Project root: $projectRoot" -ForegroundColor Gray

# Ensure log and config directories exist
$logDir = Join-Path $projectRoot 'src/backend/storage/logs'
$configDir = Join-Path $projectRoot 'src/backend/config'
New-Item -ItemType Directory -Path $logDir -Force | Out-Null
New-Item -ItemType Directory -Path $configDir -Force | Out-Null

# 2. Resolve PHP binary
if ($PhpPath) {
    $phpBinary = (Resolve-Path -LiteralPath $PhpPath).Path
} else {
    $candidatePhps = @(
        'C:\xampp\php\php.exe',
        (Join-Path $env:SystemDrive 'xampp\php\php.exe')
    )
    $foundPhp = $null
    foreach ($cand in $candidatePhps) {
        if (Test-Path -LiteralPath $cand) {
            $foundPhp = (Resolve-Path -LiteralPath $cand).Path
            break
        }
    }
    if (-not $foundPhp) {
        $phpCmd = Get-Command php.exe -ErrorAction SilentlyContinue
        if ($phpCmd) { $foundPhp = $phpCmd.Source }
    }
    if (-not $foundPhp) {
        throw "PHP executable not found. Please install XAMPP or specify -PhpPath."
    }
    $phpBinary = $foundPhp
}

Write-Host "[2/5] PHP Binary: $phpBinary" -ForegroundColor Green

# 3. Resolve Python binary (optional for ML features)
$pythonBinary = $null
if ($PythonPath) {
    $pythonBinary = (Resolve-Path -LiteralPath $PythonPath).Path
} else {
    $candidatePythons = @(
        (Join-Path $projectRoot 'venv/Scripts/python.exe'),
        (Join-Path $projectRoot '.venv/Scripts/python.exe'),
        'C:\xampp\python\python.exe',
        'C:\Python314\python.exe',
        'C:\Python312\python.exe',
        'C:\Python311\python.exe'
    )
    foreach ($cand in $candidatePythons) {
        if (Test-Path -LiteralPath $cand) {
            $pythonBinary = (Resolve-Path -LiteralPath $cand).Path
            break
        }
    }
    if (-not $pythonBinary) {
        $pyCmd = Get-Command python.exe -ErrorAction SilentlyContinue
        if ($pyCmd) { $pythonBinary = $pyCmd.Source }
    }
}
if ($pythonBinary) {
    Write-Host "[3/5] Python Binary: $pythonBinary" -ForegroundColor Green
} else {
    Write-Host "[3/5] Python Binary: (Not detected; core notifications remain functional)" -ForegroundColor Yellow
}

# 4. Enable store email alerts in database
Write-Host "[4/5] Verifying database and activating Store Email Alerts..." -ForegroundColor Gray
$enableAlertsPhp = "require 'src/backend/includes/auth.php'; save_key_value_settings(`$pdo, 'store_settings', ['store_email_alerts_enabled' => '1'], 1); `$st = get_store_settings(`$pdo); echo 'STORE_EMAIL:' . (`$st['store_email'] ?? '') . '|ALERTS:' . (`$st['store_email_alerts_enabled'] ?? '');"
$dbStatus = & $phpBinary -d display_errors=0 -r $enableAlertsPhp 2>$null
if ($LASTEXITCODE -eq 0 -and $dbStatus -match 'ALERTS:1') {
    Write-Host "      ✓ Database connected and store_email_alerts_enabled set to 1." -ForegroundColor Green
} else {
    Write-Host "      ⚠ Could not update store settings via PHP. Ensure MySQL is running in XAMPP." -ForegroundColor Yellow
}

# 5. Register Windows Scheduled Tasks
Write-Host "[5/5] Registering Windows Scheduled Tasks..." -ForegroundColor Gray

$powershellBinary = Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe'
$isSystemAccount = ($ServiceAccount -in @('NT AUTHORITY\SYSTEM', 'SYSTEM'))
if ($isSystemAccount) {
    $principal = New-ScheduledTaskPrincipal -UserId 'NT AUTHORITY\SYSTEM' -LogonType ServiceAccount -RunLevel Highest
} else {
    $principal = New-ScheduledTaskPrincipal -UserId $ServiceAccount -LogonType ServiceAccount -RunLevel Limited
}

# Task A: RetailMind ML Scheduler (Every 5 minutes + At Startup)
$mlTaskName = 'RetailMind ML Scheduler'
$mlScript = Join-Path $projectRoot 'src/backend/scripts/install_forecasting_scheduler.ps1'
if (Test-Path -LiteralPath $mlScript) {
    if (Get-ScheduledTask -TaskName $mlTaskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $mlTaskName -Confirm:$false
    }
    $mlAction = New-ScheduledTaskAction -Execute $powershellBinary -Argument (
        '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -Run' -f $mlScript
    ) -WorkingDirectory $projectRoot
    $mlStartupTrigger = New-ScheduledTaskTrigger -AtStartup
    $mlRepeatTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)
    $mlSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

    Register-ScheduledTask -TaskName $mlTaskName -Action $mlAction -Trigger @($mlStartupTrigger, $mlRepeatTrigger) `
        -Settings $mlSettings -Principal $principal `
        -Description 'RetailMind background forecasting scheduler (runs every 5 minutes non-interactively).' | Out-Null
    Write-Host "      ✓ Registered task: $mlTaskName (Every 5 mins)" -ForegroundColor Green
}

# Task B: RetailMind Daily Notifications & Stock Brief (Daily at 3:00 AM Asia/Manila)
$dailyTaskName = 'RetailMind Daily Notifications'
if (Get-ScheduledTask -TaskName $dailyTaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $dailyTaskName -Confirm:$false
}

$notifScript = Join-Path $projectRoot 'src/backend/scripts/run_notifications.php'
$notifLog = Join-Path $logDir 'daily-notifications.log'
$dailyCommand = "& '{0}' '{1}' *>> '{2}'" -f $phpBinary, $notifScript, $notifLog
$dailyAction = New-ScheduledTaskAction -Execute $powershellBinary -Argument (
    '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -Command "{0}"' -f $dailyCommand
) -WorkingDirectory $projectRoot

# Daily trigger at 3:00 AM
$dailyTrigger = New-ScheduledTaskTrigger -Daily -At "03:00"
$dailySettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

Register-ScheduledTask -TaskName $dailyTaskName -Action $dailyAction -Trigger $dailyTrigger `
    -Settings $dailySettings -Principal $principal `
    -Description 'RetailMind Daily Stock Brief, inventory alerts, and batch expiry reminders (daily at 3:00 AM; runs on startup if missed).' | Out-Null

Write-Host "      ✓ Registered task: $dailyTaskName (Daily at 03:00 AM, Catch-up enabled)" -ForegroundColor Green

# Task C: RetailMind Weekly Backup (#460). Hourly because the weekday/time live
# in Platform Settings, so saving a new schedule never rewrites the task.
$backupTaskName = 'RetailMind Weekly Backup'
if (Get-ScheduledTask -TaskName $backupTaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $backupTaskName -Confirm:$false
}
$backupScript = Join-Path $projectRoot 'src/backend/scripts/run_scheduled_backup.php'
$backupLog = Join-Path $logDir 'weekly-backup.log'
$backupCommand = "& '{0}' '{1}' *>> '{2}'" -f $phpBinary, $backupScript, $backupLog
$backupAction = New-ScheduledTaskAction -Execute $powershellBinary -Argument (
    '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -Command "{0}"' -f $backupCommand
) -WorkingDirectory $projectRoot
$backupStartup = New-ScheduledTaskTrigger -AtStartup
$backupRepeater = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) -RepetitionInterval (New-TimeSpan -Hours 1)
$backupSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

Register-ScheduledTask -TaskName $backupTaskName -Action $backupAction -Trigger @($backupStartup, $backupRepeater) `
    -Settings $backupSettings -Principal $principal `
    -Description 'RetailMind weekly Scheduled Backup; checks hourly and creates one verified package when the week is due (Settings control the weekday/time).' | Out-Null

Write-Host "      ✓ Registered task: $backupTaskName (Hourly check, startup catch-up)" -ForegroundColor Green

# Optional Test Run
if ($TestRun) {
    Write-Host ""
    Write-Host "Executing immediate test run of Daily Notifications..." -ForegroundColor Cyan
    Push-Location -LiteralPath $projectRoot
    try {
        & $phpBinary $notifScript
    } finally {
        Pop-Location
    }
}

Write-Host ""
Write-Host "========================================================" -ForegroundColor Green
Write-Host "  SETUP COMPLETE! All scheduled tasks are active.       " -ForegroundColor Green
Write-Host "========================================================" -ForegroundColor Green
Write-Host "Tasks configured:"
Write-Host "  1. '$mlTaskName'           -> Runs every 5 minutes"
Write-Host "  2. '$dailyTaskName'  -> Runs daily at 3:00 AM (runs upon wake if missed)"
Write-Host "  3. '$backupTaskName'  -> Checks hourly; runs the configured weekly backup (runs upon wake if missed)"
Write-Host "Logs are stored in: $logDir"
Write-Host "Weekly backup log: $backupLog"
Write-Host ""
