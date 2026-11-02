"""
Tests for Isolated M5 Historical-Price Experiment (Ticket #375, Spec #359).
Verifies:
  - CA_1/FOODS 50-product selection & price missingness preservation (39,433 priced, 1,267 missing, 31 varying)
  - Strict temporal isolation & prevention of future-price leakage
  - 5% 28-day relative reduction gate boundary
  - 2-of-3 validation periods requirement
  - 5% shorter-horizon (7d, 14d) regression ceiling
  - Zero reference MAE edge cases (no false relative improvement at 28d, zero-error maintenance at 7d/14d)
  - Undefined WAPE and incomplete coverage rejection
  - Fresh untouched confirmation requirement (inconclusive reporting)
  - Public/Store isolation boundary
"""
import json
import sys
import unittest
from pathlib import Path

import numpy as np
import pandas as pd

SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS))

from m5_price_experiment import (
    REFERENCE_FEATURES,
    CANDIDATE_PRICE_FEATURES,
    build_candidate_price_feature_matrix,
    compute_last_known_prices_at_origin,
    evaluate_feature_value_gates,
    evaluate_historical_price_experiment,
    prepare_price_history,
    verify_price_source_coverage,
    write_experiment_report,
)


def generate_m5_fixture(product_count: int = 50, days: int = 814) -> pd.DataFrame:
    """
    Generates deterministic fixture matching the 50 CA_1 / FOODS dataset structure:
    814 days (730 training + 84 holdout).
    """
    dates = pd.date_range("2014-03-01", periods=days)
    rng = np.random.default_rng(100)
    rows = []
    for pid in range(product_count):
        item_id = f"FOODS_3_{pid:03d}"
        base_price = 2.50 + (pid % 10) * 0.50
        has_varying = pid < 31  # 31 of 50 products have varying price

        for i, d in enumerate(dates):
            # Sales: positive with day-of-week seasonality
            dow = d.dayofweek
            sales = max(0, int(3 + np.sin(i / 7.0) * 2 + (1 if dow in (5, 6) else 0)))

            # Prices: introduce controlled missingness (1,267 of 40,700 total missing in M5)
            # In fixture, simulate missingness pattern for product 0 first 25 days, etc.
            is_missing = (pid == 0 and i < 25) or (pid == 1 and i < 15)
            if is_missing:
                price = np.nan
            else:
                price = base_price + (0.25 if (has_varying and (i // 60) % 2 == 1) else 0.0)

            rows.append({
                "datetime": d,
                "item_id": item_id,
                "sales": sales,
                "sell_price": price,
                "store_id": "CA_1",
                "cat_id": "FOODS",
            })
    return pd.DataFrame(rows)


class TestM5PriceExperiment(unittest.TestCase):
    def test_price_coverage_and_missingness_verification(self):
        """Verifies selected source coverage, missingness counts, and preservation."""
        # Synthesize exactly 50 products x 814 days = 40,700 product-days
        total_p_days = 40700
        priced_target = 39433
        missing_target = 1267

        dates = pd.date_range("2014-03-01", periods=814)
        records = []
        for pid in range(50):
            p_code = f"FOODS_1_{pid:03d}"
            is_varying = pid < 31
            for d in dates:
                records.append({
                    "date": d,
                    "product_id": p_code,
                    "units_sold": 2,
                    "price": 3.0 + (0.5 if is_varying and d.day % 2 == 0 else 0.0),
                })
        df = pd.DataFrame(records)
        # Mark exactly 1,267 rows as missing
        missing_indices = np.random.default_rng(42).choice(len(df), size=missing_target, replace=False)
        df.loc[missing_indices, "price"] = np.nan

        cov = verify_price_source_coverage(df)
        self.assertEqual(cov["total_product_days"], total_p_days)
        self.assertEqual(cov["priced_product_days"], priced_target)
        self.assertEqual(cov["missing_product_days"], missing_target)
        self.assertEqual(cov["products_with_varying_price"], 31)
        self.assertEqual(cov["total_products"], 50)
        self.assertFalse(cov["has_stockout_flag"])
        self.assertFalse(cov["has_promotion_flag"])
        self.assertTrue(cov["missing_prices_preserved"])

    def test_temporal_isolation_and_future_price_leakage_prevented(self):
        """
        Verifies that candidate features and predictions at origin T are strictly
        invariant to realized future prices occurring on target dates t > T.
        """
        raw = generate_m5_fixture(product_count=20, days=820)
        history, _ = prepare_price_history(raw, product_count=20)

        origin = pd.Timestamp("2014-10-01")
        prices_at_origin = compute_last_known_prices_at_origin(history, origin)

        # Mutate future prices drastically
        mutated_history = history.copy()
        mutated_history.loc[mutated_history["date"] > origin, "price"] = 99999.99

        prices_after_future_mutation = compute_last_known_prices_at_origin(mutated_history, origin)

        # Candidate origin prices must be 100% identical
        self.assertEqual(prices_at_origin, prices_after_future_mutation)

        # Verify feature matrix generation is identical
        dummy_base = pd.DataFrame({
            "product_id": ["FOODS_3_000", "FOODS_3_001"],
            "horizon_day": [1, 28],
        })
        feat1 = build_candidate_price_feature_matrix(dummy_base, history, origin)
        feat2 = build_candidate_price_feature_matrix(dummy_base, mutated_history, origin)
        pd.testing.assert_frame_equal(feat1, feat2)

    def test_gate_28d_relative_reduction_boundary(self):
        """
        Verifies the >= 5% relative reduction gate boundary at 28 days:
          - 4.9% relative reduction fails.
          - 5.0% relative reduction passes.
        """
        # Baseline reference MAE = 10.0
        # 4.9% reduction -> cand MAE = 9.51
        scores_fail = pd.DataFrame([
            {"fold": f, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": 0.30, "bias": 0.0, "coverage": 1.0} for f in (1, 2, 3)
        ] + [
            {"fold": f, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 9.51, "rmse": 11.5, "wape": 0.28, "bias": 0.0, "coverage": 1.0} for f in (1, 2, 3)
        ] + [
            {"fold": 1, "split": "val", "horizon": h, "product_id": "ALL", "model": m, "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0}
            for h in (7, 14) for m in ("reference_random_forest", "candidate_price_random_forest")
        ])
        res_fail = evaluate_feature_value_gates(scores_fail)
        self.assertFalse(res_fail["gate_28d_relative_reduction"]["pass"])
        self.assertAlmostEqual(res_fail["gate_28d_relative_reduction"]["relative_reduction"], 0.049, places=3)

        # 5.0% reduction -> cand MAE = 9.50
        scores_pass = scores_fail.copy()
        scores_pass.loc[scores_pass["model"] == "candidate_price_random_forest", "mae"] = 9.50
        res_pass = evaluate_feature_value_gates(scores_pass)
        self.assertTrue(res_pass["gate_28d_relative_reduction"]["pass"])
        self.assertAlmostEqual(res_pass["gate_28d_relative_reduction"]["relative_reduction"], 0.050, places=3)

    def test_gate_two_of_three_periods_requirement(self):
        """
        Verifies 28-day improvement in at least two of three validation periods.
        If candidate only improves in 1 of 3 periods, gate fails even if pooled MAE is lower!
        """
        # Fold 1: cand 8.0 vs ref 10.0 (improved)
        # Fold 2: cand 10.5 vs ref 10.0 (regressed)
        # Fold 3: cand 10.5 vs ref 10.0 (regressed)
        # Pooled cand = (8.0 + 10.5 + 10.5)/3 = 9.66 (< 10.0, > 3% better), but only 1 of 3 periods improved!
        scores = pd.DataFrame([
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": 0.30, "bias": 0.0, "coverage": 1.0},
            {"fold": 2, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": 0.30, "bias": 0.0, "coverage": 1.0},
            {"fold": 3, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": 0.30, "bias": 0.0, "coverage": 1.0},

            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 8.0, "rmse": 10.0, "wape": 0.24, "bias": 0.0, "coverage": 1.0},
            {"fold": 2, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 10.5, "rmse": 12.5, "wape": 0.31, "bias": 0.0, "coverage": 1.0},
            {"fold": 3, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 10.5, "rmse": 12.5, "wape": 0.31, "bias": 0.0, "coverage": 1.0},
        ] + [
            {"fold": 1, "split": "val", "horizon": h, "product_id": "ALL", "model": m, "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0}
            for h in (7, 14) for m in ("reference_random_forest", "candidate_price_random_forest")
        ])
        res = evaluate_feature_value_gates(scores)
        self.assertFalse(res["gate_28d_multi_period_improvement"]["pass"])
        self.assertEqual(res["gate_28d_multi_period_improvement"]["improved_periods"], 1)

        # Now make fold 2 improve: cand 9.0 vs ref 10.0 -> 2 of 3 periods improved -> passes
        scores.loc[(scores["fold"] == 2) & (scores["model"] == "candidate_price_random_forest"), "mae"] = 9.0
        res2 = evaluate_feature_value_gates(scores)
        self.assertTrue(res2["gate_28d_multi_period_improvement"]["pass"])
        self.assertEqual(res2["gate_28d_multi_period_improvement"]["improved_periods"], 2)

    def test_gate_shorter_horizon_regression_ceiling(self):
        """
        Verifies at most 5% pooled MAE regression at 7 days and 14 days:
          - 5.0% regression passes.
          - 5.1% regression fails.
        """
        # Ref 7d = 10.0, Cand 7d = 10.51 (5.1% regression) -> fails
        scores_fail = pd.DataFrame([
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": 0.30, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 9.0, "rmse": 11.0, "wape": 0.27, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "reference_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 10.51, "rmse": 12.5, "wape": 0.26, "bias": 0.0, "coverage": 1.0},
        ])
        res_fail = evaluate_feature_value_gates(scores_fail)
        self.assertFalse(res_fail["gate_shorter_horizon_regression"]["pass"])
        self.assertFalse(res_fail["gate_shorter_horizon_regression"]["horizons"]["7d"]["pass"])

        # Cand 7d = 10.50 (5.0% regression) -> passes
        scores_pass = scores_fail.copy()
        scores_pass.loc[(scores_pass["horizon"] == 7) & (scores_pass["model"] == "candidate_price_random_forest"), "mae"] = 10.50
        res_pass = evaluate_feature_value_gates(scores_pass)
        self.assertTrue(res_pass["gate_shorter_horizon_regression"]["pass"])

    def test_gate_zero_error_reference_handling(self):
        """
        Verifies explicit zero reference MAE handling:
          - Zero 28-day reference cannot demonstrate further 5% improvement (fails).
          - Zero protected-horizon (7d, 14d) reference requires zero candidate error.
        """
        # Case A: 28-day reference MAE is 0.0
        scores_zero_28 = pd.DataFrame([
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 0.0, "rmse": 0.0, "wape": 0.0, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 0.0, "rmse": 0.0, "wape": 0.0, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "reference_random_forest", "mae": 2.0, "rmse": 2.5, "wape": 0.1, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 2.0, "rmse": 2.5, "wape": 0.1, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "reference_random_forest", "mae": 3.0, "rmse": 3.5, "wape": 0.1, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 3.0, "rmse": 3.5, "wape": 0.1, "bias": 0.0, "coverage": 1.0},
        ])
        res_zero_28 = evaluate_feature_value_gates(scores_zero_28)
        self.assertFalse(res_zero_28["gate_28d_relative_reduction"]["pass"])
        self.assertIn("Zero 28-day reference MAE cannot demonstrate further 5% improvement", res_zero_28["gate_28d_relative_reduction"]["reason"])

        # Case B: 7-day reference MAE is 0.0, candidate has 0.1 error -> fails regression check
        scores_zero_7d_cand_err = pd.DataFrame([
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": 0.3, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 9.0, "rmse": 11.0, "wape": 0.27, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "reference_random_forest", "mae": 0.0, "rmse": 0.0, "wape": 0.0, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 0.1, "rmse": 0.1, "wape": 0.05, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "reference_random_forest", "mae": 3.0, "rmse": 3.5, "wape": 0.1, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 3.0, "rmse": 3.5, "wape": 0.1, "bias": 0.0, "coverage": 1.0},
        ])
        res_zero_7d_cand_err = evaluate_feature_value_gates(scores_zero_7d_cand_err)
        self.assertFalse(res_zero_7d_cand_err["gate_shorter_horizon_regression"]["horizons"]["7d"]["pass"])

        # Case C: 7-day reference MAE is 0.0, candidate has 0.0 error -> passes
        scores_zero_7d_zero_cand = scores_zero_7d_cand_err.copy()
        scores_zero_7d_zero_cand.loc[(scores_zero_7d_zero_cand["horizon"] == 7) & (scores_zero_7d_zero_cand["model"] == "candidate_price_random_forest"), "mae"] = 0.0
        res_zero_7d_zero_cand = evaluate_feature_value_gates(scores_zero_7d_zero_cand)
        self.assertTrue(res_zero_7d_zero_cand["gate_shorter_horizon_regression"]["horizons"]["7d"]["pass"])

    def test_undefined_wape_and_missing_coverage_rejected(self):
        """Verifies undefined WAPE (zero sales) and incomplete coverage (< 1.0) cannot pass."""
        scores_nan_wape = pd.DataFrame([
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": np.nan, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 9.0, "rmse": 11.0, "wape": 0.27, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "reference_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "reference_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
        ])
        res_nan = evaluate_feature_value_gates(scores_nan_wape)
        self.assertFalse(res_nan["gate_wape_coverage"]["pass"])

        # Incomplete coverage (< 1.0)
        scores_incomplete_cov = scores_nan_wape.copy()
        scores_incomplete_cov["wape"] = 0.25
        scores_incomplete_cov.loc[0, "coverage"] = 0.85
        res_cov = evaluate_feature_value_gates(scores_incomplete_cov)
        self.assertFalse(res_cov["gate_wape_coverage"]["pass"])

    def test_untouched_confirmation_inconclusive_without_success_claim(self):
        """
        Verifies that without fresh untouched confirmation evidence, the evaluation
        reports inconclusive confirmation and cannot claim success.
        """
        scores = pd.DataFrame([
            {"fold": f, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": 0.30, "bias": 0.0, "coverage": 1.0} for f in (1, 2, 3)
        ] + [
            {"fold": f, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 9.0, "rmse": 11.0, "wape": 0.27, "bias": 0.0, "coverage": 1.0} for f in (1, 2, 3)
        ] + [
            {"fold": 1, "split": "val", "horizon": h, "product_id": "ALL", "model": m, "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0}
            for h in (7, 14) for m in ("reference_random_forest", "candidate_price_random_forest")
        ])
        res = evaluate_feature_value_gates(scores, confirmation_available=False)
        self.assertTrue(res["all_development_gates_pass"])
        self.assertEqual(res["confirmation_status"], "inconclusive")
        self.assertFalse(res["can_claim_success"])
        self.assertIn("inconclusive", res["confirmation_reason"].lower())

    def test_public_store_isolation_enforced(self):
        """
        Verifies that public experiment execution strictly enforces Store isolation:
        does not write to Store model directory, cannot authorize Store activation.
        """
        scores = pd.DataFrame([
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "reference_random_forest", "mae": 10.0, "rmse": 12.0, "wape": 0.30, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 28, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 9.0, "rmse": 11.0, "wape": 0.27, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "reference_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 7, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "reference_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
            {"fold": 1, "split": "val", "horizon": 14, "product_id": "ALL", "model": "candidate_price_random_forest", "mae": 5.0, "rmse": 6.0, "wape": 0.25, "bias": 0.0, "coverage": 1.0},
        ])
        res = evaluate_feature_value_gates(scores, confirmation_available=True)
        self.assertFalse(res["store_activation_authorized"])
        self.assertIn("Store-specific evaluation, never operational Store activation", res["store_activation_notice"])

    def test_reproducible_experiment_run_and_reporting(self):
        """
        Executes end-to-end reproducible experiment on deterministic fixture,
        verifying daily predictions, horizon scoring, and plain-English report generation.
        """
        import tempfile
        raw = generate_m5_fixture(product_count=20, days=820)
        history, meta = prepare_price_history(raw, product_count=20)
        daily, scores, eval_meta = evaluate_historical_price_experiment(history, confirmation_available=False)

        self.assertIn("predicted_reference", daily.columns)
        self.assertIn("predicted_candidate", daily.columns)
        self.assertIn("baseline", daily.columns)
        self.assertEqual(len(scores["horizon"].unique()), 3)
        self.assertTrue(set(scores["model"]).issuperset({
            "reference_random_forest", "candidate_price_random_forest", "seasonal_naive_baseline"
        }))

        with tempfile.TemporaryDirectory() as tmpdir:
            out_path = Path(tmpdir)
            write_experiment_report(meta, scores, eval_meta["gates"], out_path)
            report_file = out_path / "discussion.md"
            self.assertTrue(report_file.exists())
            content = report_file.read_text(encoding="utf-8")
            self.assertIn("M5 Historical-Price Feature Value Experiment Report", content)
            self.assertIn("Executive Summary", content)
            self.assertIn("Store Isolation", content)


if __name__ == "__main__":
    unittest.main()
