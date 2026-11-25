# Held GCash exchange clarification

Status: accepted for implementation, 2026-11-25 Philippine time. The user confirmed the final scope, made held-exchange customer details optional, then explicitly requested implementation using TDD. Browser/API workflow and service integration tests were separately confirmed.

## Confirmed decisions

- Carry the original form's cash and wallet confirmations into Hold for checking without requiring the Cashier to check them again. Replace the unclear Completed movements wording with a clear description of movements already made.
- Customer name and GCash number are optional when holding a new exchange: the recipient number for Cash-In and sender number for Cash-Out. Missing customer details must not block holding or resolving the exchange; show Not recorded when absent.
- Show the saved name, GCash number, and Cashier's original reason for holding in Resolve held exchange. Keep that original context visible separately from the new resolution notes.
- Make resolution notes optional for Complete transaction, Cancel transaction, and Record return / recovery. Record the selected action even when notes are blank. Return amounts, evidence, and approval remain required.
- For older held exchanges without saved customer details, show Not recorded and allow resolution to continue.
- Held GCash exchanges are distinct from held Product sales.
- Require the Store GCash Service Wallet number when opening a GCash Service Session. Retain the number used by that session and display it in Recent GCash Sessions after closure; later number changes must not rewrite earlier sessions.
- Remove Administrator approval from Cashier More > Service funds for Replenishment, Withdrawal, Owner Capital, and Product Drawer Transfer. The Cashier can directly record money entering or leaving service cash or the wallet. Product Drawer Transfer retains its corresponding Product cash-drawer movement. Record the actual Cashier as the actor; do not invent an Administrator approver.

## Original behavior and diagnosed defect

- The Hold dialog copied both confirmations, but its preceding updatePreview call first cleared both entry-form checkboxes. A browser test reproduced this lost confirmation. Remove that preview reset on opening Hold while retaining confirmation resets when amount, transaction type, or fee method changes.
- Resolve held exchange currently shows transaction and movement amounts and restores its reference, but does not display the original holding reason there.
- Customer name and GCash number are not currently collected for these exchanges.

## Final scope confirmation

- The user explicitly included Product Drawer Transfer in direct recording without Administrator approval.
- All current Service funds movement types are covered. Existing balance validation, source/destination and applicable evidence requirements, active-session ownership, and money-history recording still apply.
- The approval removal concerns the Service funds form. The separately confirmed evidence and approval for held-exchange returns remain required.
- No design questions remain open. The user authorized implementation and confirmed the two testing seams required by the TDD skill.

## Specification scope

Optional held-exchange customer details expand the original exclusion in gcash-service-spec.md. The specification now records that exception and the per-session wallet number. These reversible form and display changes do not need an ADR.

Direct Cashier fund-movement recording revises the existing Administrator/Manager-only funding policy in gcash-service-spec.md and gcash-service-design.md. Both policies now follow the user's confirmed movement scope; [ADR-0035](../adr/0035-direct-cashier-gcash-service-funds.md) records the authority change and historical attribution trade-off.

## Verification

Implemented with red-to-green cycles covering direct-recording authorization, the funding password prompt, cancellation/return reasons, customer details and Resolve context, the checkbox reset, session-number requirements, and wallet identity on handover.

Passed:

- `npm run check` (one existing duplicate-ID warning in Cashier Shifts).
- `RUN_DB_TESTS=1 node src/backend/tests/gcash_held_exchange_browser_test.js`: actual browser/API saving, both optional customer fields, invalid supplied details, original holding reason in Resolve, blank cancellation/return/completion notes, legacy records, no double-counted cash, and dialog widths 1440/768/390.
- `RUN_DB_TESTS=1 node src/backend/tests/gcash_session_history_browser_test.js`: required Store wallet number, closed-session history, a later different wallet number preserving the earlier record, cashier scope, administrator filters, and pagination.
- `RUN_DB_TESTS=1 node src/backend/tests/gcash_fund_movements_integration_browser_test.js`: direct recording and attribution, history scope, responsive history, plus repeated migration of an earlier schema without losing exchanges or historical approvals.
- `gcash_funding_receipt_contract.php`, `emergency_gcash_handover_contract.php`, `failed_exchange_actual_returns_contract.php`, `gcash_follow_up_responsibility_contract.php`, `gcash_recording_corrections_contract.php`, `gcash_money_history_contract.php`, `gcash_fee_returns_contract.php`, `gcash_module_seams_contract.php`, and `fresh_schema_contract.php`.
- `gcash_dialog_layout_browser_test.js` and `gcash_fund_movements_browser_test.js`.
- [TestSprite run 88d83775](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/57f5f242-cc76-464f-a22c-58ff056c20b1/test/88d83775-7890-45ba-8a42-7b472a9602a2): passed, 24/24 steps, using an isolated synthetic Store. Pinned steps were inspected; TestSprite's default viewport is separate from the local responsive checks.

The application database applied `202611250002_gcash_cashier_recording` successfully. Fresh-install SQL and consolidated upgrade SQL include the same nullable columns and direct-recording approver policy. Existing SQLite tests emit PHP 8.5 deprecation notices; their assertions pass. The full legacy `gcash_browser_test.js`/`gcash_transaction_integration.php` suite was not run: its missing `support/cashier_ui_fixture.php` and older `users.full_name` fixtures remain outside this task.
