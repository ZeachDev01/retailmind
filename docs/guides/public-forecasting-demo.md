# Public-data forecasting demonstration

For the separate recent-date simulation with held-out sales, current stock and
predicted-versus-actual comparisons, use the [train/test replay guide](train-test-sales-replay.md)
and port 8092. The original-date full-history Demo below remains on port 8091.

The local app runs at [Demo login](http://127.0.0.1:8091/?login=1), using only
`retailmind_demo_uci_20261020`. The Store `.env` and operational database are
unchanged. The server listens on loopback, not the network.

Verified in the browser on 2026-10-20: `UCI-20725` (LUNCH BAG RED RETROSPOT)
went from **0 stock → 2,834 suggested → 2,834 received → 0 suggested**.
The saved forecast stayed unchanged. That product is already replenished in the
current Demo; choose another out-of-stock product to repeat the receiving steps.

## Accounts

These accounts belong to this local Demo only. Password: `DemoForecast@2026`.

| Account | Use |
| --- | --- |
| `demo_super_admin` | Train, review, approve the Demo model, generate forecasts |
| `demo_inventory_manager` | Review buying guidance and record bought goods |
| `demo_admin` | Inspect operations and generate forecasts |

## Browser walkthrough

1. Sign in as **demo_super_admin**, then open **ML Operation**. The top section
   identifies UCI Online Retail, the candidate, observation count, date range,
   error scores, and quality gates. [Open ML Operation](http://127.0.0.1:8091/components/system_administrator/ml_settings.php).
2. Select **Train model**. A real `model_retraining` job enters the queue. The
   running Python worker fits Random Forest and tests it against three later
   28-day blocks and a repeating-week baseline. The page refreshes while queued
   or running. Training creates a candidate; the previous approval remains intact.
3. Review the new candidate's 7/14/28-day WAPE and baseline. Lower is better;
   WAPE is error, not accuracy. **Approve demo model** is available only for a
   local Demo candidate that passes the quality gates. It does not activate Store
   forecasting. The Demo uses the existing 35% WAPE limits and must beat baseline.
4. Select **Open forecasts**, then **Generate Now**. A separate real
   `forecast_generation` job loads the approved artifact and writes 560 daily
   forecasts: 20 products times 28 days. The page refreshes until the job finishes.
   Its 7/14/28-day columns sum those saved daily values. **Forecast Analytics**
   shows the actual model evaluations. [Open forecasts](http://127.0.0.1:8091/components/report/predictions.php).
5. Sign out, then sign in as **demo_inventory_manager**. Open **Replenishment
   Recommendations** (or follow **Review recommendations** from Demand Forecast).
   Search for an out-of-stock `UCI-` product and expand **Forecast details**.
   The basis must say **Demand Forecast**, rather than Stock rules.
   [Open recommendations](http://127.0.0.1:8091/components/inventory_management/replenishment_queue.php).
6. Review the suggested quantity. For this Demo, lead time is 7 days and the
   target includes that lead-time demand plus 28 forecast days. The suggestion
   subtracts current stock and applies order/package rounding. These are explicit
   demonstration planning settings, not facts measured in UCI.
7. The owner buys goods outside RetailMind. To demonstrate recording them, select
   **Add bought goods** on that product. The selected product carries into Stock
   Receiving. Continue to quantities, keep **Base units**, enter the actual demo
   quantity, and continue to **Review**. Review alone must not change stock.
   Shopping store, receipt reference, and cost are optional. Do not invent a real
   purchase or claim that a recommendation placed an order.
8. Select **Save receiving entry & update stock**. Confirm the success summary,
   then follow **Review replenishment recommendations**. Current stock increases
   and the suggestion recalculates. Receiving neither retrains nor generates a
   forecast. **Receiving History** retains the recorded goods, staff, and reference.

There are no purchase requests or purchase orders in the current app. Buying
occurs outside it; only committed receiving changes inventory.

## What the data means

- Real public UK online gift/wholesale transactions, not the partner Store's sales.
- 20 products, 372 calendar days, 7,440 daily observations. Product names and
  original codes are preserved with explicit `Demo:` / `UCI-` labels.
- Historical dates stay unchanged: history ends 2011-12-08. Predictions cover
  2011-12-09 through 2012-01-05. Moving them to today's dates would misrepresent
  the experiment. No future actuals exist in the source for those predictions.
- The adapter excludes return/cancellation rows and uses an explicit complete-
  archive assumption for absent daily sales. It does not establish lost demand,
  product availability, or stockouts. See [source, filtering and results](../research/public-sales-capstone.md).
- Imported daily totals live in forecasting history. They create no fake customer
  receipts, cashier transactions, stock movements, or receiving history.
- Initial inventory is explicitly zero, with separate inventory records. Stock,
  lead time, reorder level, and later received goods are a labeled Demo scenario.
  Product prices/costs start at zero placeholders; UCI pound prices are not
  silently converted into Philippine pesos. Blank receiving costs stay unknown.
- The Demo fixes the shared recipe at 15 trees, maximum depth 8, seed 42. The
  legacy operational tuning form is hidden in this Demo so it does not promise
  to change this fixed experiment. Store activation remains separately gated.

## Start again after closing the app

From repository root in PowerShell, with local MySQL running:

```powershell
./src/backend/scripts/start_public_sales_demo.ps1
```

This starts a hidden PHP development server and the actual queue worker, with
separate Demo session storage and recovery storage outside the web root. It
refuses an occupied port or an operational database name. It requires the
already prepared Demo and a completed candidate; it does not recreate, reset,
or reimport existing records. Runtime PIDs and logs are under
`.scratch/public-sales-demo/`. Close only the matching demo `php` / `python`
processes when finished. Do not run the development server as public hosting.

## Reproduce in a fresh isolated Demo

Prepare the UCI experiment using the commands in the
[dataset guide](../research/public-sales-capstone.md#run-it-locally), then:

```powershell
$env:APP_ENV='development'
$env:DATABASE_ENV='local'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='3306'
$env:DB_USER='root'
$env:DB_PASSWORD='' # your local MySQL credentials
$env:DB_NAME='retailmind_demo_uci_new' # must not already exist
$env:DEMO_PASSWORD=Read-Host 'New local Demo password (12+ characters)'
$env:BACKUP_STORAGE_PATH=Join-Path $env:TEMP 'retailmind-uci-demo-recovery'
php src/backend/scripts/demo_forecasting.php setup
python src/backend/scripts/import_public_sales_demo.py .scratch/public-sales/experiment-20261020-verified
php src/backend/scripts/demo_forecasting.php train
python src/backend/scripts/ml_worker.py --once
./src/backend/scripts/start_public_sales_demo.ps1 -Database $env:DB_NAME
```

Stop on any failing command. Use your actual prepared experiment directory.
Setup refuses an existing database; import requires an empty Demo and validates
the source, codes, dates, whole quantities, and complete product coverage before
committing products, zero opening inventory, history, and provenance atomically.
Review and approval can then be performed through the browser as above.

## Automated workflow and screenshots

The browser test trains a new candidate through ML Operation, approves it,
generates and checks saved totals, switches to the Inventory Manager, records
one reviewed delivery, and verifies the changed stock/recommendation plus
unchanged forecasts, import history, and job count during receiving. It also
captures desktop and mobile training views. It performs a real Demo receiving
mutation: run it on a fresh imported Demo with the first product still needing
replenishment, not an operating Store.

```powershell
$env:RUN_DB_TESTS='1'
# Same isolated DB environment as above; DEMO_PASSWORD must match its accounts.
node src/backend/tests/public_sales_demo_browser_test.js
```

Screenshots and the measured workflow summary are saved locally under
`.scratch/public-sales-demo/workflow/`:

- [Training and candidate review](../../.scratch/public-sales-demo/workflow/02-new-candidate.png)
- [Generated forecasts](../../.scratch/public-sales-demo/workflow/03-forecasts.png)
- [Forecast analytics](../../.scratch/public-sales-demo/workflow/04-analytics.png)
- [Buying recommendation before receiving](../../.scratch/public-sales-demo/workflow/06-buying-recommendation.png)
- [Review before saving](../../.scratch/public-sales-demo/workflow/07-receiving-review.png)
- [Receiving success](../../.scratch/public-sales-demo/workflow/08-stock-received.png)
- [Updated recommendation](../../.scratch/public-sales-demo/workflow/09-recommendation-updated.png)
- [Receiving History](../../.scratch/public-sales-demo/workflow/10-receiving-history.png)

Generated screenshots and records are local artifacts, not committed source.
This verifies the Base units demo workflow; it is not a full Store deployment or
an acceptance test for every Sales Pack, cashier, recovery, or scheduling flow.

Verification also passed the UCI importer/adapter checks, the 20-test observed-
demand/worker suite, the fresh MySQL import with repeat-import refusal, existing
receiving/forecast/Store-activation contracts, and PHP syntax/diff checks. A
browser security probe rejected invalid CSRF, Inventory Manager training access,
and a foreign Store-model approval without changing jobs or approval settings.

The browser run caught and fixed hidden damaged-pack/loose inputs being submitted
in Base units mode. Imported Demo products also now receive explicit zero-stock
inventory records, matching normal product setup. Operator/source restrictions
and Store activation gates were retained.
