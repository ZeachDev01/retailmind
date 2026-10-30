# Transaction and browser quality test results

Date: 2026-10-30 (Asia/Manila). This follow-up supersedes the read-only scope of the [initial evidence report](beta-questionnaire-quality-report-20261030.md). [120-case questionnaire plan](beta-questionnaire-quality-plan.md).

**Overall result: NOT PASSED.** Thirteen existing checks were invoked: **5 passed, 8 returned exit code 1**. Of eight browser-suite invocations, two passed and six stopped on assertions, timeouts, or a database prerequisite. Five additional transaction/service checks produced three passes and two stops. These are check counts, not counts of passed questionnaire statements.

## Authorization and environment

The user explicitly authorized **disposable database fixtures** for transaction/browser testing. Existing application/test sources were not modified; no fixes were authorized. The configured Store database was not selected for test writes. The generic E2E runner and scripts using scan_workflow_fixture.php against the configured database were not run.

Windows PowerShell; PHP 8.5.1; Node.js v26.1.0; headless **Chromium 156.0.8078.4**. Firefox and WebKit executable checks reported not installed; neither was run. Real routes were served by each suite's local PHP server with guarded temporary database names and fixture accounts. Some service contracts use in-memory SQLite, as distinguished below.

HEAD: `2a5e3af99ebc3dca3408570110079bc312551b3f`; existing dirty Backup/Restore work and QA documents remained in place. Results cover the current local working tree and fixture data, not a deployed production installation or every questionnaire workflow.

Database-backed fixtures use generated names matching `retailmind_theme_test_<12 hex>`, `retailmind_refund_test_<12 hex>`, or `retailmind_checkout_test_<12 hex>`. Their cleanup paths were allowed to run after failures. A final read-only server inventory found 35 databases matching those prefixes. No pre-run inventory was captured, so their provenance cannot be established; no unrelated or unattributed database was deleted, and this report does not certify a clean database server.

## Hardware acceptance supplied by the user

| Equipment | Result | Evidence provenance |
| --- | --- | --- |
| Thermal printer | Pass, user-reported | User confirmed personally testing it in this conversation. |
| Barcode scanner | Pass, user-reported | User confirmed personally testing it in this conversation. |

These results are accepted for this round's hardware scope and are separate from automated print mocks and simulated barcode input. Models, browser/OS versions, artifacts, and test date were not supplied. Other device/browser portability statements and respondent ratings were not thereby executed.

## Results and questionnaire traceability

Case IDs refer to the plan. Mappings identify supporting or missing evidence, not automatic certification of an entire statement. A failed script does not prove every scenario it contains failed; later unexecuted assertions remain unverified.

| Evidence | Check | Result | Layer | Observation / execution limit | Questionnaire mapping |
| --- | --- | --- | --- | --- | --- |
| T1 | `cashier_checkout_receipt_browser_test.js` | PASS | Browser/HTTP | Real authenticated Cashier checkout, saved receipt on POS, clear cart, reopen/reload, lost-response recovery, opt-in automatic printing and manual retry. | CAS-FS-1/2; CAS-RE-1; receipt portions of CAS-CO-2 |
| T2 | `stock_receiving_ui_browser_test.js` | FAILED ASSERTION | Browser/HTTP | Stopped at line 63: expected '+ Add to Delivery', actual 'Add To Stock'. Submission assertions later in this script did not run. Treat as test/UI wording mismatch pending review. | INV-FS-2; INV-US-2; INV-RE-1/4/5 remain incomplete |
| T3 | `inventory_private_recovery_browser_test.js` | STOPPED / TIMEOUT | Browser/HTTP | Stopped at line 84 trying to fill hidden #batch_number. Recovery, retry, Count, and issue-approval scenarios later in the script did not run. | INV-RE-1/2/3; receiving recovery remain incomplete |
| T4 | `gcash_browser_test.js` | FAILED ASSERTION | Browser/HTTP | Saved a completed cash-in receipt with PHP 1,010 collected, PHP 1,000 wallet transfer, and reference 0012345678910 before failing line 455: closing the receipt did not focus #gcash-amount. Later history, cross-role, and integration assertions were not reached. | CAS-FS-3 partially observed; CAS-CO-5 and Admin oversight incomplete |
| T5 | `refund_completion_browser_test.js` | BLOCKED BY PREREQUISITE ASSERTION | MySQL/HTTP prerequisite | Parent Cash Refund database fixture failed: 'HTTP refund form distinguishes Pack and Piece lines and states sealed pack restock eligibility'. The 58/80 mm completion browser scenarios were not reached. | CAS-FS-5; CAS-RE-5; CAS-SE-3 remain incomplete |
| T6 | `administrator_management_browser_test.js` | PASS | Browser/HTTP | Authenticated UI/HTTP creation, profile edits, password reset/session version changes, disable/reactivate, role changes, protected self-role behavior, and prohibited cross-workspace requests. | ADM-FS-1; ADM-RE-1; ADM-SE-4; ADM-MA-4 supporting evidence |
| T7 | `administrator_mobile_monitoring_browser_test.js` | FAILED JAVASCRIPT ASSERTION | Browser/HTTP, emulated viewport | Gross Profit report produced JavaScript error: Identifier 'hideDates' has already been declared. Stopped at line 109; later responsive/role assertions were not reached. | ADM-FS-2; ADM-US-2; ADM-PO-3 remain incomplete |
| T8 | `forecast_decision_browser_test.js` | STOPPED / TIMEOUT | Browser/HTTP | Stopped at line 95 waiting for .queue-item matching 'Decision Test Item' and its .queue-details. Does not prove forecast generation, queue correctness, or job completion. | INV-FS-3; INV-US-3 remain incomplete; ADM-FS-5 not established |
| T9 | `checkout_attempt_integration.php` | BLOCKED / FAILED HARNESS ASSERTION | Disposable MySQL, competing workers | Fixture failed at 'Checkout worker reaches barrier'. Concurrent duplicate-checkout, rollback, and later assertions did not complete; successful single-browser checkout does not resolve this gap. | CAS-RE-1 concurrency/retry integrity remains incomplete |
| T10 | `batch_receiving_mysql_test.php` | PASS | Disposable MySQL | Real MySQL stock/batch effects, independent receipts and dates, replay returns the same saved receipt, changed payload is rejected, and injected movement failure rolls back. Includes receiving, opening stock, and legacy purchase paths. | INV-FS-2; INV-RE-1/4/5 supporting service evidence; browser suite still stopped |
| T11 | `stock_issue_batch_deduction_test.php` | BLOCKED BY FIXTURE | In-memory SQLite | SQLite test stopped before reporting/approval: no open Fiscal Period covers 2026-10-30. Fiscal validation rejected the operation; no successful approval/rejection result is established. | INV-FS-5; INV-RE-2; CAS-RE-4 remain incomplete |
| T12 | `cashier_shift_reconciliation_contract.php` | PASS | In-memory SQLite | Cashier Shift reconciliation contract passed, including expected cash/variance rules and audit-failure rollback covered by that script. | CAS-RE-3; CAS-US-5 supporting service evidence |
| T13 | `held_sale_shift_contract.php` | PASS | In-memory SQLite | Held Sale Cashier Shift ownership lifecycle contract passed: hold/list/resume/discard/close/complete/expire and ownership boundaries. | CAS-RE-2 supporting service evidence |

## Findings to resolve before an overall pass

1. **Gross Profit JavaScript error (T7):** duplicate declaration of `hideDates` observed during Admin monitoring. Investigate the actual report scripts and regression-test the page.
2. **GCash keyboard focus (T4):** saved receipt completion worked through the recorded amount/reference checks, but closing after print retry did not focus the ready input. History and cross-role checks remain unverified.
3. **Receiving suite drift (T2/T3):** wording mismatch and hidden optional batch field prevent full browser transaction/recovery coverage. Confirm intended UI behavior before correcting any test expectations.
4. **Refund prerequisite (T5):** Pack/Piece and sealed-pack guidance assertion blocks completion-browser testing. Actual refund completion is not certified.
5. **Forecast queue (T8):** expected recommendation/details never appeared within the test's wait. Determine whether the fixture, selectors, or application state caused it.
6. **Checkout concurrency (T9):** worker barrier was not reached. Single-browser payment success does not prove concurrent submissions are safe.
7. **Stock Issue fixture (T11):** no current open Fiscal Period in this isolated fixture; successful approval/deduction and rejection still need execution with valid prepared data.

No application defects, fixture assertions, or test expectations were changed to make this run pass.

## Commands and captured output

Run commands from the repository root. The opt-in environment shown for each invocation is process-local; scripts generated their own temporary fixtures. ANSI terminal formatting has been removed from captured output for readability.

### T1: Checkout and saved receipt

Environment: `RUN_DB_TESTS=1`. Exit code: **0**.

```text
node src/backend/tests/cashier_checkout_receipt_browser_test.js
Cashier payment → saved receipt on POS → close/reload: passed
Sale opt-in auto-print, unchecked default, refresh and manual retry: passed; screenshots: C:\Users\Mike\AppData\Local\Temp\rm-checkout-receipt-B6DyXN
```

### T2: Receiving interface

Environment: `RUN_DB_TESTS=1`. Exit code: **1**.

```text
node src/backend/tests/stock_receiving_ui_browser_test.js
AssertionError [ERR_ASSERTION]: The input did not match the regular expression /\+ Add to Delivery/. Input:

'Add To Stock'

    at C:\laragon\www\retailmind\src\backend\tests\stock_receiving_ui_browser_test.js:63:16 {
  generatedMessage: true,
  code: 'ERR_ASSERTION',
  actual: 'Add To Stock',
  expected: /\+ Add to Delivery/,
  operator: 'match',
  diff: 'simple'
}
```

### T3: Inventory private recovery

Environment: `RUN_DB_TESTS=1`. Exit code: **1**.

```text
node src/backend/tests/inventory_private_recovery_browser_test.js
locator.fill: Timeout 10000ms exceeded.
Call log:
  - waiting for locator('#batch_number')
    - locator resolved to <input type="text" maxlength="100" id="batch_number" placeholder="Batch #" data-field="batch_number" name="lines[0][batch_number]"/>
    - fill("PRIVATE-BATCH-316")
  - attempting fill action
    2 × waiting for element to be visible, enabled and editable
      - element is not visible
    - retrying fill action
    - waiting 20ms
    2 × waiting for element to be visible, enabled and editable
      - element is not visible
    - retrying fill action
      - waiting 100ms
    20 × waiting for element to be visible, enabled and editable
       - element is not visible
     - retrying fill action
       - waiting 500ms

    at C:\laragon\www\retailmind\src\backend\tests\inventory_private_recovery_browser_test.js:84:45 {
  log: [
    "  - waiting for locator('#batch_number')",
    '    - locator resolved to <input type="text" maxlength="100" id="batch_number" placeholder="Batch #" data-field="batch_number" name="lines[0][batch_number]"/>',
    '    - fill("PRIVATE-BATCH-316")',
    '  - attempting fill action',
    '    2 × waiting for element to be visible, enabled and editable',
    '      - element is not visible',
    '    - retrying fill action',
    '    - waiting 20ms',
    '    2 × waiting for element to be visible, enabled and editable',
    '      - element is not visible',
    '    - retrying fill action',
    '      - waiting 100ms',
    '    20 × waiting for element to be visible, enabled and editable',
    '       - element is not visible',
    '     - retrying fill action',
    '       - waiting 500ms'
  ],
  name: 'TimeoutError'
}
```

### T4: GCash

Environment: `RUN_DB_TESTS=1`. Exit code: **1**.

```text
node src/backend/tests/gcash_browser_test.js
GCash unavailable toast and recovery: passed
AssertionError [ERR_ASSERTION]: Close returns focus to ready GCash input

false !== true

    at C:\laragon\www\retailmind\src\backend\tests\gcash_browser_test.js:455:16 {
  generatedMessage: false,
  code: 'ERR_ASSERTION',
  actual: false,
  expected: true,
  operator: 'strictEqual',
  diff: 'simple'
}
```

### T5: Refund completion

Environment: `script enables RUN_REFUND_DB_TESTS=1 internally`. Exit code: **1**.

```text
node src/backend/tests/refund_completion_browser_test.js
Cash Refund database test failed: HTTP refund form distinguishes Pack and Piece lines and states sealed pack restock eligibility
Error: Command failed: php C:\laragon\www\retailmind\src\backend\tests\cash_refund_concurrency_integration.php
    at genericNodeError (node:internal/errors:998:15)
    at wrappedFn (node:internal/errors:543:14)
    at checkExecSyncError (node:child_process:925:11)
    at execFileSync (node:child_process:961:15)
    at C:\laragon\www\retailmind\src\backend\tests\refund_completion_browser_test.js:10:9
    at Object.<anonymous> (C:\laragon\www\retailmind\src\backend\tests\refund_completion_browser_test.js:198:3)
    at Module._compile (node:internal/modules/cjs/loader:1856:14)
    at Object..js (node:internal/modules/cjs/loader:1996:10)
    at Module.load (node:internal/modules/cjs/loader:1579:32)
    at Module._load (node:internal/modules/cjs/loader:1381:12) {
  status: 1,
  signal: null,
  output: [ null, null, null ],
  pid: 22224,
  stdout: null,
  stderr: null
}
```

### T6: Admin staff management

Environment: `RUN_DB_TESTS=1`. Exit code: **0**.

```text
node src/backend/tests/administrator_management_browser_test.js
Administrator management browser/HTTP: passed
```

### T7: Admin mobile monitoring

Environment: `RUN_DB_TESTS=1`. Exit code: **1**.

```text
node src/backend/tests/administrator_mobile_monitoring_browser_test.js
AssertionError [ERR_ASSERTION]: report/report_generation.php?report=gross_profit runs without JavaScript errors
+ actual - expected

+ [
+   "Identifier 'hideDates' has already been declared"
+ ]
- []

    at C:\laragon\www\retailmind\src\backend\tests\administrator_mobile_monitoring_browser_test.js:109:24 {
  generatedMessage: false,
  code: 'ERR_ASSERTION',
  actual: [Array],
  expected: [],
  operator: 'deepStrictEqual',
  diff: 'simple'
}
```

### T8: Forecast/replenishment decisions

Environment: `RUN_DB_TESTS=1`. Exit code: **1**.

```text
node src/backend/tests/forecast_decision_browser_test.js
Disposable forecast database seeded.
Browser launched.
locator.evaluate: Timeout 30000ms exceeded.
Call log:
  - waiting for locator('.queue-item').filter({ hasText: 'Decision Test Item' }).locator('.queue-details')

    at C:\laragon\www\retailmind\src\backend\tests\forecast_decision_browser_test.js:95:51 {
  log: [
    "  - waiting for locator('.queue-item').filter({ hasText: 'Decision Test Item' }).locator('.queue-details')"
  ],
  name: 'TimeoutError'
}
```

### T9: Checkout transaction integrity

Environment: `RUN_CHECKOUT_DB_TESTS=1`. Exit code: **1**.

```text
php src/backend/tests/checkout_attempt_integration.php
Checkout attempts integration failed: Checkout worker reaches barrier
```

### T10: Receiving transaction integrity

Environment: `RUN_DB_TESTS=1`. Exit code: **0**.

```text
php src/backend/tests/batch_receiving_mysql_test.php
Batch receiving MySQL: passed (opening, receiving, legacy purchase, preserved dates, batch readers, transaction rollback)
```

### T11: Stock Issue batch deduction

Environment: `none`. Exit code: **1**.

```text
php src/backend/tests/stock_issue_batch_deduction_test.php
Starting Ticket #219 stock issue batch deduction tests...

--- Test 1: 20 loaves -> approve 3 damaged -> total 17, batch 17, 1 movement, attribution saved ---
  ok - Initial bread inventory is 20
  ok - Initial bread batch remaining is 20
Unhandled application exception: Cannot record stock issue report dated 2026-10-30 because no open fiscal period covers that date.
```

### T12: Shift reconciliation

Environment: `none`. Exit code: **0**.

```text
php src/backend/tests/cashier_shift_reconciliation_contract.php
Cashier Shift reconciliation contract: passed
```

### T13: Held-sale ownership

Environment: `none`. Exit code: **0**.

```text
php src/backend/tests/held_sale_shift_contract.php
Held Sale Cashier Shift ownership contract: passed
```

## Remaining acceptance work

The original 120-item questionnaire is not fully passed. This follow-up adds real browser/transaction evidence; it does not substitute the initial 0/120 workflow table with invented pass totals. Record complete expected/actual evidence for each case before assigning a questionnaire Pass.

Still incomplete: full sales/Gross Profit ledger reconciliation, successful refund completion, full Stock Issue approval/rejection and Counts, forecast generation/job lifecycle, database backup/download browser workflow, all performance targets, second-browser coverage, actual update/defect regression pairs, and any unexecuted role/session/validation scenarios. Existing smoke failures remain unresolved; the smoke check was not rerun in this follow-up.

Next testing should first resolve the eight nonzero exits above and rerun the affected suites. Any application fixes require a separate scope decision. Thermal printer and barcode scanner acceptance remain recorded as the user's passes; no independent hardware retest was performed.

