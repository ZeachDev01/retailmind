# Simplified Stock Issues

Status: User authorized implementation on 2026-11-25; implemented. Verification below.

## Problem

An Inventory Manager who discovers an issue currently needs to submit a Cashier report and then switch Workspace to decide it, even when one person holds both roles. Clarification adds another round trip: a manager message puts the report into Awaiting cashier reply, blocking a decision until the Cashier replies.

## Agreed behavior

- In the Inventory Manager Workspace, record a confirmed Stock Issue directly: choose the Product, issue reason/type, stock form and confirmed quantity, then use one final record-and-deduct action. No Cashier report, self-approval step, or conversation is required.
- A Cashier-only user submits a report without changing stock. The Inventory Manager approves using the confirmed affected quantity or rejects with a reason when no valid stock loss is confirmed.
- The Manager can edit the confirmed quantity before direct recording or approval. A Cashier report's original quantity stays unchanged in history; the confirmed quantity determines the actual deduction. Retain existing support for confirming fewer or more units and requiring an explanation when quantities differ.
- Zero affected units means rejection of a Cashier report, with no deduction. Direct recording requires a positive affected quantity.
- Remove the chat and Awaiting cashier reply workflow from active Stock Issue handling. Existing unresolved reports can be decided by the Manager without requiring another Cashier reply. Preserve existing conversation history as read-only evidence.
- Preserve who reported, who recorded or decided, when, the reason, the original reported quantity where applicable, and the final deducted quantity. Keep Administrator oversight read-only.
- Authority follows the active Workspace: holding both roles does not grant Inventory Manager actions inside the Cashier Workspace. A multi-role user can enter the Inventory Manager Workspace and record the issue directly.
- Quantity editing here is before the final stock deduction. Preserve existing final-decision immutability and linked correction history.
- Reuse existing stock validation, batch handling, pack conversion, fiscal safeguards, atomic deduction, and retry protection. Deduct once using the final confirmed quantity.

## Cashier shortcut to Inventory

Status: Implemented and browser-verified on 2026-11-25.

The Stock Issue page in the Cashier Workspace shows an Open Inventory Stock Issues button in place of the new-report form when the user also has the Inventory Manager role in the same Store. The button switches to the Inventory Manager Workspace and opens Record Stock Issue. Report History in the Cashier Workspace shows only reports submitted by that user as Cashier. Direct Inventory records appear in Recent decisions in the Inventory Manager Workspace. Cashier-only users can still submit reports through the existing form.

A dual-role user can decide an existing unresolved report they submitted as Cashier by opening the original report in Pending review in the Inventory Manager Workspace.

The stock issue review browser test verifies the authorized shortcut, Cashier-only reporting, role-restricted submissions, direct deduction, same-user review of an existing report, and separate histories. The shortcut fits at 390, 768, and 1440 pixels in light and dark mode.

## Examples

- Manager finds 3 damaged pieces: records 3 directly; stock decreases by 3 once.
- Cashier reports 5 damaged pieces; Manager confirms 2: history retains reported 5 and confirmed/deducted 2, with the explanation.
- Cashier reports 5; Manager confirms 7: approve 7 only if existing stock and batch validation allow it, with the explanation.
- Cashier reports damage; Manager finds no affected units: reject with a reason; stock stays unchanged.
- An older report is Awaiting cashier reply: Manager can decide it without another reply; previous messages remain readable.

## Relationship to existing docs

This design supersedes the active conversation and waiting-state flow in [Stock Issue clarification](stock-issue-clarification.md). It retains the quantity distinction and deduction rules in [Stock Issue verified quantity](stock-issue-verified-quantity.md), extending confirmed quantity to direct Manager recording. Existing implementation and verification notes in those documents describe the earlier behavior, not verification of this design.

## Verification scope

Exercise direct Manager recording, Cashier reporting and Manager decisions, quantity differences, zero-affected rejection, older waiting reports, historical messages, Workspace authorization, and duplicate retries using existing Stock Issue service and browser tests. Results and remaining suite limitations are listed below.

## Implementation and verification — 2026-11-25

- Added direct Manager entry using the shared Cashier barcode/search form. Direct recording and report approval reuse one ledger-backed deduction routine; record creation, batch deductions, revisions and durable retry result commit together. No schema migration or dependency was added.
- Manager navigation now has Record Stock Issue, Pending review and Recent decisions. Pending review includes legacy waiting reports, and decisions preserve their earlier state and historical messages. New messages and return-to-Cashier actions are refused; empty conversation controls and reply composers are removed.
- Manager confirmed quantity remains editable before approval. Direct entries support loose pieces and sealed packs, retain Manager attribution and need no Cashier Shift. Cashier report quantities stay immutable, with changed verified quantities requiring the existing reason.
- Stock Issue lookup includes expired stock and displays recorded on-hand quantity. Ordinary sale lookup still excludes expired stock. Required work and dashboard counts route unresolved legacy issues to Managers, with no Cashier reply task.
- Service checks passed: `stock_issue_batch_deduction_test.php`, `stock_issue_correction_contract.php`, `role_notification_routing_contract.php`. Coverage includes direct retry safety, refusal to reuse a token with changed values, permissions, invalid quantities, closed Fiscal Periods, rollback without orphan reports, insufficient stock, verified quantities, and preserved legacy messages.
- Browser checks passed against disposable MySQL Stores: `stock_issue_review_layout_browser_test.js` and `stock_issues_keyboard_scan_browser_test.js`. Coverage includes direct entries, HTTP retry, failed-input retention, direct-entry draft recovery after refresh, legacy review without reply, zero-affected rejection, direct packs, actual Cashier submission followed by changed-quantity approval, expired-product lookup separation, denied Cashier access, and no Manager JavaScript errors. Layout measurements and captures cover 390, 768 and 1440px in light/dark themes, plus Cashier at 390px.
- Final `npm run check` and `npm run check:contracts` passed. The existing Cashier Shifts duplicate-ID warning remains.
- `administrator_dashboard_cache_test.php` could not complete: its Cash Refund fixture has no open Fiscal Period for 2026-11-25. It also reports an initial-view failure and PHP 8.5 SQLite deprecations. This run does not verify the dashboard cache integration.

- TestSprite passed 22/22 steps: [execution c2c30671-1c60-4aa3-ab29-7a326876eb83](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/18b48fe4-24cc-46ec-a31b-5d0e1450f33a/test/c2c30671-1c60-4aa3-ab29-7a326876eb83). It verified direct Manager recording after editing confirmed quantity to one pack, attributed final deduction without self-approval, no reply composer/waiting destination, and zero-affected rejection with retained reason. Temporary test servers, recovery storage and disposable Stores were cleaned up; live Store records were not changed.
- Additional suite limits: `inventory_private_recovery_browser_test.js` stopped at its hidden receiving-save button (`.btn-submit`, line 179), so its full receiving-recovery flow is unverified. Stock Issue draft recovery is separately covered by the passing Stock Issue browser suite. `forecast_navigation_contract.php` passed its Stock Issues navigation assertions but failed its Store Calendar placement and Data Readiness link assertions; those paths were not changed by this implementation.
