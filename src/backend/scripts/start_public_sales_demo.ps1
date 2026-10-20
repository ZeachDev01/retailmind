param(
    [string]$Database = 'retailmind_demo_uci_20261020',
    [ValidateRange(1024, 65535)][int]$Port = 8091
)
$ErrorActionPreference = 'Stop'
if ($Database -cnotmatch '^retailmind_demo_[a-z0-9_]+$') { throw 'Use an isolated retailmind_demo_* database.' }
if (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue) {
    throw "Port $Port is already in use. Open the existing demo or choose another port."
}
$demoRoot = (Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
$demoLogs = Join-Path $demoRoot ".scratch/public-sales-demo/$Database"
New-Item -ItemType Directory -Force $demoLogs | Out-Null
$env:APP_ENV = 'development'
$env:DATABASE_ENV = 'local'
$env:DB_HOST = '127.0.0.1'
$env:DB_NAME = $Database
if (-not $env:DB_PORT) { $env:DB_PORT = '3306' }
if (-not $env:DB_USER) { $env:DB_USER = 'root' }
if (-not $env:DB_PASSWORD) { $env:DB_PASSWORD = '' }
$env:BACKUP_STORAGE_PATH = Join-Path $env:TEMP "retailmind-demo-recovery/$Database"
$env:SESSION_NAME = 'retailmind_' + $Database
$env:SESSION_SAVE_PATH = Join-Path $env:TEMP "retailmind-demo-sessions/$Database"
$env:APP_URL = "http://127.0.0.1:$Port"
Push-Location $demoRoot
try {
    $review = & php src/backend/scripts/demo_forecasting.php review
    if ($LASTEXITCODE -ne 0) { throw 'Create and import the isolated Demo before starting it.' }
    $server = Start-Process -FilePath (Get-Command php).Source -ArgumentList @('-S', "127.0.0.1:$Port", '-t', 'src/frontend') -WorkingDirectory $demoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $demoLogs 'server.stdout.log') -RedirectStandardError (Join-Path $demoLogs 'server.stderr.log')
    try {
        $worker = Start-Process -FilePath (Get-Command python).Source -ArgumentList @('-u', 'src/backend/scripts/ml_worker.py') -WorkingDirectory $demoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $demoLogs 'worker.stdout.log') -RedirectStandardError (Join-Path $demoLogs 'worker.stderr.log')
    } catch {
        Stop-Process -Id $server.Id
        throw
    }
    @{ server_pid = $server.Id; worker_pid = $worker.Id; database = $Database; url = $env:APP_URL } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $demoLogs 'runtime.json')
    Write-Output "Demo running at $env:APP_URL/?login=1. Server PID $($server.Id); worker PID $($worker.Id)."
} finally {
    Pop-Location
}
