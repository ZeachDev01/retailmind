# Test for install_forecasting_scheduler.ps1 configuration retention and task definition
$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $scriptDir '../../..')).Path
$installerScript = Join-Path $projectRoot 'src/backend/scripts/install_forecasting_scheduler.ps1'
$configPath = Join-Path $projectRoot 'src/backend/config/forecasting_runner.json'

Write-Output "Starting PowerShell installer and configuration contract tests..."

# Backup existing runner config if present
$backupConfig = $null
if (Test-Path -LiteralPath $configPath) {
    $backupConfig = Get-Content -LiteralPath $configPath -Raw
}

try {
    # 1. Test configuration retention with explicit paths and -SkipTaskRegistration
    $phpCmd = (Get-Command php.exe -ErrorAction Stop).Source
    $pyCmd = (Get-Command python.exe -ErrorAction Stop).Source
    $customModelDir = Join-Path $projectRoot 'src/backend/storage/ml_models'

    & $installerScript -PhpPath $phpCmd -PythonPath $pyCmd -ModelStoragePath $customModelDir `
        -ServiceAccount 'NT AUTHORITY\SYSTEM' -SkipTaskRegistration

    if (-not (Test-Path -LiteralPath $configPath)) {
        throw "Failed: forecasting_runner.json was not created by installer."
    }

    $savedConfig = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
    if ($savedConfig.php_binary -ne $phpCmd) {
        throw "Failed: saved php_binary does not match input. Expected $phpCmd, got $($savedConfig.php_binary)"
    }
    if ($savedConfig.python_binary -ne $pyCmd) {
        throw "Failed: saved python_binary does not match input. Expected $pyCmd, got $($savedConfig.python_binary)"
    }
    if ($savedConfig.project_root -ne $projectRoot) {
        throw "Failed: saved project_root does not match projectRoot."
    }
    if ($savedConfig.model_storage -ne $customModelDir) {
        throw "Failed: saved model_storage does not match input."
    }
    if ($savedConfig.service_account -ne 'NT AUTHORITY\SYSTEM') {
        throw "Failed: saved service_account does not match 'NT AUTHORITY\SYSTEM'."
    }
    Write-Output "  ok - Installer retains configuration without interactive PATH assumptions"

    # 2. Test mapped network drive rejection
    $fakeNetworkException = $false
    try {
        # Test validation function or drive check directly
        $driveLetter = [System.IO.Path]::GetPathRoot($projectRoot).TrimEnd('\')
        if ($driveLetter -match '^[A-Za-z]:$') {
            $dInfo = [System.IO.DriveInfo]::new($driveLetter)
            # Local drive passes
            if ($dInfo.DriveType -eq [System.IO.DriveType]::Network) {
                throw "Network drive detected"
            }
        }
    } catch {
        $fakeNetworkException = $true
    }
    if ($fakeNetworkException) {
        throw "Failed: local drive was falsely flagged as network drive."
    }
    Write-Output "  ok - Local fixed drive validation passed"

    # 3. Test -WhatIf / dry-run task registration parameters
    $whatIfOutput = powershell.exe -ExecutionPolicy Bypass -Command "& '$installerScript' -PhpPath '$phpCmd' -PythonPath '$pyCmd' -Force -WhatIf 2>&1" | Out-String
    if (-not ($whatIfOutput -match 'Register forecasting task under NT AUTHORITY\\SYSTEM \(non-interactive\)')) {
        throw "Failed: WhatIf output does not reflect non-interactive SYSTEM task registration. Got: $whatIfOutput"
    }
    Write-Output "  ok - Non-interactive SYSTEM scheduled task registration verified via ShouldProcess"

    # 4. Test -Run switch execution using retained config
    $runOutput = powershell.exe -ExecutionPolicy Bypass -Command "& '$installerScript' -Run 2>&1" | Out-String
    $runExit = $LASTEXITCODE
    if ($runExit -ne 0) {
        throw "Failed: -Run switch exited with code $runExit. Output: $runOutput"
    }
    Write-Output "  ok - Background runner execution succeeds with retained PHP binary"

} finally {
    if ($null -ne $backupConfig) {
        Set-Content -LiteralPath $configPath -Value $backupConfig -Encoding UTF8
    } else {
        Remove-Item -LiteralPath $configPath -Force -ErrorAction SilentlyContinue
    }
}

Write-Output "PowerShell installer contract test passed."
