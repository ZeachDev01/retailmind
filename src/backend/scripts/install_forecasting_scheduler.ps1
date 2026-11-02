[CmdletBinding(SupportsShouldProcess)]
param(
    [switch]$Run,
    [string]$PhpPath,
    [string]$PythonPath,
    [string]$ProjectRoot,
    [string]$ModelStoragePath,
    [string]$ServiceAccount = 'NT AUTHORITY\SYSTEM',
    [string]$TaskName = 'RetailMind ML Scheduler',
    [switch]$Force,
    [switch]$SkipTaskRegistration,
    [switch]$Sync
)

$ErrorActionPreference = 'Stop'

# 1. Resolve project root
if ($ProjectRoot) {
    $projectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
} else {
    $projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
}

# Verify path is on a local fixed drive (no mapped network drives)
$driveRoot = [System.IO.Path]::GetPathRoot($projectRoot).TrimEnd('\')
if ($driveRoot -match '^[A-Za-z]:$') {
    $driveInfo = [System.IO.DriveInfo]::new($driveRoot)
    if ($driveInfo.DriveType -eq [System.IO.DriveType]::Network) {
        throw "Project root '$projectRoot' is on a mapped network drive. Forecasting background services require a local fixed drive."
    }
}

$configDirectory = Join-Path $projectRoot 'src/backend/config'
$configPath = Join-Path $configDirectory 'forecasting_runner.json'
$logDirectory = Join-Path $projectRoot 'src/backend/storage/logs'
$schedulerScript = Join-Path $PSScriptRoot 'run_scheduler.php'

# When executed by Task Scheduler (-Run)
if ($Run) {
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null

    # Resolve PHP from retained configuration if available
    $phpBinary = $null
    if (Test-Path -LiteralPath $configPath) {
        try {
            $runnerConfig = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
            if ($runnerConfig.php_binary -and (Test-Path -LiteralPath $runnerConfig.php_binary)) {
                $phpBinary = $runnerConfig.php_binary
            }
        } catch {
            Write-Warning "Could not read forecasting_runner.json: $_"
        }
    }
    if (-not $phpBinary) {
        $phpBinary = (Get-Command php.exe -ErrorAction Stop).Source
    }

    # Ensure required local MySQL service is running if present
    $mysqlService = Get-Service -Name mysql -ErrorAction SilentlyContinue
    if ($null -ne $mysqlService -and $mysqlService.Status -ne 'Running') {
        try {
            Start-Service -Name mysql -ErrorAction SilentlyContinue
        } catch {
            Write-Warning "Could not start MySQL service: $_"
        }
    }

    Push-Location -LiteralPath $projectRoot
    try {
        $ErrorActionPreference = 'Continue'
        $scriptArgs = @('-f', $schedulerScript)
        if ($Sync) { $scriptArgs += '--sync' }
        & $phpBinary @scriptArgs *>> (Join-Path $logDirectory 'forecasting-scheduler.log')
        $schedulerResult = $LASTEXITCODE
        if ($null -eq $schedulerResult) { exit 1 }
        exit $schedulerResult
    } finally {
        Pop-Location
    }
}

# --- Installation & Configuration Phase ---

# Resolve PHP executable
if ($PhpPath) {
    $phpBinary = (Resolve-Path -LiteralPath $PhpPath).Path
} else {
    $phpCmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($phpCmd) {
        $phpBinary = $phpCmd.Source
    } elseif (Test-Path 'C:\xampp\php\php.exe') {
        $phpBinary = 'C:\xampp\php\php.exe'
    } else {
        throw "PHP executable not found. Specify -PhpPath or install PHP in standard XAMPP location."
    }
}

# Resolve Python environment
if ($PythonPath) {
    $pythonBinary = (Resolve-Path -LiteralPath $PythonPath).Path
} else {
    $candidatePythons = @(
        (Join-Path $projectRoot 'venv/Scripts/python.exe'),
        (Join-Path $projectRoot '.venv/Scripts/python.exe'),
        'C:\xampp\python\python.exe'
    )
    $foundPython = $null
    foreach ($cand in $candidatePythons) {
        if (Test-Path -LiteralPath $cand) {
            $foundPython = (Resolve-Path -LiteralPath $cand).Path
            break
        }
    }
    if (-not $foundPython) {
        $pyCmd = Get-Command python.exe -ErrorAction SilentlyContinue
        if ($pyCmd) {
            $foundPython = $pyCmd.Source
        }
    }
    if (-not $foundPython) {
        throw "Python executable not found. Specify -PythonPath or install Python."
    }
    $pythonBinary = $foundPython
}

# Resolve Model Storage path
if ($ModelStoragePath) {
    $modelStorage = (Resolve-Path -LiteralPath $ModelStoragePath).Path
} else {
    $modelStorage = Join-Path $projectRoot 'src/backend/storage/ml_models'
}

# Validate that all paths reside on local fixed drives
foreach ($targetPath in @($phpBinary, $pythonBinary, $modelStorage)) {
    $tDrive = [System.IO.Path]::GetPathRoot($targetPath).TrimEnd('\')
    if ($tDrive -match '^[A-Za-z]:$') {
        $dInfo = [System.IO.DriveInfo]::new($tDrive)
        if ($dInfo.DriveType -eq [System.IO.DriveType]::Network) {
            throw "Path '$targetPath' is on a mapped network drive. All runtime paths must use local fixed drives."
        }
    }
}

# Ensure directories exist
New-Item -ItemType Directory -Path $configDirectory -Force | Out-Null
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
New-Item -ItemType Directory -Path $modelStorage -Force | Out-Null

# Retain resolved paths and local service configuration
$runnerConfig = [ordered]@{
    php_binary         = $phpBinary
    python_binary      = $pythonBinary
    project_root       = $projectRoot
    model_storage      = $modelStorage
    service_account    = $ServiceAccount
    installed_at       = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    effective_schedule = [ordered]@{
        daily_forecast_time = '01:00'
        weekly_retrain_day  = 'Sunday'
        weekly_retrain_time = '01:00'
        timezone            = 'Asia/Manila'
    }
}
$runnerConfig | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $configPath -Encoding UTF8
Write-Output "Retained forecasting runner configuration in $configPath"

# Ensure MySQL local service is set to Automatic startup if installed
$mysqlService = Get-Service -Name mysql -ErrorAction SilentlyContinue
if ($null -ne $mysqlService) {
    try {
        if ($mysqlService.StartType -ne 'Automatic') {
            Set-Service -Name mysql -StartupType Automatic -ErrorAction SilentlyContinue
            Write-Output "Configured MySQL service startup type to Automatic."
        }
        if ($mysqlService.Status -ne 'Running') {
            Start-Service -Name mysql -ErrorAction SilentlyContinue
            Write-Output "Started local MySQL service."
        }
    } catch {
        Write-Warning "Could not configure local MySQL service: $_"
    }
}

if ($SkipTaskRegistration) {
    Write-Output "Skipping Windows Scheduled Task registration as requested."
    return
}

# Windows Scheduled Task registration (runs without interactive sign-in)
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    if ($Force) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Output "Removed existing task '$TaskName' for re-registration."
    } else {
        throw "Task '$TaskName' already exists. Use -Force to overwrite or review in Task Scheduler."
    }
}

$powershellBinary = Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe'
$action = New-ScheduledTaskAction -Execute $powershellBinary -Argument (
    '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -Run' -f $PSCommandPath
) -WorkingDirectory $projectRoot

# AtStartup trigger ensures execution after reboot without requiring sign-in;
# Repeating trigger ensures execution every 5 minutes.
$startupTrigger = New-ScheduledTaskTrigger -AtStartup
$repeatTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)

$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

$isSystemAccount = ($ServiceAccount -in @('NT AUTHORITY\SYSTEM', 'SYSTEM'))
if ($isSystemAccount) {
    $principal = New-ScheduledTaskPrincipal -UserId 'NT AUTHORITY\SYSTEM' -LogonType ServiceAccount -RunLevel Highest
} else {
    $principal = New-ScheduledTaskPrincipal -UserId $ServiceAccount -LogonType ServiceAccount -RunLevel Limited
}

if ($PSCmdlet.ShouldProcess($TaskName, "Register forecasting task under $ServiceAccount (non-interactive)")) {
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startupTrigger, $repeatTrigger) `
        -Settings $settings -Principal $principal `
        -Description 'Checks forecasting schedules and usable history; trains candidates without approving them (runs non-interactively without Windows sign-in).' | Out-Null
    Get-ScheduledTask -TaskName $TaskName | Select-Object TaskName, State
}
