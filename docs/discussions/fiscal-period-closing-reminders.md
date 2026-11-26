# Fiscal period closing and reminders

Status: behavior confirmed by the user; implemented and verified. See [verification results](../verification/fiscal-closing-2026-11-26.md).

## Confirmed need

- Staff must be able to finish old Cashier Shifts and GCash Service Sessions so the Administrator can close the expired Fiscal Period and proceed to its successor.
- Affected users should receive advance notice to finish outstanding work before the period ends, avoiding a trading interruption caused by unresolved closure prerequisites.
- Reconciliation and closing must remain available when today's Fiscal Period is scheduled or missing. This is already required by `docs/specs/fiscal-period-lifecycle.md`; it does not authorize new money or stock movements outside an open period.
- Notify affected Cashiers and Inventory Managers one Philippine calendar day before the Fiscal Period's end date, replacing the proposed three-day lead time for this reminder.
- Use the notification bell and SweetAlert. Email is not part of the requested delivery channels.
- Retain Administrator oversight and the previously accepted relevant-user scope. Give recipients their own pending actions and appropriate destinations.
- Late completion is reconciliation-only: old shifts/sessions may close, including legacy open sessions from finalized periods. Record actual closing times, counts and variances; preserve original money/stock transactions. Later reconciliation can appear in historical session reports.
- New sales, GCash money settlement/returns and stock-affecting count approvals retain fiscal checks and must wait for an eligible open period. A late session close does not reopen or change the period's status/dates.
- SweetAlert appears once per day per user/Workspace in the browser while work remains, including a first return after the advance-warning date. Reading a notification does not resolve the work; completing it resolves the bell notice.

## Source findings

- `GCashLiveCustodySession::closeSession()` incorrectly called `assertOpenNow()` for GCash session closing. The fix removes this posting check from reconciliation-only closure while preserving authorization, validation, Store write coordination and audit.
- Fiscal Period closure requires unfinished operational work to be resolved. Opening a successor requires the preceding open period to close first. Blocking reconciliation can therefore prevent both transitions.
- Existing Cashier Shift reminders and role-based Notifications provide established delivery and deduplication conventions. The fiscal reminder uses scoped attention states, required in-app notices without queued email, and the existing SweetAlert/button styles. General Administrator fiscal warnings and independent daily Shift reminders remain available.
- No live Store data or session state has been changed during this discussion.

## Delivery

The daily notification runner creates notices for eligible active recipients, including secondary assigned roles. Shared shell rendering and authenticated background polling refresh current-work notices for signed-in users. Popups defer while hidden, locked, processing payment/actions or showing another dialog. Polling does not extend the staff idle-session deadline.

## Verification

Verified supported GCash closing with today's posting blocked, preserving counted balances, variances, authorization and actual closing timestamps. New money movements remain blocked. Service and browser tests cover relevant recipients, Philippine reminder timing, daily popup dismissal, repeated polls and resolution. TestSprite passed all 22 recorded steps on the corrected UI flow; full commands and limits are in the linked verification report.
