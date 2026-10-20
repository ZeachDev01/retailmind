# Stock-based Replenishment Recommendations

Design agreed: 2026-10-20. Application implementation pending.

Implementation issue: [#256](https://github.com/ZeachDev01/retailmind/issues/256).

## Problem Statement

The Inventory Manager needs a realistic guide for shopping today: which Products need replenishing, how many individual units to buy, and why. The Store is not currently using forecasting for buying decisions. Existing suggestions can switch to forecast-derived targets automatically, add Safety Stock separately to Reorder Level, and round purchases to whole packages. That makes quantities difficult to explain and can suggest more goods than the Store intends to buy.

The Store needs explicit, editable stock targets rather than guessed buying quantities. Products without configured targets must still show stock problems without presenting invented suggestions.

## Solution

Use current recorded stock and Product-level planning settings for the present Replenishment Recommendations workflow. Only suggest buying when stock is at or below Reorder Level. Suggest enough Base Units to reach Target Stock, respecting Minimum Order Quantity without forcing whole-case purchases. Show a Receiving Unit breakdown as shopping guidance.

Reorder Level already includes Safety Stock. For example, expected demand of 15 bottles while waiting for replenishment plus 5 backup bottles gives a Reorder Level of 20. Safety Stock remains available for sale and is not added a second time to the purchase quantity.

Preserve the user's selected full table, rather than substituting a compact shopping list. These rows use illustrative values and 12 bottles per case:

| Priority | Product | On hand | Reorder at | Target | Suggested purchase | Why |
| --- | --- | ---: | ---: | ---: | --- | --- |
| Out of stock | Sparkling Water 330 mL | 0 | 12 | 48 | **48 bottles** · 4 cases | Buy 48 to reach target |
| Out of stock | Orange Soda 500 mL | 0 | 12 | Not set | **Set target stock** | Target needed |
| Low stock | Bottled Water 500 mL | 15 | 20 | 60 | **45 bottles** · 3 cases + 9 loose | Buy 45 to reach 60 |
| In stock | Cola 330 mL | 36 | 20 | 60 | **No purchase needed** | Above reorder level |

Use red, amber, and green status treatments with visible text so color is not the sole indicator. Recommendations never place orders, reserve stock, or require the owner to buy the suggested amount. Actual goods are recorded separately through Stock Receiving.

## User Stories

1. As an Inventory Manager, I want suggestions based on actual recorded stock, so that I can shop without employing forecasts.
2. As an Inventory Manager, I want to edit Target Stock per Product, so that buying reflects the Store's shelf space, budget, and expected turnover.
3. As an Inventory Manager, I want to edit Reorder Level, so that each Product has a clear trigger for buying more.
4. As an Inventory Manager, I want Safety Stock included in that trigger, so that backup stock is not counted twice.
5. As an Inventory Manager, I want to edit Safety Stock, so that the backup can reflect delivery delays and unexpected demand.
6. As an Inventory Manager, I want invalid combinations rejected clearly, so that my settings have a consistent meaning.
7. As an Inventory Manager, I want existing Products to start without invented targets, so that configuration is based on real buying choices.
8. As an Inventory Manager, I want Products with missing targets to show Set target stock, so that I know why a quantity is unavailable.
9. As an Inventory Manager, I want out-of-stock Products first and low-stock Products next, so that urgent buying needs are easy to find.
10. As an Inventory Manager, I want to see Products still in stock, so that I can distinguish them from Products needing purchases.
11. As an Inventory Manager, I want on-hand stock, reorder trigger, and target visible together, so that I can assess the recommendation without opening another screen.
12. As an Inventory Manager, I want the suggested purchase in Base Units, so that I know the exact number of goods to buy.
13. As an Inventory Manager, I want a cases-plus-loose-units breakdown, so that I can use the suggestion while shopping.
14. As an Inventory Manager, I want loose-unit purchases allowed, so that a saved case size does not force me to overbuy.
15. As an Inventory Manager, I want the minimum purchase quantity respected when replenishment is needed, so that guidance reflects the configured buying minimum.
16. As an Inventory Manager, I want an explanation when the minimum exceeds my target deficit, so that an unusually large suggestion is understandable.
17. As an Inventory Manager, I want no purchase suggested above the reorder trigger, so that a higher target alone does not repeatedly tell me to buy.
18. As an Inventory Manager, I want suggestions to reflect saved settings and received goods when refreshed, so that I do not shop from stale quantities.
19. As an Inventory Manager, I want Save, Cancel, and failed-save recovery in the existing Product editor, so that planning changes follow the familiar workflow.
20. As an Inventory Manager, I want planning edits to leave physical quantities and receiving history unchanged, so that settings cannot silently alter inventory.
21. As the owner buying goods, I want to choose different quantities or unplanned Products, so that suggestions remain guidance rather than purchase requirements.
22. As an Administrator or Super Administrator, I want to inspect recommendations without changing inventory settings in that workspace, so that oversight preserves the existing role boundaries.
23. As an Inventory Manager using a narrow screen, I want clear headers, readable quantities, and accessible navigation, so that the table remains usable while shopping.
24. As a future Store operator, I want forecast-based guidance to remain possible later, so that today's stock-based workflow does not require deleting existing forecasting capabilities.

## Implementation Decisions

- Extend the existing Product persistence, validation, and Products drawer rather than introducing a separate settings screen or service layer. Existing editable catalog details, price/history protections, activation behavior, and packaging-history protections remain in force.
- Add nullable Target Stock storage to Products. Null means not configured; do not represent that state as zero. Both fresh setup and an additive, repeatable upgrade must support it. Existing Products receive null rather than inferred targets. Preserve all inventory and historical records.
- Target Stock is an optional whole-number count of Base Units within the database integer range; when present, it must be strictly greater than Reorder Level. Reorder Level and Safety Stock remain nonnegative whole Base Units; Safety Stock must not exceed Reorder Level. Package size and Minimum Order Quantity remain positive whole numbers.
- Validate the final saved combination of new and existing settings on the server when any relevant setting changes. Reject fractions, malformed values, overflow, and invalid relationships rather than silently truncating, clamping, or rewriting settings. Return actionable errors and preserve the edit draft. Apply consistent validation to active Product creation, editing, and import entry points that can write these settings; omitted target values preserve existing targets on partial updates, and an explicitly blank target clears it.
- Preserve legacy settings during upgrade. If older records violate the new relationships, require correction when saving relevant planning settings; do not invent replacement values or block unrelated catalog corrections solely because legacy planning settings are invalid.
- Product setting mutations remain restricted to the active Inventory Manager workspace, with existing authorization, CSRF protection, and write gates enforced server-side. Existing oversight access remains read-only.
- Classify stock consistently in affected stock-status and low-stock consumers: no stock is Out of stock, positive stock at or below Reorder Level is Low stock, and stock above Reorder Level is In stock. Safety Stock is not a second independent trigger. Reuse existing recorded-stock semantics; do not introduce an expiry-adjusted availability model in this work.
- With no Target Stock, return an explicit unconfigured suggestion state. Do not substitute zero, a forecast target, reorder plus safety, or a guessed purchase amount. Keep the actual stock status visible, show Not set and Set target stock, and let an authorized Inventory Manager reach the existing Product editor. Read-only workspaces must not gain edit rights through that affordance.
- With a configured target and stock above Reorder Level, return no purchase needed even when below Target Stock or when a minimum is configured.
- With a configured target and stock at or below Reorder Level, suggest the larger of Target Stock minus current stock and Minimum Order Quantity. Reaching the trigger exactly must still produce a positive suggestion because Target Stock is strictly higher. Do not add Safety Stock or round to multiples of the minimum or package size.
- Display exact suggested Base Units plus their Receiving Unit breakdown using the saved Units per Package. For 45 bottles with 12 per case, show 3 cases plus 9 loose bottles. Omit zero remainders and empty package counts, and use the Product's actual unit labels. A multiplier of 1 should not create a redundant case breakdown. Receiving Units are distinct from customer Sales Packs.
- Explain the observed stock, target, and suggested amount in short operator-facing language. When the minimum raises the purchase above the deficit, identify that reason instead of claiming the purchase reaches the target exactly.
- Make the current Replenishment Recommendations entry point deterministic stock-based guidance regardless of available forecast jobs or their readiness. Show the stock-settings basis; remove forecast details and model-readiness messaging from this stock-based buying table where it would imply forecasts determine these amounts. Keep existing forecasting services, history, and governance intact. Do not build a mode selector or enable automatic switching now.
- Preserve the selected seven columns and include active Products in all three stock states, plus missing-target states. Default ordering is Out of stock, Low stock, then In stock, with deterministic ties. Retain useful existing search/filter/receiving actions without replacing the selected table or adding purchase approval requirements.
- Preserve semantic table headers, keyboard access, status text, and usable narrow-screen overflow or reflow without losing essential values. Other role workspaces retain their existing view permissions.
- Reload stock-dependent suggestions after Product planning changes and successful Stock Receiving. Update any existing recommendation snapshots or relevant cache invalidation so old targets and old formulas are not reused. If snapshot caching remains a separate implementation, document these invalidation requirements in its existing ticket rather than building a new caching system here.
- Update existing recommendation print/export surfaces, response consumers, fixtures, and affected checks where they expose these quantities. Unconfigured targets must remain distinct from no purchase needed throughout those consumers. Reading, printing, refreshing, or editing settings must never change stock, generate forecasts, or retrain models.
- Coordinate with existing pack work so on-hand quantities and suggestions use Base Units exactly once. A Receiving Unit shopping breakdown must not claim availability of unopened Sales Packs or change stock form.

## Testing Decisions

- User-confirmed primary seam: the existing Product save-to-recommendation service workflow with database assertions, plus a focused browser check of the selected table. Reuse existing tests; do not build a new end-to-end test architecture.
- Prefer existing Product stock-setting and replenishment contract tests, existing disposable database fixtures, and replenishment browser checks. Test public results and persisted effects, not exact SQL, private helpers, or source-text fragments. Use the highest existing seam that exercises validation, persistence, and resulting recommendations together.
- Cover the four selected table rows, all seven headers, explicit units, status text, default priority order, missing-target setup guidance, read-only access, and narrow-screen/keyboard use.
- Verify stock 15, reorder 20, target 60, minimum 1, case size 12 produces 45 bottles and 3 cases plus 9 loose; verify stock 0 and target 48 produces 48 and 4 cases. Verify changing Safety Stock within valid bounds does not independently increase the suggestion.
- Verify stock exactly at Reorder Level produces a suggestion; stock just above it produces none. Verify minimum 50 raises a deficit of 45 to 50, whereas minimum 10 leaves 45 unchanged. Explain target overshoot correctly. Do not purchase merely because a minimum exists.
- Verify null targets remain distinct from zero suggestions and retain low/out-of-stock status. Cover missing target on an otherwise In stock Product without inventing a quantity.
- Exercise malformed, fractional, negative, out-of-range, and invalid cross-field settings through supported write boundaries. Verify target equal to or below reorder and safety above reorder are rejected without partial writes. Check omitted-target preservation, explicit clearing, existing invalid settings, and saving a changed reorder against an existing target.
- Verify fresh setup and existing-database upgrade preserve data and leave targets unset. Use disposable databases, never Store data.
- Verify authorization and CSRF failures, Save/Cancel behavior, draft recovery, unchanged stock/history after planning edits, and updated recommendations after valid saves and actual receiving. Existing receiving protections must remain intact; actual purchases can differ from suggestions.
- Verify a ready forecast, missing forecast, observation mode, and recommendation-suspension state do not silently change the stock-based formula or cause forecast work. Preserve existing tests for separate forecasting behavior rather than changing unrelated model rules.
- Verify relevant print/export output and any active cached recommendation path use the same quantities and missing-target states. Run existing receiving-conversion checks to protect historical package multipliers and stock preservation.

## Out of Scope

- A forecast mode selector, enabling forecast-based shopping guidance, new forecast algorithms, model training, promotion, or governance changes.
- Automatic Target Stock estimation, automatic target migration, budget optimization, shelf-capacity management, expiry-based purchasing limits, or claims that manually configured targets guarantee ideal buying.
- Whole-case-only purchasing, supplier constraints beyond the existing minimum, mandatory suppliers, purchase requests, purchase orders, shopping reservations, or automatic purchases.
- Replacing the selected table with the later compact shopping-list alternatives.
- Redesigning Stock Receiving, altering recorded quantities, changing stock forms, rewriting historical prices/costs/receipts, or changing physical Product identity.
- A new caching framework, dependency, service hierarchy, or unrelated catalog/UI refactor.

## Further Notes

- This specification supersedes the earlier requirement to preserve existing stock-rule formulas and package/MOQ rounding in Product editing and direct-receiving designs. It does not supersede historical preservation, workspace restrictions, or forecasting governance.
- Coordinate with #215 (Product stock settings), #207 (recommendation snapshot caching), and #253 (Base Unit consistency for Sales Packs). Those tickets remain separate; their existence is not evidence their behavior is already implemented.
- Existing forecast recommendation calculations use a different target and rounding model. Preserve those separate capabilities for future work without letting them override this stock-based buying table.
- The selected example quantities are demonstrations, not production defaults. Realistic suggestions depend on accurate recorded stock and Inventory Manager-set targets that account for budget, space, turnover, and expiry risk.
