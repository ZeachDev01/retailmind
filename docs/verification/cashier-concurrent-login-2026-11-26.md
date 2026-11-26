# Cashier device confirmation verification

Verified locally on 2026-11-26 Philippine time. Production deployment was not checked. The local migration applied `202611260001_cashier_login_sessions`.

## Passed

- `php src/backend/tests/cashier_login_service_test.php`: ownership, pending request isolation, reject/approve, token reuse, revocation, one-hour expiry, and logout.
- `php src/backend/tests/cashier_login_mysql_test.php`: two barrier-synchronized PHP workers against a disposable MySQL database produce exactly one active login and one pending login.
- `node src/backend/tests/cashier_concurrent_login_http_test.js`: real authentication, pending access denial, CSRF, approval, rejection, expired old-device access, logout, and a multi-role account operating in Inventory.
- `node src/backend/tests/cashier_concurrent_login_browser_test.js`: independent browser contexts, shared tabs, waiting UI, rejection, transfer, old-device denial and offline expiry. Passed on SQLite and with `RUN_DB_TESTS=1` against a disposable MySQL fixture. Approval fits at 1280 px and 390 px; screenshots inspected. Screenshots disable animations so paused test clocks do not capture unfinished transitions.
- Existing Super Administrator concurrent-login HTTP and browser checks; HTTP also passed against the disposable MySQL fixture.
- `staff_login_identifier_contract.php`, `password_policy_contract.php`, `password_change_modes_contract.php`, `session_policy_test.php`, `session_policy_browser_test.js`, and `session_activity_http_test.js`.
- `RUN_DB_TESTS=1 php src/backend/tests/database_backup_workflow_integration.php`: complete migrated schema and omission of transient login ownership and pending approvals. An injected restore interruption emitted a Windows rename warning; the full suite completed successfully.
- `npm run check`, `npm run check:contracts`, relevant PHP syntax checks, and `git diff --check`. View audit retains an existing duplicate `open-register` ID warning in `cashier/shifts.php`.

## TestSprite

Project: `retailmind-ui-role-devices-20261116` (selected by the user).

[Passed execution](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/be5b0135-172e-407d-8188-bdcd0db09ae2/test/2b3b6ee5-730c-4fff-978b-7b42825e4d60): run `2b3b6ee5-730c-4fff-978b-7b42825e4d60`, all 12 recorded steps passed and inspected. Used disposable SQLite accounts and a test-only route that queues a synthetic second-device request. It exercised the shipped confirmation dialog, rejection, approval, logout and protected-access denial. Independent-browser behavior was verified separately by Playwright, not claimed from this TestSprite run.

## Other findings and limits

- Extra `admin_alert_contract.php` check reports five copy assertions in reset-link delivery, forgot-password, Store Settings and backup restore pages/routes. Those copy paths were not changed for this task; no unrelated fixes included.
- A separate browser/private window counts as a separate login. This is session ownership, not physical device fingerprinting.
- Existing cashier sessions issued before rollout require signing in again. The Cashier Shift and register remain open.
- Unsaved browser work is not transferred. Existing stock guards remain necessary between separate cashier accounts.
