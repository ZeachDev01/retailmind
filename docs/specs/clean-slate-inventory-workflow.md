# Clean inventory workflow: guided receiving and procurement retirement

## Problem Statement

The Store owner buys goods outside RetailMind, commonly in bulk from supermarkets or larger stores. The Inventory Manager needs to check stock, advise what to buy, and record the actual goods brought back. Recommendations and receiving happen at different times and serve different jobs; combining them into one full workspace makes actual stock entry harder to follow.

The current code already has direct multi-product Stock Receiving and a separate Replenishment Recommendations page, but retains supplier administration, replenishment requests, purchase orders, document-linked receiving, supplier-based planning, and purchasing approval surfaces. These redundant paths add navigation clutter, backend branches, and competing ways to change stock.

RetailMind is still in development. Preserving obsolete procurement records and compatibility workflows must not dictate the new inventory experience. The goal is a complete, simple Inventory Manager workflow that integrates correctly with Cashiers, with forecasting secondary.

## Solution

Establish one daily sequence:

**Check stock → review Replenishment Recommendations → owner buys goods outside RetailMind → Inventory Manager records bought goods → review updated stock and Receiving History.**

Keep Stock Receiving and Replenishment Recommendations on separate pages. Use screenshot B's guided selection, quantities, and review sequence for receiving. Use screenshot C's recommendation table as the reference for the separate recommendation page, rather than reproducing its side-by-side combined workspace.

Give the Inventory Manager six clear navigation destinations:

| Destination | Job |
| --- | --- |
| Stock Overview | Check current stock, low/out-of-stock products, and expiry concerns. |
| Replenishment Recommendations | Review non-binding suggested buying quantities and their basis. |
| Stock Receiving | Select products, enter actual quantities, review, and save the whole entry once. |
| Receiving History | Inspect recorded shopping entries, products, accepted quantities, optional costs, staff, and time. |
| Products | Maintain products, barcodes, prices, pack sizes, and existing stock planning settings. |
| Inventory Control | Reach existing inventory counts and Cashier Stock Issue reviews. |

Inventory Control may group links to the existing count and Stock Issue screens; it does not require a new combined control application or changes to their review rules.

Retire the obsolete procurement workflow completely, including its reachable routes, backend mutation paths, notifications, reports, and schema dependencies. Keep the working stock, batch, sale, refund, count, Stock Issue, and accountability foundation.

## User Stories

1. As an Inventory Manager, I want a Stock Overview, so that I can understand what the Store currently has.
2. As an Inventory Manager, I want low-stock and out-of-stock products identified, so that I can prioritize buying.
3. As an Inventory Manager, I want expiry concerns visible, so that I can manage saleable goods.
4. As an Inventory Manager, I want Replenishment Recommendations on a separate page, so that I can focus on buying guidance before the owner shops.
5. As an Inventory Manager, I want suggested quantities and their basis visible, so that I can judge the guidance.
6. As an Inventory Manager, I want stock-rule guidance without supplier setup or forecasts, so that ordinary inventory work stays available.
7. As an Inventory Manager, I want unavailable or low-readiness forecasts described honestly, so that missing information is not mistaken for zero demand.
8. As a Store owner, I want to shop outside RetailMind, so that I can buy from whichever store has the goods.
9. As a Store owner, I want recommendations to remain optional guidance, so that actual purchases can differ.
10. As an Inventory Manager, I want a dedicated Stock Receiving page, so that I can record what physically came back.
11. As an Inventory Manager, I want select-products, quantities, and review steps, so that I can complete an entry without a crowded form.
12. As an Inventory Manager, I want several products in one entry, so that I can record a shopping trip with one save.
13. As an Inventory Manager, I want existing search and barcode lookup, so that I can identify active products quickly.
14. As an Inventory Manager, I want to receive an active product with zero stock or no purchasing history, so that unplanned purchases are supported.
15. As an Inventory Manager, I want to remove selected products and return to earlier steps without losing values, so that I can correct the draft.
16. As an Inventory Manager, I want actual quantities entered explicitly, so that suggestions or scans never stand in for a physical count.
17. As an Inventory Manager, I want individual units and saved packs/cases, so that loose and bulk purchases both work.
18. As an Inventory Manager, I want the saved pack size and converted units visible, so that I can catch quantity mistakes.
19. As an Inventory Manager, I want optional shopping-store, receipt-reference, and purchase-cost fields, so that missing paperwork does not block receiving.
20. As an Inventory Manager, I want blank cost to remain unknown and preserve the existing product cost, so that the system does not invent a zero-cost purchase.
21. As an Inventory Manager, I want optional batch and expiry details, so that received goods remain compatible with existing saleable-stock handling.
22. As an Inventory Manager, I want damaged bought units excluded from accepted stock, so that checkout availability remains correct.
23. As an Inventory Manager, I want a complete review before saving, so that I can verify every product and quantity.
24. As an Inventory Manager, I want invalid entries explained with my draft retained, so that I can correct mistakes efficiently.
25. As an Inventory Manager, I want one failed line to save nothing, so that a shopping trip is not partially recorded.
26. As an Inventory Manager, I want accepted quantities added to current stock, so that existing goods are not overwritten.
27. As an Inventory Manager, I want repeated clicks prevented from recording twice, so that stock is not accidentally inflated.
28. As an Inventory Manager, I want Receiving History separate from entry, so that I can inspect past shopping records without crowding the receiving task.
29. As an Inventory Manager, I want one success summary and attributed receiving records, so that I know what was added and who recorded it.
30. As an Inventory Manager, I want recommendations to reflect committed receiving and sales, so that guidance uses current stock.
31. As a Cashier, I want accepted received units available through the shared stock and batch records, so that I can sell the goods.
32. As a Cashier, I want checkout to validate current saleable stock, so that outdated cart information cannot oversell goods.
33. As an Inventory Manager, I want completed sales reflected in Stock Overview and recommendations, so that buying guidance follows actual stock consumption.
34. As a Cashier, I want existing Stock Issue reporting retained, so that I can report damaged, missing, expired, or other affected units.
35. As an Inventory Manager, I want existing Stock Issue review and inventory counts retained, so that stock problems are resolved through the established controls.
36. As an Administrator, I want read-only inventory and Stock Issue oversight, so that I can inspect Store activity without performing inventory execution.
37. As a Store operator, I want obsolete procurement routes and stock-write alternatives removed, so that there is one understandable inventory workflow.
38. As a maintainer, I want obsolete procurement dependencies removed from callers, tests, fixtures, schema setup, and documentation, so that a clean installation and upgrade both support the new workflow.

## Implementation Decisions

### Page responsibilities and reference direction

- Preserve RetailMind's existing visual system and application shell. The supplied screenshots establish workflow and composition, not a new brand.
- Stock Receiving follows B: **1. Select products → 2. Enter quantities → 3. Review**. Step changes preserve the draft. Only the final reviewed save writes stock. Validation errors preserve all entered values and return the user to the affected information.
- Product selection supports existing product search and barcode/SKU lookup, including zero-stock active products. Scanning identifies a product; it does not record receiving or infer bought quantities. A case barcode may select packages/cases while leaving actual quantity for the operator to enter.
- Prevent duplicate product lines with a clear correction message. Repeated selection or scanning must not silently add quantities or overwrite an existing line.
- Replenishment Recommendations uses C's scannable product, on-hand quantity, suggested units, and guidance basis. Stock Receiving is a separate destination; do not embed a second recommendation table or an alternate receiving form in either page.
- A recommendation may help select a product for receiving, but must never prefill actual received quantities, create an order/request, or mutate stock. Unplanned purchases receive equal support.
- Receiving History is a dedicated page. Identify products, actual received/accepted/damaged quantities, the pack conversion used, available batch/expiry and shopping details, optional costs, recording staff, and time. Associate lines saved together with the same shopping entry, reusing existing storage where sufficient and adding only the minimal grouping storage if needed.
- Receiving History is distinct from customer Receipt History. Reuse authorized history visibility; it must not become a cashier-facing inventory-write surface.
- Make inventory execution prominent in Inventory Manager navigation. Keep forecasting reports secondary, and place Stock Issue review with inventory control rather than burying it among forecasting reports.

### Shared stock-write boundary and cashier integration

- Reuse ReceivingService and its existing atomic multi-product submission as the Stock Receiving write boundary. Refactor it to direct receiving only; do not introduce a second inventory update service or independently committed per-product writes.
- Use the same product identities, barcodes, inventory, saleable batches, and stock movements as cashier checkout. Creating a product or barcode is not receiving stock.
- Require active products and positive whole-number bought quantities. Validate quantity modes, numeric ranges, duplicate products, damaged units, optional costs, and applicable batch/expiry fields on the server. Resolve pack sizes from saved product configuration and record the conversion used.
- Accepted quantity equals received units minus damaged bought units. For example, 8 on hand plus 2 cases of 24 accepted units becomes 56. Receiving adds quantities; it never replaces current stock.
- A fully damaged purchase may be recorded if supported by the existing receiving rules, but adds no saleable stock. Damaged bought units must never become a later Stock Issue deduction from existing stock.
- Blank purchase cost remains unknown and does not erase the product's existing cost. Supplied cost retains valid purchase-history behavior and is clearly labelled per base unit. Retain the optional shopping-store text without requiring supplier administration or silently replacing product planning data.
- Validate and commit all inventory, batches, costs, receiving records, and stock movements atomically. Preserve Store write gates, Fiscal Period checks, CSRF, active-workspace authorization, concurrency controls, and duplicate-submit protection.
- Existing checkout continues to revalidate current stock and nonexpired saleable batches at its shared transaction boundary. Stock displays and recommendations reload from committed state through normal page navigation or existing refresh behavior; no new realtime transport is required.
- Preserve Cashier Shift, Register, sale attribution, Held Sale, and Cash Refund behavior. Restockable customer returns use the existing refund stock path; damaged returns are not deducted again as Stock Issues.
- Preserve Stock Issue reporting/review and count/correction semantics. Do not replace counts with receiving or remove their existing applicable review rules.
- Inventory mutation requires the active Inventory Manager workspace. Administrator and Super Administrator oversight remains read-only in their own workspaces; Cashiers operate existing cashier workflows.

### Complete procurement retirement

- Remove supplier administration, Supplier Product Terms management, replenishment requests, purchase orders, their approval/sending/receiving lifecycle, document-linked receiving, and duplicate supplier-based reorder planners.
- Remove the obsolete pages and callable mutation paths, not just navigation links. Remove unused alternate stock-purchase writers when direct Stock Receiving replaces them; keep shared product management, lookup, and barcode functionality.
- Reconcile every connected caller: navigation, landing/workspace destinations, product actions, receiving, recommendation calculations, operational dashboards, attention rules, notifications, reports, reset utilities, exports, authorization references, tests, fixtures, setup scripts, and current domain/product/operator documentation.
- Replace or remove purchasing approval metrics and report sections rather than leaving broken destinations or silently substituting meaningless zeros. Preserve unrelated Administrator oversight and approval responsibilities.
- Remove supplier-term joins and open-purchase-order/request stock from recommendations and remaining stock-readiness queries. Purchases made outside RetailMind are not reliable incoming stock until recorded as received.
- Retain product-level planning settings and their existing stock-rule defaults; supplier administration must not be needed to calculate guidance. Limit forecast changes to removing dependencies on the retired procurement system.
- Remove obsolete procurement tables, links, and columns where no retained capability uses them. Update fresh schema/setup and the development upgrade path consistently. Do not retain runtime schema repair that recreates retired structures.
- Development procurement records and compatibility bridges need not be preserved. Scope destructive cleanup to retired procurement data and links; this is not authorization for a blanket database reset or deletion of accounts, customer receipts, sales/refunds, current stock/batches, or Protected Audit Records.
- Update existing migrations/setup in a way that lets both clean installations and the current development database reach the same final state. No new dependency, generic compatibility framework, or speculative purchasing replacement is required.

### Forecasting boundaries and existing decisions

- Stock rules remain independently usable. Existing persisted Demand Forecasts may inform recommendations when available and permitted by existing governance settings; expose their basis and Forecast Readiness honestly.
- Missing forecasts remain unavailable, never zero demand. Preserve Observation Mode, recommendation suspension, and active model governance where applicable.
- Receiving and viewing recommendations do not enqueue Forecast Generation or Model Retraining and do not generate forecasts synchronously.
- Preserve the single-Store, active-workspace authority boundaries, staff-safe Operator Alerts, asynchronous Random Forest pipeline, and transaction-time customer receipt details established by existing ADRs.
- The current domain description that retains historical linked receiving is superseded for this development procurement retirement. Administrator oversight and accountability remain. Record the new retirement decision and update current domain language during implementation; do not rewrite historical ADRs to imply a different past decision.

## Testing Decisions

- Use the existing highest-level **browser-to-HTTP inventory workflow with database assertions**, extending it across Inventory Manager receiving and Cashier checkout/Stock Issues. Reuse existing Stock Receiving, inventory-count, Stock Issue, refund, and checkout tests/fixtures rather than creating a new test architecture.
- The existing #176 seam is retained. The user requested synthesis without follow-up decisions; no additional test-seam interview is needed.
- Test observable behavior and persisted outcomes, not source text, helper structure, or exact SQL. Primary coverage is Stock Receiving, ReceivingService, recommendation views, workspace authorization, and the connected cashier stock boundary.
- Verify separate recommendations and receiving destinations, B's three-step progression/back/edit behavior, a final review, retained invalid input, dedicated Receiving History, clear success/empty/error states, keyboard operation, and desktop/mobile layouts without page overflow.
- Verify normal receiving works without supplier records, requests, orders, approvals, emergency reasons, or forecasts. Verify zero-stock active products and unplanned products work.
- Verify search/unit/case barcode selection changes no stock and never copies suggested or inferred bought quantities. Verify duplicate selection cannot inflate an entry.
- Verify one mixed units/cases save, including **8 + (2 × 24) = 56**, optional cost/store/receipt fields, saved pack conversion, batch/expiry handling, damaged units, fully damaged entries, grouped history, staff attribution, and updated guidance.
- Verify omitted cost stays unknown while existing product cost remains intact; a supplied cost uses the displayed unit basis.
- Verify zero, negative, fractional, malformed, out-of-range, duplicate-product, inactive-product, invalid pack-size, and excessive-damage entries save nothing. Force a later-line database failure to prove earlier changes roll back.
- Verify duplicate submission protection and concurrent legitimate receiving additions. Verify overlapping receiving and checkout preserve correct committed quantities and batch availability.
- Verify received accepted goods can be sold by an authorized Cashier with an open Cashier Shift. Verify sales reduce shared stock and batches, stale checkout cannot oversell, and updated stock-dependent guidance reflects the sale.
- Verify expired or damaged-only received goods cannot be sold as usable stock. Verify Stock Issue reporting alone changes no stock; approval deducts once, and existing count/correction and refund restocking regressions remain valid.
- Verify Administrator, Super Administrator, and Cashier workspaces cannot submit direct receiving, while existing authorized oversight and cashier actions remain available.
- Verify recommendation reload and receiving do not change persisted forecasts or enqueue forecasting jobs.
- Verify retired procurement URLs/actions no longer execute or mutate stock, remaining navigation/report/notification destinations work, and no alternate obsolete writer survives.
- Verify fresh installation and upgrade of a representative development database produce the same usable inventory workflow without recreating retired procurement structures. Update or retire obsolete purchasing tests and fixture requirements while retaining connected inventory/cashier regressions.

## Out of Scope

- New supplier management, purchase requests/orders, purchasing approvals, supplier payments, accounts payable, shopping budgets, order sending, or receipt attachment uploads.
- New forecast models, retraining behavior, horizons, formula redesign beyond removing procurement dependencies, or a forecasting-led Inventory Manager dashboard.
- Full cashier UI redesign, new Cashier Shift/Register/refund rules, or changes to Stock Issue/count approval semantics.
- Multi-Store support, a new inventory ledger architecture, generic abstraction layers, realtime subscriptions, or new dependencies.
- Blanket database resets, removal of unrelated operational/audit history, or changes to Database Backup/Restore security.
- Implementation in this specification task.

## Further Notes

- Workflow reference: [issue #176](https://github.com/ZeachDev01/retailmind/issues/176). Prototype reference: [#176 prototype discussion](https://github.com/ZeachDev01/retailmind/issues/176#issuecomment-6179328846) and [prototype branch](https://github.com/ZeachDev01/retailmind/tree/prototype/176-bulk-receiving).
- User-provided screenshots: **176-B-desktop.png** (guided receiving) and **176-C-desktop.png** (recommendation-table reference). The combined C workspace is not the chosen page topology. Prototype-only sample data, floating variant controls, reset controls, and diagnostic state displays do not ship.
- This specification **supersedes #176's legacy-preservation and non-destructive-procurement scope**. Keep its direct receiving, validation, atomicity, authorization, and accountability requirements. Do not implement both scopes as competing workflows.
- The current code already supports direct bulk receiving and separate recommendations. Reuse that implementation and remove redundant procurement dependencies; “clean slate” describes the operational workflow, not a rewrite of working stock and cashier foundations.
- Source inspection established existing dependencies in receiving, recommendations, dashboards, attention signals, reports, schema setup, fixtures, and an older stock-purchase path. No runtime or database test results are claimed by this specification.

