@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\..\src\backend\scripts\tunnel\service_uninstall.ps1" %*
exit /b %ERRORLEVEL%
