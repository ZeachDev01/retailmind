@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\..\src\backend\scripts\tunnel\autostart_install.ps1" %*
endlocal
