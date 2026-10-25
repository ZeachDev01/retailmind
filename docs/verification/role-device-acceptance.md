# Role device acceptance: #314

Implementation verification and physical acceptance are separate. This record does not certify hardware compatibility or authorize production deployment.

## Automated evidence

The #317 monitoring checks use Chromium with synthesized touch input and responsive viewports, not physical phones or Safari:

- `RUN_DB_TESTS=1 node src/backend/tests/administrator_mobile_monitoring_browser_test.js`: real authenticated HTTP routes against a disposable MySQL database; 320/390px portrait and 844px landscape. Checks Store Overview, GCash Overview/Transactions, notifications, Gross Profit summaries, Stock Issue detail inspection, drawer controls, and active Workspace authorization. Existing Super Administrator access to GCash monitoring is retained; Store Overview and Stock Issue Oversight remain Administrator-only. Screenshot paths are printed by the test.
- `node src/backend/tests/administrator_dashboard_browser_test.js`: production dashboard presentation, period controls, report links, summary drill-down destinations, chart-unavailable fallback, desktop and 390px layouts.
- `RUN_DB_TESTS=1 node src/backend/tests/gcash_admin_overview_browser_test.js`: existing GCash monitoring browser coverage.
- `node src/backend/tests/notification_responsive_browser_test.js`: notification layouts and actions from 320 through 1440px.

#315 and #316 add their focused receipt delivery, scanner, draft recovery, and operation retry tests. Their results must be recorded with the integration verification report; this document makes no independent claim that those tests passed.

## Physical acceptance matrix

Every row is **pending: hardware unavailable to this implementation session**. Record actual device model, OS/browser versions, input hardware, connection, tester, date, and result when performed. Never infer these results from an emulated viewport.

| Device/browser | Required checks | Status |
| --- | --- | --- |
| Physical iPad / Safari | Cashier and Inventory workflows in portrait/landscape; rotation retains cart/forms/dialogs; software keyboard leaves focused fields and actions reachable | Pending |
| Physical iPad / Chrome | Same workflow and keyboard checks; record iPadOS and Chrome versions separately | Pending |
| Physical Android tablet / Chrome | Same workflow and keyboard checks; record Android and Chrome versions | Pending |
| Physical phone / actual browser | Administrator Store/GCash summaries, notifications and report drill-downs, navigation, read-only Stock Issue detail | Pending |
| USB wireless scanner receiver + tablet adapter | Record scanner/receiver/adapter/tablet models; consecutive Enter-terminated scans; explicit barcode entry; unrelated fields unchanged; unknown code creates nothing | Pending |
| Bluetooth keyboard-style scanner | Record scanner/tablet pairing; same consecutive scan and field-context checks on each compatible tablet | Pending |
| Each target tablet browser -> Store PC -> XP-58IID | LAN address/hostname route, saved Sale/Refund Receipt preview and Print to Store Printer, no tablet print-selection dialog, actual thermal output, configured paper width, long names/totals/clipping | Pending |
| PC keyboard/scanner and existing receipt printing | Counter checkout regression, consecutive scans, PC print route remains usable | Pending |
| Physical tablet restart | Enter every in-scope inventory value, restart, sign in as same user, Resume/Discard; another account cannot inspect drafts; no automatic stock posting | Pending |

## Failure and recovery checks for acceptance

- Durable encrypted browser drafts require a secure browser context (normally HTTPS for the Store LAN address). Plain HTTP on a tablet LAN URL cannot establish WebCrypto availability. Confirm the explicit preservation-failure message when durable storage is unavailable; never treat it as successful preservation.
- For each inventory entry workflow, interrupt before sending and separately lose the response after server commit. Resume/retry explicitly and verify exactly one committed operation and stock effect. Retain values on validation errors and revalidate current permissions/Product state.
- Stop the PC print worker or disconnect the printer. Confirm completed transactions remain saved. Distinguish accepted jobs from physical output. For uncertain delivery, inspect existing paper before explicitly choosing a reprint; repeated taps must never repeat checkout/refund or stock changes.
- Browser-data deletion/eviction and device loss are not recovery guarantees. Network/firewall changes, live installation, printer setup and physical certification remain outside this implementation.

## Existing unrelated test failures observed

- `stock_issue_oversight_contract.php`: its fixture has no open Fiscal Period covering 2026-10-25.
- `gross_profit_report_browser_test.js`: existing exclusion-note text assertion fails (`Exclusion note text must explain operating expenses`). Report markup was not changed for #317.
