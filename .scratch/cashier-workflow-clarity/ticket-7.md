Source: approved product direction, Cashier workflow clarity and reliable receipts (2026-10-01). The nine-ticket breakdown was approved for publication. Preserve ADR-0001's Administrator/Emergency Access boundaries and ADR-0006's transaction-time receipt details. Ticket 1 investigates prerequisites; application implementation was not started during specification.

## What to build

Record one append-only full or partial Cash Refund against an eligible sale, with safe settlement, returned-item treatment and a separate Refund Receipt. Permit a narrowly Administrator-authorized exception when the original Cashier is absent or disabled.

## Acceptance criteria

- [ ] Routine own-sale refunds use predefined reasons and an Other note, server-derived amounts, remaining line quantities and actual paid-after-discount caps. Enforce caps under concurrent attempts and preserve the original sale.
- [ ] Record the refund today when today's refund and applicable stock-movement periods are open, even if the original sale period is closed. A closed required current period refuses the whole operation without partial cash, inventory, receipt or audit records.
- [ ] Settle using the original payment method. Cash uses the issuing Cashier's current open/unlocked shift and ticket 6's cash sufficiency guard; card/e-wallet requires confirmation of externally completed refund and a Payment Reference with no gateway call or cash deduction.
- [ ] Classify every returned quantity as Restockable or Damaged. Only Restockable restores available inventory; both consume refundable quantity/value. Damaged customer returns must not cause a second Stock Issue deduction.
- [ ] An absent/disabled original Cashier exception requires Administrator approval bound to the specific refund. Retain original seller, issuing Cashier, approver, reason and issuing shift in immutable records.
- [ ] Expose only the sale needed for the authorized exception; preserve routine identity-scoped history and receipt access. Reject reused, mismatched or broad approvals.
- [ ] Commit refund, lines, stock effects, Protected Audit Record and saved Refund Receipt together. Receipt identifies the original sale and states externally recorded settlement without implying RetailMind transferred money.
- [ ] Preserve legacy-ledger exclusion rules pending ticket 8's documented transition. An exchange remains a refund plus a separate new sale; add no credit/rewards ledger.
- [ ] Verify discounted partial and concurrent refunds, closed historical/current periods, insufficient drawer cash, noncash references, damaged returns, absent/disabled seller exceptions and unauthorized sale access.

## Blocked by

- #107: Verify timestamps and plan the Legacy Reversal transition
- #109: Enforce Cashier workspace, shift ownership and Register Lock
- #112: Protect drawer payouts and authorize operational spending
