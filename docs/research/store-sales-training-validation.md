# Store sales-to-training validation

Updated 2026-10-28 (Asia/Manila). This records code and isolated-test evidence, not a live Store activation.

## Confirmed behavior

1. Completed sales feed the existing DemandHistoryService. Historical Sales Packs use their recorded contents to produce Base Units; current catalog edits do not rewrite old quantities.
2. Refunds and approved reversals remain linked to original sales. Versioned history makes their corrections visible only after they were known. Daily history does not replace unknown days with zero.
3. The same reader supplies the scheduler and the PHP export used by the Python worker. No second sales aggregation path was introduced.
4. Before weekly Store training is queued, check each active Product for at least 140 consecutive usable days ending yesterday in Philippine time. Skip ineligible products and return a reason; if none qualify, do not enqueue training. The CLI scheduler prints skipped-product reasons.
5. The worker independently repeats eligibility through the training export, including manually queued training. It trains the eligible products together on their common observed suffix, so a shorter product cannot introduce gaps into another product's evaluation. Eligibility diagnostics are saved in candidate metadata. No eligible products means no candidate is published.
6. Training retains the existing three chronological 28-day backtests and the existing 7/14/28-day quantity metrics, baseline, and configured quality thresholds. Training creates a candidate; it does not approve it.
7. Generation uses an approved artifact. For Store output, include only products present in that model with the latest 28 consecutive observed days. Newly introduced or gapped products retain stock-rule guidance instead of failing another product's generation.
8. The Inventory Manager sees current stock, the independent stock-rule buying suggestion, and expected 28-day sales. Forecasts never buy goods, approve a model, or change stock.

The 56-day readiness minimum is not the complete training-and-evaluation requirement. The 140-day gate is a structural minimum (56 initial days plus 84 later days), not proof of adequate accuracy. A new eligible product can shorten the common training suffix; candidate metrics still decide whether the resulting model is acceptable.

## Verified in isolated fixtures

- Real PHP Store reader → eligibility → scheduler → worker export → shared Random Forest backtest: mixed mature, 139-day, gapped, inactive, no-history, genuine-zero, and old-gap products. Eligible products survive together; all-ineligible history queues nothing. Philippine timezone conversion and repeated scheduled ticks are checked.
- Historical sales/refund cutoffs, imported certified totals, POS precedence, genuine zeros, unknown gaps, closures and zero-sale stockouts: existing demand-history contracts pass.
- Historical packs, pack refunds, catalog edits, Base Unit sales and replenishment consistency: existing reporting integration test passes.
- Worker candidate isolation, approval requirements, failure rollback and preservation of previous forecasts: existing worker checks pass, with a new check for untrained/gapped product exclusion.
- Recommendation service: independent stock-rule rounding, current/approved/complete forecasts, stale/unapproved/incomplete/missing forecasts, genuine zero, observation/suspension, and unchanged inventory.
- Browser fixture: four-column rendering, independent values, search/filter/sort, old-cache isolation, print visibility, desktop and mobile layout. PDF unit checks cover all rows, Unicode, wrapping, pagination, missing and genuine-zero forecasts.

## Limits and next decisions

- Closures, unknown days and censored stockouts break strict consecutive windows. A regularly closed Store may never qualify under the current feature contract. Supporting masked calendar windows requires a separate design decision; do not silently turn these days into zero demand.
- Positive recorded sales can still hide lost demand after a stockout. Existing censoring detects zero-sale stockout days; it does not estimate all lost demand.
- Eligibility conservatively requires every exported revision in its trailing window to remain usable. This prevents historical backtests from acquiring observations that were unknown at their forecast origins.
- The existing reader queries daily evidence per product/day. Eligibility performance on a large operational history has not been benchmarked; repeated scheduler checks when nothing qualifies may need a smaller preliminary age check or persisted diagnostics if measurements show a problem.
- Existing scheduled-job count-then-insert is not atomic across concurrent scheduler processes. This work verifies sequential duplicate prevention; concurrent scheduling and crash recovery need disposable MySQL integration checks.
- No live scheduler, operational database writes, worker activation, model promotion, or real purchase decision was executed. Public M5 results cannot approve the Store model.
- The older opt-in scheduler integration test deletes jobs in its selected database and references obsolete settings. It was not run. The new eligibility test uses disposable SQLite and an actual reader/export/backtest boundary.

## Focused checks

```powershell
python src/backend/tests/store_training_eligibility_test.py
python -m unittest discover -s src/backend/tests -p test_observed_worker.py
php src/backend/tests/demand_history_contract.php
python src/backend/tests/forecast_history_source_test.py
php src/backend/tests/sales_pack_reporting_consistency_test.php
php src/backend/tests/replenishment_queue_contract.php
php src/backend/tests/replenishment_metadata_contract.php
node src/backend/tests/replenishment_comparison_browser_test.js
node src/backend/tests/replenishment_pdf_test.js
```
