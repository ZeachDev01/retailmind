# Hugging Face product sales history

Research checked: 2026-10-27. Agreed scope: an isolated forecasting experiment using one external Walmart store (`CA_1`) and food products (`FOODS`). This does not provide sales history for the RetailMind Store.

## Source

- [Hugging Face M5 daily dataset](https://huggingface.co/datasets/skforecast/m5_daily): daily `sales` units by `datetime`, `store_id`, and `item_id`; 59,181,090 rows overall. Filter `store_id = CA_1` and `cat_id = FOODS` before training.
- [Original M5 competition](https://www.kaggle.com/competitions/m5-forecasting-accuracy/data): Walmart product/store daily unit sales with calendar and prices. Its data page says use is subject to competition rules. The Hugging Face page labels its copy CC BY 4.0; verify the original terms before redistributing a subset.

Predict expected units sold as the experiment's demand-forecast target. Keep external item IDs, dates, and models separate from operational Store IDs, imported Store history, and approved models. Evaluate on later dates only. Sales are recorded units, not proof of unconstrained demand during stockouts.

Start with 20 food products that each have at least two years of sales history, as agreed and consistent with [ADR-0005](../adr/0005-single-random-forest-forecasting-pipeline.md). Product-selection criteria and the final experiment run remain to be settled.

The M5 product codes may remain anonymous; do not map them to the Store's Products. The evaluation goal is a Demo pipeline using the existing forecasting engine. Compare expected versus actual units with MAE and WAPE on later dates and against a simple baseline. Also report precision, recall, and F1 for whether any unit sells on a day; those classification scores do not measure quantity accuracy. Public-data scores alone cannot establish reliability for the Store. Pass criteria remain open.

The current UCI Demo already trains and forecasts through the application. Its experiment preparation and importer are UCI-specific, so an M5 source adapter is needed to feed the shared pipeline. Use a separate M5 Demo database and retain the UCI Demo.

## Discussion update — 2026-10-28

The user confirmed an experiment-first sequence: run and inspect the public dataset before fitting the system integration. The operational Store will collect its own sales, then train and produce forecasts when enough usable evidence exists. This means training a Store-specific candidate, not applying the M5 model directly to Store products. Existing model approval and advisory replenishment boundaries remain in effect.

Use one source Store and Git branch `experiment/m5-colab`; sample 20 eligible food products with seed 42. Use two full calendar training years followed by 84 days: two 28-day validation blocks and one final 28-day test. Evaluate sale-day occurrence separately from quantity accuracy. The executable [Colab notebook](m5_random_forest_colab.ipynb) bundles the shared source; [measured local results](m5-results-20261028.md) are available. Colab execution remains pending because this session has no connected browser. A local run does not establish a Colab run.

### Formulas and interpretation

For Random Forest regression, the predicted sales quantity is the average tree prediction: `y_hat(x) = sum(tree_b(x), b=1..B) / B`. See [RandomForestRegressor](https://scikit-learn.org/stable/modules/generated/sklearn.ensemble.RandomForestRegressor.html).

For `N` evaluated observations, with actual sales `y_i` and predictions `y_hat_i`:

- `MAE = sum(abs(y_i - y_hat_i)) / N`: average absolute error in units; lower is better.
- `RMSE = sqrt(sum((y_i - y_hat_i)^2) / N)`: error in units with greater weight on large misses; lower is better.
- `WAPE = 100 * sum(abs(y_i - y_hat_i)) / sum(y_i)`: aggregate percentage error; undefined when total actual sales is zero. Report that case explicitly.

MAE and RMSE definitions: [scikit-learn evaluation guide](https://scikit-learn.org/stable/modules/model_evaluation.html). These assess quantities; classification scores assess the separately agreed any-sale-day event:

- Actual positive: `y_i > 0`; predicted positive: `y_hat_i >= tau`, where `tau` is selected using validation data and frozen before testing.
- `Precision = TP / (TP + FP)`: how often predicted sale days actually have sales.
- `Recall = TP / (TP + FN)`: how many actual sale days are detected.
- `F1 = 2 * TP / (2 * TP + FP + FN)`: balance of precision and recall. F1 and F1 score are the same metric.

See [classification metrics](https://scikit-learn.org/stable/modules/model_evaluation.html#precision-recall-f-measure-metrics). Report positive counts, confusion counts, and undefined denominators. High any-sale-day F1 does not establish accurate quantities or reliable stockout alerts.

### Proposed experiment and discussion outputs

- Select 20 eligible `CA_1` / `FOODS` products reproducibly, without using held-out sales to select easy cases. Keep zero-sale days; distinguish missing observations from observed zero sales.
- Two years means initial training history plus later validation/test periods. One external Store and one Git branch are used; the existing model design remains one global Random Forest.
- Use a CPU Colab notebook for exploration and artifact export, following the shared feature/forecast design. Colab runtimes and resources are temporary and variable: [Google Colab FAQ](https://research.google.com/colaboratory/faq.html).
- Compare against the existing simple baseline on chronological rolling origins for 7-, 14-, and 28-day horizons. Keep all training labels before each validation/test origin; compute features using only information available at that origin. Do not shuffle product/date rows across splits. See [time-series evaluation example](https://scikit-learn.org/stable/auto_examples/applications/plot_time_series_lagged_features.html).
- Report baseline and model metrics per product and horizon, alongside aggregate results. Discuss actual-versus-predicted plots, over/underprediction, sparse sales, large misses, and baseline-relative improvement. Do not invent result interpretations before a run.
- Investigate possible weaknesses rather than assume them: sparse sales, limited annual cycles, unobserved stockouts, unavailable future prices, and differences between Walmart and the operational Store. Prioritize improvements supported by held-out errors; keep the final test set untouched during tuning.
- Integrate through the isolated M5 Demo and existing forecast/replenishment flow. Public-data performance cannot certify operational Store accuracy. Replenishment stays advisory and requires human review, as ADR-0005 specifies.

The first experiment fixes the existing feature/model recipe before testing and selects the auxiliary sale-day threshold on validation data only. Baseline-relative acceptance criteria and the later operational integration remain open; do not infer approval from a favorable metric. The full shared three-block backtest needs at least 140 complete consecutive days (56 for initial fitting plus 84 for evaluation), even though the readiness label becomes Low at 56. More observations and acceptable held-out error are needed to establish reliability.
