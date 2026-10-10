# Demo demand dataset

## Source and verification status

The user supplied `src/backend/dataset/retail_store_inventory.csv`, associated
with [Anirudh Chauhan's Retail Store Inventory Forecasting Dataset](https://www.kaggle.com/datasets/anirudhchauhan/retail-store-inventory-forecasting-dataset).
This is not the four-column Store Item Demand Forecasting Challenge dataset.

On 2026-10-10, the publisher page returned no readable dataset description.
Publisher methodology, license, redistribution rights, and whether observations
are real or synthetic remain **unverified**. The source association is
user-provided, not independently authenticated. All results are **Demo evidence**;
they establish neither real Store accuracy nor readiness for Store activation.

## Inspected file

- SHA-256: `c7b6019887d85e6bb311a76da72560463e6bf269bfadbe4b326fbff3b1000468`.
- 73,100 rows; stores S001–S005; products P0001–P0020.
- 2022-01-01 through 2024-01-01 inclusive (731 days).
- Initial scope: S001, all 20 products, 14,620 rows; 731 dates per product.
- No duplicate Date/Store ID/Product ID keys or blank cells in this file.
- Columns: Date, Store ID, Product ID, Category, Region, Inventory Level,
  Units Sold, Units Ordered, Demand Forecast, Price, Discount, Weather Condition,
  Holiday/Promotion, Competitor Pricing, Seasonality.

## Import and meaning

`src/backend/scripts/import_kaggle_capstone.php` selects S001 and P0001–P0020
into an explicitly isolated Demo database. Product codes map to internal IDs
1–20; imported sale quantities represent the CSV's **Units Sold**. The importer
records source hash, date range, and selection in `demo_import_metadata`.
The generic Demo category is a display label, not an observed product attribute.

## Feature exclusions and evidence limits

Use dates, product identity, and historical Units Sold for demand features and
targets. Exclude the supplied Demand Forecast column from both. Category varies
within each product and Region varies within S001; neither is a stable attribute.
Future CSV prices, discounts, and promotions do not establish advance knowledge
at a forecast origin and must not enter features. Inventory Level has ambiguous
timing and does not establish stockout status. Weather, competitor pricing, and
seasonality labels are excluded.

Store opening/closure calendars, stockout evidence, and scheduling evidence stay
unknown. A recorded zero Units Sold is an observed Demo sales count, not proof
of zero unconstrained demand. Missing daily observations must not become zeros
or substitute lag values. Real Store activation requires separately collected
Store evidence, separate models, and the existing technical/operational gates.

## Reproducible training and evaluation

The queue worker reads the imported S001 observations from the selected database,
restricted to importer cashier 999 and product IDs 1–20. It checks recorded
product coverage, row count, and date range before training. It never loads the
CSV into a Store database. Explicit process environment settings take precedence
over `.env`, including the database selected for an isolated run.

`feature_pipeline.py` is shared by training and inference. Product identity is
categorically encoded; numerical inputs are horizon day, sales lags 1/7/14/28,
7/28-day rolling mean/standard deviation, and date-derived calendar fields.
Lag 1 means the origin day's observation. No price, promotion, supplied forecast,
category, region, guessed demand, or prediction fallback enters this contract.

Training samples weekly origins with 28 daily labels each. Three successive
28-day chronological holdouts cover all 7/14/28-day horizons. Each fold fits
only origins whose entire label window is observable by its cutoff. Evaluation
compares each product's horizon total with held-out Units Sold and a repeating
last-observed-week baseline; metrics aggregate product/origin totals. A final
refit uses eligible observed labels, with its actual training window recorded
separately from the full evaluation evidence window. Missing quality thresholds
or undefined WAPE leave the gate incomplete; passing requires beating the
weekly baseline as well as meeting the configured threshold.

Artifacts are separated under `storage/ml_models/{demo|store}/{database-identity-hash}`.
Demo versions start with `demo_`; artifact metadata and forecast limitation
markers identify Demo evidence. Demo generation can use the latest completed
Demo candidate for experimentation at the dataset's final observed date. Store
generation requires an explicitly approved Store version and matching artifact
identity/feature contract. Old synthetic artifacts require retraining.

Store evidence is exported through `DemandHistoryService`. Each observation
retains availability-dated revisions for refunds, reversals, and calendar edits.
Features use revisions known at their forecast origin; training labels use
revisions known at their fold cutoff. Withdrawn/unknown evidence stays missing.
Store jobs conservatively use the previous completed Philippine calendar day;
the current day is excluded even for an after-close manual run. The initial
contract requires complete 28-day history and target windows: missing days,
closures, or censored stockouts can make a candidate unusable rather than
silently becoming zero demand. Usable days alone count toward readiness.

## Checks

From the repository root:

```text
python -m unittest discover -s src/backend/tests -p "test_observed_*.py"
python src/backend/tests/test_feature_pipeline.py
php src/backend/tests/demand_history_contract.php
```

For the real CSV/importer/queue path, set `RUN_DB_TESTS=1` and run
`python src/backend/tests/observed_demand_mysql_test.py`. It needs local MySQL
credentials with permission to create/drop a disposable test database, PHP,
and the installed Python forecasting dependencies. It creates its own uniquely
named Demo test database and temporary artifact/recovery directories, then
cleans them up. It does not use an existing Store database.

The 2026-10-10 MySQL check imported 14,620 observations and persisted 560 daily
Demo forecasts. This is pipeline verification, not real Store validation.
The repository-wide `src/backend/tests/run_all.sh` was empty at verification
time, so invoking it provided no full-suite coverage.
