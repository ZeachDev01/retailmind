# Product creation and Stock Receiving UX

Date: 2026-11-17 (Asia/Manila)

Status: User-confirmed design decisions for improvements #1–#5, consolidated after the barcode reminder discussion. Specification only; not implemented.

This is the single planning source for this change. The earlier separate editor, scanner-focus, and create-and-return tickets have been closed and retired from the active backlog at the user's request. Do not implement their older scope exclusions separately or create replacement tickets from this document without a new request.

## Implementation tickets

On 2026-11-17, the user requested ticket creation from this consolidated spec and approved the seven-slice breakdown below. All tickets are published with `ready-for-agent`; blocking edges use GitHub native issue dependencies. The earlier retired tickets remain retired. No parent issue was modified, and publication does not claim application implementation or runtime verification.

| Ticket | End-to-end slice | Blocked by |
|---|---|---|
| [#477](https://github.com/ZeachDev01/retailmind/issues/477) | Known-zero and missing product/receiving costs | None |
| [#478](https://github.com/ZeachDev01/retailmind/issues/478) | Compact shared Add Product form | #477 |
| [#479](https://github.com/ZeachDev01/retailmind/issues/479) | Split-catalog receiving editor with inline quantities | None |
| [#480](https://github.com/ZeachDev01/retailmind/issues/480) | Independent batches, current-cost choice and replacement receipt grouping | #477, #479 |
| [#481](https://github.com/ZeachDev01/retailmind/issues/481) | Packaging-aware barcode lookup and recovery | #479 |
| [#482](https://github.com/ZeachDev01/retailmind/issues/482) | Create missing product and return to the exact delivery draft | #478, #480, #481 |
| [#483](https://github.com/ZeachDev01/retailmind/issues/483) | Exact package purchase amounts through history/reports | #480 |

Initial implementation frontier: **#477 and #479**. Each ticket includes its own focused verification; the final acceptance matrix below still applies to the combined feature.

## Confirmed UI direction

User-selected on 2026-11-17: **Variant A — Split catalog** is the layout to follow during implementation.

- Desktop: keep the searchable product catalog on the left and all selected delivery products with inline quantities and batch details on the right. Both remain reachable in the same receiving editor.
- Narrow screens: stack the catalog and delivery editor without adding a separate quantity stage or page-wide overflow.
- Keep labels short and avoid repeated explanatory text. Show essential fields directly; reveal optional product settings through compact disclosures. Preserve required packaging reminders, validation, and cost/conversion information.
- Retain the existing RetailMind visual system. Variants B and C are not the selected implementation direction.

Prototype primary source: branch `prototype/receiving-ui-2026-11-17`, `src/frontend/components/report/stock_receiving.prototype.html?variant=A` (served on the existing receiving route). The prototype illustrates layout and interactions; the workflow and validation rules in this specification remain authoritative. Rewrite the selected layout through shared production components rather than shipping the throwaway prototype code.

## Purpose and scope

Help the Inventory Manager register products and receive goods with fewer repeated decisions while preserving useful data, validation, and stock accuracy.

Normal receiving: **Scan/search → enter quantities and batch details → repeat → Review → save once.**

Unregistered barcode: **Check all code types → identify scanned packaging → find existing product or explicitly create one → return to the same delivery → enter quantity.**

The agreed work covers:

1. Create a missing product and return to the exact receiving draft.
2. Combine product selection and quantities in one receiving editor.
3. Use one compact Add Product form with essential fields first and relevant details available.
4. Accept supported per-unit/package costs with visible conversion and explicit current-cost selection when batch costs differ.
5. Distinguish selection from the final stock commit through clear action labels.

Explicit scope extension: **Add another batch** within a selected product, preserving different quantities, dates, and costs in one receiving entry.

Reuse existing ProductService, ReceivingService, barcode lookup, encrypted inventory drafts, accounting, authorization, and design patterns. This refines the [direct receiving workflow](direct-stock-receiving-and-replenishment.md), superseding separate selection/quantity stages and the duplicate-product restriction only as necessary for explicit batch allocations.

No supplier/order/approval workflow, new dependency, automatic quantity increment, weighted-average costing, existing-data deletion, or second product-creation implementation is requested. Preserve standalone Products creation/printing, supported links, replacement receiving, existing history, and unrelated POS behavior.

## 1. Barcode lookup, identification, and creation

### Resolve before offering creation

- Check individual-item, receiving-case, Sales Pack, and SKU identifiers. Do not treat failure to match one code type as proof that the product is missing.
- An active, unambiguous match selects the existing product and identifies its saved code type and supported conversion. Do not show Create Product for that match.
- An inactive match identifies the existing product and offers authorized recovery; never silently reactivate it or create a duplicate. Receiving-specific recovery must not change POS lookup behavior or expose unauthorized records.
- Keep ambiguous matches and lookup/network failures distinct from unregistered codes. Preserve entered values and offer retry/manual selection as appropriate.
- An unregistered code may still belong to an existing product whose package barcode was never entered. Offer **Find existing product** alongside explicit creation. Attaching a code or reactivating a product requires a deliberate authorized edit; search alone does neither.
- In Receiving, if one code matches both a SKU and one barcode type on the same product, the explicit barcode type determines the packaging mode; SKU must not hide a registered case/pack match. Unit-barcode/SKU equality keeps unit behavior. If legacy data matches multiple conflicting barcode types, require explicit resolution rather than guessing. Do not change unrelated POS precedence.
- If the same sealed pack is both received and sold, register its code only as the Sales Pack Barcode, not again as the Receiving Package Barcode. Preserve this existing reminder in the compact form.

### Prompt only for an unregistered code

Show **Which packaging did you scan?** with three choices:

| Choice | Meaning | Registration target |
|---|---|---|
| Individual item | One base-unit bottle/piece | Individual Item Barcode |
| Receiving case | Outer carton/box used to receive stock | Receiving Package Barcode |
| Sales Pack | Sealed pack also sold at checkout | Sales Pack Barcode |

The number alone does not establish packaging type or package size. Never default an unknown carton code into the item field or copy it into several identifier fields. Do not repeat this question for known, unambiguous codes.

For a receiving case, show a short reminder: **Scan the outer carton barcode here. Enter the item barcode separately and confirm how many base units are in one carton.** An absent individual barcode may still use the established automatic unique internal barcode; do not require a fabricated manufacturer code.

Reveal the corresponding conversion/pack fields. Sales Pack selection requires its distinct size, barcode, and price. Show the classification and saved conversion in Review. If staff cannot identify the packaging, retain the code/draft and allow Back, Cancel, or existing-product search; do not silently classify it.

When an unknown package code is followed by **Find existing product**, show the saved conversion and require explicit confirmation before applying it to the unknown packaging. Example: **Saved carton size: 24 bottles. Does this carton also contain 24?** Do not require this extra confirmation for known, unambiguous registered codes. If staff say it differs or cannot confirm, do not apply the saved package multiplier or attach the code. Offer base-unit quantity entry (for example, 2 cartons of 12 = enter 24 bottles) or an explicit authorized package-configuration correction followed by refreshed Review. The unknown code stays available for correction; selecting an existing product is not proof that its saved carton size matches. Do not introduce a second receiving-case configuration or silently replace the product's saved conversion.

### Preserve the delivery and return correctly

- Reuse the shared Add Product form and logic. Carry the code and chosen packaging into the corresponding editable field.
- Before leaving the receiving editor, successfully persist products, every batch allocation and quantity mode, costs, lot/expiry, receipt details, order, current-cost choices, and linked pullout context. Draft storage failure must keep the page/values intact and explain recovery; do not falsely claim a saved draft.
- Receiving-origin creation always starts at **0 stock**. Explain that delivered quantities belong to Receiving; enforce zero server-side for `initial_stock_quantity`, `starting_packs`, and `starting_loose`, including tampered requests.
- Successful creation returns to the originating delivery, refreshes catalog data, and selects the created product once by stable identity even if its name/barcode/category changed. Use its final saved code classification to select the appropriate supported mode and focus its visible blank quantity input.
- Cancel returns without adding a product. Validation/failure retains product-form values and the receiving draft. If restoration fails, preserve the draft and give an actionable recovery path; do not replace it silently with an empty delivery.
- Creation and receiving are separate commits. Abandoning the delivery leaves a successfully created catalog product at zero stock; it does not delete that product or create a receiving entry.
- Return to Receiving rather than barcode printing in this origin. Printing remains available later; standalone creation retains its print option.
- Bind the handoff to the authorized account and exact originating draft/tab context. Reject arbitrary return URLs. Repeated return, refresh, retry, Back navigation, or a concurrent code registration must not duplicate catalog products, groups, allocations, or stock. Reuse existing operation-token behavior and consume the handoff once.

## 2. One receiving editor and explicit batch allocations

Replace **Select products → Enter quantities → Review** with **Receive stock → Review**. Search/catalog and selected products remain reachable together.

- Keep one group per selected product, initially with one batch allocation. Show product identity, applicable quantity mode, visible quantity inputs, saved conversion, and calculated accepted base units.
- Show **Batch / lot (optional)** and **Expiry date (optional)** directly below quantity, side by side on desktop and stacked on narrow screens. They must not require an expand action.
- Missing expiry is **Not recorded**, including in Review; it never means No expiry. Do not invent dates, copy dates/lot across allocations, or introduce a mandatory date policy. Preserve current date validation and independent batch provenance.
- Only **Add another batch** creates another allocation. Each has its own quantity/mode, optional lot, optional expiry, and optional cost. Do not automatically merge allocations by product/date.
- Product totals sum allocations exactly once; delivery totals sum products. Do not introduce a second editable total that can disagree with the allocations.
- Example: 12 bottles expiring May 2027 plus 12 expiring August 2027 equals 24 accepted bottles stored as separate batches in one receiving entry.
- Allow editing/removing an allocation without deleting siblings. Confirm removal when entered values would be lost. Each product needs at least one valid positive-quantity allocation; an added invalid/empty allocation must be corrected or removed before save.
- Known unit/case/Sales Pack scans choose the supported mode on an untouched allocation and focus its matching visible quantity input. Keep receiving cases and Sales Packs distinct; missing configuration requires correction rather than an invented conversion.
- Rescanning a selected product focuses its most recently edited allocation, preserving entered quantity and mode even if the scanned code has another packaging type. Never increment quantities or create another allocation automatically. Staff explicitly select/add another batch or change mode.
- A deliberate mode change preserves counts in their original mode or requires explicit correction before Review; it cannot silently reinterpret 24 bottles as 24 cartons or discard counts.
- Keyboard-only operation supports search, selection, quantities, batch actions, Review, and return to editing. Enter in search performs lookup and never commits stock. Do not focus hidden controls.
- Stack search and lines on narrow screens without a separate quantity stage or page-wide overflow. Keep focus and actions clear of fixed headers/footers.

## 3. Compact Add Product without losing useful details

Use the same compact product-details form from Products and Receiving. Remove the five compulsory wizard stages; reveal conditional fields when relevant. Preserve accessible labels, inline errors, and meaningful validation.

| Field or action | Agreed treatment |
|---|---|
| Name, category, base unit, cost, selling price | Visible and required; align form and server enforcement. Explicit zero cost is valid; blank creation cost is not silently zero. Preserve current selling-price constraints. |
| Item barcode | Visible, editable, scannable; unique internal barcode generated when blank. Remove separate Generate decision in normal creation; preserve barcode management elsewhere. |
| Status | Default Active without asking in normal creation; retain status management elsewhere. |
| Receiving unit, package size, package barcode | Group together and reveal when relevant. Required conversion cannot be buried in unrelated Planning. |
| Also sell sealed packs | Separate expandable configuration with all existing size/barcode/price constraints. Preserve sealed-pack/loose-unit accounting. |
| Brand | Optional. |
| Photo and family/variants | Expandable; choosing a parent family still requires the variant label. Keep standalone-product creation simple. |
| Purchase-pack cost and markup tools | Preserve optional pricing aids and existing validation; do not make every product use them. |
| Reorder level, safety stock, minimum quantity, lead time | Preserve in expandable Planning with established defaults; these feed recommendations. |
| Product supplier name | Omit from normal creation; use delivery Bought from for actual purchase source. Preserve existing supplier data/import compatibility and planning lead time. |
| Expiry warning days | Preserve optional override and established default. |
| Supplier Pullout Eligibility | Explicit available setting; never infer eligibility from category, expiry, or perishability. |
| Starting stock | Products origin may record already-present stock in an Opening Batch with contextual expiry. Receiving origin enforces zero and collects received batch details later. |
| Barcode printing | Preserve standalone option; receiving-origin save returns to the delivery first. |

Observed implementation gap to fix: the existing form requires category/prices while `ProductService::createProduct()` currently permits null category and defaults missing prices to zero. The new form must not weaken intended rules; enforce the agreed creation rules at the server boundary while checking affected callers/fixtures.

## 4. Purchase-cost entry and current product cost

- Accept cost per base unit or per applicable receiving case/Sales Pack. Offer only bases supported by saved configuration, clearly naming the unit and conversion.
- Example: PHP 480/carton / 24 bottles = PHP 20/bottle. Show effective unit cost and line total. For a non-divisible package cost, follow the explicit amount/rounding rules below; a rounded unit-cost display is not the source for recomputing the purchase total.
- Switching cost basis must not silently reinterpret an entered amount. Record the effective cost independently on every received batch/history record.
- Blank receiving cost keeps the established saved-cost fallback when available. Resolve all such fallbacks from the product cost before this delivery, not from an allocation already processed in the same save. Blank must not be described as unknown when it inherits a known saved cost.
- Explicit zero is a known cost, distinct from missing. Preserve this distinction for new creation, edits, and receiving; apply the known/unknown fallback rules below. If no entered or known saved cost exists, retain a missing cost rather than inventing zero.
- Equal effective costs across one product's allocations automatically become the current product cost under existing behavior, without another question.
- Different effective costs require an explicit **Current product cost** choice in final Review for that product. Identify batch context and unit, preserve every batch's own cost, and do not choose by allocation order or compute a weighted average.
- Preserve a valid choice across edit/Review. If an edit removes/changes its selected cost, require a new valid choice. Validate it against the final allocations server-side.
- Apply current cost once per product in the receiving transaction. This supplies a later receiving fallback; it does not automatically change selling price.

### Exact purchase amounts and derived unit costs

- Retain the entered cost amount, its basis (base unit/case/Sales Pack), and conversion used for each batch. Validate/recompute them server-side; do not trust a browser-supplied derived total or infer the original amount from a rounded unit cost.
- Calculate a batch's actual amount from its original cost basis and accepted quantity, retaining division precision until rounding the final batch amount once to cents. Sum persisted batch amounts for product/delivery purchase totals. Use decimal-safe arithmetic or equivalent exact money/rational calculations, not repeated binary-float rounding.
- Example: 1 carton of 24 bottles costing PHP 100 remains a PHP 100.00 purchase. The display may show **approximately PHP 4.17/bottle**; it must not turn the transaction into PHP 100.08. Two such cartons total PHP 200.00. A partly loose quantity uses the same original basis and rounds its final amount once.
- Persist the original basis and actual total together with the batch cost record so history can reproduce the amount. Keep existing two-decimal product unit-cost compatibility; where that field needs a rounded representative cost, identify it as such and never use it to overwrite an exact recorded purchase amount. Check valuation/report consumers so a rounded unit cost multiplied by quantity cannot silently replace that amount. This is not a new weighted-average or accounting-ledger project.
- Compare/deduplicate the offered current-product-cost choices at the product field's stored unit-cost precision, using the same server calculation as Review. Store actual batch amounts independently. Show any material difference between an actual package amount and its rounded unit-cost equivalent as a short rounding note, not another approval dialog.
- Unknown-cost batches do not become free batches. Show **Known cost** for partial totals and identify allocations whose cost is Not recorded; do not present the known subtotal as a complete delivery purchase amount.

### Known-zero and legacy missing-cost recovery

- Persist a reliable known/unknown cost state for future explicit product-cost entries and receiving choices. An intentional PHP 0 is known; missing input without a known fallback is unknown. Include this state in drafts, Review, server validation, and stale-review checks.
- Existing positive saved costs retain their established known fallback. For historical zero product costs without reliable provenance, treat them conservatively as **Not recorded**, not free. Do not rewrite historical amounts or infer that every legacy zero was intentional.
- A blank receiving input inherits a known saved zero as PHP 0, just as a known positive cost can be inherited. An unclassified legacy zero produces **Cost not recorded — enter cost if known**; staff may explicitly enter PHP 0 to confirm free goods. Do not demand a separate zero-confirmation dialog when they already entered zero deliberately.
- For mixed known/unknown allocations, offer only known values as current-product-cost choices. One distinct known value can be applied automatically; several distinct known values require the agreed choice. If every allocation cost is unknown, do not offer an invented zero or overwrite current product cost. Unknown batch costs remain unknown even when another batch supplies a current-cost choice.
- Any schema adjustment for cost provenance/original basis must preserve old callers and historical records; new constraints must not silently classify or delete legacy data.

## 5. Review, commit, and recovery

Use **Add to delivery** for scan/catalog selection and **Save receiving & update stock** for the final commit. Product selection, scans, and zero-stock catalog creation do not add received stock.

- Review validates every product/allocation and active quantity, conversion, date, and cost rule. Retain all values, show specific inline errors, and focus the first invalid input. If several errors are summarized, make the summary focusable and link to fields.
- Show product totals, every batch's quantity/lot/expiry/effective cost, delivery totals, receipt details, and any needed current-cost selection. Back to editing preserves values, order, choices, and linked context.
- Recheck authorization, active product status, relevant conversions, and inherited effective costs at commit. If material product changes would change the reviewed outcome, retain the draft and require refreshed Review; do not silently apply new carton arithmetic or costs.
- Validate each allocation and total bounds on the server. Commit the whole delivery, batch records, history, movements, and current costs atomically; any failure leaves stock and associated records uncommitted. Existing retry protection prevents duplicate effects.
- Reuse account-scoped encrypted draft recovery. Clear the receiving draft only after confirmed receiving success, never after product creation or an uncertain response.
- Restore old selection/quantity stages into the editor and old Review only after validation. A legacy single product line becomes one allocation; new drafts preserve every allocation and order.
- Preserve legacy damaged/rejected draft safeguards requiring good quantities to be re-entered. Do not silently remove stale/unavailable products, drop allocations, or discard an unrestorable draft.
- Preserve supported direct-entry links and replacement/pullout context. Existing single-product service callers remain supported as one allocation.
- For linked replacement receiving, carry the relevant pullout link onto each replacement allocation, sum their accepted quantities, and count one receiving group as one replacement receipt. Two batches in one save are two batches, not two receipts. Use the existing individual receiving record as a fallback identity for historical records without a group; never merge unrelated old receipts. A pullout link must match each allocation's product, and unrelated product groups must not inherit it.

## Acceptance criteria and verification

| Scenario | Required result |
|---|---|
| Known unit/case/Sales Pack/SKU code | Correct existing product/type; no Create Product prompt or stock mutation |
| Same-product SKU equals case/pack code | Explicit barcode type determines Receiving mode; POS unchanged; conflicting legacy barcode types are resolved explicitly |
| Inactive, ambiguous, or failed lookup | Appropriate recovery; no false missing-product claim, silent reactivation, or duplicate creation |
| Unknown carton/pack code | Packaging prompt/reminder, correct target field, no copied identifier, explicit existing-product/create choice |
| Unknown code cannot be classified | Code and delivery retained; Back/Cancel/search available; no default to individual item |
| Unknown 12-unit carton selected against existing 24-unit configuration | Require confirmation; mismatch cannot apply 24-unit multiplier; allow actual base-unit entry or authorized correction |
| Same sealed pack received and sold | One Sales Pack Barcode registration; no duplicate case-barcode requirement |
| Create with entered delivery values | Exact originating draft survives; zero-stock product returns once with correct mode and blank quantity |
| Cancel/error/restore or storage failure | Values/draft retained; no false saved state, unexpected navigation, or empty replacement draft |
| Tampered starting stock | Rejected or forced zero server-side for receiving-origin creation |
| Product created, receiving abandoned | Catalog product remains at zero; no receiving entry or stock movement |
| Two tabs/account switch/concurrent code registration/retry | Correct ownership and originating draft; no duplicate product or delivery effects |
| Rescan after entered quantity | Last edited allocation selected; values/mode retained; no increment or new batch |
| Same product with different batches | Separate quantity/date/cost provenance, one product total, one receiving save |
| Invalid or empty added allocation | Actionable correction/removal; no partial commit |
| Per-carton/Sales Pack cost | Correct supported conversion and visible rounded effective cost/total |
| PHP 100 for 24-unit carton | Original basis preserved; actual purchase total PHP 100.00; approximate PHP 4.17/unit does not produce PHP 100.08 |
| Multiple/partial packages with non-divisible cost | Derive each final batch amount from original basis, round once, sum stored amounts; do not sum repeatedly rounded unit estimates |
| Blank versus zero cost | Saved fallback or true missing cost preserved; explicit zero remains distinct |
| Blank receiving after a known-zero product cost | Inherits known PHP 0; legacy unclassified zero stays Not recorded until explicitly supplied |
| Mixed known/unknown batch costs | Preserve unknowns, label partial Known cost totals, choose only known current-cost values; all-unknown allocations do not invent zero |
| Different batch costs | Explicit valid current-cost selection; each batch cost and selling price preserved |
| Review/edit/change selected cost | Values retained; stale selection invalidated without choosing by order |
| Conversion/status/inherited cost changes after Review | Refreshed Review required before commit; all draft values retained |
| Legacy/new draft recovery | Old steps/lines migrate correctly; all new allocations survive; damaged/rejected safeguards retained |
| Creation fields bypassed via direct request | Required server rules hold; packaging and cross-code uniqueness constraints preserved |
| Save failure/retry | Atomic rollback or exactly one confirmed success; no duplicate stock/history/current-cost update |
| Replacement with two batches in one save | Two linked batches, accepted quantities summed, one replacement receipt; historical ungrouped receipt counts preserved |
| Desktop/mobile and keyboard | Visible focus, labelled controls, reachable search/actions, no hidden focus or obstructed fields |

Implementation reach:

- `src/frontend/components/report/stock_receiving.php`: lookup/recovery, inline editor, allocations, Review/validation, handoff, draft migration, stale-review protection.
- `src/frontend/components/inventory_management/products.php`: shared compact form, origin-aware stock enforcement, barcode classification, return/cancel, standalone printing compatibility.
- `src/backend/app/Services/ProductCodeLookupService.php`, `ProductService`, `ReceivingService`, and `BatchInventoryLedger`: reuse shared rules; extend receiving-specific inactive recovery and explicit allocations without changing unrelated POS behavior. Preserve authorization, CSRF, identifiers, transactions, operation tokens, and batch provenance.
- `src/frontend/assets/js/inventory-drafts.js` and `src/backend/includes/inventory_drafts.php`: reuse storage/confirmation lifecycle; ensure persistence success can be verified before navigation, account/tab isolation, and multi-allocation restoration. Change shared code only where necessary.
- `src/frontend/assets/css/reports.css`, `stock-receiving.css`, and mapped product-form sources: remove obsolete step rules, adapt layout, retain design tokens. Never edit the generated CSS bundle directly.
- Receiving history and batch/expiry views, caller contracts, fixtures, and direct links: preserve separate allocations and legacy compatibility. Update duplicate-product tests to enforce unique product groups while allowing deliberate batch allocations.
- Purchase-history, valuation/report cost consumers, cost schema/provenance compatibility, and SupplierPulloutService replacement summaries: preserve original purchase amounts, distinguish known-zero from missing, and count receipt groups rather than allocation rows. Add focused non-divisible-cost, partial-known-cost, legacy-zero, SKU/case overlap, carton-mismatch, and multi-batch replacement checks.

After implementation, run `npm run check`, `npm run check:contracts`, focused integration/browser checks, and the applicable TestSprite workflow after reading its skill. Cover desktop/narrow layouts, keyboard, light/dark, and reduced motion; use isolated databases/fixtures for stock mutations. Inspect failures and report unavailable credentials or untested real devices honestly.

This consolidation changes documentation only. No application implementation, operational data change, runtime test, or live usability verification is claimed. The agreed workflow rules above are authoritative; illustrations explain the idea and do not override them.
