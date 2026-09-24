#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"
find . -type f -name '*.php' -not -path './vendor/*' -not -path './.kilo/worktrees/*' -print0 | sort -z | xargs -0 -n1 php -l >/tmp/retailmind_php_lint.log
python -m py_compile src/backend/legacy/demandForcasting/train_model.py src/backend/legacy/demandForcasting/auto_retrain.py src/backend/legacy/demandForcasting/db.py src/backend/legacy/demandForcasting/predict_api.py
bash src/backend/tests/javascript_syntax_check.sh
python src/backend/tests/css_balance_check.py
python src/backend/tests/forecast_regression.py
php src/backend/tests/smoke_checks.php
php src/backend/tests/fresh_schema_contract.php
php src/backend/tests/role_capability_policy_contract.php
php src/backend/tests/emergency_access_lifecycle_test.php
php src/backend/tests/recovery_account_lifecycle_test.php
php src/backend/tests/attention_rules_contract.php
php src/backend/tests/super_administrator_dashboard_workspace_test.php
php src/backend/tests/super_administrator_dashboard_route_test.php
php src/backend/tests/workspace_routing_contract.php
php src/backend/tests/store_scope_contract.php
php src/backend/tests/user_lifecycle_contract.php
php src/backend/tests/store_staff_availability_contract.php
php src/backend/tests/store_staff_username_notice_contract.php
php src/backend/tests/store_staff_email_notice_contract.php
php src/backend/tests/store_staff_race_fallback_contract.php
php src/backend/tests/password_policy_contract.php
php src/backend/tests/password_change_modes_contract.php
php src/backend/tests/recovery_exclusion_reset_audit_contract.php
php src/backend/tests/profile_image_storage_test.php
php src/backend/tests/operator_alert_test.php
php src/backend/tests/operator_alert_ui_contract.php
node src/backend/tests/operator_alert_wrapper_behavior_test.js
php src/backend/tests/operator_alert_fallback_contract.php
php src/backend/tests/cashier_alert_contract.php
php src/backend/tests/inventory_alert_contract.php
php src/backend/tests/product_seed_checks.php
php src/backend/tests/stock_issue_contract.php
php src/backend/tests/stock_issue_correction_contract.php
php src/backend/tests/stock_issue_oversight_contract.php
php src/backend/tests/database_integration.php
php src/backend/tests/audit_visibility_integration.php
php src/backend/tests/receipt_table_contract.php
php src/backend/tests/sales_trend_integration.php
if [[ -n "${WHITESPACE_BASE:-}" ]] && git cat-file -e "${WHITESPACE_BASE}^{commit}" 2>/dev/null; then
  git diff --check "$WHITESPACE_BASE" HEAD
fi
git diff --check
printf 'Git whitespace: passed\n'
bash src/backend/tests/release_package_check.sh
printf 'PHP files linted: %s\n' "$(grep -c 'No syntax errors' /tmp/retailmind_php_lint.log)"
printf 'Python compile: passed\n'
