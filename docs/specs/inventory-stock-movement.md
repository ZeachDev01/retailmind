# Stock Movement in Stock Overview

Implemented 2026-11-12 after the confirmed design discussion and interactive layout review. This replaces the calendar-month comparison and the earlier [Stock Risk implementation](inventory-stock-risk-consolidation.md).

The next iteration replaces the repeated 3×30-day grids with charts: three page-level cards and one weekly chart in the drawer. Charts use 13 weekly buckets built from the same daily sales query as the 90-day periods, so no extra query or schema change is needed. The report keeps the detailed three-period table and summary.

## Periods and quantities

- Three consecutive, non-overlapping 30-day periods ending yesterday in Philippine time. Exact date ranges and month names identify each period; today is excluded from sales and trend.
- The main table shows Product, Stock, 90-day sales, Selling days, Status, and Trend. Stock is the current recorded quantity; 90-day sales is one total across the three periods; Selling days refers to the latest 30 days.
- Three page-level charts above the table: weekly movement for all products, the five fastest movers by 90-day units, and history coverage counts. A Chart.js-free browser shows a short fallback note instead of blank canvases.
- The Product history chart uses two groups: Complete history (all three periods complete) and Limited history (any partial, unavailable or unknown period). Product details and reports retain the exact coverage reasons; movement classification is unchanged.
- Sales Frequency counts distinct dates with recorded customer sales. Multiple receipts, repeated line items, and bulk purchases on one day count as one selling day. Quantity is separate and Sales Packs use the existing base-unit conversion.
- Sales remain gross recorded quantities before refunds/reversals. Forecast-only imports are excluded. Receiving, stock issues, pullouts and corrections are not customer sales.

## Classification and trend

- **Fast-Moving:** sales on 10–30 distinct days in the latest period.
- **Slow-Moving:** sales on 0–9 distinct days in the latest period, with at least one sale in the full 90 days.
- **Dead Stock:** positive current stock and no recorded sales in a complete preceding 90-day product history.
- **Limited history:** product created after the review start, or missing creation date. Numbers remain visible, but classification and trend are withheld.
- **Stockout affected:** stock is unavailable now or reconstructed stock reached zero or below in the review, so Fast/Slow and trend are withheld.
- As explicitly chosen by the user, Dead Stock takes precedence over a stockout warning when there is a complete product history, no sales in 90 days and stock now. The drawer still identifies the availability limitation, and trend remains unavailable.
- Trend compares latest selling days against the arithmetic average of the preceding two periods: greater = Nisaka, lower = Nihinay, equal = Pareho. There is no extra score or significance threshold.
- The ten-day threshold is an agreed store rule, not a calibrated forecast. Classification does not cause replenishment, disposal or any stock change.

## Table, tooltips and drawer

Default to products with positive current stock, sorted by fewest selling days. All products includes zero/negative balances; these are never Dead Stock. Search includes product, SKU and category. Column sorting, mobile sorting and ten-row pagination remain available.

Status filters the table to All statuses (default), Fast-Moving, Slow-Moving, Dead Stock, Limited history or Stockout affected. It combines with search and stock scope and resets pagination. Generate Report includes every matching product in the current sort order in both tables, with the selected status recorded in the report scope. Empty results explain the selected status and disable Generate Report. The page charts continue to summarise all products.

Use short status labels with icons and restrained semantic colors. The existing info-tooltip component explains criteria on hover, keyboard focus and tap; Escape dismisses a tooltip. Longer explanations are not repeated in table rows.

Confirmed refinement, 2026-11-12: the Status heading also explains all five classifications in one tooltip, with green Fast-Moving, orange Slow-Moving, red Dead Stock, slate Limited history, and orange Stockout affected badges. A visible Status guide supplies the same explanation on mobile, where table headings are hidden. Per-product criteria remain available; classification rules and Trend are unchanged.

Tooltip placement reserves space for the fixed desktop or mobile header, chooses the roomier side of its trigger, and limits height to the available viewport space. Short screens allow internal scrolling to reach every status; Escape remains dismissed while scrolling. `stock_movement_tooltip_browser_test.js` covers fixed-header overlap and reachable final content at 1280×680, 390×600, and 640×360.

Product name and chevron open a right-side drawer. The native dialog provides modal behavior and Escape handling, with a local Tab wrap while open; closing returns focus, restores scrolling and detaches the outside-click and keyboard listeners. Details show current stock, status, a 13-week sales chart, latest-period stock flow and history warnings. A weekly bar has no figure before the product existed; a missing figure is never drawn as zero. The coverage note stays with the chart. Calculation basis uses a disclosure.

The drawer reconstructs yesterday's closing stock by reversing today's movements from current stock. It then reconstructs the latest period's opening stock. Opening + received + sales stock movement + returns + corrections / other = closing. Recorded sales/movement mismatches are identified. Reconstructed balances cannot establish actual shelf counts or complete movement history.

## Report and compatibility

Generate Report opens a print preview with Print / Save as PDF. The product breakdown and latest 30-day stock flow tables include all products that match the stock and search filters in the current sort order. The preview includes the review dates, generation time, and shared calculation notes. Empty results disable report generation. Blocked pop-ups produce a recovery message.

The confirmed report redesign follows the Reports document style: configured Store name and available contact details, navy-accented title, Philippine-time generation stamp, review dates, filters, product count, prepared-by name when available, readable summary table, product details, and an end-of-report footer. The summary shows Units sold in base units for each of the three exact 30-day periods, oldest first, with coverage labels beside the counts. Stock, latest Selling days, Status, and Trend remain visible. A4 landscape preserves room for the summary, with repeating table headings and product details kept together. The existing direct preview and Print / Save as PDF flow remain; no new report-options step is introduced.

The existing stock_risk.php, stock-risk.css, stock-risk.js, DOM identifiers and ?tab=risk links remain compatible; ?tab=movement is supported. Calendar-month inputs and history filter cards are replaced by the agreed rolling periods and compact table. Overview, Products, expiry workflows and Physical Counts remain available. There is no migration, dependency or inventory mutation.

## Verification

- php src/backend/tests/stock_movement_reader_test.php: rolling boundaries, year rollover/leap year, bulk purchases, packs, distinct selling days, classification boundaries, exact trend comparison, new/unknown history, stockouts, Dead Stock precedence, latest-period reconciliation, today's stock changes, 13 weekly buckets, active scope and legacy links.
- node src/backend/tests/inventory_overview_clarity_browser_test.js: existing Overview behavior, page charts and drawer chart, 1440px/390px in light/dark themes, tooltip focus/Escape/tap, right-drawer focus/close/viewport bounds, filters, sorting, pagination, complete and filtered reports, PDF/print preview and blocked pop-ups.
- npm run check and npm run check:contracts passed; inventory alert and recent-movement checks passed.
- Captures and fixture PDF: output/playwright/inventory-overview/.

Browser checks use isolated fixtures. A signed-in live database browser session and physical printer output have not been checked.
