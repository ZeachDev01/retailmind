# Store Overview: Net Sales chart and focused operational sections

## Problem Statement

The Administrator needs to see how much the Store sells today, this week, and this month, including how activity changes within each period. The existing rolling 7-, 30-, or 90-day performance comparison does not provide this chart. Calling sales earnings or profit would confuse collected sales with profitability.

Store Overview also contains sections the user no longer wants on this surface: Store governance, Inventory oversight, and Demand Forecast performance. Staff & cashier shifts needs a heading icon consistent with the existing interface.

## Solution

Add three Net Sales summary totals for Today, This Week, and This Month, and one chart controlled by those same period choices. The chart shows hourly Net Sales for Today and daily Net Sales for This Week and This Month. Show the selected period's total alongside the chart. Keep the existing rolling performance comparison and its range selector independent of the calendar-period chart.

Use Philippine time. A week begins Monday and ends Sunday; a month is a calendar month. Include only activity through the current time. Include elapsed intervals with no sales as zero values. Deduct refunds in the period when issued, even if the original sale occurred earlier; negative values are valid.

Remove the three unwanted sections from Store Overview and add an existing people/staff icon beside Staff & cashier shifts. Preserve other dashboard sections and existing destinations outside this page.

## User Stories

1. As an Administrator, I want Today's Net Sales total, so that I can assess the current day's sales.
2. As an Administrator, I want This Week's Net Sales total, so that I can assess the current calendar week.
3. As an Administrator, I want This Month's Net Sales total, so that I can assess the current calendar month.
4. As an Administrator, I want one chart with Today, This Week, and This Month controls, so that I can change perspective without navigating to another report.
5. As an Administrator, I want hourly points for Today, so that I can identify busy and quiet hours.
6. As an Administrator, I want daily points for This Week, so that I can compare the week's elapsed days.
7. As an Administrator, I want daily points for This Month, so that I can follow the month's sales pattern.
8. As an Administrator, I want the selected period's total beside its chart, so that I can relate individual points to the aggregate.
9. As an Administrator, I want sales measured after discounts, so that discounts are reflected exactly once.
10. As an Administrator, I want issued refunds deducted from Net Sales, so that returned money is reflected.
11. As an Administrator, I want a refund deducted on its issue date, so that a later refund does not rewrite the original sales day.
12. As an Administrator, I want negative chart values supported, so that refund-heavy intervals remain accurate.
13. As an Administrator, I want Philippine-time boundaries, so that late-night activity belongs to the correct Store day.
14. As an Administrator, I want Monday-start calendar weeks and calendar months, so that period labels have consistent meanings.
15. As an Administrator, I want zero values for elapsed intervals without activity, so that gaps are not mistaken for missing data.
16. As an Administrator, I want current periods marked as ongoing, so that I do not mistake incomplete totals for final results.
17. As an Administrator, I want to retain the rolling performance comparison, so that the new calendar views complement existing oversight.
18. As an Administrator, I want Store governance, Inventory oversight, and Demand Forecast performance removed from this overview, so that the page focuses on the information I requested.
19. As an Administrator, I want a staff icon beside Staff & cashier shifts, so that the section is easier to identify.
20. As an Administrator on a phone or keyboard, I want readable chart labels, usable period controls, and access to exact values, so that I can understand the chart without relying on hover or color alone.
21. As an Administrator, I want unavailable data distinguished from zero sales, so that failures do not appear as healthy financial results.
22. As an Administrator, I want refresh to preserve my selected chart period and comparison range, so that refreshed information retains my context.

## Implementation Decisions

- Extend the existing Administrator Store-operations dashboard workspace and presentation; preserve its authorization boundary. Store Overview refers to that existing dashboard, currently headed Store Operations. Renaming the page is not part of this change.
- Reuse the existing sales and Cash Refund records. Completed sales totals already include discounts; subtract refunds without deducting discounts again. Held or discarded sales and Forecast Data Imports do not contribute revenue.
- Net Sales is completed sales after discounts minus refunds. It excludes neither product costs nor operating expenses and must not be labeled profit or earnings.
- Attribute each sale to its recorded sale time and each Cash Refund to its issue time. Do not modify underlying sales, refunds, receipts, or historical attribution.
- Cash Refunds across original payment methods contribute to Net Sales; this is Store sales reporting, not a cash-drawer-only metric. Do not introduce a Legacy Reversal migration or new correction workflow.
- Use the existing Philippine-time database/session convention and a testable clock. Start Today at local midnight, This Week at Monday midnight, and This Month at the first day's midnight. Bound results at the current time; exclude later activity and avoid double-counting boundary events.
- Hourly/daily buckets are interval amounts, not cumulative running totals. Include the current partial bucket and earlier zero-activity buckets; do not plot future intervals as completed zero-sales intervals.
- Summary totals and chart points must agree for the same period and as-of time. Preserve currency precision and display Philippine pesos with two decimal places.
- Default the chart to Today. Period controls must visibly identify the selection and work with a keyboard. Keep chart selection independent from the existing rolling 7-, 30-, and 90-day comparison selector.
- Preserve manual refresh, five-minute refresh, freshness indicators, deferred refresh during interactions, and the existing server cache/invalidation behavior. Incorporate the new metrics without allowing an older cached response to break the new view.
- Reuse installed chart capabilities and existing styling where practical. No new dependency, database schema, export system, or generic analytics abstraction is required.
- Provide exact per-interval values in an accessible text or table form and a clear empty state. A data-load error must never be presented as a zero total.
- Remove only the requested dashboard sections and their page-specific presentation. Retain underlying capabilities and access elsewhere, plus the Store attention queue, Store status, rolling comparison, and Staff & cashier shifts.
- Use the existing icon library for the staff heading. Treat a redundant decorative icon as hidden from assistive technology.

## Testing Decisions

- Confirmed primary seam: the existing database-backed Store-operations dashboard workspace, using the existing injectable clock. Assert returned period totals, bucket values, authorization, and error behavior rather than private helpers or SQL wording.
- Cover already-discounted sales, partial/full refunds, non-cash refunds, refund-only negative intervals, refunds of earlier-period sales, zero activity, and multiple events within a bucket. Verify that point sums equal each period total with currency precision.
- Cover Manila midnight with a clock supplied in a different timezone, Monday week boundaries, a week crossing a month/year boundary, first/last month days, leap February, partial current buckets, and exclusion of future activity.
- Extend the existing Administrator route contract to check requested sections are absent, retained sections remain, the staff heading includes its icon, and the chart/summary controls are present.
- Use the existing browser-test approach for period switching, exact-value accessibility, empty/error presentation, negative-axis rendering, and desktop/mobile layout. Confirm refresh and range changes retain the independent selection.
- Extend the existing dashboard cache/invalidation checks where needed to ensure cached metrics remain valid for the selected periods and a completed refund invalidates affected totals.
- Prior art exists in the Administrator dashboard workspace, route, and cache contracts, plus the existing cashier dashboard browser-layout checks. Prefer these seams over new services or a separate analytics API.
- The user confirmed these testing seams before issue publication.

## Out of Scope

- Implementing application changes during this session.
- Gross profit, net profit, product cost accounting, or operating expense tracking.
- Changing the existing rolling performance calculation or replacing its comparison section.
- Comparison percentages for the new calendar totals, custom date ranges, exports, scheduled reports, or additional chart types.
- Removing governance, inventory, or forecasting capabilities from other workspaces, navigation, services, or authorization policies.
- Renaming the Store Operations page, redesigning the dashboard shell, adding multi-Store support, or changing receipt/refund workflows.
- Legacy data migration or reconstruction of historical product costs.

## Further Notes

The user confirmed Net Sales, one switchable chart, refund-date deduction, Philippine time, Monday-start weeks, calendar months, hourly Today points, daily week/month points, and zero-activity intervals. The user subsequently requested the three section removals and staff heading icon and explicitly deferred implementation to another session.

The Net Sales definition has been recorded in the existing domain glossary. No application code has been changed for this request. Follow the existing single-Store authority and dashboard caching decisions. Publishing this specification does not authorize implementing it in this session.
