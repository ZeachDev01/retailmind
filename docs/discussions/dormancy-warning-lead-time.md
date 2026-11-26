# Dormancy warning lead time

Confirmed on 2026-11-26: the Super Administrator sets **Disable after (days)**
and **Warn before disable (days)**. Disabling after 30 days with a 3-day
warning means warning after 27 days without a successful login.

- Warning lead time is a whole number from 1 to the disable period minus 1.
- Existing saved schedules retain their timing: disable 45 / warn after 30
  displays as disable 45 / warn before disable 15.
- Saving converts the lead time to elapsed inactivity days. Existing database
  keys, defaults and the notification runner retain their meaning; no data
  migration is needed.
- Email delivery still depends on the scheduled notification runner and an
  account email address. A missed run can delay the warning; this change does
  not introduce a new grace period or additional reminder emails.

Verification: `dormancy_policy_settings_contract.php`,
`dormancy_runner_contract.php`, `dormancy_status_tab_contract.php`, and
`RUN_DB_TESTS=1 node src/backend/tests/dormancy_policy_browser_test.js`.
The browser test uses a disposable database and checks persistence, invalid
input, JavaScript-free saving, and light/dark layouts at 390, 768 and 1440px.
