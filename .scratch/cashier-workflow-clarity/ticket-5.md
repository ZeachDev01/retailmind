Source: approved product direction, Cashier workflow clarity and reliable receipts (2026-10-01). The nine-ticket breakdown was approved for publication. Preserve ADR-0001's Administrator/Emergency Access boundaries and ADR-0006's transaction-time receipt details. Ticket 1 investigates prerequisites; application implementation was not started during specification.

## What to build

A Cashier can recover their own unfinished cart and explicitly preserve or discard work when leaving. Held Sales never promise stock or price and never silently alter requested lines.

## Acceptance criteria

- [ ] Recover an ordinary cart after reload only for the same Cashier and same open shift. A different identity or shift starts empty; workspace changes do not leak carts.
- [ ] Before logout or workspace switching with unfinished work, offer an explicit hold/discard decision. Preserve Held Sale owner and originating-shift rules, and require the structured reason for discard.
- [ ] Revalidate products, stock and prices on hold/resume and before checkout, without reserving stock or prices.
- [ ] Show removed/unavailable products and changed quantities/prices and require explicit Cashier review rather than silently dropping or clipping lines. Declining preserves a clear unresolved-work state.
- [ ] Resuming a Held Sale leaves it unresolved until checkout or reasoned discard; unresolved Held Sales block closure.
- [ ] Disable cart changes while locked and enforce Held Sale changes server-side using ticket 3's serialization. Verify identity/shift changes, reload, changed stock and lock races.

## Blocked by

- #109: Enforce Cashier workspace, shift ownership and Register Lock
- #110: Require reviewed prices and correct discount approval
