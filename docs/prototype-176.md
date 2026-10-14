# Issue #176 UI prototype

Question: which layout makes bulk shopping-trip receiving clearest in the Inventory Manager workspace?

Run from this worktree: `npm run prototype:176` (Node and PHP required; no npm install or database needed).
Open http://127.0.0.1:8176/components/report/stock_receiving.php?variant=A

- A: receiving worksheet; quantity table and trip summary.
- B: guided selection, quantities, and review steps.
- C: stock/recommendations beside the actual bought-goods entry.

Use the floating arrows or keyboard left/right. Draft and saved sample stock survive variant switches; reload or Reset demo starts over. Example: add Sardines, select cases, enter 2, review, save: 8 + 48 = 56.

The authenticated existing route also renders the variants when RECEIVING_PROTOTYPE=1 is explicitly set. Its authentication and existing reads are preserved. The standalone runner uses an illustrative shell and sample fixtures, serves only prototype assets, and never dispatches application handlers.

All quantities, costs, optional receipt details, history, and recommendation updates live in memory. Case sizes come from sample product configuration. Barcode lookup is simulated; there is no camera integration. Planning targets are illustrative, not the production recommendation formulas. Database transactions, concurrency, authorization enforcement, fiscal/write gates, and real forecast refresh are not validated by this UI exercise.

Browser walkthrough verified case conversion (8 to 56), fractional rejection with retained input, variant switching, and desktop/mobile rendering. No automated test suite added for throwaway code.

Verdict: awaiting user evaluation. No layout selected, no production implementation promoted. Preserve all variants on this throwaway branch as the primary source.
