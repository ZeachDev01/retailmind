# Forecasting implementation review

Reviewed 2026-10-10 against `CONTEXT.md`, ADR-0005, and `docs/specs/production-demand-forecasting.md`.

## Verdict

Partially implemented migration, not a dependable end-to-end forecasting feature. Persisted forecast readers, queue creation, role checks, and horizon aggregation exist. The production worker is incomplete; several UI pages still consume incompatible legacy contracts. Existing screens or seeded forecasts do not establish that real forecasting works.

## Current flow

1. `src/frontend/components/report/predictions.php` authorizes viewing and accepts CSRF-protected generation requests from permitted roles.
2. `src/backend/app/Services/MlJobService.php` inserts a job and audit entry. Scheduled requests originate in `ForecastingSchedulerService.php`.
3. `src/backend/scripts/run_scheduler.php` attempts to start `ml_worker.py`.
4. The worker claims a queued/expired job and attempts to write 28 daily forecast rows. Its generation implementation uses a fixed candidate artifact and dummy demand input. Retraining has no implementation in this worker.
5. `DemandForecastService.php` selects the latest successful generation and sums daily rows into 7/14/28-day totals.
6. Overview and product investigation display these rows. `ReplenishmentQueueService.php` combines forecasts, stock, inbound quantities, lead time, and minimum order quantities into non-binding recommendations.
7. Analytics, readiness, exceptions, and replenishment review contain older contracts that do not consistently match these new records.

## Findings

### P1 — Scheduler cannot invoke the worker successfully

`src/backend/scripts/run_scheduler.php:30,39` passes `--run-once`; `src/backend/scripts/ml_worker.py:99` only accepts `--once`. Running the scheduler's worker command reproduces an argparse rejection before database access. The launcher also uses Unix redirection/background syntax despite documented Windows support. Queued jobs cannot complete through the documented invocation as written.

### P1 — Generation queries nonexistent product columns

`ml_worker.py:70` selects `id, price`; the repository product schema uses `product_id, unit_price`. Even after correcting invocation and supplying the artifact, generation cannot execute against that schema.

### P1 — Model input and readiness are placeholders

`ml_worker.py:12` hardcodes one candidate artifact instead of resolving the approved model. Lines 76–82 use `lag_1 = 10.0` for every product. Line 93 writes `High` for every forecast. History preparation, closure/stockout treatment, Scheduled Demand Drivers, validation gates, and actual readiness evidence are not connected to generation. Promotion/rollback settings cannot affect this fixed artifact selection. Contradicts ADR-0005 and the domain definition of Forecast Readiness.

### P1 — Retraining reports success without training; failures remain running

`ml_worker.py:46–50` handles only Forecast Generation, then marks any claimed job successful, including Model Retraining. Lines 51–53 only roll back and print an exception; the earlier committed running status remains. No failed status or safe operator message is persisted. A broken forecast can therefore remain retrying while the overview disables Generate Now.

### P1 — Replenishment review cannot render

`src/frontend/components/inventory_management/replenishment_requests.php` fails PHP syntax checking: unexpected end of file at line 301, expecting `elseif`, `else`, or `endif`. This breaks the existing human review destination even though a separate replenishment queue exists.

### P1 — Readiness query references an undefined table alias

`src/backend/includes/functions.php:350–351` reads `df.readiness_level` and `df.forecast_origin` in `get_forecasting_readiness()` without joining a `df` source. The function catches the SQL exception and returns an empty array, hiding a query defect as absent readiness data.

### P2 — Analytics does not match the returned metrics

`src/backend/includes/functions.php:208–224` returns the latest single evaluation row with `mae` and `rmse`. `src/frontend/components/report/forecast_analytics.php` expects `mean_absolute_error` and `root_mean_squared_error`, plus absent `feature_importance` and `product_metrics` collections. Valid stored metrics therefore still produce blank key panels. Selection is neither grouped by 7/14/28-day horizon nor pinned to the model used by the displayed forecast.

The actual-versus-predicted query includes the forecast origin and excludes origin + 28, whereas worker predictions cover origin + 1 through origin + 28. It also sums raw sale quantities instead of using the defined valid-demand treatment.

### P2 — Exceptions misstate horizon and confidence, and repeat products

`src/frontend/components/report/forecast_exceptions.php:18–51` sums daily forecasts under a 30-day label despite the 28-day contract. Its grouping includes `forecast_id`, retaining a row per forecast day, so one fully populated product can appear 28 times. No `confidence_score` is selected, yet the code defaults it to zero and flags Low confidence; rendering also accesses that absent field. This contradicts the requirement to present evidence-based Readiness instead of a confidence percentage.

### P2 — Overview hides freshness and operational context

`src/frontend/components/report/predictions.php` displays product IDs, horizon totals, and readiness only. It does not display model version, run time, source cutoff, limitation markers, stock exposure, or a stale/failed-latest-run warning when older successful results exist. Search requires an internal numeric product ID. Product investigation shows scheduled drivers using today's window and current cutoff, rather than the displayed forecast's origin, which can misrepresent what was known for an older forecast.

### P2 — Missing forecasts appear Covered in replenishment

`src/backend/app/Forecasting/ReplenishmentQueueService.php:29–32` converts missing forecast values to zero while retaining all active products. Its urgency calculation initializes Covered at line 91. A product without forecast evidence can therefore appear safely covered rather than explicitly unknown. Missing prediction data must remain distinguishable from a genuine zero-demand forecast.

## Verification and limits

- PASS: `php src/backend/tests/demand_forecast_service_test.php` — in-memory horizon aggregation.
- PASS: `python src/backend/scripts/test_ml_cv.py` — chronological split helper.
- PASS: `python src/backend/scripts/test_ml_evaluation.py` — metric and baseline helpers.
- Reproduced failure: `python src/backend/scripts/ml_worker.py --run-once` rejects the scheduler's flag before database access.
- Reproduced failure: `php -l src/frontend/components/inventory_management/replenishment_requests.php` reports the parse error above.
- Blocked: navigation and replenishment contract tests stop during application bootstrap with `Recovery coordination is unavailable.` Their process status alone must not be treated as a passing assertion result.
- Did not run database integration suites: inspected suites contain destructive table cleanup, and no isolated test database was established.
- Did not run live browser checks, fit a real model, inspect production database contents, or verify installed scheduled tasks. Visual polish and real predictive accuracy remain unassessed.
- `demand_forecast_integration_test.php` is an unimplemented test stub. The browser workflow injects simulated worker output rather than executing Python; it cannot detect these worker failures.

## Recommended repair order

1. Make one real queue → approved model → persisted forecast → UI flow work, including safe failure states and actual inputs/readiness.
2. Implement retraining, chronological evaluation, and approved artifact selection as one connected lifecycle.
3. Repair replenishment rendering; align analytics, exceptions, readiness, and freshness presentation to the same run/model/horizon contract.
4. Verify with an isolated database and a real worker-driven end-to-end test, then assess model quality against the seasonal baseline.

No application code changed. No new architecture decision or domain term was adopted: the existing ADR and vocabulary already cover the required behavior. Repair scope remains a user decision.
