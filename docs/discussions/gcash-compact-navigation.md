# Compact Administrator GCash navigation

Confirmed on 2026-11-26: use four main tabs — Overview, Transactions, Sessions, and Fund movements — and shorten the content inside each tab.

- Transactions opens Unresolved exchanges in a review drawer. This remains a global queue, independent of report dates and Cashier filters, with all evidence and Verify & Clear controls retained.
- Sessions opens Closing checks in a review drawer. Session and closing-review records remain separate, with their existing review actions.
- Overview retains current balances, review shortcuts and completed activity. Reporting period and Cashier stay visible; custom dates open through Filters. The same report controls are available in Transactions.
- Supporting notes and record details open in a drawer. Empty states follow their content height instead of stretching to fill the screen. Controls retain their existing touch targets.
- Existing `tab=unresolved` and `tab=reconciliation` links open the matching drawer over its parent tab. Money history scopes, session filters, receipts, permissions and financial calculations retain their existing behavior.

This is a reversible navigation decision; no new domain terms or ADR are needed.

## Verification

- `npm run check` passed, with the existing duplicate `open-register` warning in `cashier/shifts.php`.
- GCash module-seams, activity-dates, money-history and closing-difference contracts passed. Existing SQLite deprecation notices remain.
- Session-history and fund-movements integration browser tests passed against disposable databases, including Administrator filters, Cashier scope, pagination and responsive layouts.
- `gcash_admin_tabs_browser_test.js` passed with populated/empty states, shared report filters, failed-loading recovery, legacy review URLs, keyboard navigation and light/dark layouts at 320–1440px. The reset-link checks wait for `retailmind:pagechange` before interacting with the replaced page. Desktop and mobile captures were visually inspected.
- An additional disposable-database check completed Verify & Clear, opened its saved receipt, checked nested-dialog focus restoration, and marked a closing difference reviewed.
- [TestSprite run](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/b5b65f74-b622-4c23-9f43-1e6a4c989724/test/8e5101b6-de90-4ab2-9bf5-dc32d694f66d) passed 22/22 steps. Its navigation/evidence checks submitted no financial changes. This covers the TestSprite default viewport, not physical devices.
- The broader `gcash_admin_overview_browser_test.js` remains blocked by its existing integration fixture: `Unknown column 'full_name' in 'INSERT INTO'` at `gcash_transaction_integration.php:161`. Its complete financial suite is not claimed as passed. Physical devices and Safari were not tested.
