# Batch expiration and supplier replacement context

Captured 2026-10-18. This document records the confirmed direction, verified code behavior, and agreed missing-expiration filter. It is an implementation brief, not a claim that the designed features exist. See [ADR-0013](adr/0013-batch-expiration-and-supplier-replacements.md).

## Understanding reached

- A Product describes an item; a Stock Batch describes a quantity received with its own expiration. Different batches may share an expiration date.
- Keep expiration in Add Product for starting stock, labelled **Starting Stock Expiration**. Starting stock creates an Opening Batch; later receiving creates separate batches without changing that opening batch's expiration.
- Stock Receiving records actual batch expiration. Split quantities with different expiration dates into separate receiving entries. Use the printed or supplier-confirmed date, rather than assuming bread always arrives with seven days remaining.
- Preserve depleted batch history. Expiration passing changes eligibility and warnings; it does not itself remove physical stock.
- Supplier pullouts reduce the selected old batch by the actual quantity collected. Fresh replacement stock is a separate receiving event with its own quantity and expiration. Preserve both events rather than editing the old batch to look fresh.

## Existing behavior verified in code

| Area | Current behavior and source |
|---|---|
| Starting stock | `ProductService.php` creates a batch with its supplied expiration. It also stores a product-level expiration. |
| Receiving | `ReceivingService.php` inserts receiving and batch records, then overwrites the product-level expiration when a new date is supplied. The original batch date is retained. |
| Stock Overview | `inventory_overview.php` calls `InventoryService::getExpiringSoonBatches(30)`. Its summary counts batches, while its filtered list shows products. Expired status takes precedence over expiring status. |
| Batch warnings | `InventoryService.php` considers remaining quantity greater than zero. Expiring soon includes today through the selected horizon; expired means earlier than today. |
| FEFO | `SalesWorkflowService::buildFefoBatchPlan()` excludes expired stock, selects dated batches first by expiration, then received date and batch ID, and takes undated batches last. The overview shows up to ten recommendations and uses a separate fixed seven-day urgent style. |
| Notifications | `NotificationService.php` uses Store Setting `expiry_alert_days`, defaulting to milestones 90, 60, 30, 14, and 7 days. It deduplicates per user, batch, and milestone; expired warnings are deduplicated per day. This differs from the overview's fixed 30 days. |
| Other readers | Product details and expiry reports still read or fall back to product-level expiration. The scanner API already calculates a next expiration from remaining eligible batches. |
| Inventory Control | ADR-0011 defines counts and Stock Issue review. A batch-specific supplier-return workflow has not been established by this conversation's earlier suggestions. |
| Balance gap | `InventoryCountService.php:129` and `StockIssueService.php:524` update aggregate inventory without batch balances. Supplier Pullout must not reuse those writes without addressing allocation. Stock Issue status **returned** means returned for staff correction, not supplier collection. |
| Refund lineage | `CashRefundService.php:757` restores original sale batch allocations. Preserve that lineage. Sales retain a legacy unbatched fallback when no remaining batch rows exist. |
| Additional horizons | `DashboardService.php:74` uses 30 days; `inventory_insights.php:29` uses 90 days. Decide which are operational warnings and which are intentionally fixed analytical windows. |

Inventory Control is a navigation group, not a standalone page. Base-role inventory mutation belongs to the Inventory Manager; Administrator and Super Administrator oversight does not grant the same operational mutation rights. The global expiry thresholds are read from `store_settings` but their current editing surface is Platform Governance (`system_administrator/system_settings.php`), so ownership cannot be inferred from the table name alone.

Source paths above are relative to `src/backend/app/Services/` or `src/frontend/components/inventory_management/`; other readers include `src/frontend/components/report/report_generation.php` and `src/frontend/components/barcodeScanner/apiScanner/products.php`.

## Agreed screens and workflow

1. **Products / Add Product:** enter starting quantity and its expiration when starting quantity is positive. Product details must not act as a bulk editor for existing batch dates.
2. **Stock Receiving:** enter each new batch or replacement. Receiving History preserves staff, quantities, dates, and supplier/reference details.
3. **Stock Overview:** retain one row per product; show **On Hand**, **Expired Qty**, **Next Expiration**, and **View Batches**. Next Expiration means the earliest dated expiration among remaining unexpired batches. Missing dates display **Not recorded**, not an assertion that stock never expires. Expired quantity stays separately visible, including when another batch is healthy.
4. **View Batches modal:** show **Batch**, **Source**, **Remaining Qty**, **Expiration**, and **Status**. Proposed source labels distinguish opening stock and receiving. Sort dated remaining batches by expiration; retain history access for depleted batches. Offer **Return to Supplier** for authorized inventory work.
5. **Inventory Control:** the proposed return action opens a batch-selected supplier pullout form. Record quantity, reason, staff, time, and movement; reduce batch and inventory together. Link replacement receiving to the pullout if that relationship is adopted. This adds a workflow; a stock count is not proof of a supplier return.
6. **Sales:** keep existing FEFO allocation and have staff pick the corresponding physical batch. Software allocation alone cannot establish which loaf was actually picked.

Example: an opening batch has 20 loaves expiring November 1; receiving adds 30 expiring December 1. Both dates survive, inventory shows 50 on hand and November 1 as Next Expiration. If eight old loaves are pulled out and 30 replacements arrive, record minus eight against the old batch and plus 30 in a new batch. Returned quantities cannot subsequently be sold or deducted again as expired disposal.

## Agreed warning policy

- Add an optional product **Expiry warning lead time (days)**, with bread set to two days. Products without an override retain existing channel defaults; this supersedes the earlier simple 30-day-default proposal.
- Use one eligibility rule for overview warnings, FEFO urgency, and newly generated expiration notifications: remaining quantity is positive and `0 <= days_remaining <= warning_days`.
- Use Store calendar dates in Asia/Manila consistently. An October 25 expiration warns October 23 through October 25 for a two-day lead time; October 26 is expired. The stated expiration date remains eligible through that day.
- Warning lead time affects attention only, not physical shelf life or the FEFO order. Depleted batches generate no new warnings. Undated batches cannot generate a date-based warning.
- A product override replaces global milestones for that product's future expiration notifications, as well as controlling overview warning eligibility and FEFO urgency. Preserve historical notifications and deduplicate new alerts; products without overrides keep existing behavior. Fixed analytical horizons remain explicitly labelled.

## Implementation boundaries and checks

The agreed mitigation is to update affected batches, total inventory, and movement history together for every stock change, saving all or none. Receiving adds a new batch; sales deduct eligible batches by FEFO; damage and Supplier Pullouts identify the affected batches; counts record shortages or extras by batch; eligible customer returns preserve original batch lineage. Revalidate quantities under transaction locks so concurrent users cannot deduct the same units. Existing differences require a physical batch count and traceable correction, retaining unknown dates rather than guessing.

Reuse the existing batch storage, receiving transaction, overview, and FEFO allocation. Assess schema/migration, fresh setup, product request validation/forms, fixtures, inventory queries, scanner/POS readers, reports, notifications, and role capabilities together. Supplier returns need a real transactional batch movement with concurrent quantity validation, not independent updates or overwritten totals.

Before retiring product-level expiration reads, audit legacy products without batches and reconcile aggregate stock against batch balances. Never copy the latest product date onto historical batches as if its provenance were known. Inventory counts, approved Stock Issues, customer restocks/refunds, and supplier returns must preserve batch balances and original sale allocation where available. Existing data corrections need a separate, explicit strategy.

Focused validation should cover two batches with different dates, duplicate expiration dates, no starting stock, undated stock, depleted stock, warning boundaries, simultaneous expired and healthy batches, FEFO sales, partial supplier returns, replacement quantities differing from returns, concurrency, refund/count reconciliation, role checks, and notification deduplication. These checks have not been executed for this documentation task.

## Confirmed decisions

The user accepted all six recommendations on 2026-10-18:

1. **Warning scope:** product overrides control overview, FEFO urgency, and future notifications. No override preserves current channel defaults.
2. **Editing authority:** Inventory Managers edit product overrides; global threshold governance stays unchanged.
3. **Date boundary:** retain end-of-expiration-day eligibility in Asia/Manila calendar days.
4. **Missing dates:** expiration remains optional, labelled **Not recorded**. A perishable classification and mandatory-date policy are outside this change.
5. **Pullout authority:** Inventory Managers record completed physical pullouts directly, without an extra approval stage. Administrators retain oversight. Partial or later replacements link to the pullout.
6. **Supplier settlement:** track stock pullouts and linked replacements; credits, balances, and financial settlement remain outside scope.

## Agreed addition: find missing expiration dates

The user confirmed a **Missing Expiration** filter in the existing Stock Overview product list, using the same View Batches modal rather than another page.

- Match a product when at least one batch has positive remaining quantity and no recorded expiration. Include mixed products even if their other batches have dates.
- Show **Qty Without Date** in this filtered view; View Batches identifies the specific undated batches. A known Next Expiration does not imply all stock has recorded dates.
- Exclude depleted batches and products with no stock. Legacy on-hand stock without batch coverage is a reconciliation exception and must remain visibly identifiable rather than silently classified as fully dated.
- This filter describes missing actual batch dates, not a missing product warning override. An unset override uses existing defaults.
- General merchandise may legitimately appear here; missing dates alone do not establish that stock is expired or unsafe.

The filter design is confirmed; it is not an implemented screen. No business-policy questions remain open for the documented scope. Legacy reconciliation and cross-workflow balance checks remain implementation work; any correction that requires choosing unknown historical quantities or dates must be raised separately rather than guessed.

ADR-0013 is accepted for the confirmed decisions. Application implementation is outside this documentation task.

## Implementation specification

The complete agreed behavior, balance mitigation, and user-confirmed testing approach are captured in the [specification](specs/batch-expiration-and-stock-balance.md), published as [GitHub issue #216](https://github.com/ZeachDev01/retailmind/issues/216) with `ready-for-agent`.
