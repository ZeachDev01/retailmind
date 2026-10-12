# Complete Demand Forecasting, validate with isolated demo data, and improve the Store Calendar

## Problem Statement

RetailMind needs dependable Demand Forecasts that connect real demand evidence, background model work, evaluation, and clear operational screens. Investigation found a partially implemented pipeline: queued jobs and persisted forecast readers exist, but the scheduler invokes an unsupported worker flag, generation queries incompatible product columns, demand inputs and readiness are placeholders, retraining reports success without training, and approved-model changes do not control inference. Failures can remain running instead of producing actionable status.

Presentation is also incomplete. Analytics expects different metric names from those returned by the backend; readiness queries use an undefined table alias; exceptions duplicate products and mislabel a 28-day horizon as 30 days; missing confidence becomes zero; missing forecasts can appear Covered; and the Replenishment Requests page has a confirmed PHP syntax error. Existing successful helper checks and simulated browser output do not demonstrate a working pipeline.

The user supplied a specific Kaggle CSV for demo development and wants a deliberate path to real Store operations after the pipeline is verified. Demo success must not be confused with Store accuracy or justify copying demo data or model artifacts into operational use.

The current Store Calendar is a date/status form above a recent-date table. The Administrator needs a recognizable monthly calendar, visible Open-day indicators, and quicker status entry for one date or several consecutive dates. The page also calls the current single-Store calendar service with obsolete Store/branch arguments.

## Solution

Finish the existing single-Store asynchronous Random Forest pipeline described by ADR-0005 and the production Demand Forecasting specification. Develop and demonstrate it using the selected Kaggle dataset in an isolated demo environment. Connect actual worker output to the existing overview, product investigation, evaluation, and replenishment views. Prove both correct operation and honestly measured model quality before considering live activation.

Transfer the verified pipeline code and evidence contracts to Store operations, then separately train and validate a Store-specific approved model on real Store history. Keep low readiness, missing evidence, staleness, and failures visible. Every Replenishment Recommendation remains non-binding and requires human review.

Replace the Store Calendar's recent-date table with a monthly calendar in RetailMind's existing visual style. Clicking a day opens or populates an adjacent editor. The Administrator chooses a status and saves; an optional through date applies the same status to an inclusive range. Clearly distinguish Open, Closed, Holiday, Exceptional closure, and Not recorded. Preserve historical records and audit attribution.

The user's latest instruction authorizes specification work only. Publication and the `ready-for-agent` label organize future work; neither authorizes implementation or live activation during this session.

## User Stories

1. As an Inventory Manager, I want reliable Demand Forecasts in my primary workspace, so that forecasting is part of routine inventory work.
2. As an Inventory Manager, I want fixed 7-, 14-, and 28-day totals, so that I can plan over consistent periods.
3. As an Inventory Manager, I want product names and SKUs beside forecast values, so that I do not need internal product IDs to understand results.
4. As an Inventory Manager, I want to find a product by recognizable information, so that investigation is quick.
5. As an Inventory Manager, I want daily forecast values behind each total, so that peaks and unusual patterns remain visible.
6. As an Inventory Manager, I want current stock and expected demand together, so that exposure is understandable.
7. As an Inventory Manager, I want an action queue ordered by replenishment risk, so that I review the most consequential items first.
8. As an Inventory Manager, I want Urgent, Watch, and Covered labels only where forecast evidence exists, so that missing data is not mistaken for safety.
9. As an Inventory Manager, I want missing forecasts explicitly identified, so that I know when judgment must rely on other evidence.
10. As an Inventory Manager, I want recommendations to reflect supplier lead time, reliable inbound quantities, and minimum-order constraints, so that proposed quantities are practical.
11. As an Inventory Manager, I want to review recommendations before purchasing, so that forecasts never automatically place orders or change stock.
12. As an Inventory Manager, I want the replenishment review page to work, so that I can complete the human decision workflow.
13. As an Inventory Manager, I want readiness and its limitations visible, so that early forecasts are not presented as mature evidence.
14. As an Inventory Manager, I want failed or stale generation clearly marked, so that older results do not look current.
15. As an Inventory Manager, I want the displayed run date, model version, and source cutoff, so that I know which evidence I am reviewing.
16. As an Inventory Manager, I want scheduled drivers shown as known at the displayed forecast's origin, so that later plans are not presented as its inputs.
17. As an Administrator, I want to request Forecast Generation without Model Retraining, so that current data can be evaluated with the approved model.
18. As an Administrator, I want requests to return promptly with job status, so that the browser does not wait for Python processing.
19. As an Administrator, I want calm, useful failure messages, so that staff can respond without seeing technical internals.
20. As an Administrator, I want MAE, RMSE, and WAPE reported separately for every supported horizon, so that weak longer-term results cannot be hidden by short-term results.
21. As an Administrator, I want actual-versus-predicted comparisons to use identical dates and valid demand treatment, so that reported error is meaningful.
22. As an Administrator, I want evaluation tied to the displayed model and run context, so that unrelated candidate results do not appear to validate Store forecasts.
23. As a Super Administrator, I want real chronological candidate evaluation before promotion, so that an unacceptable model cannot replace the approved model.
24. As a Super Administrator, I want manual and weekly Model Retraining to actually fit and evaluate a candidate, so that successful status means completed work.
25. As a Super Administrator, I want promotion and rollback to control the artifact used for inference, so that model governance is effective.
26. As a Super Administrator, I want failed candidates to leave approved forecasts and artifacts intact, so that experimentation does not interrupt operations.
27. As a Super Administrator, I want job attempts, status, initiator, model metadata, and diagnostic references recorded, so that runs are accountable and diagnosable.
28. As a Super Administrator, I want recoverable worker leases and idempotent retries, so that crashes cannot duplicate forecasts or promotions.
29. As a Super Administrator, I want missing or incompatible artifacts to fail safely, so that the application never silently uses an unrelated model.
30. As a Super Administrator, I want daily generation and weekly retraining scheduled after Store close, so that forecasting does not interfere with Store work.
31. As a Super Administrator, I want worker health and missed-run visibility, so that a stopped scheduler does not leave apparently healthy results indefinitely.
32. As a developer, I want the chosen CSV recorded as the demo source, so that future work uses the agreed dataset.
33. As a developer, I want reproducible single-Store imports with source checksums and mappings, so that experiments can be repeated and traced.
34. As a developer, I want the import to reject unsafe destinations and invalid records before mutation, so that demo development cannot damage Store data.
35. As a developer, I want observed Units Sold as the target and the supplied Demand Forecast excluded, so that evaluation does not learn from another prediction.
36. As a developer, I want historical features limited to information available at each origin, so that future values and labels cannot leak into training.
37. As a developer, I want inconsistent category and region fields excluded initially, so that unstable source labels do not become product or Store truth.
38. As a developer, I want unknown calendar, stockout, refund, and schedule evidence preserved as unknown, so that the import does not invent operational history.
39. As a developer, I want one shared feature and inference pipeline for demo and Store evidence, so that integration does not require a second forecasting engine.
40. As a capstone researcher, I want chronological holdout results compared with a seasonal baseline, so that reported performance has an honest reference.
41. As a capstone researcher, I want demo metrics clearly labeled, so that they are not represented as real Store accuracy.
42. As a Store owner, I want a separately trained and evaluated Store model before activation, so that demo product identities and patterns do not drive purchasing.
43. As an Administrator, I want forecasts observed against completed Store target periods before activation, so that operational usefulness is demonstrated.
44. As a Super Administrator, I want forecast recommendations suspendable without stopping sales or inventory work, so that recovery is practical.
45. As an Administrator, I want a monthly Store Calendar, so that open days and closures are understandable at a glance.
46. As an Administrator, I want clear Open-day indicators with text as well as color, so that statuses remain accessible and unambiguous.
47. As an Administrator, I want to select a calendar day and edit its current status, so that recording a date does not require repetitive date entry.
48. As an Administrator, I want an optional through date, so that a continuous period can receive one status in a single save.
49. As an Administrator, I want the affected dates and replacement status clear before saving, so that range changes are deliberate.
50. As an Administrator, I want all dates in a range and their audit entries saved together, so that failures cannot leave a partially updated calendar.
51. As an Administrator, I want corrections to preserve prior status history, so that historical forecasting evidence remains reproducible.
52. As an Administrator, I want Not recorded distinct from Open, so that the calendar does not fabricate evidence for forecasting.
53. As an Administrator, I want previous/next month navigation and a Today shortcut, so that past corrections and future plans are easy to reach.
54. As an Administrator, I want keyboard-accessible controls and a usable narrow-screen calendar, so that the workflow works across devices and input methods.
55. As a Store operator, I want calendar changes to describe trading status without automatically adjusting stock or orders, so that planning evidence stays separate from operational transactions.

## Implementation Decisions

### Confirmed scope and architecture

- Retain the existing production forecasting specification and ADR-0005 as the architectural foundation. This spec consolidates the investigation, selected demo source, staged integration, and newly agreed Store Calendar workflow.
- Use one global Random Forest, with one direct training example per product, forecast origin, and horizon day 1–28. Sum daily predictions into 7/14/28-day totals. Do not recursively treat predictions as observed demand.
- PHP authorizes and queues jobs; a Python CLI worker processes them outside web requests; PHP reads persisted results. No synchronous Flask dependency or request-time retraining.
- Reuse the existing StoreCalendar, DemandHistoryService, ScheduledDemandDriverService, MlJobService, ForecastingSchedulerService, DemandForecastService, CandidateEvaluationService, ModelPromotionService, and ReplenishmentQueueService boundaries where correct. Repair incomplete behavior rather than introducing a parallel system.
- Preserve roles: Inventory Manager views forecasts and uses recommendations; Administrator may generate and reviews operational usefulness; Super Administrator alone governs retraining, model configuration, promotion, and rollback. Maintain backend authorization, CSRF, and Protected Audit Records.
- Forecast Readiness remains Insufficient below 56 usable history days, Low at 56–179, Medium at 180–364, and High from 365 only with acceptable validation error. History length alone never guarantees quality. Lower-readiness output stays visible and marked.

### Selected demo dataset

- The user selected Anirudh Chauhan's Retail Store Inventory Forecasting Dataset on Kaggle as the source for current demo development. The supplied file is named `retail_store_inventory.csv`.
- Local inspection found 73,100 records, 15 columns, five stores, 20 products per store, and 731 consecutive dates from 2022-01-01 through 2024-01-01. Each store has 14,620 records and complete daily product coverage. No duplicate daily keys or blank cells were found.
- Proposed deterministic subset: S001 and its 20 products. This satisfies the initial single-Store, two-year, 20-product development requirement. The source does not support expanding to 50 distinct products within one Store; do not duplicate or relabel other stores to manufacture coverage.
- Use Units Sold as observed daily sales, with an explicit limitation that observed sales do not prove unconstrained demand. Exclude the source's Demand Forecast field from inputs and targets; it contains 673 negative values and its production methodology is unverified.
- Every Store/product series spans all five categories, and every Store/date group spans three or four regions. Exclude these unstable fields initially and do not use them to populate factual Store/product master attributes. Use transparent demo labels where names are absent.
- Start with product identity, horizon day, lagged/rolling observed sales, and date-derived calendar features. Future prices/promotions require evidence that their schedules existed by the origin. The combined Holiday/Promotion field does not establish separate holidays, closures, or advance scheduling.
- Preserve Inventory Level without assuming start/end-of-day timing or inventing stock movements. Units Ordered is not a receipt, delivery date, or reliable inbound quantity. Weather, competitor pricing, and source seasonality are not initial production features without verified semantics and origin-time availability.
- Correct the existing importer, which targets a different Kaggle format and numeric IDs. Validate explicit headers, types, dates, bounds, duplicates, and selected coverage before writing. Imports must be reproducible and idempotent, with failed imports preserving the previous valid state.
- Preserve the raw source and original dates. Record checksum, source selection, mapping, import/feature versions, data window, and exclusions. Do not manufacture operational cashier transactions, shifts, refunds, receipts, or stock evidence from daily aggregates.
- Isolate demo database, queue, artifacts, and configuration. Validate actual destinations; name substrings alone are insufficient. A demo artifact must not load in operational inference.
- Introduce schema changes only if the existing daily-evidence/provenance representation cannot preserve source meaning. No table design or schema migration was approved in this conversation.

### Backend correctness and model evaluation

- Fix the scheduler/worker command mismatch and OS-specific launch behavior; generation must query the actual product schema and use real prepared evidence.
- Implement both job types. A successful retraining job must represent actual fitting and evaluation. Failures must persist failed status and safe messages, with technical diagnostics available to the appropriate role.
- Resolve inference artifacts from approved model metadata; validate compatibility and origin environment. Preserve a usable prior approved artifact and ensure rollback changes subsequent inference.
- Produce exactly 28 daily values per eligible product with run/model/cutoff metadata. Job completion and result persistence must not expose partial output as successful. Bound retries and preserve atomic claims, lease recovery, and idempotency.
- Chronological validation must exclude training rows whose target labels occur after the origin cutoff. Splitting on the training origin alone is insufficient for a horizon-conditioned model.
- Proposed evaluation default: reserve the final 84 observed dates as an untouched holdout, evaluated in three non-overlapping 28-day blocks; use earlier dates for development and rolling-origin validation. Freeze feature/training decisions before final testing. This is a planning recommendation, not an already executed experiment.
- Evaluate Random Forest and a weekly seasonal-naive baseline over the same products and origins. Report MAE first, RMSE second, and WAPE with explicit units, independently for 7/14/28 days, per product and in Store aggregates. Missing and zero-demand metrics require explanations.
- Numeric quality thresholds remain to be set by the Super Administrator before acceptance, not inferred from desired results or tuned after observing the holdout. Engineering readiness and predictive usefulness are separate gates.

### Forecast presentation and replenishment

- Repair analytics metric-name mismatches, undefined readiness joins, exceptions duplication, erroneous confidence percentages, and legacy 30-day labels. Do not relabel old 30-day results as 28-day results.
- Tie overview, product investigation, analytics, and queue output to consistent run/model/horizon contracts. Show freshness, readiness limitations, safe failed status, and the last successful run without disguising it as current.
- Treat missing forecasts as unknown, never zero demand or Covered. Use reliable inbound timing when assessing exposure; do not assume every outstanding order arrives within lead time.
- Repair Replenishment Requests rendering and preserve human review through existing purchasing controls. Forecast calculations must never automatically create/approve orders or change stock.
- Display drivers known at the shown run's cutoff rather than whatever is scheduled today. Demo screens and metrics must be explicitly identified as demo data.

### Store Calendar interaction and evidence

- User-approved interaction: monthly calendar with click-to-edit single dates and an optional inclusive through-date range. This replaces the recent-date list as the main presentation, preserving the current application's style.
- Show Open, Closed, Holiday, Exceptional closure, and Not recorded using readable labels plus visual indicators. Dates without evidence remain Not recorded; no automatic Open default, weekend rule, or holiday inference.
- Provide previous/next month navigation, a Today shortcut, and a clear selected day. Selecting a date loads its status into the editor; selection alone never saves. On narrow screens the editor may stack beneath the calendar.
- The editor provides date, optional through date, status choice, affected-range feedback, and an explicit save action. A range replaces statuses within its bounds only; show this consequence before saving. Already matching dates need no duplicate history entries.
- Validate dates and status on the server, reject reversed or excessive ranges, and retain form values with a friendly error. Proposed implementation safeguard: at most 366 inclusive days per save. This limit is a recommended default, not a separately user-confirmed policy.
- Save calendar changes and their per-date audit entries in one transaction. Preserve append-only history; never delete old status entries when correcting a date.
- Fix obsolete branch arguments at the page/service boundary. Use Philippine business dates consistently, and resolve equal-timestamp entries deterministically so corrections remain consistent between single-date and range reads.
- Preserve current holiday behavior: Holiday denotes a closed trading day for forecasting; choose Open when trading on a holiday. Independent holiday labels plus open/closed status would require a separate domain change and are not part of this spec.
- Ensure Exceptional closure uses a consistent status value across calendar storage and demand-history classification. Audit the current hyphen/underscore mismatch when implementing this path.
- Retain Administrator authorization, CSRF protection, history attribution, accessible labels, visible keyboard focus, and theme compatibility. Date status does not itself block sales or adjust inventory.
- Recurring weekly hours or bulk weekday rules were not requested or agreed. Do not add them as part of the initial calendar change.

### Delivery and live integration sequence

1. Establish a validated isolated demo import and repair one real queue-to-worker-to-persisted-results flow.
2. Complete actual retraining, chronological evaluation, approved artifact selection, promotion, and failure recovery.
3. Repair forecasting UI contracts and implement the agreed Store Calendar workflow; verify both through the same existing application boundaries used by staff.
4. Demonstrate the full flow with real worker execution in demo, including edge cases supplied by clearly labeled fixtures where the Kaggle data lacks operational evidence.
5. Prepare Store-derived evidence from actual sales, reversals/refunds, calendar history, stock evidence, schedules, and supplier terms. Train a separate Store candidate using the same feature definitions and pipeline code.
6. Run Store forecasts in observation mode before routine purchasing guidance. Proposed minimum: 28 completed target days, extended until every required horizon is evaluable and quality thresholds pass. Insufficient data must remain visibly limited rather than bypassing readiness.
7. Activate only after Store-specific evaluation, operational review, scheduled-job verification, and recovery checks. Super Administrator owns technical approval; Administrator reviews operational usefulness; Inventory Manager reviews replenishment actions.
8. Preserve prior approved Store artifacts and matching metadata, monitor failures/staleness, and support suspension of forecast recommendations without stopping ordinary Store operations. Never fall back to a demo model.

## Testing Decisions

- Primary acceptance seam: the authenticated application workflow plus persisted database jobs/results and the actual CLI worker. Enqueue through supported boundaries, execute real processing, and verify visible results against persisted daily records. Do not substitute simulated worker completion for this acceptance test.
- Calendar acceptance uses the authenticated Store Calendar page and its persisted historical evidence: click a date, save one status, save an inclusive range, reload, and verify visible indicators plus historical cutoff reads. This complements the same staff-facing application/database seam instead of inventing another testing framework.
- Reuse existing forecast aggregation, navigation/role, queue, calendar, demand-history, scheduler, scheduled-driver, and browser-workflow test patterns. Repair stale contracts before relying on their results.
- Test observable behavior rather than exact Random Forest trees, private methods, markup snapshots, or arbitrary test counts.
- Use an explicitly isolated test database. Existing integration suites include destructive cleanup; never run them against Store data. Application bootstrap failure must be reported as blocked, not a passing process status.
- Import checks cover wrong dataset headers, unsafe destinations, duplicates, invalid numeric/date values, stable mappings, source coverage, repeated imports, and rollback. Verify data/artifact separation between demo and Store environments.
- Worker checks cover actual retraining, generation coverage, no-op/failed candidate promotion, approved artifact use, corrupt/missing/incompatible artifacts, unauthorized actions, lease recovery, retries, concurrent claims, duplicate prevention, and atomic completion.
- Leakage checks change observations or schedules after an origin and verify that earlier features and training labels cannot change improperly. Include future target-label leakage and known-versus-unknown scheduled drivers.
- Quality checks cover hand-calculated MAE/RMSE/WAPE, zero-demand WAPE, chronological folds, consistent seasonal baselines, fixed horizon aggregation, and honest source/model attribution. Accuracy is evaluated, never assumed from unit-test success.
- Readiness checks cover 55/56, 179/180, and 364/365 days; unacceptable/missing metrics; excluded evidence; failed generation; staleness; and no forecast being shown as Covered.
- Store evidence checks cover valid sales, returns/reversals/cancellations as defined by the domain, open days with no sales, closures, stockouts, unavailable evidence, and historical cutoff reproduction.
- Calendar checks cover leap days, month/year navigation, Philippine date boundaries, unrecorded dates, keyboard selection, single-day edits, cross-month ranges, reversed/overlong ranges, no-op saves, equal-timestamp corrections, audit attribution, and transaction rollback when any date/audit write fails.
- Calendar security checks cover non-Administrator access, direct requests, CSRF, invalid status/date inputs, and escaped content. Verify Holiday and Exceptional closure classifications reach demand history correctly.
- UI checks cover desktop and narrow layouts, supported themes, readable status indicators without relying on color alone, queued/running/success/failed/stale/empty states, truthful error metrics, correct product/date labels, and explicit review before purchasing.
- Before live activation, verify the actual Windows or Linux scheduler, healthy and missed runs, Store-trained artifact loading, recovery from failed promotion, and suspension of recommendations without disrupting sales/stock workflows.

## Out of Scope

- Application implementation, imports, training, deployment, or live activation during this specification-only request.
- Mixing demo data, product identifiers, model weights, forecasts, readiness, or evaluation metrics into Store operations.
- Multi-Store production forecasting or pooling external stores to manufacture single-Store history.
- Automatic purchase orders, order approvals, stock adjustments, or sales restrictions triggered by forecasts/calendar status.
- Random train/test splits, recursive forecasting, arbitrary horizons, or treating the supplied Demand Forecast as observed demand.
- ARIMA/K-means as production pipelines, real-time training after every sale, or a model marketplace.
- Calibrated probability/confidence percentages without a separately validated method.
- Invented operational evidence, guessed future promotions/weather, or fabricated performance claims.
- Recurring calendar rules, opening-hour management, national holiday synchronization, and independent holiday/open-status dimensions.
- Redesigning unrelated application surfaces, replacing the established visual identity, or rewriting unaffected operational modules.
- Publishing the source dataset or assuming its license terms have been verified.

## Further Notes

- Source selected by the user: https://www.kaggle.com/datasets/anirudhchauhan/retail-store-inventory-forecasting-dataset. The page yielded no readable publisher description during inspection; the source association is user-provided. Methodology and redistribution terms remain unverified.
- Supplied local source: `src/backend/dataset/retail_store_inventory.csv`; 6,191,463 bytes; SHA-256 `c7b6019887d85e6bb311a76da72560463e6bf269bfadbe4b326fbff3b1000468`.
- Requested calendar surface: http://retailmind.test/src/frontend/components/administrator/store_calendar.php.
- Existing supporting documents: the production Demand Forecasting specification, ADR-0005, the forecasting implementation review, and the demo-to-operations plan. This consolidated spec captures the full conversation; it does not imply earlier recommendations were individually approved.
- Confirmed by the user: keep the existing production spec as the repair target; use this CSV as demo data for current development; plan eventual integration into Store operations; provide a monthly calendar with Open indicators; support single-day editing plus an optional date range; do not code yet.
- Recommended defaults, not claimed as settled policy: S001 subset selection, final 84-date holdout layout, at least 28 completed Store target days of observation, and a 366-day inclusive calendar save limit. Future implementation should preserve these distinctions.
- Remaining activation decisions: actual production runtime (dedicated Windows Store PC or Linux VPS), numerical per-horizon quality thresholds set before evaluation, and operational activation after real Store evidence passes. No guaranteed accuracy or activation date was agreed.
- Investigation checks passed for horizon aggregation, chronological split helpers, and metric/baseline helpers. The worker flag failure and Replenishment Requests syntax failure were reproduced. Some broader contracts stopped at recovery coordination during bootstrap. No live Store database tests, real model fitting, authenticated calendar save, or predictive accuracy verification was completed.
- The browser reached the public login surface rather than an authenticated Administrator calendar. Visual and authenticated workflow verification therefore remain implementation acceptance work.
- The interrupted calendar edit removed the original page before a replacement was written; it was restored from the unchanged repository version. No application-code modification is part of this specification delivery.
