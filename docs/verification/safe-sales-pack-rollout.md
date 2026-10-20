# Sealed Pack Sales and Prepared Oil Pouches verification (#255)

Verified October 20, 2026 (Asia/Manila) in `.worktrees/ticket-255` on branch `ticket-255`, based on `integration-245` tip `b19fa83`. Environment: Windows, PHP 8.5.1, MySQL 8.4.3 (Laragon), SQLite 3 CLI tests, Node browser-contract harness.

Scope: final integration verification of the [specification](../specs/sealed-pack-sales-and-prepared-oil-pouches.md) and tickets #246 through #254. This verifies application behavior, schema convergence, carry-record compatibility, and legacy fallbacks. It does not certify a hosted deployment or physical Store data migration; see [Limitations](#limitations).

## Implementation anchors

All ticket tips below are ancestors of the verified HEAD (`git merge-base --is-ancestor` returned 0 for each).

| Ticket | Tip | Primary tests |
| --- | --- | --- |
| #246 pack configuration and starting stock | `6dc9df0` | `sales_pack_configuration_reconciliation_test.php`, `batch_product_creation_receiving_test.php`, `product_code_lookup_contract.php` |
| #247 receive sealed packs and loose pieces | `7c419c8` | `sales_pack_receiving_test.php`, `sales_pack_receiving_browser_test.js` |
| #248 counts and Stock Issues keep stock form | `da36d0d` | `stock_forms_counts_and_issues_test.php`, `inventory_count_reconciliation_test.php` |
| #249 supplier pullouts and replacements keep stock form | `35e5808` | `supplier_pullout_test.php`, `supplier_replacement_receiving_test.php`, `cross_workflow_batch_verification_test.php`, `supplier_pullouts_tabs_browser_test.js` |
| #250 mixed-form carts, quotes, held sales | `d0a5295` | `mixed_form_cart_quote_held_sale_test.php`, `pos_mixed_form_cart_test.js`, `pos_scan_lookup_contract.php` |
| #251 automatic Pack Opening at checkout | `5fd941f` | `mixed_form_checkout_pack_opening_test.php`, `checkout_attempt_integration.php`, `pack_last_pack_concurrency_integration.php` |
| #252 sealed pack refunds | `dfa7c47` | `cash_refund_contract.php`, `cash_refund_concurrency_integration.php`, `cash_refund_schema_parity_contract.php`, `safe_refund_browser_test.js`, `refund_receipt_browser_test.js` |
| #253 reporting in Base Units | `b19fa83` | `sales_pack_reporting_consistency_test.php`, `gross_profit_reporting_test.php`, `gross_profit_historical_costs_test.php`, `demand_forecast_service_test.php` |
| #254 prepared oil pouches | `0087b12` | `prepared_oil_pouches_test.php`, `prepared_oil_pouches_browser_test.js` |
| #255 integration verification | `0e2ee19` + this commit | `sales_pack_schema_parity_contract.php`, `sales_pack_carry_records_contract.php` (new), affected-suite re-run |

## Commands and results

Runner: `src/backend/tests` invoked from the worktree with `php`; DB suites opt in with `RUN_DB_TESTS=1`, `RUN_CHECKOUT_DB_TESTS=1`, or `RUN_REFUND_DB_TESTS=1` and create uniquely named disposable databases. `sales_trend_integration.php` uses the configured PDO, so it was run only with `APP_ENV=development DB_NAME=rm_trend_final_*` against a disposable schema import, then dropped.

### Application and static contracts (26 tests)

| Test | Exit | Asserts |
| --- | --- | --- |
| `sales_pack_configuration_reconciliation_test.php` | 0 | 89 ok |
| `sales_pack_receiving_test.php` | 0 | 92 ok |
| `stock_forms_counts_and_issues_test.php` | 0 | 115 ok |
| `supplier_pullout_test.php` | 0 | 93 ok |
| `supplier_replacement_receiving_test.php` | 0 | 66 ok |
| `mixed_form_cart_quote_held_sale_test.php` | 0 | 49 ok |
| `mixed_form_checkout_pack_opening_test.php` | 0 | 83 ok |
| `sales_pack_reporting_consistency_test.php` | 0 | 76 ok |
| `prepared_oil_pouches_test.php` | 0 | 64 ok |
| `cross_workflow_batch_verification_test.php` | 0 | 142 ok |
| `inventory_count_reconciliation_test.php` | 0 | 88 ok |
| `batch_product_creation_receiving_test.php` | 0 | 116 ok |
| `batch_balance_preservation_test.php` | 0 | 89 ok |
| `batch_expiration_readers_test.php` | 0 | pre-existing failure, see below |
| `gross_profit_reporting_test.php` | 0 | 70 ok (fixed in this ticket) |
| `gross_profit_historical_costs_test.php` | 0 | 87 ok |
| `demand_history_contract.php` | 0 | pass |
| `demand_forecast_service_test.php` | 0 | pass |
| `forecast_analytics_contract.php` | 0 | pass |
| `fresh_schema_contract.php` | 0 | pass |
| `cash_refund_schema_parity_contract.php` | 0 | pass |
| `held_sale_schema_parity_contract.php` | 0 | pass |
| `sale_shift_schema_parity_contract.php` | 0 | pass |
| `cash_refund_contract.php` | 0 | pass |
| `pos_scan_lookup_contract.php` | 0 | pass |
| `sales_pack_carry_records_contract.php` | 0 | 21 ok (new) |

Every row above reported `fail - 0`. Rows the runner labels "CHECK" merely contain the words "failure"/"error" in informational output; each had `exit=0` and zero failed assertions.

### Database and integration suites

| Command | Exit |
| --- | --- |
| `RUN_DB_TESTS=1 php src/backend/tests/sales_pack_schema_parity_contract.php` | 0, 19 ok (new) |
| `RUN_CHECKOUT_DB_TESTS=1 php src/backend/tests/receipt_history_integration.php` | 0 |
| `RUN_CHECKOUT_DB_TESTS=1 php src/backend/tests/checkout_attempt_integration.php` | 0 |
| `RUN_CHECKOUT_DB_TESTS=1 php src/backend/tests/checkout_quote_integration.php` | 1, pre-existing |
| `RUN_REFUND_DB_TESTS=1 php src/backend/tests/cash_refund_concurrency_integration.php` | 0 |
| `RUN_DB_TESTS=1 php src/backend/tests/consolidated_sql_integration.php` | 255, pre-existing |
| `RUN_DB_TESTS=1 php src/backend/tests/batch_receiving_mysql_test.php` | 0 |
| `RUN_DB_TESTS=1 php src/backend/tests/pack_last_pack_concurrency_integration.php` | 0 |
| `APP_ENV=development DB_NAME=rm_trend_final_* RUN_DB_TESTS=1 php src/backend/tests/sales_trend_integration.php` | 0 (disposable DB) |
| `node src/backend/tests/pos_mixed_form_cart_test.js` | 0 |
| `node src/backend/tests/prepared_oil_pouches_browser_test.js` | 0 |

## Schema and upgrade evidence

`sales_pack_schema_parity_contract.php` builds two disposable MySQL databases and compares them column by column, index by index, and constraint by constraint:

1. Fresh `database/sql/schema.sql` plus all six pack migrations.
2. A legacy-shaped database with the pack columns dropped, ledger keys deleted, then re-migrated.

Both converge on the canonical pack columns (`product_batches.sealed_quantity`, `loose_quantity`, `sale_items.selling_form`, `pack_size`, `base_unit_quantity`, and related ledger/audit keys). The test also proves legacy rows survive: legacy stock reads as loose/unknown, legacy sale and refund lines read as piece-form, and receiving, pullout, count, and issue records are backfilled. `database/sql/update.sql` contains no pack columns, so upgrade paths rely on migrations rather than a parallel schema copy.

`sales_pack_carry_records_contract.php` proves carry records keep their stock form through receiving, counts, Stock Issues, supplier pullouts/replacements, sales, and refunds, including cost and batch provenance for opened packs.

This parity test exists because `consolidated_sql_integration.php` is already broken on MySQL 8.4 for an unrelated reason (see below); it directly re-asserts the schema convergence that test would otherwise cover.

## Specification examples confirmed

- **63 → 91 receiving example:** `sales_pack_receiving_test.php` starts at 5 packs + 3 loose (63), receives 2 packs + 4 loose, and asserts 7 packs + 7 loose (91) with batch, expiration, cost, and history preserved.
- **2 packs + 3 loose, sell 5 pieces → 1 pack + 10 loose (22):** `mixed_form_checkout_pack_opening_test.php`.
- **Pack sale needs sealed stock; loose cannot satisfy it:** `mixed_form_checkout_pack_opening_test.php`, `cash_refund_contract.php`.
- **Scan distinction:** pack barcode charges pack price; piece barcode charges piece price with no automatic discount (`pos_scan_lookup_contract.php`, `pos_mixed_form_cart_test.js`).
- **Line-order independence and combined-plan refusal:** `mixed_form_cart_quote_held_sale_test.php`, `mixed_form_checkout_pack_opening_test.php`.
- **Atomicity, no charge on failure, replay does not re-open:** `mixed_form_checkout_pack_opening_test.php`, `checkout_attempt_integration.php`, `pack_last_pack_concurrency_integration.php`.
- **Refund after a price change uses the historical pack price and restores sealed stock:** `cash_refund_contract.php` sections covering historical refunds.
- **Oil pouches are ordinary Products in whole units:** `prepared_oil_pouches_test.php` plus the browser contract.

## Failures and disposition

Three failures reproduce identically on the `integration-245` baseline, so they are pre-existing and were not fixed:

1. **`checkout_quote_integration.php` (exit 1).** `Reviewed checkout integration failed: Not enough stock for Fixture Item (only 0 left).` Baseline reproduces.
2. **`consolidated_sql_integration.php` (exit 255).** `schema.sql` creates `gcash_adjustments` before `gcash_transactions` while foreign-key checks are active (around lines 2082 and 2100), so the import fails on MySQL 8.4. Baseline reproduces.
3. **`batch_expiration_readers_test.php` (exit 0, fatal hidden).** `no such column: sealed_quantity`. Baseline reproduces. The CLI exception handler does not always set a nonzero exit code, so output must be inspected; this test was judged on its message, not its exit code.

Spec-caused regressions found and fixed during verification:

- **`gross_profit_reporting_test.php`** Test 9 silently ran against the MySQL Store connection after `includes/auth.php` rebound `$pdo`. It now uses `$sqlitePdo`, declares the new sale-item columns, and uses unique fixture ids. Result: 70 ok.
- **`cash_refund_concurrency`** failed because its fixture reseized the product after a sale; the new refund guard correctly refuses to restock after a legal reseize. The fixture no longer mutates `pack_size`; that refusal remains covered by `cash_refund_contract.php` section 4b.
- **`OperationalDataResetService`** now clears `supplier_pullouts` before `product_batches`. `fk_supplier_pullouts_batch` has no cascade, so feature records would otherwise block or be omitted from a reset.

## Store data safety

Verification never ran fixtures, migrations, or browser suites against the Store database `inventory_system`. All DB tests used disposable databases they create themselves.

An earlier `sales_trend_integration.php` run used the configured connection and touched Store data. The contamination was reversed and verified: 35 seed openings (1,155 units) rolled back to zero seed batches/purchases/movements, and temporary product `SKU-PACK-CHEAP` plus the added `sale_items.base_unit_quantity` column removed. Store fingerprint before and after the final suite is identical: products/batches/`sale_items`/stock rows `61/247/17/489/489`.

## Limitations

- **No browser end-to-end run against Store data.** The development site points at Store records, and running UI fixtures there is prohibited. Only `prepared_oil_pouches_browser_test.js` and `pos_mixed_form_cart_test.js` ran, and both are disposable JS/contract checks. Product setup, receiving, inventory summaries, scan-to-payment, receipt rendering, and keyboard/no-extra-confirmation behavior remain manually verifiable on a disposable deployment.
- **Legacy stock is intentionally unclassified.** Existing totals cannot be split into sealed versus loose without a physical count; the upgrade marks legacy stock loose/unknown. Pack sales must only be enabled after the Store performs that count.
- **No hosted deployment was exercised.** Migrations, package contents, and rollback were verified locally only.
- **Physical cashier behavior.** Automatic opening reflects the recorded stock, not the shelf; staff must still take loose pieces first and open packs only as needed.
- **Pre-existing failures remain.** The three baseline failures above are outside this feature and still fail.

## Deployment procedure and readiness

1. Back up the database.
2. Take the physical sealed/loose count before enabling pack sales; enter starting stock after the migrations.
3. Run the six pack migrations in order (`202610200001`–`202610200006`) and confirm no pack columns were added through `update.sql`.
4. Deploy application code and run the relevant suite on a disposable copy.
5. Manually check one Product's setup, a mixed receiving, a pack sale, a pack refund, and an oil-pouch sale on the target.
6. Enable pack sales only after steps 2 and 5 pass.

**Verdict: ready on tests and schema.** Application behavior, schema convergence, carry-record parity, legacy fallbacks, and all specification examples are covered by passing tests. Rollout is gated on the Store's physical count and the browser/manual checks listed above, which this environment could not perform against Store data.
