# Replenishment On-hand Display

Design decisions agreed and implementation authorized: 2026-11-12. Implemented.

## Agreed

- In the replenishment queue, show Saleable Stock as the primary On hand total in the Product's Base Unit.
- For Products with Sales Packs, show actual unopened Sales Packs and loose Base Units below that total, with the Sales Pack size. Example: **29 pieces**, then **2 packs + 5 loose · 12 pieces/pack**.
- Use recorded stock forms, not a quotient and remainder inferred from total stock. Sales Pack size is distinct from the Receiving Unit multiplier.
- Products without Sales Packs show their Base Unit total, such as **8 bottles**.
- Show expired stock separately; it does not contribute to the primary total. This display does not dispose of or change recorded stock.

- Stock-rule buying suggestions, stock status, and forecast-backed buying quantities use Saleable Stock consistently with the displayed total. For example, 20 physical Base Units including 8 expired means 12 Saleable Base Units for these calculations.
- The existing PDF export carries the same Saleable Stock totals and actual pack/loose breakdown, with expired stock shown separately.

## Verification scope

- Cover Products with and without Sales Packs, actual sealed/loose balances that differ from a quotient/remainder conversion, and receiving multipliers that differ from Sales Pack sizes.
- Cover mixed saleable/expired batches and expired-only stock: expired goods remain recorded but do not cover replenishment needs.
- Verify stock-rule and forecast-backed suggestions, stock status, and PDF values agree with the displayed Saleable Stock while preserving the existing buying formulas apart from their stock input.

## Related design

[Stock-based replenishment recommendations](stock-based-replenishment-recommendations.md) separately defines the planned target-stock buying formula. This display discussion does not replace that formula or authorize implementing that broader pending work.

## Implementation and validation

- Reused the Product catalog's batch aggregation pattern for actual saleable and expired sealed/loose balances. Both stock-rule and forecast calculations use the same expiry-aware stock input and supplied Philippine date; goods expiring today remain saleable, consistent with the existing expiry policy.
- The screen labels On hand as Saleable stock. Its total, actual stock forms, and expired balance supply the PDF export. Stock is never written by viewing or exporting.
- Passed `npm run check`, `npm run check:contracts`, replenishment queue, rendered metadata/error-state, and PDF tests; forecast coverage, landing-page, and scheduler tests also passed.
- Passed `RUN_DB_TESTS=1 node src/backend/tests/replenishment_stock_browser_test.js` using a disposable database, covering mixed and expired-only stock, Products without Sales Packs, differing receiving/Sales Pack sizes, unchanged inventory and batches, an actual PDF download, and 1280/390px light/dark rendering. Inspected captures in `output/playwright/replenishment-stock/`.
- Existing failures remain in `demand_forecast_integration_test.php` (three superseded-job assertions) and `replenishment_supplier_integration.php` (fixture lacks `df.model_version`). Both reproduce with the original service from HEAD. They were not broadened into unrelated forecasting fixes.
