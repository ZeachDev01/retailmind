@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0service_uninstall.ps1" %*
exit /b %ERRORLEVEL%
