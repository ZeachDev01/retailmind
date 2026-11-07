---
status: accepted
---

# GCash Overview Live Custody and Activity Scope Separation

On 2026-11-07, the administrator workspace for GCash operations was clarified to resolve operator confusion between point-in-time custody balances and historical reporting scopes.

Previously, the "Report range" date and cashier filter was rendered as a standalone panel above "Service balances". Because "Service balances" displays real-time funds in drawer cash and the GCash mobile app for the active shift session, placing date controls directly above it misled operators into assuming date selection filtered live fund balances. Furthermore, "Completed activity" (the section actually controlled by the date range) was visually separated and pushed down below live review alerts.

The Overview tab is now explicitly partitioned into two functional zones:
1. **Live Custody & Status**: Contains "Service balances" (cash drawer and GCash app wallet) and "Needs review" (closing differences and unresolved exchanges). These reflect current shift reality and are unaffected by date filters.
2. **Performance & Activity Scope**: Groups the reporting period controls ("Report range" / Activity Scope) directly adjacent to "Completed activity" (cash-in, cash-out, total gross fees, and completed count).

This layout aligns visual hierarchy with data dependencies, eliminates operator ambiguity regarding live versus historical figures, and retains full compatibility with existing transaction filtering and CSV exports.
