## Problem Statement

Published as [GitHub issue #352](https://github.com/ZeachDev01/retailmind/issues/352), labelled `ready-for-agent`.

RetailMind cannot yet substantiate a complete pass against the 120 Admin, Inventory Manager, and Cashier Beta Evaluation Questionnaire statements. On 2026-10-30, five of thirteen transaction/browser/service checks passed and eight returned nonzero exits. A separate source smoke check reported five assertion mismatches. Some observations expose application behavior, while others prevent verification because a fixture, selector, prerequisite, or expectation may be stale. Calling all of them application bugs, or marking later unexecuted scenarios passed, would misrepresent the evidence.

The user needs one actionable inventory of what to fix, what to double-check, and what must be tested afterward. Thermal printer and barcode scanner acceptance has already been supplied by the user. Other browser/device observations, respondent ratings, performance measurements, and several transaction/security scenarios remain incomplete.

## Solution

Resolve demonstrated application defects, repair demonstrably outdated test setup without weakening intended behavior, and complete the missing software acceptance evidence. Reuse the existing authenticated browser/HTTP tests and disposable database fixtures, with service-level transaction tests for concurrency, rollback, and reconciliation. The user confirmed these testing boundaries.

Deliver an updated evidence matrix for all 120 statements. Each executed case records expected and actual results, environment, evidence, and any defect/retest link. Distinguish Pass, Fail, Blocked, Not run, user-reported hardware acceptance, and observations requiring a respondent. Report an overall pass only for the scope actually completed.

## User Stories

1. As an Administrator, I want Store Reports to execute their scripts without errors, so that I can change reporting periods and inspect Gross Profit.
2. As an Administrator, I want sales and Gross Profit to reconcile to a prepared ledger, so that I can trust the reported amounts.
3. As a Cashier, I want receipt dismissal to return focus to the ready GCash input, so that I can continue serving customers with the keyboard or scanner.
4. As a Cashier, I want GCash cash-in and cash-out records to preserve type, principal, fee, and reference, so that the service history is accurate.
5. As an Administrator, I want GCash oversight to agree with the Cashier's saved records, so that reviews use the same evidence.
6. As an Inventory Manager, I want receiving controls and tests to describe the intended workflow consistently, so that valid stock receipts can be verified.
7. As an Inventory Manager, I want optional batch details to be reachable through their disclosure, so that I can enter them without broken keyboard or recovery behavior.
8. As an Inventory Manager, I want unfinished drafts and post-commit retries to preserve my work, so that network interruptions do not lose values or duplicate stock.
9. As an Inventory Manager, I want Inventory Counts and Stock Issue reviews to be tested through their real workflow, so that approvals and decisions produce the expected stock effects.
10. As a Cashier, I want a Stock Issue submission to stay pending without deducting stock, so that review remains authoritative.
11. As an Inventory Manager, I want approval to deduct the reported stock once and rejection to deduct nothing, so that the stock ledger remains correct.
12. As a Cashier, I want eligible full and partial Cash Refunds to complete with separate saved receipts, so that the original sale remains available.
13. As a Cashier, I want Pack and Piece refund quantities and sealed-pack eligibility to be clear, so that I do not refund an ineligible return.
14. As a Cashier, I want printing, retrying, refreshing, and reopening receipts to avoid extra financial writes, so that receipts cannot duplicate refunds or sales.
15. As an Inventory Manager, I want valid replenishment recommendations to appear with details, so that I can review stock planning.
16. As an Inventory Manager, I want unavailable, stale, and demonstration guidance to be explicit, so that I do not mistake it for current Store evidence.
17. As an Administrator, I want forecast requests to acknowledge quickly and expose durable job status, so that I can continue other work.
18. As a Store owner, I want simultaneous checkout submissions to commit at most one intended sale, so that cash, stock, and history cannot be duplicated.
19. As a Store owner, I want failed transactions and failed audit writes to roll back consistently, so that partial records do not corrupt operations.
20. As a Cashier, I want held-sale ownership and Shift reconciliation to keep passing after repairs, so that existing working operations remain reliable.
21. As an Administrator, I want staff lifecycle and active Workspace restrictions preserved, so that account management remains secure.
22. As a Store owner, I want login, logout, session, and direct-endpoint authorization tested, so that hidden controls are not the only protection.
23. As an Administrator, I want Fiscal Period closure to reject protected writes, so that closed reporting periods stay intact.
24. As an authorized administrator, I want Database Backup creation and download tested, so that preservation works through the real screen.
25. As an authorized administrator, I want restore expectations aligned with the accepted recovery policy, so that QA does not reject an approved protected workflow.
26. As a Store owner, I want administrative actions to retain correct actor and action details in audit evidence, so that accountability survives repairs.
27. As an Inventory Manager, I want Product editing, CSV import, receiving history, and report exports checked against known data, so that saved and exported values remain correct.
28. As a Store operator, I want actionable errors without production technical details, so that I can correct or report problems safely.
29. As a Store operator, I want required controls to work across supported browser engines and screen sizes, so that the same task remains usable.
30. As a Store owner, I want measured task timings and explicit budgets, so that performance acceptance is based on evidence.
31. As a tester, I want date-independent fixtures with valid Fiscal Periods and required schema, so that tests do not fail merely because the calendar changed.
32. As a tester, I want worker failures and timeouts to preserve useful diagnostics, so that a missing prerequisite is distinguishable from a transaction defect.
33. As a developer, I want source smoke checks to follow current observable contracts, so that renaming or approved UI changes do not create misleading failures.
34. As a Store owner, I want test databases isolated and attributable to their creating run, so that testing and cleanup cannot damage Store records or another person's work.
35. As an evaluator, I want each questionnaire result linked to actual evidence, so that unexecuted and subjective statements are never given invented passes or ratings.
36. As an evaluator, I want previously reported failures explicitly rechecked or left historical, so that old observations are not mistaken for current outcomes.
37. As a Store owner, I want my tested thermal printer and barcode scanner recorded as accepted, so that already supplied hardware acceptance is preserved.
38. As a reviewer, I want remaining Maintainability statements tied to real defect corrections and update builds, so that regressions are demonstrated rather than assumed.

## Implementation Decisions

- This is one remediation and verification specification. It authorizes no new product capability, dependency, public API, schema redesign, background architecture, or expanded role authority. Implement only demonstrated corrections and the minimum fixture/test changes needed to verify accepted behavior.
- Reuse the existing report controls, GCash receipt/dialog behavior, receiving disclosures and draft recovery, Cash Refund completion/presentation, replenishment queue, checkout transaction service, Stock Issue service, Fiscal Period validation, role authorization, and audit boundaries. Fix shared root causes once and inspect their callers before editing.
- Preserve existing user work. Multi-ticket integration merges must use a dedicated integration worktree; do not stash, restore, reset, or discard the root working tree. The tested revision included uncommitted Backup/Restore changes, so implementation must recheck the current state.
- Apply ADR-0001 and ADR-0009 for active Workspace authority, ADR-0002 for operator-facing errors, ADR-0005 for asynchronous forecasting, ADR-0006 and its printing update for preserved receipt details, ADR-0013 for Stock Batch provenance, ADR-0014 for sealed Sales Packs, and ADR-0017 for protected encrypted recovery. No new ADR is needed unless implementation discovers a genuinely new architectural trade-off.
- Gross Profit remains Net Sales minus corresponding product costs, adjusted for returns, and excludes operating expenses. GCash Service remains separate from Product-sale payments and cash.
- The current observed report defect is a duplicate lexical declaration of `hideDates` in the same report-control function. Remove the duplicate behavior at its source and verify all affected report types; do not mask the JavaScript exception.
- Preserve accepted UI behavior when a test expectation is stale. A receiving label change alone does not justify reverting the UI. Reach optional batch inputs through the same disclosure users operate; do not force-fill hidden fields or skip accessibility assertions to obtain a pass.
- For failures whose cause is unconfirmed, first reproduce with valid current fixtures and record whether the defect belongs to application behavior, test setup, or an outdated specification. A disabled assertion or a larger arbitrary timeout is not resolution evidence.
- Do not weaken quantity, cost, stock, refund eligibility, Fiscal Period, session, CSRF, role, or audit checks. Repaired financial transactions retain atomicity and idempotency.
- Do not automatically delete the 35 disposable-name databases seen after the prior run. There was no pre-run inventory, so their provenance is unknown. Future tests must record the exact generated database identity and clean up only resources created by that run.

### Work inventory and acceptance

Evidence IDs T1-T13 identify the transaction/browser report; F1-F5 identify the initial smoke report. Rows marked unverified or historical are investigations, not confirmed application bugs.

| ID | Evidence and classification | Required outcome |
| --- | --- | --- |
| R01 | T7: observed JavaScript defect; duplicate declaration confirmed in current source | Report scripts execute without page errors; changing Gross Profit periods and other report filters remains usable; responsive monitoring completes. |
| R02 | T4: observed GCash focus mismatch after receipt print retry and dismissal | Closing the receipt restores focus to the intended ready input; no unintended printing or extra financial writes; rerun the rest of GCash history, oversight, and role checks. |
| R03 | T2: expected `+ Add to Delivery`, observed `Add To Stock`; likely test/UI wording drift | Confirm intended label; make behavior-focused checks consistent with it; execute actual receiving submission, persistence, validation, and one stock effect. |
| R04 | T3: hidden optional batch input blocked recovery test | Exercise its disclosure normally; complete draft ownership, reload/restart, response-loss retry, retained newer values, Count, and issue review scenarios without bypassing security or visibility checks. |
| R05 | T5: refund prerequisite rejected Pack/Piece and sealed-pack guidance | Reconcile presentation and expectations with ADR-0014; prove full/partial refund completion, eligibility, authorization, separate receipt, unchanged original sale, both paper widths, and no duplicate writes on retry/reprint. Coordinate with existing issue #270. |
| R06 | T8: expected recommendation/details timed out | Diagnose fixture/readiness/selector/application state; prove valid recommendations, details, unavailable/stale/demo states, and recovery after loading failures. |
| R07 | T9: checkout worker did not reach barrier | Capture worker exit/status/error before proceeding; prove overlapping submissions, one committed sale/stock/cash effect, payload mismatch rejection, response-loss recovery, rollback, and cancellation behavior. |
| R08 | T11: no open Fiscal Period covered 2026-10-30 | Give isolated positive tests a valid date-covering period; retain separate closed/no-period rejection tests; prove pending reporting, batch attribution, single approval deduction, and no rejection deduction. |
| R09 | F1: smoke check expects old discount-authorization identifier | Verify the current promotion/discount authorization contract through allowed and forged requests; correct behavior or stale assertion with evidence, without reintroducing obsolete approval paths. |
| R10 | F2: expected System Health maintenance wording absent | Confirm operator guidance against intended System Health behavior; restore required guidance or update a stale wording assertion. Keep this release-gate result separate from the three operational-role questionnaires. |
| R11 | F3: expected rejection-reason wording absent | Prove required rejection/review reasons and actionable validation through UI and direct requests; reject invalid writes without stock changes. Resolve assertion drift only after behavior is demonstrated. |
| R12 | F4: forbidden Register action marker present | Inspect actual action and role/Workspace enforcement; prove Administrator management and rejected unauthorized mutations. Do not infer an authorization hole from a markup marker alone. |
| R13 | F5: expected Cashier Shift ownership wording absent | Verify own-Shift opening, Register assignment/lock, no-Shift checkout rejection, and current explanatory text; preserve working reconciliation and ownership rules. |
| V01 | Unverified monetary and cross-role reporting | Independently reconcile sale amounts, tender/change, historical costs, discounts/refunds, date filters, Gross Profit availability, stock movements, and Cashier-to-Inventory-to-Admin consistency. Repeat unchanged filters and reopen saved records. |
| V02 | Unverified Product, CSV, Count, and Stock Issue workflows | Execute intended Product/planning edits, exact valid CSV import, invalid input rejection, saved receiving/count review state, issue approval/rejection, history, and export-viewer consistency. |
| V03 | Unverified forecasting job lifecycle | Prove authorized request, quick acknowledgment, queued/running/completed/failed or explained unavailable states, durable reopen, and Inventory Manager/Cashier denial of generation/retraining. Do not equate queue rendering with forecast-job completion. |
| V04 | Unverified remaining identity and authorization boundaries | Test bad passwords, sign-out and stale sessions, active Workspace switching, allowed role assignment, direct stock/POS/user/forecast endpoint denial, CSRF, and before/after state for rejected writes. Preserve passing staff lifecycle coverage. |
| V05 | Unverified Fiscal Period, Store Settings, audit, and preservation workflows | Verify saved settings and their effect, protected writes after period closure, correct audit actor/action details, and real Database Backup list/download behavior. |
| V06 | Questionnaire/accepted recovery-policy conflict | Admin Security statement 5 expects restore denial; ADR-0017 and issue #286 define protected Administrator restore. Track the discrepancy and an explicit corrected acceptance statement/version separately from the original supplied text. Test the implemented accepted policy without granting governance, weakening reconciliation, or initiating a live restore. Do not change recovery policy just to make the old questionnaire pass. |
| V07 | Unmeasured performance | Record cold plus five subsequent timings with data size, machine, browser, and network. Report against proposed 2-second search/cart, 3-second save/status, and 5-second dashboard/report targets; these are not approved service levels. Record CSV bytes/rows and leave import-duration acceptance unresolved until a budget is agreed. |
| V08 | Missing second-browser and responsive evidence | Confirm supported browser engines and size matrix, run an approved second browser, and test keyboard focus, clipping, dialogs, form/cart state, and navigation at candidate widths 320/390/768/1024/1440. Emulation does not prove physical-device compatibility. |
| V09 | Unverified Maintainability, update, and respondent scenarios | Use actual original-defect/corrected-build and before/after-update pairs. Preserve historical records and repeat affected operations. Where prerequisites or respondent observations are absent, report Blocked/Not run rather than inventing a rating or pass. |
| V10 | Historical failures not rerun in this session | Recheck the old Gross Profit operating-expense exclusion-note assertion, Stock Issue contract/oversight Fiscal Period fixtures, Inventory Count fixture missing attention state, and broad Cashier suite failures previously recorded as 159/329 baseline versus 160/329 on the compared change. Those counts are historical, not current. Record fresh results and investigate only failures still reproduced. |
| V11 | Disposable database provenance unresolved | Record run identity and exact database names before creation, isolate application/recovery storage, and guarantee scoped cleanup after success/failure. Inventory existing remnants read-only; remove only positively attributed resources. |
| V12 | Incomplete questionnaire traceability | Update all 120 case rows with evidence/status, keep hardware passes attributed to the user, preserve exact original statements, identify approved requirement corrections, and list every remaining gap. Do not translate five passing suites into five passed questionnaire statements. |

## Testing Decisions

- The user confirmed existing authenticated browser/HTTP tests with disposable databases as the primary acceptance seam. Use existing transaction services only where concurrent workers, fault injection, rollback, or full stock/cash reconciliation are more directly observable there. Add no new test framework or production seam.
- Good tests assert what the actor can observe and what is persisted: screen state, exact amounts/quantities, saved identifiers, HTTP authorization outcomes, history, audit attribution, and before/after stock/cash state. Avoid relying solely on source substrings, CSS class names, or arbitrary sleeps. Assert exact copy only when that text is an explicit requirement.
- Prior art includes the passing Cashier checkout/saved-receipt and Administrator staff-management browser suites; the passing MySQL batch receiving test for retries and rollback; and the passing held-sale ownership and Shift reconciliation contracts. Preserve all five passes while addressing failures.
- Reuse GCash, mobile monitoring, receiving, private recovery, forecast decision, refund completion, checkout-attempt, and Stock Issue tests to reproduce the eight nonzero exits. Repair fixtures before concluding the underlying workflow is broken; do not hide a genuine application failure in fixture code.
- Use valid current schema and date-covering Fiscal Periods for success scenarios. Add separate negative scenarios for missing/closed periods and invalid/missing authentication. Do not disable production validation to accommodate incomplete SQLite fixtures.
- For money/stock operations, test normal completion, duplicate submission, lost response, changed payload reuse, unauthorized direct requests, and injected failure. Assert at most one intended committed effect and rollback of partial writes. A response message alone is insufficient.
- Verify full/partial Cash Refunds and immutable saved Sale/Refund Receipt details under accepted opt-in printing rules: unchecked default, explicit per-transaction opt-in, manual print retry, and no duplicate financial writes. Existing issue #270's older manual-only wording must be reconciled with the later accepted printing update rather than copied blindly.
- Preserve the separation of ordinary Administrator restore from platform governance in ADR-0017 and issue #286. Disposable restore tests must retain destination privileged access, security state, attribution, explicit approval/reconciliation boundaries, and package/login/passphrase checks. Reuse the existing recovery work rather than implementing a competing workflow.
- Run checks sequentially where they share mutable resources. Capture process exit status, worker diagnostics, failing stage, exact test database, artifacts, and clean-up outcome. Never run the broad configured-database E2E command against operational data.
- Reproduce current failures before fixes, run the affected regression after each correction, then rerun the selected suite and relevant smoke/style checks. Broaden testing when the changed shared behavior or a new failure justifies it. Record build and dirty-tree state so results remain attributable.
- Acceptance requires R01-R13 resolved with behavioral evidence or a documented, tested correction to a stale expectation. V01-V12 require explicit evidence or an honest remaining status; unresolved required security/data-integrity cases block software acceptance. A green subset is not an overall 120-statement pass. Hardware and human observations retain their own provenance.

## Out of Scope

- Performing fixes or claiming a new test pass as part of publishing this specification.
- Production deployment, destructive tests on the live Store or Hosted Demo, purchases, Store Migration/cutover, and speculative deletion of old databases.
- Replacing existing modules, creating new capabilities, weakening authorization or validation, inventing transaction history, or introducing dependencies merely to improve test output.
- Redoing the user's thermal printer/barcode scanner acceptance. Other physical devices/computers and respondent ratings need their own actual observations and cannot be certified by browser emulation.
- Inventing original defects/update builds, approving performance budgets on the user's behalf, claiming model accuracy from a UI test, or issuing formal ISO certification.
- Duplicating the existing Cash Refund or protected Administrator restore implementations tracked elsewhere. Coordinate with those issues and retain their accepted safeguards.

## Further Notes

Evidence date: 2026-10-30, Asia/Manila. Tested environment: Windows, PHP 8.5.1 CLI, Node.js v26.1.0, headless Chromium 156.0.8078.4. Firefox and WebKit were not installed. Source HEAD was `2a5e3af99ebc3dca3408570110079bc312551b3f`, with existing uncommitted Backup/Restore changes. The results concern that local working tree, not a clean deployed build.

Initial source checks: whitespace, stylesheet bundle, role-capability policy, and workspace routing passed; the smoke command failed five assertions. Follow-up: two of eight browser-suite invocations passed; six stopped on an assertion/timeout/prerequisite; three of five additional service/transaction checks passed and two stopped. T4 reached a saved cash-in receipt with PHP 1,010 collected, PHP 1,000 wallet transfer, and the expected reference before its focus failure. Later assertions in stopped suites are unverified.

Related existing issues: [#270 Cash Refund receipt verification](https://github.com/ZeachDev01/retailmind/issues/270), [#286 protected Administrator restore](https://github.com/ZeachDev01/retailmind/issues/286), and [#282 encrypted preservation/recovery specification](https://github.com/ZeachDev01/retailmind/issues/282). This umbrella spec links and coordinates their work without altering or closing them.

Repository evidence documents: the 120-case Beta questionnaire quality plan, the 2026-10-30 initial software quality report, and the 2026-10-30 transaction/browser report. The issue body intentionally includes the observed failures and acceptance inventory so it remains reviewable even before those local documents are committed. The user's confirmed test boundaries are recorded above. Publish with `ready-for-agent`; no additional triage is needed.
