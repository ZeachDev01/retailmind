Source: approved product direction, Cashier workflow clarity and reliable receipts (2026-10-01). The nine-ticket breakdown was approved for publication. Preserve ADR-0001's Administrator/Emergency Access boundaries and ADR-0006's transaction-time receipt details. Ticket 1 investigates prerequisites; application implementation was not started during specification.

## What to build

Before collecting payment, the Cashier reviews a server-calculated final quote. Checkout saves only the reviewed inputs and amount, using the best single eligible discount and the agreed payment-recording rules.

## Acceptance criteria

- [ ] Display a final server quote for current products, quantities, prices, eligible promotions, selected discount, total and payment/change before payment collection.
- [ ] Revalidate at checkout. Changed stock, price, promotion or relevant approval inputs invalidate the quote and require explicit review; never silently record an unconfirmed amount.
- [ ] Use one best eligible discount without stacking. Only an applied manual discount above 10% requires approval; a manual discount displaced by a better promotion does not.
- [ ] Routine above-threshold approval belongs to the Administrator and is bound to the applicable sale/quote. Super Administrator authority requires the existing Emergency Access boundary.
- [ ] Support cash, card and e-wallet recording; cash received covers the confirmed total and change derives from it. Noncash payment is externally completed/verified and requires a Payment Reference; make no gateway call.
- [ ] Preserve retry semantics from ticket 2 when a quote changes or checkout response is lost.
- [ ] Verify price/promotion/stock changes between review and save, threshold boundaries, displaced manual discounts, approval misuse, payment references and cash underpayment.

## Blocked by

- #108: Make checkout outcomes and retries reliable
