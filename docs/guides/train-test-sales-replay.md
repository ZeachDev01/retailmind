# Train/test sales replay

To move this saved Demo to Hostinger, use the [SQL snapshot and hosting guide](hostinger-demo-replay.md).

Open [local Demo login](http://127.0.0.1:8092/?login=1). This separate Demo uses
`retailmind_demo_replay_20261021`; it does not change the Store database.

Password for these local accounts: `DemoForecast@2026`.

| Account | Workflow |
| --- | --- |
| `demo_super_admin` | ML Operation: inspect training, validation and candidate |
| `demo_inventory_manager` | Compare predictions, inspect stock and buying preview |
| `demo_admin` | Inspect simulated sales and reports |

## See it working

1. Sign in, then open [Train/test replay](http://127.0.0.1:8092/components/report/forecast_replay.php).
   Links also appear in ML Operation, Forecast Analytics and Replenishment Recommendations.
2. Select a product and **Compare product**. The latest 28 test dates show the
   prediction, seeded actual units and difference (prediction minus actual).
3. Follow **Simulated sales history** to inspect seeded daily sales, or **Current
   stock** to see the remaining inventory after those sales.
4. The replay's **Current stock and experimental buying preview** table shows
   current stock, expected demand and an illustrative buy quantity. For example,
   `UCI-20725` starts this scenario with 4,550 units, sells 4,550 and closes at zero.
5. Use **ML Operation** to inspect the trained candidate and its validation.
   Training again creates another candidate; this replay remains attached to its
   original frozen model. Use a fresh Demo for another reproducible experiment.

Normal buying guidance still uses Stock rules because this candidate failed
training validation. The replay preview does not approve a model or place orders.

## Training and testing

The source is [UCI Online Retail](https://archive.ics.uci.edu/dataset/352/online+retail),
real public UK transactions, under CC BY 4.0. These are not the partner Store's sales.
The original CSV files in `work/forecasting-dataset/` remain unchanged.

| Split | Original dates | Simulated dates | Daily product rows |
| --- | --- | --- | --- |
| Train | 2010-12-02 to 2011-09-15 | 2025-10-14 to 2026-07-28 | 5,760 |
| Test | 2011-09-16 to 2011-12-08 | 2026-07-29 to 2026-10-20 | 1,680 |

Both splits contain the same 20 products. Dates shift forward 5,430 days so the
last completed simulated sale is yesterday relative to setup on 2026-10-21.
Calendar features use the shifted dates: these scores describe this simulation,
not an unchanged reproduction of the original-date experiment.

Only training rows enter `forecast_sales_imports` and model fitting. The frozen
candidate predicts three consecutive 28-day test blocks. Each origin uses only
observations available by that origin; completed earlier test blocks may supply
later lag inputs. Test sales never refit the frozen model. All preview forecasts
are saved as observation jobs, separate from approved forecast-generation jobs.

Measured held-out error, pooling 60 product/origin totals at each horizon:

| Horizon | Model WAPE | Repeating-week baseline WAPE |
| --- | --- | --- |
| 7 days | 40.50% | 42.71% |
| 14 days | 38.69% | 44.85% |
| 28 days | 39.68% | 45.36% |

WAPE measures error; lower is better. Do not describe it as accuracy. Despite
beating this baseline on the held-out replay, the candidate's earlier training
validation failed: 7-day WAPE was 50.83%, versus baseline 49.68%. Existing quality
gates remain unchanged. There is no approved model in this replay Demo. These
test scores have now been inspected; further tuning needs a fresh holdout for an
unbiased final evaluation.

## Meaning of seeded stock and sales

Opening stock deliberately pre-funds all test consumption plus an illustrative
closing balance (cycling through 0, 0.5, 2 or 6 last-training-week quantities).
This uses known test consumption to construct inventory; it does not evaluate
an ordering policy or prove stockout prevention. Actual public quantities drive
the simulated sales. Stock, batches, movements and product counters reconcile.

Daily aggregates are labelled `SIMULATED-UCI-*`, not original customer baskets.
Prices, revenue and cash are zero placeholders; purchase costs are unknown.
This is a quantity-only demonstration. Future demand has no known actuals yet.
The preview reuses normal lead-time, buffer, minimum-order and package rules,
but its suggested quantities remain unapproved experiments.

## Restart and reproduce

Restart the prepared Demo with local MySQL running:

```powershell
./src/backend/scripts/start_public_sales_demo.ps1 -Database retailmind_demo_replay_20261021 -Port 8092
```

For a fresh replay, choose unused output and database names. Stop on any failing command:

```powershell
python src/backend/scripts/public_sales_replay.py work/forecasting-dataset --end-date 2026-10-20 --output-dir .scratch/replay-new
$env:APP_ENV='development'
$env:DATABASE_ENV='local'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='3306'
$env:DB_USER='root'
$env:DB_PASSWORD='' # use your local MySQL credentials
$env:DB_NAME='retailmind_demo_replay_new' # must not already exist
$env:DEMO_PASSWORD=Read-Host 'New local Demo password (12+ characters)'
$env:BACKUP_STORAGE_PATH=Join-Path $env:TEMP 'retailmind-replay-new-recovery'
php src/backend/scripts/demo_forecasting.php setup
python src/backend/scripts/import_public_sales_demo.py .scratch/replay-new
php src/backend/scripts/demo_forecasting.php train
python src/backend/scripts/ml_worker.py --once
python src/backend/scripts/public_sales_replay.py .scratch/replay-new --seed
./src/backend/scripts/start_public_sales_demo.ps1 -Database $env:DB_NAME -Port 8092
```

The end date must be a completed Philippine date. Use another free port if 8092
is occupied. Preparation refuses an existing output directory; setup refuses an
existing database; seeding refuses existing operational sales or stock activity.
Sessions, recovery storage and runtime logs are isolated per Demo database.

## Verification

Preparation and evaluation tests cover date shifts, disjoint splits, gaps,
frozen-model evaluation and future-data isolation:

```powershell
python -m unittest discover -s src/backend/tests -p 'test_public_sales_replay.py'
```

The browser check uses the seeded Demo without changing its business records:

```powershell
$env:RUN_DB_TESTS='1'
$env:DB_NAME='retailmind_demo_replay_20261021'
$env:DB_HOST='127.0.0.1'
$env:DB_USER='root'
$env:DB_PASSWORD=''
$env:DEMO_PASSWORD='DemoForecast@2026'
node src/backend/tests/public_sales_replay_browser_test.js
```

It verifies training/test boundaries, stock ledgers, prediction counts, comparison
arithmetic, product selection, unchanged approval gates and desktop/mobile views.
Screenshots: [desktop](../../.scratch/public-sales-replay-browser/replay-desktop.png)
and [mobile](../../.scratch/public-sales-replay-browser/replay-mobile.png).
This is a local demonstration, not a Store deployment or a full cashier audit.
