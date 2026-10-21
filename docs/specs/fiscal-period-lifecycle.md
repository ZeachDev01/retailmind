## Problem Statement

The Store owner needs finalized reporting windows to remain reliable. RetailMind currently allows multiple open Fiscal Periods and overlapping date ranges, permits transactions outside every period, and closes periods without checking unfinished operational work. An overlapping open period can conceal the restrictions of a closed period. GCash and drawer cash movements do not consistently use fiscal protection.

Periods must fit owner-defined reporting ranges rather than a mandatory calendar month. Staff need actionable explanations when a transaction or closure is refused, without losing access to history or the ability to finish reconciliation.

## Solution

Provide one Administrator-governed Fiscal Period lifecycle for the Store. Permit at most one open period, disallow overlapping dates, and require consecutive periods to meet without date gaps. Require every new money or stock movement to have an open period covering its effective date.

The Administrator chooses the first range, which must include the Store's current date before trading starts. Later periods start the day after the previous period ends. Ranges may span any owner-defined number of days. Closing and opening remain explicit Administrator actions; there is no automatic rollover.

Closing is allowed only once the end date has arrived, every Cashier Shift is closed, all GCash sessions are closed and reconciled, and pending Inventory Counts dated within the period are approved or rejected. Closed periods and their dates remain fixed. Later refunds and supported corrections create traceable entries in the current open period while preserving original records.

## User Stories

1. As the Store owner, I want to define a reporting date range of any required length, so that my Fiscal Periods fit how I review the Store.
2. As an Administrator, I want to create the first period with today's date included, so that trading begins within a valid reporting window.
3. As an Administrator, I want a second open period refused, so that only one reporting window accepts transactions.
4. As an Administrator, I want overlaps refused against both open and finalized periods, so that every covered date has one unambiguous period.
5. As an Administrator, I want the next start date to follow the previous end date immediately, so that no reporting dates are skipped.
6. As an Administrator, I want to open the next period explicitly after closing the current one, so that rollover remains under my control.
7. As an Administrator, I want early closure refused, so that a still-running date range is not finalized accidentally.
8. As an Administrator, I want closure refused while any Cashier Shift remains open, so that cashier activity is finished first.
9. As an Administrator, I want closure refused while GCash sessions are open or unreconciled, so that service cash and wallet activity are settled first.
10. As an Administrator, I want closure refused while the period has pending Inventory Counts, so that unfinished stock discrepancies do not enter finalized reports later.
11. As an authorized Inventory Count reviewer, I want to approve or reject submitted counts before closure, so that actual stock differences are handled through the existing review workflow.
12. As an Administrator, I want closure blockers explained with the affected records, so that the responsible staff can finish the work.
13. As a Cashier, I want checkout refused when its date has no open period, so that sales cannot bypass fiscal protection.
14. As a Cashier, I want a later Cash Refund recorded in the current open period, so that the original sale and its closed period remain unchanged.
15. As a GCash operator, I want cash-in, cash-out, funding, and money-affecting corrections subject to fiscal protection, so that GCash follows the same reporting boundary as other Store money.
16. As a Cashier, I want drawer cash movements subject to fiscal protection, so that cash activity cannot escape period controls.
17. As an Inventory Manager, I want Stock Receiving, stock adjustments, count adjustments, opening stock, and supplier-related stock movements protected, so that stock activity follows the same reporting boundary.
18. As a staff member, I want a refused operation to leave balances and records unchanged, so that retrying after the problem is resolved is safe.
19. As a staff member, I want reports and permitted historical views to remain accessible without an open period, so that I can investigate and reconcile problems.
20. As a Cashier or GCash operator, I want session and shift closure to remain available when new transactions are blocked, so that fiscal controls do not trap unfinished sessions.
21. As an Administrator, I want to correct an open period's end date with an audit record, so that an incorrect range can be repaired responsibly.
22. As an Administrator, I want date edits refused if they exclude existing transactions, overlap another period, or break continuity, so that corrections preserve reporting integrity.
23. As an Administrator, I want reopening and edits to finalized period dates refused, so that finalized reporting windows remain stable.
24. As an authorized operator, I want supported corrections recorded as new, linked entries in the current open period, so that the original history remains traceable.
25. As the Store owner, I want existing period conflicts identified before strict enforcement starts, so that transition problems are visible without silently rewriting transactions.
26. As the Store owner, I want simultaneous actions to preserve the same rules as ordinary actions, so that concurrent requests cannot create two open periods or post into a period being closed.

## Implementation Decisions

- Reuse the existing FiscalPeriodGuardService and operational service entry points. Extend their shared protection rather than adding a parallel fiscal subsystem or a dependency. Keep lifecycle validation shared across supported Administrator write routes.
- Preserve the single-Store model and Administrator workspace authority. The owner defines ranges through that workspace; do not add an owner role. Fiscal authority does not grant inventory-review or Cashier permissions. Retain authorization, CSRF protection, and existing table-specific locks.
- Validate real calendar dates and ordered, inclusive date ranges server-side. Use the existing Philippine Store-time conventions consistently for effective transaction dates and the current date, including midnight boundaries.
- Enforce at most one open period, including concurrent create requests. Reject all range intersections, including an end date reused as the next start date. The successor starts on the following calendar day, including across month, year, and leap-day boundaries.
- There may temporarily be zero open periods between explicit closure and opening. No money or stock transaction may use this gap to bypass fiscal protection. An expired period still marked open does not authorize today's activity outside its range.
- Require an open period for each new money or stock movement's effective date. Cover checkout, refunds, receiving and purchase stock, opening stock, stock-affecting product changes, adjustments, counts, Stock Issue inventory effects, supplier pullouts, GCash, drawer cash, imports, and other supported write paths. Find and close alternate-path bypasses rather than relying on disabled buttons.
- Fiscal refusal must preserve existing validation and leave the operation's records, balances, stock effects, and success audit records uncommitted. Preserve existing transaction atomicity.
- Check closure prerequisites at commit time together with period state. Prevent concurrent posting, opening of sessions/shifts, count submission, and period changes from invalidating those checks. Use the existing database transaction and locking patterns; a UI precheck alone is insufficient.
- Treat resolved Inventory Counts as approved or rejected, using existing statuses and reviewer permissions. Do not require every Product to be counted before closure. Approval records any required adjustment before finalization; rejection does not change stock.
- Reuse the GCash reconciliation model to establish that sessions are both closed and reconciled. Merely having a closed status must not conceal unresolved reconciliation work. Explain the remaining blocker using existing operational language.
- Keep read access and Cashier Shift/GCash session closure available when no period covers today. Distinguish completing reconciliation from initiating a new money or stock movement: session closure is not a general bypass for otherwise forbidden postings. Verify the existing settlement paths when implementing this distinction.
- Permit only valid forward lifecycle transitions. Closed and locked periods remain unwritable; crafted requests must not reopen them, change finalized dates, or downgrade locked state. Retain existing locks without introducing a new lock-state design.
- Allow only the Administrator to amend an open period's end date. Preserve its start date, all existing transaction coverage, nonoverlap, and continuity; record actor and before/after values through existing audit facilities. Preserve existing lifecycle audit records.
- Post later refunds and supported corrections in the current open period, linked to their originals. Do not backdate them into a closed period or modify original sales, receipts, or finalized period dates. This does not introduce new kinds of correction workflows.
- Update affected test fixtures and seed/setup paths to establish valid periods instead of depending on missing-period permission. Do not give ordinary application writes an implicit setup bypass.
- Before activation on existing data, inspect missing coverage, overlapping ranges, multiple open periods, and unfinished closure work. Preserve transactions and historical dates, and require reviewed resolution of conflicts before strict activation. Do not invent synthetic transactions, silently reassign history, edit closed dates, or treat this specification as authorization for a production-data repair.
- Show actionable, accessible operation and closure messages using existing components. Keep technical diagnostics in existing logs, following Store alert conventions. No broad fiscal-page redesign is required.

## Testing Decisions

- Proposed test seams, pending the user's confirmation required by to-spec: use existing service entry points with database-backed integration tests for fiscal and transaction behavior, plus the Administrator request boundary for lifecycle authorization and validation. Use a small browser smoke check only for the changed controls and actionable blocker presentation. No new testing framework or production abstraction is needed.
- Good tests call supported operations and assert user-visible results, persisted records, unchanged state after refusal, and audit behavior. Avoid tests that merely search source text or duplicate implementation conditions.
- Follow the existing Cash Refund contract, Inventory Count reconciliation, Cashier Shift reconciliation, and GCash transaction integration tests. Reuse disposable databases and fixtures. Prefer the highest existing seam that exercises the behavior; do not force unrelated workflows behind a new test facade.
- Cover custom multi-month and single-day ranges, invalid dates, reversed ranges, inclusive endpoints, leap days, Philippine midnight, first-period setup, consecutive successors, duplicate opens, overlap with open/closed/locked periods, and gaps.
- Cover absence of a matching period, an expired open period, closed and locked dates, existing table locks, and successful eligible writes. Include every newly guarded money/stock entry point and relevant alternate import or route paths.
- Verify closure refuses each blocker individually, then succeeds after the required work is resolved. Include approved and rejected counts, no submitted counts, and the absence of any requirement to count every Product.
- Verify an October sale refunded in a valid November period leaves October and the original sale unchanged and records November refund effects exactly once.
- Verify valid and invalid end-date changes, audit attribution, unauthorized and forged requests, repeated/stale closure, attempted reopening, finalized-date edits, and locked-state downgrade attempts.
- Verify reports, shift closure, and GCash reconciliation/closure remain reachable with posting blocked, without allowing a new money or stock movement into an uncovered or finalized date.
- Use the actual supported database for concurrency checks: simultaneous opens, period closure against money/stock posting, and closure against new shifts/sessions or pending count submission. Assert preserved invariants and no partial effects.
- Exercise transition diagnostics on conflicting historical fixtures and confirm there are no silent transaction/date rewrites. Verify existing workflow fixtures pass with explicit valid fiscal setup.

## Out of Scope

- Mandatory monthly, quarterly, or yearly ranges; fiscal periods as Cashier Shifts.
- Multiple Stores, branches, tenants, or concurrent operational Fiscal Periods.
- Automatic rollover, automatic reopening, retroactive edits to finalized periods, and backdated corrections into closed periods.
- Mandatory full-store stocktaking at every close, automatic approval/rejection of counts, or new review permissions.
- New financial ledgers, tax-reporting rules, accounting exports, correction transaction types, dependencies, or a general workflow engine.
- A broad interface redesign, notification project, or destructive production-data migration.

## Further Notes

Policy decisions were confirmed in the design discussion on 2026-10-22. This specification records requested behavior; no application implementation or database repair has been performed.

Current code exploration found missing-period permission, ambiguous overlap selection, insufficient lifecycle transition checks, absent closure prerequisites, and missing fiscal checks in GCash and drawer cash workflows. Existing Inventory Count approval checks both the original count date and the adjustment date, which is why pending counts must be resolved before finalization.

No actual operational database was audited during specification writing. Existing-data resolution must respect immutable history; if the discovered conflicts cannot be resolved within these agreed rules, report the concrete conflict for a separate decision instead of silently relaxing the rules.
