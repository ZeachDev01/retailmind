# Fiscal closing and reminders — 2026-11-26

## Result

GCash reconciliation-only session closing no longer requires today's Fiscal Period to be open. Original money/stock operations retain their fiscal guards. Authorization, actual closing timestamps, counted balances, differences and audit remain in the closing operation.

Affected Cashiers receive reminders for their own open shifts/sessions; Inventory Managers receive reminders for pending period counts; Administrators receive oversight. Reminders begin one Philippine calendar day before period end, persist for unfinished work after expiry, and resolve on completion. Bell notices use existing attention-state deduplication without queued email. SweetAlert appears once daily per user/Workspace in each browser and defers during unsafe/busy UI states. Notification buttons name the destination task.

No live Store session or period was closed or modified by verification. All mutation tests used in-memory SQLite or disposable `retailmind_theme_test_*` MySQL databases.

## Local checks

Passed:

- `npm run check` — stylesheet, view and smoke checks plus configured remote-access contracts. The view audit retains the existing duplicate `open-register` ID warning in `cashier/shifts.php`.
- `npm run check:contracts`.
- `php src/backend/tests/fiscal_period_closing_reminder_test.php` — Philippine midnight boundary, ownership, assigned-role eligibility, disabled accounts, deduplication, no email, read-versus-resolved state, expiry and finalization.
- `php src/backend/tests/gcash_follow_up_responsibility_contract.php` — real closure with scheduled/closed/locked/missing coverage, retained ownership enforcement and unchanged original GCash transactions; new money movement still blocked.
- `php src/backend/tests/fiscal_period_custody_closure_contract.php` and `fiscal_period_scheduling_contract.php`.
- `php src/backend/tests/role_notification_routing_contract.php`.
- `node src/backend/tests/session_activity_http_test.js` — fiscal polling does not extend idle login lifetime.
- `RUN_DB_TESTS=1 node src/backend/tests/fiscal_period_closing_browser_test.js` — real GCash closing with scheduled successor, Cashier/Inventory routing, actual Inventory Counts navigation, daily dismissal, bell, popup deferral, 390/1280px and light/dark rendering without browser errors.
- `RUN_DB_TESTS=1 node src/backend/tests/shift_reminder_browser_test.js` — independent shift reminder retained.
- `RUN_DB_TESTS=1 php src/backend/tests/daily_expiry_mysql_test.php` — real daily notification entrypoint still completes.
- PHP/JavaScript syntax checks and scoped `git diff --check`.

The existing `RetailMind Daily Notifications` Windows task was found enabled/ready. Its daily entrypoint is reused; no new scheduled task or email delivery was configured.

## TestSprite

Test case: `ab9c4c98-e731-49c3-9cc4-8d6d04e1b305`, project `abe16d0b-21f0-432e-8a86-72b1436c42fe`.

The initial run `d7fd9d8b-a7a0-46b9-ab65-c1f23989585d` passed 20 recorded steps, including GCash closing, but failed final confirmation of the Inventory Counts destination behind the generic "Review Condition" button. Failure steps and the captured HTML were inspected; artifact-bundle retrieval was unavailable because the service withheld the stored script. Buttons now name the fiscal closing task, and the revised plan explicitly opens Inventory Counts. Local Playwright verifies the destination.

Revised run `c59b22d8-c6e6-443d-981f-ce60ac8d2ba5`: **passed, 22 recorded steps, 0 failed**. It confirms the late GCash close, resolved GCash notice, remaining Shift notice, Inventory reminder and actual navigation to Inventory Counts. Device-size coverage belongs to the local Playwright checks above. [TestSprite execution](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/be959563-ab48-4671-8cb5-7982b2b07b6b/test/c59b22d8-c6e6-443d-981f-ce60ac8d2ba5).

The isolated TestSprite server was stopped and its disposable database removed after verification.

## Limits

No new sales, GCash settlement/returns, count approvals or backdated transactions are allowed through the reconciliation exception. A legacy session closed late can update its historical reconciliation view; original financial movements and finalized period dates/status remain unchanged. Actual Store cash and wallet counts must still be supplied by the responsible user.
