# Batch expiration, supplier replacements, and consistent stock balances

## Problem Statement

The same Product can arrive in several batches with different expiration dates. Recording the latest received expiration on the Product can hide older stock that expires sooner. Bread with a one-week shelf life also needs a short warning period: a 30-day warning marks every delivery immediately.

Staff need to see which batches expire soon, which have missing dates, and what the supplier collected and replaced. Some existing stock counts and approved Stock Issues change total inventory without changing batch quantities. This can leave a total of 17 loaves while the batch still claims 20, making warnings, availability, and pullouts unreliable.

## Solution

Keep an expiration date on each Stock Batch. Keep **Starting Stock Expiration** in Add Product only for starting stock, and capture each later batch through Stock Receiving. New receiving never replaces the expiration of existing batches.

In Stock Overview, keep one row per Product and show **On Hand**, **Expired Qty**, **Next Expiration**, and **View Batches**. Add a **Missing Expiration** filter and show **Qty Without Date** in that view. View Batches shows each batch's source, remaining quantity, expiration, and status.

Inventory Managers can set **Expiry warning lead time (days)** on a Product. Setting bread to two days applies to overview warnings, FEFO urgency, and future notifications. For an October 25 expiration, warn October 23 through October 25; mark it expired on October 26, using Asia/Manila dates. An unset override retains current channel defaults.

Record Supplier Pullouts against the actual old batch, then receive Replacement Stock separately with its own quantity and expiration. Link replacements to the pullout, including partial or later deliveries. Every stock change saves the total quantity, affected batch quantities, and movement history together. Existing differences require a physical count and recorded correction, never guessed dates or silently rewritten balances.

## User Stories

1. As an Inventory Manager, I want to enter starting stock and its expiration when creating a Product, so that stock already in the Store has an Opening Batch.
2. As an Inventory Manager, I want to create a Product without starting stock, so that I can receive its first delivery later without creating an empty batch.
3. As an Inventory Manager, I want to receive a new batch without changing old batch dates, so that older stock remains visible.
4. As an Inventory Manager, I want separate receiving entries for quantities with different dates, so that one delivery can contain several expiration dates.
5. As an Inventory Manager, I want different batches to be allowed to share a date, so that actual supplier labels can be recorded accurately.
6. As an Inventory Manager, I want expiration to remain optional, so that general merchandise can be recorded without a fabricated date.
7. As an Inventory Manager, I want missing dates labelled Not recorded, so that an unknown date is not mistaken for a non-expiring item.
8. As an Inventory Manager, I want to set bread's warning period to two days, so that warnings begin when action is useful.
9. As an Inventory Manager, I want one product override to govern warnings and future notifications, so that bread is not flagged by an earlier global milestone.
10. As an existing operator, I want products without an override to keep their current warning defaults, so that this change does not silently reset existing settings.
11. As an Inventory Manager, I want one inventory row per Product, so that stock remains easy to scan.
12. As an Inventory Manager, I want the next eligible expiration date, so that I can prioritize older stock.
13. As an Inventory Manager, I want expired quantity shown separately, so that healthy batches do not hide expired goods still on the shelf.
14. As an Inventory Manager, I want a View Batches modal, so that I can inspect the quantities and dates behind a product summary.
15. As an Inventory Manager, I want to retain depleted batch history, so that previous receiving and movements remain traceable.
16. As an Inventory Manager, I want a Missing Expiration filter, so that I can find remaining stock whose dates were not recorded.
17. As an Inventory Manager, I want that filter to include products with both dated and undated batches, so that a known date does not hide missing information.
18. As an Inventory Manager, I want quantity without a date shown in the filtered view, so that I know how much stock needs checking.
19. As an Inventory Manager, I want empty or depleted batches excluded from active warning counts, so that old history does not create false warnings.
20. As a Cashier, I want sales to use the earliest-expiring eligible stock, so that dated stock is used before it expires.
21. As a Cashier, I want expired stock excluded from saleable stock, so that a healthy batch does not make expired units available for sale.
22. As an Inventory Manager, I want to record the exact batch and quantity collected by a supplier, so that remaining stock matches what is physically present.
23. As an Inventory Manager, I want to record a completed pullout without another approval stage, so that routine collections can be recorded directly.
24. As an Inventory Manager, I want replacements received separately and linked to the pullout, so that delayed, partial, or differently sized deliveries retain their true history.
25. As an Administrator, I want read-only pullout and replacement oversight, so that I can review stock handling without performing inventory work from the oversight workspace.
26. As an Inventory Manager, I want approved damaged or expired Stock Issues to deduct the affected batches and total together, so that discarded units cannot be deducted again or sold.
27. As an Inventory Manager, I want stock counts to identify batch shortages and extras, so that a correction fixes both batch quantities and total inventory.
28. As a Cashier, I want eligible customer returns restored to their original sale batches, so that refund handling preserves traceability.
29. As an operator, I want a failed stock update to save nothing, so that half-completed operations cannot create mismatched quantities.
30. As an operator, I want simultaneous deductions checked against the latest remaining quantities, so that two users cannot take the same stock.
31. As an Inventory Manager, I want existing balance differences shown clearly, so that I can physically count and record corrections instead of trusting invented batch history.
32. As an operator, I want dated reports, product details, scanning results, and warnings to use the same underlying batches, so that different screens do not contradict each other.

## Implementation Decisions

### Batch ownership and receiving

- Reuse existing Product, Opening Batch, Stock Receiving, inventory, movement, sale allocation, and notification boundaries. Keep the existing architecture and add only the state needed for these behaviors.
- Stock Batch owns expiration and remaining quantity. An internal batch identity is unique; expiration dates are not. Quantities use the existing Base Unit and receiving package conversion rules.
- Starting Stock Expiration belongs to the Opening Batch and is shown only when starting stock is positive. Zero starting quantity creates no quantity-bearing batch. Product editing must not rewrite existing batch dates.
- Stock Receiving records actual printed or supplier-confirmed dates. Separate expiration groups require separate receiving entries. Preserve current complete-submission validation, duplicate-product protections, and atomic multi-product receiving; supporting repeated Product lines in a single submission is not required.
- Remove the latest received Product expiration as an operational source of truth. Update product summaries, expiry reports, scanner/POS callers, and exports that currently read it or use it as a fallback. Preserve legacy evidence during migration; do not drop historical dates as an incidental cleanup.
- Omitted expiration remains unknown. Do not infer seven days from a bread receipt, extend an old date when replacements arrive, or infer that undated goods never expire. Validate supplied calendar dates strictly.

### Inventory display and warnings

- Stock Overview keeps one Product row. On Hand represents physical recorded stock, including expired units until a recorded removal; label saleable quantities distinctly wherever shown.
- Next Expiration is the earliest recorded date among remaining unexpired batches. Expired Qty is the sum of remaining expired units. No eligible dated batch must not display an old Product-level date as the next expiration.
- View Batches shows Batch, Source, Remaining Qty, Expiration, and Status. Identify opening versus received stock; expose replacement linkage when present. Order remaining dated batches by expiration, and keep depleted history accessible without counting it as current stock.
- A Product with expired and healthy stock must visibly retain Expired Qty. Do not rely solely on the single status label to convey batch conditions. Expired stock has priority for that existing label.
- Missing Expiration matches any Product with at least one positive-remaining undated batch, including mixed dated/undated stock. Show Qty Without Date in this view; View Batches identifies those batches. Exclude depleted batches and out-of-stock products.
- Unbatched legacy on-hand stock must be visibly flagged as a reconciliation exception rather than appearing fully dated. Missing date is an information gap, not an expired-stock classification. An absent warning override is not a missing expiration.
- Add nullable per-Product Expiry Warning Lead Time, editable by Inventory Managers through Add/Edit Product. Accept a validated positive whole number; blank means use existing defaults. Bread's two-day override is an example, not a category-wide automatic rule. Keep existing global-setting governance unchanged.
- With an override, remaining dated stock warns when its days remaining are from zero through that lead time, inclusive. Use the same rule for Stock Overview, operational dashboard expiry warnings, FEFO urgent styling, and new notification eligibility. Explicitly labelled fixed analytical windows, such as a 90-day insights total, retain their analytical meaning.
- No override preserves existing channel defaults, including the overview's 30 days, FEFO's seven-day urgency, and configured notification milestones. Do not backfill every Product with 30 and thereby change existing notification behavior.
- Overrides replace earlier global notification milestones for that Product's future alerts. Preserve old notifications, deduplicate alerts using existing batch/user semantics, and retain separate expired-stock notifications. Depleted batches generate no new warnings.
- Use Asia/Manila calendar dates consistently in database queries, services, and displayed days remaining. Expiration day remains eligible through that day. Warning lead time changes attention, not shelf life, stock quantity, or FEFO allocation order.

### Supplier pullouts and replacements

- Add a supplier-pullout workflow under the existing Inventory Control navigation group. Return to Supplier in View Batches opens it with Product and batch selected. Inventory Control is a group, not an existing dedicated supplier-return page.
- Only an authorized active Inventory Manager workspace may mutate stock or warning overrides. Preserve existing Administrator oversight, workspace boundaries, CSRF, Fiscal Period, and Store write protections. A Cashier's Stock Issue reporting remains a separate workflow.
- Record a completed physical collection directly, with selected batch, positive whole-number Base Unit quantity, reason, staff, time, and movement history. Validate the batch belongs to the Product and requested quantity does not exceed its remaining balance. Allow collection of expired goods; expiry alone never deducts physical stock.
- Link the completed pullout to subsequent replacement receiving. Allow partial or later replacements, and quantities that differ from the pullout; do not force a one-for-one quantity or treat linked receiving as proof of a financial settlement. Record actual supplier identity/reference using existing conventions.
- Preserve pullout and receiving history. Do not reset the old batch's date or quantity to represent fresh goods. Supplier Pullout is distinct from customer Cash Refund and from a Stock Issue's returned-for-correction status.

### Consistent balances and existing records

- For reconciled stock, total On Hand must equal the sum of batch remaining quantities, including expired and undated physical stock. Record every increase or decrease against affected batches and the aggregate total in the same transaction as movement history. A failure rolls back the complete operation.
- Reach every quantity writer: starting stock, receiving, sales, supplier pullouts, stock counts, approved Stock Issues, customer refunds/restocks, and applicable import or adjustment entry points. Audit callers and existing fixtures rather than fixing only the new return action.
- Keep existing sale batch allocation and customer refund lineage. Restore only eligible restockable returned quantities; a damaged customer return must not be deducted from available inventory a second time. Restored batch dates still govern eligibility.
- Damage, disposal, and supplier pullout require the physically affected batch. A cashier may report an issue without knowing it, but manager approval must resolve the affected quantities before deduction. Counts record counted quantities by batch, including shortages and extras; an added quantity with unknown date remains explicitly undated.
- Lock and revalidate aggregate and batch quantities inside each write transaction. Preserve existing duplicate-submission guards and prevent retries from applying the same approved movement twice. Concurrent stock changes must not create negative balances or invalidate the sum.
- Detect and expose legacy missing batches or mismatches before presenting their balances as reliable. Do not silently redistribute quantities, copy a latest Product date onto older stock, erase history, or assume an empty set of batches means stock is safely dated.
- Reconcile differences through an Inventory Manager's physical count by batch and a traceable correction. Preserve historical evidence and original sale allocation links. If historical dates or quantities cannot be established, retain unknown values or visibly unresolved discrepancies and request the necessary physical information.
- Schema migrations and fresh setup must agree on the optional warning override, pullout records, replacement linkage, and batch movement attribution. Upgrade existing installations without deleting receipts, receiving history, inventory evidence, or legacy date evidence. Do not introduce a second competing stock ledger or a procurement subsystem.

## Testing Decisions

The user confirmed the approach: exercise real receiving, sales, return, and count workflows against a test database, plus browser checks for the inventory table, filters, and warnings. Prefer existing application/service and HTTP entry points over new low-level test seams. Test externally observable saved quantities, dates, eligibility, history, and permissions, rather than asserting helper shapes or SQL strings.

- Reuse prior art from receiving submission/history contracts and browser fixtures, Stock Issue correction contracts, refund transaction/concurrency integration tests, inventory count browser checks, and inventory alert contracts. Use isolated synthetic data, not the active Store database.
- Starting stock and receiving: no-starting-stock creation; opening expiration; different and identical dates on separate batches; multiple-date deliveries as separate entries; strict invalid-date handling; optional dates; receiving that preserves older dates; and rollback of the entire failed receiving submission.
- Warnings: two-day bread boundary before, on, and after its warning and expiration dates; no-override defaults; consistent Manila midnight behavior; no early global alerts for overridden products; notification deduplication; depleted stock; optional missing dates; and expired physical stock remaining recorded until removed.
- Display: one row per Product; earliest eligible date; expired quantity alongside a healthy batch; mixed dated/undated stock in Missing Expiration; Qty Without Date; exclusions for depleted/out-of-stock stock; legacy mismatch visibility; batch drilldown; accessible labels, keyboard operation, focus return, and narrow screens.
- Complete balance scenarios: begin with 20 loaves, approve three damaged, and verify both total and batch show 17 with one movement. Exercise equivalent invariants after receiving, FEFO sales, counts, partial pullouts, and customer restocks. Verify no double deduction of damaged customer returns.
- Replacement scenario: collect eight old loaves and separately receive 30 fresh loaves; verify minus eight from the selected old batch, plus 30 in a new batch, both dates retained, correct total, linked history, and partial/delayed replacement support.
- Safety: reject wrong-Product batch selection, excessive/negative/fractional quantities, unauthorized mutations, duplicate saves, and concurrent overlapping deductions. Inject a write failure to prove total, batches, movement, and pullout/receiving records all roll back together. Preserve unrelated Stock Issue lifecycle transitions.
- Legacy reconciliation: identify totals with missing or unequal batch coverage; require an explicit counted correction; retain unknown dates and historical sale/refund lineage. Verify upgrades and fresh installations support the same accepted workflows.

## Out of Scope

- Supplier credits, balances, payment settlement, or automatic proof that a replacement settled a financial obligation.
- Purchase requests, purchase orders, supplier administration, new approval chains, and automatic ordering.
- Mandatory expiration dates or a new perishable-product classification.
- Automatic shelf-life calculation, automatic date extensions, automatic disposal, fabricated historical dates, or silent stock reconciliation.
- Expanding operational permissions for Administrator or Super Administrator workspaces.
- Replacing existing FEFO, customer refund policy, forecasting, or fixed analytical horizons with a new subsystem.
- General editing of historical batch expiration dates, or reopening/deleting completed movement history.

## Further Notes

- Business decisions were confirmed on 2026-10-18, including the Missing Expiration filter. The user subsequently confirmed that all stock changes must update total and affected batches together, and accepted the testing approach during specification drafting.
- Follow accepted ADR-0013 and the batch-expiration context. This feature extends direct receiving and Inventory Control without undoing ADR-0011's retirement of procurement.
- Existing product editing work may overlap the Add/Edit Product controls. Coordinate with that work and preserve unrelated changes.
- This specification describes required behavior. It does not claim implementation, migration, physical reconciliation, or application tests have already been completed.
