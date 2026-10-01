Source: approved product direction, Cashier workflow clarity and reliable receipts (2026-10-01). The nine-ticket breakdown was approved for publication. Preserve ADR-0001's Administrator/Emergency Access boundaries and ADR-0006's transaction-time receipt details. Ticket 1 investigates prerequisites; application implementation was not started during specification.

## What to build

Route all new post-payment corrections through Cash Refunds while preserving Legacy Reversal records and resolving existing pending requests through the documented Administrator-controlled transition.

## Acceptance criteria

- [ ] Implement ticket 1's supported pending-record resolutions with explicit Administrator action, reasons, audit evidence and preserved historical records; no silent conversion.
- [ ] Disable creation of new legacy requests at all reachable UI and server entry points, including direct requests outside Cashier navigation.
- [ ] Maintain readable records clearly labelled Legacy Reversal. Do not rewrite original sales, historical Fiscal Periods or saved historical receipts.
- [ ] Prevent both ledgers from consuming the same quantity/value or issuing duplicate payouts. Validate cash, inventory and refundable balances for each supported resolution.
- [ ] Preserve intentional historical shift/report behavior or document and verify the approved transition effects; never apply duplicate legacy deductions.
- [ ] Handle unsupported/inconsistent historical records explicitly without destructive cleanup. Verify pending/approved/rejected cases and races with new refund attempts.
- [ ] New corrections use full/partial refunds; exchanges use a refund plus a new sale. Before-payment removals/discards retain their required reason rules.

## Blocked by

- #107: Verify timestamps and plan the Legacy Reversal transition
- #113: Complete the safe full/partial Cash Refund workflow
