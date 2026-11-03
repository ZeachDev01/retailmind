param([string]$Installer, [string]$ProjectRoot, [string]$PhpBinary, [string]$PythonBinary)
$ErrorActionPreference = 'Stop'
# Isolate the installer from the machine's services and Scheduled Tasks.
function Get-Service { param($Name, $ErrorAction) return $null }
function Set-Service { throw 'Fixture must not configure services' }
function Start-Service { throw 'Fixture must not start services' }
function Register-ScheduledTask { throw 'Fixture must not register tasks' }
& $Installer -ProjectRoot $ProjectRoot -PhpPath $PhpBinary -PythonPath $PythonBinary -SkipTaskRegistration
