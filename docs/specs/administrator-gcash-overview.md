# Administrator GCash Overview: funds, outstanding issues, and daily activity

## Problem Statement

The Administrator needs to answer three questions quickly: what Service Cash and Service Wallet funds are recorded, which exchanges or closing discrepancies need attention, and how much completed activity and gross fees occurred today.

The existing overview can confuse missing balances with a genuine zero, label closed-session actual counts as expected balances, and count waived discrepancies as pending work. Period controls live away from the summary they affect. Transaction-list search and type selections also affect headline totals, while Cashier filtering can hide outstanding issues and leave asynchronously refreshed review sections inconsistent. A transaction count covering every status appears beside completed-only principal and fees without a sufficiently clear distinction.

## Solution

Keep the existing four sections and RetailMind visual identity. Lead Balances & Summary with separate Service Cash and Service Wallet amounts, their recording state, session responsibility and check time, and actionable Store-wide alerts. Keep any combined amount secondary.

Follow with Today-based completed activity. Put shared period and Cashier controls beside its totals; use them in Transactions too. Keep transaction search and type local to the list. Outstanding issues and review sections cover all dates and all Cashiers, independently of activity filters. Preserve traceable history, complete filtered export, and existing audited review actions and Cashier recording boundaries.

## User Stories

1. As an Administrator, I want Service Cash and Service Wallet displayed separately, so that I can assess each source of exchange funds.
2. As an Administrator, I want recorded funds before historical performance, so that I can understand the current operating position first.
3. As an Administrator, I want open-session amounts labelled Expected, so that I understand they derive from recorded movements.
4. As an Administrator, I want closed-session amounts labelled Last counted with their closing time, so that I do not mistake historical counts for current funds.
5. As an Administrator, I want missing counts displayed as Not recorded, so that unknown funds are distinguishable from a genuine zero.
6. As an Administrator, I want the session status, responsible Cashier and last actual-check time, so that I can identify responsibility and the age of the evidence.
7. As an Administrator, I want a clear explanation that recorded amounts are not a live GCash app balance, so that I understand their limits.
8. As an Administrator, I want combined funds kept secondary, so that a large combined amount does not conceal a shortage in one fund.
9. As an Administrator, I want unresolved exchanges visible on the landing screen, so that interrupted or uncertain exchanges receive attention.
10. As an Administrator, I want only unwaived closing discrepancies counted as outstanding, so that the alert describes pending review.
11. As an Administrator, I want outstanding alerts to cover every date and Cashier, so that historical reporting selections cannot hide problems.
12. As an Administrator, I want each alert to open its review section, so that I can inspect the underlying records.
13. As an Administrator, I want waived discrepancies preserved in reconciliation history, so that approval does not erase a recorded shortage or excess.
14. As an Administrator, I want Today selected initially in Philippine time, so that the page opens on the Store's current reporting day.
15. As an Administrator, I want Month-to-date and custom dates, so that I can review longer reporting periods.
16. As an Administrator, I want period controls and the selected dates beside the totals, so that their scope is apparent.
17. As an Administrator, I want a shared Cashier selection for summary activity and Transactions, so that I can review one Cashier consistently.
18. As an Administrator, I want completed exchange count, so that the headline count uses the same completion basis as the financial totals.
19. As an Administrator, I want completed Cash-In and Cash-Out principal reported separately, so that I can see the money exchanged in each direction.
20. As an Administrator, I want gross service fees split between cash and wallet, so that I can see where the fees were earned.
21. As an Administrator, I want principal distinguished from revenue and gross fees from net profit, so that I do not misread the financial measures.
22. As an Administrator, I want transaction search and type selection to narrow only the transaction list, so that headline activity retains its shared period and Cashier scope.
23. As an Administrator, I want the list count labelled as matching records, so that I understand it may include statuses beyond Completed and legacy records.
24. As an Administrator, I want transaction references, timestamps, status, Cashier and Shift attribution, so that I can trace an exchange.
25. As an Administrator, I want details showing signed cash and wallet movements, fees and linked adjustments, so that I can explain the recorded balance impact.
26. As an Administrator, I want legacy reference-only records identified honestly, so that unavailable movement data is not invented.
27. As an Administrator, I want stable pagination and full filtered CSV export, so that I can inspect or download every matching record.
28. As an Administrator, I want expected and counted closing cash and wallet with separate signed variances, so that I can inspect shortages and excesses without netting them away.
29. As an Administrator, I want waiver identity and reason retained with each discrepancy, so that its review is accountable.
30. As an Administrator, I want unresolved exchanges to retain notes and previously recorded partial movements, so that resolution does not lose or duplicate money movements.
31. As an Administrator, I want Verify & Clear to explain that it completes the exchange and records remaining movements, so that I understand the consequence before confirming.
32. As an Administrator, I want review actions protected by existing permissions, confirmations and audit records, so that review remains controlled and traceable.
33. As an authorized Cashier, I want exchange recording to remain in the Cashier workspace under existing Shift and session checks, so that cash-handling responsibility stays clear.
34. As an Administrator, I want refreshes to keep alerts, review rows and summaries consistent with their declared scope, so that stale results do not mislead me.
35. As an Administrator, I want unavailable data distinguished from zero funds or no outstanding issues, so that a failed request cannot falsely report an all-clear state.
36. As an Administrator using a keyboard or mobile screen, I want accessible tabs, dialogs and readable tables, so that all existing review tasks remain usable.

## Implementation Decisions

- Extend the existing Administrator GCash overview, its client controller and existing overview API through GCashTransactionService. Reuse the established session, balance, movement, review and export behavior; no new reporting service, framework, dependency or schema change is required by this specification.
- Preserve four sections: Balances & Summary, Transactions & Filters, Reconciliation Log, and Unresolved Activity. Retain existing RetailMind styling and responsive structure rather than replacing the visual design.
- Represent missing balance evidence distinctly from numeric zero. Open sessions expose expected balances; closed sessions expose last counted amounts and closing time. Preserve the responsible Cashier and actual-check metadata, and do not imply an open session when showing the latest closed one.
- Keep balances independent of every activity/list filter. A combined total may remain only as a secondary, accurately labelled amount; it is not a balance-sufficiency indicator.
- Apply shared start/end dates and optional Cashier selection to completed activity and transaction history. Default to Today using PhilippineTime in both initial-page and API behavior; Month-to-date runs from the first Philippine calendar day of the current month through Today. Preserve the established reporting timestamp basis and date validation.
- Display the selected reporting period beside summary controls. Transaction search and type selections affect list rows, matching-record count, pagination and CSV only. Changing shared filters updates both activity and list results without applying local list filters to headline activity.
- Return distinct completed-exchange and matching-record counts. The completed count and principal/fee totals cover Completed service exchanges in the shared reporting scope; reference-only legacy records must not invent completed exchange amounts. Matching records retain existing supported statuses and legacy history under list filters.
- Reuse existing authoritative principal and fee calculations. Report Cash-In principal, Cash-Out principal, gross cash fees and gross wallet fees separately. Do not present principal as earned revenue or fees as net profit.
- Query unresolved exchanges and reconciliation history independently of dates, Cashier selection, search and transaction type. Outstanding discrepancy counts include only unwaived nonzero closing variances; reconciliation history continues to include waived records. Each landing alert opens the corresponding all-date, Store-wide section.
- Preserve service compatibility for other GCash callers when separating query scopes and counts. Update affected initial rendering, API responses, asynchronous consumers, pagination, details and export together. Keep responses from obsolete requests from overwriting newer selections.
- After successful verification or waiver, refresh affected outstanding counts, review records, balances and selected-period totals using their respective scopes. Do not conflate a waiver with removal or correction of the counted variance.
- Preserve reference validation, both wallet/cash confirmations, CSRF protection, authorization, atomic movement posting and audit behavior. Verify & Clear completes the exchange and posts only its remaining movements. Retain the existing endpoint/workspace authority; this work grants no broader operational rights to the Super Administrator or other roles.
- Exchange recording remains in the Cashier workspace. Preserve the existing eligible-account workspace switch and open Cashier Shift/session-control requirements; do not add direct recording to this page.
- Preserve legacy labels, linked adjustments, attribution, complete filtered CSV, formula-injection protections and failure-safe export behavior. Exports follow the list's shared and local filters across every matching page.
- Retain keyboard tab navigation, accessible labels, native confirmation dialogs, bounded tables, mobile behavior and error feedback. Missing data, loading failures and invalid filters must not silently become zero balances or an all-clear statement; errors must not present fallback data as matching the rejected selection.

## Testing Decisions

- The user confirmed reuse of existing tests on 2026-10-23. Use the existing Administrator GCash browser/HTTP suite as the primary feature boundary, with the existing GCash MySQL service integration coverage for exact totals and ledger invariants. Do not introduce a new test framework or production test interface.
- Test observable rendering, returned data, downloaded CSV, authorization outcomes and persisted movements/audit records. Avoid assertions about internal helper structure or incidental CSS.
- Exercise open, closed, never-counted and genuine-zero balance states; verify labels, timestamps and Cashier attribution. Change every reporting/list filter and verify that balance amounts remain unchanged.
- Cover Today, Month-to-date and custom dates, including Philippine day/month boundaries. Verify shared Cashier filtering, explicit period labels, distinct completed/list counts, and exact principal and fee totals.
- Use mixed-status and legacy records with multiple Cashiers and dates. Search/type changes must narrow the list and export without narrowing headline totals. Date/Cashier changes must update summary/list scope while leaving Store-wide outstanding alerts and review records intact.
- Include unresolved exchanges and waived/unwaived nonzero discrepancies outside the reporting period and assigned to another Cashier. Verify landing counts, navigation, preserved waived history and separate signed variances.
- Verify complete filtered CSV across multiple pages, matching-record counts and stable pagination, including local search/type selections and existing safe export handling.
- Verify that successful Verify & Clear and waiver refresh affected displays, preserve audit attribution, and never duplicate posted movements or remove counted variances. Reuse existing negative permission, CSRF, invalid-reference and confirmation coverage.
- Exercise delayed competing requests and failed loads: newer selections must win, and failures must not show fabricated zero balances or an all-clear result. Retain keyboard tab/dialog and desktop/mobile regression checks.
- Reuse disposable database fixtures for integration/browser tests. No production data, real GCash transfers, hardware calibration or live wallet access is needed to verify this overview feature.

## Out of Scope

- Live GCash synchronization or initiating transfers from RetailMind.
- Direct exchange recording in the Administrator workspace, new permissions, funding/replenishment workflows, emergency handover changes, or new resolution actions.
- Low-fund thresholds, alerts based on invented thresholds, charts, forecasts, multi-Store reporting or a replacement visual design.
- Provider expense accounting, net-profit reporting, fee schedule changes, principal rules or altered exchange posting semantics.
- Deleting or rewriting transaction, adjustment, audit or reconciliation history.
- Broad cleanup of older GCash documentation unrelated to this overview specification.
- Application implementation, deployment or live operations during this specification task.

## Further Notes

Published as [GitHub issue #276](https://github.com/ZeachDev01/retailmind/issues/276), labelled `ready-for-agent`.

The user confirmed the complete design on 2026-10-23 and subsequently confirmed the proposed existing test boundaries. This specification refines Administrator overview behavior within the previously agreed GCash Service rules; it does not replace the wider service specification or authorize application implementation in this session.

Existing issues [#236](https://github.com/ZeachDev01/retailmind/issues/236) and [#237](https://github.com/ZeachDev01/retailmind/issues/237) provide earlier service requirements and operational policies. Older reference-capture documentation contains historical behavior and must not override the confirmed overview design or current movement-preserving service behavior.
