@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\..\src\backend\scripts\tunnel\service_install.ps1" %*
exit /b %ERRORLEVEL%
