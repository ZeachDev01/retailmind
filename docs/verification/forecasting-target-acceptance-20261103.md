# Forecasting target-installation acceptance — November 3, 2026

**Operational verdict: NOT ACCEPTED.** The actual Windows target has no installed RetailMind scheduling task or retained runner configuration. An unattended restart has not been performed. Passing isolated workflow/contract tests does not satisfy these mandatory criteria. Keep #418 open.

**Prediction usefulness: Still collecting evidence.** This package establishes no measured prediction usefulness on genuine Store outcomes. Synthetic or fixture evidence cannot qualify a production model.

Scope: issues #410 and #418; ADR-0005 as amended by ADR-0022. Inspection performed in `C:/xampp/htdocs/retailmind/.worktrees/ticket-418`. Preserve root working files and genuine Store data. No restart, service stop, installation change, or model promotion performed for this package.

## Environment and provenance

The [read-only target inspection](evidence/forecasting-20261103/target-inspection.json) was captured at **2026-11-03 08:25:53 +08:00 (Asia/Manila)**. Inspection source revision: `7ba914f5ef92a117a8b04df3468d01f6d41d7b17`; installed root revision: `982a8a07a7f6fe285ca72cd6b28b7494130ed48f`. These differ: integrated source is not established as deployed.

Windows 11 Pro `10.0.26200`; last recorded boot `2026-11-01 06:31:30 +08:00`. Apache2.4 and mysql are Running, Automatic, LocalSystem, using `C:/xampp`. This snapshot proves configuration/current availability only; it cannot establish startup without sign-in. PATH PHP resolves to `C:/tools/php85/php.exe`; checks explicitly used `C:/xampp/php/php.exe` (PHP 8.5.12). Python resolves to `C:/Python314/python.exe` (3.14.5). No installed forecasting executable selection, effective scheduled service account, or approved production model provenance was established. The intended schedule remains daily 01:00 and Sunday 01:00 Asia/Manila, preserving explicitly selected operational settings.

No RetailMind task matched either task name or action arguments; the unrelated Windows PowerGridForecastTask is not RetailMind evidence. Root `forecasting_runner.json` and `forecasting-scheduler.log` were absent.

## Acceptance matrix

Every timestamp below is Philippine time (`+08:00`). “Not tested” means the required observation is absent, even if predecessor unit tests cover part of the behavior. Job/model/forecast references are unavailable unless explicitly stated.

| Scenario | Environment/revision and provenance | Expected | Observed, timestamp and references | Status |
| --- | --- | --- | --- | --- |
| Installed scheduler/executable/account prerequisites | Actual Windows/XAMPP; installed root `982a8a0`; no fixture | RetailMind startup/repeating task, retained fixed executable/model paths and effective service account | No task, retained runner config or scheduler log, 2026-11-03 08:25:53; target-inspection.json; no run/model/forecast reference | Failed |
| Actual unattended restart | Actual Windows/XAMPP | Independent service/task events and completed worker output after reboot before interactive sign-in | No restart performed; prior boot predates this verification; no run/model/forecast reference | Not tested |
| Actual delayed database startup | Actual Windows/XAMPP | Scheduler retries delayed database, then worker completes after availability | Shared mysql was never stopped/delayed; no real delayed-start run reference | Not tested |
| Current-only daily/weekly catch-up and deduplication contract | Explicit XAMPP PHP; inspection source plus test-only safety edit; SQLite memory and controlled clock | Latest completed-day cutoff; current-only catch-up; no duplicate jobs; three-attempt ceiling | Existing scheduler contract passed, 2026-11-03 08:25–08:26; scheduler-contract.txt; synthetic job IDs remain in memory | Passed |
| Database retry and effective schedule contracts | Same source/check environment; injected connector and SQLite memory | Bounded retries, recovery and accepted schedule persistence | Runner contract passed, 2026-11-03 08:25–08:26; runner-contract.txt; no target worker reference | Passed |
| History to candidate | Disposable Windows/MySQL; synthetic history | Actual training/evaluation and saved candidate | Job 1/run 1 trained 260 observations; actual quality gate Failed. See [pipeline evidence](forecasting-isolated-pipeline-20261103.md) | Passed for execution; qualification Failed |
| Qualification to approval | Same disposable database; labelled injected qualification/observation | Blocked unqualified approval; local attributed approval | Public service blocked unqualified candidate, then Administrator user 2 approved labelled fixtures with audit attribution. HTTP mutation coverage remains incomplete | Passed at isolated service seam only |
| Approval to forecasts | Same actual queue/worker | Complete persisted 7/14/28-day output | Job 4 persisted 28 daily rows; cumulative 133 / 266 / 535 Base Units | Passed |
| Forecasts to dashboard | Authenticated isolated Administrator; integrated revision `5d9f37c` | Dates, quantities, model and coverage match persistence | Same model/origin, November 3–30 period, 133 / 266 / 535 units, 1 of 2 Products covered; screenshots support assertions | Passed |
| Forecasts to buying guidance | Same isolated product/date/model | Forecast basis and truthful fallback agree | Expected sales 535 Base Units; separate stock-rule suggestion for excluded Product; authenticated buying guidance agrees | Passed |
| Failure to recovery | Renamed disposable artifact only; integrated revision `5d9f37c` | Three attempts, generated notice, fallback and recovery | Job 5 failed three attempts; notice 1 generated; prior valid job 4 preserved, stale output fell back; recovery job 6 succeeded | Passed |
| Routine Store non-disruption | Disposable Windows/MySQL, `d85e815` plus committed test extension; synthetic Product 2 | Successful public workflows while forecasting runs or fails | Actual separate training job 1 remained running across receipt/sale/refund/count 1; repeated operations 2 after job 5 failed three times. Stock 0→18→36, batch totals agree; expected drawer PHP100→125→150, 2026-11-03 08:49:15/08:49:32 +08:00. [Operation evidence](evidence/forecasting-20261103/store-operations.txt) | Passed at isolated public service seam; HTTP not tested |
| Integrated actions, roles and accessibility | Final integrated revision, desktop and narrow-screen browsers | Working destinations, informed review, enforced active Workspace, labelled controls, focus, text status, readable layout | Final integrated browser inspection pending; predecessor screenshot alone insufficient | Not tested |
| Genuine Store prediction usefulness | Genuine forecasts made before matching completed outcomes | Dated model/product/horizon comparison, separately labelled errors and incomplete availability | No genuine-outcome analysis performed; **Still collecting evidence** | Not tested |

Passed rows establish only their stated isolated seams. The real pipeline was repeated at integrated revision `5d9f37c` on November 3, 2026, completing at 08:36:40 +08:00 with model `v20261103083628_7e84bf`, jobs 1/2/4/5/6 and generated notice 1. Disposable database `retailmind_acceptance_62824eec0515` was removed by the harness. These results do not change the Failed/Not tested target-installation verdict or qualify the synthetic model for production.

## Unsafe predecessor check and correction

The inherited `forecasting_runner_installation_test.php` mixed SQLite contracts with a default Store database connection, installer `-Run`, and unrestricted worker `--run-once`. Two agents executed it before recognizing this unsafe boundary. Its success output is excluded from acceptance evidence. Parent read-only inspection identified default database `inventory_system` job **10**, `forecast_generation`, failed **2026-11-03 08:24:07**, attempt **1**, diagnostic **BLOCKED:MISSING_APPROVED_MODEL**; `ml_effective_schedule` was also persisted. No successful forecast output resulted. Test-owned observation jobs were cleaned by the completed test; preserve job 10 and the schedule row for attributable review, without blind deletion. The exact unrelated concurrent Store state was not assessed by this package.

This ticket removes the default-database, installer-run, and unscoped worker-completion block. Schedule checks now use SQLite memory. The retained remote-host rejection subprocess must fail before any database work. The safe runner contract explicitly reports unattended restart/full worker completion as Not tested and also passed with `DB_PORT=1`, so a reachable default MySQL server is unnecessary. No production runtime code or dependencies added.

## Remaining acceptance procedure

1. Deploy the reviewed integration revision to the intended installation, select fixed PHP/Python/model paths, verify package/runtime compatibility, and register the RetailMind task with retained configuration and a service account that can run without sign-in. Confirm actual effective settings without replacing an explicit operator schedule.
2. Arrange an attended maintenance window for the owner to restart the actual PC. Keep Windows signed out while the scheduler and worker run. Capture independent startup/task events, database availability, application revision, job attempts/completed timestamps and model/forecast IDs afterward. Never infer success from SYSTEM configuration alone.
3. Demonstrate database-start delay and current-only daily/weekly catch-up with isolated data/artifacts on the same target stack. Do not interrupt the shared Store database for testing. Show duplicate prevention and full successful worker completion.
4. Retain the isolated public-service sale/refund/receiving/count evidence above. Complete HTTP mutation/role boundaries and final integrated desktop/narrow-screen accessibility verification; public-service success does not establish those browser boundaries.
5. Replace each Not tested row only with traceable dated observations. Keep genuine Store prediction evidence separate. Reassess the operational verdict only after all mandatory rows pass.
