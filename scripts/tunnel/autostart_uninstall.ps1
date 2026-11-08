<#
.SYNOPSIS
    Convenience wrapper forwarding to src/backend/scripts/tunnel/autostart_uninstall.ps1
#>
$TargetScript = Join-Path $PSScriptRoot "..\..\src\backend\scripts\tunnel\autostart_uninstall.ps1"
& $TargetScript @args
