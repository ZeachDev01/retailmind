#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
python3 legacy/demandForcasting/auto_retrain.py
php scripts/run_notifications.php
