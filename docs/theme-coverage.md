# Personal display themes

Light, Dark, and System are personal account preferences. Apply the pending
database updates (or run `php src/backend/scripts/migrate.php`) to enable saving.
Existing and fresh accounts default to System. Staff login keeps its separate
browser-local choice; account changes never write that choice.

Every current HTML page uses the shared early theme head and control. Shared
styles retain their original light colors as variable fallbacks; dark surface,
text, border, semantic status, focus, and glass colors live in `theme.css`.
Changing appearance changes neither forms nor workflow state.

## Screen inventory

| Surface | Covered screens |
| --- | --- |
| Shared account and gates | Staff login, Profile, Preferences, workspace selection, Mandatory/Voluntary Password Change, password reset, Register Lock inside POS |
| Administrator | Store Operations dashboard, Store Settings, Registers, Shift Report, Stock Issue oversight, Database Backup, operational staff management, Fiscal Periods |
| Super Administrator | Platform Control Center, Platform Settings, Audit Logs, Database Backup/Restore (including unavailable-hosting screen), Database Updates, Emergency Access, Recovery Account, System Health, ML Settings, privileged account management |
| Inventory Manager | Inventory Overview/Insights, Products, CSV Import, barcode labels, Reorder Planner, Replenishment Requests, Stock Issues, Suppliers, Inventory Counts, Stock Receiving, Purchase Orders |
| Cashier | Dashboard, POS/product lookup, Cashier Shifts, Cash Refunds, Stock Issues, My History, Held Sales within POS |
| Reports and receipts | Sales/Receipt History, Sale Receipt previews/dialogs/reprints, Refund Receipt previews/reprints, Data Readiness, Forecast Analytics, Forecast dashboard, Forecast Exceptions, Predictions, Report Generation, notifications |

Receipt AJAX fragments inherit their host's current appearance. Canvas Forecast
Analytics labels redraw on theme changes and before/after printing. Other chart
surfaces use shared CSS colors.

The Administrator inventory includes Operational Audit alongside Stock Issue and
Cashier Shift oversight. Users & Access serves both role boundaries, with the
shared control inside its Add Store Staff dialog and Manage Account drawer.
Database Updates uses theme surfaces even though it has a standalone layout.

## Paper paths

The print-media rules enforce light backgrounds and dark text for Report
Generation, Purchase Orders, barcode labels, Sale Receipts, and Refund Receipts.
They hide appearance controls. Receipt print clones keep the existing 58 mm /
80 mm widths, transaction-time details, and user-initiated print behavior.

| Existing non-receipt paper path | Implementation and retained behavior |
| --- | --- |
| Report Generation (all sixteen catalog reports) | Same table/metrics renderer; Print and Export PDF call browser print. GET filters, CSV export and selected smart-table page stay unchanged. |
| Purchase Orders | Whole current order list through browser print; Store/supplier/product data and totals remain visible. Creation form and per-order actions stay on screen. |
| Printable Barcode Labels | Standalone sheet with its existing label dimensions, quantities and page margins. Manual Print stays manual; Generate Barcode and Print retains its explicit redirect/load print request. |
| Forecast/report browser print | Data Readiness, Forecast Exceptions, Demand Forecasts (including development dashboard variants) and Forecast Analytics use light paper. Active analytics tab stays selected; features, accuracy trend and actual/forecast canvases redraw labels for print and return to screen colors afterward. |

Shared paper rules hide desktop/mobile navigation, Search/dialog overlays, and
smart-table search/page controls, while retaining the selected rows and report
data. Paper tables wrap within their available width; label and report pagination
remain owned by the existing renderers. No screen palette inversion is used.

## Verification

`node src/backend/tests/theme_login_browser_test.js` starts its own application
server; covers local preference, System/device changes, mobile focus, and blocked
storage. It does not sign in or write Store data.

With local MySQL running, `$env:RUN_DB_TESTS='1'; node
src/backend/tests/theme_account_browser_test.js` creates/drops its own temporary
database from production DDL. It verifies fresh/upgrade parity, all four roles,
mobile, keyboard/focus/contrast, account isolation/persistence, protected writes,
failed saves, open dialogs, chart redraw, scan/form/cart preservation, workspace
switching, gates, and distinct report print paths. Screenshots go to the OS temp
directory (override with `THEME_BROWSER_OUTPUT`).

`THEME_REPORTS_ONLY=1` narrows that disposable account suite to report acceptance:
eleven routes (including all three Forecast dashboard variants), populated
sales/Purchase Order/forecast values, 66 inventory rows,
120 barcode labels and multipage browser PDFs. It tests Dark and dark-resolving
System paper, screen controls, filter/pagination state, all three canvas kinds,
mobile focus/access and unchanged inventory/transaction database snapshots.

Sale/Refund Receipt browser suites also exercise dark previews and light print
clones at both paper widths with short/long details. `run_all.sh` includes the
theme suites; account tests explicitly report a skip when `RUN_DB_TESTS` is unset.

Products menus, drawers and wizard panels use the shared palette. Shared inventory
overlays and dynamically created purchase confirmations contain synchronized
appearance controls. Promotions remains restricted to Administrator roles.

Cashier inventory includes Product Finder and all four My History tabs/details.
POS quote review and current-cart/Held Sale discard dialogs contain synchronized
appearance controls. Stock warning pills retain their semantic warning text in
Light and Dark. Appearance preserves payment drafts and unresolved Held Sales.
