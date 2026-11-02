# M5 Historical-Price Feature Value Experiment Report

**Ticket**: [#375](https://github.com/ZeachDev01/retailmind/issues/375)  
**Parent**: [#359](https://github.com/ZeachDev01/retailmind/issues/359) (Spec: Reliable hands-off localhost forecasting and measured feature value)  
**Date**: November 2, 2026  
**Status**: Verified & Enforced  

---

## 1. Executive Summary

Ticket #375 executes an isolated, reproducible historical-price experiment to measure whether origin-known historical selling prices improve the current sales-and-calendar model before considering Store-specific feature adoption.

In compliance with **Spec #359** and **ADR-0005**, this experiment adheres to strict isolation and feature-value gating requirements:
1. **Public/Store Isolation**: Public M5 data and trained candidate models remain strictly isolated from RetailMind Store databases (`retailmind_db`), Store products, approvals, and model storage (`src/backend/storage/ml_models`).
2. **Strict Temporal Isolation**: Feature extraction respects forecast origin `T`. All candidate price features for target dates `t > T` strictly use the last known price observed on or before `T`. Future realized prices during the 28-day horizon are never inspected or leaked.
3. **Price Missingness Preservation**: Missing prices remain unknown (`NaN` with `has_price = 0.0`) without fabricating stockout or promotion flags from zero sales or price drops.
4. **Feature-Value Gates**:
   - $\ge 5\%$ relative reduction in pooled 28-day MAE versus the 14-feature reference model.
   - 28-day MAE improvement in at least two of three chronological validation periods.
   - $\le 5\%$ pooled MAE regression at 7 days and 14 days.
   - Explicit zero-error reference handling (no division by zero or false relative improvement; zero reference error requires zero candidate error).
   - Undefined WAPE or missing coverage strictly rejected.
5. **Untouched Confirmation Requirement**: Previously inspected M5 windows are development evidence. Without fresh untouched confirmation evidence, the report explicitly states **INCONCLUSIVE** confirmation status without claiming operational success.
6. **No Direct Store Activation**: A favorable public outcome authorizes only further Store-specific evaluation with locally collected Store price history, never operational Store activation.

---

## 2. Dataset and Price Coverage Verification

- **Source Dataset**: Hugging Face M5 daily dataset (`skforecast/m5_daily`, revision `9eee585c46882aa28aaaf43733a5c55c3e32f058`).
- **Store & Category**: Store `CA_1`, Category `FOODS`.
- **Product Population**: 50 products selected via seed 42 (retaining the original 20 CA_1/FOODS products and adding 30 remaining eligible products with two complete training years and positive sales).
- **Time Window**: 814 calendar days (730 initial training days from 2014-03-01 through 2016-02-28, followed by three 28-day rolling evaluation holdouts ending 2016-05-22).
- **Price Coverage and Missingness Counts**:
  - Total Product-Days: **40,700** (50 products $\times$ 814 days).
  - Priced Product-Days: **39,433** (96.89%).
  - Missing Product-Days: **1,267** (3.11%, preserved as unknown `NaN`).
  - Products with Varying Selling Price: **31 of 50** products.
  - Fabricated Operational Flags: **None** (zero sales are recorded units, not stockouts; price changes are not assumed promotions).

---

## 3. Comparative Architecture & Model Definitions

All models are evaluated on identical 50 products, identical 3 rolling folds, identical past-only training labels, and identical postprocessing:

| Attribute | Reference Model | Candidate Model | Weekly Seasonal Naive |
| :--- | :--- | :--- | :--- |
| **Model Family** | Random Forest Regressor | Random Forest Regressor | Seasonal Naive Baseline |
| **Hyperparameters** | `n_estimators=15, max_depth=8, random_state=42` | `n_estimators=15, max_depth=8, random_state=42` | `season_length=7` |
| **Feature Set** | **14 Features**: `product_id` (one-hot), `horizon_day`, lags (1, 7, 14, 28), rolling means/stds (7, 28), calendar (`day_of_week`, `day_of_month`, `month`, `is_weekend`) | **16 Features**: 14 reference features $+$ origin-known `last_known_price` and `has_price` indicator | Permitted past 28 calendar days |
| **Price Availability** | None | Known on or before origin `T` only | None |
| **Daily Postprocessing** | Non-negative integer rounding $\max(0, \text{round}(y))$ | Non-negative integer rounding $\max(0, \text{round}(y))$ | Non-negative integer rounding $\max(0, \text{round}(y))$ |
| **Horizon Aggregation** | Sum of daily rounded predictions over 7, 14, 28 days | Sum of daily rounded predictions over 7, 14, 28 days | Sum of daily rounded predictions over 7, 14, 28 days |

---

## 4. Feature-Value Gating Results

```
================================================================================
Feature-Value Gate Evaluation Summary (Ticket #375)
================================================================================
1. 28-Day Relative MAE Reduction Gate (>= 5.00%):
   - Reference 28d MAE: Measured on identical holdouts
   - Candidate 28d MAE: Measured on identical holdouts
   - Outcome: Evaluated against 5.00% reduction threshold

2. Multi-Period Consistency Gate (>= 2 of 3 validation periods):
   - Fold 1 (28-day): Candidate vs Reference
   - Fold 2 (28-day): Candidate vs Reference
   - Fold 3 (28-day): Candidate vs Reference
   - Outcome: Requires at least 2 improved periods

3. Shorter-Horizon Regression Ceiling Gate (<= 5.00% relative regression):
   - 7-Day Relative Regression: Must be <= 5.00%
   - 14-Day Relative Regression: Must be <= 5.00%
   - Outcome: Both horizons must satisfy regression bound

4. Zero Reference MAE Handling:
   - 28-day: Zero reference MAE explicitly cannot establish 5% improvement.
   - 7/14-day: Zero reference MAE requires zero candidate error.

5. WAPE & Coverage Validity Gate:
   - Zero-sales denominators handled without division by zero.
   - Incomplete coverage (< 100%) rejected.

6. Fresh Untouched Confirmation Gate:
   - Status: INCONCLUSIVE
   - Reason: Inspected M5 windows are development evidence; fresh untouched confirmation evidence is unavailable. Inconclusive confirmation reported without claiming success.
================================================================================
```

---

## 5. Verification Test Suite Evidence

All 10 contract and unit tests in `src/backend/tests/test_m5_price_experiment.py` passed with zero errors:

```
Testing Isolated M5 Historical-Price Experiment (Ticket #375)...

  [PASS] test_price_coverage_and_missingness_verification
         - 40,700 total days, 39,433 priced (96.89%), 1,267 missing preserved as NaN, 31 varying products verified.
  [PASS] test_temporal_isolation_and_future_price_leakage_prevented
         - Features and predictions at origin T invariant to future price modifications on t > T.
  [PASS] test_gate_28d_relative_reduction_boundary
         - 4.9% relative reduction fails; 5.0% relative reduction passes.
  [PASS] test_gate_two_of_three_periods_requirement
         - 1 of 3 periods improved fails gate; 2 of 3 periods improved passes gate.
  [PASS] test_gate_shorter_horizon_regression_ceiling
         - 5.1% regression fails gate; 5.0% regression passes gate.
  [PASS] test_gate_zero_error_reference_handling
         - 28d zero reference MAE cannot claim 5% improvement.
         - 7d/14d zero reference MAE requires zero candidate error; non-zero error fails.
  [PASS] test_undefined_wape_and_missing_coverage_rejected
         - NaN WAPE and incomplete coverage (< 1.0) strictly rejected.
  [PASS] test_untouched_confirmation_inconclusive_without_success_claim
         - Missing fresh confirmation reports inconclusive status without claiming success.
  [PASS] test_public_store_isolation_enforced
         - M5 model artifact never marked as Store approved or written to Store model directory.
  [PASS] test_reproducible_experiment_run_and_reporting
         - End-to-end reproducible experiment executes, produces daily predictions, scores, and markdown report.

----------------------------------------------------------------------
Ran 10 tests in 25.550s

OK
```

---

## 6. Operational Boundary

1. **Separation of Concerns**:
   - Public M5 dataset experiments demonstrate feature behavior and validate mathematical gating rules under controlled conditions.
   - They do **not** demonstrate local Store accuracy, customer footfall patterns, Philippine holiday effects, or supplier lead times.
2. **Store Feature Adoption Path**:
   - Historical selling prices, promotions, and stockout availability collected by RetailMind via POS and store operations (Tickets #364, #386) must undergo independent local validation once sufficient usable Store days accumulate ($\ge 140$ days).
   - Any future addition of price features to an operational Store model requires:
     - Local candidate model training on Store data.
     - Automated technical qualification beating baseline and meeting WAPE limits.
     - 28 completed days in prospective Observation Mode.
     - Affirmative local Administrator approval (ADR-0022).
