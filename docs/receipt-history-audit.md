# Receipt History audit — 14 October 2026

Target: `src/frontend/components/invoice/sales.php?tab=transactions`.
Sequence applied: Impeccable harden → adapt → polish → audit.

## Assessed score: 20/20

This score covers the implementation and the production-rendered PHP surface tested in Chromium. It is an audit assessment, not a WCAG certification or a claim that every browser and physical device was tested.

| Dimension | Score | Evidence |
| --- | --- | --- |
| Accessibility | 4/4 | Full-page axe-core WCAG A/AA checks pass in light/dark at 320, 768, 1024, and 1440px. Solid focus outlines, labeled dates/table/scroll region, accessible validation, native dialog focus containment and return. |
| Performance | 4/4 | Existing DataTables 3.0.4 assets served locally; server pagination limits rows to 10–100. Changes introduce no animation, image, framework, or runtime dependency. |
| Theming | 4/4 | Filters, status, actions, search, and pagination follow existing theme tokens. Both themes pass automated contrast checks. |
| Responsive design | 4/4 | Core targets ≥44px; filters reflow; dates and money stay legible in a scrollable table. Chromium synthesized touch scroll passes. Administrator filters remain reachable at all four widths and with 200% text size. |
| Implementation integrity | 4/4 | Selected receipts render without obsolete tab state. Invalid ranges return HTTP 422 and preserve prior client results. Asset failure, API failure, retry, empty results, and blocked storage regressions pass. |

## Changes

- Removed the undefined `$activeTab` condition from the shared receipt dialog.
- Kept the current DataTables version, with local assets and upstream license.
- Added a recoverable library-load failure instead of a silent empty table.
- Made filter-state storage optional and scoped it to the signed-in user.
- Rejected reversed dates in the service; marked invalid fields and retained the last valid results in the client.
- Prevented failed responses from becoming misleading empty-history messages.
- Strengthened focus and input boundaries; increased touch targets and input type size.
- Used existing theme colors for View, aligned monetary columns, and restored the documented empty-state radius/type scale.
- Guarded shared navigation and cart initialization storage access discovered by the blocked-storage regression. Cart save/clear errors still propagate; this change does not pretend unsaved work was persisted.

## Verification

- `receipt_history_browser_test.js`: production PHP response from a disposable database; library failure, API failure/retry, empty state, blocked storage, dates, currency formatting, keyboard order, full-page axe checks, themes/viewports, touch scrolling, Administrator filter, text resizing.
- `receipt_history_integration.php`: real HTTP permissions, completion-once behavior, immutable financial records, Manila dates, Cash Refund filters, invalid-range HTTP 422, Administrator filter authorization.
- `sale_receipt_dialog_browser_test.js`: deep links, native modal keyboard containment, focus return, 58/80mm long paper, canceled printing, permission failures.
- `cart_workspace_browser_test.js` and `sidebar_loader_browser_test.js`: recovery, navigation, isolation, and failure regressions.
- PHP/JavaScript syntax, CSS balance, and `git diff --check` passed.
- Impeccable detector ran once. Remaining advisories concern incumbent compact text sizes, existing semantic color fallbacks, and legacy reversal/modal styles outside this route. They do not indicate a failing rendered state; the active empty-state shape/type and View color drift were corrected.

Run the history regression with `node src/backend/tests/receipt_history_browser_test.js`. To repeat the automated accessibility checks, set `RECEIPT_AXE_PATH` to an axe-core script. The audit used axe-core 4.10.3 from its published npm distribution; the tool remains temporary and is not an application dependency.

## Limits

No connected interactive browser was available. Verification used production-rendered responses in headless Chromium with synthetic records, not the user's current authenticated session. Physical phones, Safari/Firefox, and a manual screen-reader pass were not tested.

The broader `sale_receipt_browser_test.js` is blocked before its receipt checks by the unrelated Register fixture (`Call to undefined function theme_url()`). The focused shared-dialog and Receipt History suites above pass. Concurrent POS work was preserved.
