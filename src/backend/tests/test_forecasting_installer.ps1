param([string]$PythonPath = (Get-Command python.exe -ErrorAction Stop).Source)
$ErrorActionPreference = 'Stop'
Import-Module ScheduledTasks
$PythonPath = (Resolve-Path -LiteralPath $PythonPath).Path
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
$installer = Join-Path $projectRoot 'src/backend/scripts/install_forecasting_scheduler.ps1'
$fixture = Join-Path ([IO.Path]::GetTempPath()) ('retailmind-installer-' + [Guid]::NewGuid().ToString('N'))
$backend = Join-Path $fixture 'src/backend'
New-Item -ItemType Directory -Path (Join-Path $backend 'scripts') -Force | Out-Null
Copy-Item -LiteralPath $installer -Destination (Join-Path $backend 'scripts/install_forecasting_scheduler.ps1')
[IO.File]::WriteAllText((Join-Path $backend 'scripts/run_scheduler.php'), '<?php echo in_array("--sync", $argv, true) ? "isolated-sync-run" : "missing-sync"; exit(0);')
$php = (Get-Command php.exe -ErrorAction Stop).Source
$task = 'RetailMind installer fixture'
$capture = @{ registered=$null; started=$null }
function Register-ScheduledTask {
    param($TaskName, $Action, $Trigger, $Settings, $Principal, [switch]$Force)
    $capture.registered = @{ name=$TaskName; action=$Action; trigger=$Trigger; settings=$Settings; principal=$Principal }
}
function Start-ScheduledTask { param($TaskName); $capture.started = $TaskName }
function Assert-True($Condition, $Message) { if (-not $Condition) { throw $Message } }
try {
    & $installer -ProjectRoot $fixture -PhpPath $php -PythonPath $PythonPath -TaskName $task -Force
    $configPath = Join-Path $backend 'config/forecasting_runner.json'
    $config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
    Assert-True ($config.php_binary -eq $php) 'Retained PHP path'
    Assert-True ($config.python_binary -eq $PythonPath) 'Retained Python path'
    Assert-True ($config.project_root -eq $fixture) 'Retained isolated project root'
    Assert-True ($config.model_storage -eq (Join-Path $backend 'storage/ml_models')) 'Retained isolated model storage'
    Assert-True ($capture.registered.name -eq $task -and $capture.started -eq $task) ('Installer registers and launches task: ' + ($capture | ConvertTo-Json -Depth 2))
    Assert-True ($capture.registered.action.Arguments -like '*-Run*' -and $capture.registered.action.Arguments.Contains($fixture)) 'Task runs retained project configuration'
    Assert-True ($capture.registered.action.WorkingDirectory -eq $fixture) 'Task working directory is explicit'
    Assert-True ($capture.registered.settings.MultipleInstances -eq 2) 'Task ignores overlapping starts'
    Assert-True ($capture.registered.principal.LogonType -eq 5) 'Task runs independently of interactive browser/login'
    Assert-True (@($capture.registered.trigger).Count -eq 2) 'Startup and recurring triggers retained'
    $runner = Join-Path $backend 'scripts/install_forecasting_scheduler.ps1'
    & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $runner -ProjectRoot $fixture -Run
    Assert-True ($LASTEXITCODE -eq 0) 'Runner propagates successful PHP exit'
    $log = Join-Path $backend 'storage/logs/forecasting-scheduler.log'
    Assert-True ((Get-Content -LiteralPath $log -Raw) -match 'isolated-sync-run') 'Runner invokes synchronous worker mode'
    [IO.File]::WriteAllText((Join-Path $backend 'scripts/run_scheduler.php'), '<?php exit(7);')
    & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $runner -ProjectRoot $fixture -Run
    Assert-True ($LASTEXITCODE -eq 7) 'Runner propagates PHP failure instead of reporting success'
    $capture.registered = $null
    & $installer -ProjectRoot $fixture -PhpPath $php -PythonPath $PythonPath -SkipTaskRegistration
    Assert-True ($null -eq $capture.registered) 'Explicit configuration-only mode registers nothing'
    Write-Output 'PowerShell installer passed: isolated configuration, noninteractive registration, triggers, launch, sync mode, error propagation, explicit skip.'
} finally {
    Write-Output "Isolated diagnostic fixture retained at $fixture"
}
