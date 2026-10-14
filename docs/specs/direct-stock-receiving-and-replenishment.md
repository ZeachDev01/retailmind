# Direct Stock Receiving and Replenishment for Supermarket Purchases

## Problem Statement

The Store owner usually buys stock in bulk from supermarkets or larger stores. The Inventory Manager needs to monitor stock, review what needs replenishing, and record the goods brought back. Supplier administration, purchase requests, purchase orders, and Administrator approval create unnecessary steps for this normal buying process.

Today, normal Stock Receiving requires an approved replenishment request or purchase-order line and processes one product at a time. Although units and packages are supported, the Inventory Manager cannot simply record a shopping trip with several products. Reorder planning and forecast-based recommendations also lead into purchasing workflows rather than a straightforward stock-focused process.

## Solution

Make the daily workflow:

**Check stock → review Replenishment Recommendations → owner buys goods → Inventory Manager records bought stock.**

The Inventory Manager selects several products, enters actual quantities in individual units or packs/cases, reviews the entry, and saves once. Accepted quantities are added to existing stock, receiving history is recorded, and stock-dependent recommendations reflect the updated quantities. Supplier, receipt reference, and purchase cost are optional. No purchase request, purchase order, emergency justification, or Administrator approval is required for this ordinary receiving flow.

Example: the Store has 8 cans. The owner brings back 2 cases containing 24 cans each. The Inventory Manager records 2 cases and saves. Stock increases by 48 cans to 56; the corresponding recommendation reflects the new stock.

Recommendations guide buying without placing orders or changing stock. The owner may buy different quantities, different products, or buy from a different store.

## User Stories

1. As an Inventory Manager, I want to see current stock, so that I know what the Store has available.
2. As an Inventory Manager, I want low-stock and out-of-stock products identified, so that I can prioritize replenishment.
3. As an Inventory Manager, I want suggested buying quantities, so that I can help the owner decide what to buy.
4. As an Inventory Manager, I want recommendations without maintaining supplier records, so that supermarket purchases fit the Store's daily work.
5. As an Inventory Manager, I want to understand whether a suggestion comes from stock rules or an available Demand Forecast, so that I can judge its usefulness.
6. As an Inventory Manager, I want unavailable or low-readiness forecast information shown honestly, so that missing forecasts are not treated as zero demand.
7. As a Store owner, I want buying to happen outside RetailMind, so that I can shop wherever stock is available.
8. As an Inventory Manager, I want to record newly bought goods directly, so that receiving does not wait for a purchase request or purchase order.
9. As an Inventory Manager, I want to record stock without Administrator approval, so that routine inventory execution remains my responsibility.
10. As an Inventory Manager, I want to select several products in one entry, so that I can record a bulk shopping trip efficiently.
11. As an Inventory Manager, I want existing product search and barcode lookup retained, so that I can find products quickly.
12. As an Inventory Manager, I want to enter individual units, so that I can record loose items.
13. As an Inventory Manager, I want to enter packs or cases using the product's saved pack size, so that I do not manually calculate every bulk purchase.
14. As an Inventory Manager, I want the pack size and resulting unit quantity visible before saving, so that I can catch quantity mistakes.
15. As an Inventory Manager, I want to record actual purchased quantities even when they differ from recommendations, so that stock reflects goods physically brought back.
16. As an Inventory Manager, I want supplier and receipt details to be optional, so that missing paperwork does not block stock entry.
17. As an Inventory Manager, I want to enter purchase cost when available, so that existing cost and purchase-history behavior can be maintained.
18. As an Inventory Manager, I want to record relevant batch and expiry information, so that received goods remain compatible with existing stock handling.
19. As an Inventory Manager, I want damaged purchased units excluded from usable stock, so that available stock is accurate.
20. As an Inventory Manager, I want to review all lines before one save, so that I can confirm the shopping entry.
21. As an Inventory Manager, I want invalid entries explained without losing entered values, so that I can correct mistakes.
22. As an Inventory Manager, I want a failed entry to leave every product unchanged, so that a shopping trip is not partially recorded.
23. As an Inventory Manager, I want bought quantities added to current stock, so that existing stock is not overwritten.
24. As an Inventory Manager, I want a clear success summary, so that I know which products and quantities were added.
25. As an Inventory Manager, I want refreshed recommendations after receiving, so that buying advice uses current stock.
26. As an Inventory Manager, I want receiving history to show products, quantities, time, and recording staff, so that I can trace stock additions.
27. As an Administrator, I want existing oversight and historical records preserved, so that simplifying receiving does not erase accountability.
28. As a Store operator, I want inventory mutation restricted to the active Inventory Manager workspace, so that other workspaces cannot use direct receiving as a bypass.

## Implementation Decisions

- Reuse Stock Receiving and its existing ReceivingService as the shared stock-write boundary. Extend it for a multi-product submission rather than introducing a separate stock-update system.
- The normal receiving entry defaults to direct recording of bought goods. It must work with no supplier record, request, order, or emergency reason.
- A single submission may contain several product lines with their own quantity mode, optional cost, damage, batch, and expiry information. Optional supplier and receipt references may be entered once for the shopping entry and retained with its receiving records. Receipt means a text reference here; attachment upload is not required.
- Validate the complete entry and commit all receiving lines atomically. One invalid line or database failure must roll back every inventory, batch, cost, history, and movement change from that submission. Do not implement bulk entry as independently committed calls to the existing single-product operation.
- Require active products and valid positive whole-number quantities. Resolve pack sizes server-side from saved product details; show the resulting unit quantities before submission. Reject invalid quantity modes and numeric values rather than silently coercing them. Prevent duplicate product lines in one entry with a clear correction message.
- Quantities are additions, not replacement stock counts. Record the pack size used so later product changes do not alter historical meaning. Product configuration remains the place to maintain pack size.
- Only accepted units increase inventory and saleable batch quantities. Damaged goods bought during the trip must not be added to usable stock or later deducted from pre-existing stock a second time. Preserve existing inventory-control history without introducing an additional approval step for direct receiving.
- Supplier, receipt reference, and purchase cost are optional. Omitted cost must not erase an existing product cost or pretend an unknown purchase cost is zero. Retain valid existing purchase-cost behavior when a cost is provided; label its unit basis clearly. A shopping store entered for a receipt must not silently replace the product's preferred supplier.
- Keep existing applicable batch, expiry, Fiscal Period, Store write-gate, CSRF, authorization, transaction, and concurrency protections. This feature adds no new mandatory expiry policy.
- Inventory execution remains restricted to the active Inventory Manager workspace. Removing purchase prerequisites does not grant mutation rights to the Administrator, Super Administrator, or Cashier workspaces. Existing Cashier Stock Issue reporting and Administrator oversight remain intact.
- Make stock monitoring, Replenishment Recommendations, and direct Stock Receiving the primary daily path. Suppliers and legacy purchasing workflows must not be prerequisites or prominent steps in that path. Preserve existing records and compatibility for historical linked receiving; do not delete purchasing data or redesign purchasing approval as part of this feature.
- Reuse existing product lookup, barcode scanning, units/packages controls, and receiving history. Update their callers, fixtures, and instructions to support selection of active products without requiring an approved purchasing line.
- Keep recommendations non-binding. Viewing or accepting a suggestion must not create a purchase request automatically, place an order, or alter stock. Recording an unplanned purchase is supported.
- Reuse existing stock-rule and forecast-based calculations. Where no forecast is available, expose the existing stock-rule suggestion with its basis instead of inventing forecast values. No supplier configuration is required: existing product-level planning settings and defaults remain usable. Do not redesign formulas or the forecasting model in this feature.
- After successful receiving, recalculate or reload the stock-dependent recommendation view using the latest stock and existing forecast outputs. Receiving must not trigger model training or synchronous forecast generation.
- Keep audit attribution on every receiving line and show one user-facing summary for the save. Retain entered values on validation failure and prevent ordinary repeated clicks from submitting twice; use the repository's existing duplicate-submit protection where available.
- Reconcile the domain documentation with direct receiving while retaining the existing separation between inventory execution, Administrator oversight, and forecasting governance. No destructive schema migration or new dependency is expected; add storage only if existing receiving records cannot represent the agreed behavior.

## Testing Decisions

- The user confirmed the proposed highest-level test seam: the full Stock Receiving browser-to-HTTP workflow, with database assertions for stock, receiving history, and refreshed recommendations. Invalid entries must save nothing.
- Extend existing stock-receiving browser workflow and its fixture support rather than creating a new test architecture. Existing replenishment supplier integration and demand-forecast tests provide prior art for any small supporting checks that cannot be verified through the same workflow.
- Test observable behavior, not source text, internal helper structure, or exact SQL. The primary modules covered are Stock Receiving, ReceivingService, workspace authorization, and the recommendation view used after saving.
- Verify the Inventory Manager can receive without supplier, request, order, or emergency reason; verify other active workspaces cannot mutate stock through the same route.
- Verify one multi-product save supports mixed units/packages and adds the right quantities. Include the 8 + (2 × 24) = 56 example and a product with no purchasing history.
- Verify actual quantities can differ from recommendations and goods can be received without a recommendation.
- Verify missing optional details preserve existing cost and preferred-supplier data. Verify a supplied cost is recorded using the displayed unit basis.
- Verify damaged goods never increase usable stock or deduct existing units again. Verify existing batch and expiry handling is retained.
- Verify zero, negative, fractional, malformed, duplicate-product, inactive-product, and invalid pack entries produce understandable errors without partial writes. Exercise a failure on a later line to prove earlier lines roll back.
- Verify simultaneous legitimate receipts both contribute to stock; existing stock must not be overwritten. Verify normal repeated clicking does not record the same entry twice.
- Verify the success summary and history identify actual accepted quantities and recording staff, and that recommendations reflect saved stock without retraining or fabricating forecasts.
- Verify retained linked-receiving history and existing stock sales/count/issue behavior are not broken by the receiving change. Do not expand this into repairs for unrelated purchasing bugs.

## Out of Scope

- Supplier selection, supplier comparison, supplier maintenance redesign, or mandatory Supplier Product Terms.
- Shopping budgets, purchasing approval, supplier payments, accounts payable, electronic order sending, and receipt attachment uploads.
- Automatic purchases, automatic stock changes from recommendations, new forecast models, changed forecasting horizons, or forecasting retraining triggered by receiving.
- Destructive removal of suppliers, requests, purchase orders, or their historical records.
- A new stock-count or stock-correction workflow; newly bought stock is an addition, while corrections continue through existing inventory controls.
- Broader account permissions, Cashier workflows, multi-store support, or unrelated purchasing bug fixes.

## Further Notes

- The user explicitly chose inventory work to remain with the Inventory Manager, units and packs/cases to be supported, and supplier/receipt details to be optional. The focus is stock and replenishment guidance rather than managing procurement.
- Existing architecture keeps Demand Forecasts and Replenishment Recommendations as decision support and inventory execution with the Inventory Manager. Direct receiving follows those boundaries while changing the ordinary purchase-approval prerequisite.
- Source inspection found that the existing emergency-receiving exception depends on an administrator identity and a reason. It is not an appropriate normal path for the Inventory Manager and must not be used as the implementation shortcut.
- Source inspection also identified apparent defects in reorder-planner request creation and purchase-order approval permissions. These observations were not verified live. The new daily workflow must not depend on those paths; repair them separately if retained purchasing workflows require it.
- This specification authorizes a stock-focused implementation, not a redesign of every inventory screen. Preserve working inventory features and keep the daily path simple.
