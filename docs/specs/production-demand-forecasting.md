# Production Demand Forecasting for One Store

## Problem Statement

RetailMind has an early Demand Forecast implementation, but it is not yet suitable for dependable Store use. It predicts 7 and 30 days through a synchronous Flask request, recursively reuses predictions as history, omits known price and promotion effects, treats missing sales too simply, and does not consistently distinguish Store closure, stockout, reversal, cancellation, and genuine zero demand. Model training and Forecast Generation are coupled to an interactive request, role boundaries are too broad, evaluation does not cover every required Forecast Horizon, and the interface does not yet give the Inventory Manager a clear operational path from expected demand to a reviewed replenishment decision.

The Store needs a simple, explainable forecasting feature for one location and at least 20 products initially. It must forecast daily product demand over fixed 7-, 14-, and 28-day Forecast Horizons, show how trustworthy each result is, and help the Inventory Manager identify stock exposure without automatically changing stock or creating a purchase order. It must remain practical to develop on Windows with XAMPP and deploy later either on a Linux VPS or a properly maintained Store PC.

## Solution

RetailMind will provide one asynchronous Random Forest forecasting pipeline. PHP will record Forecast Generation and Model Retraining jobs in MySQL. A scheduled Python CLI worker using scikit-learn will process those jobs outside interactive web requests and persist versioned daily forecasts, horizon summaries, validation metrics, readiness, and run status for the PHP application to display.

The Inventory Manager will be the primary operational user. Their main navigation will expose Demand Forecast directly alongside Inventory Overview. The implemented experience will combine the accepted prototype directions: a Store overview for 7-, 14-, and 28-day exposure, a product workspace for investigation, and an action queue ordered by replenishment risk. The Administrator may review Store performance and request Forecast Generation. The Super Administrator alone may retrain, replace, or configure the model. Every recommendation remains decision support and requires human review.

## User Stories

1. As an Inventory Manager, I want Demand Forecast in my main navigation, so that I can reach it as part of routine inventory work rather than searching through reports.
2. As an Inventory Manager, I want to see expected Store demand for the next 7 days, so that I can address immediate stock exposure.
3. As an Inventory Manager, I want to see expected Store demand for the next 14 days, so that I can plan around ordinary supplier lead times.
4. As an Inventory Manager, I want to see expected Store demand for the next 28 days, so that I can prepare for longer replenishment cycles.
5. As an Inventory Manager, I want the overview to identify products needing attention, so that I can start with the most consequential work.
6. As an Inventory Manager, I want products ordered by stock exposure in an action queue, so that scarce review time is spent on the highest-risk items first.
7. As an Inventory Manager, I want each queue item to show current stock and expected demand together, so that the reason for its priority is understandable.
8. As an Inventory Manager, I want Urgent, Watch, and Covered stock-position labels, so that I can scan the queue without interpreting raw numbers first.
9. As an Inventory Manager, I want a Replenishment Recommendation based on expected demand, current stock, and supplier lead time, so that I have a starting point for review.
10. As an Inventory Manager, I want recommendations to remain non-binding, so that Store conditions and supplier constraints can be considered before purchasing.
11. As an Inventory Manager, I want to open a product workspace from the overview or queue, so that I can investigate an individual product before acting.
12. As an Inventory Manager, I want the product workspace to show the 7-, 14-, and 28-day totals together, so that short- and medium-term decisions can be compared.
13. As an Inventory Manager, I want to see the daily Demand Forecast behind each total, so that unusual peaks are visible rather than hidden inside an aggregate.
14. As an Inventory Manager, I want to see scheduled prices and promotions that influence a forecast, so that changes in expected demand are explainable.
15. As an Inventory Manager, I want the interface to identify a Scheduled Demand Driver separately from ordinary demand history, so that I do not assume every increase is a model anomaly.
16. As an Inventory Manager, I want to search products in the product workspace, so that I can inspect a known item quickly.
17. As an Inventory Manager, I want lower-readiness forecasts to remain visible but clearly marked, so that I can use them cautiously instead of mistaking them for mature predictions.
18. As an Inventory Manager, I want products with insufficient history to explain why the result is limited, so that I know more Store history is required.
19. As an Inventory Manager, I want stale or failed Forecast Generation to be obvious, so that I do not make decisions using an outdated run.
20. As an Inventory Manager, I want the last successful run time and model version visible, so that I can establish which forecast I am reviewing.
21. As an Inventory Manager, I want Store closures and stockouts distinguished from genuine zero demand, so that the model does not learn a false lack of customer interest.
22. As an Inventory Manager, I want cancelled and reversed sales excluded correctly, so that expected demand is not inflated by transactions that did not remain valid.
23. As an Inventory Manager, I want daily inventory snapshots available to forecasting, so that stock context can be reproduced and audited.
24. As an Inventory Manager, I want a Store business calendar to record open and closed days, so that missing sales dates have an operational explanation.
25. As an Administrator, I want to review Store-level forecast performance, so that I can understand whether the feature is useful for Store decisions.
26. As an Administrator, I want MAE shown as the primary accuracy measure, so that forecast error is understandable in units.
27. As an Administrator, I want RMSE shown as a secondary measure, so that occasional large errors remain visible.
28. As an Administrator, I want WAPE shown as a business comparison measure, so that error can be compared across products and time windows.
29. As an Administrator, I want evaluation reported separately for 7-, 14-, and 28-day Forecast Horizons, so that good short-term performance does not conceal weak longer-term performance.
30. As an Administrator, I want to request Forecast Generation without retraining, so that the approved model can be applied to current Store data on demand.
31. As an Administrator, I want Forecast Generation to return immediately as a queued job, so that a browser request never waits for Python processing.
32. As an Administrator, I want Forecast Generation failures to use calm operator language, so that technical exceptions do not disrupt Store work.
33. As a Super Administrator, I want to request Forecast Generation, so that I can verify model operation during technical oversight.
34. As a Super Administrator, I want to request Model Retraining manually, so that I can recover or validate the scheduled training process.
35. As a Super Administrator, I want only my role to configure or replace the model, so that operational roles cannot alter the technical basis of Store recommendations.
36. As a Super Administrator, I want every Model Retraining run to record its initiating identity, data window, model version, parameters, metrics, status, and timestamps, so that model changes are auditable.
37. As a Super Administrator, I want a candidate model validated before it replaces the approved model, so that a failed or materially worse model is not promoted automatically.
38. As a Super Administrator, I want the previous approved model retained during promotion, so that the Store can recover from a faulty replacement.
39. As a Super Administrator, I want Forecast Generation runs to record their model version and source-data cutoff, so that every displayed value can be traced.
40. As a Super Administrator, I want worker failures logged with full technical detail while users see safe messages, so that diagnosis remains possible without exposing internals.
41. As a Store operator, I want the system to generate forecasts after close, so that computation does not disrupt active selling and receiving.
42. As a Store operator, I want Model Retraining scheduled weekly after close, so that the model incorporates new history without changing every day.
43. As a Store owner, I want the forecasting worker to run on a Linux VPS when hosted online, so that PHP, MySQL, scheduling, and Python can be managed in one deployment.
44. As a Store owner, I want the same worker to be operable on a dedicated Store PC, so that self-hosting remains possible when the machine is kept powered, backed up, secured, and monitored.
45. As a developer, I want one CLI and database job contract between PHP and Python, so that the deployment does not require Flask or a synchronous HTTP dependency.
46. As a developer, I want one global Random Forest model with product and horizon features, so that the implementation stays simpler than maintaining a separate model for every product.
47. As a developer, I want one training row per product, forecast origin, and future day from 1 through 28, so that the model predicts each future day directly without recursion.
48. As a developer, I want all training features constructed using only information available at the forecast origin, so that validation and production avoid future-data leakage.
49. As a developer, I want future price and promotion values used only when already scheduled, so that the model never assumes unknown future actions.
50. As a developer, I want chronological rolling-origin validation, so that evaluation resembles forecasting future Store demand rather than a random train/test split.
51. As a developer, I want reproducible model artifacts and feature definitions, so that a recorded model version can be regenerated and investigated.
52. As a capstone researcher, I want the production pipeline limited to Random Forest, so that the implementation and defense remain focused and understandable.
53. As a capstone researcher, I want K-means treated only as optional exploratory analysis, so that it does not complicate the production forecasting path.
54. As a capstone researcher, I want ARIMA treated only as an academic comparison if required by the instructor, so that it does not become a second production model.
55. As a capstone researcher, I want a Kaggle single-Store subset with at least two years of daily history and 20 products for initial development, so that the pipeline can be exercised before enough Store history exists.
56. As a capstone researcher, I want the demonstration dataset expandable toward at least 50 products when data permits, so that the evaluation better resembles a useful catalog.
57. As a capstone researcher, I want FreshRetailNet used only as a separate reality check, so that unrelated Store data is not incorrectly presented as a direct accuracy test.
58. As a Store owner, I want demonstration data isolated from live Store data and model artifacts, so that synthetic or external records never influence operational decisions.

## Implementation Decisions

- The production feature will implement the accepted prototype as three connected views: Store overview as the Demand Forecast landing view, product workspace as the drill-down, and action queue as the operational prioritization view.
- Inventory Manager is the primary operational actor. Demand Forecast is a first-level item in the Inventory Manager workspace, not merely a report. Inventory Manager can view forecasts, readiness, evaluation appropriate to Store work, and Replenishment Recommendations, but cannot retrain or configure the model.
- Administrator and Super Administrator may request Forecast Generation. Only Super Administrator may request Model Retraining, change model configuration, approve a candidate model, replace the approved model, or roll back an artifact.
- The production model is one global scikit-learn Random Forest regressor. Product identity or stable product attributes and `horizon_day` allow one model to serve multiple products and days.
- Training uses a direct, horizon-conditioned layout with one example per product, forecast origin, and future day from 1 through 28. Daily predictions are summed into the fixed 7-, 14-, and 28-day Forecast Horizons. Recursive prediction is removed.
- K-means is not part of Forecast Generation or Model Retraining. It may be used in a separately identified exploratory study. ARIMA may be retained as a separately reported academic baseline when required, but it cannot contribute to production output.
- Candidate features include lagged valid unit sales, rolling demand summaries, day-of-week and calendar fields, product/category attributes, available stock context, stockout/closure indicators, and Scheduled Demand Drivers. Features must be available at the forecast origin.
- Future promotion and price features are included only when an effective schedule exists before Forecast Generation. Unknown future values use a documented neutral/no-change representation rather than inferred future knowledge.
- Valid demand excludes cancelled or reversed quantities according to the Store's sale lifecycle. Missing dates are classified using the Store business calendar and inventory evidence; they are not automatically converted to genuine zero demand.
- Add a Store business-calendar representation that can identify open days, closed days, holidays, and exceptional closures.
- Add daily inventory snapshots, or a deterministic snapshot-building process from audited stock movements, sufficient to distinguish likely stockout censoring from no demand. The chosen representation must be reproducible at a historical cutoff.
- Add a persistent forecasting job queue supporting Forecast Generation and Model Retraining job types, queued/running/succeeded/failed states, requester identity or scheduler identity, timestamps, attempts, safe operator message, and technical diagnostic reference.
- Add persisted daily product forecasts containing forecast origin, target date, horizon day, product, predicted units, model version, Forecast Readiness, and any lower-confidence marker. Aggregate 7-, 14-, and 28-day values are derived reproducibly from these daily records.
- Preserve run-level records for source-data cutoff, model version, feature-schema version, data window, parameters, metrics, artifact location, status, trigger type, and initiating identity.
- Preserve horizon-specific evaluation records for MAE, RMSE, and WAPE. Evaluation uses rolling chronological origins and reports results for 7, 14, and 28 days independently.
- Forecast Readiness is Insufficient below 56 usable history days, Low from 56–179, Medium from 180–364, and High from 365 onward only when validation error is acceptable. The acceptable-error threshold is a Super Administrator Platform Setting and never upgrades a product with insufficient history.
- Readiness is an evidence category, not a probability or guaranteed accuracy percentage. Any uncertainty derived from tree spread may support diagnostics but cannot be labeled as calibrated confidence unless calibration is implemented and validated.
- Forecast Generation runs daily after Store close. Model Retraining runs weekly after Store close. Both schedules are configurable by the Super Administrator without allowing them to overlap active Store work by default.
- PHP enqueues work and reads persisted status/results. A Python CLI worker claims jobs atomically from MySQL, processes them idempotently, and writes results transactionally. Interactive PHP requests do not start Python, train a model, or wait for long-running work.
- The existing Flask service, synchronous HTTP call, request-time training path, and Flask environment settings are removed after CLI/job parity is verified. The legacy misspelled forecasting directory is retired or replaced without keeping parallel production paths.
- A job claimed by a crashed worker becomes recoverable through an explicit timeout/lease rule. Retrying cannot duplicate an approved model promotion or duplicate daily forecast rows.
- Candidate model promotion occurs only after the required chronological evaluation succeeds and minimum quality checks pass. A failed candidate leaves the current approved artifact and current Store forecasts intact.
- Model artifacts are stored outside the web-accessible tree and include a model version plus feature-schema version. Loading an incompatible artifact fails closed with a recorded run failure.
- Replenishment Recommendation combines the applicable Forecast Horizon, current stock, scheduled inbound quantities when reliable, supplier lead time, and any minimum-order constraint available through Supplier Product Terms. It does not create or approve a purchase order.
- All manual Forecast Generation and Model Retraining requests are protected by server-side capabilities and CSRF checks. Hiding buttons is not an authorization boundary.
- Forecast Generation requested by Administrator or Super Administrator and all Model Retraining/model-promotion actions are Protected Audit Records.
- The interface follows RetailMind's existing working-stockroom design language. It uses restrained hierarchy, dense operational tables where appropriate, calm status language, no fake precision, and an explicit “decision support” reminder before replenishment review.
- When no successful forecast exists, production shows an honest empty/not-ready state. Illustrative prototype values never appear in production or become persisted forecast data.
- Development and capstone evaluation use an isolated demo database and artifact directory. The initial Kaggle subset selects one Store, at least two full years of daily history, and 20 products, expanding toward 50 when coverage permits. Product identifiers and categories are mapped through a documented import step.
- FreshRetailNet results, if reported, are labeled cross-dataset reality checking. They are not combined with Store metrics and are not described as testing the accuracy of a Kaggle-trained Store model.
- Windows with XAMPP remains the development environment. The recommended online deployment is a Linux VPS with PHP, MySQL, a Python virtual environment, a scheduler, process locking, backups, HTTPS, and monitoring. Shared PHP hosting without a persistent/scheduled Python runtime is not a supported deployment.
- A dedicated Store PC is a supported self-host option only when it is reliably powered, protected from sleep during scheduled work, secured, backed up, and monitored. A normal employee workstation is not treated as a dependable server.
- Migration retains existing forecasts until the replacement worker produces a verified run. Existing 30-day output is not relabeled as 28-day output; new 28-day forecasts are regenerated using the new contract.

## Testing Decisions

- Tests assert externally observable behavior rather than Random Forest tree structure, exact estimator internals, private helper methods, or pixel-perfect prototype markup.
- The primary end-to-end seam is the authenticated Demand Forecast page plus persisted MySQL jobs/results. Tests seed Store data and role sessions, enqueue or complete jobs through the supported application/worker boundaries, then assert visible status, authorization, horizons, readiness, and recommendations.
- The Python seam is one CLI/database job contract. Given a fixture database at a known cutoff and deterministic training configuration, tests assert claimed-job transitions, persisted artifact metadata, daily forecast coverage, aggregate consistency, evaluation records, and safe failure behavior.
- Role-capability contract tests assert that Inventory Manager can view Demand Forecast but cannot generate or retrain; Administrator can view and request Forecast Generation but cannot retrain/configure/promote; Super Administrator can perform the technical model operations.
- Sidebar/workspace contract tests assert that Inventory Manager sees Demand Forecast in the primary operational group and that unauthorized roles do not gain forecasting controls through navigation changes.
- Forecast-data integration tests cover completed sales, cancelled sales, reversals, missing open days, recorded closures, stockout days, returns where applicable, and scheduled versus unscheduled prices/promotions.
- Leakage tests construct future changes after a forecast origin and verify they cannot alter features for that origin unless they were already scheduled and visible at the cutoff.
- Horizon tests verify that every successful run writes 28 daily values per eligible product and that the 7-, 14-, and 28-day displayed totals equal the corresponding daily sums.
- Chronological evaluation tests verify that training rows precede their targets and that rolling-origin folds never train on dates at or after the evaluated target window.
- Metric tests use small hand-calculated fixtures to verify MAE, RMSE, and WAPE, including zero-demand safeguards for WAPE.
- Forecast Readiness tests cover boundary days 55/56, 179/180, and 364/365 plus acceptable/unacceptable error, stale forecasts, unavailable metrics, and insufficient usable history after excluding invalid days.
- Queue tests verify atomic claim, lease expiry, idempotent retry, duplicate prevention, concurrent worker behavior, and preservation of the currently approved model after candidate failure.
- Artifact tests verify version compatibility, missing/corrupt artifact failure, safe rollback, and rejection of a candidate that fails required validation.
- UI acceptance tests cover Store overview, product workspace, and action queue at desktop and narrow viewports; they verify honest empty, loading/queued, running, failed, stale, lower-readiness, and successful states.
- Existing role-policy, workspace-routing, friendly-alert, audit, inventory, sales-history, promotion, supplier, and purchase-order contract tests provide prior art and must remain passing.
- A smoke test exercises the daily worker against an isolated demo database. It must never use or modify the live Store database, production model artifact, or production job queue.
- Model-quality acceptance is evaluated on held-out chronological periods and compared with a simple seasonal-naive baseline. Exact thresholds are recorded with the capstone experiment rather than hard-coded into unit tests, while promotion rules consume configurable approved thresholds.

## Out of Scope

- Multi-Store or branch-specific forecasting.
- Automatic purchase-order creation, approval, or submission.
- Automatic stock adjustment based on a forecast or recommendation.
- A production ensemble, model-selection marketplace, or simultaneous Random Forest/ARIMA/K-means pipeline.
- K-means as a forecasting model.
- ARIMA as a production fallback.
- Real-time retraining after every sale.
- User-selected arbitrary Forecast Horizons outside 7, 14, and 28 days.
- Probabilistic prediction intervals or calibrated confidence percentages unless separately designed and validated.
- Treating FreshRetailNet as a direct accuracy test for a Kaggle- or Store-trained model.
- Mixing demo/Kaggle data with operational Store data.
- Shared hosting deployments that cannot run and schedule the supported Python worker.
- Implementing the throwaway prototype switcher or its illustrative values in production.

## Further Notes

- ADR-0005 records the core Random Forest, asynchronous worker, role, evaluation, and deployment decisions and this specification expands them into implementable behavior.
- The current implementation already contains useful sales, inventory, promotion, supplier, forecast-run, prediction, evaluation, and training-run concepts. The implementation should migrate and deepen those seams instead of creating a second unrelated forecasting subsystem.
- The current Forecast page is already accessible through the shared Store-report capability. Authorization must be separated into narrower view, generate, retrain, and configure capabilities before controls are implemented.
- The accepted prototype remains a design reference only. Variant A supplies the landing hierarchy, Variant B supplies product investigation, and Variant C supplies the Inventory Manager's prioritized action queue.
- The repository currently has a stale local `APP_URL` value pointing to `/inventory_system` while the working checkout is served from `/retailmind`. Local configuration should be corrected independently so generated links remain reliable.
