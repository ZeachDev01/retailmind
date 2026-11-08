<#
.SYNOPSIS
    Convenience wrapper forwarding to src/backend/scripts/tunnel/autostart_install.ps1
#>
$TargetScript = Join-Path $PSScriptRoot "..\..\src\backend\scripts\tunnel\autostart_install.ps1"
& $TargetScript @args
