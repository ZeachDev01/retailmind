---
status: accepted
---

# Scheduled Fiscal Periods and opt-in automatic rollover

On 2026-11-10 the Store owner asked to plan reporting windows ahead of time and have the system close the finished window and open the next one. The [Fiscal Period lifecycle specification](../specs/fiscal-period-lifecycle.md) had deliberately required explicit Administrator closure and opening and listed automatic rollover as out of scope, and the database allowed only one `open` row.

Fiscal Periods may now be created in advance with a `scheduled` status: the dates are reserved and continuous, but no transaction may post against them until they are opened. A range that covers today becomes open when nothing else is open; every later range is scheduled. An Administrator opens a scheduled period manually from the Fiscal Periods page, or turns on the `fiscal_period_auto_rollover` Store Setting (default off) so that the system closes an expired open period and opens its scheduled successor. Closing prerequisites (open Cashier Shifts, unreconciled GCash sessions, pending Inventory Counts) still stop the rollover, and a blocked rollover leaves the period open for review. The rollover runs on the daily notification tick and lazily on Administrator landing pages, one period per run, so a long absence never skips a reporting window.

Considered and rejected: reopening the finished period instead of pre-creating ranges (it would break finalized history), generating the next range automatically from the previous length (it would invent owner-defined dates), and making rollover unconditional so Finance never stalls (it would bypass closure reconciliation). The `scheduled` status and the generated `open_period_key` unique index keep the single-open-period invariant intact.
