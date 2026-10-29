[CmdletBinding(SupportsShouldProcess)]
param([switch]$Run)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
$phpBinary = (Get-Command php.exe -ErrorAction Stop).Source
$schedulerScript = Join-Path $PSScriptRoot 'run_scheduler.php'

if ($Run) {
    $logDirectory = Join-Path $projectRoot 'src/backend/storage/logs'
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
    Push-Location -LiteralPath $projectRoot
    try {
        # Windows PowerShell treats native stderr as an error before redirection under Stop.
        $ErrorActionPreference = 'Continue'
        & $phpBinary -f $schedulerScript *>> (Join-Path $logDirectory 'forecasting-scheduler.log')
        $schedulerResult = $LASTEXITCODE
        if ($null -eq $schedulerResult) { exit 1 }
        exit $schedulerResult
    } finally {
        Pop-Location
    }
}

# Use the signed-in account: Laragon's local services also need to be running.
$taskName = 'RetailMind ML Scheduler'
if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
    throw "Task '$taskName' already exists. Review it in Task Scheduler before replacing it."
}
Get-Command python.exe -ErrorAction Stop | Out-Null
$powershellBinary = Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe'
$action = New-ScheduledTaskAction -Execute $powershellBinary -Argument (
    '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -Run' -f $PSCommandPath
) -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) -RepetitionInterval (New-TimeSpan -Minutes 5)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
$principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) `
    -LogonType Interactive -RunLevel Limited
if ($PSCmdlet.ShouldProcess($taskName, 'Register forecasting checks every five minutes for the signed-in user')) {
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings `
        -Principal $principal -Description 'Checks forecasting schedules and usable history; trains candidates without approving them.' | Out-Null
    Get-ScheduledTask -TaskName $taskName | Select-Object TaskName, State
}
