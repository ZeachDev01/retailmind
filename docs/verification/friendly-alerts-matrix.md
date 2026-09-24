# Friendly Operator Alerts — manual verification matrix

Capstone verification for parent ticket #52 ( Friendly alerts 01–06, tickets #53–#58 ). Each scenario was verified at its automated seam with debug off to confirm the shop floor sees easy only, then with debug on (`APP_DEBUG`) to confirm the alert shows easy plus tech line, and in both cases the log still holds the full error. The matrix records those outcomes; the executable proof lives in `src/backend/tests/friendly_alerts_verification_contract.php` and the per-role alert contracts registered in `run_all.sh`.

## Scenario matrix

| # | Scenario | Trigger | Debug off (shop view) | Debug on (dev view) | Log check |
|---|----------|---------|------------------------|----------------------|-----------|
| 1 | Save failure | Cashier submits a stock report save that throws a technical exception | Easy only: "The stock report could not be saved. Check your connection and try again. Tell your Administrator if this keeps happening." No SQLSTATE, class names, or file paths. | Easy plus tech line: the same easy line first, then the grey `RuntimeException: SQLSTATE…` line. | Log check: app log records `[operator-alert] RuntimeException: <full message> in <file>:<line>`; the boundary is `OperatorAlert::message()` in `cashier/stock_issues.php`. |
| 2 | Camera failure | POS or product photo camera start fails (permission denied, insecure context, missing library) | Easy only: "The camera could not start. Check your camera permission, or enter the code manually to continue. Tell your Administrator if this keeps happening." | Easy plus tech line: the same easy line, then the raw detail appended only when `RetailMindUI.isDebug()` is true. | Log check: full detail stays on the developer console (`console.error('Camera start failed:', …)`); the shared wrapper safety net also emits console detail in debug mode. |
| 3 | Checkout failure | Cashier finalizes a sale that throws a technical exception | Easy only: "The sale could not finish. Please try again. Tell your Administrator if this keeps happening." rendered in the red Unable to continue container. | Easy plus tech line: `OperatorAlert::message()` leads with the easy line and appends the grey tech line. | Log check: app log records the full exception at the page boundary in `cashier/pos.php`; audit trails (`log_activity`) are unchanged. |

Cross-surface checks covered by the same chain (already recorded by tickets #54–#57):

- **Global fallback (normal page and JSON/scanner routes)**: debug off shows only the aligned easy line with the red error kind; debug on adds the grey tech line / `tech` JSON field; `error_log` keeps the full exception first.
- **Database connection failure**: the thrown message stays "Unable to connect to the database." with the original `PDOException` chained; the app log keeps `Database connection failed` plus the full `SQLSTATE` detail; the page/JSON surfaces show only the database easy line unless debug is on.
- **Protected Audit Records**: backup/restore failures store the full `$e->getMessage()` text in `record_backup_history` while the operator alert shows the easy line; login and staff/Store Setting trails are unchanged.

## Out of scope — respected

No changes were made to:

- **Demand Forecast** wording, model work, or pages (`report/predictions.php`, `ml_settings`).
- **Fiscal Period** close rules or pages.
- **Emergency Access** flows.
- **Recovery Account** flows.
- **Translation** — no language files or translation keys were added; wording stays in English.
- **Toast redesign** — none: toast look, position (`.rm-toast` / `#rm-toast-stack`), and default duration (`4800`) are unchanged; this is not a toast redesign, only the grey tech line styles (`.rm-toast-tech`, `.rm-swal-tech`) were added.
- **Retry-logic changes** — no automatic retry, backoff, or retry counters were introduced; failures only reword the alert and gate the tech line on the debug flag.

The out-of-scope list is enforced by source guards in `friendly_alerts_verification_contract.php`.
