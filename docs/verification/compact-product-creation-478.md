# Compact product creation — #478

Verified 2026-11-17 on the working branch.

The wizard is replaced by one form in `products.php`, reused by normal and `?origin=receiving` creation. Server validation requires the essentials, distinguishes blank from zero cost, preserves import compatibility, and refuses receiving-origin opening quantities. Optional product configuration, standalone Opening Batch stock, and barcode printing remain available.

## Verification

Passed:

- `npm run check` and `npm run check:contracts`.
- `php src/backend/tests/product_creation_boundary_test.php`: required fields, invalid category/price/conversion, and all three receiving-origin stock inputs.
- `RUN_DB_TESTS=1 node src/backend/tests/compact_product_creation_browser_test.js`: real HTTP requests against disposable databases; desktop/mobile, light/dark, keyboard disclosures, reduced motion, inline errors, retained retry token, explicit zero cost, generated barcode, Opening Batch expiry/stock, printing, tampered receiving quantities, and successful zero-stock receiving creation.
- `node src/backend/tests/product_create_browser_test.js`, `sales_pack_browser_test.js`, `prepared_oil_pouches_browser_test.js`, and `products_keyboard_scan_browser_test.js`: existing form, encrypted-draft, unit, pricing, packaging, and scanner regressions.
- `RUN_DB_TESTS=1 node src/backend/tests/known_receiving_cost_browser_test.js` and `RUN_DB_TESTS=1 php src/backend/tests/known_receiving_cost_mysql_test.php`.
- PHP suites: `batch_product_creation_receiving_test.php`, `sales_pack_configuration_reconciliation_test.php`, `sales_pack_receiving_test.php`, `cross_workflow_batch_verification_test.php`, `product_warning_lead_time_test.php`, `scheduled_demand_driver_dated_evidence_test.php`, and `product_csv_import_test.php`.
- PHP syntax checks for changed production files; `git diff --check`.

Inspected rendered desktop light and mobile dark captures. Physical cameras, scanners, printers, and real mobile devices were not tested.

`npm run test:e2e` was attempted once. Its first Inventory Counts fixture failed with `Duplicate entry '1' for key 'uq_fiscal_period_one_open'`; subsequent suites in that command did not run. The supplemental Products/Counts viewport test passed its Product checks, then timed out on the unrelated Count-recording confirmation. Neither result establishes a passing full E2E suite.

## TestSprite

[Passed rerun](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/d997bb3e-593f-45ff-b9be-ccfaba0746b3/test/5216ed56-8a77-44a4-b3f2-1ee86a5ea035), using the repository TestSprite workflow and a disposable synthetic Store.

The first run failed the inline-validation assertion. Its captured HTML showed the modal open, entered name/price retained, and the cost field marked invalid with an inline message. The message was made explicit and the rerun passed. The CLI withheld the first run's artifact bundle after an environment change; the pinned step results and final HTML snapshot were inspected instead. Final retry-token and conversion fixes were additionally verified by the focused HTTP browser test.

## Standards

No documented-standard violations or blocking design smells found by the independent review and follow-up.

## Spec

Review found two issues: initially hidden receiving conversion and lost operation tokens on redirected retries. Both were fixed and covered by focused browser assertions. Follow-up found no remaining blocker or scope creep. Receiving handoff remains the separate #479 slice.

Review totals: Standards 0 outstanding; Spec 0 outstanding (2 resolved).
