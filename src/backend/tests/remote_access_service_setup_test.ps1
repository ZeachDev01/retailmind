# Run the production installer with fake OS/provider boundaries and a disposable filesystem.
$ErrorActionPreference = 'Stop'
$source = [IO.File]::ReadAllText((Join-Path $PSScriptRoot '..\scripts\tunnel\service_setup.ps1'))
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('retailmind-service-test-' + [guid]::NewGuid().ToString('N'))
$scriptDir = Join-Path $testRoot 'src\backend\scripts\tunnel'
$runDir = Join-Path $testRoot 'src\backend\storage\tunnel'
$binDir = Join-Path $testRoot 'src\backend\storage\bin'
New-Item -ItemType Directory -Path $scriptDir, $runDir, $binDir -Force | Out-Null
# Replace only privilege and native executable boundaries; execute the installer/rollback code unchanged.
$source = $source.Replace("if (-not `$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator))", 'if ($false)')
$source = $source.Replace('& $BinPath ', 'Invoke-FakeNgrok ')
$testScript = Join-Path $scriptDir 'service_setup.ps1'
[IO.File]::WriteAllText($testScript, $source)
Set-Content -LiteralPath (Join-Path $binDir 'cloudflared.exe') -Value 'fixture'
Set-Content -LiteralPath (Join-Path $binDir 'ngrok.exe') -Value 'fixture'

function Assert-Setup([bool]$Condition, [string]$Message) { if (-not $Condition) { throw $Message } }
function Get-CimInstance {
    param($ClassName, $Filter)
    $name = [regex]::Match($Filter, "Name='([^']+)'").Groups[1].Value
    if ($global:rmTestServices.ContainsKey($name)) { return $global:rmTestServices[$name].PSObject.Copy() }
}
function Get-Service {
    param($Name, $ErrorAction)
    if ($global:rmTestServices.ContainsKey($Name)) {
        $result = [pscustomobject]@{ Status = if ($global:rmTestServices[$Name].State -eq 'Running') { 'Running' } else { 'Stopped' } }
        $result | Add-Member ScriptMethod WaitForStatus {}
        $result | Add-Member ScriptMethod Dispose {}
        return $result
    }
}
function Stop-Service { param($Name, [switch]$Force, $ErrorAction); $global:rmTestServices[$Name].State = 'Stopped' }
function Start-Service { param($Name); $global:rmTestServices[$Name].State = 'Running' }
function New-Service {
    param($Name, $BinaryPathName, $StartupType, $DisplayName)
    $global:rmTestServices[$Name] = [pscustomobject]@{ Name = $Name; PathName = $BinaryPathName; State = 'Stopped'; StartMode = 'Auto'; DisplayName = $DisplayName; StartName = 'LocalSystem' }
}
function Start-Sleep { param($Seconds, $Milliseconds) }
function Get-ScheduledTask { param($TaskName, $ErrorAction) }
function Resolve-DnsName {
    param($Name, $Type, [switch]$DnsOnly, [switch]$QuickTimeout, $ErrorAction)
    if ($global:rmTestDnsFails) { throw 'Simulated router NXDOMAIN' }
    return [pscustomobject]@{ IPAddress = '203.0.113.5' }
}
function icacls.exe { $global:LASTEXITCODE = 0 }
function sc.exe {
    $global:LASTEXITCODE = 0
    $command, $name, $rest = $args
    switch ($command) {
        'delete' { $global:rmTestServices.Remove($name) }
        'create' {
            $global:rmTestServices[$name] = [pscustomobject]@{ Name = $name; PathName = $args[3]; State = 'Stopped'; StartMode = 'Auto'; DisplayName = $name; StartName = 'LocalSystem' }
        }
        'config' { if ($args[3] -eq 'demand') { $global:rmTestServices[$name].StartMode = 'Manual' } }
        'sdshow' { 'D:(A;;CCLCSWRPWPDTLOCRRC;;;SY)' }
    }
}
function Invoke-FakeNgrok {
    $global:LASTEXITCODE = 0
    if ($args[0] -eq 'service') {
        $global:rmTestServices['ngrok'] = [pscustomobject]@{ Name = 'ngrok'; PathName = (Join-Path $binDir 'ngrok.exe'); State = 'Stopped'; StartMode = 'Auto'; DisplayName = 'ngrok'; StartName = 'LocalSystem' }
    }
}
function Invoke-WebRequest {
    param([switch]$UseBasicParsing, $Uri, $TimeoutSec, $MaximumRedirection, $Headers)
    if ($Uri -notlike 'http://127.0.0.1:*') {
        Assert-Setup ($global:rmTestServices['ngrok'].State -eq 'Running') 'The working provider stopped before public verification.'
        if (-not $global:rmTestPublicWorks) { throw 'Simulated DNS/provider failure' }
    }
    return [pscustomobject]@{ StatusCode = 200; Content = 'RetailMind:' + ([IO.File]::ReadAllText((Join-Path $runDir 'health.id'))).Trim() }
}
try {
    $global:rmTestDnsFails = $true
    $global:rmTestServices = @{}
    $dnsError = $null
    try { & $testScript -Mode Ngrok -Token 'fixture_token' -Hostname 'store.ngrok-free.dev' -HealthPath '/shop/remote_health.php' }
    catch { $dnsError = $_.Exception.Message }
    Assert-Setup ($dnsError -like '*DNS*' -and $global:rmTestServices.Count -eq 0) 'DNS failure must be diagnosed before any service is changed.'
    $global:rmTestDnsFails = $false
    foreach ($works in @($false, $true)) {
        $global:rmTestServices = @{
            ngrok = [pscustomobject]@{ Name = 'ngrok'; PathName = (Join-Path $binDir 'ngrok.exe'); State = 'Running'; StartMode = 'Auto'; DisplayName = 'ngrok'; StartName = 'LocalSystem' }
        }
        $global:rmTestPublicWorks = $works
        $activePath = Join-Path $runDir 'active_tunnel.json'
        [IO.File]::WriteAllText($activePath, '{"mode":"ngrok","url":"https://store.ngrok-free.dev"}')
        $failed = $false
        try { & $testScript -Mode Permanent -Token 'fixture-token' -Hostname 'store.example.com' -HealthPath '/shop/remote_health.php' }
        catch { $failed = $true; if ($works) { throw } }
        $active = Get-Content -LiteralPath $activePath -Raw | ConvertFrom-Json
        if ($works) {
            Assert-Setup (-not $failed -and $active.mode -eq 'permanent') 'A verified upgrade did not activate.'
            Assert-Setup ($global:rmTestServices['ngrok'].State -eq 'Stopped' -and $global:rmTestServices['ngrok'].StartMode -eq 'Manual') 'The old provider must be stopped and disabled at boot after success.'
            Assert-Setup ($global:rmTestServices['RetailMindCloudflareTunnel'].PathName -notmatch 'fixture-token') 'The service command exposed its token.'
        } else {
            Assert-Setup ($failed -and $active.mode -eq 'ngrok') 'A failed public test must retain the previous active provider.'
            Assert-Setup ($global:rmTestServices['ngrok'].State -eq 'Running') 'Rollback interrupted the working free route.'
            Assert-Setup (-not $global:rmTestServices.ContainsKey('RetailMindCloudflareTunnel')) 'Rollback left a failed service installed.'
            Assert-Setup (-not (Test-Path -LiteralPath (Join-Path $runDir 'cloudflare.token'))) 'Rollback left replacement credentials behind.'
        }
    }
    $global:rmTestServices = @{}
    $global:rmTestPublicWorks = $true
    & $testScript -Mode Ngrok -Token 'fixture_token' -Hostname 'store.ngrok-free.dev' -HealthPath '/shop/remote_health.php'
    $installed = Get-Content -LiteralPath (Join-Path $runDir 'active_tunnel.json') -Raw | ConvertFrom-Json
    Assert-Setup ($installed.mode -eq 'ngrok' -and $global:rmTestServices['ngrok'].State -eq 'Running') 'Initial free setup did not activate the native service.'
    Assert-Setup ((Get-Content -LiteralPath (Join-Path $runDir 'ngrok.yml') -Raw).Contains('web_addr: false')) 'The local traffic inspector must stay disabled.'
    Write-Host 'Service setup: initial free setup, verified upgrade, no early cutover, token-file and rollback passed.'
} finally {
    $resolved = [IO.Path]::GetFullPath($testRoot)
    Assert-Setup ($resolved.StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath())) -and (Split-Path $resolved -Leaf) -like 'retailmind-service-test-*') 'Unsafe test cleanup path.'
    Remove-Item -LiteralPath $resolved -Recurse -Force
    Remove-Variable -Name rmTestServices, rmTestPublicWorks, rmTestDnsFails -Scope Global -ErrorAction SilentlyContinue
}
