---
status: accepted
---

# Scheduled Fiscal Periods and opt-in automatic rollover

On 2026-11-10 the Store owner asked to plan reporting windows ahead of time and have the system close the finished window and open the next one. The [Fiscal Period lifecycle specification](../specs/fiscal-period-lifecycle.md) had deliberately required explicit Administrator closure and opening and listed automatic rollover as out of scope, and the database allowed only one `open` row.

Fiscal Periods may now be created in advance with a `scheduled` status: the dates are reserved and continuous, but no transaction may post against them until they are opened. A range that covers today becomes open when nothing else is open; every later range is scheduled. An Administrator opens a scheduled period manually from the Fiscal Periods page, or turns on the `fiscal_period_auto_rollover` Store Setting (default off) so that the system closes an expired open period and opens its scheduled successor. Closing prerequisites (open Cashier Shifts, unreconciled GCash sessions, pending Inventory Counts) still stop the rollover, and a blocked rollover leaves the period open for review. The rollover runs on the daily notification tick and lazily on Administrator landing pages, one period per run, so a long absence never skips a reporting window.

Considered and rejected: reopening the finished period instead of pre-creating ranges (it would break finalized history), generating the next range automatically from the previous length (it would invent owner-defined dates), and making rollover unconditional so Finance never stalls (it would bypass closure reconciliation). The `scheduled` status and the generated `open_period_key` unique index keep the single-open-period invariant intact.

## Amendment 2026-11-10: Period Schedule generation and rollover notices

The Store owner asked for a way to add "a set of Fiscal Periods" at once, because the contiguous-chain rule made every future period a separate form submission, and for the system to announce when a rollover ran or stalled.

- **Period Schedule**: the Fiscal Periods page can generate 1–24 **calendar-aligned** periods (monthly or quarterly) starting the day after the latest period ends, with an optional name prefix. The whole set is written in one transaction and aborted entirely if any generated name already exists. Cadence is calendar-aligned, so monthly ranges end on the real month end (28/29/30/31) and quarterly ranges end on 31 Mar, 30 Jun, 30 Sep, or 31 Dec.
- **Opt-in is preserved, but intent is inferred**: generating a set that produces at least one `scheduled` period turns `fiscal_period_auto_rollover` on, once, and the toggle is also available on the Fiscal Periods page. The default for a fresh Store is still off, and an Administrator can turn it off again. This does not make rollover unconditional: closure prerequisites still block it.
- **Rollover notices**: a rollover now writes one `fiscal_period` notification per Administrator per event — success (closed and opened) or blocked (closure prerequisites). Notices also queue the daily email when Store email alerts are enabled and the Administrator has email notifications on. Blocked notices are deduplicated per period per day so an overdue period cannot flood the bell on repeated page loads. A rollover that closes a period with no scheduled successor is reported as critical because trading is then blocked.
- **Manual closure notices**: closing an open period by hand writes the same `fiscal_period` notification and daily email as a rollover, so a closure is visible outside the page that performed it.
- **Guidance interaction**: completing a walkthrough topic no longer reopens its dock when the Fiscal Periods form is submitted; the page now posts, redirects, and renders flash messages, and a completed topic is only reopened through Help's Replay.

