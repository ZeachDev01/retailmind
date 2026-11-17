# Inline Stock Receiving — #479

Scope: Variant A combines catalog selection and quantity entry, followed by Review and one stock commit. One product line remains one allocation; additional allocations belong to #480. No database migration or dependency was added.

Browser coverage uses disposable MySQL databases and the real receiving route. `inline_receiving_browser_test.js` checks scans without increments or duplicates, deliberate mode changes, visible lot/expiry, missing expiry, Review totals/conversion, linked field errors, current catalog refresh, stale commit rollback, encrypted recovery, failed-save retry, legacy-stage migration, damaged-draft correction and unavailable-product safeguards. Geometry covers light/dark, reduced motion, desktop and narrow layouts, with fixed-chrome focus clearance. It is included in `npm run test:e2e`.

The existing scan workflow now creates its own disposable database, including its independent HTTP servers and Store write-gate process. Its coverage includes role/CSRF boundaries, whole-entry rollback, concurrent receipts, operation tokens, history and connected checkout.

Additional checks: `npm run check`, `npm run check:contracts`, PHP syntax, `known_receiving_cost_mysql_test.php`, `batch_receiving_mysql_test.php`, `supplier_replacement_receiving_test.php`, `receiving_submission_contract.php`, and the existing receiving UI, landscape, Sales Pack and known-cost browser tests. The repository has no separate TypeScript typecheck command; production changes are PHP and vanilla JavaScript.

The project checks, contracts, end-to-end script and listed focused receiving checks passed. The full repository command is `bash src/backend/tests/run_all.sh`; its lint phase also includes existing `.worktrees` checkouts.

Browser captures and TestSprite receipts are under the ignored `output/issue-479/` directory. Chromium viewport/touch simulation does not verify physical scanners or real mobile devices.

TestSprite passed the visible editor/Review/rescan-preservation workflow: [run 7ae10fe1](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/test-cases/86af457c-c7b6-40b8-ac12-7d222e6053b5). Exact focus and viewport coverage come from local Playwright. Earlier TestSprite attempts failed; inspection exposed premature automatic lookup moving focus before Enter, which was removed. TestSprite withheld downloadable failure bundles, so failing step HTML and CLI receipts were inspected instead.

Review against `db3f9f5`: Standards found no issues. Spec identified loss of inactive-mode counts after rejected POST; a recovery-only quantity snapshot and browser regression resolved it. The independent visual reviewer matched Variant A and required a corrected dark capture; the test now applies the same `color-scheme` as production, and the reviewer marked that finding resolved. Global DESIGN.md tokens remain unchanged.
