---
version: 1
slug: "stock-risk"
primary_target: "src/frontend/components/inventory_management/stock_risk.php"
related_targets: ["src/frontend/components/inventory_management/inventory_overview.php", "src/frontend/assets/css/stock-risk.css", "src/frontend/assets/js/stock-risk.js"]
---

# Stock Movement

## Mode and confirmed direction

Operate. The user confirmed the replacement design and reviewed an interactive mockup on 2026-11-12. Preserve RetailMind's system font, Action Blue, restrained panel borders and native light/dark themes. Focus on distinct selling days, quantity and stock. Expiry remains in Overview. No new global tokens or dependency.

## Composition and interaction

One compact comparison surface: date range and Generate Report, then search and stock scope. No month inputs, summary cards or repeated criteria paragraphs. Main columns are Product, Stock, Sold, Selling days, Status and Trend, with a compact details action. Default to positive current stock and fewest selling days.

Three consecutive 30-day periods end yesterday in Philippine time. Fast/Slow use latest-period selling days; Dead Stock checks the complete 90 days. Limited or stockout-affected history withholds unsupported judgments. Status and trend remain separate; icons and labels accompany color. The existing info-tooltip component explains criteria on hover/focus/tap and dismisses with Escape.

Product name or chevron opens a right-side native-dialog drawer showing current stock, status, all three date-labelled period comparisons, latest-period stock flow and history limitations. Calculation basis uses a disclosure. Closing returns focus and restores scrolling. Mobile records reflow to labelled quantities with native sorting; the drawer fits the screen and scrolls internally.

Generate Report opens a light print preview containing all matching pages in sort order plus every product's drawer details, filter scope, dates and generation time. Shared limitations appear once. Print / Save as PDF uses landscape print rules.

## Implementation and verification

See docs/specs/inventory-stock-movement.md for precise classification, Dead Stock precedence and stock arithmetic. Old asset filenames, DOM identifiers and risk links remain supported. Reader tests cover distinct days, periods, packs, history limitations, trends and reconciliation. Browser checks cover existing Overview behavior, tooltips, drawer focus/close, filters, sorting, pagination, complete/filtered reports and print action at 1440px/390px in both themes. npm run check, npm run check:contracts and inventory alert/recent-movement checks passed.

Checks use isolated fixtures. A signed-in live database browser session and physical printer output remain unverified.

Report content refinement, 2026-11-26: preserve both complete tables and the existing print flow. Use Sales history, exact stock-flow dates and compact Opening/Received/Sales/Returned/Other/Closing headers. Product rows retain name and SKU; category is omitted. Show days without repeating “selling”; shorten coverage to No history/Partial/Unknown and explain it once. Five brief Notes paragraphs replace the long reading guide (under 200 words), retaining units, timing, status/trend basis and reconstructed-balance limitations. Keep tabular numerals and restrained square table borders. Omit empty search criteria and duplicate generation timestamps; use singular product counts. Local browser checks cover desktop/mobile, print/PDF, filters and sorting; npm run check passed. TestSprite verification is pending project selection.

Chart hover refinement, 2026-11-26: Weekly movement and Fastest movers show formatted units sold when hovering within the matching week or product row, without requiring an exact point or bar hit. History coverage shows product counts on segment hover. Existing browser fixtures exercise real mouse hover and tooltip values at desktop and mobile widths. TestSprite remains pending project selection.

Product history simplification, 2026-11-26: the user confirmed two chart groups, Igo ang history and Kulang ang history, under Product history. The latter combines partial, unavailable and unknown coverage; exact reasons remain in product details and reports. The info tooltip explains the 90-day basis. Existing layout, chart IDs and classification rules remain. Local browser checks passed at 1440px/390px in light/dark themes, including counts, chart data, tooltips, drawer and reports; rendered mobile/light and desktop/dark captures were inspected. npm run check passed with an existing duplicate open-register ID warning in shifts.php. TestSprite run 8b4a0597-72a9-42e8-b30a-f51504d93ed9 uses the selected retailmind-ui-role-devices-20261116 project and a synthetic read-only fixture; passed all 9 executed steps. Live Store data was not checked.

English copy correction, 2026-11-26: use Complete history and Limited history in the Product history chart, with an English explanation tooltip. The grouping and classification rules are unchanged. Updated local browser checks passed; the mobile render was inspected. TestSprite was not rerun for this copy-only correction.

Status report filter, 2026-11-26: the confirmed Status dropdown combines with stock scope and search. All statuses is the default; individual choices are Fast-Moving, Slow-Moving, Dead Stock, Limited history and Stockout affected. Both report tables use the same matching rows, across all matching pages, and the report scope names the selected status. Empty matches disable Generate Report and name the selected status. Charts remain a summary of all products. Local browser checks passed for all statuses, combined filters, empty results, both report tables and print controls at desktop/mobile widths; the mobile Dead Stock render was inspected. npm run check and PHP syntax passed; the existing shifts.php duplicate open-register ID warning remains. TestSprite run 3908b839-51b8-425c-a871-4ef6f7c405c7 confirmed filters and search recovery but failed two assertions because report popups appeared blank (12/14 steps passed). A separate local Playwright check against that same HTTP fixture rendered the correct Dead Stock report and print control; cause of the TestSprite discrepancy remains unresolved. Failure steps were inspected; artifact download was refused because the stored environment had changed. Live Store data and physical printing were not checked.
