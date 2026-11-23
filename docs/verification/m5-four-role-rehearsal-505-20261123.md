# Four-role M5 rehearsal #505

Verified on Windows on 23 November 2026, Asia/Manila. This report covers the integrated #499 workflow and records failed attempts as well as the successful receiving and checkout.

## One isolated baseline

The application runs at `http://127.0.0.1:8097` from `C:/xampp/htdocs/retailmind/.worktrees/integration-499`. Final implementation includes merges through `98a0c518`. The original PHP process 9936 was restarted as process 36112 after diagnosing the stalled recommendations request. The server remains available; no worker or forecasting task remains registered by this rehearsal.

The only writable database was `retailmind_demo_m5_501_20261123` on loopback port 3307. Its external environment, sessions, recovery storage and models remain under `C:/Users/Mike/AppData/Local/retailmind-demos/scheduler-501-20261123`. The ignored integration `.env` selects those paths; ignored `src/backend/config/forecasting_runner.json` also explicitly selects that home's `models` and the integration project root. PHP is `C:/xampp/php/php.exe`; Python is `C:/xampp/htdocs/retailmind/.venv/Scripts/python.exe`.

Four separate Playwright browser contexts authenticated `demo_super_admin` (user 1), `demo_admin` (2), `demo_inventory_manager` (3), and `demo_cashier` (4). They did not share cookies or browser storage. Each retained its own role during each browser attempt. Failed assertions closed the contexts; subsequent attempts opened four fresh contexts and explicitly resumed the same named training job. This was a repaired rehearsal, not one uninterrupted first-pass test. Screenshots and JSON are retained externally in `scheduler-501-20261123/rehearsal-505`.

The password is only in the external `m5-421-20261103/environment.json` field `DEMO_PASSWORD`. Do not copy it into Git or command output.

## Administrator and real worker evidence

The Admin uploaded the existing three-row preview CSV and pressed **Preview and Validate**. The result was **0 accepted / 3 skipped / 0 invalid**, because the full history was already present. No confirmation or full reimport occurred. Preview preserved inventory, POS, receiving and import counts/checksum.

Data Readiness retained the historical source dates and current-input blockers. The Admin inspected the Sunday 1:00 AM Asia/Manila weekly schedule and all three 35% error limits; no schedule or limit was saved. Rehearsal found that Demo mode hid the existing quality form. Commit `92c20835` exposes the authorized form without changing validation or values.

| Evidence | Requester | Queued | Running | Completed | Candidate |
| --- | --- | --- | --- | --- | --- |
| Prior job 8 / run 4 | Super Administrator, user 1 | 12:57:35 | 12:58:45 | 13:01:34 | `demo_20261123130134_4263bb` |
| First new job 9 / run 5 | Administrator, user 2 | 15:20:43 | 15:21:12 | 15:22:17 | `demo_20261123152217_7618f1` |
| Final live job 10 / run 6 | Administrator, user 2 | 15:25:04 | 15:25:08 | 15:26:13 | `demo_20261123152613_0834bc` |

Jobs 9 and 10 each had one claimed attempt and succeeded. Their `ml_jobs.model_version` remains NULL; the training-run metadata identifies the job and version. No model version was inferred from that nullable job column.

The real SYSTEM task was **RetailMind Demo Scheduler 505 20261123**, registered with the supported installer, the explicit integration root, `.venv` Python and external model storage. Its exported XML uses noninteractive PowerShell `-Run`, correct working directory, startup/five-minute triggers and IgnoreNew overlap handling. The PHP scheduler ran `--sync` and launched the actual Python worker. Both runs were task driven, independently of browser polling. The last captured task result was **0**, missed runs **0**, at **15:31:07**; health was successful idle with pending jobs **0** and no diagnostic. Task XML, install output, task result, health JSON and scheduler log are retained externally. Only that owned task was unregistered after capture.

While job 10 was running, Admin opened Analytics pinned to `training_run_id=4` and Model Review pinned to the same prior run. The immutable `07-live-job10-prior-run4.png` and `08-live-job10-prior-review4.png` were captured at **15:25:38**, before completion at 15:26:13. Prior run 4 remains **provenance unverified** and has no recorded daily candidate predictions; its separately fitted replay is not attributed to that candidate. The initial Model Review omitted all Demo evidence while Store governance searched only Store candidates. Commit `75affa4a` adds a read-only isolated Demo section with run identity, qualification, provenance and exact Analytics link. Store approval summary and mutation gates remain unchanged.

Run 6 has 50 Products, 40,700 source observations, original history **2014-03-01–2016-05-22**, and metadata label **External Demo: M5 original daily sales; not Store accuracy**. Its persisted held-out predictions belong to that run. Rounded pooled 7/14/28-day WAPE was **44.33% / 40.86% / 40.64%**, versus maximum **35%** for each horizon. All three qualification checks failed; completion is not qualification.

Run 6 artifact:

```text
C:/Users/Mike/AppData/Local/retailmind-demos/scheduler-501-20261123/models/demo/a34490fb64e82d72/random_forest_candidate_demo_20261123152613_0834bc.joblib
SHA256 4a5a6eae86993041c490306fa2de07529af6e0a4438174abf38ff1e22150278a
```

No approval or operational generation was submitted. Historical Demo evidence supplies no usable prospective Store observation days and cannot activate a Store model or provide operational buying guidance. Model Review correctly retained **No model to review yet** for the Store approval summary rather than inventing a Store candidate with a numeric observation counter. The Demo evidence section explains the separate boundary. Inventory Manager and Cashier direct GETs to Model Training returned **403**.

## Demo setup, receiving and same-item sale

The clone initially had no positive selling price and no Fiscal Period. These are operational Demo setup, not M5 supplied observations:

- Existing migrations were applied only to the explicit 3307 clone after Product Save failed with missing `declared_size`. The 13 migration keys were `202611030002_retire_recovery_account_page`, `202611090001_super_admin_single_workspace`, `202611100001_backup_keyring_and_history`, `202611100001_fiscal_period_scheduling`, `202611100002_scheduled_backup_settings`, `202611100003_store_calendar_notes`, `202611120001_supplier_pullout_eligibility`, `202611170001_known_product_costs`, `202611180001_receiving_allocations`, `202611180002_receiving_cost_basis`, `202611180003_product_sku_provenance`, `202611180004_product_declared_size`, and `202611180005_product_image_source`. Exact output is `migrations.txt`.
- Inventory Manager set product 1, barcode/SKU `M5-FOODS_1_122`, to an illustrative **PHP 10.00** through the normal Product editor. Unknown cost remains unknown. Repeated safe editor saves during retries produced recorded price-driver rows; these were retained.
- The first receiving submission was rejected because no open Fiscal Period covered the date. Counts and stock remained zero. Admin then created open Fiscal Period **1**, **M5 rehearsal 2026-11**, covering November 1–30, through the normal Fiscal Period page at 15:34:38. No Store period was changed.

Inventory Manager showed **0 on hand**, a **30-unit stock-rule suggestion**, and separately **28 historical expected units** for April 25–May 22, 2016. The historical quantity remained explicitly unapproved.

The normal scan → quantities → Review → Save workflow received **30 base units**, reference **`M5-505-1795419280309`**, at **15:34:40**, recorder user 3. Review alone saved nothing. One receiving record and one +30 stock movement were created; no sale existed then.

Cashier opened shift **1** on **Demo Front Counter**, with zero starting float. The normal same-item cart, server quote review and checkout produced sale **1**, sale item **1**, receipt **1**, at **15:34:42**, cashier user 4, shift 1. One piece at PHP 10.00 was paid with PHP 10.00 cash; change PHP 0.00. The receipt displayed **Payment completed**. Stock moved **0 → 30 → 29**; there was no retry with a fresh checkout attempt.

| Persisted effect | Before | After |
| --- | ---: | ---: |
| Imported observations | 40,700 | 40,700 |
| Import CHECKSUM TABLE | 1483668528 | 1483668528 |
| Saved replay forecasts | 4,200 | 4,200 |
| Receiving records | 0 | 1 |
| Sales / sale items | 0 / 0 | 1 / 1 |
| Stock movements | 0 | 2 |
| Cashier shifts | 0 | 1 |
| Product 1 on hand | 0 | 29 |

After checkout, an authenticated recommendations GET returned **200 in 733 ms**, displaying stock 29, stock-rule quantity 0 (stock exceeds reorder level 20), and historical expected sales 28.

The new historical Model Review section, exact-run Analytics link and scheduler block were also checked at **320 and 390 pixels**, in **light and dark** themes. Bounding rectangles stayed within the viewport; card scroll width equalled client width (281 / 351 pixels), with no horizontal overflow. Four captures and `review-layout.json` are retained externally; the 320-pixel dark capture was visually inspected.

## Diagnosed performance failure

The first saved price inserted a scheduled driver and disabled the global imported-only optimization. Recommendations then reconstructed twelve years of evidence with per-day SQL, blocking the single PHP server. SQL inspection showed repeated schema probes, no blocking lock, and exactly one price driver; calendar, weekly schedule, sales and movements were still empty. A seven-day public evidence read took 0.054 seconds, while the full recommendations request stalled for minutes.

Commit `fc835792` splits imported history before the earliest cutoff-known Store evidence date from the Store tail. It retains the original per-day reconstruction for that tail. It uses effective dates for calendar/schedule/drivers and excludes records unknown at cutoff; errors conservatively fall back. It does not alter classification or eligibility rules. The actual 4,652-day clone read through November 24 took **0.0395 seconds** after the fix and **0.0364 seconds after receiving and checkout**. The public regression failed before the fix and passed afterward; it checks bounded queries across twelve years plus the next-cutoff Store tail, POS precedence, known price, calendar closure, and after-cutoff setup isolation.

## Preservation and verification limits

Fresh before/after snapshots are under `C:/Users/Mike/AppData/Local/Temp/retailmind-499`: `505-operational-before.json`, `505-operational-after.json`, `505-before.json`, `505-before-final-run.json`, and `505-after.json`. All **81 Store table checksums**, root environment/configuration hashes and root WIP diff hash matched. All original `retailmind_demo_m5_421_20261103` table checksums and original Demo session/model/config hashes matched. Original port 8093 was not restarted or used for writes. The clone's imported checksum and replay rows matched despite authorized operational records and schema upgrades.

Passed: the opt-in four-role browser workflow with the documented retries; demand history contract; Store Training Eligibility; Store forecasting operational workflow; forecasting notices; history import; forecast coverage; Analytics contract; forecasting runner installation contract; PHP/Node syntax checks. The existing forecasting navigation contract has two unrelated stale expectations: Store Calendar placement and a literal Data Readiness predictions link now supplied by shared navigation. An existing future-date `$calStatus` warning in the history contract was observed separately by the integration agent; it was not the measured slowdown path.

Parent-run TestSprite focused read-only verification **passed**, run `037a2ebc-c888-4def-aabc-65fc0f391861`: Admin login, quality-section navigation, and explicit prior-run-4 Analytics visibility. Its recorded assertion does not prove every planned limit or missing-daily check; local browser assertions cover those. The earlier combined-navigation assertion failed despite captured HTML containing the links and expanded state. Remote artifact bundles were withheld by the service's environment-scrubbing policy; pinned steps and captured HTML remain in `Temp/retailmind-499/testsprite-focused-*`. [Focused TestSprite result](https://www.testsprite.com/dashboard-v3/o/5ab7f0fd-df67-5a25-8d50-cf950137b75b/projects/abe16d0b-21f0-432e-8a86-72b1436c42fe/execution/037a2ebc-c888-4def-aabc-65fc0f391861/test/607bc3a4-be7a-42fd-8ac1-8c848d4ed163).

No reboot/cold-boot test, model approval, Store mutation, purchase order, quality-gate reduction or Colab execution occurred. The Cashier shift and all genuine Demo operational records remain for inspection.

## Reusable opt-in coverage

`src/backend/tests/m5_four_role_rehearsal_browser_test.js` exercises public signed-in browser workflows and verifies persisted effects. It requires an explicitly prepared, unsold/unreceived isolated M5 clone; it never resets existing records. Do not run the old UCI public-sales browser test against this clone.

Set the external Demo process environment and `DEMO_PASSWORD`, then `RUN_M5_REHEARSAL=1`, `DEMO_BASE_URL`, `M5_PREVIEW_CSV`, `M5_EVIDENCE_DIR`, `M5_PYTHON`, `M5_PROJECT_ROOT`, `M5_MODEL_STORAGE`, and a unique `M5_TASK_NAME` beginning `RetailMind Demo Scheduler 505 `. Run `node src/backend/tests/m5_four_role_rehearsal_browser_test.js`. `M5_RESUME_JOB` explicitly resumes the named previously captured job without creating another request; the script still refuses already received/sold clones. Preserve failed evidence before a resume. After terminal completion, export task/health evidence and unregister only the owned task. The script deliberately retains records and task evidence rather than cleaning the database.
