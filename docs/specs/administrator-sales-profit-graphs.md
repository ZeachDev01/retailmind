# Administrator dashboard: clear sales, profit, and 30-day graphs

## Problem Statement

The Administrator wants to answer “How is the Store doing today, and what needs my action?” The dashboard mixes financial terms and time windows, shows only Net Sales in its main chart, and expands a large hourly/daily table that pushes historical performance down the page. Gross Sales, Net Sales, and Gross Profit are difficult to distinguish. The existing rolling performance section presents summary cards rather than a trend graph.

## Solution

Keep urgent actions first, followed by financial totals, a Sales graph, a separate Gross Profit graph, and a Last 30 Days performance graph. Preserve the existing Store identity and restrained dashboard styling.

Use one shared Today / This Week / This Month selection for the Sales and Gross Profit graphs and their totals. Default to Today. Today displays hourly results; This Week and This Month display daily results. The Sales graph compares Gross Sales and Net Sales. The separate Gross Profit graph avoids confusing revenue with profit.

Collapse the detailed hourly/daily table behind “View detailed breakdown.” Exact values remain available through chart interactions and the accessible breakdown. The separate Last 30 Days graph compares daily Net Sales and Gross Profit, with totals and percentage changes versus the previous 30 days. Show exact dates and identify ongoing intervals.

## User Stories

1. As an Administrator, I want urgent actions before financial analysis, so that I can address Store problems promptly.
2. As an Administrator, I want clearly named Gross Sales, Net Sales, and Gross Profit totals, so that I do not confuse revenue with profit.
3. As an Administrator, I want a brief explanation of each financial measure, so that I can understand the displayed amounts.
4. As an Administrator, I want Gross Sales and Net Sales in one graph, so that I can see the effect of discounts and refunds.
5. As an Administrator, I want Gross Profit in its own graph, so that I can assess profitability without confusing it with sales.
6. As an Administrator, I want Today selected initially, so that the dashboard opens on current Store activity.
7. As an Administrator, I want hourly graph intervals for Today, so that I can identify busy and quiet hours without reading a large table.
8. As an Administrator, I want daily graph intervals for This Week, so that I can compare days within the current week.
9. As an Administrator, I want daily graph intervals for This Month, so that I can understand the current month's trend.
10. As an Administrator, I want one period selection to update both graphs and their totals, so that the financial views stay aligned.
11. As an Administrator, I want exact period dates and Philippine-time labels, so that the reporting window is unambiguous.
12. As an Administrator, I want exact values on hover or tap, so that I can inspect individual intervals.
13. As an Administrator, I want details collapsed initially, so that the dashboard remains compact.
14. As an Administrator, I want to expand and collapse the breakdown with a keyboard, so that exact values remain accessible without chart interactions.
15. As an Administrator, I want a daily Last 30 Days graph, so that I can see recent performance across calendar-month boundaries.
16. As an Administrator, I want Net Sales and Gross Profit together in that graph, so that I can compare revenue and profit trends.
17. As an Administrator, I want current and previous 30-day totals with changes, so that I can assess the direction of Store performance.
18. As an Administrator, I want partial current periods identified, so that I do not mistake ongoing results for final figures.
19. As an Administrator, I want real zero activity distinguished from unavailable data, so that missing information does not look like zero sales or profit.
20. As an Administrator, I want missing costs to produce unavailable profit points, so that the graph does not invent profit.
21. As an Administrator, I want estimated costs identified, so that I understand the uncertainty in estimated profit.
22. As an Administrator, I want negative values displayed correctly, so that refund-heavy intervals and losses remain visible.
23. As an Administrator, I want charts and controls usable on a phone, so that the dashboard remains understandable on a smaller screen.
24. As an Administrator, I want period selection to survive refresh, so that updated results preserve my context.
25. As an Administrator, I want financial period controls to leave operational alerts unchanged, so that a chart choice does not silently change what needs attention.
26. As an Administrator, I want links to the existing profit report to match the selected dates, so that I can investigate the same figures.
27. As an Administrator, I want access to totals and detailed values if charts fail to load, so that I can still assess Store performance.

## Implementation Decisions

- Extend the existing StoreOperationsDashboardWorkspace and Administrator dashboard presentation. Reuse GrossProfitService financial calculations, existing Chart.js support, dashboard styling, period controls, and report destinations. No new dependency or database schema is needed.
- Preserve Administrator authorization and the separate operational/technical role boundaries in ADR-0001. This feature reads Store records; it does not mutate sales, refunds, costs, or inventory.
- Gross Sales means completed product sales before discounts and refunds. Net Sales equals Gross Sales minus discounts minus issued Cash Refunds. Do not relabel the existing after-discount, before-refund series as Gross Sales. Use recorded financial values and existing report rules rather than inventing a second accounting formula.
- Gross Profit equals Net Sales minus the corresponding cost of products sold, adjusted for returns according to the existing profit report. It excludes operating expenses and must never be labelled Net Profit or take-home earnings. GCash Service exchanges do not become product revenue.
- Use the established historical sale-cost provenance and refund-cost rules. Missing historical costs remain missing; do not silently substitute current product prices or costs. Distinguish recorded, estimated, incomplete, and unavailable results in plain language.
- Sales and profit are discrete interval amounts, not cumulative totals. Use grouped bars for Gross Sales versus Net Sales, consistent with the current sales graph. Use a line graph for Gross Profit and two labelled lines for the 30-day view, with a common peso axis within each graph and no dual axes. Retain stable metric colors across sections.
- Use Philippine time throughout. Today begins at midnight, weeks begin Monday, and months begin on the first calendar day. Include only events through one shared as-of time. Show elapsed empty intervals as zero; exclude future intervals rather than implying future zero activity.
- Attribute sales to completion time and Cash Refunds to issue time, including refunds of older sales. Profit buckets must reconcile to the existing profit report for the same dates and as-of time, including its refund-cost treatment. Test this explicitly rather than assuming separately calculated totals agree.
- Last 30 Days includes today and the preceding 29 calendar days through the shared as-of time. Compare against the preceding 30 complete calendar days. For example, on 23 October 2026 the current range is 24 September–23 October and the previous range is 25 August–23 September. Clearly label today/current totals as ongoing; do not imply the two windows are equally complete.
- Default the historical view to a fixed 30-day window, replacing its 7/30/90-day selector for this focused dashboard change. The historical graph remains independent of Today / This Week / This Month. Preserve existing operational count information outside financial plots; do not place transaction, discount, or exception counts on a peso axis.
- Keep operational attention queries at their existing default 30-day horizon where applicable and make that horizon visible. Chart selection must not alter operational query scope. Preserve existing alert rules and destinations; a wider attention-system redesign is outside scope.
- Show Gross Sales, Net Sales, and Gross Profit totals for the selected main period. Keep product cost and the explanation “Gross Profit excludes operating expenses” available without adding another chart. Avoid repeated period badges and repeated long headings.
- The detailed breakdown is closed initially and uses the same selected period. Include Gross Sales, discounts, refunds, Net Sales, product cost, and Gross Profit with unavailable/estimated states. Reuse a native disclosure or the existing accessible disclosure pattern rather than introducing a new interaction framework.
- Show a gap for unavailable Gross Profit intervals, never a zero or a line bridged across missing costs. Estimated intervals must be identifiable. If any interval has incomplete costs, do not present a partial known-profit sum as the complete period total; retain the report's incomplete-total policy.
- Display percentage change only when both totals are complete and the previous total is positive. When it is zero or negative, show both amounts and a plain “No comparable percentage” explanation. Label estimated comparisons and withhold comparisons involving missing costs.
- Keep controls, totals, report links, and accessible details functional if the chart library fails. Legends and readable text must identify series without relying solely on color. Respect reduced motion and provide touch and keyboard access to exact values.
- Preserve selected period in the URL and refresh/report destinations. Respect ADR-0012's existing dashboard cache lifetime and invalidation; reuse batched aggregation and avoid one full profit-report calculation per hour/day. Cache entries must include all required graph data and expire across Philippine date boundaries.

## Testing Decisions

- Prefer the existing database-backed dashboard workspace contract as the primary seam: use a controlled Clock and financial fixtures to assert externally meaningful interval values, totals, dates, and availability states. Extend the existing dashboard browser coverage for interactions and accessibility; no new service seam is needed.
- Prior art includes the Administrator dashboard workspace, route, browser, and cache tests, plus Gross Profit reporting, historical-cost, and dashboard integration tests. Reuse their fixture conventions and authorization checks.
- Cover a discounted sale, partial refund, refund of an older sale, return-cost treatment, negative interval, empty interval, missing cost, estimated cost, zero/negative previous total, and unavailable service. Assert interval sums and period totals reconcile with authoritative report calculations to currency precision.
- Verify Philippine midnight, Monday/week start, month start, a 30-day range crossing months, exact as-of inclusion, exclusion of future events, and current versus previous 30-day boundaries.
- In browser coverage, switch all three main periods and assert that both main graphs, totals, exact dates, breakdown, URL, and report destination update together while the 30-day graph and attention scope stay independent.
- Verify the breakdown is collapsed initially and can be expanded using a keyboard, touch can reveal exact values, mobile layout avoids page-level horizontal overflow, and missing/estimated values have readable explanations. Verify totals and controls still work when chart loading fails.
- Check existing caching and authorization behavior for the expanded payload, including date rollover and non-Administrator access. Tests should assert visible behavior and financial results rather than private helper names or decorative CSS class strings.
- During implementation, run the relevant existing tests and inspect desktop/mobile rendering. This specification-only task does not claim those application checks have passed.

## Out of Scope

- Application implementation, deployment, or live financial-data changes in this specification task.
- New accounting policy, net-profit accounting, operating-expense tracking, GCash accounting changes, forecasting, or cost-recovery workflows.
- New dashboard dependencies, background infrastructure, database migrations, export formats, or custom date-range controls.
- A broad navigation redesign, attention-rule overhaul, or changes to other role workspaces.

## Further Notes

Published as [GitHub issue #274](https://github.com/ZeachDev01/retailmind/issues/274), labelled `ready-for-agent`.

The user confirmed the dashboard's daily Store-health purpose and accepted the final explanation proposing separate Sales and Gross Profit graphs plus a 30-day trend. They delegated remaining decisions and requested specification synthesis with no further interview. The testing seams above are selected from existing coverage under that delegation.

This specification supersedes overlapping financial visualization and expanded-table behavior from the earlier Store Overview Net Sales chart specification. Existing historical-cost, Cash Refund, authorization, and caching rules remain authoritative. Browser inspection was unavailable during this discussion; source review supports the current-state findings, while visual verification belongs to implementation.
