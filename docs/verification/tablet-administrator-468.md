# Administrator landscape verification (#468)

Date: 2026-11-17. Base: integration `7f56299b` (shared #466 navigation and #467 overlays included).

## Result

Code-ready supplemental verification; **not final acceptance**. No page-specific production changes were needed after shared fixes. Existing browser seams were reused; no new dependency, API, schema, business behavior, or permission changes.

All browser runs below used independent PHP servers, randomly named disposable MySQL databases (`retailmind_theme_test_*`), and isolated recovery storage. Fixtures cleaned their databases after each run. Chromium synthesized touch and viewport sizing are not physical-device evidence.

## Passed checks

- `RUN_DB_TESTS=1 node src/backend/tests/administrator_small_screen_browser_test.js`: 21 existing Administrator routes at **640×360, 800×480, 960×600, 1024×768, 1280×800, 1366×768**, plus existing 320×740, 390×844, 844×390 checks. Actual Playwright viewport set for each visit; document overflow and control geometry checked. Store Overview, GCash, notifications, reports, authorized management pages, Stock Issue Oversight and other assigned pages returned 200 without JavaScript errors. Existing search/menu touch, heading clearance and desktop checks passed. Screenshots: `C:\Users\Mike\AppData\Local\Temp\rm-admin-mobile-Kzy6KD`.
- `RUN_DB_TESTS=1 node src/backend/tests/administrator_mobile_monitoring_browser_test.js`: same matrix, summary drill-down, GCash Transactions, gross-profit summary, notifications opening referenced Stock Issue, report details, and read-only oversight. Oversight POST remained 403. Existing Cashier, Inventory Manager and Super Administrator active-Workspace boundary assertions passed. Screenshots: `C:\Users\Mike\AppData\Local\Temp\rm-admin-mobile-O0bjd1`; 640-pixel overview capture inspected.
- `RUN_DB_TESTS=1 node src/backend/tests/administrator_management_browser_test.js`: successful account creation at 640×360; account updates cycled through all six matrix sizes after entering values. Persisted name, password reset, status, roles and own optional-role changes passed existing browser/HTTP checks; prohibited requests preserved state. This is representative write coverage across the matrix, not every management action at every size.
- `npm run check:contracts`: passed. Styles and view checks inside `npm run check` passed (one existing Cashier duplicate-ID warning).
- `git diff --check`: passed.

Existing test expectations were corrected to current public behavior: only visible navigation links are candidates for direct scrolling (expanded destination traversal remains #466 coverage); Stock Issue detail heading is `Report #1` and link name is `Open report #1`; read-only report checks exclude shared onboarding POST forms. Desktop headers do not use the compact mobile-header height assertion.

## Gaps and existing failures

- Primary TestSprite viewport pilot cannot configure/prove the requested matrix. These supplemental results do **not** establish TestSprite acceptance. Parent #465 owns TestSprite run receipts, artifacts, default-workflow verdicts and reruns.
- Physical Store tablet, software keyboard, final trusted HTTPS origin and network acceptance remain #471/#472 work. No Store records or live network configuration were changed.
- Existing fixtures cover populated sales/Stock Issues and empty GCash lists here. Long-content, validation, connection errors, table pagination and all authorized action variants were not exhaustively exercised at every matrix cell; parent TestSprite/shared-overlay evidence must retain those gaps where unproved.
- `npm run check` stops at the unrelated existing Cashier smoke needle: `Cashier Shift opening is owned by the Cashier workspace: missing $service->availableRegisters()`. View audit also warns duplicate `open-register` in `cashier/shifts.php`.
- Existing `node src/backend/tests/shift_report_layout_browser_test.js` fails its fixture expectation `Shared pagination still applies` (`28 !== 25`): production shift table has `data-no-smart-table`, whereas this presentation fixture expects shared smart pagination. No unrelated repair included.

Issue #468 remains open for external acceptance.
