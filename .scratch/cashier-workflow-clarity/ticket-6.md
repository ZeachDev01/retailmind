Source: approved product direction, Cashier workflow clarity and reliable receipts (2026-10-01). The nine-ticket breakdown was approved for publication. Preserve ADR-0001's Administrator/Emergency Access boundaries and ADR-0006's transaction-time receipt details. Ticket 1 investigates prerequisites; application implementation was not started during specification.

## What to build

Cashier drawer movements remain attributable and explained. Outgoing cash cannot exceed the current expected drawer balance, and Store spending receives Administrator authorization.

## Acceptance criteria

- [ ] Keep reasoned float additions, cash removals and safe drops with Cashier/shift attribution and Other explanations.
- [ ] Under the same serialized shift state as competing cash writes, refuse cash refunds, withdrawals and safe drops exceeding expected cash. Expose the guard for the refund workflow without bypassable client calculations.
- [ ] Record a real top-up before an otherwise insufficient payout; communicate that expected cash is a ledger estimate and physical funds must still be checked.
- [ ] Require Administrator authorization bound to supplier payments and petty-cash spending. Preserve Emergency Access boundaries for the Super Administrator.
- [ ] Labels and guidance send customer refunds to Cash Refunds; never create a second drawer movement for a refund.
- [ ] Reconcile opening float + cash sales + additions − removals − safe drops − applicable cash refunds, preserving existing legacy accounting until its approved transition and avoiding duplicate deductions.
- [ ] Verify concurrent outgoing cash operations, insufficient funds, recorded top-up, unauthorized spending, required reasons and noncash exclusions.

## Blocked by

- #109: Enforce Cashier workspace, shift ownership and Register Lock
