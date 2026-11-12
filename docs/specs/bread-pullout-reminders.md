## Problem Statement

Inventory Managers need to know which supplier-returnable bread batches to prepare for collection before they expire, and which expired batches still await removal. Expiry alone does not establish Supplier Pullout Eligibility: ordinary wholesale purchases must not fill the Supplier Pullout workflow or be labelled as awaiting supplier collection. Existing expiry notifications do not reliably provide the continuing task for eligible stock: transaction-triggered checks honor product warning settings, while the separate scheduled check uses a fixed 30-day window. Reading an alert or recording a partial collection must not hide stock that still needs attention.

## Solution

At Add Product, let the Inventory Manager explicitly enable Supplier Pullout Eligibility for that Product under its supplier return arrangement. Leave the setting off unless selected; existing Products remain ineligible without inferring eligibility from bread, category, supplier text, expiration, or historical pullouts. Eligibility belongs to the Product, not each delivery or Stock Batch.

Use the existing in-app expiry reminder and Supplier Pullout workflows to show batch-specific reminders for eligible Products to Inventory Managers. Start according to each Product's Expiry Warning Lead Time, check daily without requiring a sale or receiving event, and keep the affected batch actionable while bread remains. After expiration, show **Expired bread—waiting for supplier collection** only for eligible bread. Other Products retain ordinary expiry warnings and Stock Issue review without supplier-collection wording or pullout actions. Staff record actual collection or other authorized removal; the reminder never deducts stock.

For example, a batch expiring October 25 with a two-day Product setting becomes actionable October 23. A five-day setting starts October 20 instead. Expiration day remains unexpired through the end of October 25 in Asia/Manila; October 26 uses the expired wording. Collecting eight of ten loaves leaves a reminder for two. Recording removal of those final two clears the active reminder.

## User Stories

1. As an Inventory Manager, I want a reminder before bread expires, so that I can prepare it for supplier collection.
2. As an Inventory Manager, I want timing to follow the Product's warning setting, so that different products receive appropriate notice.
3. As an Inventory Manager, I want a changed warning setting respected by future checks, so that the reminder follows my current configuration.
4. As an Inventory Manager, I want existing defaults retained when no Product override is set, so that leaving a setting blank preserves established behavior.
5. As an Inventory Manager, I want daily reminder checks even when no stock transaction occurs, so that quiet trading days do not delay warnings.
6. As an Inventory Manager, I want reminders inside RetailMind, so that I can review them during inventory work.
7. As an Inventory Manager, I want each reminder to identify the Product, Stock Batch, expiration, and remaining quantity, so that I can find the affected bread.
8. As an Inventory Manager, I want separate batches of the same Product tracked separately, so that collecting one batch does not hide another.
9. As an Inventory Manager, I want reading a reminder to leave the underlying task active, so that unread status is not mistaken for collection status.
10. As an Inventory Manager, I want partial collections to leave a reminder for the remaining quantity, so that bread is not overlooked.
11. As an Inventory Manager, I want the active reminder cleared when the affected batch is depleted, so that completed work stops appearing as outstanding.
12. As an Inventory Manager, I want expired bread labelled as waiting for supplier collection, so that overdue stock remains visible.
13. As an Inventory Manager, I want expiration and reminders to leave physical stock quantities unchanged, so that recorded inventory matches actual removals.
14. As an Inventory Manager, I want to record actual supplier collections through the existing Supplier Pullout workflow, so that quantities and staff attribution remain traceable.
15. As an Inventory Manager, I want repeated checks to avoid duplicate active reminders, so that one outstanding batch does not create notification clutter.
16. As an Inventory Manager, I want undated and depleted batches excluded from new date-based reminders, so that warnings do not invent expiration dates or refer to absent stock.
17. As an Administrator, I want to retain oversight, so that I can review stock handling without gaining inventory mutation rights.
18. As an Inventory Manager, I want fresh Replacement Stock tracked separately, so that its arrival does not falsely complete collection of the old batch.
19. As an Inventory Manager, I want to explicitly enable supplier pullouts when adding a Product, so that only intended supplier-returnable goods enter that workflow.
20. As an Inventory Manager, I want existing and unselected Products excluded from Supplier Pullout, so that ordinary expired goods retain expiry warnings and Stock Issue review without implying a supplier collection.

## Implementation Decisions

- Extend the existing notification service, scheduled operational check, notification presentation, and Supplier Pullout integration. Reuse current Product settings, Stock Batch balances, notification identity, and inventory authorization. Do not create a separate bread ledger or parallel reminder system.
- Treat bread as the motivating use case, not an automatic eligibility rule. Add an explicit Product-level Supplier Pullout Eligibility setting controlled by the Inventory Manager at Add Product. Default it off and leave existing Products ineligible; do not infer or backfill eligibility. No separate per-batch setting, bread category, automatic classification, or supplier timetable is required.
- Apply Product eligibility consistently to Supplier Pullout product selection, batch lookup/prefill, recording validation, and notification wording/actions. Reject an ineligible Product at the recording boundary even if a caller bypasses the picker. Preserve ordinary expiry warnings and Stock Issue review for ineligible Products; they must not say they await supplier collection. Keep historical completed pullouts accessible without treating their Products as eligible.
- Inventory Managers receive actionable in-app reminders; Administrators retain existing oversight. Preserve established account eligibility, notification preference handling, workspace restrictions, and unrelated delivery behavior.
- A configured Product warning lead time replaces earlier global expiry milestones. Before expiration, eligibility requires a dated batch with positive remaining quantity and days remaining between zero and the configured lead time, inclusive. Without an override, retain existing channel defaults and global-setting governance.
- Make the daily scheduled expiry check use the same Product-aware eligibility as transaction-triggered checks. Remove the competing fixed 30-day eligibility from that scheduled expiry path while preserving unrelated scheduled checks. Verify the deployment's existing scheduler invocation; a script that nobody schedules does not satisfy daily delivery.
- Daily means evaluate daily, not create another copy of every outstanding reminder each day. Reuse batch/user notification identity and existing milestone behavior where applicable; ensure repeated runs and transaction-triggered checks do not duplicate the same active batch condition.
- Use Asia/Manila calendar dates consistently. A batch becomes expired on the day after its recorded expiration, never at the start of expiration day.
- Derive the active condition and displayed remaining quantity from current batch balances. Reading a notification affects read status only. Partial collection leaves the remaining quantity actionable; depletion through any existing authorized stock movement clears the active condition.
- An expired positive-remaining bread batch whose Product is eligible displays **Expired bread—waiting for supplier collection**. Keep that condition visible until the remaining quantity is recorded as removed. Preserve historical notifications and pullout records when the active condition clears; historical text must not be presented as a current outstanding task.
- Integrate current batch state into existing notification reads/presentation so partial or full removal is reflected without waiting for the next daily run. Do not depend solely on the Supplier Pullout writer, because sales or other authorized movements may also deplete a batch.
- Show the affected Product, batch identity, expiration, and current remaining quantity. Reuse existing batch details and Supplier Pullout navigation where available, respecting active workspace permissions.
- Keep Supplier Pullout recording transactional and validated. A reminder, read action, or date transition must never create a stock movement, change expiration, or record a collection. Fresh Replacement Stock remains a separate receiving event.
- Undated stock cannot generate a date-based reminder. Do not infer expiration, silently reconcile stock discrepancies, or backfill dates. Depleted history remains accessible without being active work.
- Prefer existing storage and contracts; persist explicit Product eligibility with existing Products defaulting to ineligible. Reuse existing notification identity and current batch state rather than introducing a parallel reminder system.

## Testing Decisions

- Prefer the existing workflow/service integration seam: execute the daily notification check and existing stock workflows, then inspect the resulting Inventory Manager notification output and current batch balances. Test external behavior rather than private helpers, SQL text, or chosen storage structure.
- Existing prior art includes Product warning-lead-time integration coverage, Supplier Pullout transaction coverage, and notification-list browser coverage. Extend those patterns instead of introducing a new test framework or a second reminder abstraction.
- Cover Add Product with eligibility selected and unselected, existing Products remaining ineligible, and bread without eligibility remaining excluded. Verify eligible Products across picker, batch lookup/prefill, direct recording requests, and reminder actions. Ineligible expired stock must retain ordinary expiry warnings and Stock Issue review without supplier-collection wording; historical pullouts remain readable and do not enable eligibility.
- Cover before/on/after warning boundaries for at least two configured lead times; an unset override retaining existing defaults; and a Product setting change respected by later checks.
- Cover Manila midnight: expiration day remains unexpired, the next day changes wording, and daily checks work without a sale or receiving event.
- Cover two batches of the same Product, repeated daily runs, and overlapping scheduled/transaction-triggered checks. Expect one active condition per user and batch, with existing default milestone semantics preserved.
- Exercise reading a reminder, collecting part of a batch, then removing the remainder. Assert current wording and quantity, continued visibility after reading and partial collection, clearance after depletion, retained history, and correct batch/total stock movements.
- Cover depletion through an existing non-pullout workflow and receiving Replacement Stock separately, proving the reminder follows the old batch's actual remaining balance.
- Cover expired remaining stock, undated stock, zero remaining stock, account/recipient eligibility, Administrator oversight, and existing notification preferences. Assert that reminder generation and date changes never alter stock quantities.
- Use controlled dates with the project's existing clock/date test support. Avoid tests that depend on the machine's current date or timezone. Confirm the production database date-query behavior for the scheduled path as well as lightweight integration fixtures.
- The user confirmed testing through existing workflows: inspect what the Inventory Manager sees after the daily check, a partial collection, and full removal. No implementation or test execution is part of this specification task.

## Out of Scope

- Implementing the feature during this specification task.
- A fixed two-day rule for all bread, new category classification, or supplier collection calendars.
- Per-delivery eligibility settings, eligibility editing for existing Products, and automatic eligibility backfills.
- New email, SMS, push, snooze, escalation, or reminder-frequency settings.
- Automatic stock deductions, automatic supplier collections, automatic replacements, or financial settlement.
- Rewriting historical batch dates, guessing missing dates, or reconciling legacy inventory discrepancies.
- Redesigning notification preferences, unrelated alerts, role permissions, or the wider inventory interface.

## Further Notes

The behavior was agreed on October 19, 2026. Two days is an example; the user explicitly required the warning setting for that Product.

On November 12, 2026, the user clarified that Supplier Pullout must be explicitly enabled by the Inventory Manager at Add Product, rather than by delivery or automatic bread/expiry classification. Existing Products remain ineligible; only newly added Products explicitly enabled for pullout enter that workflow. Other expired Products retain expiry warnings and Stock Issue review.

The user confirmed this complete flow on November 12, 2026. This confirms the specification; implementation remains outside this discussion task.

This extends the accepted batch-expiration and supplier-replacement design in ADR-0013 and GitHub issue #216. Reuse that work rather than reopening its stock ownership, replacement, or authorization decisions.

Source inspection identified a Product-aware transaction notification path and a separate fixed-window scheduled path. The live Product settings and installed daily scheduler have not been verified. Implementation must validate the actual end-to-end daily path before claiming delivery works.
