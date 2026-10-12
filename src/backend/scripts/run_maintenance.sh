#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
php scripts/run_scheduler.php
php scripts/run_notifications.php
