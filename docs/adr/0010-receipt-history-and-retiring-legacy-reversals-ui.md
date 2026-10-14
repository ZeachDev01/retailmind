# Establish Receipt History and retire Legacy Reversals UI in pre-production

RetailMind establishes Receipt History as a dedicated, immutable lookup and customer reprint workspace, renaming the navigation from "Sales" across Administrator, Inventory Manager, and Cashier roles to prevent confusion with aggregate Store Reports. The "New Sale" Point-of-Sale trigger is restricted strictly to Cashiers holding `OPERATE_POINT_OF_SALE`, enforcing separation of duties so Administrators use the workspace solely for store-wide audits, warranty/dispute verifications, and thermal reprinting.

During pre-production development, the Legacy Reversals interface (secondary tab, filter dropdown, and table status badges) is retired from this workspace. Because the system is in active development with no historical customer production data, carrying migration bridges for pre-cutover reversals added visual clutter and cognitive overhead without operational necessity; all retail corrections exclusively use the Cash Refund workflow.

## Consequences

- Navigation and page headings use canonical "Receipt History" rather than the overloaded term "Sales".
- Administrators inspect, filter by cashier, and reprint receipts store-wide, but cannot initiate POS checkouts from this view.
- The Receipt History workspace is simplified to a single-purpose table with Cash Refund tracking, eliminating unused legacy reversal tabs, filters, and status columns.
- Receipt verification URLs (such as QR codes on printed slips) and Dashboard exception links remain fully compatible with the Receipt History route.

