@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0service_install.ps1" %*
exit /b %ERRORLEVEL%
