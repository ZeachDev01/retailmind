# Isolated forecasting pipeline evidence — November 3, 2026

The isolated workflow passed. This does **not** establish target-installation acceptance or genuine Store prediction usefulness. Keep those verdicts separate. See [target-installation gaps](forecasting-target-acceptance-20261103.md).

Source tested: `d269b7be2cb3a651a658e6f5a2e713a5fdecf5bf` on `ticket-417`, including integration `28eb9f2` and strict artifact/prospective-performance review fixes through `c25a84e`. Final run completed **2026-11-03 08:33:01.251925 +08:00**, Asia/Manila. [Raw pipeline run](evidence/ticket-417-20261103/pipeline-run.txt).

Environment: Windows 11 `10.0.26200`, local MySQL at `127.0.0.1:3306`, XAMPP PHP `C:/xampp/php/php.exe` 8.5.12, Python `C:/Python314/python.exe` 3.14.5, pandas 2.3.3, scikit-learn 1.9.1, PyMySQL 2.2.8. Authenticated pages used a temporary local PHP server and headless Chromium. This is target-stack execution, not proof of an installed unattended task.

## Provenance and isolation

The harness created `retailmind_acceptance_8c879de34f31`, loaded the repository schema/migrations, and seeded two explicitly named isolated Products. Product 1 had 260 synthetic certified-import rows through November 2, 2026; Product 2 had no history. The real PHP history exporter, queue, Python Random Forest training/evaluation, artifact persistence, observation worker, activation service, generation worker, notification delivery service, coverage and buying-guidance services ran against this database. Temporary artifacts used `.../retailmind_acceptance_c5inntez/models/store/855cfa1a8b9eb11e/`.

**Actual training gate: Failed.** The candidate was saved, but this synthetic history did not produce a technically qualified model. After demonstrating blocked approval, the test deliberately replaced only this disposable database's evaluation metrics with passing fixture values and inserted 28 historical observation rows, labelled `Observation only; isolated synthetic workflow evidence`. Those rows and elapsed dates are fabricated workflow fixtures, not genuine prospective observations. This demonstrates the approval transition without claiming the model naturally qualified. The actual observation worker also generated 28 future rows, which remained excluded from routine buying before approval.

The disposable database and temporary artifacts were removed in `finally` cleanup. No genuine Store records/artifacts were selected, replaced, or promoted by this harness. No notification was inserted by the harness.

## Acceptance observations

All rows below refer to the source/environment above and the final Philippine timestamp. Passed means the named isolated seam passed, never production acceptance.

| Scenario | Expected | Observed and references | Status |
| --- | --- | --- | --- |
| Identified history → candidate | Real queued training/evaluation saves a Store-scoped model | Training job 1, training run 1, model `v20261103083249_536dad`, 260 observations; actual gate Failed | Passed for execution; qualification Failed |
| Candidate → observation | Actual non-operational worker output cannot authorize buying | Observation job 2 succeeded with 28 labelled rows; coverage remained zero | Passed |
| Unqualified → qualified local approval | Block incomplete candidate; require synthetic checks only in isolated workflow; attribute approval | Blocked before injection; after labelled fixtures Administrator user 2 activated through public activation service; audit action `Activate Store Forecasting Model` attributed to 2 | Passed at service seam |
| Approval → persisted forecasts | Actual generation produces complete ordered daily output | Job 4 succeeded; 28 Product 1 rows, targets November 3–30, origin November 2; sums 133 / 266 / 535 Base Units for 7 / 14 / 28 days | Passed |
| Saved forecasts → authenticated display | Dates, model, quantities and coverage match persistence | Local Administrator logged in; Demand Forecast displayed same model/origin, November 3–30 period and 133 / 266 / 535; 1 of 2 Products covered | Passed |
| Saved forecasts → buying guidance | Expected sales remain distinct from suggested purchase; excluded Product uses stock rules | Authenticated Replenishment Recommendations showed 535 expected Base Units and separate 13-unit stock-rule purchase suggestion. Product 2 service basis was Stock-rule suggestion, expected sales null | Passed |
| No / partial / full coverage | Active Product scope agrees with shared eligibility | Zero before approval; 1 of 2 after generation; 1 of 1 when isolated Product 2 temporarily inactive; returned to 1 of 2 | Passed |
| Actual worker failure → bounded retries | Missing isolated artifact fails with three total attempts | Renamed only disposable model artifact; job 5 failed attempts 1 and 2 as TRANSIENT, attempt 3 EXHAUSTED; scheduler did not create attempt 4 | Passed |
| Failure → notice → fallback | Generated notice; preserve eligible earlier guidance; fall back when none remains | Notice 1 generated by NotificationService; prior job 4 stayed usable after each failure. Dating only isolated job 4 output stale removed coverage and restored stock rules | Passed |
| Recovery | Restore artifact; real new generation returns guidance and resolves failure attention | Job 6 succeeded, coverage returned to 1 of 2, failure attention inactive | Passed |
| Lifecycle / stale / incomplete / mismatch / search boundaries | Existing focused contracts agree on eligibility and mutation behavior | [137 SQLite service checks](evidence/ticket-417-20261103/sqlite-contracts.txt) plus [coverage contracts](evidence/ticket-417-20261103/coverage-contracts.txt) passed. Includes pause/resume, compatible replacement/rollback, mismatch, incomplete output and independent search totals | Passed at fixture seams |
| Full HTTP activation/CSRF and all denied roles during actual-worker run | Authenticated mutation endpoints enforce active Workspace and locality | Actual-worker harness exercised authenticated read pages and public activation service; it did not exercise every HTTP mutation/denial path | Not tested by this run |
| Sales/refunds/receiving/counting during active/failing worker | Successful public operational workflows with attributable operation IDs | Follow-up actual MySQL run: public workflow services completed while separate training job 1 remained running, then after job 5 exhausted three failures; receipts/sales/refunds/counts 1 and 2; stock 0→18→36, drawer PHP100→125→150. [Dated operation evidence](evidence/forecasting-20261103/store-operations.txt) | Passed at isolated public service seam; HTTP not tested |
| Unattended boot / actual delayed database startup | Independent service/task evidence after signed-out restart | No restart or shared database interruption performed; separate target report records missing prerequisites | Not tested |
| Genuine Store qualification / prediction usefulness | Genuine prospective outcomes and qualification evidence | No genuine Store history/outcomes assessed. Synthetic trained candidate failed its real gate; injected qualification cannot qualify production | Not tested; Still collecting evidence |

## Changes validated by red → green

The actual failure run first failed because `claim_job` returned the pre-increment attempt count. The worker classified its third failed execution TRANSIENT. Returning the incremented count made the third execution EXHAUSTED; the real scheduler/notification/fallback/recovery run passed afterward. Existing worker contracts passed: 17 observed-worker tests and 2 candidate-observation tests.

The authenticated display assertion first failed because the page labelled the covered period as origin+7 through origin+28. The persisted output starts origin+1. Correcting that date produced the expected November 3–30 display and passing browser trace.

Removed the predecessor suite's hardcoded Passed acceptance matrix. Its 137 remaining assertions are explicitly SQLite service contracts. It does not claim actual worker execution, unattended startup or public sales/refund/receiving/count completion.

Supporting screenshots: [desktop dashboard](evidence/ticket-417-20261103/dashboard.png), [390px dashboard](evidence/ticket-417-20261103/dashboard-narrow.png), [buying guidance](evidence/ticket-417-20261103/guidance.png). Screenshots include the isolated account's onboarding guidance and automatic notices; they support the behavioral checks, not an exhaustive accessibility verdict.

To repeat on a local disposable database, set `RUN_DB_TESTS=1` and `PHP_BINARY=C:/xampp/php/php.exe`, then run `python src/backend/tests/operational_pipeline_mysql_test.py` from the worktree. Database credentials stay in environment variables and are omitted from evidence. The test refuses non-local/non-acceptance fixture targets and deletes only its randomly named acceptance database and temporary artifacts.

Follow-up November 3, 2026 at **08:49:34 +08:00** ran integrated `d85e815` plus the committed test extension. It reused the same pipeline with a separate actual training process and public ReceivingService, SalesWorkflowService, CashRefundService and InventoryCountService, including actual shift opening and count approval. It passed in 26.079 seconds; model `v20261103084919_3a02b0`, database `retailmind_acceptance_b58ebc1f58ab`, namespace `4bc556e49aada503`, jobs 1/2/4/5/6 and failure notice 3. The actual synthetic quality gate still Failed; historical qualification injection remains explicitly isolated. No HTTP mutation, genuine Store qualification, unattended boot or real database startup-delay claim follows from this run.
