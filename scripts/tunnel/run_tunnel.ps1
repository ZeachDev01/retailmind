<#
.SYNOPSIS
    Convenience wrapper forwarding to src/backend/scripts/tunnel/run_tunnel.ps1
#>
$TargetScript = Join-Path $PSScriptRoot "..\..\src\backend\scripts\tunnel\run_tunnel.ps1"
& $TargetScript @args
