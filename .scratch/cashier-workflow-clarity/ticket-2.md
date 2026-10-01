Source: approved product direction, Cashier workflow clarity and reliable receipts (2026-10-01). The nine-ticket breakdown was approved for publication. Preserve ADR-0001's Administrator/Emergency Access boundaries and ADR-0006's transaction-time receipt details. Ticket 1 investigates prerequisites; application implementation was not started during specification.

## What to build

A Cashier completes each checkout attempt once and can recover its saved Sale Receipt after a lost response. Ancillary notification failures never turn a committed sale into a reported checkout failure.

## Acceptance criteria

- [ ] Sale, lines, inventory/batch depletion, saved receipt details and any Held Sale completion commit together or roll back together.
- [ ] Double clicks and concurrent retries of the same attempt return the same saved sale without a second inventory deduction or Held Sale completion. A genuinely new sale receives a new attempt identity.
- [ ] Scope attempt access to the authenticated Cashier; reject incompatible reuse rather than treating different carts/payments as the same successful attempt.
- [ ] After a lost response, recover the committed outcome before requesting another payment. A reload or navigation does not silently replace an unresolved attempt with a new one.
- [ ] Notification exceptions after commit are logged/reported separately; the Cashier receives success and the saved receipt. True pre-commit failure leaves no partial sale or inventory work.
- [ ] Add observable regression coverage for concurrency, double submission, lost-response recovery, atomic failure and injected post-commit notification failure.

## Blocked by

None (can start immediately).
