@echo off
setlocal
title RetailMind Background Tasks Setup

:: Ensure running from project root
cd /d "%~dp0"

echo ========================================================
echo   RetailMind Windows Background Services Installer
echo ========================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0src\backend\scripts\install_scheduled_tasks.ps1" %*

echo.
pause
