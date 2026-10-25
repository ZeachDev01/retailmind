# RetailMind Resources

## Knowledge
- [Product purpose](../PRODUCT.md): first-party description of the single Store and operational roles.
- [Core domain definitions](../CONTEXT.md) and [Store operations vocabulary](../../GLOSSARY.md): retained definitions for shifts, receipts, refunds, Stock Issues, forecasting, GCash, and role boundaries; consult both for overlapping concepts.
- [Checkout implementation](../../src/backend/app/Services/SalesWorkflowService.php): transaction validation, sale records, inventory deductions, and stock movements.
- [Receiving implementation](../../src/backend/app/Services/ReceivingService.php): approved purchasing references, accepted quantities, batches, and inventory increases.
- [Cashier Shift implementation](../../src/backend/app/Services/CashierShiftService.php): Register ownership, expected cash, reconciliation, and unresolved Held Sales.
- [Stock Issue implementation](../../src/backend/app/Services/StockIssueService.php): pending reports and Inventory Manager decisions.
- [Demo forecasting guide](../guides/DEMO_FORECASTING.md): separates Demo evidence from Store evidence; explains manual operations and forecasting activation limits.

## Wisdom (Communities)
- The client Store owner and staff: have them describe and try their actual receiving, selling, stock checking, and closing routines. Their feedback tests workflow fit; this is not yet collected evidence.

## Gaps
- No live deployment, client data, scanner, printer, or payment flow verified in this teaching session.
- User understanding is not yet assessed. Do not record lesson coverage as demonstrated learning.
