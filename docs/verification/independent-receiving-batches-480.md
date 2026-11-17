# Independent receiving batches — #480

Each selected product now owns independent allocations. Add another batch creates a blank allocation; rescans only focus the last edited allocation. Modes, inactive-mode counts, lot, expiry, costs, ordering and current-cost choices survive encrypted recovery and rejected submissions. Removing an entered batch requires confirmation and preserves its siblings.

The receiving service accepts grouped allocations and existing single-line callers. It resolves inherited costs from the product state before the delivery, deduplicates known costs at two-decimal storage precision, validates the final current-cost choice, and updates that product cost once. Unknown allocations remain unknown; selling prices are unchanged. All receiving rows, batches, movements, purchase history, product costs and operation-token results share one transaction. Replacement allocations inherit only their product's pullout link; grouped replacements count once, while historical ungrouped receipts keep individual identities.

Migration `202611180001_receiving_allocations` adds nullable receiving mode, cost-source and current-cost metadata. It does not rewrite historical amounts or merge historical receipts. The fresh schema includes the same fields.

## Focused verification

Passed with disposable MySQL databases where stock is mutated:

- `batch_receiving_mysql_test.php`: independent 12 + 12 batches, exact totals and provenance, current-cost validation, empty/invalid allocations, cumulative bounds, replacement links/counting, legacy ungrouped identity, second-allocation rollback and token retry.
- `known_receiving_cost_mysql_test.php`: known zero, mixed/all-unknown costs, precision deduplication and product fallbacks taken before the delivery.
- `inline_receiving_browser_test.js`: allocation editing/removal, confirmation, empty-allocation rejection, rescans including untouched batches, later-allocation stale-cost acknowledgement, explicit cost choices/invalidation, encrypted multi-allocation recovery, interrupted saves and replay protection. Layout captures cover desktop/narrow, light/dark and reduced motion.
- `stock_receiving_scan_browser_test.js`: authorization, CSRF, whole-entry rollback, concurrent receipts, history, and connected checkout/Stock Issue workflows.
- `stock_receiving_ui_browser_test.js`, `stock_receiving_landscape_browser_test.js`, `known_receiving_cost_browser_test.js`, `sales_pack_receiving_browser_test.js`, `supplier_replacement_receiving_test.php`, and `receiving_submission_contract.php`.
- `npm run check`, `npm run check:contracts`, PHP syntax and JavaScript test syntax. There is no separate TypeScript typecheck command. The view audit retains an unrelated duplicate-ID warning in `cashier/shifts.php`.

The standalone `receiving_history_contract.php` cannot complete with its existing fixture: the isolated product starts with inventory 8 and batch total 0. History behavior is covered through the receiving service and browser suites above.

## Full suite

The root checkout run was restarted in a clean temporary checkout after lint traversed existing worktrees. The clean-checkout run passed shared browser checks, PHP lint, Python compilation, CSS balance, smoke, tunnel, reopening, date-boundary and environment checks, then exited at `consolidated_sql_integration.php:88`: `Fresh schema.sql import still requires PHP migrations`. The same failure is documented for #479. Remaining full-suite steps are unverified. The new receiving migration is included in the fresh-schema ledger.

The focused multi-batch service and browser tests also passed from that clean checkout, independently of unrelated root working-tree edits. The checkout used the existing installed Node dependencies through `NODE_PATH` and disposable databases.

## TestSprite

Both TestSprite runs reported failed assertions. Their failing-step HTML was inspected: the editor capture contains the expected independent quantities, lots, dates and costs; the later Review capture contains two 12-unit batches, total 24, hidden current cost `7.00`, and the `7.00` option explicitly selected. The local browser suite independently verifies these states. TestSprite's reported status remains **failed**; this is not a TestSprite pass.

- [Editor run c7b6a0d5](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/test-cases/fe028cc9-d052-4e8c-b63e-662eb9443138)
- [Review run 80d272ae](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/test-cases/6c5befc7-ed1f-4804-bc0a-4704ef24fc42)

The first downloadable failure bundle was withheld by TestSprite; pinned step records and signed HTML snapshots supplied the evidence instead. Receipts, snapshots and captures are under ignored `output/issue-480/`.

## Review and limits

Standards: zero documented-standard violations or material smells. Spec: two findings, both fixed in `e3d5fe9a` and covered by failing-then-passing browser regressions: empty-batch rescans changing mode, and later inherited-cost allocations missing the changed-cost Review notice. Follow-up review confirmed zero remaining findings.

The interface preserves the existing RetailMind design system and split-catalog direction. No global tokens, dependencies or shipping images were added. Chromium viewport/touch simulation does not verify physical scanners or real mobile devices.
