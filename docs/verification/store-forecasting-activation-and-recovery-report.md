# Store Demand Forecasting Activation and Recovery Report

**Issue**: [#163](https://github.com/ZeachDev01/retailmind/issues/163) (Parent [#150](https://github.com/ZeachDev01/retailmind/issues/150))  
**Date**: October 10, 2026  
**Status**: Verified & Enforced  

---

## 1. Executive Summary

Ticket #163 enforces the final activation gate and operational recovery boundaries for routine Store Demand Forecasting in RetailMind. In compliance with **ADR-0005**, Store forecasting guidance cannot be turned on merely by ticket assignment, developer action, unit test passes, or Kaggle demo results.

Live activation requires:
1. **Store-Specific Quality Acceptance**: 7-, 14-, and 28-day candidate model evaluations meeting configured maximum WAPE thresholds and outperforming weekly seasonal naive baselines.
2. **Super Administrator Technical Approval**: Recorded formal review of mathematical metrics, data windows, and candidate gate outcomes.
3. **Administrator Operational Review**: Recorded review of practical usefulness, promotional elasticity, and business seasonality.
4. **Completed Observation Mode**: At least 28 days of prospective target dates evaluated in holdout against actual Store sales before live guidance activation.
5. **Reversible Governance & Isolation**:
   - Zero tolerance for demo artifacts in Store operations (demo weights/IDs are rejected at the gate).
   - Instant rollback restores prior approved Store models with updated inference without circular loops.
   - Immediate non-binding recommendation suspension (`ml_recommendations_suspended = 1`) with **zero disruption** to cashier sales, inventory counting, stock adjustments, or purchase ordering.
   - Complete retirement of legacy Flask/synchronous forecasting endpoints (`call_ml_api`, `get_ml_prediction` return HTTP 410 Gone; `run_maintenance.bat` and `run_maintenance.sh` execute `php scripts/run_scheduler.php`).

---

## 2. Gate Verification & Enforcement Matrix

| Gate | Check / Precondition | Absent / Failing Behavior | Live State Verification |
| :--- | :--- | :--- | :--- |
| **Demo Isolation** | Model version does not contain `demo` or `kaggle` | `StoreActivationService::validateActivationReadiness` returns `ready: false`; `activateStoreModel` throws `InvalidArgumentException`. `ml_worker.py` refuses to load artifact. | Tested in `store_activation_contract.php` (Test 1). Demo models strictly rejected. |
| **Quality Acceptance** | 7d, 14d, 28d WAPE <= configured thresholds and beats seasonal baseline | Candidate gate fails; activation blocked with specific horizon error. | Tested in `store_activation_contract.php` (Test 2). Blocked if missing thresholds or failing WAPE. |
| **Technical Review** | Super Admin decision = `'accepted'` in `platform_settings` | `checks['technical_review_accepted'] = false`; activation refused. | Tested in `store_activation_contract.php` (Test 2). Attributed to user ID 1 with timestamp. |
| **Operational Review** | Administrator decision = `'accepted'` in `platform_settings` | `checks['operational_review_accepted'] = false`; activation refused. | Tested in `store_activation_contract.php` (Test 2). Attributed to user ID 2 with timestamp. |
| **Observation Mode** | Minimum 28 completed forecast target dates compared against valid Store sales | Holdout comparison incomplete; activation blocked with count message. | Tested in `store_activation_contract.php` (Test 2). Blocked at 10 days; unblocked at 28 days. |
| **Reversible Rollback** | Prior approved model exists in `ml_previous_approved_model_version` | Restores previous version; clears previous slot to prevent loops; throws if absent. | Tested in `store_activation_contract.php` (Test 3). Reverts from Candidate 2 to Candidate 1 cleanly. |
| **Recommendation Suspension** | `ml_recommendations_suspended = '1'` | Recommendations marked inactive in queue; explanation updated; cashier sales and inventory operate normally. | Tested in `store_activation_contract.php` (Test 4). Cashier sales checkout & inventory count unaltered. |
| **Legacy Endpoint Retirement** | Obsolete Flask HTTP endpoints removed from operational flow | `call_ml_api` and `get_ml_prediction` return HTTP 410 Gone; scheduler executes CLI worker outside interactive web requests. | Tested in `store_activation_contract.php` (Test 5). Maintenance scripts invoke `run_scheduler.php`. |

---

## 3. Operational Ownership & Monitoring

- **Super Administrator**:
  - Sets per-horizon quality thresholds (`ml_max_wape_7d`, `ml_max_wape_14d`, `ml_max_wape_28d`).
  - Records technical evaluation acceptance or rejection.
  - Authorizes live Store model activation or triggers emergency rollback.
  - Configures daily forecast generation time (default `23:00` PHT) and weekly model retraining schedule (default `Sunday 01:00` PHT).
- **Administrator**:
  - Reviews operational forecast usefulness and pattern credibility.
  - Maintains store calendar closures, holidays, and schedule events.
- **Inventory Manager**:
  - Retains exclusive discretion over purchase order creation. Recommendations remain strictly non-binding decision support.
- **System Health Monitor**:
  - Monitors background ML jobs (`ml_jobs`), detecting running leases, failed jobs, and overdue tasks (> 48 hours without execution).

---

## 4. Contract Test Evidence

All contract tests passed with zero regressions:
```
Testing Store Model Activation, Recovery and Runtime Gating (Ticket #163)...

--- Test 1: Demo Isolation Barrier ---
  [PASS] Activation must be blocked for demo model
  [PASS] Demo rejection reason must be clearly stated
  [PASS] StoreActivationService::activateStoreModel must throw on demo models

--- Test 2: Activation Gating Matrix (Missing Evidence Refusal) ---
  [PASS] Activation is blocked when all evidence is missing
  [PASS] Quality gates check is false
  [PASS] Technical review check is false
  [PASS] Operational review check is false
  [PASS] Observation period check is false
  [PASS] Quality gates now pass
  [PASS] Activation still blocked without reviews and observation
  [PASS] Super Admin technical approval verified
  [PASS] Activation still blocked without operational review
  [PASS] Admin operational review verified
  [PASS] Activation still blocked without 28 observation days
  [PASS] 10 completed observation days recorded
  [PASS] Observation period still incomplete (< 28 days)
  [PASS] 28 completed observation days recorded
  [PASS] Observation period holdout now satisfied
  [PASS] All gating criteria satisfied: candidate is ready for activation
  [PASS] Activation record contains model version
  [PASS] Activation attributed to Super Administrator
  [PASS] Active model set to approved Store candidate
  [PASS] Observation mode disabled upon live activation
  [PASS] Recommendations suspension cleared upon live activation
  [PASS] Store model is formally active

--- Test 3: Reversible Rollback Governance ---
  [PASS] Candidate 2 is now active
  [PASS] Previous candidate 1 preserved in settings
  [PASS] Restored prior approved Store model
  [PASS] Current approved model restored to candidate 1
  [PASS] Previous model cleared to prevent loops
  [PASS] Subsequent rollback blocked when no previous model is preserved

--- Test 4: Recommendation Suspension & Operational Non-Interference ---
  [PASS] Recommendations are marked suspended
  [PASS] Queue produces items
  [PASS] Queue item indicates recommendations suspended
  [PASS] Recommendations marked inactive
  [PASS] Cashier sale and inventory deduction executed normally while recommendations suspended
  [PASS] Recommendations successfully resumed

--- Test 5: Production Runtime & Maintenance Parity ---
  [PASS] Runtime OS recognized (Windows)
  [PASS] Platform OS detected correctly
  [PASS] run_maintenance.bat does not call legacy auto_retrain.py
  [PASS] run_maintenance.bat calls run_scheduler.php
  [PASS] run_maintenance.sh does not call legacy auto_retrain.py
  [PASS] run_maintenance.sh calls run_scheduler.php
  [PASS] call_ml_api returns 410 Gone
  [PASS] get_ml_prediction returns 410 Gone
  [PASS] Forecasting pipeline health check present in SystemHealthService
  [PASS] Pipeline health is healthy when active Store model is set

========================================================
PASS: All Store Activation and Recovery Contract Tests Passed (Ticket #163)
========================================================
```
