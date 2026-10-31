# Forecasting pipeline reliability discussion

Discussion date: 2026-10-31 (Asia/Manila). Status: synthesized into [the specification](../specs/hands-off-local-demand-forecasting.md) and published as [GitHub issue #359](https://github.com/ZeachDev01/retailmind/issues/359), labelled `ready-for-agent`. No application implementation or deployment verification completed.

## Agreed scope

- Prioritize a reliable end-to-end forecasting pipeline while forecast accuracy remains unproven. Operational checks do not establish predictive accuracy or waive existing model approval gates.
- Verify history preparation, versioned candidate training and evaluation, authorized model approval, complete persisted forecasts, traceable display, replenishment fallback, and failure recovery before describing the pipeline as reliable.
- The user identifies XAMPP as the deployment stack, correcting the proposed Laragon target. The current development workspace is under Laragon. Windows is the working deployment assumption; deployment details remain to be confirmed.
- Forecasts and Replenishment Recommendations remain advisory. Expected recorded sales in Base Units remains the prediction target, as previously agreed.
- The user accepted unattended operation: no manual XAMPP launch, application sign-in, or open browser should be required; missed work should recover after startup. This includes operation without an interactive Windows sign-in, as proposed in the accepted question.
- The user accepted retaining stale, incomplete, or superseded forecasts as dated history while excluding them from current purchase suggestions and using clearly labelled stock rules instead.
- The user accepted current-only catch-up: after downtime, generate one current forecast using the latest completed Store day, rather than replay missed daily forecasts. Missing evidence remains unknown.
- The user accepted three total attempts for temporary failures, followed by an Administrator/Super Administrator notice with a reason and recovery action. Missing approval or insufficient history should show an immediate prerequisite message rather than continuous retries. Stock-rule suggestions remain available.
- The user accepted 28-day quantity forecasting as the primary outcome for evaluating additional inputs, with 7/14-day results still reported. This does not change existing production quality gates.
- The user requested discussion before coding about the value of `out_of_stock`, `price`, and `is_on_promotion`. An unfinished edit to `feature_pipeline.py` was removed; no application change remains from that attempt.
- Deployment will be local/localhost, with no routine developer Super Administrator access. Forecasting should be mostly hands-off.
- The user subsequently accepted local Administrator approval of candidates that pass fixed technical checks, without developer Super Administrator access. See [ADR-0022](../adr/0022-local-administrator-forecast-model-approval.md).
- The user requested a dedicated Forecasting navigation group for the Super Administrator, ordered by workflow, and accepted a corresponding group for the Administrator. Existing pages should be reused; role permissions still require separate enforcement.
- The user accepted that a Stockout Day, including a day with positive sales before running out, is an incomplete picture of demand. Preserve recorded sales, keep additional unmet demand unknown, and do not present stockout zeros as evidence that nobody wanted the Product.
- The user accepted testing price and promotion inputs separately, retaining them only if they improve 28-day forecasting across multiple test periods. The agreed starting thresholds are below; existing production quality gates remain separate requirements.
- Before eligibility, stock rules apply; inclusion in training does not approve a model. The agreed minimum below replaces the proposed consecutive-day rule in the future design; the current enforced rule is still 140 consecutive usable days.
- The user accepted daily Forecast Generation at 01:00 and weekly candidate training on Sunday at 01:00, Asia/Manila, when new history exists. Daily generation uses the approved model; training produces a candidate.
- The user reports weekday closing at about 21:00, earlier weekend closing, and that the PC is not always plugged in. Jobs must not depend on the PC being powered on overnight.
- The user accepted missed weekly training running at the next startup if new history exists. Startup catch-up must respect completed Store days and avoid duplicate or obsolete work.

## Accepted starting defaults

- Count 140 usable daily records without resetting accumulated history when a Stockout Day or unknown day occurs. Genuine observed zero-sales days count; incomplete demand evidence and unknown days do not count toward this minimum. Preserve original dates and require sufficient chronological validation coverage rather than compressing gaps into adjacent days. This may take longer than 20 calendar weeks.
- For the first price-feature experiment, require at least a 5% relative reduction in 28-day MAE versus the current model on matching validation data, improvement in at least two of three chronological periods, and no more than a 5% relative increase in 7/14-day MAE. Existing configured quality and weekly-baseline checks still apply. These are starting policy choices, not established accuracy guarantees; a fresh untouched confirmation window remains necessary before claiming improvement.
- Separate delivery into pipeline reliability and the local Administrator workflow, followed by historical-price experiments. Collect genuine stockout and promotion evidence for later feature evaluation; do not make deployment depend on unavailable evidence.

## Proposed navigation workflow

- Super Administrator / Forecasting: Import Sales History (optional setup), Data Readiness, ML Operation (training/settings), Forecast Model Governance (evaluation), Demand Forecast (output), Forecast Exceptions (monitoring).
- Administrator / Forecasting: Data Readiness, Model Review & Approval, Demand Forecast, Forecast Exceptions. Model Review & Approval is required functionality, not an existing complete page.
- Store Calendar and Promotions remain in Store operations, with contextual forecasting links as needed. System Health remains shared platform tooling. Demo replay remains available only in prepared demo contexts through relevant forecasting pages; included fragments, prototypes, and duplicate import routes are not standalone navigation destinations.

## Source findings to verify

- `ReplenishmentQueueService::getRecommendations` checks completeness, freshness, readiness, and approved model identity for displayed expected sales, but its purchase-quantity selection uses weaker conditions.
- Scheduler daily deduplication counts failed jobs; automatic same-day recovery therefore needs review. The worker separately supports recovery of expired running jobs.
- The Windows task installer uses an interactive account and resolves PHP/Python through PATH. The scheduler launches Python separately. XAMPP deployment must verify executable selection, database configuration, permissions, and execution when no browser is open.
- Store training currently requires 140 consecutive usable days; generation requires 28 consecutive observed days for trained products. Insufficient evidence must remain explicit.
- Existing queued jobs resume, but the scheduler does not fully catch up missed daily/weekly schedules. Ordinary worker exceptions fail immediately; the existing three-attempt policy applies to expired running leases.
- Dedicated training-failure notifications query failed training runs, whereas ordinary worker exceptions mark the queue job failed. System health can surface failure, but the dedicated notification path needs review.

## Discussion status

### Available data check (2026-10-31)

A direct read-only transaction inspected the currently configured local development database, not the future XAMPP deployment. It contained 2 products, 2 sales and 5 sale lines on one date (2026-10-29), and 13 stock movements covering the same single date. Promotions, scheduled demand drivers, imported forecast sales, and Store Calendar tables each contained zero rows. A demo-import marker was absent, but that does not certify these development records as real Store history. This is insufficient local history for model training or measuring the value of the proposed inputs.

The local M5 Parquet file contains `sell_price`, event fields, and SNAP fields, but no stock availability/stockout column or explicit product promotion flag. The current selected-history CSV drops `sell_price` and exports only date, product, and sales. Event and SNAP fields must not be treated as verified product promotion flags. Historical price inputs may be investigated with origin-time safeguards; future realized prices cannot be assumed known when forecasting.

For the selected 50 M5 products and existing experiment window, 39,433 of 40,700 product-days have observed prices (96.89%); 1,267 prices are missing. 31 products have more than one observed price. This supports a historical-price experiment with missingness preserved, not a claim that prices improve forecasts. The 21,257 zero-sale rows cannot identify stockouts by themselves.

The operating and recovery policies, local Administrator model approval, 140 accumulated usable-day minimum, schedules, and starting feature-value thresholds above are accepted. Discussion is ready to become a specification; implementation remains deferred. The specification must make existing quality-gate settings, first-model baseline comparison, gap-aware chronological evaluation, intraday stock evidence, and zero-error comparison cases explicit. Exact XAMPP paths, runtime configuration, and deployment access require environment verification rather than further business-policy questions. The navigation order above remains a proposed presentation of the accepted grouping and workflow requirement.

The Stockout Day definition is recorded in the root glossary. The local model approval decision is recorded in ADR-0022. Adding inputs to the approved model remains conditional on the agreed evidence checks; no feature improvement has been established.
