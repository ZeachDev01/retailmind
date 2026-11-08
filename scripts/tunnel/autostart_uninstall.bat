@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\..\src\backend\scripts\tunnel\autostart_uninstall.ps1" %*
endlocal
