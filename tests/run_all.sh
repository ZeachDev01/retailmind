#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
find . -type f -name '*.php' -not -path './vendor/*' -print0 | sort -z | xargs -0 -n1 php -l >/tmp/retailmind_php_lint.log
python -m py_compile demandForcasting/train_model.py demandForcasting/auto_retrain.py demandForcasting/db.py demandForcasting/predict_api.py
php tests/smoke_checks.php
php tests/database_integration.php
bash tests/release_package_check.sh
printf 'PHP files linted: %s\n' "$(grep -c 'No syntax errors' /tmp/retailmind_php_lint.log)"
printf 'Python compile: passed\n'
