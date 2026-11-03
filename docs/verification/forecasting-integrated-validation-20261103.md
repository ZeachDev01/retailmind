# Integrated forecasting validation — November 3, 2026

Source tested: `26a3d9d60fc7742fc08923f8f2f2ace973ffc540`, integration-410. Windows/XAMPP PHP `C:/xampp/php/php.exe`, Python `C:/Python314/python.exe`; all timestamps below are Asia/Manila. This report supplements, rather than replaces, earlier dated evidence.

## Results

All 11 focused PHP suites passed: forecast coverage, prediction performance evidence, forecasting landing page, scheduler, training eligibility, model review/approval, Administrator model-review component, model governance, analytics, evaluation-summary rendering and 112 smoke checks. The safe runner/installer suite also passed with `DB_PORT=1`, including retained JSON decoding by PHP/Python without a BOM. PHP lint and the full baseline diff check passed.

Python checks passed: 8 observed-demand tests (including future-calendar availability), 17 observed-worker tests, feature-pipeline assertions and evaluation-metric assertions. The authenticated landing browser passed for Administrator and Inventory Manager, light/dark themes, narrow layouts and inline summary/no-duplicate-toast behavior. A [390px screenshot and provenance](evidence/forecasting-20261103/dashboard-narrow-final.md) support the layout checks.

The [final startup run](evidence/forecasting-20261103/final-database-startup.txt) passed both process-cleanup and actual database-delay tests in 16.861 seconds. The scheduler started at 09:00:53, recovered after an owned random-port MariaDB became available at 09:00:56, and completed training job 1/run 1 at 09:01:02. Model `v20261103090102_6d5c6e` was persisted. The owned database, process, artifacts and temporary runner configuration were cleaned. Existing nonfatal PHP 8.5 implicit-nullability deprecations appear in this transcript.

The [final pipeline run](evidence/forecasting-20261103/final-pipeline.txt) passed in 24.151 seconds, completing at 09:01:28. Disposable database `retailmind_acceptance_9ca48f1587a5`, model `v20261103090114_c51dbf`, training run 1, observation job 2, generation job 4, failure job 5, recovery job 6 and generated failure notice 3 are recorded. Persisted and authenticated displayed 7/14/28-day totals agreed at 133 / 266 / 535 Base Units.

Receiving, sales, Cash Refunds and approved physical counts used their public workflow services on that same disposable Store while actual training job 1 remained running before/after, then again after job 5 exhausted three attempts. Operation IDs were 1 and 2, shift 1. Stock and batch totals reconciled 0 → 18 → 36; expected drawer cash reconciled PHP 100 → 125 → 150. These are service-boundary observations, not HTTP mutation coverage. Database/artifact cleanup completed.

## Review

Standards review: all identified hard findings resolved, including artifact isolation/validation, origin-time calendar availability, truthful metric labels and bounded owned-process cleanup. Daily Product/day outcome errors are explicitly distinguished from summed-horizon qualification backtests.

Spec review: all identified product findings resolved, including approval-versus-usability labels, truthful preserved-guidance effects, prospective evidence, compatible earlier-job selection and candidate identity. No remaining substantive finding in the final delta. A speculative metric-helper refactor was skipped.

## Acceptance limits

**Operational target acceptance remains NOT ACCEPTED.** An installed RetailMind task and an actual signed-out Windows restart/catch-up have not been demonstrated. See the [target acceptance matrix](forecasting-target-acceptance-20261103.md). No reboot, task registration or shared-service interruption was performed.

**Genuine prediction usefulness remains Still collecting evidence.** The real synthetic training gate failed; explicitly injected qualification/observation fixtures demonstrate only the isolated approval workflow. They never qualify a production model.

The earlier unsafe predecessor runner test affected the default Store database (failed job 10 and effective schedule setting), as disclosed in the target report. The repaired suites isolate database/artifact work; those earlier records were preserved rather than deleted. Root user work was not changed.
