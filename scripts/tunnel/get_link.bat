@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\..\src\backend\scripts\tunnel\run_tunnel.ps1" -Action status
echo.
pause
endlocal
