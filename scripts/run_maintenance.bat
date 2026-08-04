@echo off
setlocal
cd /d "%~dp0.."
python demandForcasting\auto_retrain.py
php scripts\run_notifications.php
endlocal
