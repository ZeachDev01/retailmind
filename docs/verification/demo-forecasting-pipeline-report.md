# Demo forecasting verification (#173)

Previous #160 report's hand-written scores and mock SQLite end-to-end claims
are withdrawn. Its synthetic service contract remains useful, but did not run
actual importer, worker, explicit promotion gate, or authenticated request submission.

See [reproducible workflow](../guides/DEMO_FORECASTING.md) and
[dataset assessment](../research/kaggle_capstone_dataset.md). Supplied CSV has
73,100 rows; selected S001/P0001–P0020 has **14,620**, not 14,600, observations
from 2022-01-01 through 2024-01-01. License and real/synthetic methodology remain
unverified; calendar, stockouts, and scheduling evidence remain unknown.

## Software workflow verification

`observed_demand_mysql_test.py` uses empty isolated MySQL, actual importer,
queued Random Forest fitting/evaluation, shared explicit promotion, forecast
generation, and authenticated Playwright request submission. It checks 560 daily
forecasts and exact request/decision job/product attribution. Request stays pending;
no purchase order is created. Synthetic worker tests cover unusable history,
prediction failure, and missing approval.

## Dataset-specific evaluation

Each run prints persisted 7-, 14-, and 28-day MAE/RMSE/WAPE against repeating
same-weekday-last-week baseline. Three chronological 28-day holdouts purge
training origins whose entire label window is not observable by validation cutoff.
Artifact metadata records products, observations, feature contract, actual final
training window, fold origins, and final observable label dates. No fixed accuracy
score is asserted or copied from former mock report.

Verified locally on 2026-10-12 with the supplied SHA-256-pinned file, Random
Forest seed 42, and the shared feature contract. Metrics aggregate 60 product/origin
horizon totals (20 products × three holdouts); MAE/RMSE units are horizon-total
sales units, WAPE below is percent. These are dataset-specific results:

| Horizon | RF MAE | RF RMSE | RF WAPE | Baseline MAE | Baseline RMSE | Baseline WAPE |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 7 days | 202.85 | 247.35 | 22.41% | 342.02 | 421.09 | 37.78% |
| 14 days | 337.75 | 389.13 | 18.02% | 629.77 | 778.27 | 33.60% |
| 28 days | 532.43 | 658.99 | 14.02% | 1212.32 | 1517.63 | 31.92% |

All three meet the explicit Demo-only 35% limit and beat baseline. Approval was
performed through `ModelPromotionService`, rather than writing an approval setting
directly. MySQL/browser check passed both safety guard and complete pipeline tests:
14,620 imports, 560 forecasts, displayed horizon totals equal persisted sums,
pending request linked to the exact job/product and decision, no purchase order.
It also refused repeated setup against the existing Demo DB. Demo setup aligns
new forecast version collation with legacy metadata on MySQL 8; importer assigns
singleton Store product scope so the imported products reach operational pages.
Read-only CLI review exposes candidate evidence before approval. The final check
also verifies the promotion audit actor/version and all 25 missing Demo target
outcomes displaying Unknown with no numeric difference. Both review findings
were fixed; Standards and Spec rechecks have no unresolved findings.

Focused observed-demand/worker suite: 19 tests passed, including unusable history,
prediction failure preserving prior output, and missing approval. Feature checks,
CV/metric checks, PHP/JS/Python syntax checks, and selected browser/client behavior
checks passed. Repository PHP sweep with writable temporary recovery storage:
98 of 110 passed; 12 existing or environment-dependent failures remain:

- Empty `run_all.sh` registration checks: backup workflow, dormancy settings,
  dormancy status tab, friendly alerts, and staff login parity (five).
- Missing `support/test_bootstrap.php`: forecast integration stub (one).
- Administrator workspace and Super Administrator route expectations (two).
- Profile initials expectations and Stock Issue date-range expectations (two).
- Backup-unavailable and restricted-host checks require different host/storage
  restrictions than the temporary recovery storage used by the sweep (two).

Initial sweep attempts encountered recovery-path errors that PHP's fallback
masked with exit zero; those results are not counted as passes.
Other opt-in Store DB/browser suites were not enabled; this is not a claim that
every repository integration test passes. `candidate_creation_test.py` was run
without an imported Demo context and correctly refused that environment.

## Store validation unavailable

No real Store history exists for this demonstration. Store accuracy, live stockout
treatment, completed observation performance, and operational usefulness cannot
yet be established. Demo results cannot activate Store forecasting. Separate Store
model, usable evidence, completed targets, explicit reviews, quality gates, and
activation remain required. Sales, inventory, and manual purchasing stay available.
