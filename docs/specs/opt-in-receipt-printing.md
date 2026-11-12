# Manual receipt printing

Updated 2026-11-12: automatic receipt printing is removed. This restores manual printing in
[ADR-0006](../adr/0006-preserve-customer-receipt-details.md),
[thermal receipts](thermal-customer-receipts.md), and
[refund completion](show-receipt-after-refund.md).

- Sale, refund, and GCash confirmations offer no automatic-print option.
- Successful completion opens the saved receipt. Only clicking its Print button
  starts printing through the existing receipt print function.
- Refresh, receipt history, and closing/reopening the receipt do not automatically
  print. Manual Print remains available for retries, cancellation, or paper problems.
- Printing uses the current Register's 58/80 mm width. Printing failures never undo
  payment or refund; operators must reprint the saved receipt rather than repeat it.
- On a cashier PC configured for silent browser printing, clicking Print sends the receipt
  to the default printer without confirmation. Otherwise the browser dialog opens.
  The website does not select or discover a printer or confirm physical output.

Setup and rollback: [Xprinter printing](../guides/xprinter-xp-58iid-setup.md).

## Verification, 2026-11-12

- Passed: `npm run check`, changed PHP/JavaScript syntax checks, cashier checkout
  browser test, shared receipt dialog browser test, and 58/80 mm refund receipt
  browser checks. These simulate `window.print()`; they do not prove paper output.
- Full GCash suites stop before receipt tests on existing label expectations:
  `0 open` versus `All clear`, and `Not recorded` versus `—`.
- Refund completion integration is blocked by its fixture expecting a `branches`
  table absent from the current schema. Refund reprint's contract also fails its
  existing direct-GET assertions. Those unrelated fixtures were not changed.
- The user confirmed successful silent printing through Print Receipt in the
  dedicated RetailMind POS shortcut on 2026-11-12; see the setup guide.
