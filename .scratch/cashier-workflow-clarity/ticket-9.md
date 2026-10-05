Source: approved product direction, Cashier workflow clarity and reliable receipts (2026-10-01). The nine-ticket breakdown was approved for publication. Preserve ADR-0001's Administrator/Emergency Access boundaries and ADR-0006's transaction-time receipt details. Ticket 1 investigates prerequisites; application implementation was not started during specification.

## What to build

Give checkout and historical receipt viewing one coherent, accessible Sale Receipt modal; clarify receipt versus operational history, and apply verified Philippine-time formatting throughout the Cashier workflow. Build on the existing thermal receipt behavior described in #101.

## Acceptance criteria

- [ ] Successful checkout opens one centered labelled Sale Receipt modal over Receipt History with Payment completed, saved receipt number, applicable change due, thermal preview, Print Receipt and New Sale. Remove the second inline preview and automatic locked-receipts popup.
- [ ] Closing returns to Receipt History; New Sale opens a fresh POS cart. Historical View uses the same saved-data modal without a new-payment success announcement.
- [ ] Focus enters and stays in the modal; Escape/visible close dismiss it and restore focus appropriately. Narrow screens and long receipts keep paper/actions reachable without horizontal clipping.
- [ ] Keep printing manual through the browser dialog. Opening, closing, refreshing, reprinting or canceling print never creates or undoes a financial transaction.
- [ ] Preserve 58/80 mm widths, long-value wrapping, nonprinting application controls, immutable transaction-time details and the older unsnapshotted-receipt notice required by ADR-0006.
- [ ] Receipt History presents authorized customer lookup/reprint, separate original paid/refunded/remaining-net amounts, and distinct Cash Refund versus Legacy Reversal statuses/filters. New corrections route to the authorized Refund workflow, with contextual explanation.
- [ ] My History remains individually scoped Cashier Operational History for sales, refunds, drawer movements and shifts; Stock Issues retain separate report history. Server-side receipt/refund access remains enforced, including locked viewing and narrowly approved exceptions.
- [ ] Refund Receipt stays a separate saved document identifying the original sale and externally recorded settlement; apply consistent manual printing and date/time formatting without altering the Sale Receipt.
- [ ] Using ticket 1's verified storage convention, show Oct 1, 2026 · 8:39 AM and clear Philippine time across customer receipts and relevant Cashier screens. Format the stored instant without seconds or blind offset shifts; sort/filter underlying timestamps with Asia/Manila calendar-day boundaries.
- [ ] Verify checkout/history/refund navigation, no false success messaging, keyboard focus, mobile/long receipts, both browser print widths, canceled print, historical snapshot stability, authorization and date-boundary ordering.

## Blocked by

- #107: Verify timestamps and plan the Legacy Reversal transition
- #108: Make checkout outcomes and retries reliable
- #113: Complete the safe full/partial Cash Refund workflow
- #114: Retire new Sales Reversals safely
