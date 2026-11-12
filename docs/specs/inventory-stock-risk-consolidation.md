# Stock Risk in Stock Overview

Implemented 2026-11-12 after the user selected **Compare products (prototype A)** and requested a redesigned table with colorful category icons. This is the implementation record, replacing the earlier planning draft.

## Agreed scope

Stock Risk helps Store staff see where recorded stock may tie up money or become a loss, based on stock in, stock out, remaining quantities, recorded sales, and batch expiration. Routine physical counts are not required. Existing manual Physical Counts remain available when staff need to investigate a discrepancy.

The new tab lives inside Stock Overview: `components/inventory_management/inventory_overview.php?tab=risk`. Existing Overview and Products content remains available. The separate Insights & Risk menu entry is retired; `inventory_insights.php` redirects to the tab and rejects mutation requests. Automatic ABC classification and cycle-count schedule generation are removed from this workflow. Existing schedule records are preserved without migration or deletion.

## Comparison table

- Product and category, with labeled colorful icons.
- Recorded stock in Base Units, recorded sales over 30 days, estimated stock coverage, and the primary concern.
- Search by product, SKU or category; risk filters; sortable product/stock/sales columns; ten products per page.
- Expandable product details explain the next step, overlapping concerns, historical limitations, and recorded stock-in/out balances over 90 days.
- Mobile rows show labeled quantities and visible concern/details controls, with a native sorting selector. Desktop retains the comparison table.

Colors identify categories and concern types; text and distinct icon shapes carry the same meaning. Action Blue remains the action/focus color. The existing RetailMind shell, system typography and themes remain the visual system.

## Calculation rules and limits

`App\Inventory\StockRiskReader` reads the already-scoped active product list, sales in Base Units, and 90 days of stock movements in bulk. It does not write inventory or generate forecasts.

The historical windows include today and the preceding 29/89 Philippine calendar dates. Sales are recorded gross units before refunds or reversals, matching the retired Insights metric; forecast-only imports are excluded. Stock balances include recorded movements such as receiving, sales, returns and corrections.

Coverage is current saleable stock divided by recorded 30-day sales / 30. Current expired quantities are excluded. Coverage greater than 30 days is a **Possible excess** prompt to review stock, not proof of excess or a required purchasing action.

Product creation dates and reverse reconstruction of recorded balances guard history-dependent labels. Products with less than 30 days of established history or a recorded balance reaching zero/below during that period have no coverage estimate. A no-sales flag requires a full 90-day established history, positive reconstructed balances throughout, positive remaining stock and zero recorded sales. Stockout-affected or incomplete history is shown separately rather than treated as no demand. Even a no-sales flag does not prove there is no customer demand.

Expiry uses the existing Stock Overview batch results and each Product's warning period, including expired and missing-date concerns. Multiple concerns remain discoverable through filters/details. Unrecorded losses, damage or missing historical movements can make reconstructed balances differ from shelf reality; no automatic physical-stock claim is made.

## Prototype decision and source

The user selected A (Compare products), rather than B (grouped review list) or C (split product/movement view). The prototype and its host-route preview are archived on local branch **`prototype/stock-risk-ui-20261112`**, commit **`def48222e29b1249132aba21ee9ca9c48d1ca698`**. Prototype variants, the development preview gate and its npm command are removed from the implementation working tree. No linked implementation issue was supplied; this document preserves the source pointer and verdict.

## Verification

- `php src/backend/tests/stock_risk_reader_test.php`: history guards, stockout/restock, sales window boundaries, Sales Packs, saleable coverage, overlapping flags, inactive/empty products, movement reconciliation and legacy-route retirement.
- `node src/backend/tests/inventory_overview_clarity_browser_test.js`: existing Overview behavior plus real Stock Risk markup, search/empty results, filters, pagination, details, desktop/mobile sorting, tab visibility/keyboard behavior, light/dark rendering and page-overflow checks at 1440px and 390px.
- `php src/backend/tests/inventory_alert_contract.php` and `php src/backend/tests/inventory_recent_movements_test.php`.
- `npm run check`, PHP/JavaScript syntax and `git diff --check`.
- A read-only check against the local configured database loaded the active product rows without modifying stock.

Browser captures: `output/playwright/inventory-overview/stock-risk-{light,dark}-{1440,390}.png`. The isolated browser render omits the live sidebar. The full signed-in theme/account suite and a browser session authenticated against the live database have not been run.
