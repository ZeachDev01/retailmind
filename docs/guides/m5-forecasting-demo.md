# M5 forecasting demonstration

Verified locally for #421 on 2026-11-04 (Asia/Manila). This is the fresh isolated Demo; October databases, passwords and Laragon paths in earlier guide revisions are obsolete.

## Four-role panel walkthrough

Start at [Demo login](http://127.0.0.1:8093/?login=1) and open one browser profile per role. Use the Demo-only accounts below.

1. **Super Administrator** shows accounts and role permissions, opens Import Sales History, and previews the three-row `m5-import-preview.csv` in the Demo home. Discard the preview after showing the validated rows. The full M5 history is already loaded.
2. **Super Administrator** requests training from the current **Model Training** page and points out the new job ID. Continue to the next roles while Python runs.
3. **Administrator** opens **Model Review** to show candidate checks and the Store approval boundary.
4. **Inventory Manager** shows stock-rule recommendations and records Demo stock receiving. For the live sale, use a Demo item with a selling price and barcode. **Cashier** opens a shift on **Demo Front Counter**, sells the received item, and shows its receipt. This sequence creates Demo inventory and POS records.
5. Return to the training job and open **Historical M5 comparison** or **Forecast Analytics**. Identify the live job by ID and label the stored historical replay as prepared evidence.

The expanded Administrator workflow, including Administrator import, training, configuration, and Analytics, is tracked in [issue #499](https://github.com/ZeachDev01/retailmind/issues/499). The restored checkout uses the existing Super Administrator pages for those steps.

On 2026-11-23, all four Demo accounts reached their role pages, the three-row CSV preview opened, and the Cashier could select Demo Front Counter. The running worker processed rehearsal job **#6** in 39 seconds. The candidate failed quality checks and no model was approved. The rehearsal did not submit receiving or checkout.

## Open the prepared Demo

Open [Demo login](http://127.0.0.1:8093/?login=1). Accounts are `demo_super_admin`, `demo_admin`, `demo_inventory_manager`, and `demo_cashier`. Their Demo-only password is in the `DEMO_PASSWORD` field of `C:/Users/Mike/AppData/Local/retailmind-demos/m5-421-20261103/environment.json`.

The database is `retailmind_demo_m5_421_20261103` on the separate loopback MariaDB instance at `127.0.0.1:3307`. PHP serves only `127.0.0.1:8093` from `C:/xampp/htdocs/retailmind/.worktrees/demo-m5-421`, restored at the previously verified commit `6a135087` on 2026-11-23. Sessions, model storage, and recovery storage are separate from the Store.

## Browser walkthrough

Use the account named for each stage. These links open existing pages; changing accounts uses the normal logout/login workflow.

| Stage | Account | Inspect |
| --- | --- | --- |
| [Forecast Data Import](http://127.0.0.1:8093/components/system_administrator/forecast_import.php) | Super Administrator | Optional Store CSV upload/preview. The prepared M5 history was imported through the existing Demo adapter; do not upload it again. Viewing imports does not create customer receipts. |
| [Data Readiness](http://127.0.0.1:8093/components/report/data_readiness.php) | Any forecast viewer | Fixed 140 accumulated usable days, supported recent 28-day inputs, and three supported chronological 28-day evaluation periods. Compare the identical policy in ML Operation. Original 2016 observations do not provide current 2026 inputs; missing recent evidence remains a blocker. |
| [ML Operation](http://127.0.0.1:8093/components/system_administrator/ml_settings.php) | Super Administrator | Actual succeeded job #1, failed candidate metrics, 0/28 prospective observation dates, disabled Demo approval, separate daily Forecast Generation and weekly Model Retraining, AM/PM time and Asia/Manila help, fixed 15-tree/depth-8/seed-42 recipe. Saving settings never trains or approves. |
| [Model Review & Approval](http://127.0.0.1:8093/components/administrator/model_review.php) | Administrator | Local lifecycle gates. The Demo artifact cannot activate a Store model; historical comparisons do not count as prospective observation. Failed quality evidence remains blocked. |
| [Demand Forecast](http://127.0.0.1:8093/components/report/predictions.php) | Any forecast viewer | Unavailable operational coverage and explained stock-rule fallback. Training completion and historical preview do not establish approved current forecasts. Follow its product links when current forecasts exist. |
| [Forecast Analytics](http://127.0.0.1:8093/components/report/forecast_analytics.php) | Super Administrator | Available persisted evidence and unavailable current output. Saved M5 test comparisons are on the historical page below, not invented current performance. |
| [Forecast Exceptions](http://127.0.0.1:8093/components/report/forecast_exceptions.php) | Any forecast viewer | Evidence/approval blockers and the responsible role. |
| [Historical M5 comparison](http://127.0.0.1:8093/components/report/forecast_replay.php?product_id=1) | Any forecast viewer | Original 2016-04-25–2016-05-22 dates, real daily actual/predicted quantities and final held-out metrics. Product 1 (`FOODS_1_122`) totals 17 actual and 28 displayed predicted units. Select another product to inspect different errors. |
| [Replenishment Recommendations](http://127.0.0.1:8093/components/inventory_management/replenishment_queue.php) | Inventory Manager | Separate columns for on hand, stock-rule suggestion and historical expected sales. For product 1 these are 0, 30 and 28 units. Historical quantities are explicitly unapproved; purchase suggestions remain advisory. |
| [Product Investigation](http://127.0.0.1:8093/components/inventory_management/product_investigation.php?product_id=1) | Inventory Manager | Product stock, unavailable approved forecasts, and stock-rule explanation. Viewing neither buys goods nor changes stock. |

Current-date readiness and historical candidate evaluation answer different questions. The real worker trained against the original frozen history; the walkthrough does not shift that history forward to make current inputs appear available. Never interpret external Walmart results as local Store accuracy or unconstrained demand.

## Actual results and provenance

The shared asynchronous worker processed job **1** on November 3, 16:48:19–16:52:13 Philippine time. Candidate `demo_20261103165213_ed4a4f` completed training but failed qualification. WAPE is error; lower is better.

| Horizon | Candidate WAPE | Repeat-last-week baseline | Maximum | Result |
| --- | ---: | ---: | ---: | --- |
| 7 days | 44.33% | 39.81% | 35% | Failed |
| 14 days | 40.86% | 41.11% | 35% | Failed |
| 28 days | 40.64% | 41.97% | 35% | Failed |

No gates were lowered and no approval was forced. Observation remains **0 of 28 usable target dates**. Current operational generation and forecast-backed purchasing are blocked. This is an honest demonstration of a completed job and rejected evidence, not an activated model.

The deterministic seed-42 sample contains 50 anonymous CA_1 FOODS products from [skforecast/m5_daily](https://huggingface.co/datasets/skforecast/m5_daily), pinned to revision `9eee585c46882aa28aaaf43733a5c55c3e32f058`. There are 40,700 daily observations totaling 72,005 units, 2014-03-01–2016-05-22. The first 730 days train; three subsequent 28-day blocks supply chronological comparisons. The saved preview contains 4,200 genuine held-out daily predictions in jobs 2–4. Their rounded fold totals are 2,094, 2,382 and 2,916 units; actual totals are 2,322, 2,310 and 3,195.

The historical page's final-test fractional WAPE is 43.00% / 32.44% / 29.63% for 7/14/28 days. These are separate from the candidate's rounded pooled validation metrics above. Daily display quantities round independently, so their sums are not inputs for recomputing fractional metrics. Each historical fold fits earlier history; saved fold predictions are not outputs from the final refitted candidate.

Opening stock 0, reorder level 20, safety stock 10, lead time 7, package/minimum quantity 1 are illustrative. Prices/costs are placeholders. Imported observations did not create receipts, deliveries or stock movements. Details: [preparation and preservation](../verification/m5-421-preparation-20261103.md), [actual training and fold results](../verification/m5-421-training-20261103.md), and [browser verification](../verification/m5-421-walkthrough-20261104.md).

## Manual restart on this machine

Use a fresh PowerShell process. Do not rerun setup, preparation, training or imports to reopen the existing Demo. Load only its external process environment:

```powershell
$m5Home = 'C:/Users/Mike/AppData/Local/retailmind-demos/m5-421-20261103'
Set-Location 'C:/xampp/htdocs/retailmind/.worktrees/demo-m5-421'
$m5Environment = Get-Content -LiteralPath "$m5Home/environment.json" -Raw | ConvertFrom-Json
$m5Environment.PSObject.Properties | ForEach-Object {
    [Environment]::SetEnvironmentVariable($_.Name, [string]$_.Value, 'Process')
}
$env:Path = "C:/xampp/php;$env:Path"
if (-not (Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue)) {
    $m5Data = "$m5Home/mysql-data"
    Start-Process 'C:/xampp/mysql/bin/mysqld.exe' -WindowStyle Hidden -ArgumentList @(
        '--no-defaults', '--basedir=C:/xampp/mysql', "--datadir=$m5Data",
        '--port=3307', '--bind-address=127.0.0.1', "--log-error=$m5Data/server.log", '--skip-log-bin'
    )
}
& 'C:/xampp/mysql/bin/mysqladmin.exe' --protocol=tcp --host=127.0.0.1 --port=3307 --user=root ping
```

When the response is `mysqld is alive`, open the existing URL if port 8093 is already listening. Otherwise start the existing application and worker:

```powershell
if (-not (Get-NetTCPConnection -State Listen -LocalPort 8093 -ErrorAction SilentlyContinue)) {
    ./src/backend/scripts/start_public_sales_demo.ps1 -Database retailmind_demo_m5_421_20261103 -Port 8093
}
```

The starter launches both the PHP server and Python worker. It refuses a busy port and records process IDs and logs under `.scratch/public-sales-demo/retailmind_demo_m5_421_20261103/` in the Demo checkout. MariaDB logs are under the persistent home's `mysql-data/server.log`. Prepared data, credentials, and verification evidence remain outside Git.

No Windows startup task was installed or executed as part of this work. Manual server recovery was exercised; a complete Windows reboot was not. Operational environment files and Store data were preserved. See the verification report for exact checks and blocked stages.

The existing [Colab notebook](../research/m5_random_forest_colab.ipynb) remains a research reproduction option. It was not executed in Google Colab during this walkthrough; its pre-existing bundled-source mismatch remains documented in the preparation report.
