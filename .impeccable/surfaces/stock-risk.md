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
