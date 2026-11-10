<#
.SYNOPSIS
    Verify and install RetailMind Remote Access using native Windows services.
#>
param (
    [string]$Token,
    [string]$Hostname,
    [ValidateRange(1, 65535)][int]$Port = 80,
    [ValidateSet('Permanent', 'Quick', 'Ngrok')][string]$Mode = 'Permanent',
    [string]$HealthPath
)
$ErrorActionPreference = 'Stop'
$RootDir = (Resolve-Path "$PSScriptRoot\..\..\..\..").Path
$BinDir = Join-Path $RootDir 'src\backend\storage\bin'
$RunDir = Join-Path $RootDir 'src\backend\storage\tunnel'
$ConfigPath = Join-Path $RunDir 'tunnel_config.json'
$ActivePath = Join-Path $RunDir 'active_tunnel.json'
$NgrokConfig = Join-Path $RunDir 'ngrok.yml'
$TokenPath = Join-Path $RunDir 'cloudflare.token'
$LogPath = Join-Path $RunDir 'tunnel.log'
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Write-Utf8([string]$Path, [string]$Value) { [IO.File]::WriteAllText($Path, $Value, $utf8) }
function Invoke-ServiceCommand([string[]]$Arguments) {
    & sc.exe @Arguments | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Windows could not configure the tunnel service.' }
}
function Protect-SecretFile([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { Write-Utf8 $Path '' }
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    & icacls.exe $Path /inheritance:r /grant:r '*S-1-5-18:(F)' '*S-1-5-32-544:(F)' "*$($sid):(F)" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not protect the tunnel credential file.' }
}
function Remove-TunnelService([string]$Name) {
    $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if ($service) {
        if ($service.Status -ne 'Stopped') {
            Stop-Service -Name $Name -Force
            $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(15))
        }
        Invoke-ServiceCommand @('delete', $Name)
        $service.Dispose()
        for ($attempt = 0; $attempt -lt 20; $attempt++) {
            if (-not (Get-Service -Name $Name -ErrorAction SilentlyContinue)) { return }
            Start-Sleep -Milliseconds 250
        }
        throw 'Windows is still removing the old service. Close Services, then run this block again.'
    }
}
function Test-PublicRoute([string]$PublicUrl) {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri ($PublicUrl + $HealthPath) -TimeoutSec 4 -MaximumRedirection 0 -Headers @{
            'ngrok-skip-browser-warning' = '1'; 'Cache-Control' = 'no-cache'
        }
        return $response.StatusCode -eq 200 -and ([string]$response.Content).Trim() -ceq ('RetailMind:' + $healthId)
    } catch { return $false }
}

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Open PowerShell as Administrator, then paste the setup block again.' }
if (-not [Environment]::Is64BitOperatingSystem -or -not [Environment]::Is64BitProcess) { throw 'Use 64-bit Windows and 64-bit PowerShell.' }
if (-not $HealthPath) { $HealthPath = '/' + (Split-Path $RootDir -Leaf) + '/src/frontend/remote_health.php' }
if ($HealthPath -notmatch '^/[a-zA-Z0-9_./% -]+$' -or $HealthPath.Contains('..')) { throw 'Invalid application health path.' }
New-Item -ItemType Directory -Path $BinDir, $RunDir -Force | Out-Null
$healthFile = Join-Path $RunDir 'health.id'
if (-not (Test-Path -LiteralPath $healthFile)) { Write-Utf8 $healthFile ([guid]::NewGuid().ToString('N')) }
$healthId = ([IO.File]::ReadAllText($healthFile)).Trim()
$local = Invoke-WebRequest -UseBasicParsing -Uri ("http://127.0.0.1:$Port" + $HealthPath) -TimeoutSec 4 -MaximumRedirection 0
if (([string]$local.Content).Trim() -cne ('RetailMind:' + $healthId)) { throw 'Start Apache and verify the local RetailMind URL before setup.' }
if ($Mode -eq 'Permanent' -and ($Token -notmatch '^[a-zA-Z0-9_=+/\-]+$' -or $Hostname -notmatch '^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$')) { throw 'Enter a valid Cloudflare token and hostname.' }
if ($Mode -eq 'Ngrok' -and ($Token -notmatch '^[a-zA-Z0-9_]+$' -or $Hostname -notmatch '^[a-zA-Z0-9-]+\.ngrok-free\.(app|dev)$')) { throw 'Enter the ngrok authtoken and assigned free hostname from the store account.' }
if ($Mode -ne 'Quick') {
    try { Resolve-DnsName -Name $Hostname -Type A -DnsOnly -QuickTimeout -ErrorAction Stop | Out-Null }
    catch { throw 'This PC cannot resolve the public hostname through its DNS. Check the hostname, use working DNS or another network, then run setup again. No tunnel service was changed.' }
}

$ServiceName = if ($Mode -eq 'Ngrok') { 'ngrok' } else { 'RetailMindCloudflareTunnel' }
$BinPath = Join-Path $BinDir $(if ($Mode -eq 'Ngrok') { 'ngrok.exe' } else { 'cloudflared.exe' })
if (-not (Test-Path -LiteralPath $BinPath)) {
    if ($Mode -eq 'Ngrok') {
        $archive = Join-Path $BinDir 'ngrok.zip'
        & curl.exe --fail --location --silent --show-error --connect-timeout 10 --max-time 120 --output $archive 'https://bin.ngrok.com/c/bNyj1mQVY4c/ngrok-v3-stable-windows-amd64.zip'
        if ($LASTEXITCODE -ne 0) { throw 'ngrok download failed. Check internet access and retry.' }
        Expand-Archive -LiteralPath $archive -DestinationPath $BinDir -Force
        Remove-Item -LiteralPath $archive -Force
    } else {
        $download = $BinPath + '.download'
        & curl.exe --fail --location --silent --show-error --connect-timeout 10 --max-time 60 --output $download 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe'
        if ($LASTEXITCODE -ne 0) { throw 'Cloudflare download failed. Check internet access and retry.' }
        Move-Item -LiteralPath $download -Destination $BinPath -Force
    }
}
if (-not (Test-Path -LiteralPath $BinPath)) { throw 'Tunnel download failed. Check internet access and retry.' }
$oldService = Get-CimInstance Win32_Service -Filter "Name='$ServiceName'"
if ($oldService -and -not $oldService.PathName.Contains($BinPath)) { throw 'An unrelated tunnel service uses this name. Resolve the conflict before setup.' }
if ($oldService -and $oldService.StartName -ne 'LocalSystem') { throw 'This service uses a custom Windows account. Keep its configuration unchanged and migrate it explicitly.' }
$otherName = if ($Mode -eq 'Ngrok') { 'RetailMindCloudflareTunnel' } else { 'ngrok' }
$otherService = Get-CimInstance Win32_Service -Filter "Name='$otherName'"
$snapshots = @{}
foreach ($path in @($NgrokConfig, $ConfigPath, $TokenPath, $ActivePath)) {
    $snapshots[$path] = if (Test-Path -LiteralPath $path) { [IO.File]::ReadAllText($path) } else { $null }
}

$serviceChanged = $false
try {
    if ($Mode -eq 'Ngrok') {
        Protect-SecretFile $NgrokConfig
        $yaml = "version: 3`nagent:`n  authtoken: $Token`n  web_addr: false`n  remote_management: false`nendpoints:`n  - name: retailmind`n    url: https://$Hostname`n    upstream:`n      url: http://127.0.0.1:$Port`n"
        Write-Utf8 $NgrokConfig $yaml
        & $BinPath config check --config $NgrokConfig *> $null
        if ($LASTEXITCODE -ne 0) { throw 'ngrok rejected the configuration. Check the token and hostname.' }
        $serviceChanged = $true
        Remove-TunnelService $ServiceName
        & $BinPath service install --config $NgrokConfig *> $null
        if ($LASTEXITCODE -ne 0) { throw 'ngrok service installation failed.' }
        $publicUrl = "https://$Hostname"
    } else {
        $serviceChanged = $true
        Remove-TunnelService $ServiceName
        if ($Mode -eq 'Permanent') {
            Protect-SecretFile $TokenPath
            Write-Utf8 $TokenPath $Token
            $binCmd = "`"$BinPath`" --no-autoupdate tunnel --logfile `"$LogPath`" run --token-file `"$TokenPath`""
            $publicUrl = "https://$Hostname"
        } else {
            Write-Host 'Interim Quick Tunnel: changing URL; no uptime guarantee.' -ForegroundColor Yellow
            Write-Utf8 $LogPath ''
            $binCmd = "`"$BinPath`" --no-autoupdate tunnel --url http://127.0.0.1:$Port --logfile `"$LogPath`""
            $publicUrl = $null
        }
        New-Service -Name $ServiceName -BinaryPathName $binCmd -StartupType Automatic -DisplayName 'RetailMind Cloudflare Remote Access Tunnel' | Out-Null
    }
    Invoke-ServiceCommand @('config', $ServiceName, 'start=', 'auto')
    Invoke-ServiceCommand @('failure', $ServiceName, 'reset=', '86400', 'actions=', 'restart/5000/restart/10000/restart/20000')
    Invoke-ServiceCommand @('failureflag', $ServiceName, '1')
    # Allow the setup account's non-elevated XAMPP process to start/stop, never reconfigure, this service.
    $descriptor = (& sc.exe sdshow $ServiceName | Where-Object { $_ -match '^D:' }) -join ''
    if (-not $descriptor) { throw 'Could not read tunnel service permissions.' }
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $ace = "(A;;LCRPWP;;;$sid)"
    if (-not $descriptor.Contains($ace)) {
        $sacl = $descriptor.IndexOf('S:')
        $descriptor = if ($sacl -ge 0) { $descriptor.Insert($sacl, $ace) } else { $descriptor + $ace }
        Invoke-ServiceCommand @('sdset', $ServiceName, $descriptor)
    }
    Start-Service -Name $ServiceName
    Write-Host 'Verifying public access before switching...' -ForegroundColor Cyan
    $verified = $false
    for ($attempt = 0; $attempt -lt 12; $attempt++) {
        if ($Mode -eq 'Quick' -and (Test-Path -LiteralPath $LogPath)) {
            $urls = [regex]::Matches([IO.File]::ReadAllText($LogPath), 'https://[a-zA-Z0-9.-]+\.trycloudflare\.com')
            if ($urls.Count) { $publicUrl = $urls[$urls.Count - 1].Value }
        }
        if ($publicUrl -and (Test-PublicRoute $publicUrl)) { $verified = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $verified) { throw 'Public verification failed. Check token, domain/DNS, quota, and internet. Restoring the previous setup.' }
    if ($otherService -and $otherService.PathName.Contains($BinDir)) {
        Stop-Service -Name $otherName -Force
        Invoke-ServiceCommand @('config', $otherName, 'start=', 'demand')
    }
    $installation = @{ mode = $Mode.ToLowerInvariant(); url = $publicUrl; health_path = $HealthPath; port = $Port }
    Write-Utf8 $ActivePath ($installation | ConvertTo-Json)
    Write-Utf8 (Join-Path $RunDir 'tunnel.url') $publicUrl
    Remove-Item -LiteralPath (Join-Path $RunDir 'health.json'), (Join-Path $RunDir 'tunnel.pid') -Force -ErrorAction SilentlyContinue
    if ($Mode -eq 'Permanent') { Write-Utf8 $ConfigPath (@{ token = $Token; hostname = $Hostname } | ConvertTo-Json) }
    if (Get-ScheduledTask -TaskName 'RetailMindQuickTunnel' -ErrorAction SilentlyContinue) { & schtasks.exe /Delete /TN 'RetailMindQuickTunnel' /F | Out-Null }
    $appUrl = $publicUrl + $HealthPath.Substring(0, $HealthPath.LastIndexOf('/') + 1)
    Write-Host "Verified Remote Access: $appUrl" -ForegroundColor Green
    Write-Host 'Automatic boot startup and crash recovery enabled. Keep Apache, database and internet running during store hours.'
    if ($Mode -eq 'Ngrok') { Write-Host 'Free: 1 GB outbound / 20,000 requests monthly; browser warning applies. Quota/provider outages require attention.' -ForegroundColor Yellow }
} catch {
    $failure = $_
    try {
        if ($serviceChanged) { Remove-TunnelService $ServiceName }
        foreach ($path in $snapshots.Keys) {
            if ($null -ne $snapshots[$path]) { Write-Utf8 $path $snapshots[$path] } else { Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue }
        }
        if ($serviceChanged -and $oldService) {
            $startup = switch ($oldService.StartMode) { 'Auto' { 'auto' } 'Disabled' { 'disabled' } default { 'demand' } }
            New-Service -Name $ServiceName -BinaryPathName $oldService.PathName -StartupType Manual -DisplayName $oldService.DisplayName | Out-Null
            Invoke-ServiceCommand @('config', $ServiceName, 'start=', $startup)
            Invoke-ServiceCommand @('failure', $ServiceName, 'reset=', '86400', 'actions=', 'restart/5000/restart/10000/restart/20000')
            if ($oldService.State -eq 'Running') { Start-Service -Name $ServiceName }
        }
        if ($otherService -and $otherService.PathName.Contains($BinDir)) {
            $startup = if ($otherService.StartMode -eq 'Auto') { 'auto' } else { 'demand' }
            Invoke-ServiceCommand @('config', $otherName, 'start=', $startup)
            if ($otherService.State -eq 'Running') { Start-Service -Name $otherName }
        }
    } catch { Write-Warning 'Automatic recovery failed. Re-run the previous setup block as Administrator; local checkout is unaffected.' }
    throw $failure
}
