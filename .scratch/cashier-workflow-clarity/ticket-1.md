Source: approved product direction, Cashier workflow clarity and reliable receipts (2026-10-01). The nine-ticket breakdown was approved for publication. Preserve ADR-0001's Administrator/Emergency Access boundaries and ADR-0006's transaction-time receipt details. Ticket 1 investigates prerequisites; application implementation was not started during specification.

## What to build

Establish the storage and accounting facts needed for safe Philippine-time display and an explicit Administrator-controlled transition of existing Sales Reversals. This is an investigation and specification ticket; do not change application behavior or financial records.

## Acceptance criteria

- [ ] Trace PHP, database and connection timezone conventions for sale, refund, drawer, shift and Stock Issue timestamps. Distinguish stored instants from Manila-local values using representative evidence; do not assume an eight-hour shift.
- [ ] Document formatting of actual stored transaction times as Oct 1, 2026 · 8:39 AM, Asia/Manila day-filter boundaries and chronological ordering without rewriting history.
- [ ] Inspect existing pending and approved Legacy Reversal records through read-only access. Document supported states, quantities, settlement methods, cash effects, inventory effects and closed-shift/report consequences; explicitly report an empty set or unavailable access.
- [ ] Specify each supported pending-request resolution with Administrator authority, preserved history/audit attribution and before/after cash, stock and refundable balances. Do not silently convert records or duplicate payouts.
- [ ] Define how pending/approved reversals and Cash Refunds remain mutually safe, including whether approved legacy records remain ineligible for new refunds. Capture unresolved record-specific cases before implementation.
- [ ] Provide reproducible verification scenarios and a supported transition plan for ticket 8. Preserve ADR-0001 and ADR-0006.

## Blocked by

None (can start immediately).
