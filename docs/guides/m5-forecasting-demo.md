# M5 forecasting demonstration

Prepared 2026-10-29 (Asia/Manila). This is a separate local Demo, not the operational Store.

## Open it

- [Demo login](http://127.0.0.1:8093/?login=1).
- Username: `demo_inventory_manager`; password: `M5DemoLocal@2026`.
- [Four-column historical M5 comparison](http://127.0.0.1:8093/components/report/forecast_replay.php#replay-stock).
- [Normal replenishment screen](http://127.0.0.1:8093/components/inventory_management/replenishment_queue.php).
- To inspect the real training candidate, sign in as `demo_super_admin` with the same Demo password, then open [ML Operation](http://127.0.0.1:8093/components/system_administrator/ml_settings.php).

The historical page shows Product, On hand, Stock-rule suggestion, and Expected sales for 2016-04-25 through 2016-05-22. It also shows actual-versus-predicted daily sales and the measured experiment's errors. Product selection uses the normal searchable dropdown.

Stock starts at zero as an explicitly illustrative scenario. Reorder level is 20, safety stock 10, package/minimum quantity 1, and lead time 7 days. Therefore an untouched product's stock-rule suggestion is 30 units; its expected sales are a separate model quantity. Prices/costs are placeholders. The Inventory Manager decides what to buy; no purchases or receiving entries were created.

## What is real, and what is illustrative?

- M5 CA_1 food sales retain their original dates and anonymous product codes. There are 50 products and 40,700 daily observations (2014-03-01 through 2016-05-22).
- The adapter imports daily sales evidence, not fake customer receipts, cashier sales, deliveries, batches, or stock movements.
- A real isolated worker trained a candidate through the existing Random Forest pipeline. Training finished; its validation failed the existing quality gates. It was not approved.
- The historical preview imports the experiment's 4,200 saved held-out daily predictions across three folds. Each fold used earlier data; this is expanding-window backtest evidence, not a single frozen model and not a new current forecast. Final test metrics cover the last 28 days and all 50 products.
- The display rounds daily predictions to whole units; metric tables retain the experiment's unrounded results. Small differences between summed display values and metric calculations are expected.
- The M5 Demo replenishment page shows these saved expected quantities beside independent stock rules, explicitly labelled with their 2016 dates and `Historical demo · unapproved`. Its historical-preview link opens the detailed actual-versus-predicted comparison. This display exception requires a prepared M5 preview in an explicitly allowed Demo database; operational Store forecasts still require current, complete, approved output.
- Receiving goods manually in the Demo would change its illustrative stock; it does not retrain the model or change the saved historical predictions.

Candidate validation pools all three chronological blocks: WAPE 41.65%, 37.97%, and 35.30% for 7/14/28 days. These differ from final-test-only metrics shown in the historical preview. The 7-day candidate also loses to the weekly baseline. The existing 35% limits were not lowered.

## Google Colab

Open [Google Colab](https://colab.research.google.com/) and select **File → Upload notebook**. Upload [m5_random_forest_colab.ipynb](../research/m5_random_forest_colab.ipynb), choose CPU (no accelerator), then **Runtime → Run all**. If package installation requests a restart, restart and run all again. The notebook defaults to 50 products. Download its result ZIP from the final cell before closing the runtime.

Colab executes the research notebook; it does not need the local Demo database and does not become the RetailMind server. This session verified local runs, not execution inside Google Colab. [Google's notebook upload/runtime guidance](https://research.google.com/colaboratory/faq.html).

## Local isolation and restart

The Demo database is `retailmind_demo_m5_20261029` on a separate loopback MySQL instance, port 3307. Its new data directory is `.scratch/m5-demo-mysql-20261029`. PHP listens only on `127.0.0.1:8093`. Root `.env`, the ordinary database configuration, and the Store database were not changed. Demo sessions/recovery storage use separate directories outside the served frontend.

The database server and application are currently running. After a machine restart, start this prepared MySQL instance if port 3307 is free:

```powershell
$demoMysql = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64'
$demoData = 'C:/laragon/www/retailmind/.scratch/m5-demo-mysql-20261029'
if (-not (Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath "$demoMysql/bin/mysqld.exe" -ArgumentList @(
        "--basedir=$demoMysql", "--datadir=$demoData", '--port=3307',
        '--bind-address=127.0.0.1', '--mysqlx=OFF', "--log-error=$demoData/server.log"
    ) -WindowStyle Hidden
}
```

Once MySQL is ready, start the app from the repository root if port 8093 is free:

```powershell
$env:DB_PORT = '3307'
./src/backend/scripts/start_public_sales_demo.ps1 -Database retailmind_demo_m5_20261029 -Port 8093
```

Do not reinitialize the data directory or rerun setup against an existing database. The setup/import/preview commands refuse replacement; choose fresh names for another experiment. Runtime details and logs are under `.scratch/public-sales-demo/retailmind_demo_m5_20261029/` and `.scratch/m5-demo-mysql-20261029/`.

## Verification

Four importer checks and fifteen worker boundary checks pass. The real MySQL setup, 40,700-row import, actual candidate training, and 4,200-prediction preview completed locally. The M5 browser check signs in as Inventory Manager, verifies original dates and 50 products, compares saved forecast totals, uses the searchable product selector, checks desktop/mobile and the labelled historical quantities on the replenishment screen, and confirms no stock, jobs, source history, or approval changes from review. `php src/backend/tests/replenishment_demo_preview_contract.php` checks that this display exception cannot apply to a Store database and that incomplete or disabled previews remain unavailable.

Screenshots and verification state are under `.scratch/m5-demo-browser/`. This does not verify Store activation, all receiving forms, or execution inside Colab.
