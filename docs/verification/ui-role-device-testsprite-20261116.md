# UI release checks by Workspace and device

Approved scope: browser UI checks across POS, Shifts, Products/Receiving, GCash,
Refunds/History, and shared navigation/dialogs. Cashier and Inventory Manager
use desktop and tablet; Administrator uses a mobile phone viewport.

| Workspace | Required browser viewport |
| --- | --- |
| Cashier | 1366×768; 768×1024; 1024×768 |
| Inventory Manager | 1366×768; 768×1024; 1024×768 |
| Administrator | 390×844 |

## Checks

- POS: search/scan, quantity edits, cart reload, visible totals, invalid payment,
  successful checkout, receipt and repeat-submit behavior.
- Shifts: tabs, retained drafts, movement validation, summaries and close form.
- Products/Receiving: create/edit, validation, search, pagination, received stock
  and receiving history.
- GCash: amount/reference validation, exchange confirmation, receipt and history.
- Refunds/History: sale lookup, quantity validation, refund confirmation, receipt
  and date/category filter persistence.
- Shared UI: login/logout, Workspace navigation, modal/drawer open/close, focus,
  empty results, no clipped actions or unintended page horizontal overflow.
- Administrator mobile: Store Overview, GCash Overview, notifications, Stock
  Issue details, report filters and detail navigation.

An allowed horizontal table scroller is not a page-overflow defect. Receipt
History is intentionally store-wide; Cashier Transaction History is personal.

## Execution rules

Use an independent application copy and a freshly created disposable MySQL
database. No live sales, inventory, wallet transfers, email, reset or restore.
Existing acceptance jobs are independent and must not be interrupted.

Require evidence of the actual browser viewport before counting a device row
as tested. If TestSprite cannot configure it, record that limitation and use
local Playwright for separately labelled viewport evidence. Do not equate
browser emulation with physical tablet, scanner, printer or Safari acceptance.

TestSprite project: `abe16d0b-21f0-432e-8a86-72b1436c42fe`.
Results are pending. Existing tests and previous acceptance runs are not results
for this device matrix.
