<# Compatibility entry point; all setup uses the verified installer. #>
param (
    [string]$Token,
    [string]$Hostname,
    [int]$Port = 80,
    [ValidateSet("Permanent", "Quick", "Auto", "Ngrok")]
    [string]$Mode = "Permanent",
    [string]$HealthPath
)
$ErrorActionPreference = 'Stop'
$configPath = Join-Path $PSScriptRoot '..\..\storage\tunnel\tunnel_config.json'
if ($Mode -notin @('Quick', 'Ngrok')) {
    $config = if (Test-Path -LiteralPath $configPath) { Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json } else { $null }
    if (-not $Token) { $Token = if ($config.token) { [string]$config.token } else { $env:CLOUDFLARE_TUNNEL_TOKEN } }
    if (-not $Hostname -and $config.hostname) { $Hostname = [string]$config.hostname }
}
if ($Mode -eq 'Auto') { $Mode = if ($Token) { 'Permanent' } else { 'Quick' } }
& "$PSScriptRoot\service_setup.ps1" -Token $Token -Hostname $Hostname -Port $Port -Mode $Mode -HealthPath $HealthPath
