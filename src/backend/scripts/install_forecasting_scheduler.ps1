[CmdletBinding(SupportsShouldProcess)]
param(
    [string]$ProjectRoot,
    [string]$PhpPath,
    [string]$PythonPath,
    [string]$ModelStoragePath,
    [string]$ServiceAccount = 'NT AUTHORITY\SYSTEM',
    [string]$TaskName = 'RetailMind ML Scheduler',
    [switch]$SkipTaskRegistration,
    [switch]$Run,
    [switch]$Force
)
$ErrorActionPreference = 'Stop'
if (-not $ProjectRoot) { $ProjectRoot = Join-Path $PSScriptRoot '../../..' }
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$configPath = Join-Path $ProjectRoot 'src/backend/config/forecasting_runner.json'
$logDir = Join-Path $ProjectRoot 'src/backend/storage/logs'

if ($Run) {
    try {
        $config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
        if ($config.project_root -ne $ProjectRoot) { throw 'Runner configuration belongs to another project. Reinstall the forecasting task.' }
        $env:PATH = (Split-Path -Parent $config.php_binary) + ';' + $env:PATH
        Push-Location -LiteralPath $ProjectRoot
        try {
            $ErrorActionPreference = 'Continue'
            & $config.php_binary (Join-Path $ProjectRoot 'src/backend/scripts/run_scheduler.php') --sync *>> (Join-Path $logDir 'forecasting-scheduler.log')
            $runnerExit = $LASTEXITCODE
        } finally { $ErrorActionPreference = 'Stop'; Pop-Location }
        exit $runnerExit
    } catch {
        if (Test-Path -LiteralPath $logDir) {
            $failure = @{ checked_at = [TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Singapore Standard Time').ToString('yyyy-MM-dd HH:mm:ss'); pending_jobs = 0; diagnostic_reference = 'FORECAST_RUNNER_STARTUP' }
            [IO.File]::WriteAllText((Join-Path $logDir 'forecasting_scheduler_health.json'), ($failure | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
        }
        [Console]::Error.WriteLine('FORECAST_RUNNER_STARTUP: ' + $_.Exception.Message + ' Check forecasting_runner.json and forecasting-scheduler.log.')
        exit 1
    }
}

if (-not $PhpPath) { $PhpPath = (Get-Command php.exe -ErrorAction Stop).Source }
if (-not $PythonPath) { $PythonPath = (Get-Command python.exe -ErrorAction Stop).Source }
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
$PythonPath = (Resolve-Path -LiteralPath $PythonPath).Path
if (-not $ModelStoragePath) { $ModelStoragePath = Join-Path $ProjectRoot 'src/backend/storage/ml_models' }
$ModelStoragePath = [IO.Path]::GetFullPath($ModelStoragePath)
foreach ($path in @($ProjectRoot, $PhpPath, $PythonPath, $ModelStoragePath)) {
    if ($path.StartsWith('\\')) { throw 'Forecasting requires local fixed paths, not a network share.' }
    $drive = [IO.DriveInfo]::new([IO.Path]::GetPathRoot($path))
    if ($drive.DriveType -eq [IO.DriveType]::Network) { throw 'Forecasting does not support mapped network drives.' }
}
if ($WhatIfPreference) {
    $null = $PSCmdlet.ShouldProcess($TaskName, "Register forecasting task under $ServiceAccount (non-interactive)")
    return
}
if (-not $SkipTaskRegistration) {
    & $PythonPath -c 'import pandas, numpy, sklearn, joblib, pymysql'
    if ($LASTEXITCODE -ne 0) { throw 'Forecasting Python dependencies are missing. Supply -PythonPath pointing to the configured forecasting virtual environment.' }
}
New-Item -ItemType Directory -Path $logDir, (Split-Path $configPath), $ModelStoragePath -Force | Out-Null
$config = [ordered]@{
    project_root = $ProjectRoot; php_binary = $PhpPath; python_binary = $PythonPath
    model_storage = $ModelStoragePath; service_account = $ServiceAccount
    installed_at = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    effective_schedule = @{ daily_forecast_time = '01:00'; weekly_retrain_day = 'Sunday'; weekly_retrain_time = '01:00'; timezone = 'Asia/Manila' }
}
[IO.File]::WriteAllText($configPath, ($config | ConvertTo-Json -Depth 4), [Text.UTF8Encoding]::new($false))
if ($SkipTaskRegistration) {
    Write-Output 'Configuration saved. Task registration explicitly skipped; forecasting is not installed.'
    return
}
if ($PSCmdlet.ShouldProcess($TaskName, "Register forecasting task under $ServiceAccount (non-interactive)")) {
    $runner = Join-Path $ProjectRoot 'src/backend/scripts/install_forecasting_scheduler.ps1'
    if (-not (Test-Path -LiteralPath $runner)) { throw "Missing forecasting runner: $runner" }
    $powershell = Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe'
    $action = New-ScheduledTaskAction -Execute $powershell -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -ProjectRoot "{1}" -Run' -f $runner, $ProjectRoot) -WorkingDirectory $ProjectRoot
    $principal = New-ScheduledTaskPrincipal -UserId $ServiceAccount -LogonType ServiceAccount -RunLevel Highest
    $startup = New-ScheduledTaskTrigger -AtStartup
    $repeat = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)
    # A training run renews its own lease; do not kill it at the next five-minute tick.
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Hours 4)
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup, $repeat) -Settings $settings -Principal $principal -Force:$Force | Out-Null
    Start-ScheduledTask -TaskName $TaskName
    Write-Output "Registered and launched forecasting task: $TaskName"
}
