# Sealed pack sales and prepared oil pouches

## Problem Statement

The Store sells the same biscuit or other Product individually and in sealed packs. A sealed pack usually has a different barcode and costs less than purchasing its contents individually. Cashiers need to scan either barcode and complete the usual checkout without calculating conversions or recording pack opening manually.

Total piece counts cannot establish whether a sealed pack is physically available. Inventory staff need simple starting-stock and Stock Receiving entries for unopened packs and loose pieces. Prepared oil pouches also need straightforward stock counts: 250 mL (1/4 litre) sells for PHP 20, and 500 mL (1/2 litre) sells for PHP 40. The owner does not want bulk-oil tracking or pouch-production accounting.

Existing exact code lookup distinguishes unit and case barcodes, but checkout normalizes cart lines by Product alone and uses one selling price. Current receiving converts packages into base-unit totals without retaining the sealed-versus-loose distinction needed for sales.

## Solution

Keep one Product for the item sold by piece and pack. Configure its piece barcode and price, one fixed Sales Pack size, distinct pack barcode, and cheaper pack price. Products sold only individually do not require pack fields.

In Add Product, enter starting unopened packs and loose pieces. Stock Receiving accepts both counts against the existing Product and retains the delivery's batch and expiration details. Show sealed and loose counts alongside their equivalent total, without double counting. For example, 5 packs of 12 plus 3 loose pieces represent 63 pieces; receiving 2 packs and 4 loose pieces results in 7 packs plus 7 pieces, or 91 pieces total.

Add Product's Planning step shows Sales Packs and Loose pieces automatically when a Sales Pack size is configured; otherwise it shows Starting pieces. Remove the Pieces / Packages / boxes / Sales Packs selector to avoid a redundant choice. Keep supplier packaging separate under Restocking, labelled Units per supplier box, followed by Supplier details. Existing piece quantities remain loose when enabling pack entry; never infer unopened packs from a total. Preserve explicit pack and loose counts when restoring a draft.

At checkout, scanning the piece barcode selects piece pricing; scanning the pack barcode selects pack pricing. A clear piece/pack selection supports the same behavior when adding from the catalog. Quantity means the selected selling form. A pack sale consumes sealed stock only. A piece sale uses loose stock first; when loose stock is insufficient, RetailMind records the minimum necessary Pack Opening automatically with the completed sale. There is no additional cashier button, confirmation, or checkout step. Staff physically use loose stock first and open packs when needed.

Example: starting with 2 sealed packs of 12 and 3 loose pieces, selling 5 pieces opens 1 pack and leaves 1 sealed pack and 10 loose pieces. Scanning a pack instead deducts 1 unopened pack at the configured pack price. Twelve loose pieces cannot satisfy a sealed-pack sale. Merely scanning, quoting, holding, or abandoning a cart changes no stock.

Normal pack Cash Refunds require the returned pack to be sealed and complete. Refund the original amount attributable to that pack and restore sealed stock through the existing authorized refund workflow. Keep Oil 250 mL and Oil 500 mL as two separately counted Products: staff enter prepared pouch counts and sales deduct whole pouches.

## User Stories

1. As an Inventory Manager, I want one Product for piece and pack sales, so that I do not maintain duplicate inventory.
2. As an Inventory Manager, I want to configure a piece price and a cheaper pack price, so that checkout charges the owner's intended amount.
3. As an Inventory Manager, I want one fixed pack size per Product, so that stock conversion is predictable.
4. As an Inventory Manager, I want separate piece and pack barcodes, so that scanning identifies the correct selling form.
5. As an Inventory Manager, I want ordinary products to work without pack settings, so that simple product entry remains simple.
6. As an Inventory Manager, I want starting stock entered as sealed packs and loose pieces, so that the initial balance matches the shelf.
7. As an Inventory Manager, I want starting stock to retain its Opening Batch details, so that expiration and costs remain traceable.
8. As an Inventory Manager, I want to receive sealed packs and loose pieces together, so that one delivery can be recorded without manual conversion.
9. As an Inventory Manager, I want receiving scans to identify piece or pack form, so that counts go into the correct field.
10. As an Inventory Manager, I want receiving history to retain the entered forms and conversion used, so that I can explain past deliveries.
11. As an Inventory Manager, I want only accepted goods added to usable stock, so that damaged arrivals do not inflate sealed-pack availability.
12. As an Inventory Manager, I want separate batches for goods with different expiration dates, so that later deliveries do not overwrite older dates.
13. As an Inventory Manager, I want sealed, loose, and equivalent total quantities visible, so that I can reconcile stock without counting it twice.
14. As a Cashier, I want a piece barcode to select the piece price, so that individual sales remain familiar.
15. As a Cashier, I want a pack barcode to select the cheaper pack price, so that I do not enter discounts manually.
16. As a Cashier, I want a clear piece/pack selection for catalog additions, so that barcode-free additions use the same rules.
17. As a Cashier, I want quantity to refer to the selected form, so that quantity 2 on a pack line means two sealed packs.
18. As a Cashier, I want piece and pack lines to remain distinct in one cart, so that their quantities and prices are not merged.
19. As a Cashier, I want piece sales to use loose stock first, so that the system matches normal shelf handling.
20. As a Cashier, I want necessary pack opening recorded automatically, so that checkout requires no additional action.
21. As a Cashier, I want insufficient sealed stock to block pack sales, so that I do not promise unopened packs that do not exist.
22. As a Cashier, I want mixed carts checked together, so that opening a pack for pieces does not consume a pack being sold sealed.
23. As a Cashier, I want held or abandoned carts to leave stock unchanged, so that browsing and interrupted sales do not alter inventory.
24. As a Cashier, I want retrying checkout to return the same result, so that a lost response does not open or sell stock twice.
25. As a Customer, I want my receipt to identify packs, pack contents, and the charged price, so that I understand my purchase.
26. As a Customer, I want pack pricing only when I buy a sealed pack, so that pricing follows the selected sale form.
27. As a Cashier, I want a normal sealed-pack refund to restore sealed stock and refund the original paid amount, so that stock and money agree.
28. As a Cashier, I want an opened or incomplete return excluded from the normal pack-restocking path, so that it cannot create imaginary sealed stock.
29. As an Inventory Manager, I want counts, approved Stock Issues, and supplier movements to retain sealed-versus-loose quantities, so that later corrections do not invalidate checkout availability.
30. As an Administrator, I want stock and Pack Opening history to retain staff and sale attribution, so that I can review changes.
31. As an Administrator, I want sales, gross profit, and demand totals to use consistent quantities, so that a pack is not counted as one piece or sold twice.
32. As an Inventory Manager, I want to manage 250 mL and 500 mL oil pouches separately, so that each size has its own price and count.
33. As a Cashier, I want selling an oil pouch to deduct one pouch, so that I do not enter litres or fractions.
34. As an existing operator, I want historical receipts, batches, prices, and refunds preserved, so that introducing pack sales does not rewrite history.

## Implementation Decisions

- Extend existing ProductService, ProductCodeLookupService, ReceivingService, SalesWorkflowService, inventory, receipts, and CashRefundService boundaries. Reuse the current exact barcode lookup, Store write gate, checkout attempt recovery, authorization, and batch allocation infrastructure. Do not add a separate pack Product or competing stock ledger.
- Preserve one optional Sales Pack configuration per Product: a whole-number contents count greater than one, a distinct barcode, and a positive currency price lower than contents count times piece price. Validate the complete configuration on creation and editing, reject conflicting barcode identities, and validate whole non-negative stock counts and integer overflow at write boundaries. Pack configuration is optional for ordinary Products.
- Receiving packages and customer Sales Packs are different concepts. Reuse existing fields only when their meaning and conversion actually match. Never silently reinterpret an existing carton/case barcode as the customer pack barcode, or change historical package conversions. Additional nested carton-to-sales-pack configuration is outside scope; existing receiving behavior must remain compatible and cannot manufacture sealed-pack counts from an unknown conversion.
- Retain sealed and loose balances at the Stock Batch level, with product summaries and equivalent Base Unit totals kept consistent. A pack opening conserves total Base Units, costs, batch provenance, and expiration; only the subsequent sale reduces the equivalent total. Every stock writer, including starting stock, receiving, sales, refunds, counts, approved Stock Issues, supplier pullouts, and replacements, must preserve this invariant.
- Keep receiving quantities and damaged/rejected goods explicit about form. Only complete accepted packs enter sealed stock; accepted loose units enter loose stock. A damaged or partially accepted package must not remain a saleable sealed pack. Preserve optional purchase costs and unknown costs rather than inventing them. Do not add supplier administration, purchasing approvals, or bulk-oil production.
- Cart identity becomes Product plus selling form. Requests carry the selling form and whole-number quantity; the server validates configuration, calculates prices and Base Unit quantities, and checks eligibility. Missing form on legacy piece-only requests retains existing piece semantics. Keep pack and piece lines distinct in quote fingerprints, checkout attempts, held/resumed carts, receipts, and refund references.
- Resolve each Product's complete cart demand together. Reserve requested sealed packs before considering automatic opening for piece lines; calculate the minimum additional packs needed for any loose-piece shortfall. Reject an insufficient combined plan without partially saving. Do not depend on cart line order.
- Retain ADR-0013 expiration eligibility. Use eligible loose stock before opening eligible sealed packs, and FEFO within each eligible form. Expired physical packs remain visible but cannot satisfy pack sales or be opened for sale. Preserve the actual source batches and costs of opened contents; do not reset expiration.
- Quote and review compute a plan without stock mutation. Revalidate and save pack opening, batch/total changes, sale, receipt details, and movement history together at final checkout under existing concurrency protection. A failure rolls back everything. Checkout replay must not repeat opening or deduction. Existing stock/price-change review behavior remains; this feature adds no pack-opening prompt.
- Pack barcode scans and explicit pack selection choose the configured pack price. Piece scans remain piece sales even when their quantity equals a full pack. Do not implement automatic quantity-based pack discounts. Existing authorized discounts and promotions must remain coherent with the chosen selling form and original paid amount.
- Persist the original selling form, pack contents count, sale quantity, and charged price with historical sale/receipt data. Later catalog edits cannot reprice or reinterpret receipts, held-sale review, or refunds. Prevent changing the pack contents count while current sealed stock would thereby change meaning; require supported reconciliation rather than silently reinterpreting stock.
- Normal pack refunds accept whole, sealed, complete packs only and restore the correct batch/form when eligible for restocking. Refund the original paid amount after the existing discount/refund allocation rules, not today's catalog price. Retain existing authorization, shift, fiscal-period, damaged-return, and exception controls; this feature does not create a new open-pack return policy.
- Keep inventory summaries, stock alerts, replenishment, sales statistics, GrossProfitService, and DemandHistoryService consistent in Base Units for quantities, with actual sale revenue and preserved historical costs. A sold pack contributes its contents once to demand; Pack Opening contributes no purchase, sale, revenue, or demand. Oil Products use whole pouches as their Base Units.
- Schema upgrades, fresh setup, exports, resets, fixtures, and imports that carry these records must agree. Preserve historical receipts and existing aggregate stock/batch evidence. A legacy total does not establish physical sealed-versus-loose composition: require a recorded physical reconciliation before enabling sealed-pack availability for that stock. Existing piece-only products continue working. Do not infer unopened packs by dividing a total by pack size.
- Oil 250 mL and Oil 500 mL use ordinary whole-pouch product entry, receiving, sales, counts, and refunds. PHP 20 and PHP 40 are the agreed initial prices, not hardcoded global rules or automatic production costs.

## Testing Decisions

The user confirmed the test seams: existing Product, Stock Receiving, Checkout, and Cash Refund service workflows against a disposable database, plus browser checks that checkout adds no extra step. Prefer these existing application entry points over new helper-level seams. Test observable prices, receipts, eligibility, saved balances, history, and failures rather than private helper structure or SQL text. Do not run financial fixtures against Store data.

Prior art includes the checkout-attempt and quote integration suites for replay, locking, and price review; batch-receiving MySQL and cross-workflow batch verification suites for receiving and rollback; Cash Refund concurrency integration for refunds; and cashier checkout/receipt, scan, product-create, and Stock Receiving browser suites for actual operator flows. Extend these patterns rather than adding a new test framework.

Required behavior checks:

1. Create an optional pack-enabled Product and a piece-only Product. Reject incomplete or conflicting pack configuration, ambiguous barcodes, invalid prices, fractional counts, and overflow without partial stock creation.
2. Start with 5 packs of 12 and 3 loose pieces: total 63. Receive 2 packs and 4 loose pieces: final 7 packs, 7 loose, total 91. Check batches, expiration, cost provenance, and receiving history. Inject a failure and verify all affected records roll back.
3. Scan a piece barcode and a pack barcode; verify distinct cart lines, form-specific quantity labels, server-calculated prices, and receipt quantities. A 12-piece line retains piece pricing; a 1-pack line receives pack pricing.
4. From 2 packs of 12 and 3 loose pieces, sell 5 pieces: 1 pack and 10 loose remain, equivalent total 22. Sell with sufficient loose stock and verify no pack is opened. Cover a shortfall requiring multiple packs and exact depletion.
5. Request a sealed pack when only loose stock remains: refuse it. In mixed pack/piece carts, preserve enough sealed packs for pack lines and obtain the same outcome regardless of line order. Insufficient combined stock causes no partial opening or charge.
6. Quote, scan, hold/resume, cancel, and abandon carts without stock mutation. Commit once, replay the same checkout attempt, and recover a lost response without duplicate conversion, movement, sale, or receipt. Competing checkouts for the last sealed pack cannot both succeed.
7. Exclude expired stock, preserve undated-stock behavior, and allocate eligible batches according to loose-first then FEFO-within-form rules. Opening never changes expiration or total stock by itself.
8. Refund a sealed, complete pack after a catalog price change: refund its original attributable paid amount and restore the original form without duplicate restocking. Opened/incomplete or damaged returns cannot enter normal sealed stock. Preserve existing piece and oil refund behavior and permission checks.
9. Exercise stock counts, approved Stock Issues, supplier pullouts, and replacements through existing workflows; verify sealed, loose, batch, and aggregate balances remain consistent. Verify demand and gross profit use converted quantities and actual revenue, excluding Pack Opening as a sale.
10. Receive and sell both oil pouch Products independently at their configured initial prices, with integer pouch quantities and no bulk-oil deduction.
11. Browser-check product setup, starting stock, mixed-form receiving, inventory summaries, and scan-to-payment/receipt. Verify no additional pack-opening click or confirmation, clear selling-form labels, keyboard operation, and useful validation messages.
12. Verify fresh schema and migration parity, preserved historical receipt/refund interpretation, piece-only backward compatibility, and physical reconciliation before legacy stock is treated as sealed. Prevent pack-size edits from changing existing sealed stock silently.

## Out of Scope

- Multiple customer pack sizes per Product, arbitrary bundles, and packaging hierarchies.
- Automatic pack discounts on loose-piece quantities or new promotion features.
- Selling loose pieces as an unopened pack or automatically resealing stock.
- A manual pack-opening step during checkout.
- Bulk oil, litre balances, fractional sale quantities, repacking production, spillage, and pouch-material accounting.
- A new policy for opened/incomplete pack refunds, new supplier workflows, or rewritten historical sales.
- Application implementation in this specification task.

## Further Notes

Published as [GitHub issue #245](https://github.com/ZeachDev01/retailmind/issues/245), labelled `ready-for-agent`.

The design was discussed on 2026-10-20. The biscuit sizes and prices used above are examples; actual product configuration belongs to the Store. The oil sizes and initial prices were explicitly supplied by the user. Physical cashier behavior must follow loose-first allocation for automatic opening to reflect the shelf.

This feature extends the existing direct Stock Receiving and batch-expiration decisions. Separate physical counts matter more than minimizing fields: an aggregate total cannot prove unopened-pack availability. Preserve batch evidence, immutable receipt interpretation, and single-store role boundaries throughout implementation.
