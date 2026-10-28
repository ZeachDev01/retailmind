# UCI II demonstration and later Store training

Agreed on 2026-10-28: use UCI Online Retail II in Google Colab to show the forecasting process, then refine RetailMind's own sales-to-training flow. Explain the work in simple English. Do not promise a good score before testing.

Later on 2026-10-28, the user chose M5 as the main demonstration instead. See [the current discussion](m5-vs-uci-panel-discussion.md). The UCI II notebook and results remain supporting evidence; the Store-training boundaries below still apply.

## The panel demonstration

Upload [the notebook](uci_online_retail_ii_colab.ipynb) to [Google Colab](https://colab.research.google.com/). Choose a CPU runtime, then **Runtime → Run all**. The last cell downloads a ZIP with the charts and results. Save it before Colab closes.

The notebook shows these steps:

1. Download real public sales from [UCI Online Retail II](https://archive.ics.uci.edu/dataset/502/online+retail+ii).
2. Read both yearly worksheets and handle repeated records across them.
3. Keep positive product sales, leave out cancellation/return records, and select 20 active products using only earlier sales.
4. Train the same Random Forest recipe used by RetailMind.
5. Predict later sales and compare with both actual sales and a simple last-week rule.
6. Explain good and weak results, then save the evidence.

The source covers December 2009 to December 2011. Saving 84 days for checking leaves less than two full training years. The first two 28-day blocks are validation; the last is the final test for this run. Model settings stay fixed. Its late-2011 test period overlaps the older UCI experiment, so this is not an independent confirmation of those earlier results.

These are UK online gift/wholesale sales. This dataset is not a physical grocery Store and has no Store identifier to select. We do not rename it as Store history or use its model for Store products. Customer details are excluded from experiment outputs.

## Simple meanings

- **Training:** the computer learns patterns from older sales.
- **Forecast:** an estimate of how many units may sell later.
- **Testing:** compare a forecast with sales that really happened later.
- **Baseline:** a simple comparison rule; here, repeat the last week's sales.
- **MAE:** the average mistake in units. Lower is better.
- **WAPE:** total absolute mistakes compared with total sales. Lower is better; this is not an accuracy percentage.
- **Precision, recall, F1:** scores for the separate question “will anything sell on this day?” They do not measure how close the predicted quantity is.

## Later: learning from this Store's sales

This is the next integration stage, not a claim that the live system is activated.

1. Cashiers record real sales through RetailMind. The daily history reader uses Store sales and stock/calendar evidence. Unknown days must not quietly become zero-sale days.
2. Check whether each product has enough usable history. “Enough” includes data quality and later dates for testing, not just a large transaction count.
3. Once enough evidence exists, the scheduled background worker trains a new candidate. The existing design calls for weekly training after close. It must not train inside a cashier's sales request.
4. Test the candidate against later sales and the baseline. Show the results to the Super Administrator.
5. Use an approved Store model to generate forecasts after close. Staff review buying suggestions; the forecast never changes stock or buys goods.
6. If the candidate fails, keep the previous approved model or existing stock rules. Show why the candidate is not ready.

**Automatic training is not automatic approval.** A candidate can be trained on schedule while human review controls whether it is used. Colab is for the demonstration; automatic Store training needs the server or Store PC worker and scheduler running.

The existing readiness label starts at 56 history days, but the full three-block backtest needs at least 140 complete consecutive usable days: 56 for initial fitting and 84 for checking. Missing or excluded days can require more calendar time. At 180 days the readiness band is Medium; High needs at least 365 days and acceptable validation error. None of these counts guarantees accurate forecasts.

## Existing code to refine rather than replace

- `DemandHistoryService`: reads actual Store sales, calendar, and stock evidence.
- `ForecastingSchedulerService`: queues daily forecast and weekly training jobs; verify readiness before scheduling training and avoid repeated unusable jobs.
- `ml_worker.py` and `feature_pipeline.py`: fit and test candidates outside web requests.
- `CandidateEvaluationService` and `ModelPromotionService`: enforce quality checks and approval.
- `StoreActivationService`: keeps observation and operational activation separate.

Integration review must verify sales corrections, product eligibility, missing days, genuine zero sales, stockouts, worker availability, duplicate job prevention, failed training, model approval, and the fallback shown to staff. Reuse the current services and existing tests. No new forecasting engine is needed.

## Verification status

The notebook's Python cells compile. Three focused checks cover both worksheets, overlap handling, preserved repeated lines, selection without future sales, missing worksheet failure, and exact shared-source parity. A local run is a local run; only an actual Google Colab execution can be described as Colab-tested. Store automatic training and activation are not enabled by this document or notebook.

The full notebook data-loading, training, result-export, and chart cells completed locally. [Measured results and explanation](uci-ii-results-20261028.md): the 7- and 14-day forecasts beat the simple rule on average unit error; the 28-day forecast is slightly worse. Sale-day detection is strong, but quantity errors remain too high to infer Store approval. Colab installation, execution, and result download still need to run in Colab itself.
