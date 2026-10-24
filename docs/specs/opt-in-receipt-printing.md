# Opt-in receipt printing

Confirmed 2026-10-24. This updates the manual-only printing requirement in
[ADR-0006](../adr/0006-preserve-customer-receipt-details.md),
[thermal receipts](thermal-customer-receipts.md), and
[refund completion](show-receipt-after-refund.md).

- Sale payment review offers **Print receipt automatically** below Change due.
  The same option is available for non-cash payments.
- Record refund confirmation offers the same checkbox below its explanation.
- Every new review starts unchecked. The choice applies only to that transaction.
- Successful completion opens the saved receipt and, if selected, starts printing
  once through the existing receipt print function. Failed transactions never print.
- Refresh, receipt history, and closing/reopening the receipt do not automatically
  print. Manual Print remains available for retries, cancellation, or paper problems.
- Printing uses the current Register's 58/80 mm width. Printing failures never undo
  payment or refund; operators must reprint the saved receipt rather than repeat it.
- On a cashier PC configured for silent browser printing, this sends the receipt
  to the default printer without confirmation. Otherwise the browser dialog opens.
  The website does not select or discover a printer or confirm physical output.

Setup and rollback: [Xprinter printing](../xprinter-xp-58iid-setup.md).
