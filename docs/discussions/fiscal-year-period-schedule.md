# Fiscal-year Period Schedule

Design confirmed through the interview and implemented on 2026-11-12 (Asia/Manila).

## Confirmed

- Generate one Fiscal Year covering twelve months from its fiscal-year start, rather than requiring January–December.
- The Administrator chooses 1, 2, 3, 4, 6, or 12 periods; this divides the year into whole-month portions rather than choosing how many monthly or quarterly ranges to append.
- Continue after the latest existing Fiscal Period and preserve existing dates, records, and statuses.
- Number periods from the beginning of the generated Fiscal Year. With four periods, the first is Q1 even when the fiscal year starts outside January.
- With no existing periods, start today. Otherwise start the day after the latest period ends, including when that date is mid-month.
- Use Q1–Q4 for four periods and P1–Pn for every other supported count.
- Every label uses the fiscal year's starting year, including periods that fall in the next calendar year (for example FY2026 Q1 through FY2026 Q4).
- Calculate every boundary from the original fiscal-year start, advancing by multiples of 12 / period count months. If the original day does not exist in the target month, use that month's last day; restore the original day in later months where possible. Each period ends one day before the next boundary, including the final period before the next fiscal-year boundary. For a January 31 monthly start in a non-leap year, starts are January 31, February 28, March 31, and April 30.

This replaces the calendar-aligned generation behavior in [ADR 0029](../adr/0029-scheduled-fiscal-periods-and-opt-in-rollover.md). Existing periods retain their dates and names; the new rules apply to newly generated schedules.

## Implementation scope

The shared generator and Administrator form now use the supported number of divisions for one Fiscal Year instead of cadence plus count. The optional name prefix precedes the generated fiscal-year label, for example Store FY2026 Q1. The form defaults to four quarters.

The scheduling contract covers non-January starts, every supported count, annual numbering, month-end clamping without cumulative drift, leap years, continuity after existing periods, and duplicate-name rollback. Direct browser verification against a disposable MySQL database covers Q/P generation, invalid-count refusal, and desktop/mobile form bounds. The broader walkthrough browser test currently fails at the existing dashboard Registers-link assertion before reaching the fiscal form.

Existing transaction atomicity, name collision protection, single-open-period rules, and closure prerequisites remain constraints.
