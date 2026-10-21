# Reproducible Demo forecasting (#173)

For the capstone's **real public sales experiment**, use
[the UCI browser walkthrough](guides/public-forecasting-demo.md) and
[source/evaluation details](research/public-sales-capstone.md). This document describes
the separate synthetic-data application demo. The publisher's API now confirms
synthetic generation and a CC0 label; older unverified-status statements below
record the earlier assessment, not current publisher metadata.

Run from repository root on a local development machine. Use a separate
PowerShell session with process-level settings; keep the Store `.env` separate.
Requires local MySQL with CREATE DATABASE permission, PHP (`composer install`),
Node (`npm ci`, `npx playwright install chromium`), and Python worker packages:
`numpy pandas scikit-learn joblib pymysql python-dotenv`.

## Three stages

1. Deterministic synthetic fixtures: `test_observed_worker.py`,
   `test_observed_demand.py`, and `test_feature_pipeline.py`. These test calculations,
   unusable history, prediction exceptions, missing approval, and isolation.
   Their values and scores never enter runtime training evidence.
2. Imported Demo observations: supplied CSV, S001, P0001–P0020, 731 dates each,
   14,620 observations. See [dataset assessment](research/kaggle_capstone_dataset.md)
   for hash, fields, coverage, exclusions, unknown license, and limitations.
   Real/synthetic methodology remains unverified. These are Demo sales counts,
   never observed Store demand. Establish license before redistributing the CSV.
3. Future Store observation: separately fitted Store candidate from actual dated
   Store evidence, technical/operational review, and existing activation gates.
   Demo success cannot approve this stage.

## Persistent local demonstration

Choose a fresh database name. Setup refuses an existing DB; creates tables without
the schema dump's development sales/inventory seeds; runs current migrations;
creates four local Demo accounts; sets explicit Demo WAPE limits of 0.35 for each
horizon. These limits are demonstration policy, not approved Store accuracy targets.
Setup failure can leave a partial Demo DB: investigate and use a fresh name.

```powershell
$env:APP_ENV='development'
$env:DATABASE_ENV='local'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='3306'
$env:DB_USER='root'
$env:DB_PASSWORD='' # use your local credentials
$env:DB_NAME='retailmind_demo_walkthrough_20261012'
$env:BACKUP_STORAGE_PATH="$env:TEMP/retailmind-demo-recovery/$env:DB_NAME"
$env:DEMO_PASSWORD=Read-Host 'New local Demo password (12+ characters)'
php src/backend/scripts/demo_forecasting.php setup
php src/backend/scripts/import_kaggle_capstone.php
php src/backend/scripts/demo_forecasting.php train
python src/backend/scripts/ml_worker.py --once
php src/backend/scripts/demo_forecasting.php review
php -S 127.0.0.1:8088 -t src/frontend
```

Stop on any nonzero exit. Review terminal JSON from the read-only `review` command:
actual candidate MAE/RMSE/WAPE, baseline, gates, evidence window, and fold cutoffs.
Copy its exact `model_version` (`demo_...`). Review does not approve or generate
forecasts. Failed/incomplete gates stay blocked. Keep PHP server running.

After review, in a second PowerShell session with the same environment:

```powershell
php src/backend/scripts/demo_forecasting.php approve demo_EXACT_VERSION_FROM_REVIEW
php src/backend/scripts/demo_forecasting.php forecast
python src/backend/scripts/ml_worker.py --once
```

Explicit CLI approval calls shared `ModelPromotionService` as local Demo Super
Administrator 1, checks evaluation gates, and accepts only a Demo-prefixed version.
It never activates Store forecasting.
Approval also explicitly enables Demo recommendations by clearing Demo observation
mode and suspension through existing settings services.
Keep server on loopback; four `demo_*` accounts share the local Demo password.
Do not copy approvals or artifacts to Store.

Sign in as `demo_inventory_manager` at
`http://127.0.0.1:8088/?login=1` with the Demo password. After approval/generation,
`/components/report/forecast_analytics.php` displays the chosen model's evaluation.
`/components/report/predictions.php`, then
`/components/inventory_management/replenishment_queue.php` and
`/components/inventory_management/replenishment_requests.php`. Review product,
current stock, 7/14/28-day totals, unknown evidence, and quantity. Submit
**Create Request from Forecast**, with a reason for quantity changes. Pending
request retains forecast job/product attribution and a linked decision;
Administrator approval remains the ordinary purchasing step. Viewing forecasts
creates no PO and moves no stock. Setup invents no supplier or inbound terms;
enter demonstration terms explicitly before demonstrating further purchasing.
Initial current inventory is empty (displayed as zero), a Demo operational
scenario rather than a reconstruction of the CSV's ambiguous Inventory Level.

Demo origin is final imported date, 2024-01-01, rather than today's Store date.
Calendar, stockouts, promotions, and advance price schedules remain unknown.
Artifacts use `storage/ml_models/demo/<database-identity-hash>`; Store uses
`store/<database-identity-hash>`. Approval settings belong to their selected DB.
Old synthetic artifacts require retraining.

## Automated verification

```powershell
python -m unittest discover -s src/backend/tests -p 'test_observed_*.py'
python src/backend/tests/test_feature_pipeline.py
php src/backend/tests/store_activation_contract.php
$env:RUN_DB_TESTS='1'
python src/backend/tests/observed_demand_mysql_test.py
```

MySQL check creates a unique empty Demo through the same setup, imports supplied
CSV, fits/evaluates through queued worker, explicitly approves via promotion
service, persists 560 forecasts, opens authenticated forecast page, and submits
reviewed request. Checks pending request/decision's exact job/product attribution
and absence of POs. DB, artifacts, and recovery storage are disposable. Browser
click represents a test operator, not real human approval evidence. Printed
metrics come from imported records. Older `demo_pipeline_e2e_contract.php` is a
synthetic service contract, not CSV/worker/browser end-to-end verification.

## Actual Store evidence and transition

Ordinary sales, inventory, and manual purchasing require no active forecasting
model. Cashier checkout records dated `sales`/`sale_items` under its Cashier Shift;
refunds and legacy reversals retain append-only corrections and availability
timestamps. Receiving, counts, and approved Stock Issues create dated stock
movements. Record Store Calendar through Administrator calendar and price/promotion
schedules through existing product/promotion workflows. Current price does not
prove historical or advance scheduled price; unrecorded promotion does not mean
“no promotion.”

`DemandHistoryService` / `export_demand_history.php` prepare dated sales,
corrections, calendar, and stock evidence. Unknown dates, missing observations,
closures, and censored stockouts stay distinct from confirmed genuine zero.
Worker uses previous completed Philippine calendar day and availability-dated
revisions: features known at origin, labels known by validation cutoff.

Train a Store candidate against Store DB with separate credentials and quality
policy. Run `python src/backend/scripts/ml_worker.py --observe-model VERSION`
for candidate observations without operational approval. Observation rows stay
excluded from routine forecasts and purchasing. Review completed actual targets
through existing evaluation/activation workflow, separately from Demo metrics.
Super Administrator technical review and Administrator operational review precede
activation; suspension keeps manual purchasing available. Below 56 usable days
remains Insufficient. Reaching 56 days establishes neither accuracy nor permission
to activate. Initial worker also needs consecutive feature/label windows and
three chronological holdouts.
