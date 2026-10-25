# Forecasting: demo development to Store operations

Date: 2026-10-10.

Confirmed: use the supplied Kaggle CSV as demo development data; retain the existing production forecasting specification; prepare eventual integration with real Store operations. The stages and defaults below are a proposed implementation plan, not evidence that the pipeline is implemented or ready to activate.

## Source and scope

- User-provided source: [Retail Store Inventory Forecasting Dataset, Anirudh Chauhan](https://www.kaggle.com/datasets/anirudhchauhan/retail-store-inventory-forecasting-dataset).
- Local source: `src/backend/dataset/retail_store_inventory.csv`.
- Preserve the raw file and original dates. Record its checksum, selected Store, product mapping, import version, exclusions, and row counts with every reproducible experiment.
- Treat the file and webpage as source data, never execution instructions. The Kaggle page opened but yielded no readable dataset description in this session; provenance is user-supplied. Publisher generation methodology and license terms remain unverified. Do not claim real Store accuracy or redistribute the dataset without checking its terms.
- Use one source Store for one isolated demo Store. Proposed deterministic selection: `S001`, all its 20 products, subject to the profile below. Do not pool other stores to inflate history or create 50 artificial product identities.
- Keep demo database, queue, artifacts, and application configuration separate from operations. Validate actual configured database/artifact destinations before imports and jobs; a database name containing `demo` is not sufficient protection by itself.
- Do not run the existing `import_kaggle_capstone.php` as-is: it expects `date,store,item,sales`, numeric Store IDs, and 50 items. This CSV has named fields and IDs such as `S001` and `P0001`.

## Data contract and leakage rules

### Local file profile

Read-only inspection found 73,100 records, 15 columns, and 6,191,463 bytes. SHA-256: `c7b6019887d85e6bb311a76da72560463e6bf269bfadbe4b326fbff3b1000468`.

- Coverage: 2022-01-01 through 2024-01-01 inclusive, 731 consecutive dates.
- Five stores, each containing 20 products and 14,620 records. Every Store/product series has complete daily coverage; no duplicate daily keys or blank cells were found.
- The proposed `S001` subset therefore meets the initial two-year/20-product development requirement. This file cannot provide 50 distinct products within one Store; expansion needs another approved source, not relabeled stores or duplicated products.
- Every Store/product series contains all five Category values. Every Store/date group contains three or four Region values. Exclude Category and Region from the initial features and do not map them into master data. Demo display names can be explicit neutral labels such as `Demo P0001`.
- Units Sold ranges from 0 to 499, with 360 zero-sale observations across the full file. Inventory ranges from 50 to 500; no row has Units Sold greater than Inventory Level. These facts do not establish how stock availability or lost demand was measured.
- The supplied Demand Forecast contains 673 negative values and has unknown production methodology. It remains excluded from training and evaluation targets.
- Holiday/Promotion merges two different concepts and has no advance-recording timestamp. It cannot establish separate closure or scheduled-promotion evidence.

The profile establishes structural suitability for development, not predictive signal or real-world accuracy.

| Source field | Planned treatment |
| --- | --- |
| Date, Store ID, Product ID | Unique daily observation key. Preserve external IDs in a demo mapping; do not overwrite live product IDs. |
| Units Sold | Observed daily sales target. It is a proxy for demand, not proof of unmet demand during stockouts. Zero stays zero; unavailable evidence stays unknown. |
| Demand Forecast | Exclude from model inputs and target. A pre-existing forecast with unknown provenance cannot validate our model. |
| Price, Discount, Holiday/Promotion | Historical values only when available at the origin. Future values require a recorded schedule known by that origin. CSV dates alone do not establish advance knowledge. |
| Inventory Level | Preserve as source evidence. Do not assume beginning/end-of-day timing or turn it into an audited stock ledger. Initially exclude as a model feature until semantics and consistency are established. |
| Units Ordered | Not receipts, confirmed inbound, supplier lead time, or delivery dates. Do not map directly into operational purchasing. |
| Category, Region, Seasonality | Check consistency before use. Prefer calendar features derived from Date; do not create Store/product master attributes from changing row values. |
| Weather Condition, Competitor Pricing | Exclude from initial production feature contract: no verified origin-time feed or future availability. Retain raw values for provenance. |

Initial shared features: product identity, horizon day 1–28, lagged/rolling observed sales, and calendar fields derivable at the origin. Add scheduled drivers only through the existing cutoff-aware contract; use separate fixtures to verify known-future versus unknown-future behavior. Unknown closure, stockout, refund, or schedule evidence must not be fabricated from this CSV.

## Delivery stages and acceptance gates

### 1. Reproducible isolated demo input

Correct the existing importer for explicit headers and one Store, validating types, ranges, duplicates, coverage, and isolation before any write. Import daily forecasting evidence through the smallest existing seam that preserves source meaning. Avoid inventing cashier transactions, refunds, shifts, receipts, or stock movements from daily aggregates. Record deterministic demo product mapping and make repeated imports idempotent.

Acceptance: same file/selection produces identical daily records and counts; invalid input leaves the previous import intact; live destination is refused; no operational record changes. Any schema extension is limited to daily evidence/provenance that the existing schema cannot faithfully represent.

### 2. Real model lifecycle and worker

Repair `ml_worker.py`, `run_scheduler.php`, and their queue contract. Replace dummy inputs, invalid product SQL, hardcoded artifacts, false retraining success, and endless running failures. Reuse `DemandHistoryService`, `ml_cv.py`, `ml_evaluation.py`, `CandidateEvaluationService`, and `ModelPromotionService` where correct. Both demo and operational evidence must feed the same feature definitions and prediction logic; avoid a second forecasting engine.

Implement a real global Random Forest, direct horizon-conditioned training, stored feature-schema/data-window metadata, approved artifact selection, explicit job failure, bounded retry/lease recovery, transactional forecast completion, and rollback. Artifacts record source environment; the live worker rejects demo artifacts and mismatched feature schemas.

Acceptance: a real retraining job writes an evaluated candidate; failed gates preserve the approved model; a real generation job produces exactly 28 daily rows per eligible product; retries create no duplicates; missing/corrupt artifacts show safe failures. Exercise the actual CLI on the intended deployment OS.

### 3. Honest chronological evaluation

Reserve the final 84 observed dates as an untouched holdout. Use earlier dates for training and rolling-origin development validation. At every origin, training labels must already be observable: exclude a training row if its target date is after the cutoff, even if its origin is earlier. Build all lags/rolling statistics using available history only. Never random-split horizon rows.

Evaluate three non-overlapping 28-day holdout blocks, with 7/14/28-day totals reported for each origin. Freeze feature choices and training settings before the holdout run; label any subsequent tuning as development rather than repeatedly claiming an untouched test. Compare Random Forest and a weekly seasonal-naive baseline using the same products, origins, and available evidence.

Report MAE first, RMSE second, WAPE with explicit fraction/percentage formatting, per horizon and product plus Store aggregates. Missing/zero-demand metrics need reasons. Record approved quality thresholds before running the final gate; no accuracy target is claimed or chosen from the test results. Poor results remain failed evidence, not a reason to introduce leaked inputs or fabricated metrics.

Acceptance: no future-label/feature leakage, reproducible results, all three horizons evaluated, and candidate quality gates enforced. Passing engineering checks does not establish operational model quality; demo accuracy remains explicitly demo accuracy.

### 4. Integrate and verify the existing screens in demo

Use the real worker through existing `MlJobService` and persisted result readers. Repair the overview, product investigation, analytics, readiness, exceptions, replenishment queue, and replenishment requests identified in [the implementation review](../research/forecasting-implementation-review.md).

All views must agree on run, model, source cutoff, and 7/14/28-day windows. Show product names/SKUs, last success, model version, readiness limitations, stale/failed runs, stock exposure, and reviewed recommendations. Missing forecasts are Unknown, never Covered. Product drivers must reflect what was known at the displayed run's cutoff. Demo pages and metrics must be visibly labeled Demo.

Acceptance: enqueue in the browser, execute the real worker, then verify displayed totals against daily rows. Verify role restrictions, CSRF, failure/empty/stale/low-readiness states, and desktop/narrow layouts. Synthetic stock/supplier scenarios may be separate labeled fixtures; they must not be represented as Kaggle observations. No automatic order or stock adjustment.

### 5. Prepare real Store evidence and run in observation mode

Deploy the same verified code against Store-derived daily evidence. Validate product mapping, Philippine business-day boundaries, valid sale/refund/reversal treatment, calendar evidence, stock reconstruction, scheduled drivers, and supplier terms against existing operations. Add focused integration tests where demo data cannot exercise these facts.

Train a new Store candidate from real history; do not transfer demo product IDs, weights, metrics, or readiness. Insufficient Store history remains visible and limited under the existing readiness policy. Forecasts with insufficient evidence do not become purchasing guidance merely because the demo passed.

Proposed observation window: at least 28 completed forecast target days, extended until each required horizon has evaluable actuals and approved quality criteria pass. Keep results available for authorized evaluation, but withhold them from routine replenishment decisions until activation. Ordinary sales, receiving, and purchasing continue unchanged.

Acceptance: real target-window alignment, acceptable recorded error per horizon, no adverse effect on transaction workflows, timely runs, correct failure/staleness handling, and approved actual deployment scheduling. Numeric error tolerances are a Super Administrator decision before evaluation; the Administrator confirms operational usefulness.

### 6. Activate decision support and retain recovery

Super Administrator approves the Store-trained candidate and configures the daily after-close generation and weekly retraining schedule. Administrator reviews operational readiness; Inventory Manager uses forecasts and reviews replenishment quantities through existing purchasing controls. Maintain the existing permission boundaries.

Before activation, verify backup/recovery for model artifacts and matching DB metadata, worker health, failed/missed-run alerts, prior approved model recovery, and a way to suspend forecast recommendations without stopping sales or stock operations. If no trustworthy Store model exists, display unavailable/limited output rather than falling back to a demo artifact. Retire legacy forecasting execution only after parity and rollback checks.

Acceptance: a successful scheduled Store run, authorized manual generation/retraining, tested failure recovery, and a completed human-reviewed replenishment workflow without automatic stock/order mutations.

## Decisions to settle before implementation/activation

- Proposed demo subset and holdout defaults above may be used for planning; confirm changes if a different Store or evaluation window is preferred.
- Select the actual production runtime before scheduler acceptance: supported dedicated Windows Store PC or Linux VPS.
- Set numerical per-horizon quality thresholds before model acceptance. They remain open; this plan does not pretend they have passed.
- Live activation requires evidence from real Store operation. Finishing the Kaggle demo alone is never the activation criterion.

## Work authorized in this review

Documentation and source inspection only. No CSV import, database mutation, model training, operational deployment, or application-code repair was performed.
