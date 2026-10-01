Source: approved product direction, Cashier workflow clarity and reliable receipts (2026-10-01). The nine-ticket breakdown was approved for publication. Preserve ADR-0001's Administrator/Emergency Access boundaries and ADR-0006's transaction-time receipt details. Ticket 1 investigates prerequisites; application implementation was not started during specification.

## What to build

Authorize Cashier operations consistently by authenticated identity and active workspace, and serialize competing operations so a Register Lock or closed Cashier Shift cannot admit operational writes.

## Acceptance criteria

- [ ] An Administrator assigned the Cashier workspace can perform Cashier operations, including Stock Issue reporting, under their own attribution. Stored primary role is not a routine bypass or denial.
- [ ] Enforce appropriate own open, unlocked shift at every server entry point for sales, refunds, Held Sale changes, drawer movements and Stock Issue changes; direct requests cannot bypass UI guards.
- [ ] Maintain at most one open Cashier Shift per Cashier and Register, recording the selected Register and actual opening float.
- [ ] Serialize hold/resume/discard, checkout, lock and closure against shift state. Concurrent hold/close or hold/lock cannot create an unresolved Held Sale after closure or lock.
- [ ] Register Lock keeps ownership, blocks operational writes/cart changes, and permits authorized own history and receipt reprints. Only the owner unlocks using their account password.
- [ ] Logout leaves the shift open. Closure requires all Held Sales resolved and counted cash; preserve material-variance explanations and reasoned Administrator intervention.
- [ ] Verify service/HTTP behavior under races, forged identities/workspaces and lock state, including Stock Issue review and immutable terminal decisions.

## Blocked by

None (can start immediately).
