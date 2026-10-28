# Forecasting input coverage review

Reviewed 2026-10-29 (Asia/Manila). Scope: current working-tree notebook, shared Python features, Store history export, worker, candidate evaluation, and saved M5 results. Existing uncommitted changes were included in inspection and preserved. No application behavior was changed.

## Finding

The pipeline learns product sales patterns, but does not use all the contextual data already collected by RetailMind. It supports an advisory demonstration; future Store accuracy remains unproven. Adding columns cannot guarantee improvement: each proposed input needs comparison on chronological validation and fresh confirmation data.

## Exact inputs

The M5 adapter selects `datetime`, `item_id`, and `sales`, renaming them to `date`, `product_id`, and `units_sold`. `store_id=CA_1` and `cat_id=FOODS` select the population; they are not model inputs. `units_sold` is the prediction target.

The shared contract has 14 feature columns before product identity is one-hot encoded:

```text
product_id, horizon_day, lag_1, lag_7, lag_14, lag_28,
rolling_mean_7, rolling_std_7, rolling_mean_28, rolling_std_28,
day_of_week, day_of_month, month, is_weekend
```

Lags and rolling summaries use observations through the forecast origin. Here `lag_1` means sales on the origin date; these historical values stay fixed across the 28 forecast days. Calendar values describe each target date. See [feature_pipeline.py](../../src/backend/scripts/feature_pipeline.py#L13) and [M5 adapter](../../src/backend/scripts/m5_sales_experiment.py#L23).

## Coverage and consequences

| Evidence | Current handling | Limitation |
|---|---|---|
| Past daily sales and product identity | Model inputs | Limited recent summaries; no category attributes for sharing patterns with new products |
| Weekday, month, day of month | Model inputs | No explicit local holiday, payday, event, or opening schedule inputs |
| Stock movements | Reconstruct end-of-day stock to classify historical evidence | Stock availability is not a feature; intraday stockout duration is not measured |
| Stockout days | Zero sales with known nonpositive ending stock are excluded | Positive sales are classified as valid before checking ending stock; selling out after some sales can still hide unmet demand |
| Closures and missing evidence | Excluded from usable history | Training requires 140 consecutive usable days; generation requires 28 recent observed days. Weekly closures can prevent either. Future closures are not applied to predictions |
| Scheduled promotions/drivers | Collected in PHP daily evidence | Export reduces history to date/product/units/availability; drivers never reach Python model inputs |
| Price and discount history | Absent from model features | No learned price or discount response; present-day prices must not substitute for historical schedules |
| Refunds and approved reversals | Deducted with dated revisions | Target is corrected recorded sales; this does not recover customers who wanted unavailable goods |
| Current stock and lead time | Used separately in replenishment planning | These help decide purchases, not prove sales-prediction accuracy |

Evidence: [daily classifications and drivers](../../src/backend/app/Forecasting/DemandHistoryService.php#L138), [history export](../../src/backend/app/Forecasting/DemandHistoryService.php#L233), [training eligibility](../../src/backend/app/Forecasting/DemandHistoryService.php#L243), [generation](../../src/backend/scripts/ml_worker.py#L270).

## Evaluation strengths and gaps

- Shared features, direct 28-day predictions, chronological holdouts, origin cutoffs, and dated corrections provide useful protections against future-data leakage.
- Production training pools product/horizon totals over three chronological 28-day holdouts. Gates require WAPE below configured limits and better than the seasonal weekly baseline; explicit approval remains separate. See [evaluation](../../src/backend/scripts/feature_pipeline.py#L129) and [worker gates](../../src/backend/scripts/ml_worker.py#L323).
- These are pooled gates, not per-product acceptance. Strong products can hide weak products. The pasted claim that every product is automatically protected against a weaker model is stronger than the implementation.
- Quantity metrics compare horizon totals. Opposing daily errors can cancel inside a product's total; inspect daily errors and sustained underprediction too.
- The worker rounds each daily prediction before persistence, while training evaluation uses fractional predictions. This can matter for slow sellers and should be evaluated on the quantities actually displayed and used.
- The previously inspected final M5 window is development evidence after follow-up choices. Additional features need validation and a fresh untouched confirmation period. Walmart history is not evidence of local Store performance.

The saved 50-product final-window results are mixed: 7-day WAPE is **43.00% versus 37.88%** for the baseline; 28-day WAPE is **29.63% versus 36.53%**. Lower is better. This supports a promising 28-day demonstration, not a general accuracy claim. See [recorded expansion results](m5-expansion-results-20261028.md). No new M5 model was trained for this review.

## Notebook versus pasted discussion

The repository notebook still has its original technical explanations. The claimed simplified descriptions, explicit feature-list introduction, MAE histogram, and selected-product comparison additions are not present as described in the pasted discussion. Its embedded shared-source parity test passes. The discussion is not an execution artifact: no updated Colab notebook or result ZIP was supplied, so this review cannot verify that run or its package fixes.

## Decisions and remaining questions

1. **Accepted by the user:** Define the target as expected recorded sales in Base Units. Treat unmet demand during stockouts as unknown unless separately supported by evidence. Keep Forecast Quantity distinct from purchase quantity, as already defined in [GLOSSARY.md](../../GLOSSARY.md).
2. **Accepted by the user:** Prioritize an honest, reproducible capstone demonstration and keep purchasing recommendations advisory while collecting local evidence. Closure handling, stockout evidence, and scheduled promotions/prices are proposed improvements to test; their detailed implementation remains to be discussed.
3. **Open:** Agree acceptance by product and supported horizon, including underforecast risk, before running another experiment. Retain aggregate reporting and the existing weekly comparison; do not change thresholds after seeing test outcomes.

The accepted decisions establish the target and demonstration scope; they do not establish accuracy thresholds or a detailed feature implementation. No new glossary definition or ADR was adopted: existing vocabulary and ADR-0005 already cover the central distinctions.

## Implemented model review summary

The user approved a simple read-only evaluation summary for the Administrator and Super Administrator. Demand Forecast now shows the approved model, the model behind displayed forecasts, the latest completed candidate's 7/14/28-day checks, and observation/pause warnings. Passing tests is explicitly separate from approval. Missing metrics or incomplete horizon evidence never appears as a passing check. Only the Super Administrator receives links to existing training and detailed governance pages; authorization and approval rules are unchanged.

The summary reuses persisted evaluations and current configured thresholds; it does not train a model, approve one, or alter stock. Tests use an in-memory database to cover roles, approval separation, missing/failed results, suspension, escaping, unavailable reads, and read-only rendering. Desktop and mobile checks render the PHP summary with existing styles in an isolated fixture; they do not establish an authenticated live workflow. No new feature columns were implemented.

## Method and verification

Inspected source and saved local reports. Passed 6 M5 selection/leakage/source-parity tests, 7 observed-history tests, 15 worker boundary tests, 3 directly invoked feature checks, and 1 training-eligibility check (32 total). The feature file uses standalone functions; unittest discovery found none, so its executable self-check was run directly. No Store database writes, production training, deployment, or live Colab execution were performed.

External references: [Forecasting: Principles and Practice, predictors known at forecast time](https://otexts.com/fpptr/forecasting-regression.html) explains scheduled versus unknown future inputs; [scikit-learn chronological evaluation example](https://scikit-learn.org/1.5/auto_examples/applications/plot_time_series_lagged_features.html) explains why time-ordered testing matters.
