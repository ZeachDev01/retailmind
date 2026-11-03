# Align forecasting settings and demonstrate the pipeline with isolated M5 data

## Problem Statement

The Super Administrator cannot reliably understand or control Demand Forecasting because Data Readiness and ML Operation describe different policies. Data Readiness uses a fixed Training Eligibility policy, while ML Operation exposes minimum/preferred history, history-window, forecast-horizon, retraining-frequency, sales-record, accuracy, percentile, and Random Forest controls that do not govern the current pipeline. A successful save can therefore imply a behavior change that never occurs.

The user also wants to see the real pipeline working with M5 data before relying on it: history, eligibility, background training, evaluation, observation, model review, forecasts, and replenishment guidance. The demo must explain these stages without contaminating the operational Store or claiming that external-data performance establishes Store accuracy.

The latest instruction requests a specification before implementing the demo. Creating and publishing this spec does not start database provisioning or application implementation.

## Solution

Make the existing forecasting pages describe one shared policy, expose only settings consumed by the current pipeline, and let the Super Administrator choose a weekly Model Retraining day and time. Present schedule controls and times in AM/PM, explicitly in Philippine time. Explain the differences between Training Eligibility, candidate qualification, observation, approval, and daily Forecast Generation.

Prepare a separate local M5 Demo using the existing importer, shared Random Forest worker, and historical comparison screens. Use 50 reproducibly selected food products from M5 store CA_1, retaining their original dates and anonymous product identities. Provide a browser walkthrough that shows actual results and blocked transitions when a candidate fails; do not manufacture a successful approval or bypass quality gates.

## User Stories

1. As a Super Administrator, I want Data Readiness and ML Operation to show the same Training Eligibility policy, so that I can trust their explanations.
2. As a forecast viewer, I want progress displayed as usable days collected out of required days, so that 120 of 140 days is not mistaken for a 120-day requirement.
3. As a forecast viewer, I want to understand which days count as usable, so that missing records and stockouts are not mistaken for observed zero demand.
4. As a Super Administrator, I want the current 140-usable-day requirement shown read-only, so that an ineffective history setting cannot suggest that I weakened eligibility.
5. As a forecast viewer, I want recent-input and historical evaluation blockers explained, so that reaching 140 days does not imply automatic eligibility.
6. As a Super Administrator, I want disconnected minimum, preferred, nonzero-sales-day, and maximum-history controls removed, so that every available control has a real effect.
7. As an Inventory Manager, I want fixed 7-, 14-, and 28-day Forecast Horizons, so that forecast totals remain consistent across pages.
8. As a Super Administrator, I want to choose the weekly training weekday, so that background work suits the Store's operating pattern.
9. As a Super Administrator, I want to choose the weekly training time in AM/PM, so that I can configure it without converting a 24-hour clock.
10. As a Super Administrator, I want the time range and timezone explained in a tooltip, so that noon, midnight, and the selected hour are unambiguous.
11. As a Super Administrator, I want Sunday at 1:00 AM as the default weekly schedule, so that the existing default remains recognizable.
12. As a Super Administrator, I want saved schedule changes to control the actual scheduler, so that the form and background behavior agree.
13. As a Super Administrator, I want initial automatic training at the next selected training time after eligibility is detected, so that the first model need not wait for the weekly weekday.
14. As a Super Administrator, I want missed training to catch up when the PC starts, so that downtime does not postpone model work for another week.
15. As a Super Administrator, I want catch-up to require eligible new history and prevent duplicate jobs, so that startup does not create repeated training runs.
16. As a Super Administrator, I want manual training to remain available, so that I can deliberately request a run when needed.
17. As a Store operator, I want daily Forecast Generation explained separately from Model Retraining, so that I know predictions can update without rebuilding the model daily.
18. As a Super Administrator, I want the disconnected interval and new-sales-record controls removed, so that they cannot imply competing automatic triggers.
19. As a Super Administrator, I want separate working WAPE limits for the three Forecast Horizons, so that configured acceptance criteria match evaluation.
20. As a model reviewer, I want WAPE described as error with lower values better, so that it is not presented as an accuracy guarantee.
21. As a model reviewer, I want candidates compared against repeating last week's sales, so that a complex model must justify its use.
22. As a forecast viewer, I want unsupported percentile controls removed, so that a point forecast is not presented as a measured uncertainty interval.
23. As a Super Administrator, I want the actual Random Forest recipe displayed read-only, so that the page explains what training really uses.
24. As a model reviewer, I want a completed training run distinguished from a qualified candidate, so that successful execution is not confused with passing evaluation.
25. As a local Administrator, I want the required observation evidence and approval gates retained, so that an automatic training run cannot silently replace the operational model.
26. As a local Administrator, I want a failed or rejected candidate to leave the approved model unchanged, so that routine forecasts remain controlled.
27. As a user learning the system, I want a separately identified M5 Demo, so that external Walmart observations are never mistaken for this Store's sales.
28. As a Super Administrator, I want the M5 Demo in a separate database with isolated model identity and sessions, so that experimentation cannot modify operational records or approvals.
29. As a user learning the system, I want original M5 dates and product codes retained, so that the demonstration does not fabricate current Store history.
30. As a user learning the system, I want 50 CA_1 food products with sufficient historical coverage, so that I can inspect several realistic sales patterns through one shared pipeline.
31. As a Super Administrator, I want source provenance and imported counts recorded, so that the prepared dataset can be checked and reproduced.
32. As a user learning the system, I want to watch an actual queued training job complete, so that the demonstration proves background processing rather than displaying a mock result.
33. As a model reviewer, I want real model and baseline results shown even when they fail, so that the demonstration remains honest.
34. As a user learning the system, I want historical predicted-versus-actual comparisons, so that I can see where the model overpredicts or underpredicts.
35. As an Inventory Manager, I want historical demo quantities clearly separated from approved operational guidance, so that I do not use an unapproved experiment as a purchasing instruction.
36. As an Inventory Manager, I want expected sales separated from suggested purchase quantities, so that existing stock and stock rules remain understandable.
37. As an Inventory Manager, I want unavailable or unapproved forecasts to fall back to explained stock rules, so that replenishment remains usable without invented forecast coverage.
38. As a user learning the system, I want links between history, training, review, forecast, analytics, exceptions, and replenishment pages, so that I can follow the pipeline in order.
39. As a Super Administrator, I want local restart instructions and demo-only login details, so that I can reopen the prepared demonstration later.
40. As a Store owner, I want the operational database and configuration preserved, so that preparing the demonstration cannot replace real sales, stock, users, or model approvals.
41. As a keyboard or mobile user, I want accessible time controls and explanatory tooltips, so that I can use the same scheduling workflow without a mouse or large display.

## Implementation Decisions

- Reuse the existing asynchronous architecture: PHP checks authorization and queues jobs, the Python CLI worker performs training and inference, and pages read persisted results. Retain one shared global Random Forest and fixed 7/14/28-day Forecast Horizons.
- Keep Training Eligibility read-only: at least 140 accumulated usable days per Product, a supported recent 28-calendar-day input window, and three supported chronological 28-day evaluation periods backed by earlier training history. Supported genuine-zero observations count; closures, unknown observations, and Stockout Days do not add usable days. Supported closures need not themselves invalidate an otherwise supported input/evaluation window.
- Reuse StoreTrainingEligibilityService and the shared history reader as the source of eligibility decisions. Remove legacy explanations that still let disconnected settings change the apparent rule. Training Eligibility is distinct from the existing Forecast Readiness evidence bands; this work does not redesign those bands.
- Update Data Readiness and ML Operation to display the shared policy and actionable blockers. Keep the accumulated usable-day count separate from elapsed calendar span and days with sales.
- Remove writable controls for minimum/preferred/nonzero-sales-day history, maximum training history, and arbitrary forecast horizon. Do not introduce a replacement configurable eligibility policy or a new history-truncation rule.
- Reuse ForecastingSchedulerService and the existing operational schedule settings. The Super Administrator can edit the weekly training weekday and time. Store only one authoritative effective schedule through the existing settings mechanism; preserve existing explicitly selected schedules rather than resetting them to defaults.
- Present the training-time control and displayed schedule times in 12-hour AM/PM, with minute precision and explicit Asia/Manila timezone. The selectable range is 12:00 AM through 11:59 PM. Do not depend on the browser's locale to guarantee AM/PM presentation. Internal schedule storage may retain the existing 24-hour representation.
- Use this time-control tooltip: "Select a time from 12:00 AM to 11:59 PM, Philippine time (Asia/Manila). Prefer a time after Store close. Missed training runs catch up when the PC starts." Permit the full stated range; after-close timing is guidance rather than a newly invented Store-hours restriction.
- Keep Sunday at 1:00 AM as the weekly default. A regular run becomes due on the selected weekday at the selected time and starts on the first available scheduler check, subject to worker availability and eligibility.
- Initial automatic training becomes due at the next occurrence of the selected training time after eligibility is detected, regardless of weekday. For example, eligible history detected Monday at 10:00 AM with a 1:00 AM selection schedules initial training for Tuesday at 1:00 AM. Do not reinterpret first detection after the selected hour as immediate permission to train during Store operations.
- A missed scheduled run, including a previously due initial run, catches up on the first scheduler check after startup without waiting for the selected time or weekday. Determine whether a run was missed from the effective schedule and run evidence; the existing fixed seven-day-age shortcut is insufficient for this contract. A training opportunity that was never due is not a missed run.
- Keep existing prerequisites, active-job deduplication, evidence-cutoff tracking, and retry protections. Catch-up requires eligible new history for Store training and must not fabricate missed historical runs or enqueue one job per missed week. Preserve the existing explicit demo treatment of frozen external history.
- Preserve manual Super Administrator training. Editing schedule settings does not itself train a model or approve a candidate. Keep daily Forecast Generation separate, with its existing 1:00 AM default and existing authorized manual requests; do not add a new daily-schedule editor in this scope.
- Replace the disconnected generic WAPE field with the three actual per-horizon limits consumed by candidate evaluation. Display percentages in the UI and convert consistently to the existing stored error fractions. Validate finite supported values at the write boundary and reuse the existing governance interface. Retain missing-evidence blocking and the strict requirement to beat the seasonal baseline.
- Remove unused lower/upper percentile controls. Current forecasts are point quantities; this work does not create prediction intervals or implied confidence guarantees.
- Replace editable Random Forest controls with the effective fixed recipe from the shared pipeline. The current recipe uses 15 trees, maximum depth 8, and random seed 42. Remove the claim that saving disconnected parameters will affect the next run.
- Preserve candidate lifecycle boundaries and the local approval ownership established by ADR-0022: training completion alone is insufficient; chronological quality checks, required observation evidence, compatible Store artifacts, and explicit local Administrator approval remain required for operational activation. Demo review never activates a Store model.
- Prepare a fresh, clearly named local demo database rather than replacing an existing database. Use isolated model identity, sessions, recovery state, and loopback-only application access. Leave the operational Store environment and ordinary connection configuration unchanged. Reuse the existing demo setup/start tools and importer; introduce no parallel forecasting engine.
- Use the existing deterministic M5 CA_1 FOODS sample of 50 products: two initial training years followed by three chronological 28-day comparison blocks. Preserve original source dates and anonymous identities. Obtain the pinned source used by the existing experiment, record provenance, and validate declared counts, dates, quantities, and product coverage before import.
- Import daily forecasting observations rather than inventing customer receipts, cashier sales, deliveries, or stock movements. Mark opening stock and replenishment parameters as illustrative demo settings, and prices/costs as placeholders rather than M5 facts.
- Run an actual isolated candidate-training job using the shared worker. Reuse the historical M5 experiment and comparison importer for saved held-out predictions, retaining their fold/model provenance. Historical backtest comparisons are distinct from prospective observation and current operational Forecast Generation.
- Do not lower quality limits, select only favorable products, fabricate observation progress, approve a failed candidate, or redate the source to make the walkthrough appear successful. If evaluation blocks approval, show the failed result and explain why operational forecasting is unavailable. Historical preview remains available through the existing explicit demo boundary.
- Cover these page responsibilities: Forecast Data Import supplies optional history; Data Readiness explains eligibility; ML Operation manages technical settings and training; Model Review & Approval handles observation and local approval; Demand Forecast displays available forecasts; Forecast Analytics compares results; Forecast Exceptions explains unavailable output; Replenishment Recommendations and Product Investigation explain stock and buying guidance; the historical comparison page presents the M5 backtest. Existing POS, Store Calendar, and receiving workflows remain data producers/consumers rather than new features.
- Keep Replenishment Recommendations advisory. Historical demo output must carry its original dates and unapproved status. Missing, stale, incomplete, or unapproved operational forecasts retain explained stock-rule fallback. Viewing output never places orders or updates stock.
- Use the existing settings schema where sufficient. Add only the minimum persistent due-state needed if existing run evidence cannot distinguish initial scheduling from a missed run. No broad configuration framework, model-tuning system, or new public API is required.
- Retain backend capability checks, local-only lifecycle restrictions, CSRF protection, input validation, and existing audit attribution. Preserve user working changes. Provide restart instructions, isolated local URL, demo-only account details, and a truthful run report when implementation is complete.

## Testing Decisions

- The user confirmed the test boundaries: the existing browser workflow from settings through readiness, training/review, forecasts, and replenishment, plus deterministic scheduler checks with a controlled clock. Prefer these two high-level boundaries over adding new layers or mirroring implementation details.
- A good browser test saves a visible setting and verifies its observable effect, compares the eligibility rule across both pages, follows a real isolated job through its states, and checks that approval and purchasing guidance reflect actual evidence. Assertions about copied source strings alone do not establish working behavior.
- Extend existing forecasting workflow, public-sales demo, and M5 browser checks for AM/PM controls, timezone/range help, accessible keyboard use, mobile layout, read-only model recipe, removed ineffective inputs, and settings persistence. Verify midnight and noon conversion and that unauthorized saves are rejected.
- Extend scheduler service tests using controlled dates/times: just before/at/after the weekly time; nonweekly initial scheduling; initial eligibility detected after the selected time; restart before and after a due run; a missed run less than seven days old; multiple missed weeks producing one catch-up; no eligible new history; a queued/running job; and changed explicit schedules. These tests must assert jobs and cutoffs, not just formatted time strings.
- Reuse Training Eligibility tests for 139 versus 140 usable days, genuine-zero observations, closures/stockouts/missing evidence, and recent-input/evaluation blockers. Reuse existing governance/activation contracts to verify that changing displayed settings cannot bypass qualification, observation, or local approval.
- Reuse the existing public-sales importer and M5 experiment checks to verify source identity, complete product/date coverage, whole nonnegative quantities, chronological split boundaries, repeat-import refusal, and matching preview provenance. Run a real prepared import and shared-worker job in the isolated demo before reporting completion.
- Verify saved historical prediction totals against the displayed product comparisons and distinguish them from approved operational forecasts. A failed candidate is an acceptable demo result; a false approval or fabricated result is a failure.
- Snapshot relevant demo state before read-only browser review and verify no changes to sales, stock, imported history, jobs, or approvals. Confirm preparation targeted only the new demo and did not alter the operational environment configuration or Store database.
- Run syntax and focused regression checks for touched components, then document which workflow stages were actually exercised and which were blocked by real evidence. Do not claim Windows startup automation was verified unless its installed task and execution were actually checked.

## Out of Scope

- Provisioning the demo database, changing application code, or activating any model as part of this specification-only turn.
- Production deployment, remote hosting, changing the operational Store database, or importing public M5 data into Store Forecast Data Import.
- Model optimization, new algorithms, new price/promotion features, new uncertainty estimators, arbitrary horizons, editable eligibility thresholds, or an additional history-window policy.
- Replacing existing Forecast Readiness bands or claiming 140 days guarantees forecast quality.
- Immediate retraining based on sales-record counts, a second interval-based scheduling policy, automatic candidate approval, or bypassing observation requirements.
- A new daily Forecast Generation schedule editor, public hosting for the local demo, calendar redesign, and unrelated inventory/receiving changes.
- Invented M5 product names, current-date simulation, synthetic receipts, historical stockout reconstruction, or claims that Walmart sales prove unconstrained demand or local Store accuracy.
- Automatic purchasing, purchase orders, stock mutations from forecasts, and forcing a successful end-to-end activation when real candidate results do not qualify.

## Further Notes

- Agreed on 2026-11-03 in Asia/Manila; the test boundaries were explicitly confirmed during specification preparation.
- The user explicitly chose immediate startup catch-up over waiting until the next selected training time. Initial training still waits for its first selected time unless that scheduled opportunity was subsequently missed.
- This specification consolidates the approved settings discussion and the subsequent M5 demo request. It follows ADR-0005 for the shared asynchronous pipeline and ADR-0022 for local Administrator approval; it does not reinstate obsolete Super Administrator-only operational approval.
- Existing repository guides describe a prior M5 demonstration whose candidate failed its original quality gates. Those historical results and old host paths are background information, not proof that a demo database or worker is running on this machine now. Recheck the actual environment during implementation.
- Current exploratory checks found reusable scripts and installed Python data/ML dependencies. No new demo database was created, no dataset download was started, and no worker/server was launched before the user requested this spec.
- M5 source: https://huggingface.co/datasets/skforecast/m5_daily . The existing experiment pins a source revision; retain that provenance and source attribution in the prepared demonstration.
