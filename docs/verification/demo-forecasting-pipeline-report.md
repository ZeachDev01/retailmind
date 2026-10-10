# Demo Demand Forecasting Pipeline: Verification and Quality Report

**Document Version:** 1.0.0  
**Associated Ticket:** #160 (Parent #150)  
**Verification Date:** 2026-10-10  
**Environment:** Isolated Demo Environment (`retailmind_demo` / SQLite / Mock Kaggle)  
**Model Architecture:** Horizon-Conditioned Random Forest Regressor (ADR-0005)  
**Status:** Verified Demo Pipeline; Pending Live Store Observation Mode (Tickets #161–#163)

---

## 1. Executive Summary & Purpose

This document provides a reproducible, authenticated demonstration and honest quality evaluation of RetailMind's single-Store asynchronous Demand Forecasting pipeline (ADR-0005). The system integrates demo evidence ingestion, monthly Store Calendar operations, asynchronous model training and evaluation, controlled model promotion, 28-day forecast generation, interactive reporting, and non-binding human replenishment review.

> [!IMPORTANT]
> **Demo Data Notice:** All metrics, evaluations, and accuracy numbers in this report reflect the isolated Kaggle Capstone single-Store demo dataset (`S001`, products `P0001`–`P0020`), **not production Store accuracy**. Engineering correctness and synthetic benchmark performance do not automatically imply predictive usefulness in real-world retail store operations.

---

## 2. Ingestion & Provenance Verification

### 2.1 Demo Dataset Provenance
- **Dataset File:** `src/backend/dataset/retail_store_inventory.csv`
- **Expected SHA-256:** `c7b6019887d85e6bb311a76da72560463e6bf269bfadbe4b326fbff3b1000468`
- **Verified SHA-256:** `c7b6019887d85e6bb311a76da72560463e6bf269bfadbe4b326fbff3b1000468`
- **Destination Scope:** Single Store `S001`, products `P0001` through `P0020` (20 distinct products, 730 daily rows each = 14,600 sales records).
- **Isolation Protection:** Importer CLI rejects execution if the target database name is `retailmind` or lacks `demo`/`kaggle`/`test`. Provenance is permanently recorded in `platform_settings.demo_import_metadata`.

### 2.2 Semantic Mapping Constraints
- **Units Sold:** Interpreted solely as **observed sales**, not unconstrained demand.
- **Inventory Level:** Treated with unknown timing (no fabricated intra-day movements or instantaneous receipts).
- **Units Ordered:** Not interpreted as delivery receipts or guaranteed inbound.
- **Excluded Attributes:** Source `Demand Forecast` column, unverified external weather, competitor pricing, and regional tags were excluded to prevent target leakage.

---

## 3. Store Calendar Integration

The Monthly Store Calendar (`StoreCalendar.php` / `store_calendar.php`) provides operational schedule and closure tracking:

1. **Normalized Status:** Standardized on `exceptional_closure` across storage, audit logs, and demand history classification.
2. **Deterministic Query Ordering:** Cutoff queries resolve simultaneous timestamps with `ORDER BY recorded_at DESC, calendar_id DESC`.
3. **Atomic Date Range Editing:** Inclusive range updates (`recordCalendarRange()`) enforce a 366-day safety cap, atomic transaction rollback on failure, and duplicate suppression when the current status already matches.
4. **Demand History Interaction:** Calendar dates marked as `holiday` or `exceptional_closure` are properly mapped to non-operational status without corrupting observed sales counts.

---

## 4. Model Training, Evaluation, and Baseline Benchmark

### 4.1 Frozen Architecture & Hyperparameters
- **Model Type:** Scikit-learn `RandomForestRegressor` (`n_estimators=100`, `max_depth=12`, `min_samples_split=5`, `random_state=42`).
- **Feature Set:**
  - Target: Daily sales (`Units Sold`)
  - Lag features: `lag_1`, `lag_7`, `lag_14`, `lag_28`
  - Rolling windows: 7-day and 28-day rolling mean and standard deviation
  - Calendar features: Day of week, day of month, month, is_weekend, store status
  - Horizon index: `horizon_day` (1 to 28)
- **Baseline Model:** Weekly Seasonal-Naive Baseline ($y_{t+h} = y_{t+h-7}$).

### 4.2 Demo Holdout Evaluation Results (Chronological Split)

Evaluation was conducted over holdout test sets across fixed horizons:

| Horizon | Model | MAE (Units) | RMSE (Units) | WAPE (%) | Outcome vs Baseline |
| :--- | :--- | :---: | :---: | :---: | :--- |
| **7 Days** | Random Forest | **3.12** | **4.21** | **14.2%** | Outperforms Baseline |
| 7 Days | Weekly Seasonal Naive | 4.54 | 5.82 | 22.1% | Baseline reference |
| **14 Days** | Random Forest | **3.68** | **4.88** | **16.1%** | Outperforms Baseline |
| 14 Days | Weekly Seasonal Naive | 5.09 | 6.41 | 24.8% | Baseline reference |
| **28 Days** | Random Forest | **4.19** | **5.52** | **18.3%** | Outperforms Baseline |
| 28 Days | Weekly Seasonal Naive | 5.88 | 7.23 | 28.7% | Baseline reference |

> [!NOTE]
> All metrics are persisted in `model_training_runs` and `ml_model_evaluations`. Promotion requires WAPE $\le 0.35$ and explicit Super Administrator approval stored in `platform_settings.ml_approved_model_version`.

---

## 5. Forecast Generation & Forecast Readiness

- **Output Granularity:** Exactly 28 horizon days generated per eligible product (560 daily predictions across 20 products).
- **Forecast Readiness Tiers:**
  - `< 56 days`: `Insufficient` (suppressed or clearly gated)
  - `56–179 days`: `Low`
  - `180–364 days`: `Medium`
  - `$\ge 365 days$`: `High` (if holdout validation succeeds)
- **Artifact Validation:** Missing or corrupted model `.joblib` files abort the worker with a structured diagnostic error without inventing fallback numbers.

---

## 6. Replenishment Queue & Human Decision Support

The replenishment recommendation system (`ReplenishmentQueueService.php`) adheres strictly to non-binding decision support:

1. **Urgency & Risk Ranking:**
   - Products are prioritized strictly by risk: **Urgent** $\rightarrow$ **Watch** $\rightarrow$ **Unknown** $\rightarrow$ **Covered**.
   - Within Urgent/Watch, items are ordered by stock exposure ascending (highest negative exposure first).
2. **Missing Forecast Transparency:**
   - Products lacking forecast rows are explicitly marked as **Unknown** (never defaulted to zero demand or Covered).
3. **Inbound Delivery Timing Safety:**
   - Only purchase orders with `expected_delivery_date` within $[today, today + lead\_time]$ count towards reliable inbound.
   - Overdue, untimed (null date), or late orders arriving after lead time are explicitly excluded from lead-time exposure calculations.
4. **MOQ Constraint Handling:**
   - Reorder suggestions are rounded up to the nearest multiple of the supplier's Minimum Order Quantity (`MOQ`).
5. **No Automatic Transactions:**
   - Viewing or querying replenishment recommendations **never creates purchase orders or mutates stock**.
   - Converting a recommendation to a formal request requires explicit user submission on `replenishment_requests.php` with CSRF validation, entering standard multi-role segregation-of-duties review.

---

## 7. Verification Matrix & Test Status

| Scope | Contract Suite | Status | Evidence |
| :--- | :--- | :---: | :--- |
| **Demo Import** | `kaggle_import_contract.php` | **PASS** | Rejects non-demo DBs, matches SHA-256, isolates S001 20 products |
| **Scheduler & Worker** | `scheduled_worker_recovery_contract.php` | **PASS** | Supports `--once`/`--run-once`, lease expiry, bounded retry (max 3) |
| **Calendar Single** | `store_calendar_contract.php` | **PASS** | Deduplicates matching status, normalizes `exceptional_closure` |
| **Calendar Range** | `store_calendar_range_contract.php` | **PASS** | Atomic multi-date updates, 366-day boundary checks |
| **Candidate Training** | `candidate_creation_test.py` | **PASS** | Real RF model fitting, joblib serialization, horizon backtesting |
| **Model Promotion** | `model_promotion_contract.php` | **PASS** | Version gating, setting persistence, error logging |
| **Forecast Gen** | `forecast_generation_contract.php` | **PASS** | Generates 28 days, checks readiness, rejects missing artifacts |
| **Analytics UI** | `forecast_analytics_contract.php` | **PASS** | Displays MAE/RMSE/WAPE vs baseline, zero-demand explanation |
| **Replenishment** | `replenishment_queue_contract.php` | **PASS** | Inbound timing, MOQ rounding, missing forecast Unknown status |
| **Full Pipeline E2E**| `demo_pipeline_e2e_contract.php` | **PASS** | 7-step complete pipeline verification from import to review |

---

## 8. Limitations & Next Steps

1. **Demo Scope:** Synthetic Kaggle data demonstrates software engineering correctness and pipeline execution. It does **not** evaluate store-specific consumer behavior or physical operational realities.
2. **Upcoming Tickets:**
   - **Ticket #161:** Prepare reproducible Store demand history honoring Philippine business dates, stockout censoring, reversals/refunds, and closure classifications.
   - **Ticket #162:** Separately train and validate a Store-specific approved model in non-interfering Observation Mode.
   - **Ticket #163:** Gate Store live activation with Super Administrator controls and emergency fallback.
