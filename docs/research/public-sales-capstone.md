# Real public sales data for the capstone

Research checked: 2026-10-20. Decision: use real public transactions for the
forecasting experiment; retain synthetic data for software tests. Neither
dataset represents this Store's customers.

The experiment now also feeds an isolated in-app Demo:
[browser training, forecasting, recommendations, and receiving](../guides/public-forecasting-demo.md).

## Dataset and attribution

Use **UCI Online Retail**. UCI describes actual transactions from a UK online
retailer selling gifts, with many wholesale customers: 541,909 rows dated
2010-12-01 through 2011-12-09. This is real sales evidence, but its business
differs from a Philippine grocery Store. [Official dataset](https://archive.ics.uci.edu/dataset/352/online+retail)

- [Official ZIP download](https://archive.ics.uci.edu/static/public/352/online%2Bretail.zip)
  contains `Online Retail.xlsx` (22.6 MB).
- Fields: `InvoiceNo`, `StockCode`, `Description`, `Quantity`, `InvoiceDate`,
  `UnitPrice`, `CustomerID`, `Country`. An invoice beginning with `C` denotes
  cancellation. [Schema](https://archive.ics.uci.edu/dataset/352/online+retail)
- License: CC BY 4.0. Cite: Chen, D. (2015). *Online Retail* [Dataset]. UCI Machine
  Learning Repository. [DOI: 10.24432/C5BW33](https://doi.org/10.24432/C5BW33).
  Attribute UCI and the creator, retain the license reference, and describe
  filtering and aggregation as modifications. [License](https://creativecommons.org/licenses/by/4.0/)

The workbook was downloaded from UCI. SHA-256:
`43465a06f2ccf7c8b5bd2892bc7defb52f97487934fe93b16ae4c3936424676d`.

## Keep the existing synthetic dataset separate

The publisher's current metadata explicitly describes the existing Kaggle
Retail Store Inventory Forecasting Dataset as synthetic. Its API reports
`licenseName` as `CC0: Public Domain`. This updates the older verification status
in [the existing dataset note](kaggle_capstone_dataset.md); it does not prove that
any locally supplied file is byte-identical to a publisher download.
[Publisher page](https://www.kaggle.com/datasets/anirudhchauhan/retail-store-inventory-forecasting-dataset),
[first-party metadata API](https://www.kaggle.com/api/v1/datasets/view/anirudhchauhan/retail-store-inventory-forecasting-dataset).

Use that synthetic dataset for repeatable software tests and labeled
demonstrations. Do not describe its forecasting results as real sales validation
or combine it with UCI transactions for the experiment.

## Experiment contract

These are implementation choices, not facts supplied by UCI. The isolated CLI is
`src/backend/scripts/public_sales_experiment.py`; its input is the downloaded
workbook and its output directory must be fresh.

1. Preserve the raw file and record its source URL, hash, retrieval date, filters,
   selected product codes, date boundaries, and excluded-row counts.
2. Predict **recorded positive units sold**, not revenue, supplier purchases, or
   unconstrained demand. Retain positive quantity and price, reject invoices
   beginning with `C`, and accept product codes matching five digits with optional
   trailing letters. This is gross recorded positive sales: cancellation records
   are excluded, not matched back to original purchases to calculate net sales.
   Administrative lines outside that code rule are excluded. Customer identifiers
   are unnecessary for this task.
3. Aggregate each retained `StockCode` and invoice date into `date, product_id,
   units_sold`, matching the shared feature pipeline. Aggregate the whole retailer
   across countries; there is no Store ID to select. Remove the first and last
   archive dates conservatively as potentially partial boundaries. Keep external
   product IDs separate from live Store Product IDs.
4. Select products using only the initial training period, before the first
   chronological holdout. Freeze that product list for all folds. Selecting
   best-selling products using the full year's totals would reveal test-period
   information. Specifically, retain up to 20 products present on the first
   retained date, ranked by number of sale dates before the final 84-day
   evaluation period, with product code as deterministic tie-breaker. Results
   describe this active early-product cohort, not all retail products.
5. A day without a retained transaction can become zero **only under an explicit
   external-experiment assumption**: the archive records the relevant sales for
   that day and the selected product is treated as observable during the chosen
   interval. A zero means no recorded retained sale; it does not establish that
   the shop was open, the product was listed, or stock was available. Never apply
   this assumption to missing Store history. There is no verified stockout or
   operating-calendar evidence in this experiment.
6. Reuse [feature_pipeline.py](../../src/backend/scripts/feature_pipeline.py),
   including its three successive chronological 28-day holdouts, fully observed
   training-label windows, and 7/14/28-day horizon totals. Compare Random Forest
   with its repeating last-observed-week baseline. Report MAE, RMSE, WAPE, fold
   dates, and product count; disclose worse results as well as improvements.
   These are horizon-total metrics, not daily percentage accuracy.
7. Keep experimental data, models, and reports outside the operational Store
   database and approval settings. Do not relabel this as certified Store
   history or use its results to enable Store forecasting. Outputs are
   `daily_history.csv`, `products.csv`, `metrics.json`, `model.joblib`, and
   `forecasts.csv`, explicitly marked as an external experiment rather than
   worker-importable Store evidence.

This follows [ADR-0005](../adr/0005-single-random-forest-forecasting-pipeline.md):
one shared Random Forest forecasting design, chronological evaluation, and
isolated public/demo evidence. No second production forecasting engine is needed.

## Wording for the capstone

> The Store did not have sufficient historical product-level sales records.
> We therefore developed and evaluated the forecasting pipeline using real,
> publicly available UCI Online Retail transactions. The experiment measures
> performance on the selected external products, not forecast accuracy for the
> partner Store. Synthetic data was used separately for software tests. The
> operational system collects product-level sales for later Store-specific
> training and validation.

Do not claim a measured accuracy before running the chronological experiment.
Even a successful external experiment does not validate Philippine grocery
demand, stockout-adjusted demand, or this Store's replenishment decisions.

## Run it locally

From the repository root in PowerShell, with the existing forecasting packages
installed (`numpy`, `pandas`, `scikit-learn`, `joblib`):

```powershell
python -m pip install openpyxl
New-Item -ItemType Directory -Force .scratch/public-sales | Out-Null
Invoke-WebRequest -Uri 'https://archive.ics.uci.edu/static/public/352/online%2Bretail.zip' -OutFile .scratch/public-sales/online-retail.zip
Expand-Archive -LiteralPath .scratch/public-sales/online-retail.zip -DestinationPath .scratch/public-sales/raw
python src/backend/scripts/public_sales_experiment.py '.scratch/public-sales/raw/Online Retail.xlsx' --output-dir .scratch/public-sales/experiment-new
```

Use a fresh output name for each run; existing result directories are refused.
If the workbook is already downloaded, only run the final command. The CLI
never loads `.env`, connects to MySQL, imports Store evidence, or approves a model.
Keep the workbook unchanged. Generated files stay local under the ignored
`.scratch/` directory; the script and this note are the reproducible recipe.

- `metrics.json`: source hash, assumptions, selection, software versions, features,
  model parameters, fold dates, RF and baseline metrics.
- `daily_history.csv`: 20 external product series; Excel can open this CSV.
- `train.csv` and `test.csv`: chronological daily observations, using the final
  84 days for testing. Ready-made files and usage notes are in
  [work/forecasting-dataset](../../work/forecasting-dataset/README.md).
- `products.csv`: original external codes and descriptions from the training period.
- `forecasts.csv`: 28 daily expected-unit predictions per product beyond the archive.
  Fractional units are model expectations, not actual transactions or order quantities.
  No matching future actuals exist in this archive, so these rows are not test scores.
- `model.joblib`: fitted external experiment artifact; do not copy it into the
  Store worker or load untrusted joblib artifacts.

## Measured result on 2026-10-20

The unchanged model configuration (15 trees, depth 8, seed 42) trained through the
shared engine on the downloaded workbook. Retained history covers 2010-12-02
through 2011-12-08: 372 calendar days, 20 products, 7,440 daily rows. Of the
541,909 source transaction rows, 523,043 pass the product/sales/boundary filters;
27,089 belong to the selected cohort. Remaining daily grid entries follow the
explicit zero-recorded-sales assumption above, not Store observation rules.

Selection ends 2011-09-15. Three successive evaluation blocks are 2011-09-16
through 2011-10-13, 2011-10-14 through 2011-11-10, and 2011-11-11 through
2011-12-08. Each fold refits using only labels observable before that block.
Settings were not tuned against these results.

Metrics below pool 60 product/origin totals (20 products times three origins)
for each horizon. MAE and RMSE are units per horizon total, not daily units.
WAPE is weighted absolute percentage error; **100% minus WAPE is not accuracy**.

| Horizon | RF MAE | RF RMSE | RF WAPE | Baseline MAE | Baseline RMSE | Baseline WAPE |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 7 days | 144.56 | 237.06 | 30.74% | 200.83 | 339.55 | 42.71% |
| 14 days | 269.84 | 439.42 | 31.19% | 388.05 | 633.80 | 44.85% |
| 28 days | 638.11 | 1300.96 | 32.48% | 891.22 | 1495.49 | 45.36% |

RF improves all pooled metrics here. Results vary by fold: the final fold's
28-day RMSE is 1558.52 versus baseline 1555.68, so it does not win every
comparison. No Store quality gate or business acceptance threshold is inferred
from this experiment.

The final fit produces 560 future daily rows for 2011-12-09 through 2012-01-05.
The artifact records final training label coverage separately from the overall
data window. Runtime: Python 3.14.5, pandas 2.3.3, NumPy 2.4.4,
scikit-learn 1.9.1, and openpyxl 3.1.5.

Software checks:

```powershell
python -m unittest discover -s src/backend/tests -p 'test_public_sales_experiment.py'
python -m unittest discover -s src/backend/tests -p 'test_observed_*.py'
python src/backend/tests/test_feature_pipeline.py
python src/backend/scripts/test_ml_cv.py
python src/backend/scripts/test_ml_evaluation.py
```

The measurements above were first obtained by the offline experiment. The
separate browser guide records its later in-app integration and checks; neither
establishes accuracy for the partner Store.
