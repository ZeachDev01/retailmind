# Receiving, Issue Reviews and Supplier Pullouts (#470)

Date: 2026-11-17. Supplemental checks use Playwright Chromium against the independent worktree and disposable database; they are not TestSprite or physical-device acceptance.

## Change

The Receiving receipt summary was sticky even when taller than the available viewport. At 960x600, scrolling Next into view left it at y=574.78 with height=54.59, below the 600px viewport. Removing sticky positioning lets ordinary page scrolling expose the receipt fields and actions. No submission, permission, stock-posting or schema behavior changed. `stock-receiving.css` is a separately loaded page stylesheet, outside the generated nine-source bundle.

## Supplemental matrix

CSS viewports: 640x360, 800x480, 960x600, 1024x768, 1280x800 and 1366x768.

- `RUN_DB_TESTS=1 node src/backend/tests/stock_receiving_landscape_browser_test.js`: passed all six rows. Real authenticated Receiving page, retained quantity and receipt after resizing, long supplier, scrollable fields and Next, reachable final Save, no whole-page horizontal overflow. Uses existing disposable fixture/server pattern. Red failure preceded the CSS fix. Local artifacts: `output/playwright/stock-receiving-landscape/measurements.json`, six viewport captures, and `red.png`; inspected red and 960x600 final capture.
- `node src/backend/tests/stock_issue_review_clarity_browser_test.js`: passed six rows plus 390x1000, light/dark populated, expanded batch allocations and empty states. Uses the real template with display fixtures; verifies escaped product/SKU, insufficient-stock disabled approval, keyboard batch disclosure, allocation payload, and no page overflow. Refreshed stale fixture formatter, labels and selectors to match current UI. Inspected `output/playwright/stock-issue-reviews/light-640.png`.
- `node src/backend/tests/supplier_pullouts_tabs_browser_test.js`: passed six rows plus 390x900, mutable/read-only display modes, retained reason, tabs/keyboard navigation, pagination, search, table-local horizontal scroll and light/dark field focus. Rendered display fixtures do not prove server-side permission enforcement or writes. Captures emitted to the test-reported temporary `rm-pullout-tabs-*` directory.

## Recovery and authorized writes

The existing `inventory_private_recovery_browser_test.js` uses its existing browser/HTTP and persisted-state seam. Fixture repairs remove retired user columns and provide opening batch stock matching inventory; without that batch, Receiving returned HTTP 200 containing a validation error, not a successful commit. The test now checks the actual Receiving success before simulating response loss. Removed Receiving notes fields are replaced by current batch/receipt fields, all existing batches are explicitly counted, and draft flush synchronizes persistence without waiting for a once-only notice. Added a successful Supplier Pullout submit and one persisted stock movement assertion after restoring its draft. Product/Count setup remains shared with #469. Final full-run command: `RUN_DB_TESTS=1 node src/backend/tests/inventory_private_recovery_browser_test.js` — **passed**, after merging integration `1a6b5cf9`. Its existing assertions verify encrypted storage, explicit Resume/Discard, account isolation and process restart; offline unsent submission saves nothing; lost Receiving response persists exactly one Receiving record and one stock movement; lost Count approval and Issue approval responses reconcile without restoring vanished decision forms; Issue approval posts one stock movement; restored Pullout submits successfully and posts one stock movement; Product create returns through barcode printing and clears its draft; newer unsent receipt edits remain reviewable and cannot repeat stock effects. Uses an 820x1180 emulated browser, separate from the six landscape geometry rows. Product opening-stock lost-response assertions are not present in this script.

## Repository checks and limits

`npm run check:contracts`: passed. `npm run check`: styles and view audit passed; stopped at the unchanged smoke needle `Cashier Shift opening is owned by the Cashier workspace: missing $service->availableRegisters()`. Existing duplicate `open-register` ID warning remains. The old broad `stock_receiving_ui_browser_test.js` still stops before its responsive loop on obsolete Add-to-Delivery copy; it was left unchanged rather than treating it as a passing workflow.

Primary TestSprite authenticated workflow runs and artifacts belong to the integration evidence. Requested TestSprite viewport control/measurement remains unverified; no TestSprite pass is claimed here. Physical tablet, browser chrome, actual keyboard and touch gestures remain unverified. Keep #470 open pending required primary and physical acceptance. Validation/connection behavior is checked by the recovery workflow; a six-viewport matrix of every error and successful write is not claimed.
