@echo off
setlocal
cd /d "%~dp0.."
php scripts\run_scheduler.php
php scripts\run_notifications.php
endlocal
